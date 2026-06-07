<?php
/**
 * Move um chamado em aberto para a LIXEIRA do GLPI (PUT is_deleted=1)
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

// Permite excluir chamados em Novo (1) ou Atribuído (2)
if (!in_array($status, [1, 2], true)) {
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_exec($ch);
    curl_close($ch);

    $statusLabels = [1 => 'Novo', 2 => 'Atribuído', 3 => 'Planejado', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];
    $label = $statusLabels[$status] ?? "desconhecido ({$status})";
    echo json_encode(['ok' => false, 'msg' => "Chamado #{$ticket_id} está como «{$label}». Só é possível excluir chamados em Novo ou Atribuído."]);
    exit;
}

// ── Move para a LIXEIRA do GLPI (soft delete: is_deleted=1) ──
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode(['input' => ['is_deleted' => 1]]),
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
    echo json_encode(['ok' => true, 'msg' => "Chamado #{$ticket_id} movido para a lixeira do GLPI."]);
} else {
    echo json_encode(['ok' => false, 'msg' => "Falha ao mover chamado #{$ticket_id} para a lixeira (HTTP {$httpCode})."]);
}
