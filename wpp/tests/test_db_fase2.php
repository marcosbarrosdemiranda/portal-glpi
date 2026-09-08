<?php
require_once __DIR__ . '/../db.php';   // cria as tabelas
global $pdo;

// as 5 tabelas existem
foreach (['portal_wpp_contatos','portal_wpp_autorizados','portal_wpp_notificados','portal_wpp_dm_agendado','portal_wpp_log'] as $t) {
    $ok = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
    t_ok((bool)$ok, "tabela $t existe");
}

// wpp_agora_db retorna string de data
$agora = wpp_agora_db($pdo);
t_ok((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $agora), 'wpp_agora_db retorna datetime');

// wpp_marcar_notificado + wpp_ja_notificado
wpp_marcar_notificado('novo', '99999');
t_ok(wpp_ja_notificado('novo', '99999'), 'marcar/ja_notificado funciona');
t_ok(!wpp_ja_notificado('novo', '88888'), 'nao notificado retorna false');
wpp_marcar_notificado('novo', '99999'); // 2x nao quebra (INSERT IGNORE)
t_ok(true, 'marcar 2x nao lanca');

// wpp_log nunca lanca
wpp_log('out', 'x@g.us', 'teste', 'ok');
t_ok(true, 'wpp_log nao lanca');

// limpeza
$pdo->exec("DELETE FROM portal_wpp_notificados WHERE ref_id IN ('99999','88888')");
$pdo->exec("DELETE FROM portal_wpp_log WHERE resumo='teste'");
