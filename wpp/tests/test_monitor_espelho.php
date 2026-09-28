<?php
// Testes da virada (etapa 3): o monitor passa a mandar no estado que a
// Central de Alertas lê (portal_dude_estado). A regra é PURA
// (monitor_espelho_diff) — nada aqui toca o banco de produção.
require_once __DIR__ . '/../../monitor_lib.php';

$mon = fn(string $nome, string $status, string $desde, array $extra = []) => array_merge([
    'nome' => $nome, 'status' => $status, 'status_desde' => $desde, 'ip_respondeu' => '10.0.0.1',
    'ips' => '10.0.0.1', 'ip_fixo' => null, 'loja' => 'Lj 030', 'categoria' => 'PDVs', 'falhas_para_cair' => 8,
], $extra);
$est = fn(string $chave, string $status, string $em, array $extra = []) => array_merge([
    'chave' => $chave, 'nome' => $chave, 'endereco' => '10.0.0.1', 'loja' => 'Lj 030', 'categoria' => 'PDVs',
    'status' => $status, 'atualizado_em' => $em,
], $extra);

// ---------------------------------------------------------------------------
// status diferente -> grava com atualizado_em = "desde" do monitor
// ---------------------------------------------------------------------------
$d = monitor_espelho_diff(
    [$mon('PDV002-LJ030', 'up', '2026-09-28 04:57:23')],
    [$est('PDV002-LJ030', 'down', '2026-09-28 09:38:25')]
);
t_eq(count($d['gravar']), 1, 'espelho: monitor up x estado down -> grava');
t_eq([$d['gravar'][0]['status'], $d['gravar'][0]['atualizado_em']], ['up', '2026-09-28 04:57:23'],
    'espelho: status do monitor, "desde" do monitor (ligado muito tempo continua certo)');
t_eq($d['remover'], [], 'espelho: equipamento monitorado nunca é removido');

$d = monitor_espelho_diff([$mon('PDV127-LJ003', 'down', '2026-09-28 08:05:55')], []);
t_eq([$d['gravar'][0]['chave'], $d['gravar'][0]['status']], ['PDV127-LJ003', 'down'], 'espelho: sem linha no estado -> cria');
t_eq($d['gravar'][0]['detalhe'], 'sem resposta a ping (8 tentativas)', 'espelho: detalhe do down cita a tolerância do grupo');

// ---------------------------------------------------------------------------
// mesmo status — só corrige loja/categoria/endereço ou atualizado_em fossilizado
// ---------------------------------------------------------------------------
$d = monitor_espelho_diff(
    [$mon('PDV010-LJ030', 'up', '2026-09-28 10:00:00')],
    [$est('PDV010-LJ030', 'up', '2026-09-28 10:00:00')]
);
t_eq([$d['gravar'], $d['metadados']], [[], []], 'espelho: mesmo status e mesmo timestamp -> nada a fazer');

// atualizado_em fossilizado (monitor tem status_desde mais recente que o estado): grava pra corrigir
$d = monitor_espelho_diff(
    [$mon('PDV050-LJ001', 'up', '2026-09-28 08:00:00')],
    [$est('PDV050-LJ001', 'up', '2026-09-25 20:02:21')] // timestamp antigo do Dude
);
t_eq(count($d['gravar']), 1, 'espelho: timestamp fossilizado (pre-monitor) -> grava pra corrigir');
t_eq($d['gravar'][0]['atualizado_em'], '2026-09-28 08:00:00', 'espelho: atualizado_em corrigido para status_desde do monitor');

$d = monitor_espelho_diff(
    [$mon('PDV010-LJ030', 'up', '2026-09-27 06:00:00')],
    [$est('PDV010-LJ030', 'up', '2026-09-28 10:00:00', ['loja' => '', 'categoria' => ''])]
);
t_eq($d['gravar'], [], 'espelho: estado já mais recente e mesmo status -> não regrava');
t_eq(count($d['metadados']), 1, 'espelho: loja/categoria vazias são corrigidas mesmo sem regravar timestamp');
t_eq([$d['metadados'][0]['loja'], $d['metadados'][0]['categoria']], ['Lj 030', 'PDVs'], 'espelho: loja/categoria corrigidas (horário por loja passa a valer)');

// ---------------------------------------------------------------------------
// limpeza: linhas que não são de equipamento monitorado saem
// ---------------------------------------------------------------------------
$d = monitor_espelho_diff(
    [$mon('PDV002-LJ030', 'up', '2026-09-28 04:57:23')],
    [$est('PDV002-LJ030', 'up', '2026-09-28 04:57:23'), $est('192.168.3.11', 'up', '2026-09-20 10:00:00'), $est('PDV121', 'up', '2026-09-20 10:00:00')]
);
t_eq($d['remover'], ['192.168.3.11', 'PDV121'], 'espelho: nomes antigos do Dude são removidos');

// desconhecido (ainda não pingado) = não grava nada, mas também não apaga
$d = monitor_espelho_diff(
    [$mon('NOVO', 'desconhecido', '')],
    [$est('NOVO', 'up', '2026-09-20 10:00:00')]
);
t_eq([$d['gravar'], $d['remover']], [[], []], 'espelho: desconhecido não grava nem remove');

// endereço: IP que respondeu; se nunca respondeu, o fixo ou o 1º candidato
$d = monitor_espelho_diff([$mon('X', 'down', '2026-09-28 08:00:00', ['ip_respondeu' => null, 'ips' => '192.168.0.14,192.168.33.170'])], []);
t_eq($d['gravar'][0]['endereco'], '192.168.0.14', 'espelho: sem IP que respondeu -> 1º candidato');
