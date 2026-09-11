<?php
// Testes das tabelas novas da Etapa 2. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
global $pdo;

$tel = 'TESTE_ETAPA2_' . bin2hex(random_bytes(4));

try {
    // portal_wpp_conversas: round-trip básico
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, ?, NOW())")
        ->execute([$tel, json_encode(['passo' => 'titulo'])]);
    $estado = $pdo->query("SELECT estado FROM portal_wpp_conversas WHERE telefone = " . $pdo->quote($tel))->fetchColumn();
    t_eq(json_decode($estado, true)['passo'], 'titulo', 'portal_wpp_conversas: round-trip do estado');

    // portal_wpp_vinculos: UNIQUE(telefone)
    $pdo->prepare("INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, 999999, 'teste', 1)")
        ->execute([$tel]);
    $dup_falhou = false;
    try {
        $pdo->prepare("INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, 888888, 'dup', 1)")
            ->execute([$tel]);
    } catch (\PDOException $e) {
        $dup_falhou = true;
    }
    t_ok($dup_falhou, 'portal_wpp_vinculos: telefone duplicado é rejeitado (UNIQUE)');

    // portal_wpp_chamados: UNIQUE(telefone, ticket_id), ENUM aceita os 2 valores
    $pdo->prepare("INSERT INTO portal_wpp_chamados (telefone, ticket_id, origem, criado_em) VALUES (?, 123, 'vinculado', NOW())")
        ->execute([$tel]);
    $pdo->prepare("INSERT INTO portal_wpp_chamados (telefone, ticket_id, origem, criado_em) VALUES (?, 456, 'pendencia', NOW())")
        ->execute([$tel]);
    $n = $pdo->query("SELECT COUNT(*) FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel))->fetchColumn();
    t_eq((int) $n, 2, 'portal_wpp_chamados: aceita os 2 valores de origem');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = " . $pdo->quote($tel));
    $pdo->exec("DELETE FROM portal_wpp_vinculos WHERE telefone = " . $pdo->quote($tel));
    $pdo->exec("DELETE FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel));
}
