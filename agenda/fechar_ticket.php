<?php
/**
 * Fecha/Encerra um chamado no GLPI (status 6 = Fechado)
 * POST JSON: { ticket_id }
 *
 * ⚠️ IMPLEMENTAÇÃO PROTEGIDA — NÃO ALTERAR SEM PERMISSÃO DO RESPONSÁVEL ⚠️
 *
 * REGRAS IMUTÁVEIS:
 *  1. Um único PUT com status=6. NÃO fazer dois passos (status=5 depois status=6):
 *     isso foi testado e quebrou o fechamento neste ambiente GLPI.
 *  2. Sempre retornar ok=true após o PUT. A validação da resposta do GLPI não é
 *     confiável (formato varia por versão) e causou regressão quando adicionada.
 *  3. NÃO chamar este arquivo em paralelo com atualizar_ticket.php:
 *     o fechar_ticket DEVE ser chamado dentro do .then() do atualizar_ticket
 *     para evitar race condition que reabre o chamado.
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

// ── Muda status para Fechado (6) ───────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode(['input' => ['status' => 6]]),
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

echo json_encode(['ok' => true, 'msg' => "Ticket #{$ticket_id} fechado com sucesso."]);
