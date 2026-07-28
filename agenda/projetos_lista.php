<?php
/**
 * Lista os nomes dos projetos GitHub (de todos os técnicos que cadastraram
 * conta), pra popular o select "Projeto" no evento de agenda tipo=projeto.
 * Respeita a visibilidade que cada técnico define em Projetos
 * (portal_projetos_status.visivel) e é a mesma fonte de dados que
 * projetos.php usa pra listar os cards.
 */
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Sessão inválida']); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vault_crypto.php';
require_once __DIR__ . '/../github_client.php';

$contas = $pdo->query("SELECT * FROM portal_github_contas WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);

$nomes = [];
foreach ($contas as $conta) {
    $token     = vault_decrypt($conta['token_enc']);
    $resultado = github_listar_repos($token);
    if (isset($resultado['erro'])) continue; // token invalido/GitHub indisponivel — pula essa conta

    $st = $pdo->prepare("SELECT repo_nome, visivel FROM portal_projetos_status WHERE conta_id=?");
    $st->execute([$conta['id']]);
    $visMap = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $visMap[$row['repo_nome']] = (int)$row['visivel'];

    foreach ($resultado as $repo) {
        if (($visMap[$repo['nome']] ?? 1) === 0) continue; // ocultado pelo técnico
        $nomes[] = $repo['nome'];
    }
}

$nomes = array_values(array_unique($nomes));
sort($nomes, SORT_NATURAL | SORT_FLAG_CASE);

echo json_encode(['ok' => true, 'projetos' => $nomes]);
