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
