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
