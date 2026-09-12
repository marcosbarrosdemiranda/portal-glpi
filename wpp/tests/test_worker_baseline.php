<?php
// Testa wpp_semear_baseline(): marca o estado atual como "já notificado"
// SEM ENVIAR nada, popula portal_alertas_ocorrencias + watermark de chamados novos.
// Requer banco (glpi2) — roda no deploy via `docker exec glpi-web php .../wpp/tests/run.php`.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../worker.php';   // require-safe: NÃO roda passada ao ser incluído
global $pdo;

// GUARDA: este teste é DESTRUTIVO — apaga/re-semeia portal_wpp_notificados.
// Se um ciclo do worker cair nessa janela, gat_sla/gat_atribuido (sem watermark,
// só dedup) disparam o backlog inteiro nos grupos + DM pra todo técnico.
// Só roda com WPP_TEST_DESTRUTIVO=1 E com o worker PARADO (ver wpp/README.md).
if (getenv('WPP_TEST_DESTRUTIVO') !== '1') {
    echo "  -- test_worker_baseline pulado (defina WPP_TEST_DESTRUTIVO=1, com o worker PARADO)\n";
    return;
}

// worker.php foi incluído (não invocado) — a passada não pode ter rodado sozinha.
t_ok(function_exists('wpp_worker_passada'), 'worker.php expõe wpp_worker_passada (require-safe)');
t_ok(function_exists('wpp_semear_baseline'), 'worker.php expõe wpp_semear_baseline');

// Estado limpo pros tipos que a baseline semeia.
$pdo->exec("DELETE FROM portal_wpp_notificados WHERE tipo IN ('novo','sla','atribuido')");
wpp_cfg_set('wpp_baseline_ok', '');

// "Baseline nao manda nada": nenhuma linha de saida (direcao='out') pode
// surgir enquanto a baseline e semeada.
$outAntes = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'"
)->fetchColumn();

wpp_semear_baseline($pdo);

$outDepois = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_log WHERE direcao='out'"
)->fetchColumn();
t_ok($outDepois === $outAntes, 'baseline nao registrou nenhum envio (direcao=out inalterado)');

$abertos = (int) $pdo->query(
    "SELECT COUNT(*) FROM glpi_tickets WHERE is_deleted=0 AND status IN (1,2,3,4)"
)->fetchColumn();

$nNovo = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='novo'"
)->fetchColumn();
t_ok($nNovo === $abertos, "baseline marcou os $abertos chamados abertos como 'novo' notificado");

$nPrevenc = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='sla' AND hash='prevenc'"
)->fetchColumn();
t_ok($nPrevenc === $abertos, "baseline marcou os $abertos chamados abertos como 'sla/prevenc'");

$atribAtuais = (int) $pdo->query(
    "SELECT COUNT(*) FROM glpi_tickets_users tu
     JOIN glpi_tickets t ON t.id = tu.tickets_id AND t.is_deleted=0 AND t.status IN (1,2,3,4)
     WHERE tu.type = 2"
)->fetchColumn();
$nAtrib = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='atribuido'"
)->fetchColumn();
t_ok($nAtrib === $atribAtuais, "baseline marcou as $atribAtuais atribuições atuais");

// baseline popula portal_alertas_ocorrencias pros tipos do catálogo, sem enviar.
require_once __DIR__ . '/../../alertas_tipos.php';
$ocInv = 0;
foreach (alertas_catalogo() as $slug => $def) {
    try { $ocInv += count(call_user_func($def['check'], $pdo, alertas_config_do_tipo($pdo, $slug)['params'])); }
    catch (\Throwable $e) {}
}
$ocTab = (int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias")->fetchColumn();
t_ok($ocTab >= $ocInv && $ocInv >= 0, "baseline semeou portal_alertas_ocorrencias ($ocTab linhas, esperado >= $ocInv do catálogo agora)");

t_ok((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) wpp_cfg_get('wm_novo')),
    'baseline gravou watermark wm_novo (datetime do banco)');

// Idempotência: rodar 2x não quebra nem duplica.
wpp_semear_baseline($pdo);
$nNovo2 = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='novo'"
)->fetchColumn();
t_ok($nNovo2 === $abertos, 'baseline é idempotente (rodar 2x não duplica)');
