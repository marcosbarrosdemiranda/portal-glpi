<?php
/**
 * Detecta projetos "trabalhados" numa data específica (commit no GitHub
 * ou item de checklist concluído em Projeto Manual), pra pré-popular os
 * chips do campo Projeto no modal da Agenda (tipo='projeto'). Só leitura
 * — nunca escreve em Projetos. Ver SPEC
 * .claude/sda/specs/feat-agenda-projeto-deteccao-automatica.md.
 */
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => 'Sessão inválida']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vault_crypto.php';
require_once __DIR__ . '/../github_client.php';
require_once __DIR__ . '/../github_cache.php';

$data = trim($_GET['data'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    echo json_encode(['ok' => false, 'msg' => 'Data inválida']);
    exit;
}

$projetos = [];
$detalhes = []; // pra montar a descrição do evento — commits/itens que bateram na data

// ── 1) GitHub: repo com commit cacheado na data (qualquer autor) ────────
// Lista de repos = mesmo cache de projetos_lista.php (gh_cached,
// TTL_REPOS — worker reaquece a cada 10min, cache miss raro). Commits
// são lidos DIRETO do cache SEM fallback live: gh_cached cairia em
// github_commits_recentes (branches + N chamadas por repo) — pesado
// demais pra rodar síncrono na abertura do modal. Repo sem cache de
// commits é só ignorado nesta detecção (fail silencioso).
gh_cache_bootstrap($pdo);
$contas = $pdo->query("SELECT * FROM portal_github_contas WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($contas as $conta) {
    $token = vault_decrypt($conta['token_enc']);
    $resultado = gh_cached($pdo, "repos:{$conta['id']}", TTL_REPOS, fn() => github_listar_repos($token));
    if (isset($resultado['erro'])) continue;

    $st = $pdo->prepare("SELECT repo_nome, status, visivel FROM portal_projetos_status WHERE conta_id=?");
    $st->execute([$conta['id']]);
    $visMap = []; $statusMap = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $visMap[$row['repo_nome']]    = (int)$row['visivel'];
        $statusMap[$row['repo_nome']] = $row['status'] ?: 'em_execucao';
    }

    foreach ($resultado as $repo) {
        $nome = $repo['nome'];
        if (($visMap[$nome] ?? 1) === 0) continue;
        if (($statusMap[$nome] ?? 'em_execucao') !== 'em_execucao') continue;

        $stC = $pdo->prepare("SELECT payload FROM portal_github_cache WHERE chave = ?");
        $stC->execute(["commits:{$conta['id']}:{$nome}"]);
        $payload = $stC->fetchColumn();
        if (!$payload) continue; // sem cache — ignora, sem chamada live

        $commits = json_decode($payload, true);
        if (!is_array($commits)) continue;

        $commitsDoDia = [];
        foreach ($commits as $cm) {
            if (substr((string)($cm['data'] ?? ''), 0, 10) === $data) {
                $commitsDoDia[] = ['msg' => $cm['msg'] ?? '', 'sha' => $cm['sha'] ?? ''];
            }
        }
        if ($commitsDoDia) {
            $projetos[] = $nome;
            $detalhes[] = ['nome' => $nome, 'tipo' => 'github', 'commits' => $commitsDoDia];
        }
    }
}

// ── 2) Projetos Manuais: item de checklist concluído na data ────────────
$st = $pdo->prepare("
    SELECT pm.titulo, pmi.texto
    FROM portal_projetos_manuais pm
    JOIN portal_projetos_manuais_itens pmi ON pmi.projeto_id = pm.id
    WHERE pm.status = 'em_execucao' AND DATE(pmi.concluido_em) = ?
    ORDER BY pm.titulo, pmi.ordem
");
$st->execute([$data]);
$itensPorProjeto = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $itensPorProjeto[$row['titulo']][] = $row['texto'];
}
foreach ($itensPorProjeto as $titulo => $itens) {
    $projetos[] = $titulo;
    $detalhes[] = ['nome' => $titulo, 'tipo' => 'manual', 'itens' => $itens];
}

$projetos = array_values(array_unique($projetos));
sort($projetos, SORT_NATURAL | SORT_FLAG_CASE);

echo json_encode(['ok' => true, 'data' => $data, 'projetos' => $projetos, 'detalhes' => $detalhes]);
