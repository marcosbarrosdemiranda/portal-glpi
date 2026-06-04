<?php
/**
 * Retorna dados auxiliares do GLPI para o formulário do atendente:
 * categorias, usuários, entidades
 */
header('Content-Type: application/json');
ob_start(); error_reporting(0);
session_start();
if (empty($_SESSION['autenticado'])) { ob_end_clean(); echo json_encode(['error'=>'não autenticado']); exit; }

require_once __DIR__ . '/../agenda/config.php';

$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';
if (!$token) { ob_end_clean(); echo json_encode(['error'=>'falha auth']); exit; }

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

function glpi_get($url, $h) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    return is_array($r) ? $r : [];
}

// Entidade filtro (se passado via GET)
$entidade_id = isset($_GET['entidade_id']) ? (int)$_GET['entidade_id'] : 0;

$categorias = glpi_get(GLPI_URL.'/apirest.php/ITILCategory?range=0-100&expand_dropdowns=true', $h);
$entidades  = glpi_get(GLPI_URL.'/apirest.php/Entity?range=0-50', $h);

// Busca usuários vinculados à entidade via Profile_User
if ($entidade_id > 0) {
    $profile_users = glpi_get(GLPI_URL.'/apirest.php/Profile_User?range=0-500&searchText[entities_id]='.$entidade_id.'&expand_dropdowns=true', $h);
    $user_ids = array_unique(array_column($profile_users, 'users_id'));
    $usuarios = [];
    foreach (array_chunk($user_ids, 20) as $chunk) {
        foreach ($chunk as $uid) {
            $u = glpi_get(GLPI_URL.'/apirest.php/User/'.$uid.'?expand_dropdowns=true', $h);
            if (!empty($u['id']) && ($u['is_active'] ?? 1)) $usuarios[] = $u;
        }
    }
} else {
    $usuarios = glpi_get(GLPI_URL.'/apirest.php/User?range=0-200&is_active=1&expand_dropdowns=true', $h);
}

// Encerra sessão
$ch = curl_init(GLPI_URL.'/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch); curl_close($ch);

$cats = array_filter($categorias, fn($c) => isset($c['id']));
$cats = array_map(fn($c) => [
    'id'   => $c['id'],
    'nome' => $c['completename'] ?? $c['name'] ?? '',
], array_values($cats));

$users = array_filter($usuarios, fn($u) => isset($u['id']));
$users = array_map(function($u) {
    $nome = trim(($u['realname']??'').' '.($u['firstname']??'')) ?: ($u['name']??'');
    return ['id'=>$u['id'],'nome'=>$nome,'login'=>$u['name']??''];
}, array_values($users));
usort($users, fn($a,$b) => strcmp($a['nome'],$b['nome']));

$ents = array_filter($entidades, fn($e) => isset($e['id']));
$ents = array_map(fn($e) => ['id'=>$e['id'],'nome'=>$e['completename']??$e['name']??''], array_values($ents));

ob_end_clean();
echo json_encode(['categorias'=>array_values($cats),'usuarios'=>array_values($users),'entidades'=>array_values($ents)]);
