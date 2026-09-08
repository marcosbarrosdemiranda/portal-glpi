<?php
// Testes do guardrail de saída. Roda com `php wpp/tests/run.php`.
// Precisa de banco acessível (fixtures em portal_wpp_*).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';
global $pdo;

// fixtures
wpp_cfg_set('grupo_alertas_jid',  '111@g.us');
wpp_cfg_set('grupo_chamados_jid', '222@g.us');
$pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone='5567999990000'");
$pdo->exec("INSERT INTO portal_wpp_contatos (glpi_user_id,telefone,ativo) VALUES (999999,'5567999990000',1)");

t_ok(wpp_norm_telefone('+55 (67) 99999-0000') === '5567999990000', 'norm_telefone tira nao-digito');

t_ok(wpp_destino_permitido('111@g.us'),  'grupo alertas configurado: ok');
t_ok(wpp_destino_permitido('222@g.us'),  'grupo chamados configurado: ok');
t_ok(!wpp_destino_permitido('333@g.us'), 'grupo aleatorio: BLOQUEADO');
t_ok(!wpp_destino_permitido('status@broadcast'), 'status: BLOQUEADO');
t_ok(!wpp_destino_permitido('999@broadcast'),    'broadcast: BLOQUEADO');
t_ok(wpp_destino_permitido('5567999990000'),     'contato ativo: ok');
t_ok(!wpp_destino_permitido('5511000000000'),    'numero nao cadastrado: BLOQUEADO');
t_ok(!wpp_destino_permitido(''),                 'vazio: BLOQUEADO');

// evo_guarded_send bloqueia sem chamar o callable
$chamou = false;
$r = evo_guarded_send('333@g.us', function() use (&$chamou){ $chamou = true; return ['ok'=>true]; }, 'x');
t_ok(!$chamou && $r['bloqueado'] === true, 'guarded_send nao chama o callable pra destino bloqueado');

// evo_guarded_send deixa passar destino ok
$r = evo_guarded_send('111@g.us', fn() => ['ok'=>true], 'x');
t_ok($r['ok'] === true, 'guarded_send passa destino permitido');

$pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone='5567999990000'");
