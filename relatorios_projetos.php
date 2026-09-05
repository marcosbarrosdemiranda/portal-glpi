<?php
/**
 * API de dados para o relatório de Projetos
 * Mesma fonte que projetos.php usa: repositórios GitHub das contas
 * cadastradas por qualquer técnico, agregados.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/github_client.php';
require_once __DIR__ . '/github_cache.php';

$statusLabel = ['futuro' => 'Futuro', 'em_execucao' => 'Em Execução', 'concluido' => 'Concluído'];

$contas   = $pdo->query("SELECT * FROM portal_github_contas WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);
$contaIds = array_column($contas, 'id');

$statusMap  = [];
$visivelMap = [];
if ($contaIds) {
    $ph = implode(',', array_fill(0, count($contaIds), '?'));
    $st = $pdo->prepare("SELECT conta_id, repo_nome, status, visivel FROM portal_projetos_status WHERE conta_id IN ($ph)");
    $st->execute($contaIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chave = $row['conta_id'] . ':' . $row['repo_nome'];
        $statusMap[$chave]  = $row['status'];
        $visivelMap[$chave] = (int)$row['visivel'];
    }
}

$projetos = [];
foreach ($contas as $conta) {
    $token  = vault_decrypt($conta['token_enc']);
    $ghUser = $conta['usuario_github'];
    $resultado = gh_cached($pdo, "repos:{$conta['id']}", 300, fn() => github_listar_repos($token));
    if (isset($resultado['erro'])) continue; // conta com token invalido/GitHub indisponivel — pula

    foreach ($resultado as $repo) {
        $chave = $conta['id'] . ':' . $repo['nome'];
        if (($visivelMap[$chave] ?? 1) === 0) continue; // ocultado pelo técnico

        $status   = $statusMap[$chave] ?? 'em_execucao';
        $previsao = gh_cached($pdo, "milestone:$chave", 600, fn() => github_obter_previsao($token, $ghUser, $repo['nome']));

        // Progresso: issues fechadas / total; sem issues → checklist do README
        $issues = gh_cached($pdo, "issues:$chave", 600, fn() => github_issues($token, $ghUser, $repo['nome']));
        $totIss = $issues['total_abertas'] + $issues['total_fechadas'];
        if ($totIss > 0) {
            $pct = (int)round($issues['total_fechadas'] / $totIss * 100);
            $tarefas = $totIss;
        } else {
            $analise = gh_cached($pdo, "readme:$chave", 600, fn() => github_analisar_readme($token, $ghUser, $repo['nome']));
            $pct = $analise['progresso']['pct'] ?? 0;
            $tarefas = $analise['progresso']['total'] ?? 0;
        }

        $projetos[] = [
            'nome'      => $repo['nome'],
            'progresso' => $pct,
            'status'    => $statusLabel[$status] ?? 'Em Execução',
            'prazo'     => $previsao ? date('d/m/Y', strtotime($previsao)) : '',
            'tarefas'   => $tarefas,
            'pendentes' => $issues['total_abertas'],
            'equipe'    => $conta['apelido'],
        ];
    }
}

echo json_encode($projetos);
