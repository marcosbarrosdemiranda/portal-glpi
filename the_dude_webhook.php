<?php
// Endpoint chamado pelo The Dude (192.168.1.246) quando um device/link/probe
// muda de estado. Sem sessão/login: quem autentica é o token (?token=)
// gerado em dude_config.php e colado na Notification do cliente do Dude.
// Diferente do webhook do back-gmais: o Dude não reentrega em loop, então
// aqui os erros de validação viram status HTTP de verdade (ajuda a debugar
// a Notification direto no cliente).
header('Content-Type: application/json');

$boot_ok = true;
try {
    require_once __DIR__ . '/agenda/db.php';
    require_once __DIR__ . '/dude_lib.php';
} catch (\Throwable $e) {
    $boot_ok = false;
    error_log('the_dude_webhook: boot falhou: ' . $e->getMessage());
}

if (!$boot_ok) {
    echo json_encode(['ok' => false]);
    exit;
}

global $pdo;
$token = (string) ($_GET['token'] ?? '');
$atual = dude_token_atual($pdo);
if ($atual === '' || !hash_equals($atual, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'token invalido']);
    exit;
}

$tipo   = (string) ($_GET['tipo'] ?? '');
$estado = (string) ($_GET['estado'] ?? '');
if (!in_array($tipo, DUDE_TIPOS_VALIDOS, true) || !in_array($estado, ['up', 'down'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'tipo ou estado invalido']);
    exit;
}

$chave    = trim((string) ($_GET['chave'] ?? ''));
$nome     = trim((string) ($_GET['nome'] ?? ''));
$endereco = trim((string) ($_GET['addr'] ?? ''));
$loja     = trim((string) ($_GET['loja'] ?? '')); // fixo por mapa do Dude (ex.: "Loja 05")
$detalhe  = trim((string) ($_GET['detalhe'] ?? ''));
if ($chave === '') $chave = $nome !== '' ? $nome : ($endereco !== '' ? $endereco : 'sem-id');

try {
    dude_registrar_estado($pdo, $tipo, $chave, $nome, $endereco, $loja, $estado, $detalhe);
} catch (\Throwable $e) {
    error_log('the_dude_webhook: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
