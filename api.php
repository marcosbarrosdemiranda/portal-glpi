<?php
header('Content-Type: application/json');
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$nome     = trim($_POST['nome'] ?? '');
$email    = trim($_POST['email'] ?? '');
$setor    = trim($_POST['setor'] ?? '');
$tipo     = trim($_POST['tipo'] ?? '');
$urgencia = (int)($_POST['urgencia'] ?? 3);
$titulo   = trim($_POST['titulo'] ?? '');
$descricao = trim($_POST['descricao'] ?? '');

if (!$nome || !$email || !$titulo || !$descricao) {
    http_response_code(400);
    echo json_encode(['error' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

// --- Autenticação ---
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);

$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth,
        'App-Token: ' . GLPI_APP_TOKEN,
    ],
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($response['session_token'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao autenticar na API do GLPI.']);
    exit;
}
$session = $response['session_token'];

// --- Criação do chamado ---
$corpo = "**Solicitante:** $nome\n**E-mail:** $email\n**Setor:** $setor\n\n$descricao";

$ticket = [
    'input' => [
        'name'     => $titulo,
        'content'  => $corpo,
        'urgency'  => $urgencia,
        'type'     => $tipo === 'incident' ? 1 : 2, // 1=Incidente, 2=Requisição
        'status'   => 1, // Novo
    ]
];

$ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($ticket),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Session-Token: ' . $session,
        'App-Token: ' . GLPI_APP_TOKEN,
    ],
]);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

// --- Encerra sessão ---
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_HTTPHEADER     => [
        'Session-Token: ' . $session,
        'App-Token: ' . GLPI_APP_TOKEN,
    ],
]);
curl_exec($ch);
curl_close($ch);

if (!empty($result['id'])) {
    echo json_encode(['success' => true, 'ticket_id' => $result['id']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao criar chamado.', 'detail' => $result]);
}
