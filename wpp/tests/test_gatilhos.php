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
