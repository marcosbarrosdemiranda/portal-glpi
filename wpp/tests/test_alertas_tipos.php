<?php
// Testa alertas_tipos.php — catálogo, criação da tabela, config por tipo e checks/renders.
// Precisa de banco → roda no deploy (php wpp/tests/run.php).
require_once __DIR__ . '/../../alertas_tipos.php';
global $pdo;

// catálogo
$cat = alertas_catalogo();
t_ok(isset($cat['sem_inventario'], $cat['disco_cheio']), 'catálogo tem os 2 tipos do GLPI');
t_ok(is_callable($cat['sem_inventario']['check']) && is_callable($cat['sem_inventario']['render']),
     'sem_inventario tem check e render chamáveis');

// tabela criada
t_ok((bool) $pdo->query("SHOW TABLES LIKE 'portal_alertas_config'")->fetch(), 'portal_alertas_config existe');

// config sem linha = defaults do catálogo
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");
$c = alertas_config_do_tipo($pdo, 'sem_inventario');
t_eq($c['ativo'], true, 'sem linha: ativo=true');
t_eq($c['params']['dias'], 7, 'sem linha: params.dias = default 7');
t_eq($c['lembrete_min'], 0, 'sem linha: lembrete_min = 0');

// config com linha = mescla + clamp
$pdo->prepare("INSERT INTO portal_alertas_config (tipo,ativo,params,notif_whatsapp,lembrete_min)
               VALUES ('sem_inventario',0,'{\"dias\":999}',0,120)")->execute();
$c = alertas_config_do_tipo($pdo, 'sem_inventario');
t_eq($c['ativo'], false, 'com linha: ativo=false');
t_eq($c['params']['dias'], 90, 'com linha: params.dias clampado no max 90');
t_eq($c['notif_whatsapp'], false, 'com linha: notif_whatsapp=false');
t_eq($c['lembrete_min'], 120, 'com linha: lembrete_min=120');
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");

// params não-objeto -> defaults (MariaDB valida json_valid na coluna JSON, então usamos JSON escalar válido)
$pdo->prepare("INSERT INTO portal_alertas_config (tipo,params) VALUES ('disco_cheio','123')")->execute();
$c = alertas_config_do_tipo($pdo, 'disco_cheio');
t_eq($c['params']['pct'], 90, 'params não-objeto cai no default');
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'disco_cheio'");

// checks retornam array de ocorrências com as chaves obrigatórias
foreach (['sem_inventario', 'disco_cheio'] as $tipo) {
    $oc = call_user_func($cat[$tipo]['check'], $pdo, alertas_config_do_tipo($pdo, $tipo)['params']);
    t_ok(is_array($oc), "$tipo check retorna array");
    foreach ($oc as $o) {
        t_ok(isset($o['chave'], $o['titulo']), "$tipo ocorrência tem chave e titulo");
        break; // basta a primeira
    }
    // render de lista vazia -> mensagem "ok"
    t_ok(strpos(call_user_func($cat[$tipo]['render'], []), 'vazio') !== false, "$tipo render([]) tem a msg vazia");
    // render com dados -> string não vazia
    if ($oc) t_ok(strlen(call_user_func($cat[$tipo]['render'], $oc)) > 20, "$tipo render(ocorr) devolve HTML");
}
