<?php
// Endpoint chamado pelo Ponto (API Sólides) a cada 5 min com o diagnóstico de
// saúde (mesmo JSON do /api/saude do Ponto). Sem sessão/login: quem autentica
// é o token (?token=) gerado em solides_config.php e colado no painel do
// Ponto (Saúde do sistema → Monitoramento externo → webhook).
// Token errado -> 401 de propósito: o painel do Ponto mostra "Falhou: HTTP 401"
// no último resultado, o que já aponta o problema pra quem configurou.
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/agenda/db.php';
    require_once __DIR__ . '/solides_lib.php';
} catch (\Throwable $e) {
    error_log('webhook_solides: boot falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

global $pdo;
$token = (string) ($_GET['token'] ?? '');
$atual = solides_webhook_token();
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
    solides_registrar_payload($pdo, $payload);
} catch (\Throwable $e) {
    error_log('webhook_solides: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true]);
