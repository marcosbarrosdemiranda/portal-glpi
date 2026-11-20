<?php
/**
 * webhook_reconhecimento.php — Endpoint chamado pela aplicação de Reconhecimento Facial
 * (coletor FaceEventsIntelbras) a cada X min com o diagnóstico de saúde.
 * Sem sessão/login: quem autentica é o token (?token=) gerado em reconhecimento_config.php.
 */
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/agenda/db.php';
    require_once __DIR__ . '/reconhecimento_lib.php';
} catch (\Throwable $e) {
    error_log('webhook_reconhecimento: boot falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

global $pdo;
$token = (string) ($_GET['token'] ?? '');
$atual = reconhecimento_webhook_token();
if ($atual === '' || !hash_equals($atual, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'token invalido']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'json invalido']);
    exit;
}

try {
    reconhecimento_registrar_payload($pdo, $payload);
} catch (\Throwable $e) {
    error_log('webhook_reconhecimento: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true]);
