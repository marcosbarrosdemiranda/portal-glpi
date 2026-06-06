<?php
/**
 * Atribui um chamado GLPI a um ou mais técnicos e muda o status para "Atribuído" (2)
 * Chamado via POST com JSON: { ticket_id, atendente_id } ou { ticket_id, atendentes_ids }
 *
 * `atendentes_ids` (array) permite atribuir múltiplos técnicos de uma vez.
 * Se omitido, usa `atendente_id` (int único) para compatibilidade.
 */
header('Content-Type: application/json');
require_once 'config.php';

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id    = (int)($body['ticket_id']   ?? 0);

// Suporte a múltiplos atendentes
$atendentes_ids = $body['atendentes_ids'] ?? [];
if (!is_array($atendentes_ids)) $atendentes_ids = [];

$atendente_id = (int)($body['atendente_id'] ?? 0);

// Se só tem atendente_id (array vazio), adiciona como único
if ($atendente_id && empty($atendentes_ids)) {
    $atendentes_ids[] = $atendente_id;
}

// Filtra inteiros válidos
$atendentes_ids = array_map('intval', array_filter($atendentes_ids, fn($v) => is_numeric($v)));
$atendentes_ids = array_values(array_unique($atendentes_ids));

if (!$ticket_id || empty($atendentes_ids)) {
    echo json_encode(['ok' => false, 'msg' => 'ticket_id e atendente_id/atendentes_ids são obrigatórios']);
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

// Atribui novo(s) técnico(s) (type=2 → técnico)
$erros = [];
$ultimoRes = null;
foreach ($atendentes_ids as $aid) {
    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['input' => [
            'tickets_id' => $ticket_id,
            'users_id'   => $aid,
            'type'       => 2, // 1=Solicitante, 2=Técnico, 3=Observador
        ]]),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $ultimoRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (!isset($ultimoRes['id'])) $erros[] = "Falha ao atribuir técnico ID $aid";
}

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
curl_close($ch);

$numAtrib = count($atendentes_ids);
$sucesso  = empty($erros);
echo json_encode([
    'ok'  => $sucesso,
    'msg' => $sucesso
        ? "Ticket #{$ticket_id} atribuído a {$numAtrib} técnico(s) com sucesso."
        : implode('; ', $erros),
    'ticket_update' => $resTicket,
    'tecnico'       => $ultimoRes,
]);
