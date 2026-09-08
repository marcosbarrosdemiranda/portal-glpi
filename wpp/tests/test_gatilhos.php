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
// gat_novo() — watermark ausente só grava wm_novo e não envia (precisa de banco)
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    // salva estado real pra restaurar (a suíte roda contra a PROD)
    $oldWm  = wpp_cfg_get('wm_novo');
    $oldOn  = wpp_cfg_get('on_novo');
    $oldGrp = wpp_cfg_get('grupo_chamados_jid');

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

    // restaura estado real
    if ($oldWm !== null)  wpp_cfg_set('wm_novo', $oldWm);
    else                  $pdo->exec("DELETE FROM portal_wpp_config WHERE chave = 'wm_novo'");
    wpp_cfg_set('on_novo', $oldOn ?? '1');
    wpp_cfg_set('grupo_chamados_jid', $oldGrp ?? '');
} else {
    echo "  -- gat_novo(): banco indisponível, testes de watermark pulados\n";
}
