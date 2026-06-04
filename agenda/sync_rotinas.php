<?php
/**
 * Sincroniza chamados de rotina (Entidade raiz) para a agenda
 * Deve rodar via cron às 06:30 todo dia
 * Cron: 30 6 * * * php /var/www/html/glpi/agenda/sync_rotinas.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$hoje = date('Y-m-d');
$log  = [];

function log_msg(string $msg): void {
    global $log;
    $log[] = '[' . date('H:i:s') . '] ' . $msg;
    echo $msg . "\n";
}

log_msg("=== Sync Rotinas: $hoje ===");

// ── Abre sessão GLPI ──────────────────────────────────────────
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
    log_msg("ERRO: Falha ao autenticar na API GLPI");
    exit(1);
}

$h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

// ── Mapa de usuários: id → "Sobrenome Nome" (mesmo formato de users.php) ──
$ch_usr = curl_init(GLPI_URL . '/apirest.php/User?range=0-500');
curl_setopt_array($ch_usr, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$users_raw = json_decode(curl_exec($ch_usr), true) ?? [];
curl_close($ch_usr);

$user_map = [];
foreach ((array)$users_raw as $u) {
    if (!isset($u['id'])) continue;
    $nome = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
    if (!$nome) $nome = $u['name'] ?? '';
    $user_map[(int)$u['id']] = $nome;
}
log_msg("Mapa de usuários carregado: " . count($user_map) . " usuário(s)");

// ── Busca chamados criados HOJE com entidade raiz ─────────────
// Entidade raiz = entities_id = 0
$url = GLPI_URL . '/apirest.php/Ticket?range=0-100&order=ASC&sort=date&expand_dropdowns=true'
     . '&searchText[entities_id]=Entidade raiz'
     . '&searchText[date]=' . $hoje;

$ch2 = curl_init($url);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
$tickets = json_decode(curl_exec($ch2), true) ?? [];
curl_close($ch2);

if (!is_array($tickets) || isset($tickets['ERROR'])) {
    // Tenta busca alternativa — todos criados hoje
    $url2 = GLPI_URL . '/apirest.php/Ticket?range=0-200&order=ASC&sort=date&expand_dropdowns=true'
          . '&searchText[date]=' . $hoje;
    $ch3 = curl_init($url2);
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $todos = json_decode(curl_exec($ch3), true) ?? [];
    curl_close($ch3);

    // Filtra apenas entidade raiz
    $tickets = array_filter($todos, function($t) {
        $ent = strtolower($t['entities_id'] ?? '');
        return $ent === 'entidade raiz' || $ent === '' || ($t['entities_id'] ?? -1) === 0;
    });
}

log_msg("Chamados de rotina encontrados hoje: " . count($tickets));

if (empty($tickets)) {
    log_msg("Nenhum chamado de rotina hoje. Encerrando.");
    $ch_kill = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch_kill, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch_kill); curl_close($ch_kill);
    exit(0);
}

// ── Busca tickets já na agenda hoje ──────────────────────────
$agendados_hoje = $pdo->query(
    "SELECT ticket_id FROM glpi_plugin_agenda_events
     WHERE DATE(start) = '$hoje' AND ticket_id IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);
$agendados_set = array_flip($agendados_hoje);

$adicionados = 0;
$ignorados   = 0;

foreach ($tickets as $t) {
    if (!isset($t['id'])) continue;

    $ticket_id = (int)$t['id'];

    // Já está na agenda hoje?
    if (isset($agendados_set[$ticket_id])) {
        log_msg("  #$ticket_id — já na agenda, ignorando");
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

    // REGRA: rotina SEMPRE deve ter atendente definido
    // Cron não tem sessão → usa o atendente padrão configurado em config.php
    if ((!$atendente_nome || !$atendente_id) && SYNC_ATENDENTE_ID && SYNC_ATENDENTE_NOME) {
        $atendente_id   = SYNC_ATENDENTE_ID;
        $atendente_nome = SYNC_ATENDENTE_NOME;
        log_msg("  #$ticket_id — sem técnico no GLPI, atribuído ao padrão: $atendente_nome");
    }

    // Define horário: usa hora de abertura do chamado
    $data_abertura = $t['date'] ?? ($hoje . ' 09:00:00');
    $start_dt      = date('Y-m-d H:i:s', strtotime($data_abertura));
    $end_dt        = date('Y-m-d H:i:s', strtotime($start_dt . ' +30 minutes'));

    // Cor do atendente (fixas por nome)
    $cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];
    $cor   = $cores[abs(crc32($atendente_nome)) % count($cores)];

    $titulo    = '#' . $ticket_id . ' – ' . ($t['name'] ?? 'Rotina');
    $ev_id     = uniqid('rot_', true);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO glpi_plugin_agenda_events
                (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor, prioridade, setor, ticket_id, tipo, concluido)
            VALUES
                (:id, :titulo, :desc, :start, :end, :atendente, :atendente_id, :cor, 'media', :setor, :ticket_id, 'chamado', 0)
            ON DUPLICATE KEY UPDATE id=id
        ");
        $stmt->execute([
            ':id'          => $ev_id,
            ':titulo'      => $titulo,
            ':desc'        => 'Rotina automática',
            ':start'       => $start_dt,
            ':end'         => $end_dt,
            ':atendente'   => $atendente_nome,
            ':atendente_id'=> $atendente_id,
            ':cor'         => $cor,
            ':setor'       => $t['entities_id'] ?? '',
            ':ticket_id'   => $ticket_id,
        ]);

        log_msg("  #$ticket_id '{$t['name']}' → agenda {$start_dt} → {$atendente_nome}");
        $adicionados++;

    } catch (Exception $e) {
        log_msg("  ERRO #$ticket_id: " . $e->getMessage());
    }
}

// ── Encerra sessão ────────────────────────────────────────────
$ch_kill = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch_kill, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
curl_exec($ch_kill); curl_close($ch_kill);

log_msg("=== Concluído: $adicionados adicionados, $ignorados ignorados ===");

// Salva log
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
file_put_contents($log_dir . '/sync_' . $hoje . '.log', implode("\n", $log) . "\n");
