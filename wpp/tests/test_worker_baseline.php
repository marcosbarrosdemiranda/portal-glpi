<?php
// Testa wpp_semear_baseline(): marca o estado atual como "já notificado"
// SEM ENVIAR nada, e grava snapshot de alertas + watermark de chamados novos.
// Requer banco (glpi2) — roda no deploy via `docker exec glpi-web php .../wpp/tests/run.php`.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../worker.php';   // require-safe: NÃO roda passada ao ser incluído
global $pdo;

// worker.php foi incluído (não invocado) — a passada não pode ter rodado sozinha.
t_ok(function_exists('wpp_worker_passada'), 'worker.php expõe wpp_worker_passada (require-safe)');
t_ok(function_exists('wpp_semear_baseline'), 'worker.php expõe wpp_semear_baseline');

// Estado limpo pros tipos que a baseline semeia.
$pdo->exec("DELETE FROM portal_wpp_notificados WHERE tipo IN ('novo','sla','atribuido')");
wpp_cfg_set('wpp_baseline_ok', '');

wpp_semear_baseline($pdo);

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

t_ok(wpp_cfg_get('wpp_snap_alertas') !== null, 'baseline gravou snapshot de alertas (wpp_snap_alertas)');
$snap = json_decode((string) wpp_cfg_get('wpp_snap_alertas'), true);
t_ok(is_array($snap) && array_key_exists('sem_inventario', $snap) && array_key_exists('disco_cheio', $snap),
    'snapshot de alertas tem as duas chaves esperadas');

t_ok((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) wpp_cfg_get('wm_novo')),
    'baseline gravou watermark wm_novo (datetime do banco)');

// Idempotência: rodar 2x não quebra nem duplica.
wpp_semear_baseline($pdo);
$nNovo2 = (int) $pdo->query(
    "SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='novo'"
)->fetchColumn();
t_ok($nNovo2 === $abertos, 'baseline é idempotente (rodar 2x não duplica)');
