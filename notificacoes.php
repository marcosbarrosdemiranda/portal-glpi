<?php
/**
 * Verifica chamados novos desde o último check
 * GET ?ultimo=2026-06-01T10:00:00
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { echo json_encode([]); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

$ultimo = $_GET['ultimo'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
$ultimo = str_replace('T', ' ', $ultimo);

$auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch    = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r     = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';
if (!$token) { echo json_encode([]); exit; }

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

// Busca chamados criados após o último check
$url = GLPI_URL . '/apirest.php/Ticket?range=0-20&order=DESC&expand_dropdowns=true'
     . '&searchText[status]=1'; // status Novo

$ch2 = curl_init($url);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$h]);
$tickets = json_decode(curl_exec($ch2), true) ?? [];
curl_close($ch2);

$ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch3); curl_close($ch3);

$novos = [];
foreach ($tickets as $t) {
    if (!isset($t['id'])) continue;
    $data_ticket = str_replace('T', ' ', $t['date'] ?? '');
    if ($data_ticket > $ultimo) {
        $novos[] = [
            'id'       => $t['id'],
            'titulo'   => $t['name'] ?? 'Sem título',
            'entidade' => apelido_entidade($t['entities_id'] ?? ''),
            'data'     => $t['date'] ?? '',
            'tipo'     => ($t['type'] ?? 1) == 1 ? 'Incidente' : 'Requisição',
        ];
    }
}

echo json_encode($novos);
