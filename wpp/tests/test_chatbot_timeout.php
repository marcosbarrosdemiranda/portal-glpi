<?php
// Testes do sweep de timeout de conversas. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
global $pdo;

// Fake instalado em $GLOBALS (seam wpp_chatbot_enviar de wpp/chatbot.php),
// nunca redeclarando evo_send_text() — run.php carrega todos os test_*.php
// num processo só e outros arquivos requerem a função de verdade.
$GLOBALS['__wpp_fake_send'] = [];
$GLOBALS['__wpp_chatbot_enviar_fake'] = function (string $destino, string $texto): array {
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto];
    return ['ok' => true];
};

// Só dígitos, curto (cabe em VARCHAR(20)) — mesmo precedente das Tasks 1/3/5.
$telVelha   = '30' . random_int(100000, 999999);  // > 30 min: apaga calado
$telMedia   = '31' . random_int(100000, 999999);  // entre timeout e 30min, passo engajado: avisa e apaga
$telViva    = '32' . random_int(100000, 999999);  // recente: fica
$telMenu    = '36' . random_int(100000, 999999);  // parado no menu: apaga SEM avisar (nunca pediu nada)
$telIndisp  = '37' . random_int(100000, 999999);  // passo transitorio 'indisponivel' (nao engajado): apaga SEM avisar

$cfgTimeoutOriginal = wpp_cfg_get('chatbot_timeout_min');
$cfgChatbotOriginal = wpp_cfg_get('on_chatbot');

try {
    wpp_cfg_set('chatbot_timeout_min', '5');
    wpp_cfg_set('on_chatbot', '1'); // aviso de timeout é gateado por isso

    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW() - INTERVAL 40 MINUTE)")->execute([$telVelha]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{\"passo\":\"titulo\"}', NOW() - INTERVAL 10 MINUTE)")->execute([$telMedia]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW())")->execute([$telViva]);
    // Etapa 3: quem ainda esta no passo 'menu' nunca respondeu nada de
    // verdade (so recebeu o menu inicial) - avisar "tempo esgotado" seria
    // mensagem nao solicitada. Mesma janela de tempo do $telMedia (que avisa).
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{\"passo\":\"menu\"}', NOW() - INTERVAL 10 MINUTE)")->execute([$telMenu]);
    // Regressao: a excecao do aviso e ALLOWLIST, nao blacklist de 'menu'.
    // 'indisponivel' e um passo transitorio da Etapa 2 (gravado logo antes de
    // um envio, limpo logo depois); se o envio lancar, a linha sobrevive com
    // esse passo e SEM vinculo nenhum - avisar ai seria exatamente a mensagem
    // nao solicitada que esse mecanismo existe pra evitar.
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{\"passo\":\"indisponivel\"}', NOW() - INTERVAL 10 MINUTE)")->execute([$telIndisp]);

    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_sweep_timeouts();

    t_ok(wpp_chatbot_estado_get($telVelha) === null, '> 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telMedia) === null, 'entre timeout e 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telViva) !== null, 'recente: continua');
    t_ok(wpp_chatbot_estado_get($telMenu) === null, 'parado no menu: tambem apagada');
    t_ok(wpp_chatbot_estado_get($telIndisp) === null, 'passo indisponivel: tambem apagada');

    $destinos = array_column($GLOBALS['__wpp_fake_send'], 'destino');
    t_ok(!in_array($telVelha, $destinos, true), '> 30min: NAO avisa (silencioso)');
    t_ok(in_array($telMedia, $destinos, true), 'entre timeout e 30min, passo engajado: avisa');
    t_ok(!in_array($telViva, $destinos, true), 'recente: nao recebe aviso nenhum');
    t_ok(!in_array($telMenu, $destinos, true), 'parado no menu: NAO avisa (nunca pediu nada de verdade)');
    t_ok(!in_array($telIndisp, $destinos, true), 'passo nao-engajado (indisponivel): NAO avisa (allowlist, nao blacklist de menu)');

    // --- rollback: com on_chatbot=0 nao avisa ninguem, mas ainda limpa ---
    wpp_cfg_set('on_chatbot', '0');
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{\"passo\":\"titulo\"}', NOW() - INTERVAL 10 MINUTE)")->execute([$telMedia]);
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_sweep_timeouts();
    t_eq(count($GLOBALS['__wpp_fake_send']), 0, 'on_chatbot=0: nenhum aviso de timeout e enviado');
    t_ok(wpp_chatbot_estado_get($telMedia) === null, 'on_chatbot=0: conversa velha ainda e limpa (silenciosa)');

    // --- config degenerada nao pode derrubar conversa recem-criada ---
    wpp_cfg_set('on_chatbot', '1');
    wpp_cfg_set('chatbot_timeout_min', '0'); // clampado pra 1
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_sweep_timeouts();
    t_ok(wpp_chatbot_estado_get($telViva) !== null, 'timeout_min=0 (clamp): conversa recente sobrevive');
    t_eq(count($GLOBALS['__wpp_fake_send']), 0, 'timeout_min=0 (clamp): ninguem e avisado a toa');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone IN (" . implode(',', array_map([$pdo, 'quote'], [$telVelha, $telMedia, $telViva, $telMenu, $telIndisp])) . ")");
    if ($cfgTimeoutOriginal !== null) { wpp_cfg_set('chatbot_timeout_min', $cfgTimeoutOriginal); }
    if ($cfgChatbotOriginal !== null) { wpp_cfg_set('on_chatbot', $cfgChatbotOriginal); }
    $GLOBALS['__wpp_chatbot_enviar_fake'] = null;
}
