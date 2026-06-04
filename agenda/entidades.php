<?php
header('Content-Type: application/json');
require_once 'config.php';

$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';
if (!$token) { echo json_encode([]); exit; }

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

$ch2 = curl_init(GLPI_URL . '/apirest.php/Entity?range=0-100&expand_dropdowns=true');
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$entidades = json_decode(curl_exec($ch2), true) ?? [];
curl_close($ch2);

$ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch3); curl_close($ch3);

$result = [];
foreach ($entidades as $e) {
    if (!isset($e['id'])) continue;
    if ((int)$e['id'] === 0) continue; // Exclui entidade raiz
    $nome = $e['completename'] ?? $e['name'] ?? '';
    if (strtolower($nome) === 'entidade raiz' || !$nome) continue;
    $result[] = ['id' => $e['id'], 'nome' => $nome];
}

usort($result, fn($a,$b) => strcmp($a['nome'], $b['nome']));
echo json_encode($result);
