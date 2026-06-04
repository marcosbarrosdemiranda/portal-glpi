<?php
/**
 * Atribui um chamado GLPI a um técnico e muda o status para "Atribuído" (2)
 * Chamado via POST com JSON: { ticket_id, atendente_id }
 */
header('Content-Type: application/json');
require_once 'config.php';

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id   = (int)($body['ticket_id']   ?? 0);
$atendente_id= (int)($body['atendente_id'] ?? 0);

if (!$ticket_id || !$atendente_id) {
    echo json_encode(['ok' => false, 'msg' => 'ticket_id e atendente_id são obrigatórios']);
    exit;
}

// ── Abre sessão GLPI ───────────────────────────────────────────
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

// ── Atualiza status do ticket para Atribuído (2) ───────────────
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode(['input' => ['status' => 2]]),
    CURLOPT_HTTPHEADER     => $headers,
]);
$resTicket = json_decode(curl_exec($ch), true);
curl_close($ch);

// ── Remove técnicos anteriores e atribui o novo ────────────────
// Busca usuários já atribuídos como técnico (type=2)
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id . '/Ticket_User?searchText[type]=2');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
$tecnicosAtuais = json_decode(curl_exec($ch), true) ?? [];
curl_close($ch);

// Remove técnicos existentes
foreach ($tecnicosAtuais as $tu) {
    if (!isset($tu['id'])) continue;
    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User/' . $tu['id']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Atribui novo técnico (type=2 → técnico)
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['input' => [
        'tickets_id' => $ticket_id,
        'users_id'   => $atendente_id,
        'type'       => 2, // 1=Solicitante, 2=Técnico, 3=Observador
    ]]),
    CURLOPT_HTTPHEADER => $headers,
]);
$resTecnico = json_decode(curl_exec($ch), true);
curl_close($ch);

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
curl_close($ch);

$sucesso = isset($resTecnico['id']);
echo json_encode([
    'ok'  => $sucesso,
    'msg' => $sucesso
        ? "Ticket #{$ticket_id} atribuído ao técnico ID {$atendente_id} com sucesso."
        : 'Erro ao atribuir técnico.',
    'ticket_update' => $resTicket,
    'tecnico'       => $resTecnico,
]);
