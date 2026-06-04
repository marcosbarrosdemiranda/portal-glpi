<?php
/**
 * Sincroniza TODOS os campos de um chamado/requisição da agenda com o GLPI.
 * POST JSON: { ticket_id, titulo, descricao, tipo, prioridade,
 *              categoria_id, entidade_id, requerente_id, origem_id }
 *
 * Atualiza apenas os campos que foram informados (não-nulos/não-vazios).
 * O atendente (técnico) é gerenciado separadamente por atribuir_ticket.php.
 */
header('Content-Type: application/json');
require_once 'config.php';

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id = (int)($body['ticket_id'] ?? 0);

if (!$ticket_id) {
    echo json_encode(['ok' => false, 'msg' => 'ticket_id obrigatório']);
    exit;
}

// ── Lê campos informados ────────────────────────────────────────
$titulo        = isset($body['titulo'])        && $body['titulo']        !== '' ? trim($body['titulo'])     : null;
$descricao     = isset($body['descricao'])     && $body['descricao']     !== '' ? trim($body['descricao'])  : null;
$tipo          = isset($body['tipo'])          && $body['tipo']          !== '' ? $body['tipo']              : null;
$prioridade    = isset($body['prioridade'])    && $body['prioridade']    !== '' ? $body['prioridade']        : null;
$categoria_id  = isset($body['categoria_id'])  && $body['categoria_id']  ? (int)$body['categoria_id']       : null;
$entidade_id   = isset($body['entidade_id'])   && $body['entidade_id']   ? (int)$body['entidade_id']        : null;
$requerente_id = isset($body['requerente_id']) && $body['requerente_id'] ? (int)$body['requerente_id']      : null;
$origem_id     = isset($body['origem_id'])     && $body['origem_id']     ? (int)$body['origem_id']          : null;

// ── Monta payload do PUT /Ticket/{id} ──────────────────────────
$input = [];

if ($titulo)       $input['name']               = $titulo;
if ($descricao)    $input['content']             = $descricao;
if ($categoria_id) $input['itilcategories_id']   = $categoria_id;
if ($entidade_id)  $input['entities_id']         = $entidade_id;
if ($origem_id)    $input['requesttypes_id']     = $origem_id;

if ($tipo) {
    $input['type'] = ($tipo === 'requisicao') ? 2 : 1; // 1=Incidente, 2=Requisição
}
if ($prioridade) {
    $mapa = ['baixa' => 2, 'media' => 3, 'alta' => 4, 'critica' => 5];
    $urg  = $mapa[$prioridade] ?? null;
    if ($urg) { $input['urgency'] = $urg; $input['priority'] = $urg; }
}

// Nada a fazer?
if (empty($input) && !$requerente_id) {
    echo json_encode(['ok' => true, 'campos' => 0, 'msg' => 'Nenhum campo para atualizar']);
    exit;
}

// ── Abre sessão GLPI ───────────────────────────────────────────
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
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

$campos_atualizados = 0;

// ── PUT /Ticket/{id} — atualiza campos principais ──────────────
if (!empty($input)) {
    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res_put = json_decode(curl_exec($ch), true);
    curl_close($ch);
    // Resposta do PUT é [ {"id": X, "message": ""} ] ou {"id": X}
    if (!empty($res_put['id']) || (!empty($res_put[0]) && !empty($res_put[0]['id']))) {
        $campos_atualizados += count($input);
    }
}

// ── Requerente (Ticket_User type=1) ───────────────────────────
if ($requerente_id) {
    // Busca requerentes atuais do ticket
    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id . '/Ticket_User');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $ticket_users = json_decode(curl_exec($ch), true) ?? [];
    curl_close($ch);

    $req_existente = null;
    foreach ((array)$ticket_users as $u) {
        if ((int)($u['type'] ?? 0) === 1) {
            $req_existente = $u;
            break;
        }
    }

    if ($req_existente) {
        // Atualiza requerente existente
        $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User/' . $req_existente['id']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode(['input' => ['users_id' => $requerente_id]]),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } else {
        // Cria novo vínculo de requerente
        $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['input' => [
                'tickets_id' => $ticket_id,
                'users_id'   => $requerente_id,
                'type'       => 1, // 1 = Requerente
            ]]),
            CURLOPT_HTTPHEADER => $headers,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    $campos_atualizados++;
}

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
curl_exec($ch);
curl_close($ch);

echo json_encode([
    'ok'     => true,
    'campos' => $campos_atualizados,
    'msg'    => "$campos_atualizados campo(s) atualizado(s) no GLPI.",
]);
