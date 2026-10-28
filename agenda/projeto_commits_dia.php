<?php
/**
 * Retorna os commits de um repositório GitHub para uma data específica (YYYY-MM-DD),
 * usados para popular automaticamente a resposta de um chamado do tipo projeto na Agenda.
 */
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => 'Sessão inválida']);
    exit;
}

header('Content-Type: application/json');

$repoName = trim($_GET['repo'] ?? '');
$dataAlvo = trim($_GET['data'] ?? date('Y-m-d'));

if ($repoName === '') {
    echo json_encode(['ok' => false, 'msg' => 'Repositório não informado']);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vault_crypto.php';
require_once __DIR__ . '/../github_client.php';

$contas = $pdo->query("SELECT * FROM portal_github_contas WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);

$commitsDoDia = [];
$encontrouRepo = false;

foreach ($contas as $conta) {
    $token = vault_decrypt($conta['token_enc']);
    $resultado = github_listar_repos($token);
    if (isset($resultado['erro'])) continue;

    // Encontra o owner (login) da conta GitHub
    $ghUser = '';
    foreach ($resultado as $r) {
        // github_listar_repos retorna lista de repos. Vamos checar se o repoName bate.
        if (strcasecmp($r['nome'], $repoName) === 0) {
            // Extrai o owner da URL do repo ou da conta se disponível
            // A API do GitHub retorna url do repo ou podemos inferir do owner se guardado.
            // Alternativamente, github_commits_completo precisa do $owner (username).
            // Vamos extrair o owner da URL do repositório (ex: https://github.com/owner/repo)
            $htmlUrl = $r['url'] ?? '';
            if (preg_match('#github\.com/([^/]+)/#i', $htmlUrl, $m)) {
                $ghUser = $m[1];
            }
            break;
        }
    }

    if ($ghUser === '') {
        // Tenta buscar o usuário da API se não veio na url
        // Mas geralmente a url do repo é https://github.com/owner/repo
        continue;
    }

    // Verifica se este repo pertence a esta conta
    $encontrouRepo = true;
    $todosCommits = github_commits_completo($token, $ghUser, $repoName, 2);

    foreach ($todosCommits as $cm) {
        $cmDataIso = $cm['data'] ?? ''; // ex: 2026-10-23T14:30:00Z
        if ($cmDataIso === '') continue;
        $cmDataDia = substr($cmDataIso, 0, 10); // YYYY-MM-DD
        if ($cmDataDia === $dataAlvo) {
            $commitsDoDia[] = [
                'sha'    => $cm['sha'],
                'msg'    => $cm['msg'],
                'autor'  => $cm['autor'],
                'data'   => $cm['data'],
                'branch' => $cm['branch'],
                'url'    => $cm['url'],
            ];
        }
    }
    break; // Encontrou o repo e processou
}

echo json_encode([
    'ok'      => true,
    'repo'    => $repoName,
    'data'    => $dataAlvo,
    'commits' => $commitsDoDia,
]);
