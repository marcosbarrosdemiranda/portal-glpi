<?php
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => 'Sessão inválida']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $titulo = trim($_GET['titulo'] ?? '');
    if ($titulo === '') {
        echo json_encode(['ok' => false, 'itens' => []]);
        exit;
    }

    $st = $pdo->prepare("SELECT id, titulo FROM portal_projetos_manuais WHERE titulo = ? LIMIT 1");
    $st->execute([$titulo]);
    $proj = $st->fetch(PDO::FETCH_ASSOC);

    if (!$proj) {
        echo json_encode(['ok' => true, 'encontrado' => false, 'itens' => []]);
        exit;
    }

    $stI = $pdo->prepare("SELECT id, texto, concluido, ordem FROM portal_projetos_manuais_itens WHERE projeto_id = ? ORDER BY ordem ASC");
    $stI->execute([$proj['id']]);
    $itens = $stI->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'         => true,
        'encontrado' => true,
        'projeto_id' => $proj['id'],
        'titulo'     => $proj['titulo'],
        'itens'      => $itens,
    ]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $concluidos = $body['concluidos'] ?? [];
    if (!empty($concluidos) && is_array($concluidos)) {
        $stUp = $pdo->prepare("UPDATE portal_projetos_manuais_itens SET concluido = 1 WHERE id = ?");
        foreach ($concluidos as $itemId) {
            $stUp->execute([(int)$itemId]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}
