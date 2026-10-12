<?php
// Testes da Fase 2 do box pfSense expandido — saúde do servidor (mem/carga)
// + status de pfBlockerNG/Snort, via scraping HTTPS (sem SSH, ver header de
// monitor_pfsense_saude_lib.php). Só regras PURAS + fixtures reais
// capturados do pfSense da Lj 030 em 2026-10-12: nada aqui toca banco, rede
// ou pfSense.
require_once __DIR__ . '/../../monitor_pfsense_saude_lib.php';

$htmlServicos = (string) file_get_contents(__DIR__ . '/fixtures/pfsense_services_lj030.html');
$getstats = trim((string) file_get_contents(__DIR__ . '/fixtures/pfsense_getstats_lj030.txt'));

// ---------------------------------------------------------------------------
// monitor_pfsense_saude_parse_servicos() — status_services.php (pfSense 2.7)
// ---------------------------------------------------------------------------
$sv = monitor_pfsense_saude_parse_servicos($htmlServicos);
t_eq($sv, ['pfb_dnsbl' => false, 'pfb_filter' => false, 'snort' => true],
    'parse_servicos: pfb_dnsbl/pfb_filter parados, snort rodando (fixture real Lj 030) — bsnmpd (fora do alvo) não entra');
t_eq(monitor_pfsense_saude_parse_servicos('<html>login</html>'), [], 'parse_servicos: página sem tabela -> []');
t_eq(monitor_pfsense_saude_parse_servicos(str_replace('pfb_dnsbl', 'outra_coisa', $htmlServicos)),
    ['pfb_filter' => false, 'snort' => true], 'parse_servicos: linha sem nome-alvo não entra');

// ---------------------------------------------------------------------------
// monitor_pfsense_saude_parse_recursos() — getstats.php (pfSense 2.7)
// ---------------------------------------------------------------------------
$rec = monitor_pfsense_saude_parse_recursos($getstats);
t_eq($rec, ['mem_pct' => 15.0, 'carga_1m' => 0.69, 'carga_5m' => 0.45, 'carga_15m' => 0.43],
    'parse_recursos: mem% e carga 1/5/15min (fixture real Lj 030)');
t_eq(monitor_pfsense_saude_parse_recursos(''), ['mem_pct' => null, 'carga_1m' => null, 'carga_5m' => null, 'carga_15m' => null],
    'parse_recursos: string vazia -> tudo null, não erro');
t_eq(monitor_pfsense_saude_parse_recursos('1|2|30')['mem_pct'], 30.0, 'parse_recursos: funciona mesmo sem o campo de carga');

// ---------------------------------------------------------------------------
// monitor_pfsense_saude_classificar() — junta serviços + recursos
// ---------------------------------------------------------------------------
$cl = monitor_pfsense_saude_classificar(['pfb_dnsbl' => false, 'pfb_filter' => false, 'snort' => true], $rec);
t_eq([$cl['pfblocker'], $cl['snort']], ['inativo', 'ativo'], 'classificar: pfBlockerNG com os 2 parados -> inativo; snort rodando -> ativo');

$cl = monitor_pfsense_saude_classificar(['pfb_dnsbl' => true, 'pfb_filter' => false], $rec);
t_eq($cl['pfblocker'], 'parcial', 'classificar: 1 dos 2 serviços do pfBlockerNG rodando -> parcial');

$cl = monitor_pfsense_saude_classificar(['pfb_dnsbl' => true, 'pfb_filter' => true, 'snort' => false], $rec);
t_eq([$cl['pfblocker'], $cl['snort']], ['ativo', 'inativo'], 'classificar: os 2 rodando -> ativo; snort parado -> inativo');

$cl = monitor_pfsense_saude_classificar([], $rec);
t_eq([$cl['pfblocker'], $cl['snort']], ['nao_instalado', 'nao_instalado'], 'classificar: nenhuma linha presente -> não instalado (não confundir com inativo)');

// ---------------------------------------------------------------------------
// monitor_pfsense_saude_resumo_texto() — texto pro resumo do chamado
// ---------------------------------------------------------------------------
$linha = fn(string $loja, ?float $mem, ?float $carga, string $pfb, string $snort) => [
    'loja' => $loja, 'mem_pct' => $mem, 'carga_1m' => $carga, 'carga_5m' => null, 'carga_15m' => null,
    'pfblocker' => $pfb, 'snort' => $snort,
];

$texto = monitor_pfsense_saude_resumo_texto([$linha('Lj 001', 40.0, 0.5, 'ativo', 'ativo')]);
t_ok(str_contains($texto, '- Lj 001: 🟢 mem 40%') && str_contains($texto, '🟢 carga 0.5') && str_contains($texto, '🟢 pfBlockerNG')
    && str_contains($texto, '🟢 Snort'), 'resumo_texto: tudo saudável -> tudo 🟢');

$texto = monitor_pfsense_saude_resumo_texto([$linha('Lj 030', 90.0, 2.5, 'inativo', 'ativo')]);
t_ok(str_contains($texto, '🔴 mem 90%') && str_contains($texto, '🔴 carga 2.5') && str_contains($texto, '🔴 pfBlockerNG inativo'),
    'resumo_texto: mem/carga acima do crítico -> 🔴; pfBlockerNG inativo avisado');

t_ok(str_contains(monitor_pfsense_saude_resumo_texto([$linha('Lj 030', 78.0, 1.5, 'parcial', 'ativo')]), '🟡 mem 78%'),
    'resumo_texto: faixa intermediária -> 🟡');

t_ok(str_contains(monitor_pfsense_saude_resumo_texto([$linha('Lj 030', 40.0, 0.5, 'nao_instalado', 'nao_instalado')]), '⚪ pfBlockerNG não instalado'),
    'resumo_texto: pacote não instalado -> ⚪, distinto de inativo');

$texto = monitor_pfsense_saude_resumo_texto(
    [$linha('Lj 001', 40.0, 0.5, 'ativo', 'ativo')],
    ['Lj 003' => 'atrás de MikroTik', 'Lj 010' => 'sem pfSense, é MikroTik']
);
t_ok(str_contains($texto, '- Lj 003: sem dado (atrás de MikroTik)'), 'resumo_texto: loja sem dado entra com o motivo');
t_ok(str_contains($texto, '- Lj 010: sem dado (sem pfSense, é MikroTik)'), 'resumo_texto: 2ª loja sem dado, motivo próprio');

t_eq(monitor_pfsense_saude_resumo_texto([]), 'Saúde dos pfSense: nenhuma loja monitorada.', 'resumo_texto: nada monitorado -> mensagem explícita, não string vazia');
