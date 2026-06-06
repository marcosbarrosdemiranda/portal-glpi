<?php
/**
 * Verifica chamados na agenda com mais de 1 dia de atraso.
 *
 * Regra:
 *   - Chamados concluídos → ignorados
 *   - Chamados com end < (agora - 24h) E ainda não concluídos:
 *       → remove o evento da agenda
 *       → se NENHUM outro período do mesmo ticket estiver dentro do prazo,
 *         reseta o ticket no GLPI (status=1, remove técnico, volta pro sidebar)
 *   - Eventos/reuniões sem ticket → ignorados (não removem)
 *
 * Ex:
 *   Hoje = Sábado
 *     - Chamados de SEXTA   → ficam (< 24h)
 *     - Chamados de QUINTA  → removidos (> 24h)
 *   Se ticket de QUINTA tiver um novo período cadastrado em SEXTA:
 *     → só remove o período de QUINTA, ticket continua ativo
 */
header('Content-Type: application/json');
ob_start();
error_reporting(0);
require_once 'config.php';
require_once 'db.php';

$agora     = date('Y-m-d H:i:s');
$limite_1d = date('Y-m-d H:i:s', strtotime('-24 hours'));

// ── Busca eventos com +24h de atraso (não concluídos, com ticket) ──
$stmt = $pdo->prepare("
    SELECT * FROM glpi_plugin_agenda_events
    WHERE concluido = 0 AND ticket_id IS NOT NULL AND end < ?
    ORDER BY end ASC
");
$stmt->execute([$limite_1d]);
$atrasados = $stmt->fetchAll();

$para_avisar = []; // eventos sem ticket atrasados (só aviso, não remove)
$para_remover = [];

foreach ($atrasados as $ev) {
    $tid = $ev['ticket_id'];
    if (!$tid) {
        $para_avisar[] = $ev['id'];
        continue;
    }

    // Verifica se o mesmo ticket tem OUTRO período ainda dentro do prazo (< 24h de atraso)
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM glpi_plugin_agenda_events
        WHERE ticket_id = ? AND id != ? AND end >= ? AND concluido = 0
    ");
    $check->execute([$tid, $ev['id'], $limite_1d]);
    $tem_periodo_recente = (int)$check->fetchColumn() > 0;

    $para_remover[] = [
        'id'            => $ev['id'],
        'ticket_id'     => (int)$tid,
        'reset_ticket'  => !$tem_periodo_recente, // só reseta se não houver período recente
    ];
}

$ids_remover          = array_column(array_filter($para_remover, fn($r) => $r['id']), 'id');
$tickets_para_resetar = array_unique(array_column(
    array_filter($para_remover, fn($r) => $r['reset_ticket']),
    'ticket_id'
));

// ── Remove da agenda ──
if (!empty($ids_remover)) {
    $placeholders = implode(',', array_fill(0, count($ids_remover), '?'));
    $pdo->prepare("DELETE FROM glpi_plugin_agenda_events WHERE id IN ($placeholders)")->execute($ids_remover);
}

// ── Reseta tickets no GLPI ──
if (!empty($tickets_para_resetar)) {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'App-Token: ' . GLPI_APP_TOKEN,
        ],
    ]);
    $r     = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $token = $r['session_token'] ?? '';

    if ($token) {
        $headers = [
            'Content-Type: application/json',
            'Session-Token: ' . $token,
            'App-Token: ' . GLPI_APP_TOKEN,
        ];

        foreach ($tickets_para_resetar as $tid) {
            // Status → Novo (1)
            $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => json_encode(['input' => ['status' => 1]]),
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            curl_exec($ch);
            curl_close($ch);

            // Remove técnicos (type=2)
            $ch = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid . '/Ticket_User');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
            $users = json_decode(curl_exec($ch), true) ?? [];
            curl_close($ch);
            foreach ($users as $u) {
                if (!isset($u['id']) || (int)($u['type'] ?? 0) !== 2) continue;
                $ch = curl_init(GLPI_URL . '/apirest.php/Ticket_User/' . $u['id']);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => $headers]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch);
        curl_close($ch);
    }
}

ob_end_clean();
echo json_encode([
    'ok'              => true,
    'removidos'       => count($ids_remover),
    'periodos_antigos'=> count($ids_remover) - count($tickets_para_resetar), // períodos removidos mas ticket continua
    'tickets'         => $tickets_para_resetar,
    'para_avisar'     => $para_avisar,
]);
