<?php
/**
 * API de eventos da agenda — armazenamento no banco de dados do GLPI
 *
 * Tabela: glpi_plugin_agenda_events
 *
 * Endpoints (GET/POST):
 *   ?action=list       → lista todos os eventos
 *   ?action=save       → cria ou atualiza evento (JSON body)
 *   ?action=delete&id= → remove evento pelo ID
 */
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? 'list';

try {

    // ── LIST ──────────────────────────────────────────────────────────────
    if ($action === 'list') {
        // Remove eventos cujo ticket foi purgado (excluído permanentemente) do GLPI
        $pdo->exec(
            "DELETE e FROM glpi_plugin_agenda_events e
             LEFT JOIN glpi_tickets t ON e.ticket_id = t.id
             WHERE e.ticket_id IS NOT NULL AND e.ticket_id != '' AND t.id IS NULL"
        );
        // Corrige retroativamente chamados salvos com tipo='evento' por engano:
        // qualquer evento vinculado a um ticket GLPI deve ser 'chamado', não 'evento'
        $pdo->exec(
            "UPDATE glpi_plugin_agenda_events
             SET tipo = 'chamado'
             WHERE tipo = 'evento' AND ticket_id IS NOT NULL AND ticket_id != ''"
        );
        $rows = $pdo->query("SELECT * FROM glpi_plugin_agenda_events ORDER BY start ASC")->fetchAll();
        echo json_encode($rows);
        exit;
    }

    // ── DELETE por ID ─────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = trim($_GET['id'] ?? '');
        if ($id === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID não informado']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM glpi_plugin_agenda_events WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'removidos' => $stmt->rowCount()]);
        exit;
    }

    // ── DELETE por ticket_id (remove todos os eventos do chamado) ─────────
    if ($action === 'deleteByTicket') {
        $ticket_id = (int)($_GET['ticket_id'] ?? 0);
        if (!$ticket_id) {
            http_response_code(400);
            echo json_encode(['error' => 'ticket_id não informado']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM glpi_plugin_agenda_events WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        echo json_encode(['ok' => true, 'removidos' => $stmt->rowCount()]);
        exit;
    }

    // ── CONCLUIR por ticket_id ─────────────────────────────────────────
    // Marca TODOS os eventos do chamado como concluídos (sem filtro concluido=0).
    // Retorna { ok, updated } onde updated = 0 se NENHUM evento existe no DB
    // para este ticket (foi deletado por verificarAtrasados), e >0 se ao menos
    // um evento foi atualizado ou já estava concluído.
    // ⚠️ REGRA PROTEGIDA — NÃO ALTERAR SEM PERMISSÃO DO RESPONSÁVEL ⚠️
    // A remoção do filtro `AND concluido = 0` é INTENCIONAL: evita race
    // condition onde o UPDATE não encontra o evento e o fallback no JS cria
    // duplicata (red + green lado a lado no mesmo horário).
    if ($action === 'concluir_ticket') {
        $body         = json_decode(file_get_contents('php://input'), true) ?? [];
        $ticket_id    = (int)($body['ticket_id'] ?? 0);
        $atendente    = $body['atendente']     ?? null;
        $atendente_id = isset($body['atendente_id']) && $body['atendente_id'] !== '' ? (int)$body['atendente_id'] : null;
        $atendente_cor= $body['atendente_cor'] ?? null;
        if (!$ticket_id) {
            echo json_encode(['ok' => false, 'updated' => 0, 'msg' => 'ticket_id obrigatório']);
            exit;
        }
        // Se atendente informado: preenche também nos eventos sem atendente (rotinas não mapeadas)
        if ($atendente) {
            $stmt = $pdo->prepare(
                "UPDATE glpi_plugin_agenda_events
                 SET concluido = 1,
                     atendente     = CASE WHEN (atendente IS NULL OR atendente = '') THEN :atendente     ELSE atendente     END,
                     atendente_id  = CASE WHEN (atendente IS NULL OR atendente = '') THEN :atendente_id  ELSE atendente_id  END,
                     atendente_cor = CASE WHEN (atendente IS NULL OR atendente = '') THEN :atendente_cor ELSE atendente_cor END
                 WHERE ticket_id = :ticket_id"
            );
            $stmt->execute([
                ':ticket_id'    => $ticket_id,
                ':atendente'    => $atendente,
                ':atendente_id' => $atendente_id,
                ':atendente_cor'=> $atendente_cor ?? '#1a73e8',
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE glpi_plugin_agenda_events SET concluido = 1
                 WHERE ticket_id = ?"
            );
            $stmt->execute([$ticket_id]);
        }
        // Verifica se algum evento existe no DB (se não existe, foi deletado por verificarAtrasados)
        $check = $pdo->prepare("SELECT COUNT(*) FROM glpi_plugin_agenda_events WHERE ticket_id = ?");
        $check->execute([$ticket_id]);
        $exists = (int)$check->fetchColumn();
        echo json_encode(['ok' => true, 'updated' => $exists ? 1 : 0]);
        exit;
    }

    // ── SAVE (INSERT ou UPDATE) ───────────────────────────────────────────
    if ($action === 'save') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Detecta se é edição (id fornecido) ou novo evento
        $is_edit = isset($body['id']) && $body['id'] !== null && $body['id'] !== '';
        $id = $is_edit ? (string)$body['id'] : uniqid('ev_', true);

        $titulo       = $body['titulo']       ?? 'Sem título';
        $descricao    = $body['descricao']    ?? '';
        $start        = $body['start']        ?? '';
        $end          = $body['end']          ?? $start;
        $atendente    = $body['atendente']    ?? null;
        $atendente_id = isset($body['atendente_id']) && $body['atendente_id'] !== '' ? (int)$body['atendente_id'] : null;
        $atendente_cor= $body['atendente_cor']?? '#1a73e8';
        $prioridade   = in_array($body['prioridade'] ?? '', ['baixa','media','alta','critica'])
                        ? $body['prioridade'] : 'media';
        $setor        = $body['setor']        ?? null;
        $ticket_id    = isset($body['ticket_id']) && $body['ticket_id'] !== '' ? (int)$body['ticket_id'] : null;
        $tipo         = in_array($body['tipo'] ?? '', ['evento','chamado','requisicao','reuniao'])
                        ? $body['tipo'] : 'evento';
        // ⚠️ Mapeia 'requisicao' → 'chamado' porque o ENUM do MySQL não tem 'requisicao'
        if ($tipo === 'requisicao') $tipo = 'chamado';
        $concluido    = isset($body['concluido']) ? (int)$body['concluido'] : 0;
        $orig_start   = $body['orig_start'] ?? '';

        // Normaliza datas
        $start      = str_replace('T', ' ', substr($start, 0, 19));
        $end        = str_replace('T', ' ', substr($end,   0, 19));
        $orig_start = $orig_start ? str_replace('T', ' ', substr($orig_start, 0, 19)) : '';

        $params = [
            ':titulo'       => $titulo,
            ':descricao'    => $descricao,
            ':start'        => $start,
            ':end'          => $end,
            ':atendente'    => $atendente,
            ':atendente_id' => $atendente_id,
            ':atendente_cor'=> $atendente_cor,
            ':prioridade'   => $prioridade,
            ':setor'        => $setor,
            ':ticket_id'    => $ticket_id,
            ':tipo'         => $tipo,
            ':concluido'    => $concluido,
        ];

        $insert_sql = "
            INSERT INTO glpi_plugin_agenda_events
                (id, titulo, descricao, start, end, atendente, atendente_id, atendente_cor, prioridade, setor, ticket_id, tipo, concluido)
            VALUES
                (:id,:titulo,:descricao,:start,:end,:atendente,:atendente_id,:atendente_cor,:prioridade,:setor,:ticket_id,:tipo,:concluido)
        ";

        if ($is_edit) {
            // Tenta UPDATE direto
            $stmt = $pdo->prepare("
                UPDATE glpi_plugin_agenda_events SET
                    titulo=:titulo, descricao=:descricao, start=:start, end=:end,
                    atendente=:atendente, atendente_id=:atendente_id, atendente_cor=:atendente_cor,
                    prioridade=:prioridade, setor=:setor, ticket_id=:ticket_id,
                    tipo=:tipo, concluido=:concluido
                WHERE id = :id
            ");
            $stmt->execute(array_merge($params, [':id' => $id]));

            // Se não encontrou o registro, insere (era um evento fantasma na memória)
            if ($stmt->rowCount() === 0) {
                $stmt2 = $pdo->prepare($insert_sql);
                $stmt2->execute(array_merge($params, [':id' => $id]));
            }
        } else {
            // Novo evento: INSERT
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute(array_merge($params, [':id' => $id]));
        }

        // Sincroniza horário dos co-atendentes do mesmo período (mesmo ticket + mesmo horário original)
        if ($ticket_id && $orig_start) {
            $sync = $pdo->prepare("
                UPDATE glpi_plugin_agenda_events
                SET start = :start, end = :end
                WHERE ticket_id = :ticket_id
                  AND id != :id
                  AND (start = :orig OR start = :orig2)
            ");
            $sync->execute([
                ':start'     => $start,
                ':end'       => $end,
                ':ticket_id' => $ticket_id,
                ':id'        => $id,
                ':orig'      => $orig_start,
                ':orig2'     => $orig_start . ':00',
            ]);
        }

        // Se o evento foi marcado como concluído, conclui TODOS os períodos do mesmo ticket
        if ($concluido && $ticket_id) {
            $pdo->prepare(
                "UPDATE glpi_plugin_agenda_events SET concluido = 1
                 WHERE ticket_id = ? AND id != ? AND concluido = 0"
            )->execute([$ticket_id, $id]);
        }

        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    echo json_encode(['error' => 'Ação inválida']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
