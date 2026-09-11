# WhatsApp Fase 3 — Etapa 2 (Fluxo A: abrir chamado, número vinculado) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um número de WhatsApp **vinculado** a um usuário GLPI consegue abrir um chamado ponta a ponta, só conversando com o bot — sem escolher loja/usuário, direto pro título. É o primeiro pedaço "de verdade" do chatbot (Etapa 1 só recebia e logava).

**Architecture:** Máquina de estados por telefone (`portal_wpp_conversas`, JSON), resolvida a cada mensagem que passa pelo guardrail de entrada (Etapa 1). Vínculo telefone→usuário GLPI por aba manual (`portal_wpp_vinculos`) + match automático por `glpi_users.phone`/`mobile`. Criação do chamado via GLPI REST, reusando o padrão Basic-auth de `agenda/criar_ticket.php`.

**Tech Stack:** PHP 8.2 + PDO (MariaDB `glpi2`), mini-harness de testes próprio (`wpp/tests/`), Bootstrap 5, GLPI REST API.

**Spec:** `Docs/superpowers/specs/2026-09-10-whatsapp-fase3-chatbot-design.md` (o mesmo spec da Etapa 1 — cobre a Fase 3 inteira; esta etapa implementa só o Fluxo A dele).

## Escopo desta etapa (recorte deliberado do spec completo)

O spec descreve o estado FINAL da Fase 3 (Fluxo A + B + C + pendências + lista interativa + imagem). Esta etapa entrega só o Fluxo A, com os seguintes recortes explícitos — cada um vira etapa própria depois:

- **Só número vinculado.** Número não reconhecido recebe 1 mensagem ("ainda não disponível") e a conversa **não fica presa em estado nenhum** — a próxima mensagem dele reavalia do zero. Fluxo B (loja→usuário→pendência) é a Etapa 3.
- **Sem menu numerado (1 Abrir / 2 Consultar / 3 Sair) ainda.** Só existe "abrir". O menu completo entra junto com a Etapa 4 (consulta), quando "2" passa a fazer sentido.
- **Sem imagem.** Mensagem com mídia e sem texto recebe um aviso ("ainda não consigo processar imagem") — Etapa 5 implementa o anexo de verdade.
- **Sem lista interativa.** Fluxo A não tem escolha de loja/usuário (é vinculado, pula direto pro título), então a lista interativa (`evo_send_list`, Decisão #4 do spec) só entra na Etapa 3. O Spike 0 (pendente, precisa do celular de alguém) também só é relevante a partir daí.
- **`portal_wpp_chamados.origem`** já é criada com o ENUM completo (`'vinculado','pendencia'`) — só `'vinculado'` é gravado nesta etapa, pra não precisar de `ALTER TABLE` na Etapa 3.
- **Aba Vínculos** entra nesta etapa (é o que faz "vinculado" existir). **Aba Pendências** é da Etapa 3.

## Global Constraints

- Todo arquivo `wpp/*.php` novo/tocado (exceto `wpp/webhook.php`, entrypoint HTTP já existente): sem HTML, funções isoladas, retorno em array/escalar, **nunca lança exceção**.
- Comentários no código em português.
- `wpp_chatbot_processar()` é chamado de dentro de `wpp/webhook.php` (que já garante 200 sempre) — mesmo assim, todo o corpo de `wpp_chatbot_processar` fica em try/catch próprio (defesa em profundidade — um bug no chatbot não pode nem arriscar o "sempre 200").
- Toda resposta do bot sai por `evo_send_text()` (já existe, Fase 1/2) — que passa pelo guardrail de saída `wpp_destino_permitido()`. **Esta etapa estende esse guardrail** (Task 2) pra aceitar um número que tenha linha ativa em `portal_wpp_conversas` — sem isso a primeira resposta do bot pra um número não cadastrado em `portal_wpp_contatos`/`portal_wpp_autorizados` seria bloqueada pelo próprio guardrail de saída.
- Testes: mini-harness do projeto (`wpp/tests/assert.php`), sem PHPUnit. Rodam com `php wpp/tests/run.php` — localmente, contra o mesmo banco Docker descartável usado na Etapa 1 (rede `wpp-sdd-net`, container `wpp-sdd-glpi-db` alias `glpi-db`, imagem `wpp-sdd-php:local`; recriar se não existirem mais — ver README do repo/ledger da Etapa 1 pros comandos exatos).
- **Diferença desta etapa: o banco local de teste PRECISA de uma tabela `glpi_users` mínima** (funções desta etapa fazem `JOIN`/`SELECT` nela). Já foi criada nesse banco descartável:
  ```sql
  CREATE TABLE IF NOT EXISTS glpi_users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) DEFAULT NULL,
      realname VARCHAR(255) DEFAULT NULL,
      firstname VARCHAR(255) DEFAULT NULL,
      phone VARCHAR(255) DEFAULT NULL,
      mobile VARCHAR(255) DEFAULT NULL,
      entities_id INT NOT NULL DEFAULT 0,
      is_active TINYINT NOT NULL DEFAULT 1,
      is_deleted TINYINT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  Se o banco de teste for recriado do zero, rode isso uma vez antes das tasks que dependem de `glpi_users` (Tasks 3, 8). **Nunca rode esse `CREATE TABLE` contra o servidor real** — lá `glpi_users` já existe, com o schema de verdade do GLPI.
- `bot_criar_chamado()` (Task 4) fala com a API REST do GLPI de verdade — como `agenda/criar_ticket.php`, **não tem teste automatizado da chamada de rede** (convenção já estabelecida no projeto: endpoints/glue que fala com o GLPI real não é unit-testado). A parte testável (montar o payload) é extraída numa função pura. Verificação real é no deploy (Task 9).

---

## Task 1: Tabelas + config da Etapa 2

**Files:**
- Modify: `wpp/db.php` (fim do arquivo)
- Test: Create `wpp/tests/test_db_fase3_etapa2.php`

**Interfaces:**
- Produces: tabelas `portal_wpp_conversas`, `portal_wpp_vinculos`, `portal_wpp_chamados`. Consumidas por `wpp/chatbot.php` (Tasks 3, 5) e `config_whatsapp.php` (Task 8).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_db_fase3_etapa2.php`:

```php
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
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run (mesmo comando Docker da Etapa 1 — ver Global Constraints):
```
php /scratch/run_scoped.php wpp/tests/test_db_fase3_etapa2.php
```
Expected: erro de tabela inexistente (`Base table or view not found`) na primeira query.

- [ ] **Step 3: Implementar** — no fim de `wpp/db.php`:

```php
// --- Fase 3 Etapa 2: chatbot (FSM + vínculo + histórico de chamados) ---

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_conversas (
    telefone VARCHAR(20) PRIMARY KEY,
    estado JSON NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_vinculos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    glpi_user_id INT NOT NULL,
    rotulo VARCHAR(120) DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tel (telefone),
    KEY idx_user (glpi_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_chamados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    ticket_id INT NOT NULL,
    origem ENUM('vinculado','pendencia') NOT NULL,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uq_tel_ticket (telefone, ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
```

Novas chaves em `portal_wpp_config` (sem `CREATE`, só documentação — `wpp_cfg_get` já lida com default): `chatbot_timeout_min` (default `'5'`, lido pela Task 7).

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `3 ok, 0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/db.php wpp/tests/test_db_fase3_etapa2.php
git commit -m "feat: tabelas da Etapa 2 - conversas, vinculos, chamados"
```

---

## Task 2: Guardrail de saída aceita número com conversa ativa

**Files:**
- Modify: `wpp/guardrails.php` (função `wpp_destino_permitido`, já existe)
- Test: Modify `wpp/tests/test_guardrails.php` (adiciona casos, não recria o arquivo)

**Interfaces:**
- Consumes: tabela `portal_wpp_conversas` (Task 1).
- Produces: `wpp_destino_permitido()` passa a aceitar destino com conversa ativa. Consumida implicitamente por toda chamada de `evo_send_text()` que a FSM (Task 5) fizer.

- [ ] **Step 1: Ler o teste atual e adicionar os novos casos**

`wpp/tests/test_guardrails.php` já existe (Fase 1/2) e roda dentro de um `try { ... } finally { limpeza }`. Adicionar, DENTRO do bloco `try` existente (não criar um novo bloco try/finally — reusar o já existente, só acrescentando fixtures e asserts):

```php
// --- Etapa 2: numero com conversa ativa vira destino permitido ---
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
$pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW())")
    ->execute(['5567666660000']);

t_ok(wpp_destino_permitido('5567666660000'), 'numero com conversa ativa: ok');
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
t_ok(!wpp_destino_permitido('5567666660000'), 'mesmo numero, conversa apagada: BLOQUEADO de novo');
```

E, no bloco `finally` existente, acrescentar a linha de limpeza (redundante com a de cima, mas garante que não fica lixo se um assert lançar no meio):
```php
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone = '5567666660000'");
```

- [ ] **Step 2: Rodar e confirmar que falha**

```
php /scratch/run_scoped.php wpp/tests/test_guardrails.php
```
Expected: `FAIL numero com conversa ativa: ok` (a função ainda não conhece `portal_wpp_conversas`).

- [ ] **Step 3: Implementar** — em `wpp/guardrails.php`, dentro de `wpp_destino_permitido()`, o bloco que hoje é:

```php
        global $pdo;
        $st = $pdo->prepare(
            "SELECT 1 FROM portal_wpp_contatos WHERE telefone = ? AND ativo = 1
             UNION SELECT 1 FROM portal_wpp_autorizados WHERE telefone = ? AND ativo = 1 LIMIT 1"
        );
        $st->execute([$tel, $tel]);
        if ($st->fetchColumn()) {
            return true;
        }
```

vira (acrescenta `portal_wpp_conversas` na UNION e o 3º parâmetro):

```php
        global $pdo;
        $st = $pdo->prepare(
            "SELECT 1 FROM portal_wpp_contatos WHERE telefone = ? AND ativo = 1
             UNION SELECT 1 FROM portal_wpp_autorizados WHERE telefone = ? AND ativo = 1
             UNION SELECT 1 FROM portal_wpp_conversas WHERE telefone = ? LIMIT 1"
        );
        $st->execute([$tel, $tel, $tel]);
        if ($st->fetchColumn()) {
            return true;
        }
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas` no arquivo.

- [ ] **Step 5: Commit**

```bash
git add wpp/guardrails.php wpp/tests/test_guardrails.php
git commit -m "feat: guardrail de saida aceita numero com conversa ativa"
```

---

## Task 3: Estado da conversa + resolução de vínculo

**Files:**
- Create: `wpp/chatbot.php`
- Test: Create `wpp/tests/test_chatbot_estado.php`

**Interfaces:**
- Produces:
  - `wpp_chatbot_estado_get(string $telefone): ?array`
  - `wpp_chatbot_estado_set(string $telefone, array $estado): void`
  - `wpp_chatbot_estado_limpar(string $telefone): void`
  - `wpp_chatbot_resolve_vinculo(string $telefone): ?array` — `null`, ou `['glpi_user_id'=>int,'entities_id'=>int,'nome'=>string]`.
  Todas consumidas pela FSM (Task 5).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_chatbot_estado.php`:

```php
<?php
// Testes de estado de conversa + resolução de vínculo. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
global $pdo;

$tel = 'TESTE_CHATBOT_' . bin2hex(random_bytes(4));

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
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Failed opening required '.../wpp/chatbot.php'` (arquivo ainda não existe).

- [ ] **Step 3: Implementar** — criar `wpp/chatbot.php`:

```php
<?php
// wpp/chatbot.php — máquina de estados da conversa do WhatsApp (Fase 3).
// Sem HTML, funções isoladas, nunca lançam (o corpo de wpp_chatbot_processar
// tem seu próprio try/catch — ver Task 5).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guardrails.php'; // evo_send_text() (via evo_api.php) usa isto

// --- Estado da conversa (portal_wpp_conversas) ---

function wpp_chatbot_estado_get(string $telefone): ?array {
    global $pdo;
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
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), updated_at = NOW()"
    );
    $st->execute([$telefone, json_encode($estado, JSON_UNESCAPED_UNICODE)]);
}

function wpp_chatbot_estado_limpar(string $telefone): void {
    global $pdo;
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
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/chatbot.php wpp/tests/test_chatbot_estado.php
git commit -m "feat: estado de conversa + resolucao de vinculo telefone->usuario GLPI"
```

---

## Task 4: `wpp/glpi_bot.php` — criar chamado via GLPI REST

**Files:**
- Create: `wpp/glpi_bot.php`
- Test: Create `wpp/tests/test_glpi_bot.php`

**Interfaces:**
- Produces:
  - `bot_criar_chamado_payload(int $requerente_id, int $entities_id, string $titulo, string $descricao): array` — pura, testável.
  - `bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array` — `['ok'=>bool,'ticket_id'=>?int,'erro'=>?string]`. Fala com a rede, **não testada aqui** (ver Global Constraints).
  Consumidas pela FSM (Task 5).

- [ ] **Step 1: Escrever o teste (só da função pura)**

Criar `wpp/tests/test_glpi_bot.php`:

```php
<?php
// Teste da montagem do payload de criação de chamado (função pura, sem
// rede). A chamada real (bot_criar_chamado) é verificada no deploy — ver
// Global Constraints do plano da Etapa 2.
require_once __DIR__ . '/../glpi_bot.php';

$p = bot_criar_chamado_payload(42, 7, 'PC não liga', 'Tentei ligar e não acontece nada.');
t_eq($p['name'], 'PC não liga', 'payload: name = titulo');
t_eq($p['content'], 'Tentei ligar e não acontece nada.', 'payload: content = descricao');
t_eq($p['_users_id_requester'], 42, 'payload: requerente');
t_eq($p['entities_id'], 7, 'payload: entidade');
t_eq($p['type'], 1, 'payload: tipo Incidente');
t_eq($p['status'], 1, 'payload: status Novo (sem atendente)');

$p2 = bot_criar_chamado_payload(1, 1, 'Título', '');
t_eq($p2['content'], 'Título', 'payload: descricao vazia cai pro titulo');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Failed opening required '.../wpp/glpi_bot.php'`.

- [ ] **Step 3: Implementar** — criar `wpp/glpi_bot.php`:

```php
<?php
// wpp/glpi_bot.php — chamadas à API do GLPI usadas pelo chatbot. Sem HTML,
// funções isoladas, retorno em array, nunca lançam. Reusa o padrão
// Basic-auth de agenda/criar_ticket.php (sem token por usuário GLPI).
require_once __DIR__ . '/../agenda/config.php';

// Monta o array "input" do POST /apirest.php/Ticket. Pura, sem rede —
// testável sem precisar de um GLPI de verdade.
function bot_criar_chamado_payload(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    return [
        'name'                => $titulo,
        'content'             => $descricao !== '' ? $descricao : $titulo,
        'type'                => 1, // Incidente
        'urgency'             => 3,
        'priority'            => 3,
        'status'              => 1, // Novo — sem atendente ainda
        '_users_id_requester' => $requerente_id,
        'entities_id'         => $entities_id,
    ];
}

// Cria o chamado de verdade via REST. Nunca lança — qualquer falha de rede
// ou da API volta como ['ok'=>false,'erro'=>...].
function bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo, string $descricao): array {
    try {
        $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
        $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
        ]);
        $r = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $token = $r['session_token'] ?? '';
        if (!$token) {
            return ['ok' => false, 'ticket_id' => null, 'erro' => 'falha ao autenticar na API do GLPI'];
        }

        $headers = ['Content-Type: application/json', 'Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN];
        $input   = bot_criar_chamado_payload($requerente_id, $entities_id, $titulo, $descricao);

        $ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);

        // best-effort: encerra a sessão, sem deixar isso afetar o resultado
        $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch);
        curl_close($ch);

        if (!empty($res['id'])) {
            return ['ok' => true, 'ticket_id' => (int) $res['id'], 'erro' => null];
        }
        return ['ok' => false, 'ticket_id' => null, 'erro' => 'GLPI nao retornou id: ' . json_encode($res)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'ticket_id' => null, 'erro' => $e->getMessage()];
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/glpi_bot.php wpp/tests/test_glpi_bot.php
git commit -m "feat: wpp/glpi_bot.php - criar chamado via GLPI REST"
```

---

## Task 5: FSM completa (`wpp_chatbot_processar` e os passos)

**Files:**
- Modify: `wpp/chatbot.php` (fim do arquivo)
- Test: Create `wpp/tests/test_chatbot_fsm.php`

**Interfaces:**
- Consumes: `wpp_chatbot_estado_*`/`wpp_chatbot_resolve_vinculo` (Task 3), `bot_criar_chamado` (Task 4), `evo_send_text` (já existe).
- Produces: `wpp_chatbot_processar(string $telefone, array $msg): void` — chamada por `wpp/webhook.php` (Task 6). `$msg` é o array de `wpp_extrair_msg()` (Etapa 1): `['id','remoteJid','fromMe','timestamp','texto','temMidia']`.

Para testar sem rede real, este teste substitui `evo_send_text` por um contador — mesma técnica que `test_gatilhos.php` já usa (`$GLOBALS['__wpp_fake_send']`) pra não precisar da Evolution de verdade. **Leia `wpp/tests/test_gatilhos.php` linhas iniciais antes de implementar este step**, pra copiar exatamente o padrão de fake usado lá (nome da variável global, formato do array que ela acumula).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_chatbot_fsm.php`:

```php
<?php
// Testes da FSM do chatbot (Fluxo A - vinculado). Roda com `php wpp/tests/run.php`.
// Usa o mesmo fake de envio que test_gatilhos.php usa (ver aquele arquivo
// pra referência do padrão), pra não precisar da Evolution real nem do
// GLPI real: também substitui bot_criar_chamado por um fake local.
require_once __DIR__ . '/../chatbot.php';
require_once __DIR__ . '/../glpi_bot.php';
global $pdo;

$tel = 'TESTE_FSM_' . bin2hex(random_bytes(4));

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
    $telNaoVinc = $tel . '_nv';
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
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Call to undefined function wpp_chatbot_processar()`.

- [ ] **Step 3: Implementar** — no fim de `wpp/chatbot.php`:

```php
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
        evo_send_text($telefone, 'Ainda não consigo identificar seu número para abrir chamado por aqui. Peça pro TI te cadastrar, ou abra pelo portal.');
        wpp_chatbot_estado_limpar($telefone);
        return;
    }
    wpp_chatbot_estado_set($telefone, ['passo' => 'titulo', 'vinculo' => $vinculo, 'descricao' => '']);
    evo_send_text($telefone, "Abrir chamado para {$vinculo['nome']}. Qual o título?");
}

function wpp_chatbot_passo_titulo(string $telefone, array $estado, string $texto): void {
    if ($texto === '') {
        evo_send_text($telefone, 'O título não pode ficar vazio. Qual o título?');
        return;
    }
    $estado['passo']  = 'descricao';
    $estado['titulo'] = $texto;
    wpp_chatbot_estado_set($telefone, $estado);
    evo_send_text($telefone, 'Obrigado! Agora descreva o problema.');
}

function wpp_chatbot_passo_descricao(string $telefone, array $estado, string $texto, array $msg): void {
    if ($texto === '') {
        if (!empty($msg['temMidia'])) {
            evo_send_text($telefone, 'Ainda não consigo processar imagem por aqui — descreva o problema em texto, por favor.');
        } else {
            evo_send_text($telefone, 'Descreva o problema, por favor.');
        }
        return;
    }
    $estado['descricao'] = trim(($estado['descricao'] ?? '') . "\n" . $texto);
    $estado['passo']     = 'confirma_mais';
    wpp_chatbot_estado_set($telefone, $estado);
    evo_send_text($telefone, "Adicionar mais alguma coisa à descrição?\n1 - Sim\n2 - Não, finalizar\n3 - Cancelar");
}

function wpp_chatbot_passo_confirma_mais(string $telefone, array $estado, string $texto): void {
    switch ($texto) {
        case '1':
            $estado['passo'] = 'descricao';
            wpp_chatbot_estado_set($telefone, $estado);
            evo_send_text($telefone, 'Pode mandar mais informações.');
            break;
        case '2':
            wpp_chatbot_finalizar($telefone, $estado);
            break;
        case '3':
            wpp_chatbot_estado_limpar($telefone);
            evo_send_text($telefone, 'Abertura cancelada. Se precisar, é só chamar de novo.');
            break;
        default:
            evo_send_text($telefone, 'Resposta inválida. Digite 1, 2 ou 3.');
    }
}

function wpp_chatbot_finalizar(string $telefone, array $estado): void {
    global $pdo;
    $vinculo = $estado['vinculo'];
    $r = bot_criar_chamado(
        (int) $vinculo['glpi_user_id'],
        (int) $vinculo['entities_id'],
        (string) ($estado['titulo'] ?? ''),
        (string) ($estado['descricao'] ?? '')
    );
    wpp_chatbot_estado_limpar($telefone);

    if (!empty($r['ok'])) {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO portal_wpp_chamados (telefone, ticket_id, origem, criado_em) VALUES (?, ?, 'vinculado', NOW())"
        );
        $st->execute([$telefone, $r['ticket_id']]);
        evo_send_text($telefone, "✅ Chamado #{$r['ticket_id']} criado.");
    } else {
        evo_send_text($telefone, 'Não consegui criar o chamado agora (sistema indisponível). Tente de novo em alguns minutos.');
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/chatbot.php wpp/tests/test_chatbot_fsm.php
git commit -m "feat: FSM do chatbot - fluxo A completo (numero vinculado)"
```

---

## Task 6: Ligar o chatbot no `wpp/webhook.php`

**Files:**
- Modify: `wpp/webhook.php` (1 require + 1 linha trocada)

**Interfaces:**
- Consumes: `wpp_chatbot_processar()` (Task 5).

Sem teste novo — é 1 `require_once` + trocar uma chamada por outra num arquivo já testado via smoke HTTP na Etapa 1. Verificação é o smoke test do Step 3 abaixo.

- [ ] **Step 1: Adicionar o require**

Em `wpp/webhook.php`, junto dos outros `require_once` dentro do bloco de boot (`try` do topo, Etapa 1):

```php
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/guardrails.php';
    require_once __DIR__ . '/webhook_parse.php';
    require_once __DIR__ . '/chatbot.php';
```

(acrescenta só a última linha)

- [ ] **Step 2: Trocar a linha do stub**

Onde hoje tem (Etapa 1):

```php
                        if (wpp_origem_permitida($msg)) {
                            // Etapa 1: chatbot ainda não existe. Etapa 2 troca esta
                            // linha por: wpp_chatbot_processar($msg['remoteJid'], $msg);
                            wpp_log('in', $msg['remoteJid'], 'recebido (chatbot ainda nao implementado)', 'ok');
                        }
```

vira:

```php
                        if (wpp_origem_permitida($msg)) {
                            wpp_chatbot_processar($msg['remoteJid'], $msg);
                        }
```

Atualizar também o comentário do topo do arquivo (linhas que mencionam "Etapa 1: só valida a origem e loga... Etapa 2 troca...") pra refletir que o chatbot agora está ligado.

- [ ] **Step 3: Smoke test HTTP real (mesma técnica da Etapa 1)**

```bash
export MSYS_NO_PATHCONV=1
docker run --rm -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -l wpp/webhook.php
docker rm -f wpp-sdd-webserver-etapa2 >/dev/null 2>&1
docker run -d --rm --name wpp-sdd-webserver-etapa2 --network wpp-sdd-net -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -S 0.0.0.0:8099 -t /app
sleep 1
# liga on_chatbot=1 e cadastra um numero de teste vinculado (via aba manual, que ja existe da Task 3)
docker run --rm --network wpp-sdd-net -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -r '
require "wpp/db.php";
wpp_cfg_set("on_chatbot","1");
$pdo->exec("INSERT INTO glpi_users (name,realname,firstname,phone,mobile,entities_id,is_active,is_deleted) VALUES (\"smoke_etapa2\",\"Smoke\",\"Teste\",\"\",\"\",1,1,0)");
$id = $pdo->lastInsertId();
$pdo->exec("INSERT INTO portal_wpp_vinculos (telefone,glpi_user_id,rotulo,ativo) VALUES (\"5567000009999\",$id,\"smoke\",1)");
echo "vinculo id=$id\n";
'
TS=$(date +%s)
docker run --rm --network wpp-sdd-net curlimages/curl:8.10.1 -s -o /dev/null -w "HTTP %{http_code}\n" \
  -X POST http://wpp-sdd-webserver-etapa2:8099/wpp/webhook.php -H "Content-Type: application/json" \
  -d "{\"event\":\"messages.upsert\",\"data\":{\"key\":{\"remoteJid\":\"5567000009999@s.whatsapp.net\",\"fromMe\":false,\"id\":\"SMOKE-E2-1\"},\"message\":{\"conversation\":\"oi\"},\"messageTimestamp\":$TS}}"
docker run --rm --network wpp-sdd-net -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -r '
require "wpp/db.php";
$r = $pdo->query("SELECT estado FROM portal_wpp_conversas WHERE telefone=\"5567000009999\"")->fetchColumn();
echo "estado: " . var_export($r, true) . "\n";
'
# limpeza
docker run --rm --network wpp-sdd-net -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -r '
require "wpp/db.php";
$pdo->exec("DELETE FROM glpi_users WHERE name=\"smoke_etapa2\"");
$pdo->exec("DELETE FROM portal_wpp_vinculos WHERE telefone=\"5567000009999\"");
$pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone=\"5567000009999\"");
$pdo->exec("DELETE FROM portal_wpp_msgs_vistas WHERE message_id=\"SMOKE-E2-1\"");
wpp_cfg_set("on_chatbot","0");
'
docker rm -f wpp-sdd-webserver-etapa2 >/dev/null 2>&1
```

Expected: `HTTP 200`, e o `estado` impresso mostra `{"passo":"titulo",...}` — ou seja, o webhook real chamou a FSM de ponta a ponta (número vinculado, 1ª mensagem, avançou pro passo "titulo"). **Nota: `evo_send_text` de verdade vai tentar chamar a Evolution real** (não existe nesse ambiente Docker isolado) e vai falhar silenciosamente (a chamada de rede falha, `evo_guarded_send` loga o erro em `portal_wpp_log` e retorna `ok:false`, mas isso não derruba nada — é exatamente o comportamento esperado quando a Evolution não está acessível). Isso é aceitável pra este smoke test: o que importa aqui é confirmar que o **estado da conversa avançou**, não que a mensagem foi entregue de verdade (a entrega de verdade só é testável no deploy real, Task 9).

- [ ] **Step 4: Commit**

```bash
git add wpp/webhook.php
git commit -m "feat: liga o chatbot no webhook.php (Fluxo A)"
```

---

## Task 7: Worker — sweep de timeout das conversas

**Files:**
- Modify: `wpp/chatbot.php` (fim do arquivo)
- Modify: `wpp/worker.php` (novo passo + require)
- Test: Create `wpp/tests/test_chatbot_timeout.php`

**Interfaces:**
- Produces: `wpp_chatbot_sweep_timeouts(): void`, chamada pelo worker a cada passada.

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_chatbot_timeout.php`:

```php
<?php
// Testes do sweep de timeout de conversas. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../chatbot.php';
global $pdo;

$GLOBALS['__wpp_fake_send'] = [];
function evo_send_text(string $destino, string $texto): array {
    $GLOBALS['__wpp_fake_send'][] = ['destino' => $destino, 'texto' => $texto];
    return ['ok' => true];
}

$telVelha   = 'TESTE_TIMEOUT_VELHA_' . bin2hex(random_bytes(3));  // > 30 min: apaga calado
$telMedia   = 'TESTE_TIMEOUT_MEDIA_' . bin2hex(random_bytes(3));  // entre timeout e 30min: avisa e apaga
$telViva    = 'TESTE_TIMEOUT_VIVA_'  . bin2hex(random_bytes(3));  // recente: fica

try {
    wpp_cfg_set('chatbot_timeout_min', '5');

    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW() - INTERVAL 40 MINUTE)")->execute([$telVelha]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW() - INTERVAL 10 MINUTE)")->execute([$telMedia]);
    $pdo->prepare("INSERT INTO portal_wpp_conversas (telefone, estado, updated_at) VALUES (?, '{}', NOW())")->execute([$telViva]);

    $GLOBALS['__wpp_fake_send'] = [];
    wpp_chatbot_sweep_timeouts();

    t_ok(wpp_chatbot_estado_get($telVelha) === null, '> 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telMedia) === null, 'entre timeout e 30min: apagada');
    t_ok(wpp_chatbot_estado_get($telViva) !== null, 'recente: continua');

    $destinos = array_column($GLOBALS['__wpp_fake_send'], 'destino');
    t_ok(!in_array($telVelha, $destinos, true), '> 30min: NAO avisa (silencioso)');
    t_ok(in_array($telMedia, $destinos, true), 'entre timeout e 30min: avisa');
    t_ok(!in_array($telViva, $destinos, true), 'recente: nao recebe aviso nenhum');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE telefone IN (" . implode(',', array_map([$pdo, 'quote'], [$telVelha, $telMedia, $telViva])) . ")");
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Call to undefined function wpp_chatbot_sweep_timeouts()`.

- [ ] **Step 3: Implementar** — no fim de `wpp/chatbot.php`:

```php
// --- Sweep de timeout (chamado pelo worker a cada passada) ---

// Conversas paradas há mais de `chatbot_timeout_min` minutos são encerradas.
// Entre o timeout e 30min: avisa e apaga. Acima de 30min: apaga calado (o
// número já esfriou de verdade, não faz sentido mandar aviso tardio).
function wpp_chatbot_sweep_timeouts(): void {
    global $pdo;
    $timeoutMin = (int) wpp_cfg_get('chatbot_timeout_min', '5');

    $pdo->exec("DELETE FROM portal_wpp_conversas WHERE updated_at < NOW() - INTERVAL 30 MINUTE");

    $st = $pdo->prepare("SELECT telefone FROM portal_wpp_conversas WHERE updated_at < NOW() - INTERVAL ? MINUTE");
    $st->execute([$timeoutMin]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $telefone) {
        try {
            evo_send_text($telefone, '⏳ Tempo esgotado. A conversa foi encerrada — mande uma mensagem pra começar de novo.');
        } catch (\Throwable $e) {
            // segue mesmo se o envio falhar - a conversa tem que expirar de qualquer jeito
        }
        wpp_chatbot_estado_limpar($telefone);
    }
}
```

- [ ] **Step 4: Ligar no worker** — em `wpp/worker.php`, acrescentar o require no topo:

```php
require_once __DIR__ . '/chatbot.php';
```

E dentro de `wpp_worker_passada()`, logo depois do bloco "1. A instância está conectada?" (depois do `if (... !== 'open') { ...; return; }`) e antes do bloco "2. Reset por offline longo":

```php
    // 1.5. Sweep de timeout das conversas do chatbot (Fase 3 Etapa 2) - roda
    //      toda passada com a instância conectada, independente de baseline.
    try {
        wpp_chatbot_sweep_timeouts();
    } catch (\Throwable $e) {
        wpp_log('sys', '', 'chatbot sweep: ' . $e->getMessage(), 'erro');
    }
```

- [ ] **Step 5: Rodar e confirmar que passa**

Expected: `0 falhas`. Rodar também `php -l wpp/worker.php` (Docker) pra confirmar sintaxe.

- [ ] **Step 6: Commit**

```bash
git add wpp/chatbot.php wpp/worker.php wpp/tests/test_chatbot_timeout.php
git commit -m "feat: sweep de timeout das conversas do chatbot no worker"
```

---

## Task 8: Aba Vínculos em `config_whatsapp.php`

**Files:**
- Modify: `config_whatsapp.php` (novos `case`s, HTML, JS)

**Interfaces:**
- Consumes: `portal_wpp_vinculos` (Task 1), `glpi_users`.

Sem teste automatizado (UI, mesma convenção da aba Contatos/Gatilhos — verificação manual no deploy, Task 9). **Antes de editar, leia a aba Contatos inteira em `config_whatsapp.php`** (HTML + JS + os `case`s `contatos_listar`/`contatos_salvar`) — a aba Vínculos segue exatamente o mesmo padrão visual e de AJAX, só troca "1 usuário fixo (técnico)" por "N números por usuário (qualquer usuário GLPI)".

- [ ] **Step 1: `case`s novos** — junto dos outros `case`s de aba (perto de `contatos_listar`/`contatos_salvar`):

```php
        case 'vinculos_buscar_usuario':
            $q = trim((string) ($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'usuarios' => []]); exit; }
            try {
                $st = $pdo->prepare(
                    "SELECT id, realname, firstname, name
                     FROM glpi_users
                     WHERE is_active = 1 AND is_deleted = 0
                       AND (realname LIKE ? OR firstname LIKE ? OR name LIKE ?)
                     ORDER BY realname, firstname LIMIT 20"
                );
                $like = '%' . $q . '%';
                $st->execute([$like, $like, $like]);
                $usuarios = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nome = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
                    if ($nome === '') { $nome = (string) ($r['name'] ?? ''); }
                    $usuarios[] = ['id' => (int) $r['id'], 'nome' => $nome];
                }
                echo json_encode(['ok' => true, 'usuarios' => $usuarios]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao buscar usuário']);
            }
            break;
        case 'vinculos_listar':
            try {
                $sql = "SELECT v.id, v.telefone, v.glpi_user_id, v.rotulo, v.ativo,
                               COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS nome
                        FROM portal_wpp_vinculos v
                        JOIN glpi_users u ON u.id = v.glpi_user_id
                        ORDER BY nome, v.telefone";
                $linhas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'linhas' => $linhas]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao listar vínculos']);
            }
            break;
        case 'vinculos_salvar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $telefone = wpp_norm_telefone((string) ($_POST['telefone'] ?? ''));
            $uid      = (int) ($_POST['glpi_user_id'] ?? 0);
            $rotulo   = trim((string) ($_POST['rotulo'] ?? ''));
            $ativo    = (($_POST['ativo'] ?? '1') === '1') ? 1 : 0;
            if ($telefone === '' || strlen($telefone) < 10 || strlen($telefone) > 13) {
                echo json_encode(['ok' => false, 'erro' => 'telefone deve ter de 10 a 13 dígitos']);
                exit;
            }
            if ($uid <= 0) { echo json_encode(['ok' => false, 'erro' => 'escolha um usuário']); exit; }
            try {
                $st = $pdo->prepare(
                    "INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE glpi_user_id = VALUES(glpi_user_id), rotulo = VALUES(rotulo), ativo = VALUES(ativo)"
                );
                $st->execute([$telefone, $uid, $rotulo, $ativo]);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao salvar vínculo']);
            }
            break;
        case 'vinculos_remover':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id inválido']); exit; }
            try {
                $pdo->prepare("DELETE FROM portal_wpp_vinculos WHERE id = ?")->execute([$id]);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao remover']);
            }
            break;
```

- [ ] **Step 2: Aba nova na navegação** — no `<ul class="nav nav-tabs" id="wpp-tabs">`, acrescentar antes da aba Gatilhos:

```html
      <li class="nav-item"><span class="nav-link" data-tab="vinculos"><i class="bi bi-link-45deg me-1"></i>Vínculos</span></li>
```

E no JS que troca de aba (o bloco com `$('tab-conexao').style.display = ...`), acrescentar a linha correspondente:

```js
      $('tab-vinculos').style.display  = (alvo === 'vinculos')  ? '' : 'none';
```

- [ ] **Step 3: HTML da aba** — depois da `<!-- ─────────── Aba Contatos ─────────── -->` e antes da `<!-- ─────────── Aba Gatilhos ─────────── -->`:

```html
    <!-- ─────────── Aba Vínculos ─────────── -->
    <div class="tab-body" id="tab-vinculos" style="display:none">
      <p class="small text-muted mb-2">
        Números que pulam direto pro título ao abrir chamado pelo WhatsApp (sem escolher loja/usuário).
        Um usuário pode ter vários números — ex.: "SAC Loja X" com 3 celulares diferentes.
      </p>

      <div class="row g-2 mb-3">
        <div class="col-4">
          <input type="text" class="form-control form-control-sm" id="vinc-telefone" placeholder="Telefone (só dígitos)">
        </div>
        <div class="col-4" style="position:relative">
          <input type="text" class="form-control form-control-sm" id="vinc-busca-usuario" placeholder="Buscar usuário GLPI…" autocomplete="off">
          <div id="vinc-resultados" class="list-group" style="position:absolute; z-index:10; width:100%; max-height:200px; overflow:auto;"></div>
          <input type="hidden" id="vinc-usuario-id">
        </div>
        <div class="col-3">
          <input type="text" class="form-control form-control-sm" id="vinc-rotulo" placeholder="Rótulo (opcional)">
        </div>
        <div class="col-1">
          <button class="btn btn-success btn-sm w-100" id="btn-vinc-adicionar" style="background:var(--wpp);border-color:var(--wpp)">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tbl-vinculos">
          <thead>
            <tr>
              <th>Usuário</th>
              <th>Telefone</th>
              <th>Rótulo</th>
              <th class="text-center" style="width:4rem">Ativo</th>
              <th style="width:3rem"></th>
            </tr>
          </thead>
          <tbody id="tbody-vinculos">
            <tr><td colspan="5" class="text-muted">Carregando…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="feedback" id="fb-vinculos"></div>
    </div>
```

- [ ] **Step 4: JS da aba** — junto das outras seções de JS (padrão de `carregarContatos`/`renderContatos`/`salvarContato`):

```js
  /* ─────────── Vínculos ─────────── */
  var vinculosCarregados = false;
  var vincUsuarioSelecionado = null;

  function carregarVinculos() {
    feedback($('fb-vinculos'), 'info', 'Carregando…');
    fetch(PAGE + '?action=vinculos_listar')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao carregar'); }
        renderVinculos(d.linhas);
        vinculosCarregados = true;
        feedback($('fb-vinculos'), 'ok', 'Vínculos carregados.');
      })
      .catch(function (err) { feedback($('fb-vinculos'), 'err', 'Erro: ' + (err.message || err)); });
  }

  function renderVinculos(linhas) {
    var tbody = $('tbody-vinculos');
    tbody.innerHTML = '';
    if (!linhas.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-muted">Nenhum vínculo cadastrado.</td></tr>';
      return;
    }
    linhas.forEach(function (v) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + v.nome.replace(/</g, '&lt;') + '</td>' +
        '<td>' + v.telefone + '</td>' +
        '<td>' + (v.rotulo || '').replace(/</g, '&lt;') + '</td>' +
        '<td class="text-center"><input type="checkbox" class="form-check-input vinc-ativo" ' + (v.ativo == 1 ? 'checked' : '') + '></td>' +
        '<td><button class="btn btn-outline-danger btn-sm btn-vinc-remover"><i class="bi bi-trash"></i></button></td>';
      tr.querySelector('.vinc-ativo').addEventListener('change', function () {
        var params = new URLSearchParams();
        params.set('telefone', v.telefone);
        params.set('glpi_user_id', v.glpi_user_id);
        params.set('rotulo', v.rotulo || '');
        params.set('ativo', this.checked ? '1' : '0');
        fetch(PAGE + '?action=vinculos_salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) { feedback($('fb-vinculos'), d.ok ? 'ok' : 'err', d.ok ? 'Atualizado.' : (d.erro || 'erro')); });
      });
      tr.querySelector('.btn-vinc-remover').addEventListener('click', function () {
        if (!confirm('Remover o vínculo de ' + v.telefone + '?')) { return; }
        var params = new URLSearchParams();
        params.set('id', v.id);
        fetch(PAGE + '?action=vinculos_remover', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.ok) { carregarVinculos(); } else { feedback($('fb-vinculos'), 'err', d.erro || 'erro ao remover'); }
          });
      });
      tbody.appendChild(tr);
    });
  }

  var vincBuscaTimer = null;
  $('vinc-busca-usuario').addEventListener('input', function () {
    var termo = this.value;
    vincUsuarioSelecionado = null;
    $('vinc-usuario-id').value = '';
    clearTimeout(vincBuscaTimer);
    if (termo.trim().length < 2) { $('vinc-resultados').innerHTML = ''; return; }
    vincBuscaTimer = setTimeout(function () {
      fetch(PAGE + '?action=vinculos_buscar_usuario&q=' + encodeURIComponent(termo))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var box = $('vinc-resultados');
          box.innerHTML = '';
          (d.usuarios || []).forEach(function (u) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            item.textContent = u.nome;
            item.addEventListener('click', function () {
              vincUsuarioSelecionado = u;
              $('vinc-busca-usuario').value = u.nome;
              $('vinc-usuario-id').value = u.id;
              box.innerHTML = '';
            });
            box.appendChild(item);
          });
        });
    }, 300);
  });

  $('btn-vinc-adicionar').addEventListener('click', function () {
    var telefone = $('vinc-telefone').value.replace(/\D+/g, '');
    var uid = $('vinc-usuario-id').value;
    var rotulo = $('vinc-rotulo').value;
    if (!telefone || telefone.length < 10) { feedback($('fb-vinculos'), 'err', 'Telefone inválido.'); return; }
    if (!uid) { feedback($('fb-vinculos'), 'err', 'Escolha um usuário na busca.'); return; }
    var params = new URLSearchParams();
    params.set('telefone', telefone);
    params.set('glpi_user_id', uid);
    params.set('rotulo', rotulo);
    params.set('ativo', '1');
    fetch(PAGE + '?action=vinculos_salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { feedback($('fb-vinculos'), 'err', d.erro || 'erro ao salvar'); return; }
        $('vinc-telefone').value = ''; $('vinc-busca-usuario').value = ''; $('vinc-usuario-id').value = ''; $('vinc-rotulo').value = '';
        carregarVinculos();
      });
  });
```

E no despachante de troca de aba (junto do `if (alvo === 'gatilhos' && !gatilhosCarregados) { carregarGatilhos(); }`), acrescentar:

```js
      if (alvo === 'vinculos' && !vinculosCarregados) { carregarVinculos(); }
```

- [ ] **Step 5: Verificar sintaxe**

```bash
export MSYS_NO_PATHCONV=1
docker run --rm -v "C:/claude code/portal-glpi:/app" -w /app wpp-sdd-php:local php -l config_whatsapp.php
```

- [ ] **Step 6: Commit**

```bash
git add config_whatsapp.php
git commit -m "feat: aba Vinculos em config_whatsapp.php"
```

---

## Task 9: README + deploy

**Files:**
- Modify: `wpp/README.md` (nova seção "FASE 3 - ETAPA 2")

- [ ] **Step 1: Escrever a seção**

Seguir o mesmo formato das seções anteriores (`====`, `[ ]`, `----`). Conteúdo mínimo:

```
====================================================================
 FASE 3 - ETAPA 2 - FLUXO A (ABRIR CHAMADO, NUMERO VINCULADO)
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Numero vinculado (aba Vinculos OU telefone cadastrado no GLPI) que
    manda qualquer mensagem privada recebe: "Abrir chamado para
    [Nome]. Qual o titulo?" -> titulo -> descricao (com loop
    "adicionar mais?") -> cria o chamado de verdade no GLPI.
  * Numero NAO vinculado recebe 1 mensagem ("ainda nao disponivel") e
    NAO fica com conversa presa - fluxo completo (loja/usuario/
    pendencia) e Etapa 3.
  * Worker (a cada passada) encerra conversas paradas ha mais de
    chatbot_timeout_min (default 5min): avisa se for recente, apaga
    calado se for > 30min.

ARQUIVOS A SINCRONIZAR
----
  - wpp/db.php
  - wpp/guardrails.php
  - wpp/chatbot.php (novo)
  - wpp/glpi_bot.php (novo)
  - wpp/webhook.php
  - wpp/worker.php
  - config_whatsapp.php
  - wpp/tests/ (arquivos novos)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] Etapa 1 em produção (webhook ativo, on_chatbot ligavel).
[ ] Cadastrar pelo menos 1 numero na aba Vinculos (ou confirmar que
    algum usuario GLPI ja tem celular/telefone cadastrado) - senao
    nao tem como testar o Fluxo A de ponta a ponta.

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas" (ignorando falhas pre-existentes de outras
       features nao relacionadas a esta etapa, se houver).
[ ] 3. Aba Vinculos -> cadastra o numero de teste.
[ ] 4. on_chatbot ja deve estar ligado (Etapa 1). Se nao, aba
       Gatilhos -> liga.

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. Do numero cadastrado na aba Vinculos, manda "oi" pro WhatsApp
       do TI.
[ ] 2. Deve receber: "Abrir chamado para [Nome]. Qual o titulo?"
[ ] 3. Responde com um titulo de teste.
[ ] 4. Deve receber: "Obrigado! Agora descreva o problema."
[ ] 5. Responde com uma descricao de teste.
[ ] 6. Deve receber o menu "Adicionar mais? 1/2/3".
[ ] 7. Responde "2".
[ ] 8. Deve receber "Chamado #N criado." - confere no GLPI que o
       chamado existe, com o titulo/descricao certos, requerente =
       o usuario vinculado.
[ ] 9. De um numero QUALQUER (nao vinculado), manda "oi" -> deve
       receber a mensagem de "ainda nao disponivel" e NADA mais (sem
       ficar esperando resposta).
[ ] 10. Comeca um fluxo de novo e espera mais de chatbot_timeout_min
        minutos sem responder -> deve receber "Tempo esgotado".

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desliga "Chatbot de entrada". O webhook continua
recebendo e logando (comportamento da Etapa 1), so para de processar
qualquer fluxo.
```

- [ ] **Step 2: Commit**

```bash
git add wpp/README.md
git commit -m "docs: runbook Fase 3 Etapa 2 (Fluxo A)"
```

---

## Depois desta etapa

Etapa 3 (Fluxo B — não vinculado, escolha de loja/usuário, pendência de aprovação + aba Pendências) ganha plano próprio, escrito depois desta rodar em produção — mesmo padrão das etapas anteriores.
