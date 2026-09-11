<?php
// wpp/chatbot.php — máquina de estados da conversa do WhatsApp (Fase 3).
// Sem HTML, funções isoladas, nunca lançam (o corpo de wpp_chatbot_processar
// tem seu próprio try/catch — ver Task 5).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guardrails.php';
// Propositalmente NÃO faz require de evo_api.php/glpi_bot.php aqui: quem
// chama este arquivo (wpp/webhook.php em produção, Task 6) é responsável
// por isso.

// --- Indireção pra permitir fake em teste sem redeclarar função global ---

// Todo envio/criação de chamado deste arquivo passa por estas duas funções.
// Assim os testes podem instalar um fake em $GLOBALS em vez de redeclarar
// evo_send_text()/bot_criar_chamado() no escopo global — o que dava
// "Cannot redeclare" quando wpp/tests/run.php carrega TODOS os test_*.php
// num só processo PHP (alguns requerem o arquivo de verdade, outros querem
// fakear).
function wpp_chatbot_enviar(string $telefone, string $texto): array {
    $fake = $GLOBALS['__wpp_chatbot_enviar_fake'] ?? null;
    return is_callable($fake) ? $fake($telefone, $texto) : evo_send_text($telefone, $texto);
}

function wpp_chatbot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    $fake = $GLOBALS['__wpp_chatbot_criar_chamado_fake'] ?? null;
    return is_callable($fake)
        ? $fake($requerente_id, $entities_id, $titulo, $descricao)
        : bot_criar_chamado($requerente_id, $entities_id, $titulo, $descricao);
}

// --- Estado da conversa (portal_wpp_conversas) ---

// A coluna telefone guarda SEMPRE só dígitos. Normalizar aqui dentro (e não
// só no call site do webhook.php) mantém o invariante válido pra qualquer
// chamador futuro — normalizar dígitos puros de novo é no-op.
function wpp_chatbot_estado_get(string $telefone): ?array {
    global $pdo;
    $telefone = wpp_norm_telefone($telefone);
    $st = $pdo->prepare("SELECT estado FROM portal_wpp_conversas WHERE telefone = ?");
    $st->execute([$telefone]);
    $v = $st->fetchColumn();
    if ($v === false) {
        return null;
    }
    $d = json_decode((string) $v, true);
    return is_array($d) ? $d : null;
}

function wpp_chatbot_estado_set(string $telefone, array $estado): void {
    global $pdo;
    $telefone = wpp_norm_telefone($telefone);
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), updated_at = NOW()"
    );
    $st->execute([$telefone, json_encode($estado, JSON_UNESCAPED_UNICODE)]);
}

function wpp_chatbot_estado_limpar(string $telefone): void {
    global $pdo;
    $telefone = wpp_norm_telefone($telefone);
    $st = $pdo->prepare("DELETE FROM portal_wpp_conversas WHERE telefone = ?");
    $st->execute([$telefone]);
}

// --- Vínculo telefone -> usuário GLPI ---

// null, ou ['glpi_user_id'=>int, 'entities_id'=>int, 'nome'=>string].
// Ordem: 1) aba de vínculos manual (ativo=1); 2) match automático por
// glpi_users.phone/mobile, só se achar EXATAMENTE 1 usuário ativo.
function wpp_chatbot_resolve_vinculo(string $telefone): ?array {
    global $pdo;
    $tel = wpp_norm_telefone($telefone);
    if ($tel === '') {
        return null;
    }

    $st = $pdo->prepare(
        "SELECT v.glpi_user_id, u.entities_id,
                COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS nome
         FROM portal_wpp_vinculos v
         JOIN glpi_users u ON u.id = v.glpi_user_id
         WHERE v.telefone = ? AND v.ativo = 1
           AND u.is_active = 1 AND u.is_deleted = 0
         LIMIT 1"
    );
    $st->execute([$tel]);
    $row = $st->fetch();
    if ($row) {
        return ['glpi_user_id' => (int) $row['glpi_user_id'], 'entities_id' => (int) $row['entities_id'], 'nome' => $row['nome']];
    }

    $st = $pdo->prepare(
        "SELECT id, entities_id,
                COALESCE(NULLIF(TRIM(CONCAT(realname,' ',firstname)),''), name) AS nome
         FROM glpi_users
         WHERE is_active = 1 AND is_deleted = 0
           AND (REGEXP_REPLACE(phone, '[^0-9]', '') = ? OR REGEXP_REPLACE(mobile, '[^0-9]', '') = ?)"
    );
    $st->execute([$tel, $tel]);
    $rows = $st->fetchAll();
    if (count($rows) === 1) {
        return ['glpi_user_id' => (int) $rows[0]['id'], 'entities_id' => (int) $rows[0]['entities_id'], 'nome' => $rows[0]['nome']];
    }

    return null; // nenhum match ou ambíguo (2+)
}

// --- FSM: ponto de entrada chamado pelo webhook.php ---

// Nunca lança — qualquer erro interno é logado e a conversa é preservada
// como estava (não trava, não perde o que já foi digitado).
function wpp_chatbot_processar(string $telefone, array $msg): void {
    try {
        $texto  = trim((string) ($msg['texto'] ?? ''));
        $estado = wpp_chatbot_estado_get($telefone);

        if ($estado === null) {
            wpp_chatbot_iniciar($telefone, $msg);
            return;
        }

        switch ($estado['passo'] ?? '') {
            case 'titulo':
                wpp_chatbot_passo_titulo($telefone, $estado, $texto);
                break;
            case 'descricao':
                wpp_chatbot_passo_descricao($telefone, $estado, $texto, $msg);
                break;
            case 'confirma_mais':
                wpp_chatbot_passo_confirma_mais($telefone, $estado, $texto);
                break;
            default:
                // estado desconhecido/corrompido: reinicia do zero
                wpp_chatbot_estado_limpar($telefone);
                wpp_chatbot_iniciar($telefone, $msg);
        }
    } catch (\Throwable $e) {
        wpp_log('sys', $telefone, 'chatbot erro: ' . $e->getMessage(), 'erro');
    }
}

// Primeira mensagem de uma conversa (sem estado salvo ainda).
function wpp_chatbot_iniciar(string $telefone, array $msg): void {
    $vinculo = wpp_chatbot_resolve_vinculo($telefone);
    if ($vinculo === null) {
        // Sem próximo passo: abre e fecha a conversa na mesma chamada, só pra
        // o guardrail de saída liberar esta ÚNICA resposta (número que
        // acabou de falar tem direito a 1 desfecho, mesmo sem vínculo).
        wpp_chatbot_estado_set($telefone, ['passo' => 'indisponivel']);
        wpp_chatbot_enviar($telefone, 'Ainda não consigo identificar seu número para abrir chamado por aqui. Peça pro TI te cadastrar, ou abra pelo portal.');
        wpp_chatbot_estado_limpar($telefone);
        return;
    }
    wpp_chatbot_estado_set($telefone, ['passo' => 'titulo', 'vinculo' => $vinculo, 'descricao' => '']);
    wpp_chatbot_enviar($telefone, "Abrir chamado para {$vinculo['nome']}. Qual o título?");
}

function wpp_chatbot_passo_titulo(string $telefone, array $estado, string $texto): void {
    if ($texto === '') {
        wpp_chatbot_enviar($telefone, 'O título não pode ficar vazio. Qual o título?');
        return;
    }
    $estado['passo']  = 'descricao';
    // GLPI trunca glpi_tickets.name em 255 — corta antes, com folga.
    $estado['titulo'] = mb_substr($texto, 0, 250);
    wpp_chatbot_estado_set($telefone, $estado);
    wpp_chatbot_enviar($telefone, 'Obrigado! Agora descreva o problema.');
}

function wpp_chatbot_passo_descricao(string $telefone, array $estado, string $texto, array $msg): void {
    if ($texto === '') {
        if (!empty($msg['temMidia'])) {
            wpp_chatbot_enviar($telefone, 'Ainda não consigo processar imagem por aqui — descreva o problema em texto, por favor.');
        } else {
            wpp_chatbot_enviar($telefone, 'Descreva o problema, por favor.');
        }
        return;
    }
    $estado['descricao'] = trim(($estado['descricao'] ?? '') . "\n" . $texto);
    $estado['passo']     = 'confirma_mais';
    wpp_chatbot_estado_set($telefone, $estado);
    wpp_chatbot_enviar($telefone, "Adicionar mais alguma coisa à descrição?\n1 - Sim\n2 - Não, finalizar\n3 - Cancelar");
}

function wpp_chatbot_passo_confirma_mais(string $telefone, array $estado, string $texto): void {
    // Reentrância: um 2º "2" (message.id diferente, o dedup da Etapa 1 não
    // pega) enquanto o POST do GLPI ainda está em voo (até ~40s) abriria um
    // 2º chamado. A flag vem persistida do estado, então a 2ª chamada do
    // webhook a enxerga.
    if (!empty($estado['criando'])) {
        wpp_chatbot_enviar($telefone, 'Já estou processando sua solicitação, aguarde.');
        return;
    }

    switch ($texto) {
        case '1':
            $estado['passo'] = 'descricao';
            wpp_chatbot_estado_set($telefone, $estado);
            wpp_chatbot_enviar($telefone, 'Pode mandar mais informações.');
            break;
        case '2':
            wpp_chatbot_finalizar($telefone, $estado);
            break;
        case '3':
            // Envia ANTES de limpar: pra número só-vinculado, a permissão de
            // saída do guardrail vem justamente da linha em
            // portal_wpp_conversas — limpar antes engoliria a mensagem.
            wpp_chatbot_enviar($telefone, 'Abertura cancelada. Se precisar, é só chamar de novo.');
            wpp_chatbot_estado_limpar($telefone);
            break;
        default:
            wpp_chatbot_enviar($telefone, 'Resposta inválida. Digite 1, 2 ou 3.');
    }
}

function wpp_chatbot_finalizar(string $telefone, array $estado): void {
    global $pdo;

    // Estado corrompido/incompleto (ex: linha mexida na mão): não dá pra
    // criar chamado nenhum — encerra limpo em vez de estourar.
    if (!is_array($estado['vinculo'] ?? null)) {
        wpp_chatbot_enviar($telefone, 'Algo deu errado com sua sessão. Mande uma mensagem pra começar de novo.');
        wpp_chatbot_estado_limpar($telefone);
        return;
    }
    $vinculo = $estado['vinculo'];

    // Marca "criando" ANTES do POST (spec): o webhook reentrante vê a flag e
    // não dispara um 2º chamado enquanto este ainda está em voo.
    $estado['criando'] = true;
    wpp_chatbot_estado_set($telefone, $estado);

    $r = wpp_chatbot_criar_chamado(
        (int) $vinculo['glpi_user_id'],
        (int) $vinculo['entities_id'],
        (string) ($estado['titulo'] ?? ''),
        (string) ($estado['descricao'] ?? '')
    );

    if (!empty($r['ok'])) {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO portal_wpp_chamados (telefone, ticket_id, origem, criado_em) VALUES (?, ?, 'vinculado', NOW())"
        );
        $st->execute([wpp_norm_telefone($telefone), $r['ticket_id']]);
        // Envia ANTES de limpar (ver comentário do case '3').
        wpp_chatbot_enviar($telefone, "✅ Chamado #{$r['ticket_id']} criado.");
        wpp_chatbot_estado_limpar($telefone);
    } else {
        // Falha do GLPI: MANTÉM a conversa (spec) pra o usuário não redigitar
        // título/descrição — um "2" novo tenta de novo. Só zera o criando.
        // A expiração fica por conta do sweep genérico de timeout.
        $estado['criando'] = false;
        $estado['passo']   = 'confirma_mais';
        wpp_chatbot_estado_set($telefone, $estado);
        wpp_chatbot_enviar($telefone, 'Não consegui criar o chamado agora (sistema indisponível). Seus dados foram guardados — responda "2" pra tentar de novo em alguns minutos.');
    }
}

// --- Sweep de timeout (chamado pelo worker a cada passada) ---

// Conversas paradas há mais de `chatbot_timeout_min` minutos são encerradas.
// Entre o timeout e 30min: avisa e apaga. Acima de 30min: apaga calado (o
// número já esfriou de verdade, não faz sentido mandar aviso tardio).
function wpp_chatbot_sweep_timeouts(): void {
    global $pdo;
    // Clamp [1,29]: config degenerada (0, negativa ou lixo) viraria
    // "INTERVAL 0 MINUTE" e derrubaria TODA conversa a cada passada, inclusive
    // as que acabaram de começar. 29 é o teto porque 30 é o corte do delete
    // silencioso logo abaixo — a janela de avisar-e-apagar tem que ficar
    // estritamente antes dele.
    $timeoutMin = max(1, min(29, (int) wpp_cfg_get('chatbot_timeout_min', '5')));
    // O aviso é uma feature do chatbot: com on_chatbot desligado (rollback)
    // ninguém pode mais receber mensagem nossa. A limpeza continua rodando.
    $avisar = wpp_cfg_get('on_chatbot', '0') === '1';

    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE updated_at < NOW() - INTERVAL 30 MINUTE");

    $st = $pdo->prepare("SELECT telefone FROM portal_wpp_conversas WHERE updated_at < NOW() - INTERVAL ? MINUTE");
    $st->execute([$timeoutMin]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $telefone) {
        if ($avisar) {
            try {
                wpp_chatbot_enviar($telefone, '⏳ Tempo esgotado. A conversa foi encerrada — mande uma mensagem pra começar de novo.');
            } catch (\Throwable $e) {
                // segue mesmo se o envio falhar - a conversa tem que expirar de qualquer jeito
            }
        }
        wpp_chatbot_estado_limpar($telefone);
    }
}
