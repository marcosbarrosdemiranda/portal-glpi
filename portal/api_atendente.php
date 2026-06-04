<?php
/**
 * Abre chamado no GLPI a partir do formulário do atendente
 * POST multipart: titulo, descricao, tipo, urgencia, arquivos[]
 */
ob_start();
error_reporting(0);
session_start();

if (empty($_SESSION['autenticado'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/../agenda/config.php';

$titulo       = trim($_POST['titulo']        ?? '');
$descricao    = trim($_POST['descricao']     ?? '');
$tipo         = (int)($_POST['tipo']         ?? 1);
$urgencia     = (int)($_POST['urgencia']   ?? 3);
$impacto      = 3; // fixo: Médio
$status       = 1; // fixo: Novo
$categoria    = (int)($_POST['categoria'] ?? 0);
$entidade     = (int)($_POST['entidade']  ?? 0);
$origem       = (int)($_POST['origem']    ?? 1);
$requerente_id = (int)($_POST['requerente_id'] ?? 0);
// Atribuído: sem atendente por padrão
$user_id      = (int)($_SESSION['user_id']   ?? 0);

if (!$titulo || !$descricao) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Título e descrição são obrigatórios']);
    exit;
}

// ── Abre sessão ────────────────────────────────
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_CONNECTTIMEOUT  => 5,
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_HTTPHEADER      => ['Authorization: Basic '.$auth, 'App-Token: '.GLPI_APP_TOKEN],
]);
$r = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';

if (!$token) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Falha ao autenticar na API GLPI']);
    exit;
}

$headers = ['Content-Type: application/json','Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN];

// ── Cria o ticket ──────────────────────────────
$prioridade = min(5, (int)round(($urgencia + $impacto) / 2));

$input = [
    'name'              => $titulo,
    'content'           => $descricao,
    'type'              => $tipo,
    'urgency'           => $urgencia,
    'impact'            => $impacto,
    'priority'          => $prioridade,
    'status'            => $status,
    'requesttypes_id'   => $origem,
];
if ($categoria)  $input['itilcategories_id'] = $categoria;
if ($entidade)   $input['entities_id']       = $entidade;
// Requerente será adicionado via Ticket_User após criar o ticket
// Sem atribuído por padrão

$ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 20,
]);
$res       = json_decode(curl_exec($ch), true);
curl_close($ch);
$ticket_id = $res['id'] ?? null;

if (!$ticket_id) {
    // Encerra sessão
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    curl_exec($ch); curl_close($ch);
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Erro ao criar chamado no GLPI', 'detail' => $res]);
    exit;
}

// ── Adiciona requerente via UPDATE ────────────────────────
$req_id = $requerente_id ?: $user_id;
if ($req_id) {
    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode(['input' => [
            '_users_id_requester' => $req_id,
        ]]),
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $req_res = json_decode(curl_exec($ch), true);
    curl_close($ch);
}

// ── Upload de anexos ───────────────────────────
$docs_enviados = 0;
if (!empty($_FILES['arquivos']['name'][0])) {
    foreach ($_FILES['arquivos']['tmp_name'] as $i => $tmp) {
        if ($_FILES['arquivos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $nome = basename($_FILES['arquivos']['name'][$i]);
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $ext  = pathinfo($nome, PATHINFO_EXTENSION);
        $tmpLocal = sys_get_temp_dir() . '/' . uniqid('glpi_', true) . '.' . $ext;
        copy($tmp, $tmpLocal);

        $ch = curl_init(GLPI_URL . '/apirest.php/Document');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'uploadManifest' => json_encode([
                    'input' => [
                        'name'               => $nome,
                        'itemtype'           => 'Ticket',
                        'items_id'           => $ticket_id,
                        '_tag_Document_Item' => uniqid('tag_', true),
                    ]
                ]),
                'filename[0]' => new CURLFile($tmpLocal, $mime, $nome),
            ],
            CURLOPT_HTTPHEADER => ['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN],
        ]);
        $doc = json_decode(curl_exec($ch), true);
        curl_close($ch);
        @unlink($tmpLocal);
        if (!empty($doc['id'])) $docs_enviados++;
    }
}

// ── Encerra sessão ─────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
curl_exec($ch); curl_close($ch);

ob_end_clean();
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'ticket_id' => $ticket_id, 'anexos' => $docs_enviados]);
