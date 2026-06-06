<?php
/**
 * Anexa arquivos a um ticket já existente no GLPI
 * POST multipart/form-data: ticket_id, arquivos[]
 *
 * Os documentos são vinculados diretamente ao Ticket (não a um followup).
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

$ticket_id = (int)($_POST['ticket_id'] ?? 0);

if (!$ticket_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'ticket_id é obrigatório']);
    exit;
}

if (empty($_FILES['arquivos']['name'][0])) {
    echo json_encode(['ok' => true, 'anexos' => 0, 'msg' => 'Nenhum arquivo para anexar.']);
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

$docs_enviados = 0;

foreach ($_FILES['arquivos']['tmp_name'] as $i => $tmp) {
    if ($_FILES['arquivos']['error'][$i] !== UPLOAD_ERR_OK) continue;

    $nome = basename($_FILES['arquivos']['name'][$i]);
    $mime = mime_content_type($tmp) ?: 'application/octet-stream';

    $ext      = pathinfo($nome, PATHINFO_EXTENSION);
    $tmpLocal = sys_get_temp_dir() . '/' . uniqid('glpi_', true) . '.' . $ext;
    copy($tmp, $tmpLocal);

    $tag = uniqid('tag_', true);

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
                    '_tag_Document_Item' => $tag,
                ]
            ]),
            'filename[0]' => new CURLFile($tmpLocal, $mime, $nome),
        ],
        CURLOPT_HTTPHEADER => [
            'Session-Token: ' . $token,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $doc = json_decode(curl_exec($ch), true);
    curl_close($ch);
    @unlink($tmpLocal);

    if (!empty($doc['id'])) $docs_enviados++;
}

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
]);
curl_exec($ch);
curl_close($ch);

ob_end_clean();
header('Content-Type: application/json');

echo json_encode([
    'ok'     => true,
    'anexos' => $docs_enviados,
    'msg'    => $docs_enviados > 0 ? "$docs_enviados anexo(s) enviado(s)." : 'Nenhum anexo enviado.',
]);
