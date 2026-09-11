<?php
// Testes da FSM do chatbot (Fluxo A - vinculado). Roda com `php wpp/tests/run.php`.
// Usa o mesmo fake de envio que test_gatilhos.php usa (ver aquele arquivo
// pra referência do padrão), pra não precisar da Evolution real nem do
// GLPI real: também substitui bot_criar_chamado por um fake local.
// NÃO faz require de glpi_bot.php (chatbot.php também não faz, de
// propósito — ver comentário no topo de wpp/chatbot.php) — se fizesse, a
// função fake abaixo colidiria com a de verdade ("Cannot redeclare").
require_once __DIR__ . '/../chatbot.php';
global $pdo;

// Telefone só com dígitos, curto: cabe em VARCHAR(20) mesmo com sufixos, e
// não quebra wpp_chatbot_resolve_vinculo() (que normaliza a busca pra só
// dígitos via wpp_norm_telefone — um fixture com letras hex não bateria
// com o valor cru gravado em glpi_users.mobile). Mesmo precedente já
// aplicado no fix da Task 1 e na Task 3 (ver os reports delas).
$tel = (string) random_int(2000000, 2999999);

// --- fakes: nunca tocam rede ---
$GLOBALS['__wpp_fake_send'] = [];
function evo_send_text(string $destino, string $texto): array {
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto];
    return ['ok' => true];
}

$GLOBALS['__fake_criar_chamado_resultado'] = ['ok' => true, 'ticket_id' => 4242, 'erro' => null];
function bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    return $GLOBALS['__fake_criar_chamado_resultado'];
}

function _msg_fsm(string $texto, bool $temMidia = false): array {
    return ['id' => 'X', 'remoteJid' => $texto, 'fromMe' => false, 'timestamp' => time(), 'texto' => $texto, 'temMidia' => $temMidia];
}

$pdo->prepare("DELETE FROM glpi_users WHERE name = 'teste_fsm_vinculado'")->execute();
$pdo->prepare("INSERT INTO glpi_users (name, realname, firstname, phone, mobile, entities_id, is_active, is_deleted)
               VALUES ('teste_fsm_vinculado', 'Fulano', 'FSM', '', ?, 9, 1, 0)")->execute([$tel]);
$userId = (int) $pdo->lastInsertId();

try {
    // --- número NÃO vinculado: 1 mensagem, sem estado preso ---
    $telNaoVinc = $tel . '9'; // só dígitos também, e não bate com nenhum glpi_users cadastrado
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($telNaoVinc, _msg_fsm('oi'));
    t_eq(count($GLOBALS['__wpp_fake_send']), 1, 'nao vinculado: manda exatamente 1 mensagem');
    t_ok(wpp_chatbot_estado_get($telNaoVinc) === null, 'nao vinculado: nao fica com conversa presa');

    // --- número vinculado: fluxo completo até criar o chamado ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'titulo', 'vinculado: 1a msg -> passo titulo');
    t_ok(strpos($GLOBALS['__wpp_fake_send'][0]['texto'], 'título') !== false, 'vinculado: pergunta o titulo');

    wpp_chatbot_processar($tel, _msg_fsm('PC nao liga'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'descricao', 'apos titulo: passo descricao');
    t_eq(wpp_chatbot_estado_get($tel)['titulo'], 'PC nao liga', 'titulo foi salvo no estado');

    wpp_chatbot_processar($tel, _msg_fsm('Aperto o botao e nada acontece'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'confirma_mais', 'apos descricao: passo confirma_mais');

    // "1" = quer adicionar mais
    wpp_chatbot_processar($tel, _msg_fsm('1'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'descricao', '"1": volta pra descricao');

    wpp_chatbot_processar($tel, _msg_fsm('Ja tentei trocar a tomada tambem'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'confirma_mais', 'segunda descricao: confirma_mais de novo');
    t_ok(strpos(wpp_chatbot_estado_get($tel)['descricao'], 'Aperto o botao') !== false, 'descricao acumulou o 1o trecho');
    t_ok(strpos(wpp_chatbot_estado_get($tel)['descricao'], 'tomada') !== false, 'descricao acumulou o 2o trecho');

    // "2" = finalizar -> cria o chamado (fake) e limpa o estado
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('2'));
    t_ok(wpp_chatbot_estado_get($tel) === null, 'apos finalizar: conversa encerrada');
    $ultimaMsg = end($GLOBALS['__wpp_fake_send'])['texto'];
    t_ok(strpos($ultimaMsg, '4242') !== false, 'mensagem final cita o numero do chamado criado');

    $n = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel) . " AND ticket_id = 4242")->fetchColumn();
    t_eq($n, 1, 'chamado gravado em portal_wpp_chamados com origem vinculado');

    // --- cancelar (opção 3) ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm('Outro titulo'));
    wpp_chatbot_processar($tel, _msg_fsm('Outra descricao'));
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('3'));
    t_ok(wpp_chatbot_estado_get($tel) === null, 'cancelar (3): encerra sem criar chamado');
    t_ok(strpos(end($GLOBALS['__wpp_fake_send'])['texto'], 'cancelad') !== false, 'mensagem confirma cancelamento');

    // --- opção inválida no confirma_mais ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm('T'));
    wpp_chatbot_processar($tel, _msg_fsm('D'));
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('9'));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'confirma_mais', 'opcao invalida: nao avanca o passo');
    t_ok(strpos(end($GLOBALS['__wpp_fake_send'])['texto'], 'inv') !== false, 'opcao invalida: avisa');
    wpp_chatbot_estado_limpar($tel); // limpa pro proximo bloco

    // --- titulo vazio: reask, nao avanca ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm(''));
    t_eq(wpp_chatbot_estado_get($tel)['passo'], 'titulo', 'titulo vazio: nao avanca o passo');

    // --- imagem sem texto no passo titulo: soma zero avanco (mesma msg de vazio) ---
    // (comportamento aceito: so a etapa "descricao" tem aviso especifico de midia)
} finally {
    $pdo->exec("DELETE FROM glpi_users WHERE name = 'teste_fsm_vinculado'");
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone LIKE " . $pdo->quote($tel . '%'));
    $pdo->exec("DELETE FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel));
}
