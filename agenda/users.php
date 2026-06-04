<?php
/**
 * Retorna lista de usuários ativos do GLPI para uso como atendentes
 */
header('Content-Type: application/json');
require_once 'config.php';

function glpi_get_users(): array {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);

    // Abre sessão
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
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
    if (!$token) return [];

    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

    // Busca o ID do perfil Super Admin
    $ch = curl_init(GLPI_URL . '/apirest.php/Profile?range=0-50&expand_dropdowns=true');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $profiles = json_decode(curl_exec($ch), true) ?? [];
    curl_close($ch);

    // Encontra IDs de perfis Super Admin
    $perfis_admin = [];
    foreach ($profiles as $p) {
        if (!isset($p['id'])) continue;
        $nome_perfil = strtolower($p['name'] ?? '');
        if (str_contains($nome_perfil, 'super') || str_contains($nome_perfil, 'admin') || str_contains($nome_perfil, 'técnico') || str_contains($nome_perfil, 'tecnico') || str_contains($nome_perfil, 'technician')) {
            $perfis_admin[] = $p['id'];
        }
    }

    // Busca usuários com perfil Super Admin via Profile_User
    $user_ids_admin = [];
    foreach ($perfis_admin as $pid) {
        $ch = curl_init(GLPI_URL . '/apirest.php/Profile_User?searchText[profiles_id]='.$pid.'&range=0-200');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
        $pu = json_decode(curl_exec($ch), true) ?? [];
        curl_close($ch);
        foreach ($pu as $row) {
            if (isset($row['users_id'])) $user_ids_admin[$row['users_id']] = true;
        }
    }

    // Busca todos os usuários ativos
    $ch = curl_init(GLPI_URL . '/apirest.php/User?range=0-200&expand_dropdowns=true');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $users = json_decode(curl_exec($ch), true) ?? [];
    curl_close($ch);

    // Encerra sessão
    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch); curl_close($ch);

    // Cores fixas por posição
    $cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7','#546e7a','#f4511e'];

    // Usuários ocultos da agenda (sistema/genéricos)
    $usuarios_ocultos = ['glpi', 'tech', 'post-only', 'normal', 'glpi-system'];

    $result = [];
    foreach ($users as $i => $u) {
        if (!isset($u['id'])) continue;
        if (($u['is_active'] ?? 0) != 1) continue;
        // Oculta usuários do sistema
        if (in_array(strtolower($u['name'] ?? ''), $usuarios_ocultos)) continue;
        // Filtra apenas Super Admin (se encontrou perfis)
        if (!empty($user_ids_admin) && !isset($user_ids_admin[$u['id']])) continue;
        $nome = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
        if (!$nome) $nome = $u['name'] ?? 'Usuário ' . $u['id'];
        $result[] = [
            'id'   => $u['id'],
            'nome' => $nome,
            'login'=> $u['name'] ?? '',
            'cor'  => $cores[count($result) % count($cores)],
        ];
    }

    // Ordena por nome
    usort($result, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    return $result;
}

// Se ?todos=1 retorna todos os usuários (para dropdown de requerente)
$todos = isset($_GET['todos']) && $_GET['todos'] == '1';
if ($todos) {
    // Busca simples sem filtro de perfil
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

    $ch2 = curl_init(GLPI_URL . '/apirest.php/User?range=0-500&expand_dropdowns=true');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $users = json_decode(curl_exec($ch2), true) ?? [];
    curl_close($ch2);

    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch3); curl_close($ch3);

    $ocultos = ['glpi', 'tech', 'post-only', 'normal', 'glpi-system'];
    $result = [];
    foreach ($users as $u) {
        if (!isset($u['id'])) continue;
        if (($u['is_active'] ?? 0) != 1) continue;
        if (in_array(strtolower($u['name'] ?? ''), $ocultos)) continue;
        $nome = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? '')) ?: ($u['name'] ?? '');
        $result[] = ['id' => $u['id'], 'nome' => $nome, 'login' => $u['name'] ?? ''];
    }
    usort($result, fn($a,$b) => strcmp($a['nome'], $b['nome']));
    echo json_encode($result);
} else {
    echo json_encode(glpi_get_users());
}
