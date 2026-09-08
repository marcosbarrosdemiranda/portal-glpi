<?php
// Endpoint de reenvio manual da notificação WhatsApp de um chamado (Fase 2).
//
// Override do atendente: força o reenvio pro técnico atribuído OU pro grupo
// "Chamados", ignorando de propósito a dedup (portal_wpp_notificados) do worker.
// NÃO ignora o guardrail de destino (evo_send_text -> evo_guarded_send): um
// telefone fora de portal_wpp_contatos ativo, ou um grupo não configurado,
// continua bloqueado.
//
// POST JSON { ticket_id: int, alvo: 'tecnico'|'grupo' } -> JSON { ok, enviados, erro }.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evo_api.php';
require_once __DIR__ . '/gatilhos.php';
require_once __DIR__ . '/../entidade_alias.php';

/**
 * Reenvia a notificação de um chamado. Nunca lança: qualquer erro de banco vira
 * ['ok'=>false,'erro'=>...]. Reaproveita as mensagens do worker:
 *   alvo 'grupo'   -> gat_msg_novo()      (formato que vai pro grupo)
 *   alvo 'tecnico' -> gat_msg_atribuido() (formato da DM)
 */
function wpp_renotificar(PDO $pdo, int $ticketId, string $alvo): array
{
    if ($ticketId <= 0) return ['ok' => false, 'erro' => 'ticket inválido'];
    if (!in_array($alvo, ['tecnico', 'grupo'], true)) return ['ok' => false, 'erro' => 'alvo inválido'];

    try {
        // dados do ticket (chaves lidas por gat_msg_novo: id, name, content, loja,
        // date_creation, type, req_nome, req_login)
        $st = $pdo->prepare(
            "SELECT t.id, t.name, t.content, t.type, t.date_creation, t.date_mod,
                    e.completename AS loja,
                    TRIM(CONCAT(COALESCE(ur.realname,''), ' ', COALESCE(ur.firstname,''))) AS req_nome,
                    ur.name AS req_login
             FROM glpi_tickets t
             LEFT JOIN glpi_entities e ON e.id = t.entities_id
             LEFT JOIN glpi_users ur ON ur.id = t.users_id_recipient
             WHERE t.id = ? AND t.is_deleted = 0"
        );
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return ['ok' => false, 'erro' => 'chamado não encontrado'];

        // gat_msg_atribuido lê $t['ticket_id']; gat_msg_novo lê $t['id']. Carrega os dois.
        $t['ticket_id'] = $t['id'];
        $quem = $_SESSION['nome'] ?? 'alguém';

        if ($alvo === 'grupo') {
            $grupo = (string) wpp_cfg_get('grupo_chamados_jid', '');
            if ($grupo === '') return ['ok' => false, 'erro' => 'grupo Chamados não configurado'];

            $r = evo_send_text($grupo, gat_msg_novo($t));
            wpp_log('out', $grupo, "renotif manual #{$ticketId} p/ grupo por {$quem}", !empty($r['ok']) ? 'ok' : 'erro');
            return !empty($r['ok'])
                ? ['ok' => true, 'enviados' => 1]
                : ['ok' => false, 'erro' => 'falha no envio (ver Log)'];
        }

        // alvo === 'tecnico': telefones dos técnicos (type=2) com contato ativo
        $tec = $pdo->prepare(
            "SELECT c.telefone
             FROM glpi_tickets_users tu
             JOIN portal_wpp_contatos c ON c.glpi_user_id = tu.users_id AND c.ativo = 1
             WHERE tu.tickets_id = ? AND tu.type = 2 AND c.telefone <> ''"
        );
        $tec->execute([$ticketId]);
        $tels = $tec->fetchAll(PDO::FETCH_COLUMN);
        if (!$tels) return ['ok' => false, 'erro' => 'nenhum técnico atribuído com telefone cadastrado'];

        $ok = 0;
        foreach ($tels as $tel) {
            $r = evo_send_text($tel, gat_msg_atribuido($t));
            wpp_log('out', $tel, "renotif manual #{$ticketId} p/ tecnico por {$quem}", !empty($r['ok']) ? 'ok' : 'erro');
            if (!empty($r['ok'])) $ok++;
        }
        return $ok > 0
            ? ['ok' => true, 'enviados' => $ok]
            : ['ok' => false, 'erro' => 'falha no envio (ver Log)'];
    } catch (\Throwable $e) {
        wpp_log('sys', '', "renotif manual #{$ticketId}: " . $e->getMessage(), 'erro');
        return ['ok' => false, 'erro' => 'erro interno (ver Log)'];
    }
}

// ── Wrapper HTTP — só quando este arquivo é o ponto de entrada (não em include/testes) ──
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_once __DIR__ . '/../auth_guard.php';
    if (empty($_SESSION['autenticado'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
        exit;
    }
    if (($_SESSION['perfil'] ?? '') === 'self-service') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'sem permissão']);
        exit;
    }
    // NOTA: qualquer atendente pode renotificar — NÃO exige notificacoes_config.

    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'método inválido']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $ticketId = (int) ($body['ticket_id'] ?? 0);
    $alvo     = (string) ($body['alvo'] ?? '');

    echo json_encode(wpp_renotificar($pdo, $ticketId, $alvo));
    exit;
}
