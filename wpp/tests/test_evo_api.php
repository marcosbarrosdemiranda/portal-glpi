<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../evo_api.php';

// evo_url normaliza barras
t_eq(evo_url('/instance/connect/x'), rtrim(EVO_URL, '/') . '/instance/connect/x', 'evo_url junta sem barra dupla');
t_eq(evo_url('instance/x'),          rtrim(EVO_URL, '/') . '/instance/x',          'evo_url adiciona a barra que falta');

// evo_request nunca lança, mesmo com host inválido
$r = evo_request('GET', '/nada', null, 2);
t_ok(is_array($r) && array_key_exists('ok', $r) && array_key_exists('status', $r), 'evo_request sempre retorna array com ok/status');

// evo_status normaliza o estado
t_ok(is_array(evo_status()) && array_key_exists('estado', evo_status()), 'evo_status retorna estado');

// evo_send_text é bloqueado pra destino não permitido (não faz request de verdade).
// Obs: evo_api.php agora puxa guardrails.php -> db.php, então esta suíte exige banco.
require_once __DIR__ . '/../guardrails.php';
$oldChamados = wpp_cfg_get('grupo_chamados_jid');   // salva config real (suíte roda contra PROD)
wpp_cfg_set('grupo_chamados_jid', '222@g.us');
$r = evo_send_text('999@g.us', 'oi');
t_ok(!empty($r['bloqueado']), 'evo_send_text bloqueia grupo nao cadastrado');
wpp_cfg_set('grupo_chamados_jid', $oldChamados ?? '');  // restaura
