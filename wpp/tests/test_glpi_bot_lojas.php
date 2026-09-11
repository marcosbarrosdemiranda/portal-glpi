<?php
// Testes das consultas de loja/usuário/perfil (Etapa 3). Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../glpi_bot.php';
global $pdo;

$pdo->exec("DELETE FROM glpi_entities WHERE name LIKE 'teste_e3_%'");
$pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_e3_%'");
$pdo->exec("DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name LIKE 'teste_e3_%')");

try {
    // Níveis batem com a árvore real do GLPI daqui: level 1 = "Entidade raiz"
    // (id=0, já excluída pelo id > 0), level 2 = a holding ("Grupo Gmais"),
    // level 3 = lojas de verdade.
    // holding (level 2) — NÃO deve aparecer em bot_lojas()
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_holding', 'Entidade raiz > teste_e3_holding', 2)");
    // 2 lojas (level 3) — devem aparecer, em ordem alfabética de completename
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_lojaB', 'Entidade raiz > teste_e3_holding > teste_e3_lojaB', 3)");
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_lojaA', 'Entidade raiz > teste_e3_holding > teste_e3_lojaA', 3)");
    $idLojaA = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_lojaA'")->fetchColumn();
    $idLojaB = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_lojaB'")->fetchColumn();

    $lojas = bot_lojas();
    $nomesLojas = array_column($lojas, 'nome');
    $idsLojas = array_column($lojas, 'id');
    t_ok(in_array($idLojaA, $idsLojas, true), 'bot_lojas: loja A (level 3) aparece');
    t_ok(in_array($idLojaB, $idsLojas, true), 'bot_lojas: loja B (level 3) aparece');
    // holding (level 2) não deve estar na lista — não é loja de verdade
    $holdingId = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_holding'")->fetchColumn();
    t_ok(!in_array($holdingId, $idsLojas, true), 'bot_lojas: holding (level 2) NAO aparece');

    t_eq(bot_entidade_nome($idLojaA), 'teste_e3_lojaA', 'bot_entidade_nome: sem alias cadastrado, devolve o nome original');

    // usuários da lojaA: um ativo, um inativo (não deve aparecer), um em outra loja (não deve aparecer)
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, entities_id, is_active, is_deleted) VALUES ('teste_e3_u1', 'Sobrenome', 'Ativo', $idLojaA, 1, 0)");
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, entities_id, is_active, is_deleted) VALUES ('teste_e3_u2', 'Sobrenome', 'Inativo', $idLojaA, 0, 0)");
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, entities_id, is_active, is_deleted) VALUES ('teste_e3_u3', 'Sobrenome', 'OutraLoja', $idLojaB, 1, 0)");
    $idU1 = (int) $pdo->query("SELECT id FROM glpi_users WHERE name='teste_e3_u1'")->fetchColumn();

    $usuarios = bot_usuarios_loja($idLojaA);
    $idsUsuarios = array_column($usuarios, 'id');
    t_ok(in_array($idU1, $idsUsuarios, true), 'bot_usuarios_loja: usuario ativo da loja aparece');
    t_eq(count($usuarios), 1, 'bot_usuarios_loja: so o ativo da loja certa (nao o inativo, nao o de outra loja)');
    t_eq($usuarios[0]['nome'], 'Ativo Sobrenome', 'bot_usuarios_loja: nome formatado (nome_requerente)');

    // perfil técnico
    $pdo->exec("INSERT INTO glpi_profiles_users (users_id, profiles_id) VALUES ($idU1, 4)");
    t_ok(bot_perfil_tecnico($idU1), 'bot_perfil_tecnico: profiles_id=4 -> true');
    $idU3 = (int) $pdo->query("SELECT id FROM glpi_users WHERE name='teste_e3_u3'")->fetchColumn();
    t_ok(!bot_perfil_tecnico($idU3), 'bot_perfil_tecnico: sem linha em glpi_profiles_users -> false');
} finally {
    $pdo->exec("DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name LIKE 'teste_e3_%')");
    $pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_e3_%'");
    $pdo->exec("DELETE FROM glpi_entities WHERE name LIKE 'teste_e3_%'");
}
