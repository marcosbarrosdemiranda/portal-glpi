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
require_once __DIR__ . '/../alertas_tipos.php';   // alertas_catalogo(), alertas_config_do_tipo(), cria portal_alertas_ocorrencias

/**
 * Task 6 — chamado novo aberto no GLPI -> mensagem no grupo "Chamados".
 *
 * Watermark: portal_wpp_config.wm_novo guarda o date_creation do último chamado
 * já processado. A cada passada busca chamados com date_creation >= wm_novo
 * (>=, não >: dois chamados no mesmo segundo em que a query pega só o primeiro
 * fariam o outro nunca mais entrar; o wpp_ja_notificado('novo', id) logo abaixo
 * dedup as linhas de borda re-selecionadas), envia os que ainda não estão em
 * portal_wpp_notificados('novo', <id>) e só avança o watermark até o último
 * efetivamente processado nesta passada.
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
        SELECT t.id, t.name, t.content, t.date_creation, t.date_mod, t.type,
               e.completename AS loja,
               " . gat_sql_nomes_ticket_user(1) . " AS req_nomes,
               " . gat_sql_nomes_ticket_user(2) . " AS tec_nomes
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0 AND t.date_creation >= ?
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
/**
 * Subquery que devolve os nomes dos usuários ligados a um ticket por tipo
 * (1 = requerente, 2 = técnico), no formato "Firstname Realname" que o GLPI
 * usa, separados por vírgula. String vazia se não houver ninguém.
 * $tipo é literal inteiro controlado por nós — não vai parâmetro de usuário.
 */
function gat_sql_nomes_ticket_user(int $tipo): string
{
    $tipo = (int) $tipo;
    return "(SELECT GROUP_CONCAT(
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.realname,''))), ''), u.name)
                ORDER BY u.firstname SEPARATOR ', ')
             FROM glpi_tickets_users tu
             JOIN glpi_users u ON u.id = tu.users_id
             WHERE tu.tickets_id = t.id AND tu.type = {$tipo})";
}

/**
 * Reduz um texto HTML do GLPI a texto plano curto (pra caber no WhatsApp).
 * O GLPI costuma guardar o conteúdo com as tags escapadas (&lt;p&gt;...), às
 * vezes duas vezes — por isso decodifica, tira as tags e decodifica de novo.
 */
function gat_texto_plano(string $html, int $max = 500): string
{
    $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');   // &lt;p&gt; -> <p>
    $t = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ' ', $t);
    $t = strip_tags($t);                                               // remove o resto das tags
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');      // &nbsp; etc. remanescentes
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if (mb_strlen($t) > $max) $t = rtrim(mb_substr($t, 0, $max - 1)) . '…';
    return $t;
}

/**
 * Envio dos gatilhos com ponto de injeção pra teste. Produção: evo_send_text()
 * (que passa pelo guardrail). Teste: definir
 *   $GLOBALS['__wpp_fake_send'] = fn(string $destino, string $texto): array => ['ok'=>bool]
 * intercepta sem tocar a rede.
 */
function gat_enviar(string $destino, string $texto): array
{
    if (isset($GLOBALS['__wpp_fake_send']) && is_callable($GLOBALS['__wpp_fake_send'])) {
        return (array) ($GLOBALS['__wpp_fake_send'])($destino, $texto);
    }
    return evo_send_text($destino, $texto);
}

/**
 * Deriva um título legível da chave estável de uma ocorrência.
 *   'sem_inv:PC-01'   -> 'PC-01'
 *   'disco:SRV-01|C:' -> 'SRV-01 (C:)'
 *   sem ':'           -> a própria chave
 */
function gat_alerta_titulo_da_chave(string $chave): string
{
    $pos = strpos($chave, ':');
    if ($pos === false) return $chave;
    $resto = substr($chave, $pos + 1);
    if (strpos($resto, '|') !== false) {
        [$nome, $vol] = explode('|', $resto, 2);
        return $vol !== '' ? "{$nome} ({$vol})" : $nome;
    }
    return $resto !== '' ? $resto : $chave;
}

/** "titulo — loja" (loja neutra vazia/"—"/"Sem loja" é omitida). */
function gat_alerta_titulo_loja(string $titulo, string $loja): string
{
    $loja = trim($loja);
    return ($loja !== '' && $loja !== '—' && $loja !== 'Sem loja') ? "{$titulo} — {$loja}" : $titulo;
}

function gat_msg_alerta_novo(string $nomeTipo, array $o): string
{
    return "🔔 *{$nomeTipo}*\n"
         . gat_alerta_titulo_loja((string) ($o['titulo'] ?? '(sem título)'), (string) ($o['loja'] ?? '')) . "\n"
         . (string) ($o['detalhe'] ?? '');
}

function gat_msg_alerta_resolvido(string $nomeTipo, string $chave): string
{
    return "✅ *Resolvido — {$nomeTipo}*\n" . gat_alerta_titulo_da_chave($chave);
}

/**
 * Lembrete de ocorrências ainda abertas. Até 8 linhas "• titulo — loja";
 * o restante vira "…+N".
 */
function gat_msg_alerta_lembrete(string $nomeTipo, array $devidas): string
{
    $n = count($devidas);
    $m = "⏰ *{$nomeTipo} — ainda pendente* ({$n})";
    foreach (array_slice($devidas, 0, 8) as $o) {
        $m .= "\n• " . gat_alerta_titulo_loja((string) ($o['titulo'] ?? '?'), (string) ($o['loja'] ?? ''));
    }
    if ($n > 8) $m .= "\n…+" . ($n - 8);
    return $m;
}

function gat_msg_novo(array $t): string
{
    $fmt  = fn($d) => !empty($d) ? date('d/m/Y H:i', strtotime($d)) : '—';
    $loja = function_exists('apelido_entidade')
        ? apelido_entidade($t['loja'] ?? '')
        : ($t['loja'] ?? '');
    $tipo = ((int) ($t['type'] ?? 1)) === 2 ? 'Requisição' : 'Incidente';

    $req  = trim((string) ($t['req_nomes'] ?? ''));
    $tec  = trim((string) ($t['tec_nomes'] ?? ''));
    $desc = gat_texto_plano((string) ($t['content'] ?? ''));

    $m  = "🆕 *Novo chamado criado! ID {$t['id']}*\n";
    $m .= "📌 *Título:* " . (($t['name'] ?? '') !== '' ? $t['name'] : '(sem título)') . "\n";
    $m .= "📝 *Descrição:* " . ($desc !== '' ? $desc : '—') . "\n";
    $m .= "📅 *Data de Criação:* " . $fmt($t['date_creation'] ?? null) . "\n";
    $m .= "🔄 *Última Modificação:* " . $fmt($t['date_mod'] ?? null) . "\n";
    $m .= "🏢 *Loja:* " . ($loja !== '' ? $loja : '—') . "\n";
    $m .= "🙋 *Requerente:* " . ($req !== '' ? $req : '—') . "\n";
    if ($tec !== '') $m .= "👷 *Atendente(s):* {$tec}\n";
    $m .= "_{$tipo}_";
    return $m;
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

    // Cap de retentativa: DM 'pendente' cujo prazo venceu há mais de 1 dia é
    // falha recorrente de envio — cancela pra não retentar pra sempre (cada
    // passada gravava uma linha em portal_wpp_log => ~2880 linhas/dia por DM presa).
    $pdo->exec("UPDATE portal_wpp_dm_agendado SET status = 'cancelado'
                WHERE status = 'pendente' AND enviar_em < NOW() - INTERVAL 1 DAY");

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
 * Alertas do parque -> grupo "Alertas", POR OCORRÊNCIA.
 *
 * Fonte da verdade: alertas_catalogo() (alertas_tipos.php). Para cada tipo ATIVO
 * chama gat_alertas_tipo(), isolado num try/catch (um tipo com erro não derruba
 * os outros). A decisão de notificar é o switch notif_whatsapp de cada tipo
 * (tela alertas_config.php) — não há mais toggle único "on_alertas".
 */
function gat_alertas(PDO $pdo): void
{
    $grupo = (string) wpp_cfg_get('grupo_alertas_jid', '');

    foreach (alertas_catalogo() as $slug => $def) {
        $cfg = alertas_config_do_tipo($pdo, $slug);
        if (!$cfg['ativo']) continue;
        try {
            gat_alertas_tipo($pdo, $slug, $def, $cfg, $grupo);
        } catch (\Throwable $e) {
            wpp_log('sys', '', "gat_alertas/{$slug}: " . $e->getMessage(), 'erro');
        }
    }
}

/**
 * Sincroniza portal_alertas_ocorrencias de UM tipo com o que o check() retorna
 * agora, e notifica pelo grupo (se notif_whatsapp e $grupo != '').
 *
 *   NOVA      (atual, não guardada)  -> 🔔  + INSERT
 *   RESOLVIDA (guardada, não atual)  -> ✅  + DELETE
 *   ABERTA + lembrete_min>0 + venceu -> ⏰  + UPDATE ultimo_lembrete
 *
 * notif_whatsapp=0 (ou grupo vazio): grava/apaga a tabela mas NÃO envia — assim
 * ligar a notificação depois não despeja o acúmulo.
 *
 * Envio que falha/bloqueia NÃO muda o estado daquela chave -> re-tenta na próxima.
 *
 * @param array $def  precisa de 'nome' (string) e 'check' (callable(PDO,array):array)
 * @param array $cfg  precisa de 'notif_whatsapp' (bool), 'params' (array), 'lembrete_min' (int)
 */
function gat_alertas_tipo(PDO $pdo, string $slug, array $def, array $cfg, string $grupo): void
{
    $notifica = !empty($cfg['notif_whatsapp']) && $grupo !== '';
    $nome     = (string) ($def['nome'] ?? $slug);

    $atuais = call_user_func($def['check'], $pdo, $cfg['params'] ?? []);
    $porChave = [];
    foreach ($atuais as $o) $porChave[$o['chave']] = $o;

    $st = $pdo->prepare(
        "SELECT chave, primeiro_visto, ultimo_lembrete
         FROM portal_alertas_ocorrencias WHERE tipo = ?"
    );
    $st->execute([$slug]);
    $guardadas = $st->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);   // chave => row

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())"
    );
    $del = $pdo->prepare(
        "DELETE FROM portal_alertas_ocorrencias WHERE tipo = ? AND chave = ?"
    );

    // Horário de silêncio por tipo (portal_alertas_horario, configurável em
    // alertas_config.php) — só se aplica quando notif_whatsapp está ligado;
    // com notif desligado o comportamento de sempre gravar sem enviar
    // continua igual (não é affetado por horário nenhum).
    $dentroDoHorario = !$notifica || alertas_horario_permitido($pdo, $slug);

    // NOVAS
    foreach ($atuais as $o) {
        if (isset($guardadas[$o['chave']])) continue;
        if ($notifica) {
            if (!$dentroDoHorario) continue;   // fora do horário -> não grava, re-tenta quando a janela abrir (igual envio falhar)
            $r = gat_enviar($grupo, gat_msg_alerta_novo($nome, $o));
            if (empty($r['ok'])) continue;   // não grava -> re-tenta na próxima passada
        }
        $ins->execute([$slug, $o['chave']]);
        alertas_historico_registrar(
            $pdo, $slug, (string) $o['chave'], 'nova',
            $o['titulo'] ?? null, $o['loja'] ?? null, $o['detalhe'] ?? null
        );
    }

    // RESOLVIDAS
    foreach ($guardadas as $chave => $row) {
        if (isset($porChave[$chave])) continue;
        if ($notifica) {
            $r = gat_enviar($grupo, gat_msg_alerta_resolvido($nome, (string) $chave));
            if (empty($r['ok'])) continue;   // não apaga -> re-tenta
        }
        $del->execute([$slug, $chave]);
        alertas_historico_registrar($pdo, $slug, (string) $chave, 'resolvida', gat_alerta_titulo_da_chave((string) $chave));
    }

    // LEMBRETE — também respeita o horário de silêncio (nada de reenviar às 3h
    // só porque venceu o intervalo; espera a janela abrir de novo).
    if ($notifica && $dentroDoHorario && (int) ($cfg['lembrete_min'] ?? 0) > 0) {
        $agora   = strtotime(wpp_agora_db($pdo));
        $limite  = (int) $cfg['lembrete_min'] * 60;
        $devidas = [];
        foreach ($atuais as $o) {
            $g = $guardadas[$o['chave']] ?? null;
            if (!$g) continue;   // recém-inserida nesta passada
            $ref = $g['ultimo_lembrete'] ?: $g['primeiro_visto'];
            if ($agora - strtotime((string) $ref) >= $limite) $devidas[] = $o;
        }
        if ($devidas) {
            $r = gat_enviar($grupo, gat_msg_alerta_lembrete($nome, $devidas));
            if (!empty($r['ok'])) {
                $chaves = array_column($devidas, 'chave');
                $ph  = implode(',', array_fill(0, count($chaves), '?'));
                $upd = $pdo->prepare(
                    "UPDATE portal_alertas_ocorrencias SET ultimo_lembrete = NOW()
                     WHERE tipo = ? AND chave IN ($ph)"
                );
                $upd->execute(array_merge([$slug], $chaves));
            }
        }
    }
}

/**
 * Task 9 — SLA: chamado parado ou perto de furar o SLA -> grupo "Chamados".
 *
 * Dois braços independentes, ambos deduplicados via portal_wpp_notificados
 * (tipo 'sla'). Toda janela de tempo usa o relógio do BANCO (NOW()) por
 * consistência — o PHP do container roda em America/Campo_Grande
 * (docker/php-custom.ini), o mesmo fuso do glpi-db (-04:00), então date()/
 * strtotime() e o NOW() do banco fazem round-trip sem shift.
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
