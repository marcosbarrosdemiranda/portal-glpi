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

/**
 * Task 8 — digest de alertas do parque -> grupo "Alertas".
 *
 * A cada passada compara o snapshot atual (alertas_lib::alertas_snapshot) com o
 * último snapshot salvo em portal_wpp_config.wpp_snap_alertas. Só manda mensagem
 * quando há item NOVO (que não estava no snapshot anterior) E respeitando o
 * intervalo cfg_digest_alertas_min (watermark wm_alertas_digest = hora do último
 * digest efetivamente enviado).
 *
 * Chave estável usada no diff:
 *   sem_inventario -> name (nome da máquina)
 *   disco_cheio    -> name|volume
 *
 * Regras de snapshot/watermark:
 *   - sem grupo configurado: sai sem tocar em nada (não perde o "novo" quando
 *     o grupo for cadastrado);
 *   - nada novo: atualiza o snapshot (pra refletir itens que saíram) e não envia;
 *   - há novo mas o envio falhou/foi bloqueado: NÃO atualiza snapshot nem
 *     watermark -> tenta de novo na próxima passada;
 *   - envio OK: grava snapshot atual + watermark.
 */
function gat_alertas(PDO $pdo): void
{
    if (wpp_cfg_get('on_alertas', '1') !== '1') return;

    $grupo = (string) wpp_cfg_get('grupo_alertas_jid', '');
    if ($grupo === '') return;   // sem destino: não mexe no snapshot p/ não perder o "novo"

    // Throttle do digest: no máximo 1 a cada cfg_digest_alertas_min minutos.
    $intervalo = max(1, (int) wpp_cfg_get('cfg_digest_alertas_min', '15'));
    $ultimo    = wpp_cfg_get('wm_alertas_digest');
    if ($ultimo && (strtotime(wpp_agora_db($pdo)) - strtotime($ultimo)) < $intervalo * 60) {
        return;   // ainda não é hora
    }

    $atual    = alertas_snapshot($pdo);
    $anterior = json_decode((string) wpp_cfg_get('wpp_snap_alertas', '{}'), true);
    if (!is_array($anterior)) $anterior = ['sem_inventario' => [], 'disco_cheio' => []];

    $novos = alertas_novos($atual, $anterior);

    if (!$novos['inv'] && !$novos['disco']) {
        // Nada novo: atualiza o snapshot (reflete itens que saíram) mas não envia.
        wpp_cfg_set('wpp_snap_alertas', json_encode($atual));
        return;
    }

    $r = evo_send_text($grupo, gat_msg_digest($atual, $novos['inv'], $novos['disco']));
    if (!empty($r['ok'])) {
        wpp_cfg_set('wpp_snap_alertas', json_encode($atual));
        wpp_cfg_set('wm_alertas_digest', wpp_agora_db($pdo));
    }
    // falhou/bloqueado: não grava snapshot nem watermark -> re-tenta na próxima passada
}

/**
 * Diff puro entre dois snapshots de alertas. Retorna só as linhas de $atual
 * cuja chave estável NÃO aparecia em $anterior.
 *   sem_inventario -> chave = name
 *   disco_cheio    -> chave = name|volume
 *
 * $anterior malformado/ausente (chaves faltando) é tratado como vazio -> todos
 * os itens de $atual entram como "novos".
 */
function alertas_novos(array $atual, array $anterior): array
{
    $keysInv = [];
    foreach ($anterior['sem_inventario'] ?? [] as $r) {
        $keysInv[(string) ($r['name'] ?? '')] = true;
    }
    $keysDisco = [];
    foreach ($anterior['disco_cheio'] ?? [] as $r) {
        $keysDisco[($r['name'] ?? '') . '|' . ($r['volume'] ?? '')] = true;
    }

    $novosInv = [];
    foreach ($atual['sem_inventario'] ?? [] as $r) {
        if (!isset($keysInv[(string) ($r['name'] ?? '')])) $novosInv[] = $r;
    }
    $novosDisco = [];
    foreach ($atual['disco_cheio'] ?? [] as $r) {
        if (!isset($keysDisco[($r['name'] ?? '') . '|' . ($r['volume'] ?? '')])) $novosDisco[] = $r;
    }

    return ['inv' => $novosInv, 'disco' => $novosDisco];
}

/**
 * Monta o texto do digest de alertas.
 * Ex:
 *   🔔 *Alertas do parque*
 *
 *   📉 Sem inventário +7d: 12 (novos: PC-CAIXA-01 (Lj 003), PC-RET-03)
 *   💾 Disco cheio: 3 (novos: SRV-01 (Lj 001) D: 95%)
 *
 * A linha só ganha o "(novos: …)" quando há itens novos naquela categoria.
 * Listas são truncadas em 5 itens com sufixo "…+N". apelido_entidade() é
 * aplicado na loja de cada item (o nome da máquina em si nunca vira apelido).
 */
function gat_msg_digest(array $atual, array $novosInv, array $novosDisco): string
{
    $totInv   = count($atual['sem_inventario'] ?? []);
    $totDisco = count($atual['disco_cheio'] ?? []);

    $linhaInv = "📉 Sem inventário +7d: {$totInv}";
    if ($novosInv) {
        $itens = array_map(static function (array $r): string {
            $nome = (string) ($r['name'] ?? '(sem nome)');
            $loja = function_exists('apelido_entidade') ? apelido_entidade($r['loja'] ?? '') : (string) ($r['loja'] ?? '');
            return $loja !== '' ? "{$nome} ({$loja})" : $nome;
        }, $novosInv);
        $linhaInv .= ' (novos: ' . gat_lista_curta($itens) . ')';
    }

    $linhaDisco = "💾 Disco cheio: {$totDisco}";
    if ($novosDisco) {
        $itens = array_map(static function (array $r): string {
            $nome = (string) ($r['name'] ?? '(sem nome)');
            $loja = function_exists('apelido_entidade') ? apelido_entidade($r['loja'] ?? '') : (string) ($r['loja'] ?? '');
            $vol  = (string) ($r['volume'] ?? '?');
            $pct  = (int) ($r['pct'] ?? 0);
            $base = $loja !== '' ? "{$nome} ({$loja})" : $nome;
            return "{$base} {$vol} {$pct}%";
        }, $novosDisco);
        $linhaDisco .= ' (novos: ' . gat_lista_curta($itens) . ')';
    }

    return "🔔 *Alertas do parque*\n\n{$linhaInv}\n{$linhaDisco}";
}

/**
 * Junta os itens com ", "; se houver mais de $max, mostra os primeiros $max
 * e acrescenta " …+N" com o restante.
 */
function gat_lista_curta(array $itens, int $max = 5): string
{
    $n = count($itens);
    if ($n <= $max) return implode(', ', $itens);
    return implode(', ', array_slice($itens, 0, $max)) . ' …+' . ($n - $max);
}

/**
 * Task 9 — SLA: chamado parado ou perto de furar o SLA -> grupo "Chamados".
 *
 * Dois braços independentes, ambos deduplicados via portal_wpp_notificados
 * (tipo 'sla'). Toda janela de tempo usa o relógio do BANCO (NOW()), nunca
 * date() do PHP — o container roda em UTC e o glpi-db em -04:00.
 *
 *   A) PARADO: chamado Novo(1)/Atribuído(2) sem follow-up há mais de
 *      $horasParado h E cujo date_mod também está há mais de $horasParado h
 *      (ninguém encostou no chamado). Dedup por DIA: hash = 'parado:<YYYY-MM-DD>'.
 *      Ou seja, um chamado genuinamente travado recebe UMA cutucada por dia,
 *      todo dia, até alguém mexer nele. Isso é intencional (lembrete diário),
 *      não backfill: a baseline marca 'parado:<data-da-baseline>' pra todos os
 *      abertos, então no dia da baseline nada dispara; a partir do dia seguinte
 *      o hash muda e o chamado ainda parado é cutucado uma vez.
 *
 *   B) PRÉ-VENCIMENTO: time_to_resolve entre agora e agora + $prevencMin min,
 *      chamado não resolvido/fechado (status NOT IN 5,6). Dedup PERMANENTE:
 *      hash = 'prevenc' (um aviso por chamado, pra sempre).
 *
 * Limitação conhecida (Fase 2): a baseline marca 'prevenc' pra TODO chamado
 * aberto no momento da semeadura. Um chamado que já estava aberto na baseline
 * e só depois se aproxima do SLA NUNCA recebe o aviso de pré-vencimento —
 * 'prevenc' só dispara para chamados criados APÓS a baseline. Numa operação de
 * rede de lojas (chamados abrindo/fechando o tempo todo) isso é aceitável.
 *
 * Falha/bloqueio de envio não marca o dedup -> re-tentado na próxima passada.
 */
function gat_sla(PDO $pdo): void
{
    if (wpp_cfg_get('on_sla', '1') !== '1') return;

    $grupo = (string) wpp_cfg_get('grupo_chamados_jid', '');
    if ($grupo === '') return;

    // max(1, ...) garante inteiro >= 1 -> seguro interpolar direto no INTERVAL
    // (MariaDB não faz bind confiável de parâmetro dentro de INTERVAL).
    $horasParado = max(1, (int) wpp_cfg_get('cfg_sla_horas', '4'));
    $prevencMin  = max(1, (int) wpp_cfg_get('cfg_sla_prevenc_min', '30'));
    $hoje = substr(wpp_agora_db($pdo), 0, 10);   // relógio do BANCO

    // ── A) PARADO ──
    $stParados = $pdo->prepare("
        SELECT t.id, t.name, e.completename AS loja
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.status IN (1,2)
          AND t.date_mod < (NOW() - INTERVAL " . (int) $horasParado . " HOUR)
          AND NOT EXISTS (
              SELECT 1 FROM glpi_itilfollowups f
              WHERE f.itemtype = 'Ticket' AND f.items_id = t.id
                AND f.date_creation > (NOW() - INTERVAL " . (int) $horasParado . " HOUR)
          )
        ORDER BY t.date_mod ASC
        LIMIT 30
    ");
    $stParados->execute();
    foreach ($stParados->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $hash = 'parado:' . $hoje;
        if (wpp_ja_notificado('sla', (string) $t['id'], $hash)) continue;
        $r = evo_send_text($grupo, gat_msg_sla($t, 'parado', $horasParado));
        if (!empty($r['ok'])) wpp_marcar_notificado('sla', (string) $t['id'], $hash);
    }

    // ── B) PRÉ-VENCIMENTO ──
    $stPrevenc = $pdo->prepare("
        SELECT t.id, t.name, e.completename AS loja, t.time_to_resolve
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.status NOT IN (5,6)
          AND t.time_to_resolve IS NOT NULL
          AND t.time_to_resolve BETWEEN NOW() AND (NOW() + INTERVAL " . (int) $prevencMin . " MINUTE)
        ORDER BY t.time_to_resolve ASC
        LIMIT 30
    ");
    $stPrevenc->execute();
    foreach ($stPrevenc->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (wpp_ja_notificado('sla', (string) $t['id'], 'prevenc')) continue;
        $r = evo_send_text($grupo, gat_msg_sla($t, 'prevenc', $prevencMin));
        if (!empty($r['ok'])) wpp_marcar_notificado('sla', (string) $t['id'], 'prevenc');
    }
}

/**
 * Monta o texto da notificação de SLA.
 *   'parado':  "⏳ *Chamado #7 parado há +4h* — Lj 003\nPC não liga"
 *   'prevenc': "⚠️ *Chamado #7 perto de furar o SLA* — Lj 003\nvence 07/09 15:00\nPC não liga"
 * $n = horas (parado) ou minutos (prevenc), só usado no texto de 'parado'.
 */
function gat_msg_sla(array $t, string $motivo, int $n): string
{
    $loja = function_exists('apelido_entidade')
        ? apelido_entidade($t['loja'] ?? '')
        : ($t['loja'] ?? '');
    $suf = $loja ? " — {$loja}" : "";

    if ($motivo === 'parado') {
        return "⏳ *Chamado #{$t['id']} parado há +{$n}h*{$suf}\n"
             . ($t['name'] ?? '(sem título)');
    }

    $venc = !empty($t['time_to_resolve']) ? date('d/m H:i', strtotime($t['time_to_resolve'])) : '';
    return "⚠️ *Chamado #{$t['id']} perto de furar o SLA*{$suf}\n"
         . "vence {$venc}\n"
         . ($t['name'] ?? '(sem título)');
}
