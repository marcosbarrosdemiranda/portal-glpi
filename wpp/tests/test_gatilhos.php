<?php
// Testes dos gatilhos do worker WhatsApp (Fase 2).
// Roda com `php wpp/tests/run.php`. As partes que tocam o banco rodam no deploy
// via `docker exec glpi-web php .../wpp/tests/run.php` (banco glpi2 acessível).

require_once __DIR__ . '/../gatilhos.php';
global $pdo;

// ---------------------------------------------------------------------------
// gat_msg_novo() — montagem da mensagem (puro, sem banco)
// ---------------------------------------------------------------------------
$msg = gat_msg_novo([
    'id'            => 7,
    'name'          => 'PC nao liga',
    'loja'          => 'Grupo Gmais > Loja 3',
    'date_creation' => '2026-09-07 14:30:00',
    'type'          => 1,
]);
t_eq(
    $msg,
    "🆕 *Novo chamado criado! ID 7*\n"
    . "📌 *Título:* PC nao liga\n"
    . "📝 *Descrição:* —\n"
    . "📅 *Data de Criação:* 07/09/2026 14:30\n"
    . "🔄 *Última Modificação:* —\n"
    . "🏢 *Loja:* Grupo Gmais > Loja 3\n"
    . "🙋 *Requerente:* —\n"
    . "_Incidente_",
    'gat_msg_novo: 7 campos, fallback "—" nos ausentes'
);

// type 2 -> "Requisição"; requerente + atendente (nomes já vêm prontos do GLPI).
// descrição: GLPI guarda com as tags escapadas (&lt;p&gt;...&lt;br&gt;...)
$msgReq = gat_msg_novo([
    'id'            => 8,
    'name'          => 'Instalar impressora',
    'content'       => '&lt;p&gt;Impressora nova&lt;br&gt;na recep&amp;ccedil;&amp;atilde;o&lt;/p&gt;',
    'loja'          => 'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM',
    'date_creation' => '2026-09-07 09:05:00',
    'date_mod'      => '2026-09-07 10:00:00',
    'type'          => 2,
    'req_nomes'     => 'SAC Rincão BTO',
    'tec_nomes'     => 'Felix Agnelo',
]);
t_eq(
    $msgReq,
    "🆕 *Novo chamado criado! ID 8*\n"
    . "📌 *Título:* Instalar impressora\n"
    . "📝 *Descrição:* Impressora nova na recepção\n"
    . "📅 *Data de Criação:* 07/09/2026 09:05\n"
    . "🔄 *Última Modificação:* 07/09/2026 10:00\n"
    . "🏢 *Loja:* Lj 003\n"
    . "🙋 *Requerente:* SAC Rincão BTO\n"
    . "👷 *Atendente(s):* Felix Agnelo\n"
    . "_Requisição_",
    'gat_msg_novo: type=2 + apelido_entidade + requerente e atendente do GLPI + descrição em texto plano'
);

// título ausente -> "(sem título)"; loja/req/datas ausentes -> "—"; sem atendente -> linha some
$msgSemLoja = gat_msg_novo(['id' => 9, 'date_creation' => '2026-09-07 00:00:00']);
t_eq(
    $msgSemLoja,
    "🆕 *Novo chamado criado! ID 9*\n"
    . "📌 *Título:* (sem título)\n"
    . "📝 *Descrição:* —\n"
    . "📅 *Data de Criação:* 07/09/2026 00:00\n"
    . "🔄 *Última Modificação:* —\n"
    . "🏢 *Loja:* —\n"
    . "🙋 *Requerente:* —\n"
    . "_Incidente_",
    'gat_msg_novo: campos ausentes usam os fallbacks e a linha de atendente some'
);

// gat_texto_plano: tags escapadas do GLPI + entidades + colapsa espaço + trunca
t_eq(gat_texto_plano('&lt;p&gt;oi   &lt;b&gt;l&amp;aacute;&lt;/b&gt;&lt;br&gt;tudo   bem?&lt;/p&gt;'),
    'oi lá tudo bem?',
    'gat_texto_plano: tags escapadas do GLPI viram texto plano');
t_eq(gat_texto_plano('<p>tag crua tambem</p>'), 'tag crua tambem',
    'gat_texto_plano: aguenta tag crua também');
t_eq(gat_texto_plano(str_repeat('a', 600), 100), str_repeat('a', 99) . '…',
    'gat_texto_plano trunca no limite');

// ---------------------------------------------------------------------------
// gat_msg_atribuido() — montagem da mensagem (puro, sem banco)
// ---------------------------------------------------------------------------
$msgAtr = gat_msg_atribuido([
    'ticket_id' => 7,
    'name'      => 'PC nao liga',
    'loja'      => 'Grupo Gmais > Loja 3',
]);
t_eq(
    $msgAtr,
    "📌 *Chamado #7 atribuído a você* — Grupo Gmais > Loja 3\nPC nao liga",
    'gat_msg_atribuido monta a string esperada (com loja)'
);

// loja ausente -> sem sufixo " — "; título ausente -> "(sem título)"
$msgAtrSemLoja = gat_msg_atribuido(['ticket_id' => 9]);
t_eq(
    $msgAtrSemLoja,
    "📌 *Chamado #9 atribuído a você*\n(sem título)",
    'gat_msg_atribuido: loja/título ausentes usam os fallbacks'
);

// ---------------------------------------------------------------------------
// gat_msg_sla() — montagem da mensagem (puro, sem banco)
// ---------------------------------------------------------------------------
// 'parado' com loja -> aplica apelido_entidade e usa o sufixo " — "
t_eq(
    gat_msg_sla(
        ['id' => 7, 'name' => 'PC nao liga', 'loja' => 'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM'],
        'parado',
        4
    ),
    "⏳ *Chamado #7 parado há +4h* — Lj 003\nPC nao liga",
    'gat_msg_sla parado: apelido na loja + horas no titulo'
);

// 'parado' sem loja -> sem sufixo; titulo ausente -> "(sem título)"
t_eq(
    gat_msg_sla(['id' => 9], 'parado', 8),
    "⏳ *Chamado #9 parado há +8h*\n(sem título)",
    'gat_msg_sla parado: loja/titulo ausentes usam fallbacks'
);

// 'prevenc' com loja e time_to_resolve -> linha "vence dd/mm HH:MM"
t_eq(
    gat_msg_sla(
        ['id' => 12, 'name' => 'Impressora travou', 'loja' => 'Grupo Gmais > Loja 3', 'time_to_resolve' => '2026-09-07 15:00:00'],
        'prevenc',
        30
    ),
    "⚠️ *Chamado #12 perto de furar o SLA* — Grupo Gmais > Loja 3\nvence 07/09 15:00\nImpressora travou",
    'gat_msg_sla prevenc: sufixo de loja + data de vencimento formatada'
);

// 'prevenc' sem loja e sem time_to_resolve -> "vence " vazio, sem sufixo
t_eq(
    gat_msg_sla(['id' => 13], 'prevenc', 30),
    "⚠️ *Chamado #13 perto de furar o SLA*\nvence \n(sem título)",
    'gat_msg_sla prevenc: sem loja/time_to_resolve usa fallbacks'
);

// ---------------------------------------------------------------------------
// gat_alerta_titulo_da_chave() + builders de mensagem de alerta (puros)
// ---------------------------------------------------------------------------
t_eq(gat_alerta_titulo_da_chave('sem_inv:PC-CAIXA-01'), 'PC-CAIXA-01', 'titulo_da_chave: sem_inv');
t_eq(gat_alerta_titulo_da_chave('disco:SRV-01|C:'), 'SRV-01 (C:)', 'titulo_da_chave: disco vira "nome (volume)"');
t_eq(gat_alerta_titulo_da_chave('coisa-sem-dois-pontos'), 'coisa-sem-dois-pontos', 'titulo_da_chave: fallback');

$oNovo = ['chave' => 'sem_inv:PC-01', 'titulo' => 'PC-01', 'loja' => 'Loja 3', 'detalhe' => '12 dias sem reportar'];
t_eq(
    gat_msg_alerta_novo('Máquinas sem reportar inventário', $oNovo),
    "🔔 *Máquinas sem reportar inventário*\nPC-01 — Loja 3\n12 dias sem reportar",
    'msg_alerta_novo: nome / titulo — loja / detalhe'
);
// loja neutra ('—' ou vazio) não vira " — —"
$oSemLoja = ['chave' => 'disco:SRV|C:', 'titulo' => 'SRV', 'loja' => '—', 'detalhe' => 'C: · 95% cheio'];
t_eq(
    gat_msg_alerta_novo('Discos quase cheios', $oSemLoja),
    "🔔 *Discos quase cheios*\nSRV\nC: · 95% cheio",
    'msg_alerta_novo: loja "—" é omitida'
);

t_eq(
    gat_msg_alerta_resolvido('Discos quase cheios', 'disco:SRV-01|C:'),
    "✅ *Resolvido — Discos quase cheios*\nSRV-01 (C:)",
    'msg_alerta_resolvido: extrai o titulo legível da chave'
);

$devidas = [];
for ($i = 1; $i <= 10; $i++) $devidas[] = ['titulo' => "PC-$i", 'loja' => 'Loja 1'];
$msgLem = gat_msg_alerta_lembrete('Máquinas sem reportar inventário', $devidas);
t_ok(strpos($msgLem, "⏰ *Máquinas sem reportar inventário — ainda pendente* (10)") === 0, 'msg_alerta_lembrete: cabeçalho com total');
t_eq(substr_count($msgLem, "\n• "), 8, 'msg_alerta_lembrete: no máximo 8 linhas de item');
t_ok(strpos($msgLem, "…+2") !== false, 'msg_alerta_lembrete: sufixo "…+2" quando passa de 8');
t_eq(
    gat_msg_alerta_lembrete('X', [['titulo' => 'A', 'loja' => 'L1'], ['titulo' => 'B', 'loja' => '']]),
    "⏰ *X — ainda pendente* (2)\n• A — L1\n• B",
    'msg_alerta_lembrete: 2 itens, loja vazia omitida, sem sufixo'
);

// gat_enviar sem fake -> cai no evo_send_text (aqui só garante que o seam existe e é usado)
$GLOBALS['__wpp_fake_send'] = fn($d, $t) => ['ok' => true, 'eco' => [$d, $t]];
$r = gat_enviar('123@g.us', 'oi');
t_ok(!empty($r['ok']) && $r['eco'][0] === '123@g.us', 'gat_enviar: usa $GLOBALS[__wpp_fake_send] quando definido');
unset($GLOBALS['__wpp_fake_send']);

// ---------------------------------------------------------------------------
// gat_alertas_tipo() — sincroniza portal_alertas_ocorrencias e envia por ocorrência
// (precisa de banco: roda no deploy contra glpi2). Usa um tipo sintético
// '__teste_alerta__' e um check-fake -> não toca nos alertas reais.
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $TIPO = '__teste_alerta__';
    $GRP  = '111222333@g.us';
    $limpa = function () use ($pdo, $TIPO) {
        $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ?")->execute([$TIPO]);
        $pdo->prepare("DELETE FROM portal_alertas_historico WHERE tipo = ?")->execute([$TIPO]);
        $pdo->prepare("DELETE FROM portal_alertas_horario WHERE tipo = ?")->execute([$TIPO]);
    };
    // check-fake: devolve o que estiver em $GLOBALS['__fake_ocorr']
    $defFake = ['nome' => 'Alerta de Teste', 'check' => function ($pdo, $params) {
        return $GLOBALS['__fake_ocorr'] ?? [];
    }];
    $cfg = fn(bool $notif, int $lem = 0) => ['notif_whatsapp' => $notif, 'params' => [], 'lembrete_min' => $lem];
    $oc  = fn(string $k) => ['chave' => $k, 'titulo' => $k, 'loja' => 'L1', 'detalhe' => 'x'];

    try {
        // -- NOVA + notif on: 1 envio, 1 linha inserida --
        $limpa();
        $enviadas = [];
        $GLOBALS['__wpp_fake_send'] = function ($d, $t) use (&$enviadas) { $enviadas[] = [$d, $t]; return ['ok' => true]; };
        $GLOBALS['__fake_ocorr'] = [$oc('a'), $oc('b')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 2, 'gat_alertas_tipo: 2 ocorrências novas -> 2 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 2,
             'gat_alertas_tipo: 2 linhas gravadas');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_historico WHERE tipo='$TIPO' AND evento='nova'")->fetchColumn(), 2,
             'gat_alertas_tipo: 2 novas registradas no histórico');

        // -- 2ª passada, mesmas ocorrências: nada novo, 0 envios --
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: sem mudança -> 0 envios');

        // -- RESOLVIDA: 'b' sumiu -> 1 envio "resolvido" + linha apagada --
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('a')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 1, 'gat_alertas_tipo: 1 resolvida -> 1 envio');
        t_ok(strpos($enviadas[0][1], '✅ *Resolvido') === 0, 'gat_alertas_tipo: mensagem de resolvido');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: linha da resolvida apagada');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_historico WHERE tipo='$TIPO' AND evento='resolvida'")->fetchColumn(), 1,
             'gat_alertas_tipo: resolvida registrada no histórico');

        // -- LEMBRETE: lembrete_min=30, primeiro_visto forçado pra 40min atrás -> 1 lembrete --
        $pdo->prepare("UPDATE portal_alertas_ocorrencias SET primeiro_visto = NOW() - INTERVAL 40 MINUTE, ultimo_lembrete = NULL WHERE tipo=? AND chave='a'")->execute([$TIPO]);
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true, 30), $GRP);
        t_eq(count($enviadas), 1, 'gat_alertas_tipo: lembrete vencido -> 1 envio');
        t_ok(strpos($enviadas[0][1], '⏰ *Alerta de Teste — ainda pendente') === 0, 'gat_alertas_tipo: mensagem de lembrete');
        t_ok($pdo->query("SELECT ultimo_lembrete FROM portal_alertas_ocorrencias WHERE tipo='$TIPO' AND chave='a'")->fetchColumn() !== null,
             'gat_alertas_tipo: ultimo_lembrete atualizado');

        // -- lembrete_min=0: nunca manda lembrete --
        $pdo->prepare("UPDATE portal_alertas_ocorrencias SET primeiro_visto = NOW() - INTERVAL 40 MINUTE, ultimo_lembrete = NULL WHERE tipo=? AND chave='a'")->execute([$TIPO]);
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true, 0), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: lembrete_min=0 -> 0 lembretes');

        // -- notif off: grava/apaga mas NÃO envia --
        $limpa();
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('c')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(false), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: notif off -> 0 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: notif off ainda mantém a tabela em dia');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_historico WHERE tipo='$TIPO' AND evento='nova'")->fetchColumn(), 1,
             'gat_alertas_tipo: notif off ainda registra no histórico');

        // -- envio FALHA: estado NÃO muda --
        $limpa();
        $GLOBALS['__wpp_fake_send'] = fn($d, $t) => ['ok' => false, 'erro' => 'simulado'];
        $GLOBALS['__fake_ocorr'] = [$oc('d')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 0,
             'gat_alertas_tipo: envio falhou -> nada gravado (re-tenta depois)');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_historico WHERE tipo='$TIPO'")->fetchColumn(), 0,
             'gat_alertas_tipo: envio falhou -> histórico também não grava');

        // -- horario de silencio: NOVA fora da janela permitida -> nao grava, nao envia (igual envio falhar) --
        $limpa();
        $GLOBALS['__wpp_fake_send'] = function ($d, $t) use (&$enviadas) { $enviadas[] = [$d, $t]; return ['ok' => true]; };
        $agora = time();
        // janela que NAO inclui agora (comeca daqui 2h, termina daqui 3h)
        alertas_horario_salvar($pdo, $TIPO, date('H:i:s', $agora + 2 * 3600), date('H:i:s', $agora + 3 * 3600));
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('e')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: fora do horario permitido -> 0 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 0,
             'gat_alertas_tipo: fora do horario -> nada gravado (retenta quando a janela abrir)');

        // -- mesma ocorrencia, agora DENTRO da janela permitida -> notifica normalmente (o que ficou "preso" sai na hora) --
        alertas_horario_salvar($pdo, $TIPO, date('H:i:s', $agora - 3600), date('H:i:s', $agora + 3600));
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 1, 'gat_alertas_tipo: janela abriu -> a ocorrencia que ficou presa notifica na hora');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: agora sim gravada');

        // -- LEMBRETE tambem respeita o horario: mesmo vencido, fora da janela nao reenvia --
        $pdo->prepare("UPDATE portal_alertas_ocorrencias SET primeiro_visto = NOW() - INTERVAL 40 MINUTE, ultimo_lembrete = NULL WHERE tipo=? AND chave='e'")->execute([$TIPO]);
        alertas_horario_salvar($pdo, $TIPO, date('H:i:s', $agora + 2 * 3600), date('H:i:s', $agora + 3 * 3600));
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true, 30), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: lembrete vencido mas fora do horario -> nao reenvia');

        // -- notif_whatsapp=0 ignora o horario (continua so gravando, nunca manda) --
        alertas_horario_remover($pdo, $TIPO);
        $limpa();
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('f')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(false), $GRP);
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: notif off ainda grava mesmo com horario configuravel disponivel (nao afeta esse caminho)');

        // -- grupo vazio: equivale a notif off --
        $limpa();
        $enviadas = [];
        $GLOBALS['__wpp_fake_send'] = function ($d, $t) use (&$enviadas) { $enviadas[] = 1; return ['ok' => true]; };
        $GLOBALS['__fake_ocorr'] = [$oc('e')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), '');
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: grupo vazio -> 0 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: grupo vazio ainda popula a tabela');
    } finally {
        unset($GLOBALS['__wpp_fake_send'], $GLOBALS['__fake_ocorr']);
        $limpa();
    }
} else {
    echo "  -- gat_alertas_tipo(): banco indisponível, testes pulados\n";
}

// ---------------------------------------------------------------------------
// gat_atribuido() — DM agendada vira 'cancelado' se a atribuição não vale mais
// (precisa de banco: roda no deploy contra glpi2)
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $oldOn    = wpp_cfg_get('on_atribuido');
    $oldDelay = wpp_cfg_get('cfg_delay_dm_min');

    // acha um chamado ABERTO com técnico (type=2) atribuído
    $tk = $pdo->query("
        SELECT tu.tickets_id AS tid, tu.users_id AS uid
        FROM glpi_tickets_users tu
        JOIN glpi_tickets t ON t.id = tu.tickets_id AND t.is_deleted = 0 AND t.status IN (1,2,3,4)
        WHERE tu.type = 2
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$tk) {
        echo "  -- gat_atribuido(): nenhum chamado aberto com tecnico atribuido, teste pulado\n";
    } else {
        $tid      = (int) $tk['tid'];
        $bogusUid = 99000001;          // user que NÃO está atribuído a esse chamado
        $bogusTel = '000000000000';
        $ref      = $tid . ':' . $bogusUid;
        $refsInseridos = [];

        try {
            wpp_cfg_set('on_atribuido', '1');
            wpp_cfg_set('cfg_delay_dm_min', '5');

            // Neutraliza a PARTE A sem deixar rastro: marca como já tratadas
            // todas as atribuições correntes, guardando só os refs que ESTE
            // teste inseriu pra apagar depois.
            $chk = $pdo->prepare(
                "SELECT 1 FROM portal_wpp_notificados WHERE tipo='atribuido' AND ref_id=? AND hash='' LIMIT 1"
            );
            $insN = $pdo->prepare(
                "INSERT IGNORE INTO portal_wpp_notificados (tipo, ref_id, hash, enviado_em)
                 VALUES ('atribuido', ?, '', NOW())"
            );
            $assign = $pdo->query("
                SELECT tu.tickets_id AS tid, tu.users_id AS uid
                FROM glpi_tickets_users tu
                JOIN glpi_tickets t ON t.id = tu.tickets_id AND t.is_deleted = 0 AND t.status IN (1,2,3,4)
                WHERE tu.type = 2
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($assign as $x) {
                $r = $x['tid'] . ':' . $x['uid'];
                $chk->execute([$r]);
                if (!$chk->fetchColumn()) { $insN->execute([$r]); $refsInseridos[] = $r; }
            }

            // limpa restos de execuções anteriores deste teste
            $pdo->prepare("DELETE FROM portal_wpp_dm_agendado WHERE ticket_id = ? AND glpi_user_id = ?")
                ->execute([$tid, $bogusUid]);

            // DM agendada VENCIDA para um user que não está atribuído ao chamado
            $pdo->prepare("
                INSERT INTO portal_wpp_dm_agendado (ticket_id, glpi_user_id, telefone, enviar_em, status)
                VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'pendente')
            ")->execute([$tid, $bogusUid, $bogusTel]);

            $outAntes = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();

            gat_atribuido($pdo);

            $st = $pdo->prepare(
                "SELECT status FROM portal_wpp_dm_agendado WHERE ticket_id = ? AND glpi_user_id = ?"
            );
            $st->execute([$tid, $bogusUid]);
            t_eq(
                $st->fetchColumn(),
                'cancelado',
                'gat_atribuido: DM vencida de atribuicao inexistente vira cancelado'
            );

            $outDepois = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
            t_ok($outDepois === $outAntes, 'gat_atribuido: cancelamento nao registra envio (direcao=out inalterado)');
        } finally {
            $pdo->prepare("DELETE FROM portal_wpp_dm_agendado WHERE ticket_id = ? AND glpi_user_id = ?")
                ->execute([$tid, $bogusUid]);
            if ($refsInseridos) {
                $ph = implode(',', array_fill(0, count($refsInseridos), '?'));
                $pdo->prepare("DELETE FROM portal_wpp_notificados WHERE tipo='atribuido' AND hash='' AND ref_id IN ($ph)")
                    ->execute($refsInseridos);
            }
            wpp_cfg_set('on_atribuido', $oldOn ?? '1');
            if ($oldDelay !== null) wpp_cfg_set('cfg_delay_dm_min', $oldDelay);
            else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'cfg_delay_dm_min'");
        }
    }
} else {
    echo "  -- gat_atribuido(): banco indisponível, teste de cancelamento pulado\n";
}

// ---------------------------------------------------------------------------
// gat_novo() — watermark ausente só grava wm_novo e não envia (precisa de banco)
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    // salva estado real pra restaurar (a suíte roda contra a PROD)
    $oldWm  = wpp_cfg_get('wm_novo');
    $oldOn  = wpp_cfg_get('on_novo');
    $oldGrp = wpp_cfg_get('grupo_chamados_jid');

    try {
        wpp_cfg_set('on_novo', '1');
        wpp_cfg_set('grupo_chamados_jid', '999888777@g.us'); // qualquer coisa não-vazia
        $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_novo'");

        $outAntes = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();

        gat_novo($pdo);

        $wmDepois = wpp_cfg_get('wm_novo');
        t_ok(
            is_string($wmDepois) && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wmDepois),
            'gat_novo sem wm_novo grava o watermark (datetime do banco)'
        );

        $outDepois = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
        t_ok($outDepois === $outAntes, 'gat_novo sem wm_novo não registra nenhum envio (direcao=out inalterado)');

        // toggle desligado -> não faz nada
        wpp_cfg_set('on_novo', '0');
        $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_novo'");
        gat_novo($pdo);
        t_ok(wpp_cfg_get('wm_novo') === null, 'gat_novo com on_novo=0 não toca no watermark');
    } finally {
        // restaura estado real — mesmo se um assert lançar no meio
        if ($oldWm !== null)  wpp_cfg_set('wm_novo', $oldWm);
        else                  $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_novo'");
        wpp_cfg_set('on_novo', $oldOn ?? '1');
        wpp_cfg_set('grupo_chamados_jid', $oldGrp ?? '');
    }
} else {
    echo "  -- gat_novo(): banco indisponível, testes de watermark pulados\n";
}
