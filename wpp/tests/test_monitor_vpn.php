<?php
// Testes da etapa 3b — queda de VPN entre lojas. Só regras PURAS: nada aqui
// toca o banco nem a rede (a suíte roda em produção).
require_once __DIR__ . '/../../monitor_lib.php';

// ---------------------------------------------------------------------------
// pacotes por rodada (grupo) — 1..5, padrão 1
// ---------------------------------------------------------------------------
t_eq(monitor_grupo_normalizar([])['pacotes'], 1, 'grupo: pacotes padrão 1');
t_eq(monitor_grupo_normalizar(['pacotes' => 3])['pacotes'], 3, 'grupo: pacotes 3');
t_eq(monitor_grupo_normalizar(['pacotes' => 99])['pacotes'], 5, 'grupo: pacotes no máximo 5');
t_eq(monitor_grupo_normalizar(['pacotes' => 0])['pacotes'], 1, 'grupo: pacotes no mínimo 1');

// ---------------------------------------------------------------------------
// monitor_vpn_ocorrencias() — 1 ocorrência por loja com VPN fora
// ---------------------------------------------------------------------------
$fora = ['Lj 030' => ['nome' => 'pfSense Lj 030', 'ip' => '192.168.3.1', 'desde' => '2026-09-28 11:00:10']];
$oc = monitor_vpn_ocorrencias($fora, ['Lj 030' => 11]);
t_eq(count($oc), 1, 'vpn: 1 loja fora -> 1 ocorrência');
t_eq($oc[0]['chave'], 'dude:link:Lj 030', 'vpn: chave por loja (tipo dude_link reaproveitado)');
t_eq($oc[0]['titulo'], 'VPN Lj 030', 'vpn: título diz a loja');
t_eq($oc[0]['loja'], 'Lj 030', 'vpn: loja preenchida');
t_ok(str_contains($oc[0]['detalhe'], '11 equipamentos sem comunicação'), 'vpn: detalhe conta os equipamentos da loja');
t_ok(str_contains($oc[0]['detalhe'], '192.168.3.1') && str_contains($oc[0]['detalhe'], '11:00'), 'vpn: detalhe tem IP e desde');
t_eq(monitor_vpn_ocorrencias([], []), [], 'vpn: nenhuma loja fora -> nada');
$oc0 = monitor_vpn_ocorrencias($fora, []);
t_ok(!str_contains($oc0[0]['detalhe'], 'equipamentos'), 'vpn: sem equipamentos fora -> não cita contagem');

// ---------------------------------------------------------------------------
// monitor_vpn_suprimir() — com a VPN da loja fora, os equipamentos que caíram
// junto não alertam um por um. Quem já estava fora ANTES continua (senão o
// alerta dele "sumiria" e sairia um ✅ falso).
// ---------------------------------------------------------------------------
$dev = fn(string $nome, string $loja, string $desde) => ['chave' => "dude:device:$nome", 'titulo' => $nome, 'loja' => $loja, 'desde' => $desde];
$lista = [
    $dev('PDV001-LJ030', 'Lj 030', '2026-09-28 11:00:40'),   // caiu junto com a VPN
    $dev('pfSense Lj 030', 'Lj 030', '2026-09-28 11:00:10'), // o próprio pfSense (a VPN já avisa)
    $dev('PDV052-LJ001', 'Lj 001', '2026-09-26 00:00:51'),   // outra loja
    $dev('PDV009-LJ030', 'Lj 030', '2026-09-28 09:00:00'),   // já estava fora bem antes
];
$r = array_column(monitor_vpn_suprimir($lista, $fora), 'titulo');
t_eq($r, ['PDV052-LJ001', 'PDV009-LJ030'], 'vpn: suprime quem caiu junto (e o pfSense), mantém outra loja e quem já estava fora antes');
t_eq(count(monitor_vpn_suprimir($lista, [])), 4, 'vpn: nenhuma VPN fora -> não suprime nada');
t_eq(array_column(monitor_vpn_suprimir([$dev('X', 'Lj 030', '2026-09-28 10:59:00')], $fora), 'titulo'), [],
    'vpn: caiu até 2 min antes da VPN também é suprimido (ping de PDV é mais lento que o da VPN)');
