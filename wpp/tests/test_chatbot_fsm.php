<?php
// Testes da FSM do chatbot (Fluxo A - vinculado). Roda com `php wpp/tests/run.php`.
// Os fakes são instalados em $GLOBALS (seam wpp_chatbot_enviar/
// wpp_chatbot_criar_chamado de wpp/chatbot.php), NUNCA redeclarando
// evo_send_text()/bot_criar_chamado() no escopo global: run.php carrega
// todos os test_*.php num único processo PHP e outros arquivos requerem as
// funções de verdade — redeclarar dava fatal "Cannot redeclare".
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
$GLOBALS['__wpp_chatbot_enviar_fake'] = function (string $destino, string $texto): array {
    // No MOMENTO do envio a conversa ainda precisa existir: pra número
    // só-vinculado (todo o público do Fluxo A) a permissão do guardrail de
    // saída vem justamente da linha em portal_wpp_conversas. Se o código
    // limpar o estado antes de mandar, em produção a mensagem é engolida.
    global $pdo;
    $aindaExiste = (bool) $pdo->query(
        "SELECT 1 FROM portal_wpp_conversas WHERE telefone = " . $pdo->quote($destino)
    )->fetchColumn();
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto, 'conversa_existia' => $aindaExiste];
    return ['ok' => true];
};

$GLOBALS['__fake_criar_chamado_resultado'] = ['ok' => true, 'ticket_id' => 4242, 'erro' => null];
$GLOBALS['__fake_criar_chamado_chamadas']  = 0;
$GLOBALS['__wpp_chatbot_criar_chamado_fake'] = function (int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    $GLOBALS['__fake_criar_chamado_chamadas']++;
    return $GLOBALS['__fake_criar_chamado_resultado'];
};

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
    $ultimaEnvio = end($GLOBALS['__wpp_fake_send']);
    t_ok(strpos($ultimaEnvio['texto'], '4242') !== false, 'mensagem final cita o numero do chamado criado');
    t_ok($ultimaEnvio['conversa_existia'], 'a confirmacao foi mandada ANTES de limpar a conversa (senao o guardrail real bloquearia)');

    $n = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel) . " AND ticket_id = 4242")->fetchColumn();
    t_eq($n, 1, 'chamado gravado em portal_wpp_chamados com origem vinculado');

    // --- cancelar (opção 3) ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm('Outro titulo'));
    wpp_chatbot_processar($tel, _msg_fsm('Outra descricao'));
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('3'));
    t_ok(wpp_chatbot_estado_get($tel) === null, 'cancelar (3): encerra sem criar chamado');
    $envioCancel = end($GLOBALS['__wpp_fake_send']);
    t_ok(strpos($envioCancel['texto'], 'cancelad') !== false, 'mensagem confirma cancelamento');
    t_ok($envioCancel['conversa_existia'], 'cancelamento tambem foi mandado ANTES de limpar a conversa');

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
    wpp_chatbot_estado_limpar($tel);

    // --- titulo gigante e cortado (glpi_tickets.name tem 255) ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm(str_repeat('a', 300)));
    t_eq(mb_strlen(wpp_chatbot_estado_get($tel)['titulo']), 250, 'titulo gigante cortado em 250 chars');
    wpp_chatbot_estado_limpar($tel);

    // --- falha do GLPI: mantem o estado pro usuario nao redigitar ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm('Titulo da falha'));
    wpp_chatbot_processar($tel, _msg_fsm('Descricao da falha'));
    $GLOBALS['__fake_criar_chamado_resultado'] = ['ok' => false, 'ticket_id' => null, 'erro' => 'boom'];
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('2'));
    $estadoFalha = wpp_chatbot_estado_get($tel);
    t_ok($estadoFalha !== null, 'falha do GLPI: conversa NAO e destruida');
    t_eq($estadoFalha['titulo'], 'Titulo da falha', 'falha: titulo preservado');
    t_ok(strpos($estadoFalha['descricao'], 'Descricao da falha') !== false, 'falha: descricao preservada');
    t_eq($estadoFalha['passo'], 'confirma_mais', 'falha: volta pro passo confirma_mais');
    t_ok(empty($estadoFalha['criando']), 'falha: flag criando zerada (permite nova tentativa)');
    $envioFalha = end($GLOBALS['__wpp_fake_send']);
    t_ok(strpos($envioFalha['texto'], 'guardados') !== false, 'falha: avisa que os dados foram guardados');
    t_ok($envioFalha['conversa_existia'], 'falha: mensagem mandada com a conversa ainda viva');

    // retry: "2" de novo, agora com o GLPI de volta -> cria sem redigitar nada
    $GLOBALS['__fake_criar_chamado_resultado'] = ['ok' => true, 'ticket_id' => 4343, 'erro' => null];
    wpp_chatbot_processar($tel, _msg_fsm('2'));
    t_ok(wpp_chatbot_estado_get($tel) === null, 'retry apos falha: conversa encerrada');
    $n = (int) $pdo->query("SELECT COUNT(*) FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel) . " AND ticket_id = 4343")->fetchColumn();
    t_eq($n, 1, 'retry apos falha: chamado criado sem redigitar');

    // --- guarda de reentrancia: estado.criando bloqueia um 2o "2" ---
    wpp_chatbot_processar($tel, _msg_fsm('oi'));
    wpp_chatbot_processar($tel, _msg_fsm('Titulo reentrante'));
    wpp_chatbot_processar($tel, _msg_fsm('Descricao reentrante'));
    $estadoReentrante = wpp_chatbot_estado_get($tel);
    $estadoReentrante['criando'] = true; // simula o 1o POST ainda em voo
    wpp_chatbot_estado_set($tel, $estadoReentrante);
    $chamadasAntes = $GLOBALS['__fake_criar_chamado_chamadas'];
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($tel, _msg_fsm('2'));
    t_eq($GLOBALS['__fake_criar_chamado_chamadas'], $chamadasAntes, 'criando=true: NAO abre um 2o chamado');
    t_ok(strpos(end($GLOBALS['__wpp_fake_send'])['texto'], 'aguarde') !== false, 'criando=true: avisa pra aguardar');
    wpp_chatbot_estado_limpar($tel);
} finally {
    $pdo->exec("DELETE FROM glpi_users WHERE name = 'teste_fsm_vinculado'");
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone LIKE " . $pdo->quote($tel . '%'));
    $pdo->exec("DELETE FROM portal_wpp_chamados WHERE telefone = " . $pdo->quote($tel));
    // não deixa os fakes vazando pros outros test_*.php do mesmo processo
    $GLOBALS['__wpp_chatbot_enviar_fake']        = null;
    $GLOBALS['__wpp_chatbot_criar_chamado_fake'] = null;
}
