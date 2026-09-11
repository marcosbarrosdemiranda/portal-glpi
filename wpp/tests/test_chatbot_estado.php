<?php
// Testes de estado de conversa + resolução de vínculo. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
global $pdo;

// Nota: usa dígitos apenas (não hex) e curto de propósito — $tel é reusado
// embutido em strings de telefone ("55679999{$tel}N") que precisam caber em
// VARCHAR(20) (portal_wpp_conversas/portal_wpp_vinculos) e bater com o valor
// já normalizado (wpp_norm_telefone só mantém dígitos) usado no match manual
// de portal_wpp_vinculos.
$tel = (string) random_int(1000000, 9999999);

try {
    // --- estado: get/set/limpar ---
    t_ok(wpp_chatbot_estado_get($tel) === null, 'sem conversa: get retorna null');

    wpp_chatbot_estado_set($tel, ['passo' => 'titulo', 'x' => 1]);
    $e = wpp_chatbot_estado_get($tel);
    t_eq($e['passo'], 'titulo', 'apos set: passo salvo');
    t_eq($e['x'], 1, 'apos set: outros campos salvos');

    wpp_chatbot_estado_set($tel, ['passo' => 'descricao']);
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'descricao', 'set 2x: sobrescreve (nao acumula)');

    wpp_chatbot_estado_limpar($tel);
    t_ok(wpp_chatbot_estado_get($tel) === null, 'apos limpar: get retorna null de novo');

    // --- resolve_vinculo ---
    $pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_etapa2_%'");
    $pdo->exec("DELETE FROM portal_wpp_vinculos WHERE telefone LIKE '55679999%'");

    // usuário A: só telefone cadastrado no GLPI (sem linha na aba de vínculos)
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, phone, mobile, entities_id, is_active, is_deleted)
                VALUES ('teste_etapa2_a', 'Fulano', 'De Tal', '', '55679999{$tel}1', 3, 1, 0)");
    $idA = (int) $pdo->lastInsertId();

    // usuário B: SEM telefone no GLPI, mas COM linha manual na aba de vínculos
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, phone, mobile, entities_id, is_active, is_deleted)
                VALUES ('teste_etapa2_b', 'SAC', 'Bonito', '', '', 5, 1, 0)");
    $idB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, ?, 'teste', 1)")
        ->execute(["55679999{$tel}2", $idB]);

    // usuário C: telefone no GLPI repetido em 2 usuários -> ambíguo, não resolve
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, phone, mobile, entities_id, is_active, is_deleted)
                VALUES ('teste_etapa2_c1', 'Dup', 'Um', '', '55679999{$tel}3', 1, 1, 0)");
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, phone, mobile, entities_id, is_active, is_deleted)
                VALUES ('teste_etapa2_c2', 'Dup', 'Dois', '', '55679999{$tel}3', 1, 1, 0)");

    $r = wpp_chatbot_resolve_vinculo("55679999{$tel}1");
    t_ok($r !== null, 'match automatico por telefone do GLPI: resolve');
    t_eq($r['glpi_user_id'], $idA, 'match automatico: glpi_user_id certo');
    t_eq($r['entities_id'], 3, 'match automatico: entities_id do usuario');

    $r2 = wpp_chatbot_resolve_vinculo("55679999{$tel}2");
    t_ok($r2 !== null, 'aba de vinculos manual: resolve');
    t_eq($r2['glpi_user_id'], $idB, 'aba de vinculos: glpi_user_id certo (sem telefone no GLPI)');

    t_ok(wpp_chatbot_resolve_vinculo("55679999{$tel}3") === null, '2 usuarios com o mesmo telefone: NAO resolve (ambiguo)');
    t_ok(wpp_chatbot_resolve_vinculo("5567000000000") === null, 'numero desconhecido: NAO resolve');

    // vínculo inativo não resolve
    $pdo->exec("DELETE FROM portal_wpp_vinculos WHERE telefone = '55679999{$tel}2'");
    $pdo->prepare("INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, ?, 'teste', 0)")
        ->execute(["55679999{$tel}2", $idB]);
    t_ok(wpp_chatbot_resolve_vinculo("55679999{$tel}2") === null, 'vinculo com ativo=0: NAO resolve');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = " . $pdo->quote($tel));
    $pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_etapa2_%'");
    $pdo->exec("DELETE FROM portal_wpp_vinculos WHERE telefone LIKE '55679999%'");
}
