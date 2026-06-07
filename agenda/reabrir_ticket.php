<?php
/**
 * Reabre um chamado fechado no GLPI (status 2 = Atribuído)
 * POST JSON: { ticket_id }
 */
header('Content-Type: application/json');
require_once 'config.php';

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id = (int)($body['ticket_id'] ?? 0);

if (!$ticket_id) {
    echo json_encode(['ok' => false, 'msg' => 'ticket_id é obrigatório']);
    exit;
}

// ── Abre sessão ────────────────────────────────────────────────
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . $auth,
        'App-Token: ' . GLPI_APP_TOKEN,
    ],
]);
$r = json_decode(curl_exec($ch), true);
curl_close($ch);
$token = $r['session_token'] ?? '';

if (!$token) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao autenticar na API do GLPI']);
    exit;
}

$headers = [
    'Content-Type: application/json',
    'Session-Token: ' . $token,
    'App-Token: ' . GLPI_APP_TOKEN,
];

// ── Muda status para Atribuído (2) ─────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode(['input' => ['status' => 2]]),
    CURLOPT_HTTPHEADER     => $headers,
]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
curl_close($ch);

echo json_encode(['ok' => true, 'msg' => "Chamado #{$ticket_id} reaberto com sucesso."]);
