<?php
// Testes de impressoras_lib.php — CRUD de cadastro. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../../impressoras_lib.php';
global $pdo;

$APELIDO_TESTE = '__teste_imp_hp__';

$pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();

try {
    $id = impressora_cadastrar($pdo, '10.0.9.50', $APELIDO_TESTE, 'Loja 05', 'public');
    t_ok($id > 0, 'impressora_cadastrar: devolve id > 0');

    $lista = impressora_listar($pdo);
    $achou = array_values(array_filter($lista, fn($i) => $i['apelido'] === $APELIDO_TESTE));
    t_eq(count($achou), 1, 'impressora_listar: cadastro aparece na lista');
    t_eq($achou[0]['ip'], '10.0.9.50', 'impressora_listar: ip salvo corretamente');
    t_eq($achou[0]['comunidade'], 'public', 'impressora_listar: comunidade default');

    $buscada = impressora_buscar($pdo, $id);
    t_ok($buscada !== null, 'impressora_buscar: acha pelo id');
    t_eq($buscada['loja'], 'Loja 05', 'impressora_buscar: loja salva corretamente');

    impressora_editar($pdo, $id, '10.0.9.51', $APELIDO_TESTE, 'Loja 06', 'privada123');
    $editada = impressora_buscar($pdo, $id);
    t_eq($editada['ip'], '10.0.9.51', 'impressora_editar: ip atualizado');
    t_eq($editada['loja'], 'Loja 06', 'impressora_editar: loja atualizada');
    t_eq($editada['comunidade'], 'privada123', 'impressora_editar: comunidade atualizada');

    impressora_excluir($pdo, $id);
    t_ok(impressora_buscar($pdo, $id) === null, 'impressora_excluir: some da base');
} finally {
    $pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
}

// --- impressora_snmp_parsear: puro, sem rede ---
$offline = impressora_snmp_parsear(['sysDescr' => false]);
t_ok($offline['online'] === false, 'snmp_parsear: sysDescr=false (timeout) -> online false');
t_ok($offline['modelo'] === null, 'snmp_parsear: offline -> modelo null');

$bruto = [
    'sysDescr'      => 'HP LaserJet Pro M404dn',
    'serial'        => 'VNC1234567',
    'paginas_total' => '48213',
    'consumiveis_descricoes' => ['Black Toner Cartridge', 'Imaging Drum'],
    'consumiveis_niveis'     => ['62', '-2'],
    'consumiveis_maximos'    => ['100', '100'],
];
$parseado = impressora_snmp_parsear($bruto);
t_ok($parseado['online'], 'snmp_parsear: com sysDescr -> online true');
t_eq($parseado['modelo'], 'HP LaserJet Pro M404dn', 'snmp_parsear: modelo = sysDescr');
t_eq($parseado['serial'], 'VNC1234567', 'snmp_parsear: serial');
t_eq($parseado['paginas_total'], 48213, 'snmp_parsear: paginas_total vira int');
t_eq(count($parseado['consumiveis']), 2, 'snmp_parsear: 2 consumiveis');
t_eq($parseado['consumiveis'][0]['nome'], 'Black Toner Cartridge', 'snmp_parsear: nome do consumivel 1');
t_eq($parseado['consumiveis'][0]['nivel'], 62, 'snmp_parsear: nivel do consumivel 1');
t_eq($parseado['consumiveis'][0]['max'], 100, 'snmp_parsear: max do consumivel 1');
t_ok($parseado['consumiveis'][1]['nivel'] === null, 'snmp_parsear: nivel -2 (sem percentual) vira null, nao erro');
t_ok($parseado['consumiveis'][1]['max'] === null, 'snmp_parsear: max tambem null quando nivel e -2');

$semConsumiveis = impressora_snmp_parsear(['sysDescr' => 'Brother HL-L2350DW', 'serial' => false, 'paginas_total' => null]);
t_ok($semConsumiveis['online'], 'snmp_parsear: online mesmo sem serial/paginas');
t_ok($semConsumiveis['serial'] === null, 'snmp_parsear: serial=false -> null');
t_ok($semConsumiveis['paginas_total'] === null, 'snmp_parsear: paginas_total=null -> null');
t_eq(count($semConsumiveis['consumiveis']), 0, 'snmp_parsear: sem consumiveis -> array vazio');
