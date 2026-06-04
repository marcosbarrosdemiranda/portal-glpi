<?php
/**
 * Adiciona acompanhamento ao chamado no GLPI com suporte a anexos
 * POST multipart/form-data: ticket_id, resposta, arquivos[]
 */
ob_start(); // captura qualquer saída espúria (warnings do GLPI)
error_reporting(E_ALL);
ini_set('display_errors', 0); // erros vão para log, não para saída
require_once 'config.php';

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$resposta  = trim($_POST['resposta']   ?? '');

if (!$ticket_id || !$resposta) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'ticket_id e resposta são obrigatórios']);
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

$headers_json = [
    'Content-Type: application/json',
    'Session-Token: ' . $token,
    'App-Token: ' . GLPI_APP_TOKEN,
];

// ── Cria o followup ────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/ITILFollowup');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'input' => [
            'items_id'        => $ticket_id,
            'itemtype'        => 'Ticket',
            'content'         => $resposta,
            'is_private'      => 0,
            'requesttypes_id' => 1,
        ]
    ]),
    CURLOPT_HTTPHEADER => $headers_json,
]);
$res_followup = json_decode(curl_exec($ch), true);
curl_close($ch);

$followup_id  = $res_followup['id'] ?? null;
$docs_enviados = 0;

// ── Upload de arquivos e vínculo ao followup ───────────────────
if ($followup_id && !empty($_FILES['arquivos']['name'][0])) {
    foreach ($_FILES['arquivos']['tmp_name'] as $i => $tmp) {
        if ($_FILES['arquivos']['error'][$i] !== UPLOAD_ERR_OK) continue;

        $nome = basename($_FILES['arquivos']['name'][$i]);
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';

        // Copia para um path acessível com extensão correta
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
                        'itemtype'           => 'ITILFollowup',
                        'items_id'           => $followup_id,
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
}

// ── Encerra sessão ─────────────────────────────────────────────
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
]);
curl_exec($ch);
curl_close($ch);

ob_end_clean(); // descarta qualquer warning/output anterior
header('Content-Type: application/json');

if ($followup_id) {
    echo json_encode([
        'ok'          => true,
        'followup_id' => $followup_id,
        'anexos'      => $docs_enviados,
        'msg'         => 'Resposta enviada com sucesso!',
    ]);
} else {
    // Extrai mensagem de erro do GLPI (pode ser array com code+message ou string)
    $glpi_msg = '';
    if (is_array($res_followup)) {
        $glpi_msg = $res_followup['message'] ?? ($res_followup[1] ?? json_encode($res_followup));
    } elseif (is_string($res_followup)) {
        $glpi_msg = $res_followup;
    }
    echo json_encode([
        'ok'     => false,
        'msg'    => 'Erro ao criar acompanhamento no GLPI' . ($glpi_msg ? ': ' . $glpi_msg : '.'),
        'detail' => $res_followup,
        'ticket' => $ticket_id,
    ]);
}
