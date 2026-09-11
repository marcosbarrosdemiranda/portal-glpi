# WhatsApp Fase 3 — Etapa 3 (Menu + loja/setor sempre + confirmação de vínculo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reformar o início do Fluxo A com base no teste real (Etapa 2 já em produção): todo técnico vinculado escolhe loja → setor/usuário sempre (não pula mais pro título); vínculo tipo loja/departamento (ex. "SAC Santos Bonito") ganha uma confirmação sim/não antes de pular; menu inicial (Abrir/Consultar/Sair) entra em produção; lista interativa fica descartada (spike falhou) — só menu numerado.

**Architecture:** Estende a FSM existente (`wpp/chatbot.php`, Etapa 2) com 4 passos novos (`menu`, `confirma_loja`, `escolhe_loja`, `escolhe_usuario`) antes do já existente `titulo`. Duas funções de consulta ao GLPI novas em `wpp/glpi_bot.php` (lojas, usuários da loja) reusando os helpers de nome já existentes em `entidade_alias.php`. Tipo de vínculo (pessoal vs. loja) é inferido pelo perfil GLPI (`glpi_profiles_users.profiles_id = 4` = técnico), sem campo novo na aba Vínculos.

**Tech Stack:** PHP 8.2 + PDO (MariaDB `glpi2`), mini-harness de testes próprio (`wpp/tests/`).

**Spec:** `Docs/superpowers/specs/2026-09-10-whatsapp-fase3-chatbot-design.md` (seção "Revisão 2026-09-11", que supersede as Decisões #2 e #4 originais).

## Escopo desta etapa (recorte)

- **Número sem vínculo nenhum continua recebendo "ainda não disponível"** (comportamento da Etapa 2, sem mudança) — o picker de loja/usuário só é alcançado por número **vinculado** (pessoal/técnico, ou loja/departamento depois de confirmar "Não"). Isso preserva a proteção anti-abuso até a pendência de aprovação (Decisão #3) ser implementada numa etapa futura — deixar não-vinculado usar o mesmo picker removeria essa proteção sem repô-la.
- **Consulta** ("2" no menu) continua sendo só "em breve" — Etapa 4.
- **Sem lista interativa** — Spike 0 rodado em 2026-09-11 falhou (erro interno da Evolution, `evoapicloud/evolution-api:latest` sem versão pinada). Só menu numerado.
- **Sem imagem** — Etapa 5, sem mudança.

## Global Constraints

- Mesmas regras da Etapa 1/2: `wpp/*.php` sem HTML, funções isoladas, nunca lançam exceção; comentários em português; toda resposta do bot via `wpp_chatbot_enviar()` (não `evo_send_text()` direto — ver comentário no topo de `wpp/chatbot.php`); **enviar sempre ANTES de limpar a conversa** (achado crítico da Etapa 2 — a permissão de saída do guardrail depende da linha em `portal_wpp_conversas` ainda existir no momento do envio).
- Testes: `wpp/tests/assert.php`, sem PHPUnit. Ambiente local: mesmo banco Docker descartável das etapas anteriores (rede `wpp-sdd-net`, container `wpp-sdd-glpi-db` alias `glpi-db`, imagem `wpp-sdd-php:local`). **Duas tabelas-stub novas já foram criadas nesse banco descartável** (só lá, nunca em servidor real, onde essas tabelas já existem com o schema de verdade do GLPI):
  ```sql
  CREATE TABLE IF NOT EXISTS glpi_entities (
      id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), completename VARCHAR(255),
      level INT NOT NULL DEFAULT 1
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  CREATE TABLE IF NOT EXISTS glpi_profiles_users (
      id INT AUTO_INCREMENT PRIMARY KEY, users_id INT NOT NULL, profiles_id INT NOT NULL,
      entities_id INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
- Reusa os helpers de nome já existentes em `entidade_alias.php` (raiz do portal): `apelido_entidade(string $completename): string` (ex. ".../Supermercado Santos - BTO" → "Lj 001") e `nome_requerente(string $nome): string` (GLPI guarda "Sobrenome Nome" — essa função devolve "Nome Sobrenome"). **Não reimplementar** — `require_once` o arquivo.
- Índices de escolha numérica (`escolhe_loja`/`escolhe_usuario`) são **1-based** na mensagem ("1 – Loja X") mas o array `opcoes` salvo no estado é 0-based — sempre `((int)$texto) - 1` pra indexar.

---

## Task 1: `wpp/glpi_bot.php` — consultas de loja/usuário/perfil

**Files:**
- Modify: `wpp/glpi_bot.php` (fim do arquivo)
- Test: Create `wpp/tests/test_glpi_bot_lojas.php`

**Interfaces:**
- Produces: `bot_lojas(): array` (`[['id'=>int,'nome'=>string], ...]`), `bot_usuarios_loja(int $entities_id): array` (mesmo shape), `bot_entidade_nome(int $entities_id): string`, `bot_perfil_tecnico(int $glpi_user_id): bool`. Todas consumidas por `wpp/chatbot.php` (Task 2).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_glpi_bot_lojas.php`:

```php
<?php
// Testes das consultas de loja/usuário/perfil (Etapa 3). Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../glpi_bot.php';
global $pdo;

$pdo->exec("DELETE FROM glpi_entities WHERE name LIKE 'teste_e3_%'");
$pdo->exec("DELETE FROM glpi_users WHERE name LIKE 'teste_e3_%'");
$pdo->exec("DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name LIKE 'teste_e3_%')");

try {
    // raiz (level 1) — NÃO deve aparecer em bot_lojas()
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_raiz', 'Entidade raiz > teste_e3_raiz', 1)");
    // 2 lojas (level 2) — devem aparecer, em ordem alfabética de completename
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_lojaB', 'Entidade raiz > teste_e3_raiz > teste_e3_lojaB', 2)");
    $pdo->exec("INSERT INTO glpi_entities (name, completename, level) VALUES ('teste_e3_lojaA', 'Entidade raiz > teste_e3_raiz > teste_e3_lojaA', 2)");
    $idLojaA = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_lojaA'")->fetchColumn();
    $idLojaB = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_lojaB'")->fetchColumn();

    $lojas = bot_lojas();
    $nomesLojas = array_column($lojas, 'nome');
    $idsLojas = array_column($lojas, 'id');
    t_ok(in_array($idLojaA, $idsLojas, true), 'bot_lojas: loja A (level 2) aparece');
    t_ok(in_array($idLojaB, $idsLojas, true), 'bot_lojas: loja B (level 2) aparece');
    // raiz (level 1) não deve estar na lista
    $raizId = (int) $pdo->query("SELECT id FROM glpi_entities WHERE name='teste_e3_raiz'")->fetchColumn();
    t_ok(!in_array($raizId, $idsLojas, true), 'bot_lojas: entidade raiz (level 1) NAO aparece');

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
```

- [ ] **Step 2: Rodar e confirmar que falha**

```
php /scratch/run_scoped.php wpp/tests/test_glpi_bot_lojas.php
```
Expected: `Call to undefined function bot_lojas()`.

- [ ] **Step 3: Implementar** — no fim de `wpp/glpi_bot.php`:

```php
require_once __DIR__ . '/../entidade_alias.php'; // apelido_entidade(), nome_requerente()

// Lojas pra o picker do chatbot: entidades filhas de verdade (level > 1 —
// exclui a raiz/holding), com o apelido curto já aplicado.
function bot_lojas(): array {
    global $pdo;
    $st = $pdo->query("SELECT id, completename FROM glpi_entities WHERE id > 0 AND level > 1 ORDER BY completename");
    $lojas = [];
    foreach ($st->fetchAll() as $r) {
        $lojas[] = ['id' => (int) $r['id'], 'nome' => apelido_entidade($r['completename'])];
    }
    return $lojas;
}

// Usuários ativos de uma loja, pro picker depois de escolher a entidade.
function bot_usuarios_loja(int $entities_id): array {
    global $pdo;
    $st = $pdo->prepare(
        "SELECT id, realname, firstname, name FROM glpi_users
         WHERE is_active = 1 AND is_deleted = 0 AND entities_id = ?
         ORDER BY realname, firstname"
    );
    $st->execute([$entities_id]);
    $usuarios = [];
    foreach ($st->fetchAll() as $r) {
        $nome = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
        if ($nome === '') {
            $nome = (string) ($r['name'] ?? '');
        }
        $usuarios[] = ['id' => (int) $r['id'], 'nome' => nome_requerente($nome)];
    }
    return $usuarios;
}

// Nome curto da entidade, pro texto de confirmação do vínculo tipo loja.
function bot_entidade_nome(int $entities_id): string {
    global $pdo;
    $st = $pdo->prepare("SELECT completename FROM glpi_entities WHERE id = ?");
    $st->execute([$entities_id]);
    $cn = $st->fetchColumn();
    return $cn !== false ? apelido_entidade((string) $cn) : ('Entidade #' . $entities_id);
}

// true se o usuário GLPI tem o perfil "técnico" (profiles_id=4 — mesmo
// critério já usado na aba Contatos de config_whatsapp.php).
function bot_perfil_tecnico(int $glpi_user_id): bool {
    global $pdo;
    $st = $pdo->prepare("SELECT 1 FROM glpi_profiles_users WHERE users_id = ? AND profiles_id = 4 LIMIT 1");
    $st->execute([$glpi_user_id]);
    return (bool) $st->fetchColumn();
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/glpi_bot.php wpp/tests/test_glpi_bot_lojas.php
git commit -m "feat: bot_lojas/bot_usuarios_loja/bot_entidade_nome/bot_perfil_tecnico"
```

---

## Task 2: FSM — menu, confirma_loja, escolhe_loja, escolhe_usuario

**Files:**
- Modify: `wpp/chatbot.php` (troca `wpp_chatbot_iniciar`, acrescenta 4 funções de passo, atualiza o `switch` de `wpp_chatbot_processar`, ajusta o nome no `resolve_vinculo`)
- Test: Create `wpp/tests/test_chatbot_menu.php`

**Interfaces:**
- Consumes: `bot_lojas()`, `bot_usuarios_loja()`, `bot_entidade_nome()`, `bot_perfil_tecnico()` (Task 1).
- Produces: os novos passos (`menu`, `confirma_loja`, `escolhe_loja`, `escolhe_usuario`) no `estado.passo`.

**Antes de editar, leia o `wpp/chatbot.php` atual inteiro** — esta task troca o corpo de `wpp_chatbot_iniciar()` (hoje ela resolve vínculo direto; passa a só mandar o menu) e acrescenta 4 funções novas. As funções de `titulo`/`descricao`/`confirma_mais`/`finalizar` (Etapa 2) **não mudam**.

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_chatbot_menu.php`:

```php
<?php
// Testes do menu + picker de loja/usuário (Etapa 3). Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
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
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, mobile, entities_id, is_active, is_deleted) VALUES ('teste_menu_tecnico', 'Sobrenome', 'Tecnico', ?, 0, 1, 0)");
    $stmt = $pdo->prepare("UPDATE glpi_users SET mobile = ? WHERE name = 'teste_menu_tecnico'");
    $stmt->execute([$telTecnico]);
    $idTecnico = (int) $pdo->query("SELECT id FROM glpi_users WHERE name='teste_menu_tecnico'")->fetchColumn();
    $pdo->exec("INSERT INTO glpi_profiles_users (users_id, profiles_id) VALUES ($idTecnico, 4)");

    // fixture: usuario "loja/departamento" vinculado por telefone (SEM profiles_id=4)
    $pdo->exec("INSERT INTO glpi_users (name, realname, firstname, mobile, entities_id, is_active, is_deleted) VALUES ('teste_menu_sac', 'SAC', 'Teste', ?, $idLoja, 1, 0)");
    $stmt2 = $pdo->prepare("UPDATE glpi_users SET mobile = ? WHERE name = 'teste_menu_sac'");
    $stmt2->execute([$telLoja]);

    // --- técnico: "1" no menu -> vai direto pro picker de loja (nunca confirma) ---
    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_processar($telTecnico, _msg_menu('oi'));
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'menu', 'primeira msg: passo menu');
    wpp_chatbot_processar($telTecnico, _msg_menu('1'));
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'escolhe_loja', 'tecnico + "1": vai direto pro picker de loja (nunca confirma_loja)');

    wpp_chatbot_processar($telTecnico, _msg_menu('1')); // escolhe a 1a (unica) loja
    t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'escolhe_usuario', 'apos escolher loja: passo escolhe_usuario');
    t_eq(wpp_chatbot_estado_get($telTecnico)['entities_id'], $idLoja, 'entities_id da loja escolhida foi salvo');

    wpp_chatbot_processar($telTecnico, _msg_menu('1')); // escolhe o 1o (unico) usuario da loja
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
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: falha em `t_eq(wpp_chatbot_estado_get($telTecnico)['passo'], 'menu', ...)` — hoje a 1ª mensagem já resolve vínculo direto, não manda menu.

- [ ] **Step 3: Implementar**

**3a. Trocar `wpp_chatbot_iniciar()`** (hoje resolve vínculo direto — passa a só mandar o menu):

```php
// Primeira mensagem de uma conversa (sem estado salvo ainda): sempre
// começa pelo menu — a resolução de vínculo só acontece quando a pessoa
// escolhe "1" (ver wpp_chatbot_passo_menu).
function wpp_chatbot_iniciar(string $telefone, array $msg): void {
    wpp_chatbot_estado_set($telefone, ['passo' => 'menu']);
    wpp_chatbot_enviar($telefone, "O que você precisa?\n1 – Abrir chamado\n2 – Consultar chamado (em breve)\n3 – Sair");
}
```

**3b. Acrescentar as 4 funções novas** (antes ou depois de `wpp_chatbot_passo_titulo`, tanto faz):

```php
function wpp_chatbot_passo_menu(string $telefone, array $estado, string $texto): void {
    switch ($texto) {
        case '1':
            $vinculo = wpp_chatbot_resolve_vinculo($telefone);
            if ($vinculo === null) {
                // ainda sem pendência implementada — mesma resposta da Etapa 2.
                // Não entra no picker: só vínculo confirmado chega lá (mantém
                // a proteção anti-abuso até a pendência existir).
                wpp_chatbot_enviar($telefone, 'Ainda não consigo identificar seu número para abrir chamado por aqui. Peça pro TI te cadastrar, ou abra pelo portal.');
                wpp_chatbot_estado_limpar($telefone);
                return;
            }
            if (!bot_perfil_tecnico($vinculo['glpi_user_id'])) {
                // vínculo tipo loja/departamento: confirma antes de pular
                $estado = ['passo' => 'confirma_loja', 'vinculo' => $vinculo];
                wpp_chatbot_estado_set($telefone, $estado);
                $loja = bot_entidade_nome((int) $vinculo['entities_id']);
                wpp_chatbot_enviar($telefone, "Quer atendimento pra {$loja}, departamento {$vinculo['nome']}?\n1 – Sim\n2 – Não");
                return;
            }
            // vínculo pessoal/técnico: sempre escolhe loja, nunca pula
            wpp_chatbot_ir_para_escolha_loja($telefone);
            break;
        case '2':
            wpp_chatbot_enviar($telefone, 'Consulta ainda não está disponível — em breve! Se precisar, digite "1" pra abrir um chamado.');
            wpp_chatbot_estado_limpar($telefone);
            break;
        case '3':
            wpp_chatbot_enviar($telefone, 'Ok! Se precisar, é só chamar de novo.');
            wpp_chatbot_estado_limpar($telefone);
            break;
        default:
            wpp_chatbot_enviar($telefone, 'Não entendi. Digite 1, 2 ou 3.');
    }
}

function wpp_chatbot_ir_para_escolha_loja(string $telefone): void {
    $lojas = bot_lojas();
    if (empty($lojas)) {
        wpp_chatbot_enviar($telefone, 'Não consegui carregar as lojas agora. Tente de novo em alguns minutos.');
        wpp_chatbot_estado_limpar($telefone);
        return;
    }
    wpp_chatbot_estado_set($telefone, ['passo' => 'escolhe_loja', 'opcoes' => $lojas]);
    $msg = "Escolha a loja:\n";
    foreach ($lojas as $i => $l) {
        $msg .= ($i + 1) . " – {$l['nome']}\n";
    }
    wpp_chatbot_enviar($telefone, trim($msg));
}

function wpp_chatbot_passo_confirma_loja(string $telefone, array $estado, string $texto): void {
    switch ($texto) {
        case '1':
            $estado['passo']     = 'titulo';
            $estado['descricao'] = '';
            wpp_chatbot_estado_set($telefone, $estado);
            wpp_chatbot_enviar($telefone, "Abrir chamado para {$estado['vinculo']['nome']}. Qual o título?");
            break;
        case '2':
            wpp_chatbot_ir_para_escolha_loja($telefone);
            break;
        default:
            wpp_chatbot_enviar($telefone, 'Digite 1 pra Sim ou 2 pra Não.');
    }
}

function wpp_chatbot_passo_escolhe_loja(string $telefone, array $estado, string $texto): void {
    $idx    = ((int) $texto) - 1;
    $opcoes = $estado['opcoes'] ?? [];
    if ($texto === '' || !isset($opcoes[$idx])) {
        wpp_chatbot_enviar($telefone, 'Escolha inválida. Digite o número da loja.');
        return;
    }
    $loja     = $opcoes[$idx];
    $usuarios = bot_usuarios_loja((int) $loja['id']);
    if (empty($usuarios)) {
        wpp_chatbot_enviar($telefone, 'Essa loja não tem usuário cadastrado no GLPI. Escolha outra loja ou fale com o TI.');
        return; // continua no mesmo passo — pode escolher outra loja
    }
    wpp_chatbot_estado_set($telefone, ['passo' => 'escolhe_usuario', 'entities_id' => (int) $loja['id'], 'opcoes' => $usuarios]);
    $msg = "Escolha o setor/usuário:\n";
    foreach ($usuarios as $i => $u) {
        $msg .= ($i + 1) . " – {$u['nome']}\n";
    }
    wpp_chatbot_enviar($telefone, trim($msg));
}

function wpp_chatbot_passo_escolhe_usuario(string $telefone, array $estado, string $texto): void {
    $idx    = ((int) $texto) - 1;
    $opcoes = $estado['opcoes'] ?? [];
    if ($texto === '' || !isset($opcoes[$idx])) {
        wpp_chatbot_enviar($telefone, 'Escolha inválida. Digite o número do usuário.');
        return;
    }
    $usuario = $opcoes[$idx];
    $estado['vinculo'] = [
        'glpi_user_id' => (int) $usuario['id'],
        'entities_id'  => (int) $estado['entities_id'],
        'nome'         => $usuario['nome'],
    ];
    $estado['passo']     = 'titulo';
    $estado['descricao'] = '';
    unset($estado['opcoes']);
    wpp_chatbot_estado_set($telefone, $estado);
    wpp_chatbot_enviar($telefone, "Abrir chamado para {$usuario['nome']}. Qual o título?");
}
```

**3c. Atualizar o `switch` de `wpp_chatbot_processar()`** — acrescentar os 4 `case`s novos, ANTES de `case 'titulo':` (a ordem entre eles não importa):

```php
        switch ($estado['passo'] ?? '') {
            case 'menu':
                wpp_chatbot_passo_menu($telefone, $estado, $texto);
                break;
            case 'confirma_loja':
                wpp_chatbot_passo_confirma_loja($telefone, $estado, $texto);
                break;
            case 'escolhe_loja':
                wpp_chatbot_passo_escolhe_loja($telefone, $estado, $texto);
                break;
            case 'escolhe_usuario':
                wpp_chatbot_passo_escolhe_usuario($telefone, $estado, $texto);
                break;
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
                wpp_chatbot_estado_limpar($telefone);
                wpp_chatbot_iniciar($telefone, $msg);
        }
```

(só os 4 `case`s novos são acréscimo — os últimos 3 `case`s + `default` já existem, não mude o corpo deles)

**3d. Ajustar `wpp_chatbot_resolve_vinculo()`** — usar `nome_requerente()` no nome devolvido, pra não aparecer "Barros de Miranda Marcos" (ordem crua do GLPI) e sim "Marcos Barros de Miranda". Trocar as duas linhas que montam `'nome'`:

Onde hoje tem (aba de vínculos manual):
```php
                COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS nome
```
e (match automático):
```php
                COALESCE(NULLIF(TRIM(CONCAT(realname,' ',firstname)),''), name) AS nome
```
**não mude a SQL** — em vez disso, aplique `nome_requerente()` no PHP, depois de buscar a linha, nos dois pontos onde `'nome' => $row['nome']` é montado no array de retorno:
```php
        return ['glpi_user_id' => (int) $row['glpi_user_id'], 'entities_id' => (int) $row['entities_id'], 'nome' => nome_requerente($row['nome'])];
```
e o equivalente no bloco de match automático. Acrescentar `require_once __DIR__ . '/../entidade_alias.php';` no topo de `wpp/chatbot.php`, junto dos outros requires.

- [ ] **Step 4: Rodar e confirmar que passa**

```
php /scratch/run_scoped.php wpp/tests/test_chatbot_menu.php wpp/tests/test_chatbot_fsm.php wpp/tests/test_chatbot_estado.php wpp/tests/test_chatbot_timeout.php
```
Expected: `0 falhas` no conjunto (confirma que o menu novo não quebrou o Fluxo A da Etapa 2 — `titulo`/`descricao`/`confirma_mais`/`finalizar` continuam iguais).

- [ ] **Step 5: Commit**

```bash
git add wpp/chatbot.php wpp/tests/test_chatbot_menu.php
git commit -m "feat: menu inicial + picker de loja/usuario sempre pra vinculo pessoal + confirmacao pra vinculo tipo loja"
```

---

## Task 3: README + deploy

**Files:**
- Modify: `wpp/README.md` (nova seção "FASE 3 - ETAPA 3")

- [ ] **Step 1: Escrever a seção**

```
====================================================================
 FASE 3 - ETAPA 3 - MENU + LOJA/SETOR SEMPRE + CONFIRMACAO DE VINCULO
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Toda conversa nova comeca com o menu:
      1 - Abrir chamado
      2 - Consultar chamado (em breve)
      3 - Sair
  * "1" com numero vinculado a um TECNICO (profiles_id=4 no GLPI):
    sempre escolhe loja -> escolhe usuario da loja -> titulo ->
    descricao. NUNCA pula mais pro titulo direto.
  * "1" com numero vinculado a um usuario QUALQUER OUTRO PERFIL
    (ex: "SAC Santos Bonito"): pergunta "Quer atendimento pra
    <loja>, departamento <nome>? 1 Sim / 2 Nao". Sim pula pro
    titulo com esse vinculo; Nao cai no mesmo picker manual do
    tecnico.
  * "1" sem vinculo nenhum: mensagem de "ainda nao disponivel" -
    SEM MUDANCA da Etapa 2 (nao entra no picker - protecao contra
    abuso, ate a pendencia de aprovacao existir numa etapa futura).
  * "2"/"3" no menu: responde e nao fica com conversa presa.
  * Lista interativa (sendList) NAO e usada - o Spike 0 (rodado em
    2026-09-11) deu erro interno na Evolution atual. So menu
    numerado por agora.

ARQUIVOS A SINCRONIZAR
----
  - wpp/glpi_bot.php
  - wpp/chatbot.php
  - wpp/tests/ (arquivos novos: test_glpi_bot_lojas.php, test_chatbot_menu.php)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] Etapa 2 em produção.
[ ] Confirme que o perfil "tecnico" no GLPI e mesmo profiles_id=4
    (mesmo criterio ja usado na aba Contatos) - se a instalacao usar
    outro id, ajustar bot_perfil_tecnico() em wpp/glpi_bot.php antes
    de sincronizar.

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas" (ignorando a falha pre-existente e nao
       relacionada da Central de Alertas Etapa 2, se ainda presente).

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. De um numero vinculado a um TECNICO, manda "oi" -> recebe o
       menu -> "1" -> recebe lista de lojas (nunca pula pro titulo).
[ ] 2. Escolhe uma loja (numero) -> recebe lista de usuarios daquela
       loja.
[ ] 3. Escolhe um usuario -> recebe "Abrir chamado para <nome>. Qual
       o titulo?" -> segue o fluxo normal (titulo/descricao/2) ->
       confere no GLPI que o requerente e o USUARIO ESCOLHIDO, nao
       o tecnico.
[ ] 4. De um numero vinculado a um usuario QUE NAO E TECNICO, manda
       "oi" -> "1" -> recebe a pergunta de confirmacao com o nome da
       loja e do vinculo certos -> "1" (sim) -> pula pro titulo.
[ ] 5. Repete o passo 4 mas responde "2" (nao) na confirmacao ->
       cai no mesmo picker de loja do passo 1.
[ ] 6. De um numero SEM vinculo nenhum, "1" -> mensagem de
       indisponivel, sem picker.
[ ] 7. No menu, "2" -> mensagem de em breve, sem ficar preso.
[ ] 8. No menu, "3" -> mensagem de saida, sem ficar preso.

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desliga "Chatbot de entrada" (mesmo da Etapa 1/2).

====================================================================
 FIM - FASE 3 ETAPA 3
====================================================================
```

- [ ] **Step 2: Commit**

```bash
git add wpp/README.md
git commit -m "docs: runbook Fase 3 Etapa 3 (menu + loja/setor sempre)"
```

---

## Depois desta etapa

Pendência de aprovação pra número sem vínculo (Decisão #3 do spec, ainda não implementada), consulta de chamado (Etapa 4), imagem (Etapa 5) e lista interativa (só se a Evolution for atualizada) continuam como trabalho futuro, cada um com plano próprio.
