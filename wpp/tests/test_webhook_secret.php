<?php
// Testes da autenticação do webhook: segredo compartilhado + instância.
// Funções puras (só leem constantes), sem banco e sem rede.
require_once __DIR__ . '/../webhook_parse.php';

// --- segredo compartilhado ---------------------------------------------

if (defined('WPP_WEBHOOK_SECRET') && WPP_WEBHOOK_SECRET === '') {
    // config.php real com a constante vazia: checagem pulada de propósito
    t_ok(wpp_webhook_secret_ok(null) === true, 'WPP_WEBHOOK_SECRET vazia: checagem pulada (gap aceito no rollout)');
} else {
    if (!defined('WPP_WEBHOOK_SECRET')) {
        // ambiente sem wpp/config.php (dev/CI): dá pra exercitar o caminho
        // "operador ainda nao configurou o segredo" antes de definir a constante
        t_ok(wpp_webhook_secret_ok(null) === true, 'sem WPP_WEBHOOK_SECRET: request sem header passa (gap aceito no rollout)');
        t_ok(wpp_webhook_secret_ok('qualquer-coisa') === true, 'sem WPP_WEBHOOK_SECRET: header e ignorado');
        define('WPP_WEBHOOK_SECRET', 'segredo-de-teste-' . bin2hex(random_bytes(6)));
    } else {
        // servidor real: a constante ja veio do wpp/config.php. Nao se toca
        // nesse arquivo; os asserts abaixo usam o proprio valor configurado.
        t_ok(true, 'WPP_WEBHOOK_SECRET ja definida neste ambiente — caminho "sem segredo" nao testavel aqui');
    }

    t_ok(wpp_webhook_secret_ok(WPP_WEBHOOK_SECRET) === true, 'segredo correto no header: passa');
    t_ok(wpp_webhook_secret_ok(WPP_WEBHOOK_SECRET . 'x') === false, 'segredo errado: BLOQUEADO');
    t_ok(wpp_webhook_secret_ok(null) === false, 'header ausente com segredo configurado: BLOQUEADO');
    t_ok(wpp_webhook_secret_ok('') === false, 'header vazio: BLOQUEADO');
    t_ok(wpp_webhook_secret_ok(strtoupper(WPP_WEBHOOK_SECRET) . '!') === false, 'segredo parecido mas diferente: BLOQUEADO');
}

// --- instância (defesa em profundidade) ---------------------------------

t_ok(wpp_evento_da_instancia([]) === true, 'payload sem campo instance: nao bloqueia (versoes antigas da Evolution)');
t_ok(wpp_evento_da_instancia(['instance' => '']) === true, 'instance vazia: nao bloqueia');
t_ok(wpp_evento_da_instancia(['instance' => ['nao', 'string']]) === true, 'instance nao-string: nao bloqueia');

if (!defined('EVO_INSTANCE')) {
    define('EVO_INSTANCE', 'portal_ti_teste');
}
t_ok(wpp_evento_da_instancia(['instance' => EVO_INSTANCE]) === true, 'instance igual a EVO_INSTANCE: passa');
t_ok(wpp_evento_da_instancia(['instance' => EVO_INSTANCE . '_outra']) === false, 'instance de outra app: BLOQUEADA');
t_ok(wpp_evento_da_instancia(['instance' => 'checklist_gmais']) === false, 'instance de app vizinha na mesma Evolution: BLOQUEADA');
