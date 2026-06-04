<?php
/**
 * Endpoint AJAX para sincronizar rotinas manualmente
 * Regra: chamado recorrente vai para o atendente configurado no GLPI.
 * Se o ticket não tiver técnico no GLPI → evento criado sem atendente (não atribui ao logado).
 */
session_start();
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$hoje = date('Y-m-d');
$adicionados = 0;
$ignorados   = 0;

// Abre sessão GLPI
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Basic '.$auth, 'App-Token: '.GLPI_APP_TOKEN],
]);
$r     = json_decode(curl_exec($ch), true);
curl_close($ch);
$token = $r['session_token'] ?? '';

if (!$token) {
    echo json_encode(['ok'=>false,'msg'=>'Falha ao autenticar na API GLPI']);
    exit;
}

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

// ── Mapa de usuários: id → nome (mesmo formato de users.php) ────────────────
$ch_usr = curl_init(GLPI_URL . '/apirest.php/User?range=0-500');
curl_setopt_array($ch_usr, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$users_raw = json_decode(curl_exec($ch_usr), true) ?? [];
curl_close($ch_usr);

$user_map = []; // user_id (int) => nome "Sobrenome Nome"
foreach ((array)$users_raw as $u) {
    if (!isset($u['id'])) continue;
    $nome = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
    if (!$nome) $nome = $u['name'] ?? '';
    $user_map[(int)$u['id']] = $nome;
}

// Busca todos os chamados criados hoje
$ch2 = curl_init(GLPI_URL . '/apirest.php/Ticket?range=0-200&order=ASC&sort=date&expand_dropdowns=true&searchText[date]=' . $hoje);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$todos = json_decode(curl_exec($ch2), true) ?? [];
curl_close($ch2);

// Filtra apenas entidade raiz
$tickets = array_filter((array)$todos, function($t) {
    if (!isset($t['id'])) return false;
    $ent = strtolower($t['entities_id'] ?? '');
    return $ent === 'entidade raiz' || $ent === '' || ($t['entities_id'] ?? -1) === 0;
});

// Tickets já na agenda hoje
$agendados_hoje = $pdo->query(
    "SELECT ticket_id FROM glpi_plugin_agenda_events WHERE DATE(start) = '$hoje' AND ticket_id IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);
$agendados_set = array_flip($agendados_hoje);

// ── Corrige todos os chamados ativos de HOJE: sincroniza atendente com o GLPI ─
// Cobre tanto eventos sem atendente quanto eventos com atendente desatualizado.
$stmt_hoje = $pdo->query(
    "SELECT id, ticket_id, atendente_id FROM glpi_plugin_agenda_events
     WHERE DATE(start) = '$hoje'
       AND ticket_id IS NOT NULL
       AND concluido = 0"
);
$chamados_hoje_agenda = $stmt_hoje->fetchAll();

foreach ($chamados_hoje_agenda as $ev) {
    $tid = (int)$ev['ticket_id'];

    // Busca técnico (type=2) do ticket no GLPI
    $ch_tu = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid . '/Ticket_User');
    curl_setopt_array($ch_tu, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $tus = json_decode(curl_exec($ch_tu), true) ?? [];
    curl_close($ch_tu);

    $fix_id   = null;
    $fix_nome = '';
    foreach ((array)$tus as $tu) {
        if ((int)($tu['type'] ?? 0) === 2) {
            $uid = (int)($tu['users_id'] ?? 0);
            if ($uid) {
                $fix_id   = $uid;
                $fix_nome = $user_map[$uid] ?? '';
            }
            break;
        }
    }

    // Sem técnico no GLPI → mantém como está (não atribui ao logado)
    if (!$fix_nome || !$fix_id) continue;

    // Só atualiza se o atendente for diferente do que está na agenda
    if ((int)$ev['atendente_id'] === $fix_id) continue;

    $fix_cor = $cores[abs(crc32($fix_nome)) % count($cores)];
    $pdo->prepare("
        UPDATE glpi_plugin_agenda_events
        SET atendente = :nome, atendente_id = :uid, atendente_cor = :cor
        WHERE id = :id
    ")->execute([':nome' => $fix_nome, ':uid' => $fix_id, ':cor' => $fix_cor, ':id' => $ev['id']]);
}

$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];

foreach ($tickets as $t) {
    $ticket_id = (int)$t['id'];

    if (isset($agendados_set[$ticket_id])) {
        $ignorados++;
        continue;
    }

    // Busca atendente técnico (type=2) via Ticket_User
    $ch4 = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $ticket_id . '/Ticket_User');
    curl_setopt_array($ch4, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $ticket_users = json_decode(curl_exec($ch4), true) ?? [];
    curl_close($ch4);

    $atendente_nome = '';
    $atendente_id   = null;

    foreach ((array)$ticket_users as $tu) {
        if (($tu['type'] ?? 0) == 2) {
            $uid = (int)($tu['users_id'] ?? 0);
            if ($uid) {
                $atendente_id   = $uid;
                $atendente_nome = $user_map[$uid] ?? '';
            }
            break;
        }
    }

    // Se o ticket não tem técnico no GLPI, a rotina fica sem atendente.
    // Não atribui ao logado — o atendente correto é o configurado no GLPI.

    // Horário: usa hora de abertura + 30 min
    $data_abertura = $t['date'] ?? ($hoje . ' 09:00:00');
    $start_dt      = date('Y-m-d H:i:s', strtotime($data_abertura));
    $end_dt        = date('Y-m-d H:i:s', strtotime($start_dt . ' +30 minutes'));

    $cor = $cores[abs(crc32($atendente_nome)) % count($cores)];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO glpi_plugin_agenda_events
                (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor, prioridade, setor, ticket_id, tipo, concluido)
            VALUES
                (:id, :titulo, :desc, :start, :end, :atendente, :atendente_id, :cor, 'media', :setor, :ticket_id, 'chamado', 0)
            ON DUPLICATE KEY UPDATE id=id
        ");
        $stmt->execute([
            ':id'           => uniqid('rot_', true),
            ':titulo'       => '#' . $ticket_id . ' – ' . ($t['name'] ?? 'Rotina'),
            ':desc'         => 'Rotina automática',
            ':start'        => $start_dt,
            ':end'          => $end_dt,
            ':atendente'    => $atendente_nome,
            ':atendente_id' => $atendente_id,
            ':cor'          => $cor,
            ':setor'        => $t['entities_id'] ?? '',
            ':ticket_id'    => $ticket_id,
        ]);
        $adicionados++;
    } catch (Exception $e) {
        // ignora duplicatas
    }
}

// Encerra sessão GLPI
$ch_kill = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch_kill, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch_kill); curl_close($ch_kill);

echo json_encode([
    'ok'          => true,
    'adicionados' => $adicionados,
    'ignorados'   => $ignorados,
    'msg'         => "$adicionados rotina(s) adicionada(s), $ignorados já existiam"
]);
