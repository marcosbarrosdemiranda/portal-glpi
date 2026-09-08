# Integração WhatsApp do Portal — Fase 2 (Notificações de saída + guardrails) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O portal passa a **mandar** notificações no WhatsApp — chamado novo, chamado atribuído (DM), alertas operacionais e SLA — via um worker que roda em loop, com guardrails de destino e a regra dura de que **conectar/reconectar nunca dispara backlog**.

**Architecture:** Um container `portal-wpp-worker` roda `php wpp/worker.php` em loop (mesmo padrão do `glpi-cron`). O worker lê o GLPI por SQL direto (PDO no `glpi2`), compara com watermarks/baseline em `portal_wpp_config` e `portal_wpp_notificados`, e envia via `evo_send_text()` — que passa **obrigatoriamente** por `evo_guarded_send()` (só os 2 grupos configurados + contatos ativos). A tela `config_whatsapp.php` ganha 3 abas (Contatos, Gatilhos, Log). Nenhum webhook ainda (isso é Fase 3).

**Tech Stack:** PHP 8.2 (sem framework), PDO/MariaDB, Docker Compose, Evolution API v2 (REST, já no ar), Bootstrap 5 (CDN).

**Spec:** `Docs/superpowers/specs/2026-09-07-whatsapp-portal-design.md`

## Estado atual (Fase 1, já em produção)

- `evolution-api` + `evolution-db` (mariadb:11) no `docker-compose.yml`, rede `glpi-net`.
- Instância `portal_ti` pareada, estado `open`. Grupos já cadastrados em `portal_wpp_config`:
  `grupo_alertas_jid` = `120363412180101593@g.us`, `grupo_chamados_jid` = `120363430299976604@g.us`.
- `wpp/config.php` (gitignored): `EVO_URL`, `EVO_API_KEY`, `EVO_INSTANCE='portal_ti'`.
- `wpp/db.php`: tabela `portal_wpp_config (chave VARCHAR(64) PK, valor TEXT)` + `wpp_cfg_get(string,?string):?string` / `wpp_cfg_set(string,string):void`.
- `wpp/evo_api.php`: `evo_request`, `evo_status`, `evo_qr`, `evo_groups`, `evo_logout`, `evo_ensure_instance`. Nunca lançam; retornam array.
- `config_whatsapp.php`: guard de sessão + permissão `notificacoes_config`; abas Conexão e Grupos; `?action=` dispatch (GET pra leitura, POST pra `save_groups`/`logout`).
- Tabela do portal fica no database **`glpi2`** (mesmo do GLPI). `agenda/db.php` expõe `$pdo`.

## Global Constraints

- **Guardrail de saída (a regra mais importante):** todo envio passa por `evo_guarded_send()`. Destino só pode ser: (1) exatamente `grupo_alertas_jid` OU `grupo_chamados_jid` de `portal_wpp_config`, (2) número ativo em `portal_wpp_contatos`, (3) número ativo em `portal_wpp_autorizados` (essa 3ª só será usada na Fase 3, mas a função já aceita). Qualquer outro destino → **recusa + grava em `portal_wpp_log`**. Rejeita sempre: qualquer coisa terminando em `@broadcast`, `status@broadcast`, qualquer `@g.us` que não seja um dos 2 grupos, número não cadastrado.
- **Cold-start / reconexão (regra dura):**
  1. O worker mantém um *watermark* por gatilho em `portal_wpp_config`. Primeira execução de um gatilho (watermark ausente): grava watermark = `NOW()` do relógio do banco e **envia zero**.
  2. `portal_wpp_notificados` vazia OU flag `wpp_baseline_ok` ausente: o worker semeia TODOS os itens vigentes (chamados abertos, alertas atuais, atribuições atuais) como "já notificado", **sem enviar nada**, grava `wpp_baseline_ok=1`, e sai. A passada seguinte opera normal.
  3. Se `evo_status()['estado'] !== 'open'`: worker não faz nada além de logar, e sai. NÃO acumula backlog pra quando reconectar (watermark não avança, mas quando voltar, só pega o que for novo a partir do watermark — e o watermark é do relógio, então eventos de quando estava offline entram; por isso o passo 4).
  4. Se o worker detectar que ficou > `WPP_OFFLINE_RESET_MIN` (default 30) sem rodar com sucesso (compara `wpp_last_ok` com agora), na volta ele **re-semeia a baseline** (repete passo 2) em vez de despejar tudo que aconteceu no intervalo.
  5. Instância já vem com `syncFullHistory=false`, `groupsIgnore=true`, `readMessages=false` (Fase 1). Não mexer.
- **Fase 2 NÃO configura webhook** (`MESSAGES_UPSERT`) e **NÃO responde mensagem recebida**. Só envia. Chatbot é Fase 3.
- `wpp/*.php` que não são tela: sem HTML, sem `session_start`, retornam array/valor, **nunca lançam**.
- Worker: nunca usa `echo`/`print` pra saída de verdade — loga em `portal_wpp_log` e/ou stderr. Idempotente: rodar 2x seguidas não manda nada 2x (dedup em `portal_wpp_notificados` com UNIQUE).
- Toda query no GLPI é **SQL direto via `$pdo`** (padrão de `notificacoes.php`/`alertas.php`), nunca a REST API (a search API do GLPI tem índice desatualizado).
- Commits: subject em português, Conventional Commits (`feat:`/`fix:`/`refactor:`/`docs:`). Comentários em português. Trailer:
  ```
  Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TeiLgTAYjLg3dtuSq78Bo8
  ```
- Branch: `feat/whatsapp-fase2`, saído de `infra/migracao-docker-glpi`. Ao mudar `docker/docker-compose.yml`, replicar no servidor à mão (`ssh glpi-server`, compose em `C:\docker\glpi-portal\docker-compose.yml`) — o compose do servidor NÃO é sincronizado por git.
- Sem PHP/Docker no ambiente de dev do implementer: rodar testes com `docker run --rm -v "C:/claude code/portal-glpi:/app" -w //app php:8.2-cli php wpp/tests/run.php` (usar `MSYS_NO_PATHCONV=1` no Git Bash). `php -l` idem.
- Ambiente do worker em produção: container roda como o usuário do container; o `$pdo` conecta em `glpi-db:3306` (host `glpi-db`, user `root`, pass `root_password`, db `glpi2`) — igual `agenda/db.php`.

## Referência de schema do GLPI (o que o worker consulta)

| Tabela | Uso |
|---|---|
| `glpi_tickets` | `id, name, date_creation, date_mod, status, entities_id, type, time_to_resolve`. status: 1=Novo 2=Atribuído 3=Planejado 4=Pendente 5=Solucionado 6=Fechado |
| `glpi_tickets_users` | atribuição: `tickets_id, users_id, type` — **type=2 = técnico**. NÃO tem coluna de data. |
| `glpi_itilfollowups` | `items_id, itemtype='Ticket', date_creation` — última interação no chamado |
| `glpi_users` | `id, name, realname, firstname, phone, mobile, is_active, is_deleted` |
| `glpi_profiles_users` | `users_id, profiles_id` — **profiles_id=4 = perfil Técnico** |
| `glpi_entities` | `id, completename` (usar `apelido_entidade()` de `entidade_alias.php`) |
| `glpi_computers`, `glpi_items_disks`, `portal_inv_*` | usados pelas queries de alerta (ver `alertas.php`) |

## File Structure

| Arquivo | Responsabilidade | Ação |
|---|---|---|
| `wpp/db.php` | + CREATE das 6 tabelas `portal_wpp_*` novas + helpers | Modify |
| `wpp/guardrails.php` | `wpp_destino_permitido()`, `evo_guarded_send()` | Create |
| `wpp/evo_api.php` | + `evo_send_text()`, `evo_send_media()` (via guarded) | Modify |
| `alertas_lib.php` | funções puras com as queries de alerta (extraídas de `alertas.php`) | Create |
| `alertas.php` | passa a consumir `alertas_lib.php` | Modify |
| `wpp/worker.php` | loop de polling: baseline, watermarks, 4 gatilhos | Create |
| `wpp/gatilhos.php` | a lógica de cada gatilho (funções puras, testáveis) | Create |
| `docker/docker-compose.yml` | + serviço `portal-wpp-worker` | Modify |
| `config_whatsapp.php` | + abas Contatos, Gatilhos, Log + handlers `?action=` | Modify |
| `wpp/tests/test_guardrails.php` | testes do guardrail | Create |
| `wpp/tests/test_gatilhos.php` | testes dos gatilhos (com `$pdo` fake/sqlite ou fixtures) | Create |
| `wpp/README.md` | + seção Fase 2 (worker, abas, o que muda) | Modify |

---

## Task 1: Tabelas `portal_wpp_*` + helpers em `wpp/db.php`

**Files:**
- Modify: `wpp/db.php`
- Test: `wpp/tests/test_db_fase2.php` (Create)

**Interfaces:**
- Consumes: `$pdo` de `agenda/db.php` (já requerido em `wpp/db.php`).
- Produces (novas tabelas, todas `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, criadas com `CREATE TABLE IF NOT EXISTS`):

```sql
CREATE TABLE IF NOT EXISTS portal_wpp_contatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    glpi_user_id INT NOT NULL,
    telefone VARCHAR(20) NOT NULL,          -- só dígitos, formato E.164 sem '+', ex: 5567999998888
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (glpi_user_id)
);

CREATE TABLE IF NOT EXISTS portal_wpp_autorizados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefone VARCHAR(20) NOT NULL,
    entities_id INT NULL,
    nome VARCHAR(120) DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tel (telefone)
);

CREATE TABLE IF NOT EXISTS portal_wpp_notificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(24) NOT NULL,               -- 'novo' | 'atribuido' | 'alerta' | 'sla'
    ref_id VARCHAR(64) NOT NULL,             -- ticket id, ou "ticket:user" p/ atribuido, ou hash do alerta
    hash VARCHAR(40) NOT NULL DEFAULT '',    -- desambiguador opcional
    enviado_em DATETIME NOT NULL,
    UNIQUE KEY uq_evento (tipo, ref_id, hash)
);

CREATE TABLE IF NOT EXISTS portal_wpp_dm_agendado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    glpi_user_id INT NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    enviar_em DATETIME NOT NULL,
    status ENUM('pendente','enviado','cancelado') NOT NULL DEFAULT 'pendente',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dm (ticket_id, glpi_user_id)
);

CREATE TABLE IF NOT EXISTS portal_wpp_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direcao ENUM('out','in','sys') NOT NULL DEFAULT 'out',
    destino VARCHAR(64) DEFAULT '',
    resumo VARCHAR(255) DEFAULT '',
    status VARCHAR(24) DEFAULT '',           -- 'ok' | 'bloqueado' | 'erro' | 'baseline' | ...
    criado_em DATETIME NOT NULL
);
```

  (Não há `portal_wpp_conversas` na Fase 2 — é Fase 3.)

  Helpers novos em `wpp/db.php`:
```php
function wpp_log(string $direcao, string $destino, string $resumo, string $status): void
// INSERT em portal_wpp_log com criado_em = NOW(). Nunca lança (try/catch interno).

function wpp_ja_notificado(string $tipo, string $ref_id, string $hash = ''): bool
// SELECT 1 FROM portal_wpp_notificados WHERE tipo=? AND ref_id=? AND hash=? LIMIT 1

function wpp_marcar_notificado(string $tipo, string $ref_id, string $hash = ''): void
// INSERT IGNORE ... enviado_em = NOW()
```

- [ ] **Step 1: Escrever o teste que falha — `wpp/tests/test_db_fase2.php`**

```php
<?php
require_once __DIR__ . '/../db.php';   // cria as tabelas
global $pdo;

// as 5 tabelas existem
foreach (['portal_wpp_contatos','portal_wpp_autorizados','portal_wpp_notificados','portal_wpp_dm_agendado','portal_wpp_log'] as $t) {
    $ok = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
    t_ok((bool)$ok, "tabela $t existe");
}

// wpp_marcar_notificado + wpp_ja_notificado
wpp_marcar_notificado('novo', '99999');
t_ok(wpp_ja_notificado('novo', '99999'), 'marcar/ja_notificado funciona');
t_ok(!wpp_ja_notificado('novo', '88888'), 'nao notificado retorna false');
wpp_marcar_notificado('novo', '99999'); // 2x nao quebra (INSERT IGNORE)
t_ok(true, 'marcar 2x nao lanca');

// wpp_log nunca lanca
wpp_log('out', 'x@g.us', 'teste', 'ok');
t_ok(true, 'wpp_log nao lanca');

// limpeza
$pdo->exec("DELETE FROM portal_wpp_notificados WHERE ref_id IN ('99999','88888')");
$pdo->exec("DELETE FROM portal_wpp_log WHERE resumo='teste'");
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker run --rm -v "C:/claude code/portal-glpi:/app" -w //app php:8.2-cli php wpp/tests/run.php` (com `MSYS_NO_PATHCONV=1`).
Expected: FAIL — o teste precisa de `$pdo` (banco). **Se o container não alcança um MariaDB**, o teste de DB não roda no dev. Nesse caso: marque `test_db_fase2.php` pra rodar só no deploy (`docker exec glpi-web php .../wpp/tests/run.php`), e no dev rode só os testes que não tocam banco. Documente no report.

- [ ] **Step 3: Implementar as tabelas + helpers em `wpp/db.php`**

Adicionar os `CREATE TABLE IF NOT EXISTS` logo após o de `portal_wpp_config`, e as 3 funções. `wpp_log` com `try { ... } catch (\Throwable $e) {}`.

- [ ] **Step 4: Rodar os testes**

Run: (deploy) `docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add wpp/db.php wpp/tests/test_db_fase2.php
git commit -m "feat: wpp/ - tabelas portal_wpp_* da Fase 2 + helpers de log/dedup"
```

---

## Task 2: Guardrail de saída — `wpp/guardrails.php`

**Files:**
- Create: `wpp/guardrails.php`
- Create: `wpp/tests/test_guardrails.php`

**Interfaces:**
- Consumes: `wpp_cfg_get()` e `wpp_log()` de `wpp/db.php`; `$pdo`.
- Produces:

```php
function wpp_norm_telefone(string $v): string
// tira tudo que nao for digito. '' se vazio.

function wpp_destino_permitido(string $destino): bool
// $destino pode ser um JID de grupo ('...@g.us') ou um numero (so digitos, ou 'digitos@s.whatsapp.net').
// REJEITA (retorna false + wpp_log('out',$destino,'destino bloqueado','bloqueado')):
//   - contém 'broadcast' (status@broadcast, qualquer @broadcast)
//   - termina em '@g.us' mas NAO é wpp_cfg_get('grupo_alertas_jid') nem 'grupo_chamados_jid'
//   - é um numero que nao existe (ativo=1) em portal_wpp_contatos NEM em portal_wpp_autorizados
//   - vazio / malformado
// ACEITA (true): exatamente um dos 2 grupos configurados; ou numero de contato/autorizado ativo.

function evo_guarded_send(string $destino, callable $enviar, string $resumo): array
// if (!wpp_destino_permitido($destino)) return ['ok'=>false,'bloqueado'=>true];
// $r = $enviar();  // callable que faz a chamada REST de fato e retorna ['ok'=>bool,...]
// wpp_log('out', $destino, $resumo, $r['ok'] ? 'ok' : 'erro');
// return $r;
```

- [ ] **Step 1: Escrever `wpp/tests/test_guardrails.php`**

```php
<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';
global $pdo;

// fixtures
wpp_cfg_set('grupo_alertas_jid',  '111@g.us');
wpp_cfg_set('grupo_chamados_jid', '222@g.us');
$pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone='5567999990000'");
$pdo->exec("INSERT INTO portal_wpp_contatos (glpi_user_id,telefone,ativo) VALUES (999999,'5567999990000',1)");

t_ok(wpp_norm_telefone('+55 (67) 99999-0000') === '5567999990000', 'norm_telefone tira nao-digito');

t_ok(wpp_destino_permitido('111@g.us'),  'grupo alertas configurado: ok');
t_ok(wpp_destino_permitido('222@g.us'),  'grupo chamados configurado: ok');
t_ok(!wpp_destino_permitido('333@g.us'), 'grupo aleatorio: BLOQUEADO');
t_ok(!wpp_destino_permitido('status@broadcast'), 'status: BLOQUEADO');
t_ok(!wpp_destino_permitido('999@broadcast'),    'broadcast: BLOQUEADO');
t_ok(wpp_destino_permitido('5567999990000'),     'contato ativo: ok');
t_ok(!wpp_destino_permitido('5511000000000'),    'numero nao cadastrado: BLOQUEADO');
t_ok(!wpp_destino_permitido(''),                 'vazio: BLOQUEADO');

// evo_guarded_send bloqueia sem chamar o callable
$chamou = false;
$r = evo_guarded_send('333@g.us', function() use (&$chamou){ $chamou = true; return ['ok'=>true]; }, 'x');
t_ok(!$chamou && $r['bloqueado'] === true, 'guarded_send nao chama o callable pra destino bloqueado');

// evo_guarded_send deixa passar destino ok
$r = evo_guarded_send('111@g.us', fn() => ['ok'=>true], 'x');
t_ok($r['ok'] === true, 'guarded_send passa destino permitido');

$pdo->exec("DELETE FROM portal_wpp_contatos WHERE telefone='5567999990000'");
```

- [ ] **Step 2: Rodar e ver falhar** — `guardrails.php` não existe.

- [ ] **Step 3: Implementar `wpp/guardrails.php`**

```php
<?php
require_once __DIR__ . '/db.php';

function wpp_norm_telefone(string $v): string {
    return preg_replace('/\D+/', '', $v) ?? '';
}

function wpp_destino_permitido(string $destino): bool {
    $d = trim($destino);
    if ($d === '' || stripos($d, 'broadcast') !== false) {
        wpp_log('out', $d, 'destino bloqueado', 'bloqueado');
        return false;
    }
    if (str_ends_with($d, '@g.us')) {
        $grupos = array_filter([
            wpp_cfg_get('grupo_alertas_jid', ''),
            wpp_cfg_get('grupo_chamados_jid', ''),
        ]);
        if (in_array($d, $grupos, true)) return true;
        wpp_log('out', $d, 'grupo nao cadastrado', 'bloqueado');
        return false;
    }
    // numero
    $tel = wpp_norm_telefone(str_replace('@s.whatsapp.net', '', $d));
    if ($tel === '') { wpp_log('out', $d, 'numero invalido', 'bloqueado'); return false; }
    global $pdo;
    $st = $pdo->prepare(
        "SELECT 1 FROM portal_wpp_contatos WHERE telefone=? AND ativo=1
         UNION SELECT 1 FROM portal_wpp_autorizados WHERE telefone=? AND ativo=1 LIMIT 1"
    );
    $st->execute([$tel, $tel]);
    if ($st->fetchColumn()) return true;
    wpp_log('out', $d, 'numero nao cadastrado', 'bloqueado');
    return false;
}

function evo_guarded_send(string $destino, callable $enviar, string $resumo): array {
    if (!wpp_destino_permitido($destino)) {
        return ['ok' => false, 'bloqueado' => true];
    }
    try {
        $r = $enviar();
    } catch (\Throwable $e) {
        wpp_log('out', $destino, $resumo . ' :: ' . $e->getMessage(), 'erro');
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
    wpp_log('out', $destino, $resumo, !empty($r['ok']) ? 'ok' : 'erro');
    return is_array($r) ? $r : ['ok' => false];
}
```

- [ ] **Step 4: Rodar os testes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add wpp/guardrails.php wpp/tests/test_guardrails.php
git commit -m "feat: wpp/guardrails - allowlist de destino + evo_guarded_send"
```

---

## Task 3: `evo_send_text` / `evo_send_media` via guardrail

**Files:**
- Modify: `wpp/evo_api.php`
- Test: adicionar casos em `wpp/tests/test_evo_api.php`

**Interfaces:**
- Consumes: `evo_request()` (já existe), `evo_guarded_send()` (Task 2).
- Produces:

```php
function evo_send_text(string $destino, string $texto): array
// $jidOuNumero -> se for so digitos, vira "<digitos>@s.whatsapp.net" pro payload da Evolution;
//   se ja tem @, usa como veio.
// return evo_guarded_send($destino, fn() => evo_request('POST', '/message/sendText/'.EVO_INSTANCE, [
//     'number' => <destino normalizado>, 'text' => $texto ]), mb_substr($texto,0,80));

function evo_send_media(string $destino, string $base64, string $legenda, string $mime = 'image/jpeg', string $nome = 'arquivo'): array
// idem, POST /message/sendMedia/{inst} com {number, mediatype:'image', mimetype, caption, media, fileName}
```

- [ ] **Step 1: Teste** — adicionar em `test_evo_api.php`:

```php
// evo_send_text é bloqueado pra destino não permitido (não faz request de verdade)
require_once __DIR__ . '/../guardrails.php';
wpp_cfg_set('grupo_chamados_jid', '222@g.us');
$r = evo_send_text('999@g.us', 'oi');
t_ok(!empty($r['bloqueado']), 'evo_send_text bloqueia grupo nao cadastrado');
```

- [ ] **Step 2: Rodar e ver falhar.**

- [ ] **Step 3: Implementar** as 2 funções em `wpp/evo_api.php` (adicionar `require_once __DIR__.'/guardrails.php';` no topo, depois do `require_once config.php`).

- [ ] **Step 4: Rodar os testes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add wpp/evo_api.php wpp/tests/test_evo_api.php
git commit -m "feat: wpp/evo_api - envio de texto e midia via guardrail"
```

---

## Task 4: `alertas_lib.php` — extrair as queries de alerta

**Files:**
- Create: `alertas_lib.php`
- Modify: `alertas.php`
- Test: `wpp/tests/test_alertas_lib.php` (Create)

**Interfaces:**
- Consumes: `$pdo`, `apelido_entidade()` de `entidade_alias.php`.
- Produces (funções puras, sem HTML):

```php
function alertas_sem_inventario(PDO $pdo, int $dias = 7): array
// a query 1 do alertas.php (linhas 16-27). Retorna as linhas cruas (name, last_inventory_update, loja, cat).

function alertas_disco_cheio(PDO $pdo, int $pct = 90): array
// a query 2 do alertas.php (linhas 31-42). Retorna (name, loja, volume, totalsize, freesize, pct).

function alertas_snapshot(PDO $pdo): array
// ['sem_inventario' => [...], 'disco_cheio' => [...]]  — usado pelo worker pra comparar com o snapshot anterior.
```

- [ ] **Step 1: Teste `wpp/tests/test_alertas_lib.php`** (só verifica que roda e retorna array; sem asserção de conteúdo):

```php
<?php
require_once __DIR__ . '/../../alertas_lib.php';
require_once __DIR__ . '/../../agenda/db.php';
global $pdo;
t_ok(is_array(alertas_sem_inventario($pdo, 7)), 'alertas_sem_inventario retorna array');
t_ok(is_array(alertas_disco_cheio($pdo, 90)), 'alertas_disco_cheio retorna array');
$s = alertas_snapshot($pdo);
t_ok(isset($s['sem_inventario']) && isset($s['disco_cheio']), 'snapshot tem as 2 chaves');
```

- [ ] **Step 2: Rodar e ver falhar.**

- [ ] **Step 3: Criar `alertas_lib.php`** com as 3 funções — **copiar as SQLs exatas** de `alertas.php:16-27` e `31-42` (mesmas constantes `ALERTA_INV_DIAS`/`ALERTA_DISCO_PCT` viram parâmetros com esses defaults).

- [ ] **Step 4: Refatorar `alertas.php`** — `require_once __DIR__.'/alertas_lib.php';` e trocar os 2 blocos `$pdo->query("...")` por `alertas_sem_inventario($pdo, ALERTA_INV_DIAS)` / `alertas_disco_cheio($pdo, ALERTA_DISCO_PCT)`. A tela tem que renderizar **idêntica** (comparar antes/depois).

- [ ] **Step 5: Rodar os testes + `php -l alertas.php` + abrir a Central de Alertas e conferir que não mudou nada visualmente.**

- [ ] **Step 6: Commit**

```bash
git add alertas_lib.php alertas.php wpp/tests/test_alertas_lib.php
git commit -m "refactor: extrai queries de alerta pra alertas_lib.php (reuso no worker)"
```

---

## Task 5: `wpp/worker.php` — esqueleto + baseline + watermarks + container

**Files:**
- Create: `wpp/worker.php`
- Modify: `docker/docker-compose.yml`
- Modify: `wpp/README.md` (seção Fase 2)
- Test: `wpp/tests/test_worker_baseline.php` (Create)

**Interfaces:**
- Consumes: `wpp/db.php`, `wpp/evo_api.php`, `alertas_lib.php`, `wpp/gatilhos.php` (Task 6+, stub por enquanto).
- Produces:

```php
// wpp/worker.php — roda uma passada e sai. O loop fica no container (while true; sleep 30).
// Fluxo de uma passada:
//   1. $st = evo_status(); se $st['estado'] !== 'open' -> wpp_log('sys','', 'instancia '.$st['estado'], 'skip'); exit(0);
//   2. controle de reset:
//        $lastOk = wpp_cfg_get('wpp_last_ok');
//        if ($lastOk && (time() - strtotime($lastOk)) > WPP_OFFLINE_RESET_MIN*60) { wpp_cfg_set('wpp_baseline_ok',''); }
//   3. baseline: if (wpp_cfg_get('wpp_baseline_ok') !== '1') { wpp_semear_baseline($pdo); wpp_cfg_set('wpp_baseline_ok','1'); wpp_cfg_set('wpp_last_ok', now); exit(0); }
//   4. rodar os 4 gatilhos (Task 6-9), cada um dentro de try/catch que loga e segue.
//   5. wpp_cfg_set('wpp_last_ok', now do banco).

function wpp_semear_baseline(PDO $pdo): void
// marca como 'notificado' SEM ENVIAR:
//   - todo glpi_tickets aberto (status IN 1,2,3,4): wpp_marcar_notificado('novo', id) e ('sla', id)
//   - toda atribuicao atual: SELECT tickets_id,users_id FROM glpi_tickets_users WHERE type=2
//        + ticket aberto -> wpp_marcar_notificado('atribuido', "$tid:$uid")
//   - snapshot de alertas atual -> wpp_cfg_set('wpp_snap_alertas', json de alertas_snapshot())
//   - grava watermark de 'novo' e 'sla' = NOW() do banco (SELECT NOW())
//   - wpp_log('sys','', 'baseline semeada: N tickets', 'baseline')

function wpp_agora_db(PDO $pdo): string   // SELECT NOW() — usar sempre o relogio do banco, nunca o do PHP
```

  Constantes no topo do worker (lidas de `portal_wpp_config`, com default):
  `WPP_OFFLINE_RESET_MIN` (30), `WPP_DELAY_DM_MIN` (5), `WPP_DIGEST_ALERTAS_MIN` (15), `WPP_SLA_HORAS_PARADO` (4), `WPP_SLA_PREVENC_MIN` (30). Chaves em `portal_wpp_config`: `cfg_offline_reset_min`, `cfg_delay_dm_min`, `cfg_digest_alertas_min`, `cfg_sla_horas`, `cfg_sla_prevenc_min`, e toggles `on_novo`/`on_atribuido`/`on_alertas`/`on_sla` (default '1').

- [ ] **Step 1: Teste `wpp/tests/test_worker_baseline.php`**

```php
<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../worker.php';   // worker.php deve ser "require-safe": se rodar via require, NÃO executa a passada. Usar: if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'worker.php') { wpp_worker_passada(); }
global $pdo;

$pdo->exec("DELETE FROM portal_wpp_notificados WHERE tipo IN ('novo','sla','atribuido')");
wpp_cfg_set('wpp_baseline_ok', '');
wpp_semear_baseline($pdo);

$n = (int)$pdo->query("SELECT COUNT(*) FROM portal_wpp_notificados WHERE tipo='novo'")->fetchColumn();
$abertos = (int)$pdo->query("SELECT COUNT(*) FROM glpi_tickets WHERE is_deleted=0 AND status IN (1,2,3,4)")->fetchColumn();
t_ok($n === $abertos, "baseline marcou todos os $abertos chamados abertos como 'novo' notificado");
t_ok(wpp_cfg_get('wpp_snap_alertas') !== null, 'baseline gravou snapshot de alertas');
```

- [ ] **Step 2: Rodar e ver falhar.**

- [ ] **Step 3: Implementar `wpp/worker.php`** — a função `wpp_worker_passada()` com os passos 1-5, `wpp_semear_baseline()`, `wpp_agora_db()`. Gatilhos como chamadas a funções de `wpp/gatilhos.php` que ainda não existem → criar `wpp/gatilhos.php` com stubs `function gat_novo(PDO $pdo): void {}` etc. nesta task, implementar nas próximas.
  **worker.php require-safe:** só chama `wpp_worker_passada()` se for o script principal (checar `realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__` ou `debug_backtrace` vazio).

- [ ] **Step 4: Adicionar o serviço ao `docker/docker-compose.yml`** (depois de `glpi-cron`):

```yaml
  portal-wpp-worker:
    build: .
    container_name: portal-wpp-worker
    restart: unless-stopped
    depends_on:
      - glpi-db
      - evolution-api
    volumes:
      - C:\docker\glpi-portal\glpi2:/var/www/html/glpi2
    entrypoint: ["sh", "-c", "while true; do php /var/www/html/glpi2/portal-glpi/wpp/worker.php; sleep 30; done"]
    networks:
      - glpi-net
```

- [ ] **Step 5: `wpp/README.md`** — nova seção "FASE 2 — worker de notificações": o que o container faz, como subir (`docker compose up -d portal-wpp-worker`), como ver o log (`docker compose logs -f portal-wpp-worker` e a aba Log da tela), e a regra de baseline (primeira subida não manda nada).

- [ ] **Step 6: Rodar os testes** (via `docker exec glpi-web php ...`). PASS.

- [ ] **Step 7: Commit**

```bash
git add wpp/worker.php wpp/gatilhos.php docker/docker-compose.yml wpp/README.md wpp/tests/test_worker_baseline.php
git commit -m "feat: wpp/worker - esqueleto, baseline sem envio, watermarks + container"
```

---

## Task 6: Gatilho — chamado novo → grupo Chamados

**Files:**
- Modify: `wpp/gatilhos.php`
- Test: `wpp/tests/test_gatilhos.php` (Create)

**Interfaces:**
- Consumes: `$pdo`, `wpp_cfg_get`, `wpp_ja_notificado`/`wpp_marcar_notificado`, `evo_send_text`, `apelido_entidade()`.
- Produces:

```php
function gat_novo(PDO $pdo): void
// if (wpp_cfg_get('on_novo','1') !== '1') return;
// $wm = wpp_cfg_get('wm_novo');  if (!$wm) { wpp_cfg_set('wm_novo', wpp_agora_db($pdo)); return; }
// SELECT t.id, t.name, t.date_creation, e.completename AS loja, t.type
//   FROM glpi_tickets t LEFT JOIN glpi_entities e ON e.id=t.entities_id
//   WHERE t.is_deleted=0 AND t.date_creation > :wm ORDER BY t.date_creation ASC LIMIT 30
// para cada: se !wpp_ja_notificado('novo', id):
//     evo_send_text(wpp_cfg_get('grupo_chamados_jid'), gat_msg_novo($row));
//     wpp_marcar_notificado('novo', id);
//   avança $maxData
// wpp_cfg_set('wm_novo', $maxData ?: $wm)   // só avança até o último processado

function gat_msg_novo(array $t): string
// "🆕 *Chamado #<id>* — <loja>\n<titulo>\nAberto em <dd/mm HH:MM>"
```

- [ ] **Step 1: Teste `wpp/tests/test_gatilhos.php`** — usa um mock de `evo_send_text` via uma global `$GLOBALS['__wpp_sent']` que `gat_*` respeita quando setada (ou testa a montagem da mensagem `gat_msg_novo` isoladamente + o avanço do watermark com fixtures no banco). Mínimo viável: testar `gat_msg_novo(['id'=>7,'name'=>'PC não liga','loja'=>'Entity > Loja 3','date_creation'=>'2026-09-07 14:30:00','type'=>1])` produz a string esperada, e que `gat_novo` com `wm_novo` ausente só grava o watermark e não "envia".

- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar `gat_novo` + `gat_msg_novo`.**
- [ ] **Step 4: Rodar os testes.** PASS.
- [ ] **Step 5: Commit** — `feat: wpp/gatilhos - chamado novo no grupo Chamados`

---

## Task 7: Gatilho — chamado atribuído → DM com atraso

**Files:**
- Modify: `wpp/gatilhos.php`
- Test: `wpp/tests/test_gatilhos.php` (adicionar)

**Interfaces:**
- Produces:

```php
function gat_atribuido(PDO $pdo): void
// if (wpp_cfg_get('on_atribuido','1') !== '1') return;
// PARTE A — detectar novas atribuições e AGENDAR (não envia agora):
//   SELECT tu.tickets_id, tu.users_id, t.name, e.completename AS loja
//     FROM glpi_tickets_users tu
//     JOIN glpi_tickets t ON t.id=tu.tickets_id AND t.is_deleted=0 AND t.status IN (1,2,3,4)
//     LEFT JOIN glpi_entities e ON e.id=t.entities_id
//     WHERE tu.type=2
//   para cada (tid,uid): se !wpp_ja_notificado('atribuido', "$tid:$uid"):
//       $tel = SELECT telefone FROM portal_wpp_contatos WHERE glpi_user_id=$uid AND ativo=1
//       if (!$tel) { wpp_marcar_notificado('atribuido',"$tid:$uid"); continue; }  // sem telefone: marca e ignora
//       INSERT IGNORE portal_wpp_dm_agendado (ticket_id,glpi_user_id,telefone,enviar_em)
//              VALUES ($tid,$uid,$tel, NOW() + INTERVAL cfg_delay_dm_min MINUTE)
//       wpp_marcar_notificado('atribuido', "$tid:$uid")
// PARTE B — enviar os DMs cujo prazo chegou, se ainda válidos:
//   SELECT * FROM portal_wpp_dm_agendado WHERE status='pendente' AND enviar_em <= NOW()
//   para cada: revalida — o ticket $tid ainda tem tu.type=2 com users_id=$uid E status IN (1,2,3,4)?
//       sim  -> evo_send_text($tel, gat_msg_atribuido($row)); status='enviado'
//       não  -> status='cancelado'  (foi reatribuído ou resolvido antes do prazo)

function gat_msg_atribuido(array $t): string
// "📌 *Chamado #<id> atribuído a você* — <loja>\n<titulo>"
```

- [ ] **Steps 1-5:** teste (agendamento cria linha `pendente`; reatribuir antes do prazo → `cancelado`, 0 envios; `gat_msg_atribuido` string), implementar, rodar, commit (`feat: wpp/gatilhos - DM de chamado atribuido com atraso configuravel`).

---

## Task 8: Gatilho — alertas → grupo Alertas (digest)

**Files:**
- Modify: `wpp/gatilhos.php`
- Test: `wpp/tests/test_gatilhos.php` (adicionar)

**Interfaces:**
- Consumes: `alertas_snapshot()` de `alertas_lib.php`.
- Produces:

```php
function gat_alertas(PDO $pdo): void
// if (wpp_cfg_get('on_alertas','1') !== '1') return;
// $ultimo = wpp_cfg_get('wm_alertas_digest');
// $intervalo = (int) wpp_cfg_get('cfg_digest_alertas_min', '15');
// if ($ultimo && (time() - strtotime($ultimo)) < $intervalo*60) return;   // ainda não é hora
// $atual = alertas_snapshot($pdo);
// $anterior = json_decode(wpp_cfg_get('wpp_snap_alertas','{}'), true) ?: ['sem_inventario'=>[],'disco_cheio'=>[]];
// $novos = itens em $atual que não estavam em $anterior (comparar por chave estável: nome+volume p/ disco, nome p/ inventário)
// if ($novos vazios) { wpp_cfg_set('wpp_snap_alertas', json($atual)); return; }   // atualiza snap, não manda
// evo_send_text(wpp_cfg_get('grupo_alertas_jid'), gat_msg_digest($atual, $novos));
// wpp_cfg_set('wpp_snap_alertas', json($atual));
// wpp_cfg_set('wm_alertas_digest', wpp_agora_db($pdo));

function gat_msg_digest(array $atual, array $novos): string
// "🔔 *Alertas* (<N> novos)\n\n📉 Sem inventário +7d: <total> (novos: <lista curta>)\n💾 Disco cheio: <total> (novos: ...)"
```

- [ ] **Steps 1-5:** teste (dois snapshots, só os itens realmente novos entram; respeita o intervalo), implementar, rodar, commit (`feat: wpp/gatilhos - digest de alertas no grupo Alertas`).

---

## Task 9: Gatilho — SLA / chamado parado → grupo Chamados

**Files:**
- Modify: `wpp/gatilhos.php`
- Test: `wpp/tests/test_gatilhos.php` (adicionar)

**Interfaces:**
- Produces:

```php
function gat_sla(PDO $pdo): void
// if (wpp_cfg_get('on_sla','1') !== '1') return;
// $horasParado = (int) wpp_cfg_get('cfg_sla_horas', '4');
// $prevencMin  = (int) wpp_cfg_get('cfg_sla_prevenc_min', '30');
// A) PARADO: ticket status IN (1,2) sem followup há > $horasParado h:
//    SELECT t.id, t.name, e.completename AS loja, t.date_mod
//      FROM glpi_tickets t LEFT JOIN glpi_entities e ON e.id=t.entities_id
//      WHERE t.is_deleted=0 AND t.status IN (1,2)
//        AND NOT EXISTS (SELECT 1 FROM glpi_itilfollowups f
//                        WHERE f.itemtype='Ticket' AND f.items_id=t.id
//                          AND f.date_creation > NOW() - INTERVAL :h HOUR)
//        AND t.date_mod < NOW() - INTERVAL :h HOUR
//    hash da dedup = 'parado:' . date('Y-m-d')  (uma cutucada por ticket por dia)
// B) PRÉ-VENCIMENTO: t.time_to_resolve BETWEEN NOW() AND NOW() + INTERVAL :m MINUTE, status NOT IN (5,6)
//    hash = 'prevenc'  (uma vez por ticket)
// para cada: se !wpp_ja_notificado('sla', $id, $hash):
//    evo_send_text(wpp_cfg_get('grupo_chamados_jid'), gat_msg_sla($row, $motivo));
//    wpp_marcar_notificado('sla', $id, $hash);

function gat_msg_sla(array $t, string $motivo): string
// motivo 'parado':  "⏳ *Chamado #<id> parado há +<h>h* — <loja>\n<titulo>"
// motivo 'prevenc': "⚠️ *Chamado #<id> perto de furar o SLA* — <loja>\nvence <dd/mm HH:MM>"
```

- [ ] **Steps 1-5:** teste (as 2 queries com fixtures; dedup por dia no 'parado'), implementar, rodar, commit (`feat: wpp/gatilhos - SLA parado e pre-vencimento no grupo Chamados`).

---

## Task 10: `config_whatsapp.php` — aba Contatos

**Files:**
- Modify: `config_whatsapp.php`

**Interfaces:**
- Consumes: `$pdo`, `portal_wpp_contatos`, `wpp_norm_telefone()`.
- Produces novos `?action=`:
  - `?action=contatos_listar` (GET) → `{ok, tecnicos:[{glpi_user_id, nome, telefone, ativo, mobile_glpi}]}` — junta `glpi_users` (perfil técnico `profiles_id=4`, `is_active=1`, `is_deleted=0`) LEFT JOIN `portal_wpp_contatos`. `mobile_glpi` = `glpi_users.mobile` normalizado (sugestão de preenchimento).
  - `?action=contatos_salvar` (POST `glpi_user_id`, `telefone`, `ativo`) → valida `wpp_norm_telefone()` com 10-13 dígitos, `INSERT ... ON DUPLICATE KEY UPDATE`. → `{ok}`.

- [ ] **Step 1:** aba "Contatos" no HTML: tabela (Técnico | Telefone (input) | Ativo (checkbox) | [Salvar linha]) + botão "Puxar celulares do GLPI" que preenche os inputs vazios com `mobile_glpi`.
- [ ] **Step 2:** os 2 handlers, antes do bloco HTML, com o guard de permissão já existente (Fase 1) cobrindo.
- [ ] **Step 3:** JS: `carregarContatos()`, `salvarContato(uid)`, `puxarDoGlpi()`.
- [ ] **Step 4:** `php -l`, teste manual no servidor (cadastra 1 técnico, confere `SELECT * FROM portal_wpp_contatos`).
- [ ] **Step 5: Commit** — `feat: config_whatsapp - aba Contatos (tecnico -> telefone)`

---

## Task 11: `config_whatsapp.php` — abas Gatilhos e Log

**Files:**
- Modify: `config_whatsapp.php`

**Interfaces:**
- Produces:
  - `?action=gatilhos_ler` (GET) → `{ok, cfg:{on_novo, on_atribuido, on_alertas, on_sla, cfg_delay_dm_min, cfg_digest_alertas_min, cfg_sla_horas, cfg_sla_prevenc_min}}` (de `portal_wpp_config`, com defaults).
  - `?action=gatilhos_salvar` (POST) → valida (toggles 0/1; minutos/horas inteiros 1..1440) → `wpp_cfg_set` de cada → `{ok}`.
  - `?action=log_listar` (GET, `?limite=100`) → `{ok, linhas:[{criado_em, direcao, destino, resumo, status}]}` — `SELECT ... FROM portal_wpp_log ORDER BY id DESC LIMIT :limite`.

- [ ] **Step 1:** aba "Gatilhos": 4 switches + 4 campos numéricos, botão Salvar.
- [ ] **Step 2:** aba "Log": tabela read-only (data | direção | destino | resumo | status), botão "Atualizar", auto-refresh opcional a cada 10s **com `bg=1`** (mesma regra da Fase 1). Colorir `status` (`ok`=verde, `bloqueado`=vermelho, `erro`=laranja).
- [ ] **Step 3:** handlers + JS.
- [ ] **Step 4:** `php -l` + teste manual.
- [ ] **Step 5: Commit** — `feat: config_whatsapp - abas Gatilhos e Log`

---

## Task 12: Deploy da Fase 2 + verificação

**Files:**
- Modify: `wpp/README.md` (checklist de deploy da Fase 2)

- [ ] **Step 1:** Escrever no `wpp/README.md` o checklist de deploy da Fase 2:
  1. `scp` dos arquivos novos/alterados pro servidor (`wpp/*.php`, `alertas_lib.php`, `alertas.php`, `config_whatsapp.php`).
  2. Replicar o serviço `portal-wpp-worker` no `C:\docker\glpi-portal\docker-compose.yml` do servidor (à mão).
  3. `docker compose up -d portal-wpp-worker`.
  4. **Primeira subida = baseline.** Conferir no log: `docker compose logs portal-wpp-worker` → "baseline semeada". Aba Log da tela → 1 linha `sys / baseline`. **Zero linhas `out/ok`.**
  5. Conferir nos 2 grupos do WhatsApp: **nenhuma mensagem** chegou.
  6. Cadastrar ao menos 1 técnico na aba Contatos, ligar os toggles desejados na aba Gatilhos.
  7. Teste real: criar um chamado de teste no GLPI → em ~30-60s deve chegar UMA mensagem no grupo Chamados. Atribuir a um técnico com telefone → DM chega após o `cfg_delay_dm_min`.
  8. Rodar `docker exec glpi-web php .../wpp/tests/run.php` → tudo verde.
- [ ] **Step 2:** Executar o deploy e marcar o checklist.
- [ ] **Step 3: Commit** — `docs: runbook de deploy da Fase 2`

---

## Self-Review

**1. Spec coverage:**
- Guardrail de saída (3 regras) → Task 2 ✅
- Cold-start / baseline / watermark / reset por offline → Task 5 (Global Constraints + `wpp_semear_baseline`) ✅
- `evo_guarded_send` em todo envio → Task 2 + Task 3 (send_text/media só via guarded) ✅
- `alertas_lib.php` → Task 4 ✅
- Container worker (padrão glpi-cron) → Task 5 ✅
- Gatilho chamado novo → Task 6 ✅
- Gatilho atribuído + delay + cancelamento → Task 7 ✅
- Gatilho alertas digest → Task 8 ✅
- Gatilho SLA/parado → Task 9 ✅
- `portal_wpp_contatos` + prefill GLPI → Task 1 + Task 10 ✅
- Abas Gatilhos + Log → Task 11 ✅
- Tabelas `portal_wpp_*` → Task 1 ✅
- Fora de escopo (webhook, chatbot, `portal_wpp_conversas`, autorizados-em-uso) → Fase 3, não aparece aqui ✅

**2. Placeholder scan:** As SQLs estão escritas por extenso. As telas (Task 10/11) descrevem estrutura + handlers com contrato exato — o implementer produz o arquivo completo no estilo da Fase 1 (`config_whatsapp.php` já existe como referência). `gat_msg_*` têm o formato exato da string. Sem "TODO"/"tratar edge cases" solto.

**3. Type consistency:**
- `wpp_marcar_notificado(tipo, ref_id, hash='')` / `wpp_ja_notificado(...)` — mesma assinatura Task 1 (def) e Tasks 6-9 (uso). ✅
- `evo_send_text(destino, texto)` — Task 3 def, Tasks 6-9 uso. ✅
- `evo_guarded_send(destino, callable, resumo)` — Task 2 def, Task 3 uso. ✅
- `alertas_snapshot($pdo)` → `['sem_inventario'=>..., 'disco_cheio'=>...]` — Task 4 def, Task 8 uso. ✅
- Chaves `portal_wpp_config`: `grupo_alertas_jid`/`grupo_chamados_jid` (Fase 1), `wm_novo`/`wm_alertas_digest`/`wpp_snap_alertas`/`wpp_baseline_ok`/`wpp_last_ok`, `on_*`, `cfg_*` — nomes idênticos entre Task 5, 6, 8, 9, 11. ✅
- `portal_wpp_dm_agendado` colunas — Task 1 (schema) vs Task 7 (INSERT/SELECT). ✅
- status do ticket: 1,2,3,4 = aberto em todos os gatilhos; 5,6 = fechado. Consistente. ✅

## Execution Handoff

Plan complete and saved to `Docs/superpowers/plans/2026-09-07-whatsapp-portal-fase2.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
