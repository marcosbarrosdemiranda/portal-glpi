<?php
/**
 * Exclui permanentemente um chamado do GLPI (DELETE /Ticket/{id})
 * Apenas permite exclusão de chamados em aberto (status = 1 = Novo)
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

// ── Verifica status do chamado (só permite excluir se estiver Novo/status=1) ──
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
$ticket = json_decode(curl_exec($ch), true);
curl_close($ch);

$status = (int)($ticket['status'] ?? 0);

if ($status !== 1) {
    // Encerra sessão antes de retornar erro
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_exec($ch);
    curl_close($ch);

    echo json_encode(['ok' => false, 'msg' => "Chamado #{$ticket_id} não pode ser excluído pois não está em aberto (status atual: {$status})."]);
    exit;
}

// ── Exclui permanentemente do GLPI ────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_HTTPHEADER     => $headers,
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['ok' => true, 'msg' => "Chamado #{$ticket_id} excluído permanentemente do GLPI."]);
} else {
    echo json_encode(['ok' => false, 'msg' => "Falha ao excluir chamado #{$ticket_id} do GLPI (HTTP {$httpCode})."]);
}
