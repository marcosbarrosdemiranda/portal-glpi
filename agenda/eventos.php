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

// ── Tabela de recorrências + coluna ─────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS agenda_recorrencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id VARCHAR(50) NOT NULL,
    dias_semana VARCHAR(25) NOT NULL COMMENT '1=Domingo...7=Sábado',
    data_limite DATE DEFAULT NULL,
    ativo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}
// Corrige collation de tabelas já existentes (compatibilidade retroativa)
try { $pdo->exec("ALTER TABLE agenda_recorrencias CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE agenda_recorrencias MODIFY evento_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE glpi_plugin_agenda_events ADD COLUMN recorrencia_id INT DEFAULT NULL AFTER concluido"); } catch (Exception $e) {}

$action = $_GET['action'] ?? 'list';

try {

    // ── LIST ──────────────────────────────────────────────────────────────
    if ($action === 'list') {
        // Remove eventos cujo ticket foi purgado ou está na lixeira do GLPI
        // (t.id IS NULL = purgado, t.is_deleted = 1 = lixeira/recycle bin)
        $pdo->exec(
            "DELETE e FROM glpi_plugin_agenda_events e
             LEFT JOIN glpi_tickets t ON e.ticket_id = t.id AND t.is_deleted = 0
             WHERE e.ticket_id IS NOT NULL AND e.ticket_id != '' AND t.id IS NULL"
        );
        // Sincroniza status do GLPI: se o chamado foi resolvido (5) ou fechado (6) no GLPI,
        // marca automaticamente o evento como concluído na agenda — e não permite mais edição.
        // ⚠️ REGRA PROTEGIDA — NÃO ALTERAR SEM PERMISSÃO DO RESPONSÁVEL ⚠️
        $pdo->exec(
            "UPDATE glpi_plugin_agenda_events e
             INNER JOIN glpi_tickets t ON e.ticket_id = t.id AND t.status IN (5,6)
             SET e.concluido = 1
             WHERE e.concluido = 0"
        );
        // Corrige retroativamente chamados salvos com tipo='evento' por engano:
        // qualquer evento vinculado a um ticket GLPI deve ser 'chamado', não 'evento'
        $pdo->exec(
            "UPDATE glpi_plugin_agenda_events
             SET tipo = 'chamado'
             WHERE tipo = 'evento' AND ticket_id IS NOT NULL AND ticket_id != ''"
        );
        // Tenta JOIN com recorrência; se falhar (coluna não existe), faz SELECT simples
        try {
            $rows = $pdo->query("
                SELECT e.*, r.dias_semana AS recorrencia_dias, r.data_limite AS recorrencia_data_limite
                FROM glpi_plugin_agenda_events e
                LEFT JOIN agenda_recorrencias r ON e.recorrencia_id = r.id AND r.ativo = 1
                ORDER BY e.start ASC
            ")->fetchAll();
        } catch (Exception $e) {
            // Fallback: coluna recorrencia_id pode não existir ainda
            $rows = $pdo->query("SELECT * FROM glpi_plugin_agenda_events ORDER BY start ASC")->fetchAll();
        }
        echo json_encode($rows);
        exit;
    }

    // ── DELETE por ID ─────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = trim($_GET['id'] ?? '');
        $recorrencia = $_GET['recorrencia'] ?? 'single'; // 'single' ou 'futuras'
        if ($id === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID não informado']);
            exit;
        }

        // Se for excluir futuras ocorrências de uma recorrência
        if ($recorrencia === 'futuras') {
            // Busca o recorrencia_id deste evento
            $st = $pdo->prepare("SELECT recorrencia_id FROM glpi_plugin_agenda_events WHERE id=?");
            $st->execute([$id]);
            $ev = $st->fetch();
            $recId = $ev ? (int)$ev['recorrencia_id'] : 0;

            if ($recId > 0) {
                // Desativa recorrência
                $pdo->prepare("UPDATE agenda_recorrencias SET ativo=0 WHERE id=?")->execute([$recId]);
                // Deleta eventos futuros (>= hoje) desta recorrência
                $stDel = $pdo->prepare(
                    "DELETE FROM glpi_plugin_agenda_events
                     WHERE recorrencia_id = ? AND DATE(start) >= CURDATE()"
                );
                $stDel->execute([$recId]);
                echo json_encode(['ok' => true, 'removidos' => $stDel->rowCount(), 'recorrencia_desativada' => true]);
                exit;
            }
        }

        // Delete padrão (único)
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

        // ⚠️ REGRA PROTEGIDA — VALIDAÇÃO GLPI ⚠️
        // Se o chamado já está resolvido/fechado no GLPI, não permite salvar como não-concluído
        $ticket_id = isset($body['ticket_id']) && $body['ticket_id'] !== '' ? (int)$body['ticket_id'] : null;
        if ($ticket_id && empty($body['concluido'])) {
            $check = $pdo->prepare("SELECT status FROM glpi_tickets WHERE id = ?");
            $check->execute([$ticket_id]);
            $status = (int)$check->fetchColumn();
            if (in_array($status, [5,6], true)) {
                echo json_encode(['ok' => false, 'error' => 'Chamado já está resolvido/fechado no GLPI. Recorra ao GLPI para reabri-lo.']);
                exit;
            }
        }

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

        // ── Recorrência ──────────────────────────────────────────────
        $recorrencia_id = (isset($body['recorrencia_id']) && $body['recorrencia_id'] !== null && $body['recorrencia_id'] !== '') ? (int)$body['recorrencia_id'] : null;
        if (!empty($body['recorrencia_ativa']) && !empty($body['recorrencia_dias'])) {
            $dias = trim($body['recorrencia_dias']);
            $data_limite = !empty($body['recorrencia_data_limite']) ? $body['recorrencia_data_limite'] : null;

            if ($data_limite) {
                $dl = date('Y-m-d', strtotime($data_limite));
                if ($dl <= date('Y-m-d')) $data_limite = null; // se já passou, ignora
            }

            if ($is_edit && $recorrencia_id) {
                // Só atualiza recorrência se os dias REALMENTE mudaram
                // (evita regenerar tudo ao marcar concluído em uma instância)
                $check = $pdo->prepare("SELECT dias_semana, data_limite FROM agenda_recorrencias WHERE id=?");
                $check->execute([$recorrencia_id]);
                $atual = $check->fetch();
                if ($atual && ($atual['dias_semana'] !== $dias || ($atual['data_limite'] ?? '') !== ($data_limite ?? ''))) {
                    $pdo->prepare("UPDATE agenda_recorrencias SET dias_semana=?, data_limite=? WHERE id=?")
                        ->execute([$dias, $data_limite, $recorrencia_id]);
                    // Regenera instâncias com os novos dias
                    gerarInstanciasRecorrencia($pdo, null, $recorrencia_id, 52);
                }
            } else {
                // Cria nova recorrência (vinculada ao evento "mãe")
                $pdo->prepare("INSERT INTO agenda_recorrencias (evento_id, dias_semana, data_limite) VALUES (?,?,?)")
                    ->execute([$id, $dias, $data_limite]);
                $recorrencia_id = (int)$pdo->lastInsertId();
                // Vincula o evento mãe
                $pdo->prepare("UPDATE glpi_plugin_agenda_events SET recorrencia_id=? WHERE id=?")
                    ->execute([$recorrencia_id, $id]);
                // Gera TODAS as instâncias futuras de uma vez
                gerarInstanciasRecorrencia($pdo, null, $recorrencia_id, 52);
            }
        } elseif ($is_edit && $recorrencia_id) {
            // Desativou recorrência
            $pdo->prepare("UPDATE agenda_recorrencias SET ativo=0 WHERE id=?")->execute([$recorrencia_id]);
        }

        echo json_encode(['ok' => true, 'id' => $id, 'recorrencia_id' => $recorrencia_id]);
        exit;
    }

    // ── GERAR SEMANA (recorrência) ──────────────────────────────────
    // Gera instâncias da semana corrente baseado nas recorrências ativas.
    // Pode ser chamado manualmente pelo CRON (domingo 07:00) ou via AJAX.
    if ($action === 'gerar_semana') {
        $criados = 0;
        $ignorados = 0;
        $erros = 0;

        $recurrences = $pdo->query("
            SELECT r.*, e.titulo, e.descricao, e.start, e.end,
                   e.atendente, e.atendente_id, e.atendente_cor, e.prioridade, e.setor,
                   e.ticket_id, e.tipo
            FROM agenda_recorrencias r
            JOIN glpi_plugin_agenda_events e ON r.evento_id COLLATE utf8mb4_unicode_ci = e.id
            WHERE r.ativo = 1
              AND (r.data_limite IS NULL OR r.data_limite >= CURDATE())
        ")->fetchAll();

        foreach ($recurrences as $rec) {
            try {
                // Limite de 50 inserts por recorrência por chamada (evita timeout)
                $result = gerarInstanciasRecorrencia($pdo, $rec, null, 52, 50);
                $criados += $result['criados'];
                $ignorados += $result['ignorados'];
            } catch (\Throwable $e) {
                $erros++;
                error_log('gerar_semana: erro na recorrência #' . ($rec['id'] ?? '?') . ' - ' . $e->getMessage());
            }
        }

        echo json_encode(['ok'=>true, 'criados'=>$criados, 'ignorados'=>$ignorados, 'erros'=>$erros]);
        exit;
    }

    echo json_encode(['error' => 'Ação inválida']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
}

// ── Helper: gera instâncias futuras de uma recorrência ──────────────
// Gera TODAS as ocorrências futuras de uma vez (até data limite ou 364 dias).
// $rec = registro completo via JOIN, $recId = só o ID (busca internamente)
function gerarInstanciasRecorrencia($pdo, $rec = null, $recId = null, $semanas = 52, $maxInserts = PHP_INT_MAX) {
    if ($rec === null && $recId !== null) {
        $st = $pdo->prepare("
            SELECT r.*, e.titulo, e.descricao, e.start, e.end,
                   e.atendente, e.atendente_id, e.atendente_cor, e.prioridade, e.setor,
                   e.ticket_id, e.tipo
            FROM agenda_recorrencias r
            JOIN glpi_plugin_agenda_events e ON r.evento_id COLLATE utf8mb4_unicode_ci = e.id
            WHERE r.id = ? AND r.ativo = 1
        ");
        $st->execute([$recId]);
        $rec = $st->fetch();
        if (!$rec) return ['criados' => 0, 'ignorados' => 0];
    }
    if (!$rec) return ['criados' => 0, 'ignorados' => 0];

    $criados = 0;
    $ignorados = 0;
    $today = new DateTime();
    $todayDate = (clone $today)->setTime(0, 0, 0);

    $dias_raw = $rec['dias_semana'] ?? '';
    $dias = array_map('intval', array_filter(explode(',', $dias_raw)));
    $recDbId = (int)$rec['id'];
    $duracao = strtotime($rec['end']) - strtotime($rec['start']);
    $horaInicio = date('H:i:s', strtotime($rec['start']));

    // Data limite: se definida usa ela, senão vai até 31/12/2026
    if ($rec['data_limite']) {
        $dataLimite = new DateTime($rec['data_limite']);
    } else {
        $dataLimite = new DateTime('2026-12-31');
    }

    // Busca datas já existentes desta recorrência
    $existentes = $pdo->prepare("SELECT DATE(start) FROM glpi_plugin_agenda_events WHERE recorrencia_id = ?");
    $existentes->execute([$recDbId]);
    $existentesDatas = $existentes->fetchAll(PDO::FETCH_COLUMN);

    // Começa de amanhã (o evento original já é o de hoje)
    $cursor = (clone $today)->modify('+1 day');

    while ($cursor <= $dataLimite) {
        $dataStr = $cursor->format('Y-m-d');

        // Mapeia: format('N') = 1(Mon)..7(Sun) → nosso formato: Seg=2, Ter=3...Dom=1
        $n = (int)$cursor->format('N');
        $checkDia = $n + 1;
        if ($checkDia > 7) $checkDia = 1;

        if (in_array($checkDia, $dias)) {
            if (!in_array($dataStr, $existentesDatas)) {
                $startDt = $dataStr . ' ' . $horaInicio;
                $endDt   = date('Y-m-d H:i:s', strtotime($startDt) + $duracao);
                $evId = 'rec_' . $recDbId . '_' . $dataStr . '_' . uniqid();

                $pdo->prepare("
                    INSERT INTO glpi_plugin_agenda_events
                        (id, titulo, descricao, start, end, atendente, atendente_id,
                         atendente_cor, prioridade, setor, ticket_id, tipo, concluido, recorrencia_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)
                ")->execute([
                    $evId, $rec['titulo'], $rec['descricao'],
                    $startDt, $endDt,
                    $rec['atendente'], $rec['atendente_id'], $rec['atendente_cor'],
                    $rec['prioridade'], $rec['setor'],
                    $rec['ticket_id'], $rec['tipo'],
                    $recDbId,
                ]);
                $criados++;
                if ($criados >= $maxInserts) break;
            } else {
                $ignorados++;
            }
        }

        $cursor->modify('+1 day');
    }

    return ['criados' => $criados, 'ignorados' => $ignorados];
}
