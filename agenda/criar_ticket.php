<?php
/**
 * Cria um chamado no GLPI a partir da agenda
 * POST JSON: { titulo, descricao, tipo, prioridade, atendente_id, setor }
 */
header('Content-Type: application/json');
require_once 'config.php';

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$titulo       = trim($body['titulo']       ?? '');
$descricao    = trim($body['descricao']    ?? '');
$tipo         = $body['tipo']              ?? 'chamado'; // 'chamado'=1, 'requisicao'=2
$prioridade   = (int)($body['prioridade']  ?? 3);
$atendente_id = (int)($body['atendente_id']  ?? 0);
$categoria_id = (int)($body['categoria_id']  ?? 0);
$entidade_id  = (int)($body['entidade_id']   ?? 0);
$requerente_id= (int)($body['requerente_id'] ?? 0);
$origem_id    = (int)($body['origem_id']     ?? 0);

if (!$titulo) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Título é obrigatório']);
    exit;
}

// Mapeia prioridade da agenda para urgência do GLPI (1–5)
$urgencia_map = ['baixa' => 2, 'media' => 3, 'alta' => 4, 'critica' => 5];
$urgencia     = $urgencia_map[$body['prioridade'] ?? 'media'] ?? 3;

// Tipo: 1=Incidente (chamado), 2=Requisição
$tipo_glpi = ($tipo === 'requisicao') ? 2 : 1;

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

// ── Cria o ticket ──────────────────────────────────────────────
$input = [
    'name'     => $titulo,
    'content'  => $descricao ?: $titulo,
    'type'     => $tipo_glpi,
    'urgency'  => $urgencia,
    'priority' => $urgencia,
    'status'   => $atendente_id ? 2 : 1, // Atribuído se tiver técnico, senão Novo
];

// Campos opcionais preenchidos pelo modal da agenda
if ($atendente_id)  $input['_users_id_assign']   = $atendente_id;
if ($requerente_id) $input['_users_id_requester'] = $requerente_id;
if ($categoria_id)  $input['itilcategories_id']   = $categoria_id;
if ($entidade_id)   $input['entities_id']          = $entidade_id;
if ($origem_id)     $input['requesttypes_id']      = $origem_id;

$ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
    CURLOPT_HTTPHEADER     => $headers,
]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
curl_exec($ch);
curl_close($ch);

if (!empty($res['id'])) {
    echo json_encode(['ok' => true, 'ticket_id' => $res['id'], 'msg' => "Chamado #{$res['id']} criado no GLPI."]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Erro ao criar chamado.', 'detail' => $res]);
}
