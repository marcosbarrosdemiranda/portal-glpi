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
    "🆕 *Chamado #7* — Grupo Gmais > Loja 3\nPC nao liga\n_Incidente · aberto 07/09 14:30_",
    'gat_msg_novo monta a string esperada (incidente)'
);

// type 2 -> "Requisição"
$msgReq = gat_msg_novo([
    'id'            => 8,
    'name'          => 'Instalar impressora',
    'loja'          => 'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM',
    'date_creation' => '2026-09-07 09:05:00',
    'type'          => 2,
]);
t_eq(
    $msgReq,
    "🆕 *Chamado #8* — Lj 003\nInstalar impressora\n_Requisição · aberto 07/09 09:05_",
    'gat_msg_novo: type=2 vira Requisição e aplica apelido_entidade'
);

// loja vazia -> "sem loja"; título ausente -> "(sem título)"
$msgSemLoja = gat_msg_novo(['id' => 9, 'date_creation' => '2026-09-07 00:00:00']);
t_eq(
    $msgSemLoja,
    "🆕 *Chamado #9* — sem loja\n(sem título)\n_Incidente · aberto 07/09 00:00_",
    'gat_msg_novo: loja/título ausentes usam os fallbacks'
);

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
// alertas_novos() — diff de snapshots (puro, sem banco)
// ---------------------------------------------------------------------------
$anteriorSnap = [
    'sem_inventario' => [['name' => 'PC-A'], ['name' => 'PC-B']],
    'disco_cheio'    => [['name' => 'PC-X', 'volume' => 'C:']],
];
$atualSnap = [
    'sem_inventario' => [['name' => 'PC-B'], ['name' => 'PC-C']],   // PC-C novo, PC-A saiu
    'disco_cheio'    => [
        ['name' => 'PC-X', 'volume' => 'C:'],   // já existia
        ['name' => 'PC-X', 'volume' => 'D:'],   // novo (mesma máquina, outro volume)
    ],
];
$diff = alertas_novos($atualSnap, $anteriorSnap);
t_eq(array_column($diff['inv'], 'name'), ['PC-C'], 'alertas_novos: só PC-C é novo no inventário');
t_eq(count($diff['disco']), 1, 'alertas_novos: só um volume novo em disco');
t_eq($diff['disco'][0]['volume'], 'D:', 'alertas_novos: o volume novo é o D: da mesma máquina');

// anterior malformado -> tudo é novo
$diffVazio = alertas_novos($atualSnap, ['lixo' => 1]);
t_eq(count($diffVazio['inv']), 2, 'alertas_novos: anterior malformado -> todo inventário é novo');
t_eq(count($diffVazio['disco']), 2, 'alertas_novos: anterior malformado -> todo disco é novo');

// nada mudou -> nada novo
$diffIgual = alertas_novos($anteriorSnap, $anteriorSnap);
t_ok(!$diffIgual['inv'] && !$diffIgual['disco'], 'alertas_novos: snapshots iguais -> nada novo');

// ---------------------------------------------------------------------------
// gat_msg_digest() — montagem da mensagem (puro, sem banco)
// ---------------------------------------------------------------------------
$atualDigest = [
    'sem_inventario' => [
        ['name' => 'PC-CAIXA-01', 'loja' => 'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM'],
        ['name' => 'PC-RET-02',   'loja' => 'Loja sem apelido'],
        ['name' => 'PC-RET-03',   'loja' => ''],
    ],
    'disco_cheio' => [
        ['name' => 'SRV-01', 'loja' => 'Entidade raiz > Grupo Gmais > Supermercado Santos - BTO', 'volume' => 'D:', 'pct' => 95],
    ],
];
$msgDigest = gat_msg_digest(
    $atualDigest,
    [$atualDigest['sem_inventario'][0], $atualDigest['sem_inventario'][2]],
    $atualDigest['disco_cheio']
);
t_eq(
    $msgDigest,
    "🔔 *Alertas do parque*\n\n"
    . "📉 Sem inventário +7d: 3 (novos: PC-CAIXA-01 (Lj 003), PC-RET-03)\n"
    . "💾 Disco cheio: 1 (novos: SRV-01 (Lj 001) D: 95%)",
    'gat_msg_digest: totais + listas de novos com apelido_entidade na loja'
);

// sem novos em nenhuma categoria -> só os totais
t_eq(
    gat_msg_digest($atualDigest, [], []),
    "🔔 *Alertas do parque*\n\n📉 Sem inventário +7d: 3\n💾 Disco cheio: 1",
    'gat_msg_digest: sem novos -> apenas os totais'
);

// truncamento em 5 com "…+N"
$muitos = [];
for ($i = 1; $i <= 7; $i++) $muitos[] = ['name' => "PC-{$i}", 'loja' => ''];
$msgTrunc = gat_msg_digest(['sem_inventario' => $muitos, 'disco_cheio' => []], $muitos, []);
t_eq(
    $msgTrunc,
    "🔔 *Alertas do parque*\n\n"
    . "📉 Sem inventário +7d: 7 (novos: PC-1, PC-2, PC-3, PC-4, PC-5 …+2)\n"
    . "💾 Disco cheio: 0",
    'gat_msg_digest: lista de novos trunca em 5 com "…+2"'
);

// ---------------------------------------------------------------------------
// gat_alertas() — throttle e branch "nada novo" (precisa de banco)
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $oldOn    = wpp_cfg_get('on_alertas');
    $oldGrp   = wpp_cfg_get('grupo_alertas_jid');
    $oldInt   = wpp_cfg_get('cfg_digest_alertas_min');
    $oldSnap  = wpp_cfg_get('wpp_snap_alertas');
    $oldWm    = wpp_cfg_get('wm_alertas_digest');

    try {
        // toggle desligado -> não toca em nada
        wpp_cfg_set('on_alertas', '0');
        wpp_cfg_set('grupo_alertas_jid', '999888777@g.us');
        $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_alertas_digest'");
        $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wpp_snap_alertas'");
        gat_alertas($pdo);
        t_ok(
            wpp_cfg_get('wpp_snap_alertas') === null && wpp_cfg_get('wm_alertas_digest') === null,
            'gat_alertas: on_alertas=0 não grava snapshot nem watermark'
        );

        // grupo vazio -> sai sem tocar no snapshot
        wpp_cfg_set('on_alertas', '1');
        wpp_cfg_set('grupo_alertas_jid', '');
        gat_alertas($pdo);
        t_ok(
            wpp_cfg_get('wpp_snap_alertas') === null && wpp_cfg_get('wm_alertas_digest') === null,
            'gat_alertas: grupo vazio não grava snapshot nem watermark'
        );

        // throttle: watermark recente -> nem chega a montar snapshot
        wpp_cfg_set('grupo_alertas_jid', '999888777@g.us');
        wpp_cfg_set('cfg_digest_alertas_min', '15');
        wpp_cfg_set('wm_alertas_digest', (string) $pdo->query("SELECT NOW()")->fetchColumn());
        $outAntes = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
        gat_alertas($pdo);
        $outDepois = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
        t_ok($outDepois === $outAntes, 'gat_alertas: dentro do intervalo não envia nada');
        t_ok(wpp_cfg_get('wpp_snap_alertas') === null, 'gat_alertas: throttle não grava snapshot');

        // branch "nada novo": snapshot == estado atual, sem watermark ->
        // atualiza snapshot mas não envia nem grava watermark
        $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_alertas_digest'");
        wpp_cfg_set('wpp_snap_alertas', json_encode(alertas_snapshot($pdo)));
        $outAntes = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
        gat_alertas($pdo);
        $outDepois = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'")->fetchColumn();
        t_ok($outDepois === $outAntes, 'gat_alertas: nada novo -> não envia');
        t_ok(wpp_cfg_get('wm_alertas_digest') === null, 'gat_alertas: nada novo -> não grava watermark');
        t_ok(wpp_cfg_get('wpp_snap_alertas') !== null, 'gat_alertas: nada novo -> atualiza o snapshot');
    } finally {
        if ($oldOn   !== null) wpp_cfg_set('on_alertas', $oldOn);           else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'on_alertas'");
        if ($oldGrp  !== null) wpp_cfg_set('grupo_alertas_jid', $oldGrp);   else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'grupo_alertas_jid'");
        if ($oldInt  !== null) wpp_cfg_set('cfg_digest_alertas_min', $oldInt); else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'cfg_digest_alertas_min'");
        if ($oldSnap !== null) wpp_cfg_set('wpp_snap_alertas', $oldSnap);   else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wpp_snap_alertas'");
        if ($oldWm   !== null) wpp_cfg_set('wm_alertas_digest', $oldWm);    else $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_alertas_digest'");
    }
} else {
    echo "  -- gat_alertas(): banco indisponível, testes de throttle pulados\n";
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
