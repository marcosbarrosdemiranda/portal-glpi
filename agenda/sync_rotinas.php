<?php
/**
 * Sincroniza chamados de rotina (Entidade raiz) para a agenda
 * Usa SQL direto via PDO (ignora REST API com índice desatualizado)
 * Deve rodar via cron às 06:30 todo dia
 * Cron: 30 6 * * * php /var/www/html/glpi/agenda/sync_rotinas.php
 *
 * Regras:
 * - Só cria evento para chamados COM técnico atribuído
 * - Duração: 15 min
 * - Atualiza eventos existentes se o horário mudou
 * - Busca chamados da entidade raiz de hoje em diante
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

log_msg("=== Sync Rotinas (PDO): $hoje ===");

$adicionados = 0;
$atualizados = 0;
$ignorados   = 0;
$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];

try {
    // ── 1. Busca chamados de rotina (entidade raiz) com técnico ──────────────
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.date,
               tu.users_id,
               u.realname, u.firstname, u.name AS username
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id AND u.is_active = 1
        WHERE t.is_deleted = 0
          AND t.status NOT IN (5, 6)
          AND t.date >= ?
          AND (
            t.entities_id = 0
            OR EXISTS (
              SELECT 1 FROM glpi_ticketrecurrents tr
              WHERE tr.is_active = 1
                AND tr.entities_id = 0
                AND t.name = tr.name
            )
          )
        ORDER BY t.date ASC
    ");
    $stmt->execute([$hoje . ' 00:00:00']);
    $tickets = $stmt->fetchAll();

    log_msg("Chamados de rotina com técnico encontrados: " . count($tickets));

    if (empty($tickets)) {
        log_msg("Nenhum chamado de rotina. Encerrando.");
        exit(0);
    }

    // ── 2. Mapa de eventos existentes na agenda (por ticket_id) ─────────────
    $stmt_ev = $pdo->prepare("
        SELECT id, ticket_id, start, end
        FROM glpi_plugin_agenda_events
        WHERE ticket_id IS NOT NULL
          AND DATE(start) >= ?
    ");
    $stmt_ev->execute([$hoje]);
    $existentes = [];
    foreach ($stmt_ev->fetchAll() as $ev) {
        $existentes[(int)$ev['ticket_id']] = $ev;
    }

    // ── 3. Para cada ticket, cria ou atualiza o evento ──────────────────────
    foreach ($tickets as $t) {
        $ticket_id = (int)$t['id'];

        // Monta nome do atendente
        $atendente_nome = trim(($t['realname'] ?? '') . ' ' . ($t['firstname'] ?? ''));
        if (!$atendente_nome) $atendente_nome = $t['username'] ?? '';
        $atendente_id = (int)$t['users_id'];

        if (!$atendente_nome || !$atendente_id) {
            log_msg("  #$ticket_id — sem técnico, ignorando");
            $ignorados++;
            continue;
        }

        // Horário: hora exata de abertura + 15 min
        $start_dt = date('Y-m-d H:i:s', strtotime($t['date']));
        $end_dt   = date('Y-m-d H:i:s', strtotime($start_dt . ' +15 minutes'));

        $titulo = '#' . $ticket_id . ' – ' . ($t['name'] ?? 'Rotina');
        $cor    = $cores[abs(crc32($atendente_nome)) % count($cores)];

        if (isset($existentes[$ticket_id])) {
            // Evento já existe → atualiza horário se mudou
            $existing = $existentes[$ticket_id];
            $start_old = date('Y-m-d H:i', strtotime($existing['start']));
            $start_new = date('Y-m-d H:i', strtotime($start_dt));
            $end_old   = date('Y-m-d H:i', strtotime($existing['end']));
            $end_new   = date('Y-m-d H:i', strtotime($end_dt));

            if ($start_old !== $start_new || $end_old !== $end_new) {
                $pdo->prepare("
                    UPDATE glpi_plugin_agenda_events
                    SET start = :start, end = :end
                    WHERE id = :id
                ")->execute([
                    ':start' => $start_dt,
                    ':end'   => $end_dt,
                    ':id'    => $existing['id'],
                ]);
                log_msg("  #$ticket_id '{$t['name']}' → atualizado {$start_dt} → {$atendente_nome}");
                $atualizados++;
            } else {
                log_msg("  #$ticket_id — já na agenda com horário correto");
                $ignorados++;
            }
        } else {
            // Novo → insere
            $pdo->prepare("
                INSERT INTO glpi_plugin_agenda_events
                    (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor, prioridade, setor, ticket_id, tipo, concluido)
                VALUES
                    (:id, :titulo, :desc, :start, :end, :atendente, :atendente_id, :cor, 'media', '', :ticket_id, 'chamado', 0)
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
            ]);
            log_msg("  #$ticket_id '{$t['name']}' → inserido {$start_dt} → {$atendente_nome}");
            $adicionados++;
        }
    }
} catch (Exception $e) {
    log_msg("ERRO: " . $e->getMessage());
    exit(1);
}

log_msg("=== Concluído: $adicionados adicionados, $atualizados atualizados, $ignorados ignorados ===");

// Salva log
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
file_put_contents($log_dir . '/sync_' . $hoje . '.log', implode("\n", $log) . "\n");
