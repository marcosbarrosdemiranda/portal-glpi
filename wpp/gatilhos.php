<?php
// Gatilhos de notificação do worker WhatsApp (Fase 2).
//
// Cada função roda uma "passada" de um gatilho: detecta o que é novo desde a
// última execução (via watermark / portal_wpp_notificados) e envia. O toggle
// on_* de portal_wpp_config é CHECADO DENTRO de cada gatilho.
//
//   gat_novo      -> Task 6  (chamado novo aberto)          [IMPLEMENTADO]
//   gat_atribuido -> Task 7  (chamado atribuído a um técnico -> DM)
//   gat_alertas   -> Task 8  (digest de alertas do parque)
//   gat_sla       -> Task 9  (chamado parado / prevenção de estouro de SLA)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evo_api.php';
require_once __DIR__ . '/../alertas_lib.php';
require_once __DIR__ . '/../entidade_alias.php';   // apelido_entidade() — alertas_lib não puxa

/**
 * Task 6 — chamado novo aberto no GLPI -> mensagem no grupo "Chamados".
 *
 * Watermark: portal_wpp_config.wm_novo guarda o date_creation do último chamado
 * já processado. A cada passada busca chamados com date_creation > wm_novo,
 * envia os que ainda não estão em portal_wpp_notificados('novo', <id>) e só
 * avança o watermark até o último efetivamente processado nesta passada.
 *
 * Envio bloqueado/falho NÃO marca o chamado nem trava o watermark além dele:
 * na próxima passada o mesmo chamado volta a ser tentado.
 */
function gat_novo(PDO $pdo): void
{
    if (wpp_cfg_get('on_novo', '1') !== '1') return;

    // Fase 2 sem grupo configurado: não há destino. Loga uma única vez
    // (dedup via portal_wpp_notificados) e sai sem tocar no watermark — quando
    // o grupo for cadastrado, só chamados a partir daí disparam (sem backfill).
    $grupo = (string) wpp_cfg_get('grupo_chamados_jid', '');
    if ($grupo === '') {
        if (!wpp_ja_notificado('sys', 'grupo_chamados_jid_ausente')) {
            wpp_log('sys', '', 'grupo_chamados_jid nao configurado', 'skip');
            wpp_marcar_notificado('sys', 'grupo_chamados_jid_ausente');
        }
        // Mantém o watermark corrente: quando o grupo for cadastrado, só
        // chamados a partir daí disparam — sem despejar o histórico do intervalo.
        wpp_cfg_set('wm_novo', wpp_agora_db($pdo));
        return;
    }

    // Primeira execução sem baseline: só marca o watermark e sai (anti-backfill).
    $wm = wpp_cfg_get('wm_novo');
    if (!$wm) {
        wpp_cfg_set('wm_novo', wpp_agora_db($pdo));
        return;
    }

    $st = $pdo->prepare("
        SELECT t.id, t.name, t.date_creation, t.type, e.completename AS loja
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.date_creation > ?
        ORDER BY t.date_creation ASC
        LIMIT 30
    ");
    $st->execute([$wm]);

    // Comparação de watermark é string pura ('YYYY-MM-DD HH:MM:SS' ordena igual
    // cronologicamente); nada de strtotime aqui.
    //
    // O watermark só avança sobre linhas RESOLVIDAS (já notificadas ou enviadas
    // agora com sucesso) e a passada PARA no primeiro envio que falha/bloqueia —
    // senão um envio OK mais recente empurraria o watermark para além de um
    // chamado ainda não enviado, que nunca mais seria selecionado.
    $maxData = $wm;
    while ($t = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (string) $t['id'];

        if (wpp_ja_notificado('novo', $id)) {
            if ($t['date_creation'] > $maxData) $maxData = $t['date_creation'];
            continue;
        }

        $r = evo_send_text($grupo, gat_msg_novo($t));
        if (empty($r['ok'])) break;   // re-tenta deste ponto na próxima passada

        wpp_marcar_notificado('novo', $id);
        if ($t['date_creation'] > $maxData) $maxData = $t['date_creation'];
    }

    wpp_cfg_set('wm_novo', $maxData);   // só avança até o último resolvido
}

/**
 * Monta o texto da notificação de chamado novo.
 * Ex: "🆕 *Chamado #7* — Lj 003\nPC não liga\n_Incidente · aberto 07/09 14:30_"
 */
function gat_msg_novo(array $t): string
{
    $loja = function_exists('apelido_entidade')
        ? apelido_entidade($t['loja'] ?? '')
        : ($t['loja'] ?? '');
    $data = !empty($t['date_creation']) ? date('d/m H:i', strtotime($t['date_creation'])) : '';
    $tipo = ((int) ($t['type'] ?? 1)) === 2 ? 'Requisição' : 'Incidente';

    return "🆕 *Chamado #{$t['id']}* — " . ($loja ?: 'sem loja') . "\n"
         . ($t['name'] ?? '(sem título)') . "\n"
         . "_{$tipo} · aberto {$data}_";
}

/**
 * Task 7 — chamado atribuído a um técnico -> DM com atraso configurável.
 *
 * Gatilho em duas partes:
 *
 *   PARTE A: detecta atribuições novas (tu.type=2 em chamado aberto que ainda
 *   não está em portal_wpp_notificados('atribuido', "<tid>:<uid>")) e AGENDA a
 *   DM em portal_wpp_dm_agendado com enviar_em = NOW() + cfg_delay_dm_min min.
 *   Não envia nada agora. Técnico sem telefone ativo: marca como tratado e não
 *   agenda. wpp_semear_baseline() já marcou as atribuições correntes, então na
 *   primeira passada nada é agendado (anti-backfill).
 *
 *   PARTE B: pega as DMs 'pendente' cujo enviar_em já venceu e, ANTES de enviar,
 *   revalida que o chamado continua aberto E que ESTE user ainda é tu.type=2.
 *   Se a atribuição mudou ou o chamado fechou no meio do atraso -> 'cancelado'
 *   (nenhum envio). Se ainda vale -> evo_send_text() e 'enviado'. Falha de envio
 *   deixa a linha 'pendente' com enviar_em no passado -> re-tenta na próxima.
 *
 * Ressalva (Fase 2): portal_wpp_dm_agendado tem UNIQUE(ticket_id, glpi_user_id).
 * Se um técnico for desatribuído e reatribuído ao MESMO chamado, o INSERT IGNORE
 * não cria linha nova (a antiga ficou 'cancelado'/'enviado') — a 2ª atribuição
 * não gera DM. Aceitável nesta fase.
 */
function gat_atribuido(PDO $pdo): void
{
    if (wpp_cfg_get('on_atribuido', '1') !== '1') return;

    $delay = max(0, (int) wpp_cfg_get('cfg_delay_dm_min', '5'));

    // ── PARTE A: detectar novas atribuições e AGENDAR (não envia agora) ──
    $novas = $pdo->query("
        SELECT tu.tickets_id AS tid, tu.users_id AS uid, t.name AS name, e.completename AS loja
        FROM glpi_tickets_users tu
        JOIN glpi_tickets t ON t.id = tu.tickets_id AND t.is_deleted = 0 AND t.status IN (1,2,3,4)
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE tu.type = 2
    ")->fetchAll(PDO::FETCH_ASSOC);

    $selTel = $pdo->prepare(
        "SELECT telefone FROM portal_wpp_contatos WHERE glpi_user_id = ? AND ativo = 1 LIMIT 1"
    );
    $insDm = $pdo->prepare(
        "INSERT IGNORE INTO portal_wpp_dm_agendado
             (ticket_id, glpi_user_id, telefone, enviar_em, status)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'pendente')"
    );

    foreach ($novas as $a) {
        $ref = $a['tid'] . ':' . $a['uid'];
        if (wpp_ja_notificado('atribuido', $ref)) continue;   // já tratado (ou baseline)

        $selTel->execute([$a['uid']]);
        $tel = $selTel->fetchColumn();
        if ($tel === false || $tel === null || $tel === '') {
            // técnico sem telefone ativo: marca como tratado e não agenda
            wpp_marcar_notificado('atribuido', $ref);
            continue;
        }

        $insDm->execute([$a['tid'], $a['uid'], $tel, $delay]);
        wpp_marcar_notificado('atribuido', $ref);
    }

    // ── PARTE B: enviar os DMs cujo prazo chegou, se ainda válidos ──
    $pend = $pdo->query("
        SELECT d.id, d.ticket_id, d.glpi_user_id, d.telefone, t.name AS name, e.completename AS loja
        FROM portal_wpp_dm_agendado d
        JOIN glpi_tickets t ON t.id = d.ticket_id
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE d.status = 'pendente' AND d.enviar_em <= NOW()
    ")->fetchAll(PDO::FETCH_ASSOC);

    $revalida = $pdo->prepare("
        SELECT 1
        FROM glpi_tickets t
        JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.users_id = ? AND tu.type = 2
        WHERE t.id = ? AND t.is_deleted = 0 AND t.status IN (1,2,3,4)
        LIMIT 1
    ");
    $marcaCancel = $pdo->prepare("UPDATE portal_wpp_dm_agendado SET status = 'cancelado' WHERE id = ?");
    $marcaEnvio  = $pdo->prepare("UPDATE portal_wpp_dm_agendado SET status = 'enviado' WHERE id = ?");

    foreach ($pend as $d) {
        $revalida->execute([$d['glpi_user_id'], $d['ticket_id']]);
        if (!$revalida->fetchColumn()) {
            // reatribuído ou resolvido antes do prazo -> não envia
            $marcaCancel->execute([$d['id']]);
            continue;
        }

        $r = evo_send_text($d['telefone'], gat_msg_atribuido($d));
        if (!empty($r['ok'])) {
            $marcaEnvio->execute([$d['id']]);
        }
        // falhou: linha segue 'pendente' com enviar_em no passado -> re-tenta na próxima passada
    }
}

/**
 * Monta o texto da DM de chamado atribuído.
 * Ex: "📌 *Chamado #7 atribuído a você* — Lj 003\nPC não liga"
 */
function gat_msg_atribuido(array $d): string
{
    $loja = function_exists('apelido_entidade')
        ? apelido_entidade($d['loja'] ?? '')
        : ($d['loja'] ?? '');

    return "📌 *Chamado #{$d['ticket_id']} atribuído a você*"
         . ($loja ? " — {$loja}" : "") . "\n"
         . ($d['name'] ?? '(sem título)');
}

function gat_alertas(PDO $pdo): void {}    // Task 8

function gat_sla(PDO $pdo): void {}        // Task 9
