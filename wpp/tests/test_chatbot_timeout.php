<?php
// Testes do sweep de timeout de conversas. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
global $pdo;

$GLOBALS['__wpp_fake_send'] = [];
function evo_send_text(string $destino, string $texto): array {
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto];
    return ['ok' => true];
}

// Só dígitos, curto (cabe em VARCHAR(20)) — mesmo precedente das Tasks 1/3/5.
$telVelha   = '30' . random_int(100000, 999999);  // > 30 min: apaga calado
$telMedia   = '31' . random_int(100000, 999999);  // entre timeout e 30min: avisa e apaga
$telViva    = '32' . random_int(100000, 999999);  // recente: fica

try {
    wpp_cfg_set('chatbot_timeout_min', '5');

    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW() - INTERVAL 40 MINUTE)")->execute([$telVelha]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW() - INTERVAL 10 MINUTE)")->execute([$telMedia]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW())")->execute([$telViva]);

    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_sweep_timeouts();

    t_ok(wpp_chatbot_estado_get($telVelha) === null, '> 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telMedia) === null, 'entre timeout e 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telViva) !== null, 'recente: continua');

    $destinos = array_column($GLOBALS['__wpp_fake_send'], 'destino');
    t_ok(!in_array($telVelha, $destinos, true), '> 30min: NAO avisa (silencioso)');
    t_ok(in_array($telMedia, $destinos, true), 'entre timeout e 30min: avisa');
    t_ok(!in_array($telViva, $destinos, true), 'recente: nao recebe aviso nenhum');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone IN (" . implode(',', array_map([$pdo, 'quote'], [$telVelha, $telMedia, $telViva])) . ")");
}
