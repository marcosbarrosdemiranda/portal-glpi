<?php
/**
 * Sincroniza chamados de rotina (Entidade raiz) para a agenda e converte pre-chamados
 * Usa SQL direto via PDO (ignora REST API com índice desatualizado)
 *
 * Roda via Tarefa Agendada do Windows no host glpi-server:
 * \Portal\Agenda-Sync-Rotinas — diária às 06:30, repete a cada 15min por 3h
 * (até 09:30), usuário SYSTEM. Repetição é necessária porque o motor nativo
 * do GLPI (glpi_crontasks.RecurrentItems) só cria os chamados reais de
 * rotina por volta de 07:55 (não 06:30) — ver
 * Portal-Glpi/Logs/2026-10-27-sessao-prechamado-cron-06h30.md.
 * Comando: docker.exe exec glpi-web php /var/www/html/glpi2/portal-glpi/agenda/sync_rotinas.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pre_chamado_lib.php';

$hoje    = date('Y-m-d');
$passado = date('Y-m-d', strtotime('-7 days'));
$log     = [];

function log_msg(string $msg): void {
    global $log;
    $log[] = '[' . date('H:i:s') . '] ' . $msg;
    echo $msg . "\n";
}

log_msg("=== Sync Rotinas & Pré-chamados (PDO): $hoje ===");

$adicionados = 0;
$atualizados = 0;
$convertidos = 0;
$ignorados   = 0;
$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];

try {
    // ── 1. Busca chamados de rotina (entidade raiz) com técnico ──────────────
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.date, t.status,
               tu.users_id,
               u.realname, u.firstname, u.name AS username,
               (SELECT tr.id FROM glpi_ticketrecurrents tr
                 WHERE tr.is_active = 1 AND tr.entities_id = 0
                   AND t.name LIKE CONCAT(TRIM(tr.name), '%')
                 LIMIT 1) AS ticketrecurrents_id
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id AND u.is_active = 1
        WHERE t.is_deleted = 0
          AND t.date >= ?
          AND (
            t.entities_id = 0
            OR EXISTS (
              SELECT 1 FROM glpi_ticketrecurrents tr
              WHERE tr.is_active = 1
                AND tr.entities_id = 0
                AND t.name LIKE CONCAT(TRIM(tr.name), '%')
            )
          )
        ORDER BY t.date ASC
    ");
    $stmt->execute([$passado . ' 00:00:00']);
    $tickets = $stmt->fetchAll();

    log_msg("Chamados de rotina com técnico encontrados: " . count($tickets));

    // ── 2. Mapas de eventos existentes na agenda ────────────────────────────
    $stmt_ev = $pdo->prepare("
        SELECT id, ticket_id, ticketrecurrents_id, tipo, start, end
        FROM glpi_plugin_agenda_events
        WHERE (ticket_id IS NOT NULL OR tipo = 'pre_chamado')
          AND DATE(start) >= ?
    ");
    $stmt_ev->execute([$passado]);
    $existentes    = [];
    $placeholders  = [];
    foreach ($stmt_ev->fetchAll() as $ev) {
        if ($ev['ticket_id']) {
            $existentes[(int)$ev['ticket_id']] = $ev;
        }
        if ($ev['tipo'] === 'pre_chamado' && empty($ev['ticket_id']) && $ev['ticketrecurrents_id']) {
            $key = $ev['ticketrecurrents_id'] . '|' . date('Y-m-d', strtotime($ev['start']));
            $placeholders[$key] = $ev;
        }
    }

    // ── 3. Para cada ticket, cria, atualiza ou converte um placeholder ──────
    foreach ($tickets as $t) {
        $ticket_id = (int)$t['id'];

        $atendente_nome = trim(($t['realname'] ?? '') . ' ' . ($t['firstname'] ?? ''));
        if (!$atendente_nome) $atendente_nome = $t['username'] ?? '';
        $atendente_id = (int)$t['users_id'];

        if (!$atendente_nome || !$atendente_id) {
            log_msg("  #$ticket_id — sem técnico, ignorando");
            $ignorados++;
            continue;
        }

        $start_dt = date('Y-m-d H:i:s', strtotime($t['date']));
        $end_dt   = date('Y-m-d H:i:s', strtotime($start_dt . ' +15 minutes'));

        $titulo = '#' . $ticket_id . ' – ' . ($t['name'] ?? 'Rotina');
        $cor    = $cores[abs(crc32($atendente_nome)) % count($cores)];
        $concluido = (in_array((int)$t['status'], [5, 6])) ? 1 : 0;

        if (isset($existentes[$ticket_id])) {
            $existing = $existentes[$ticket_id];
            $start_old = date('Y-m-d H:i', strtotime($existing['start']));
            $start_new = date('Y-m-d H:i', strtotime($start_dt));
            $end_old   = date('Y-m-d H:i', strtotime($existing['end']));
            $end_new   = date('Y-m-d H:i', strtotime($end_dt));

            if ($start_old !== $start_new || $end_old !== $end_new) {
                $pdo->prepare("
                    UPDATE glpi_plugin_agenda_events
                    SET start = :start, end = :end, concluido = :concluido
                    WHERE id = :id
                ")->execute([
                    ':start'     => $start_dt,
                    ':end'       => $end_dt,
                    ':concluido' => $concluido,
                    ':id'        => $existing['id'],
                ]);
                log_msg("  #$ticket_id '{$t['name']}' → atualizado {$start_dt} → {$atendente_nome}");
                $atualizados++;
            } else {
                $ignorados++;
            }
            continue;
        }

        $convertido = false;
        if ($t['ticketrecurrents_id']) {
            $dia = date('Y-m-d', strtotime($t['date']));
            $key = $t['ticketrecurrents_id'] . '|' . $dia;
            if (isset($placeholders[$key])) {
                $ph = $placeholders[$key];
                $pdo->prepare("
                    UPDATE glpi_plugin_agenda_events
                    SET ticket_id = :ticket_id, tipo = 'chamado', titulo = :titulo,
                        descricao = :desc, start = :start, end = :end,
                        atendente = :atendente, atendente_id = :atendente_id, atendente_cor = :cor,
                        concluido = :concluido
                    WHERE id = :id
                ")->execute([
                    ':ticket_id'    => $ticket_id,
                    ':titulo'       => $titulo,
                    ':desc'         => 'Rotina automática',
                    ':start'        => $start_dt,
                    ':end'          => $end_dt,
                    ':atendente'    => $atendente_nome,
                    ':atendente_id' => $atendente_id,
                    ':cor'          => $cor,
                    ':concluido'    => $concluido,
                    ':id'           => $ph['id'],
                ]);
                log_msg("  #$ticket_id '{$t['name']}' → convertido de pre_chamado em {$start_dt}");
                $convertidos++;
                $convertido = true;
            }
        }

        if (!$convertido) {
            $pdo->prepare("
                INSERT INTO glpi_plugin_agenda_events
                    (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor,
                     prioridade, setor, ticket_id, ticketrecurrents_id, tipo, concluido)
                VALUES
                    (:id, :titulo, :desc, :start, :end, :atendente, :atendente_id, :cor,
                     'media', '', :ticket_id, :trid, 'chamado', :concluido)
            ")->execute([
                ':id'           => uniqid('rot_', true),
                ':titulo'       => $titulo,
                ':desc'         => 'Rotina automática',
                ':start'        => $start_dt,
                ':end'          => $end_dt,
                ':atendente'    => $atendente_nome,
                ':atendente_id' => $atendente_id,
                ':cor'          => $cor,
                ':ticket_id'    => $ticket_id,
                ':trid'         => $t['ticketrecurrents_id'] ?: null,
                ':concluido'    => $concluido,
            ]);
            log_msg("  #$ticket_id '{$t['name']}' → inserido {$start_dt} → {$atendente_nome}");
            $adicionados++;
        }
    }

    // ── 4. Limpeza de eventuais duplicatas (pre_chamado remanescente no mesmo dia que já tem chamado real) ──
    $pdo->exec("
        DELETE p FROM glpi_plugin_agenda_events p
        JOIN glpi_plugin_agenda_events c ON c.ticketrecurrents_id = p.ticketrecurrents_id
             AND DATE(c.start) = DATE(p.start)
             AND c.ticket_id IS NOT NULL
             AND p.tipo = 'pre_chamado'
             AND p.ticket_id IS NULL
    ");

} catch (Exception $e) {
    log_msg("ERRO: " . $e->getMessage());
    exit(1);
}

log_msg("=== Concluído: $adicionados adicionados, $atualizados atualizados, $convertidos convertidos, $ignorados ignorados ===");

$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
file_put_contents($log_dir . '/sync_' . $hoje . '.log', implode("\n", $log) . "\n");
