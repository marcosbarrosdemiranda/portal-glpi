<?php
/**
 * github_cache_worker.php — pré-aquece o cache do GitHub (contas → repos →
 * readme/issues/commits/milestone) pra projetos.php nunca precisar chamar a
 * API do GitHub durante um request HTTP.
 *
 * Script SINGLE-PASS (mesmo padrão de agenda/monitor_db_worker.php e
 * wpp/worker.php) — quem faz o loop/sleep é o entrypoint do serviço no
 * docker-compose.yml, não este arquivo.
 *
 * Mitigação da Lição Aprendida #6 (CLAUDE.md): um require_once que falha no
 * topo mata o processo inteiro de forma silenciosa, já que nenhum catch
 * interno protege contra isso. Por isso cada dependência é checada com
 * file_exists() ANTES do require — se faltar, grava em STDERR e sai com
 * código 1 (aparece em `docker logs`, e o loop do compose tenta de novo no
 * próximo ciclo em vez de morrer pra sempre).
 */

$deps = [
    __DIR__ . '/agenda/db.php',
    // config.php só define constantes (GLPI_APP_TOKEN etc.), sem sessão HTTP —
    // vault_crypto.php precisa de GLPI_APP_TOKEN pra montar VAULT_KEY.
    __DIR__ . '/agenda/config.php',
    __DIR__ . '/vault_crypto.php',
    __DIR__ . '/github_client.php',
    __DIR__ . '/github_cache.php',
];
foreach ($deps as $dep) {
    if (!file_exists($dep)) {
        fwrite(STDERR, "[github_cache_worker] dependência ausente: $dep\n");
        exit(1);
    }
    require_once $dep;
}

$contas = $pdo->query("SELECT * FROM portal_github_contas WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);

foreach ($contas as $conta) {
    try {
        $token     = vault_decrypt($conta['token_enc']);
        $resultado = gh_cached($pdo, "repos:{$conta['id']}", TTL_REPOS, fn() => github_listar_repos($token));

        if (isset($resultado['erro'])) {
            $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=0, ultima_verificacao=NOW() WHERE id=?")
                ->execute([$conta['id']]);
            error_log("[github_cache_worker] conta {$conta['apelido']}: {$resultado['erro']}");
            continue;
        }
        $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=?")
            ->execute([$conta['id']]);

        // Sem o filtro de visibilidade de projetos.php (($visivelMap[$chave] ?? 1) === 0)
        // de propósito — cache cobre TODOS os repos; visibilidade é só filtro de exibição.
        foreach ($resultado as $repo) {
            try {
                $ghUser = $conta['usuario_github'];
                $ck     = "{$conta['id']}:{$repo['nome']}";

                gh_cached($pdo, "readme:$ck",  TTL_DETALHE, fn() => github_analisar_readme($token, $ghUser, $repo['nome']));
                gh_cached($pdo, "issues:$ck",  TTL_DETALHE, fn() => github_issues($token, $ghUser, $repo['nome']));
                gh_cached($pdo, "commits:$ck", TTL_DETALHE, fn() => github_commits_recentes($token, $ghUser, $repo['nome'], 8));

                // Milestone só é lido se não há previsão manual nem prazo no doc —
                // replica a MESMA condição de projetos.php (bloco de previsão) pra
                // não gastar 1 chamada de API por repo que nunca seria lida.
                $prevManual = $pdo->prepare("SELECT previsao FROM portal_projetos_status WHERE conta_id=? AND repo_nome=?");
                $prevManual->execute([$conta['id'], $repo['nome']]);
                $prevManualVal = $prevManual->fetchColumn();

                $doc = docPrdDoProjeto($repo['nome']);
                if (!$prevManualVal && !($doc['prazo'] ?? null)) {
                    gh_cached($pdo, "milestone:$ck", TTL_DETALHE, fn() => github_obter_previsao($token, $ghUser, $repo['nome']));
                }
            } catch (\Throwable $e) {
                error_log("[github_cache_worker] repo {$repo['nome']}: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log("[github_cache_worker] conta {$conta['apelido']}: " . $e->getMessage());
    }
}

echo "[github_cache_worker] ciclo concluído " . date('Y-m-d H:i:s') . " — " . count($contas) . " conta(s)\n";
