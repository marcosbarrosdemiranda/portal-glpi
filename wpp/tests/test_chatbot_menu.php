<?php
// Testes do menu + picker de loja/usuario (Etapa 3). Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
require_once __DIR__ . '/../glpi_bot.php'; // bot_lojas/bot_usuarios_loja/bot_entidade_nome/bot_perfil_tecnico (Task 1)
global $pdo;

$GLOBALS['__wpp_fake_send'] = [];
$GLOBALS['__wpp_chatbot_enviar_fake'] = function (string $destino, string $texto): array {
    global $pdo;
    $existia = (bool) $pdo->query("SELECT 1 FROM portal_wpp_conversas WHERE telefone = " . $pdo->quote($destino))->fetchColumn();
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto, 'conversa_existia' => $existia];
    return ['ok' => true];
};
$GLOBALS['__wpp_chatbot_criar_chamado_fake'] = function (int $rid, int $eid, string $tit, string $desc): array {
    return ['ok' => true, 'ticket_id' => 9191, 'erro' => null];
};

function _msg_menu(string $texto): array {
    return ['id' => 'X', 'remoteJid' => $texto, 'fromMe' => false, 'timestamp' => time(), 'texto' => $texto, 'temMidia' => false];
}

$sufixo = (string) random_int(4000000, 4999999);
$telTecnico = '33' . $sufixo; // vinculado, perfil tecnico -> sempre picker
$telLoja    = '34' . $sufixo; // vinculado, NAO tecnico -> confirma_loja
$telNenhum  = '35' . $sufixo; // sem vinculo -> "ainda nao disponivel"

$pdo->exec("DELETE FROM glpi_entities WHERE name LIKE 'teste_menu_%'");
$pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_menu_%'");

try {
    // fixture: 1 loja com 1 usuario
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_menu_loja', 'Entidade raiz > teste_menu_loja', 2)");
    $idLoja = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_menu_loja'")->fetchColumn();
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, entities_id, is_active, is_deleted) VALUES ('teste_menu_userloja', 'Sobrenome', 'DaLoja', $idLoja, 1, 0)");
    $idUserLoja = (int) $pdo->query("SELECT id FROM glpi_users WHERE name='teste_menu_userloja'")->fetchColumn();

    // fixture: usuario tecnico vinculado por telefone
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, mobile, entities_id, is_active, is_deleted) VALUES ('teste_menu_tecnico', 'Sobrenome', 'Tecnico', '', 0, 1, 0)");
    $stmt = $pdo->prepare("UPDATE glpi_users SET mobile = ? WHERE name = 'teste_menu_tecnico'");
    $stmt->execute([$telTecnico]);
    $idTecnico = (int) $pdo->query("SELECT id FROM glpi_users WHERE name='teste_menu_tecnico'")->fetchColumn();
    $pdo->exec("INSERT INTO glpi_profiles_users (users_id, profiles_id) VALUES ($idTecnico, 4)");

    // fixture: usuario "loja/departamento" vinculado por telefone (SEM profiles_id=4)
    // entities_id=0 (nao $idLoja) de proposito: se cair na mesma loja do picker,
    // bot_usuarios_loja($idLoja) devolveria 2 usuarios (SAC + userloja) e o teste
    // do tecnico ("1" = unico usuario da loja) ficaria ambiguo.
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, mobile, entities_id, is_active, is_deleted) VALUES ('teste_menu_sac', 'SAC', 'Teste', '', 0, 1, 0)");
    $stmt2 = $pdo->prepare("UPDATE glpi_users SET mobile = ? WHERE name = 'teste_menu_sac'");
    $stmt2->execute([$telLoja]);

    // --- técnico: "1" no menu -> vai direto pro picker de loja (nunca confirma) ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($telTecnico, _msg_menu('oi'));
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'menu', 'primeira msg: passo menu');
    wpp_chatbot_processar($telTecnico, _msg_menu('1'));
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'escolhe_loja', 'tecnico + "1": vai direto pro picker de loja (nunca confirma_loja)');

    // Nao assume que o fixture e a 1a opcao alfabetica: descobre a posicao
    // real na lista (o banco de teste compartilhado pode ter outras
    // entidades reais que ordenem antes) — mesmo padrao de
    // test_glpi_bot_lojas.php (Task 1) com in_array/array_search.
    $lojasDisponiveis = bot_lojas();
    $idxLoja = array_search($idLoja, array_column($lojasDisponiveis, 'id'), true);
    t_ok($idxLoja !== false, 'fixture: loja aparece no picker de lojas');
    wpp_chatbot_processar($telTecnico, _msg_menu((string) ($idxLoja + 1))); // escolhe a loja do fixture pela posicao real
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'escolhe_usuario', 'apos escolher loja: passo escolhe_usuario');
    t_eq(wpp_chatbot_estado_get($telTecnico)['entities_id'], $idLoja, 'entities_id da loja escolhida foi salvo');

    $usuariosDisponiveis = bot_usuarios_loja($idLoja);
    $idxUsuario = array_search($idUserLoja, array_column($usuariosDisponiveis, 'id'), true);
    t_ok($idxUsuario !== false, 'fixture: usuario aparece no picker de usuarios da loja');
    wpp_chatbot_processar($telTecnico, _msg_menu((string) ($idxUsuario + 1))); // escolhe o usuario do fixture pela posicao real
    $estFinal = wpp_chatbot_estado_get($telTecnico);
    t_eq($estFinal['passo'], 'titulo', 'apos escolher usuario: passo titulo');
    t_eq($estFinal['vinculo']['glpi_user_id'], $idUserLoja, 'requerente = usuario escolhido no picker (nao o tecnico)');

    // --- vinculo tipo loja (SAC): "1" no menu -> confirma_loja ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($telLoja, _msg_menu('oi'));
    wpp_chatbot_processar($telLoja, _msg_menu('1'));
    t_eq(wpp_chatbot_estado_get($telLoja)['passo'], 'confirma_loja', 'vinculo tipo loja + "1": pede confirmacao');
    $msgConfirma = end($GLOBALS['__wpp_fake_send'])['texto'];
    t_ok(strpos($msgConfirma, 'teste_menu_sac') !== false || strpos($msgConfirma, 'SAC') !== false, 'confirmacao cita o nome do vinculo');

    // "1" (sim) na confirmacao -> pula pro titulo com o proprio vinculo
    wpp_chatbot_processar($telLoja, _msg_menu('1'));
    t_eq(wpp_chatbot_estado_get($telLoja)['passo'], 'titulo', 'confirma_loja "1" (sim): pula pro titulo');

    // --- vinculo tipo loja, mas responde "2" (nao) -> cai no picker manual ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_estado_limpar($telLoja);
    wpp_chatbot_processar($telLoja, _msg_menu('oi'));
    wpp_chatbot_processar($telLoja, _msg_menu('1'));
    wpp_chatbot_processar($telLoja, _msg_menu('2')); // nao
    t_eq(wpp_chatbot_estado_get($telLoja)['passo'], 'escolhe_loja', 'confirma_loja "2" (nao): cai no picker manual de loja');

    // --- sem vinculo: "1" continua com a mensagem de indisponivel (Etapa 2, sem mudanca) ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($telNenhum, _msg_menu('oi'));
    wpp_chatbot_processar($telNenhum, _msg_menu('1'));
    t_ok(wpp_chatbot_estado_get($telNenhum) === null, 'sem vinculo: nao fica com conversa presa');
    $msgIndisp = end($GLOBALS['__wpp_fake_send'])['texto'];
    t_ok(strpos($msgIndisp, 'identificar') !== false, 'sem vinculo: mensagem de indisponivel (nao entra no picker)');
    t_ok(end($GLOBALS['__wpp_fake_send'])['conversa_existia'], 'sem vinculo: mensagem mandada ANTES de limpar');

    // --- menu: opcoes 2 e 3 nao ficam com conversa presa ---
    wpp_chatbot_processar($telNenhum, _msg_menu('oi'));
    wpp_chatbot_processar($telNenhum, _msg_menu('2'));
    t_ok(wpp_chatbot_estado_get($telNenhum) === null, 'menu "2" (consultar): nao fica com conversa presa');
    wpp_chatbot_processar($telNenhum, _msg_menu('oi'));
    wpp_chatbot_processar($telNenhum, _msg_menu('3'));
    t_ok(wpp_chatbot_estado_get($telNenhum) === null, 'menu "3" (sair): nao fica com conversa presa');
} finally {
    $GLOBALS['__wpp_chatbot_enviar_fake'] = null;
    $GLOBALS['__wpp_chatbot_criar_chamado_fake'] = null;
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone IN ($telTecnico, $telLoja, $telNenhum)");
    $pdo->exec("DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name LIKE 'teste_menu_%')");
    $pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_menu_%'");
    $pdo->exec("DELETE FROM glpi_entities WHERE name LIKE 'teste_menu_%'");
}
