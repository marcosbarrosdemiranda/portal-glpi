<?php
// Testes do guardrail de saída. Roda com `php wpp/tests/run.php`.
// Precisa de banco acessível (fixtures em portal_wpp_*).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';
global $pdo;

// --- salva config real pra restaurar no fim (a suíte roda contra a PROD) ---
$oldA = wpp_cfg_get('grupo_alertas_jid');
$oldC = wpp_cfg_get('grupo_chamados_jid');

// Tudo que muta a PROD roda dentro de try/finally: um throw no meio (ex.: o
// INSERT batendo numa linha UNIQUE remanescente) não pode deixar os 2 JIDs de
// grupo apontando pra grupo inexistente nem as fixtures no banco.
try {

// fixtures
wpp_cfg_set('grupo_alertas_jid',  '111@g.us');
wpp_cfg_set('grupo_chamados_jid', '222@g.us');
$pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone IN ('5567999990000','5567888880000')");
$pdo->exec("DELETE FROM portal_wpp_autorizados WHERE telefone='5567777770000'");
$pdo->exec("INSERT INTO portal_wpp_contatos (glpi_user_id,telefone,ativo) VALUES (999999,'5567999990000',1)");
$pdo->exec("INSERT INTO portal_wpp_contatos (glpi_user_id,telefone,ativo) VALUES (999998,'5567888880000',0)");
$pdo->exec("INSERT INTO portal_wpp_autorizados (telefone,nome,ativo) VALUES ('5567777770000','teste guardrail',1)");

t_ok(wpp_norm_telefone('+55 (67) 99999-0000') === '5567999990000', 'norm_telefone tira nao-digito');

t_ok(wpp_destino_permitido('111@g.us'),  'grupo alertas configurado: ok');
t_ok(wpp_destino_permitido('222@g.us'),  'grupo chamados configurado: ok');
t_ok(!wpp_destino_permitido('333@g.us'), 'grupo aleatorio: BLOQUEADO');
t_ok(!wpp_destino_permitido('status@broadcast'), 'status: BLOQUEADO');
t_ok(!wpp_destino_permitido('999@broadcast'),    'broadcast: BLOQUEADO');
t_ok(wpp_destino_permitido('5567999990000'),     'contato ativo: ok');
t_ok(!wpp_destino_permitido('5511000000000'),    'numero nao cadastrado: BLOQUEADO');
t_ok(!wpp_destino_permitido(''),                 'vazio: BLOQUEADO');

// sufixo @s.whatsapp.net removido -> bate no contato ativo
t_ok(wpp_destino_permitido('5567999990000@s.whatsapp.net'), 'contato com sufixo @s.whatsapp.net: ok');
// sufixo JID nao suportado -> BLOQUEADO mesmo que os digitos batam num contato
t_ok(!wpp_destino_permitido('5567999990000@newsletter'), 'sufixo @newsletter: BLOQUEADO');

// contato com ativo=0 -> BLOQUEADO
t_ok(!wpp_destino_permitido('5567888880000'), 'contato inativo (ativo=0): BLOQUEADO');
// numero so em portal_wpp_autorizados (ativo) -> ok
t_ok(wpp_destino_permitido('5567777770000'), 'autorizado ativo: ok');
// formatacao removida -> bate no contato salvo so com digitos
t_ok(wpp_destino_permitido('+55 (67) 99999-0000'), 'contato com telefone formatado: ok');

// evo_guarded_send bloqueia sem chamar o callable
$chamou = false;
$r = evo_guarded_send('333@g.us', function() use (&$chamou){ $chamou = true; return ['ok'=>true]; }, 'x');
t_ok(!$chamou && $r['bloqueado'] === true, 'guarded_send nao chama o callable pra destino bloqueado');

// evo_guarded_send deixa passar destino ok
$r = evo_guarded_send('111@g.us', fn() => ['ok'=>true], 'x');
t_ok($r['ok'] === true, 'guarded_send passa destino permitido');

// --- Etapa 2: numero com conversa ativa vira destino permitido ---
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
$pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW())")
    ->execute(['5567666660000']);

t_ok(wpp_destino_permitido('5567666660000'), 'numero com conversa ativa: ok');
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
t_ok(!wpp_destino_permitido('5567666660000'), 'mesmo numero, conversa apagada: BLOQUEADO de novo');

} finally {
    // --- limpeza: fixtures e config real (roda mesmo se um assert lançar) ---
    $pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone IN ('5567999990000','5567888880000')");
    $pdo->exec("DELETE FROM portal_wpp_autorizados WHERE telefone='5567777770000'");
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
    wpp_cfg_set('grupo_alertas_jid',  $oldA ?? '');
    wpp_cfg_set('grupo_chamados_jid', $oldC ?? '');
}
