<?php
/**
 * Endpoint AJAX para sincronizar rotinas manualmente
 * Usa SQL direto via PDO (ignora REST API com índice desatualizado)
 *
 * Regras:
 * - Só cria evento para chamados COM técnico atribuído
 * - Duração: 15 min
 * - Atualiza eventos existentes se o horário mudou
 * - Busca chamados de entidade raiz (entities_id=0) de hoje em diante
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

$hoje        = date('Y-m-d');
$adicionados = 0;
$atualizados = 0;
$ignorados   = 0;

$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];

try {
    // ── 1. Busca chamados de rotina (entidade raiz) com técnico ──────────────
    // Usa SQL direto — a REST API search tem índice desatualizado e não
    // retorna resultados confiáveis para filtro por data.
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.date,
               tu.users_id,
               u.realname, u.firstname, u.name AS username
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id AND u.is_active = 1
        WHERE t.is_deleted = 0
          AND t.status NOT IN (5, 6)  -- Não solucionado/fechado
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

        // Monta nome do atendente (mesmo formato de users.php: "Sobrenome Nome")
        $atendente_nome = trim(($t['realname'] ?? '') . ' ' . ($t['firstname'] ?? ''));
        if (!$atendente_nome) $atendente_nome = $t['username'] ?? '';
        $atendente_id = (int)$t['users_id'];

        // ⚠️ SÓ cria evento se tiver técnico atribuído
        if (!$atendente_nome || !$atendente_id) {
            $ignorados++;
            continue;
        }

        // Horário: usa a data/hora exata de abertura do chamado
        // Duração: 15 minutos
        $start_dt = date('Y-m-d H:i:s', strtotime($t['date']));
        $end_dt   = date('Y-m-d H:i:s', strtotime($start_dt . ' +15 minutes'));

        $titulo = '#' . $ticket_id . ' – ' . ($t['name'] ?? 'Rotina');
        $cor    = $cores[abs(crc32($atendente_nome)) % count($cores)];

        if (isset($existentes[$ticket_id])) {
            // Evento já existe → atualiza horário
            $existing = $existentes[$ticket_id];

            // Só atualiza se realmente mudou (evita UPDATE desnecessário)
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
                $atualizados++;
            } else {
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
            $adicionados++;
        }
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok'          => true,
    'adicionados' => $adicionados,
    'atualizados' => $atualizados,
    'ignorados'   => $ignorados,
    'msg'         => "$adicionados adicionado(s), $atualizados atualizado(s), $ignorados ignorado(s)",
]);
