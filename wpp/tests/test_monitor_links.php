<?php
// Testes da etapa 3c — qual link de internet cada loja está usando (lido do
// Status → Gateways do pfSense). Só regras PURAS + HTML real capturado do
// pfSense da Lj 030 (fixtures/): nada aqui toca banco, rede ou pfSense.
require_once __DIR__ . '/../../monitor_links_lib.php';

$html = (string) file_get_contents(__DIR__ . '/fixtures/pfsense_gateways_lj030.html');

// ---------------------------------------------------------------------------
// monitor_links_parse() — tabela do status_gateways.php (pfSense 2.7)
// ---------------------------------------------------------------------------
$gws = monitor_links_parse($html);
t_eq(array_column($gws, 'nome'), ['mikrotik', 'fibra', 'fibra_pfsense', 'starlink', 'VPN_VPNV4'], 'parse: todos os gateways, na ordem');
$mk = $gws[0];
t_eq([$mk['gateway'], $mk['monitor'], $mk['status'], $mk['padrao'], $mk['descricao']], ['10.10.10.1', '9.9.9.9', 'up', true, 'Mikrotik'],
    'parse: mikrotik — IP, monitor, no ar, é o padrão (default), descrição');
t_ok($mk['rtt_ms'] > 0 && $mk['perda'] === 0.0, 'parse: RTT em ms e perda em %');
t_eq([$gws[3]['nome'], $gws[3]['padrao'], $gws[3]['status']], ['starlink', false, 'up'], 'parse: starlink no ar, não é o padrão');
t_eq([$gws[1]['monitor'], $gws[1]['status']], [null, 'nao_monitorado'], 'parse: (unmonitored) -> sem monitor, status nao_monitorado');

// status pela cor da célula
$troca = fn(string $cls) => monitor_links_parse(str_replace('<td class="bg-success">', "<td class=\"$cls\">", $html))[0]['status'];
t_eq($troca('bg-danger'), 'down', 'parse: bg-danger -> down');
t_eq($troca('bg-warning'), 'alerta', 'parse: bg-warning (perda/latência) -> alerta');
t_eq(monitor_links_parse('<html>login</html>'), [], 'parse: página sem tabela -> []');

// ---------------------------------------------------------------------------
// monitor_links_internet() — só links de internet: monitorados e não-VPN
// ---------------------------------------------------------------------------
t_eq(array_column(monitor_links_internet($gws), 'nome'), ['mikrotik', 'starlink'], 'internet: tira não monitorados e VPN');

// ---------------------------------------------------------------------------
// monitor_links_ocorrencias() — link fora + loja saindo pelo link reserva
// ---------------------------------------------------------------------------
$lk = fn(string $loja, string $nome, string $status, bool $padrao, bool $principal) => [
    'loja' => $loja, 'nome' => $nome, 'descricao' => $nome, 'status' => $status, 'padrao' => $padrao ? 1 : 0,
    'principal' => $principal ? 1 : 0, 'status_desde' => '2026-09-28 11:00:00', 'perda' => 100.0,
];
t_eq(monitor_links_ocorrencias([$lk('Lj 030', 'mikrotik', 'up', true, true), $lk('Lj 030', 'starlink', 'up', false, false)]), [],
    'ocorr: tudo no ar e saindo pelo principal -> nada');

$oc = monitor_links_ocorrencias([$lk('Lj 030', 'mikrotik', 'up', true, true), $lk('Lj 030', 'starlink', 'down', false, false)]);
t_eq(array_column($oc, 'chave'), ['rede_link:Lj 030:starlink'], 'ocorr: starlink fora -> 1 ocorrência por link');
t_ok(str_contains($oc[0]['titulo'], 'starlink') && $oc[0]['loja'] === 'Lj 030', 'ocorr: título cita o link, loja preenchida');

$oc = monitor_links_ocorrencias([$lk('Lj 030', 'mikrotik', 'down', false, true), $lk('Lj 030', 'starlink', 'up', true, false)]);
t_eq(array_column($oc, 'chave'), ['rede_link:Lj 030:mikrotik', 'rede_link:Lj 030:reserva'], 'ocorr: principal fora + saindo pelo reserva -> 2 ocorrências');
t_ok(str_contains($oc[1]['titulo'], 'starlink') && str_contains($oc[1]['detalhe'], 'mikrotik'), 'ocorr: reserva diz por qual link está saindo e qual é o principal');

$oc = monitor_links_ocorrencias([$lk('Lj 030', 'mikrotik', 'alerta', true, true)]);
t_eq($oc, [], 'ocorr: só perda/latência (alerta amarelo) não vira ocorrência');
