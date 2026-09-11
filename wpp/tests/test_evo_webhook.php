<?php
// Teste do guard de evo_set_webhook. NUNCA faz chamada de rede real: chamar
// evo_set_webhook(false) de verdade desligaria o webhook num servidor de
// producao, entao o caminho "desligar" e verificado pela fonte, nao chamado.

// --- assertivas independentes de ambiente (sem require, sem rede) --------
// Nao dependem de wpp/config.php existir: leem o proprio fonte do evo_api.php.
$__fonte_evo = (string) file_get_contents(__DIR__ . '/../evo_api.php');
if (preg_match('/function evo_set_webhook.*?\n\}/s', $__fonte_evo, $__m)) {
    t_ok((bool) preg_match('/if\s*\(\s*\$ligar\s*&&/', $__m[0]),
        'guard de WPP_WEBHOOK_URL so dispara com $ligar=true (desligar nunca depende da constante)');
    t_ok(strpos($__m[0], 'X-Wpp-Secret') !== false,
        'evo_set_webhook registra o header X-Wpp-Secret na Evolution');
} else {
    t_ok(false, 'evo_set_webhook nao encontrada em wpp/evo_api.php');
}

// --- guard em si (precisa carregar evo_api.php) --------------------------
// wpp/config.php ausente: evo_api.php faz http_response_code(500)+exit() no
// proprio topo, o que mataria o processo INTEIRO do run.php sem falha
// nenhuma sinalizada (exit 0, testes seguintes nunca rodam). Por isso a
// checagem vem ANTES do require.
if (!file_exists(__DIR__ . '/../config.php')) {
    t_ok(true, 'wpp/config.php ausente neste ambiente — evo_api.php nao pode ser carregado (exit no topo dele); chamada do guard pulada');
    return;
}

require_once __DIR__ . '/../evo_api.php';

if (!defined('WPP_WEBHOOK_URL') || WPP_WEBHOOK_URL === '') {
    // config.php existe mas esta desatualizado: o guard tem que fechar limpo,
    // sem tentar rede
    $r = evo_set_webhook(true);
    t_ok($r['ok'] === false, 'sem WPP_WEBHOOK_URL: evo_set_webhook(true) falha limpo');
    t_ok(!empty($r['erro']), 'sem WPP_WEBHOOK_URL: erro explica o motivo');
} else {
    // Ambiente com a constante ja definida (servidor real). Constante de PHP
    // nao se redefine e o wpp/config.php real nao pode ser tocado por teste,
    // entao esse branch fica registrado como nao exercitavel aqui — as
    // assertivas de fonte acima e que cobrem a forma do guard.
    t_ok(true, 'WPP_WEBHOOK_URL configurada neste ambiente — chamada com a constante ausente nao testavel aqui');
}
