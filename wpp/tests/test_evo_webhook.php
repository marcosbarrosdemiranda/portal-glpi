<?php
// Teste do guard de evo_set_webhook (não faz chamada de rede real — só
// confirma que falha limpo sem WPP_WEBHOOK_URL configurada).
require_once __DIR__ . '/../evo_api.php';

if (!defined('WPP_WEBHOOK_URL') || WPP_WEBHOOK_URL === '') {
    $r = evo_set_webhook(true);
    t_ok($r['ok'] === false, 'sem WPP_WEBHOOK_URL: evo_set_webhook(true) falha limpo');
    t_ok(!empty($r['erro']), 'sem WPP_WEBHOOK_URL: erro explica o motivo');
} else {
    // ambiente já tem a constante (ex: servidor de produção) — pula, sem rede real no teste
    t_ok(true, 'WPP_WEBHOOK_URL configurada neste ambiente — guard não testável aqui, pulado');
}
