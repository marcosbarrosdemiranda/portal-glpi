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
 * - Se o chamado real veio de uma glpi_ticketrecurrents ativa e já existe um
 *   "pre_chamado" (placeholder) pra mesma definição+dia, CONVERTE esse placeholder
 *   em vez de inserir uma linha nova (evita duplicar a mesma ocorrência na agenda)
 * - No fim, gera placeholders "pre_chamado" dos próximos 30 dias pra cada
 *   glpi_ticketrecurrents ativa que ainda não tem evento pra aquele dia
 */
require_once __DIR__ . '/../auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pre_chamado_lib.php';

$hoje        = date('Y-m-d');
$adicionados = 0;
$atualizados = 0;
$convertidos = 0;
$ignorados   = 0;
$novos_tickets = [];

$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#d93025','#0097a7'];

try {
    // ── 1. Busca chamados de rotina (entidade raiz) com técnico ──────────────
    // Usa SQL direto — a REST API search tem índice desatualizado e não
    // retorna resultados confiáveis para filtro por data.
    // ticketrecurrents_id via subquery escalar (não JOIN) pra não gerar fan-out
    // se por algum motivo houver mais de uma definição com o mesmo nome.
    //
    // Casamento por PREFIXO (não igualdade exata): o GLPI às vezes grava o
    // nome do chamado real diferente do campo "name" da definição — ex.: def
    // "TVs, Radios e Consulta Preços" gera chamado "TVs, Radios e Consulta
    // Preços - Rotina Diária" (sufixo extra), e def "Bot Conversa" tem nome
    // gravado com espaço sobrando ("Bot Conversa "). TRIM(tr.name) + LIKE
    // 'tr.name%' cobre os dois casos achados em produção (2026-10-19) sem
    // ambiguidade nova: nenhuma definição ativa de entidade raiz é prefixo
    // de outra definição ativa com nome diferente (só duplicatas exatas, que
    // já eram uma ambiguidade pré-existente resolvida arbitrariamente pelo
    // LIMIT 1).
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.date,
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
          AND t.status NOT IN (5, 6)  -- Não solucionado/fechado
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
    $stmt->execute([$hoje . ' 00:00:00']);
    $tickets = $stmt->fetchAll();

    // ── 2. Mapas de eventos existentes na agenda ────────────────────────────
    // Por ticket_id (chamados já mirrorados) e por "ticketrecurrents_id|dia"
    // (placeholders pre_chamado ainda sem ticket, candidatos a conversão)
    $stmt_ev = $pdo->prepare("
        SELECT id, ticket_id, ticketrecurrents_id, tipo, start, end
        FROM glpi_plugin_agenda_events
        WHERE (ticket_id IS NOT NULL OR tipo = 'pre_chamado')
          AND DATE(start) >= ?
    ");
    $stmt_ev->execute([$hoje]);
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
            continue;
        }

        // Ainda não existe evento com esse ticket_id → tenta converter um
        // placeholder "pre_chamado" da mesma definição+dia antes de inserir novo
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
                        atendente = :atendente, atendente_id = :atendente_id, atendente_cor = :cor
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
                    ':id'           => $ph['id'],
                ]);
                $convertidos++;
                $novos_tickets[] = $ticket_id;
                $convertido = true;
            }
        }

        if (!$convertido) {
            // Nenhum placeholder correspondente → insere evento novo (fallback,
            // comportamento idêntico ao de antes desta feature)
            $pdo->prepare("
                INSERT INTO glpi_plugin_agenda_events
                    (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor,
                     prioridade, setor, ticket_id, ticketrecurrents_id, tipo, concluido)
                VALUES
                    (:id, :titulo, :desc, :start, :end, :atendente, :atendente_id, :cor,
                     'media', '', :ticket_id, :trid, 'chamado', 0)
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
            ]);
            $adicionados++;
            $novos_tickets[] = $ticket_id;
        }
    }

    // ── 4. Gera placeholders "pre_chamado" dos próximos 30 dias ─────────────
    // Pra cada glpi_ticketrecurrents ativa (entidade raiz), garante que exista
    // um evento pra cada ocorrência futura dentro da janela — sem duplicar
    // dia já ocupado por ticket real ou por outro placeholder.
    $placeholders_criados = 0;
    try {
        $defs = $pdo->query("
            SELECT id, name, begin_date, periodicity
            FROM glpi_ticketrecurrents
            WHERE is_active = 1 AND entities_id = 0
        ")->fetchAll();

        $stmt_ocupadas = $pdo->prepare("
            SELECT ticketrecurrents_id, DATE(start) AS dia
            FROM glpi_plugin_agenda_events
            WHERE ticketrecurrents_id IS NOT NULL
              AND DATE(start) >= ?
        ");
        $stmt_ocupadas->execute([$hoje]);
        $ocupadas = [];
        foreach ($stmt_ocupadas->fetchAll() as $o) {
            $ocupadas[$o['ticketrecurrents_id'] . '|' . $o['dia']] = true;
        }

        $janelaInicio = new DateTime($hoje . ' 00:00:00');
        $janelaFim    = (clone $janelaInicio)->modify('+30 days');

        $ins_ph = $pdo->prepare("
            INSERT INTO glpi_plugin_agenda_events
                (id, titulo, descricao, start, end, ticket_id, ticketrecurrents_id, tipo, concluido)
            VALUES
                (:id, :titulo, :desc, :start, :end, NULL, :trid, 'pre_chamado', 0)
        ");

        foreach ($defs as $def) {
            $ocorrencias = preChamadoProximasOcorrencias($def['begin_date'], $def['periodicity'], $janelaInicio, $janelaFim);
            foreach ($ocorrencias as $dt) {
                $dia = $dt->format('Y-m-d');
                $key = $def['id'] . '|' . $dia;
                if (isset($ocupadas[$key])) continue;

                $start_dt = $dt->format('Y-m-d H:i:s');
                $end_dt   = date('Y-m-d H:i:s', strtotime($start_dt . ' +15 minutes'));

                $ins_ph->execute([
                    ':id'    => uniqid('pre_', true),
                    ':titulo'=> $def['name'],
                    ':desc'  => 'Pré-chamado — rotina automática (GLPI)',
                    ':start' => $start_dt,
                    ':end'   => $end_dt,
                    ':trid'  => $def['id'],
                ]);
                $ocupadas[$key] = true; // defensivo: evita duplicar na mesma passada
                $placeholders_criados++;
            }
        }
    } catch (Exception $e) {
        // Falha na geração de placeholders não derruba a resposta principal
        // (o mirror de tickets reais, mais crítico, já rodou)
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok'                    => true,
    'adicionados'           => $adicionados,
    'atualizados'           => $atualizados,
    'convertidos'           => $convertidos,
    'ignorados'             => $ignorados,
    'placeholders_criados'  => $placeholders_criados ?? 0,
    'ticket_ids'            => $novos_tickets ?? [],
    'msg'                   => "$adicionados adicionado(s), $atualizados atualizado(s), $convertidos convertido(s), $ignorados ignorado(s), " . ($placeholders_criados ?? 0) . " pré-chamado(s) gerado(s)",
]);
