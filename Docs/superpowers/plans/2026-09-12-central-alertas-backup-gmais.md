# Central de Alertas — monitoramento de backups (back-gmais) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 2 tipos novos no catálogo de alertas (`backup_erro`, `backup_silencio`),
alimentados por webhook do back-gmais, aparecendo automaticamente no painel
(`alertas.php`), na tela de config (`alertas_config.php`) e no WhatsApp
(`gat_alertas`) — **sem tocar em nenhum desses 3 arquivos**, porque todos já
são genéricos/orientados a `alertas_catalogo()` (confirmado lendo o código:
`alertas.php` já faz `foreach (alertas_catalogo() ...)`, `alertas_config.php`
já monta os inputs de `params` a partir do catálogo, `gat_alertas` já itera
o catálogo tipo a tipo).

**Spec:** `Docs/superpowers/specs/2026-09-12-central-alertas-backup-gmais.md`

**Architecture:** `backup_lib.php` (novo) cria 2 tabelas
(`portal_backup_maquinas`, `portal_backup_execucoes`) e expõe funções puras de
check/render/parse. `alertas_tipos.php` ganha 1 `require` e 2 entradas no
catálogo — nada mais nesse arquivo muda. `webhook_backup.php` (novo, sem
sessão) recebe o POST do back-gmais e grava. `backup_maquinas.php` (novo,
com sessão) é o CRUD de máquinas + gerador de token/URL.

**Tech Stack:** igual ao resto do projeto — PHP 8.2 + PDO/MariaDB, sem
framework, `CREATE TABLE IF NOT EXISTS` inline, testes em `wpp/tests/`
(`t_ok`/`t_eq`, `run.php`), deploy por `scp` pro servidor (`glpi-server`,
container `glpi-web`, path `C:\docker\glpi-portal\glpi2\portal-glpi\`).

## Global Constraints

- **Aditivo, ponto final.** Nenhuma task edita `alerta_check_sem_inventario`,
  `alerta_check_disco_cheio`, seus renders, `gat_alertas`, `gat_alertas_tipo`,
  `gat_msg_alerta_*`, nem o schema de `portal_alertas_config`. Se alguma task
  parecer precisar disso, **parar e revisar o plano** em vez de encostar.
- **Todo deploy usa o fluxo já validado:** subir como `<arquivo>.new`,
  `docker exec glpi-web php -l` no `.new`, só então `Move-Item -Force` por
  cima do arquivo real. Nunca sobrescrever direto.
- **`wpp/tests/run.php` verde antes E depois de cada deploy** (roda contra o
  `glpi2` de produção — os testes de tipos existentes não devem mudar de
  resultado).
- **Webhook nunca lança.** `webhook_backup.php` responde 200 sempre (mesmo
  token inválido, JSON malformado ou banco fora do ar) — mesmo padrão do
  `wpp/webhook.php` já existente (boot em try/catch próprio antes de
  qualquer require perigoso).
- **Token não vaza em log de erro nem em mensagem de resposta.** Token
  inválido = 200 vazio, sem pista.
- **Comentários em português, tipagem PHP explícita, commits em inglês
  (Conventional Commits).**

---

## File Structure

| Arquivo | Papel |
|---|---|
| `backup_lib.php` | **novo** — cria as 2 tabelas (IIFE, mesmo padrão de `alertas_tipos.php`); `backup_parse_mensagem()`; `backup_token_novo()`; `alerta_check_backup_erro/silencio`; `alerta_render_backup_erro/silencio`; helpers de CRUD de máquina usados por `backup_maquinas.php` |
| `alertas_tipos.php` | +1 `require_once` no topo; +2 entradas em `alertas_catalogo()`. Nada mais. |
| `webhook_backup.php` | **novo**, raiz do portal, sem sessão — recebe o POST do back-gmais |
| `backup_maquinas.php` | **novo**, com sessão (`auth_guard.php`) — CRUD de máquinas + URL do webhook |
| `wpp/tests/test_backup_lib.php` | **novo** — testes puros (`backup_parse_mensagem`, `backup_token_novo`) + testes com banco (`alerta_check_backup_*`, insert/consulta de máquina e execução) |

---

## Interfaces

```php
// backup_lib.php

/** Parseia o corpo de texto que o back-gmais manda no campo "message" do
 *  webhook (linhas "Chave: valor"). Nunca lança — retorno best-effort. */
function backup_parse_mensagem(string $message): array
// ['politica' => ?string, 'status' => ?string, 'erro' => ?string]
// $status é sempre uma das strings cruas do back-gmais: success|warning|error|cancelled|running|null

function backup_token_novo(): string
// bin2hex(random_bytes(16)) -> 32 chars hex

/** Grava 1 execução recebida + atualiza o "último contato" da máquina.
 *  Idempotente o bastante: cada POST vira 1 linha nova em execucoes. */
function backup_registrar_execucao(PDO $pdo, int $maquinaId, ?string $politica, string $status, string $mensagemCrua): void

function backup_maquina_por_token(PDO $pdo, string $token): ?array
// null se não existir OU ativo=0 (mesmo tratamento — quem chama não distingue)

// CRUD pra backup_maquinas.php
function backup_maquinas_listar(PDO $pdo): array
function backup_maquina_criar(PDO $pdo, string $nome, ?int $silencioHoras): array   // retorna a linha criada (com token)
function backup_maquina_atualizar(PDO $pdo, int $id, string $nome, bool $ativo, ?int $silencioHoras): void
function backup_maquina_excluir(PDO $pdo, int $id): void   // CASCADE limpa execucoes

// Catálogo (assinaturas exigidas por alertas_catalogo())
function alerta_check_backup_erro(PDO $pdo, array $p): array
// 1 ocorrência por (maquina, política) cuja ÚLTIMA execução é status='error'
//   ['chave'=>'backup_erro:<maquina_id>:<politica>', 'titulo'=>'<nome máquina>',
//    'loja'=>'', 'detalhe'=>'<política>: <trecho do erro ou "falhou"> (<Xh atrás>)']

function alerta_check_backup_silencio(PDO $pdo, array $p): array
// $p['horas'] = limiar default (config do tipo); máquina com silencio_horas
// próprio usa o dela. Ocorrência por máquina ATIVA sem contato dentro do limiar.
//   ['chave'=>'backup_silencio:<maquina_id>', 'titulo'=>'<nome máquina>',
//    'loja'=>'', 'detalhe'=>'sem contato há <Xh> (última: <política/nunca>)']

function alerta_render_backup_erro(array $ocorr): string     // tabela, mesmo visual dos outros tipos
function alerta_render_backup_silencio(array $ocorr): string // tabela
```

```sql
CREATE TABLE IF NOT EXISTS portal_backup_maquinas (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(80) NOT NULL,
    token          VARCHAR(40) NOT NULL UNIQUE,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    silencio_horas INT DEFAULT NULL,
    ultimo_contato DATETIME NULL,
    ultima_politica VARCHAR(120) DEFAULT NULL,
    ultimo_status  ENUM('success','warning','error','cancelled','running') DEFAULT NULL,
    criado_em      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_backup_execucoes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    maquina_id  INT NOT NULL,
    politica    VARCHAR(120) NOT NULL DEFAULT '(sem nome)',
    status      ENUM('success','warning','error','cancelled','running') NOT NULL,
    mensagem    TEXT,
    recebido_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (maquina_id) REFERENCES portal_backup_maquinas(id) ON DELETE CASCADE,
    INDEX idx_maquina_politica (maquina_id, politica, recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Task 1: `backup_lib.php` — tabelas, parse, CRUD de máquina, testes puros

**Files:**
- Create: `backup_lib.php`
- Create: `wpp/tests/test_backup_lib.php`

**Interfaces:** produz tudo listado acima **exceto** `alerta_check_backup_*` /
`alerta_render_backup_*` (Task 2, depende de Task 1 estar completa).

- [ ] **Step 1: Criar `backup_lib.php`** com: `require_once agenda/config.php`
  (mesma convenção de `alertas_tipos.php`); IIFE de criação das 2 tabelas
  (usa `global $pdo` — só roda de verdade quando `$pdo` já existe no escopo
  de quem incluiu, mesmo comportamento de `alertas_tipos.php`); as funções
  `backup_parse_mensagem`, `backup_token_novo`, `backup_registrar_execucao`,
  `backup_maquina_por_token`, `backup_maquinas_listar`, `backup_maquina_criar`,
  `backup_maquina_atualizar`, `backup_maquina_excluir`.

  `backup_parse_mensagem` — regex linha a linha `/^([^:]+):\s*(.*)$/u` sobre
  `explode("\n", $message)`, mapeia `'Política'=>politica`, `'Status'=>status`,
  `'Erro'=>erro`; chaves ausentes viram `null`. Nunca lança (sem `explode`
  em string vazia problemático — `explode` nunca lança em PHP, só cuidado
  com `$message` não-string, já tipado `string` no parâmetro).

- [ ] **Step 2: Testes puros em `wpp/tests/test_backup_lib.php`**
  (sem banco — igual aos blocos puros de `test_gatilhos.php`):
  ```php
  require_once __DIR__ . '/assert.php';
  require_once __DIR__ . '/../../backup_lib.php';

  $msg = "Política: Backup Diário\nStatus: error\nModo: mirror\nJob: abc123\nInício: 2026-09-12T03:00:00-04:00\nErro: rede inacessível";
  $p = backup_parse_mensagem($msg);
  t_eq($p['politica'], 'Backup Diário', 'parse: política');
  t_eq($p['status'], 'error', 'parse: status');
  t_eq($p['erro'], 'rede inacessível', 'parse: erro');

  $p2 = backup_parse_mensagem("Política: X\nStatus: success\n");
  t_eq($p2['status'], 'success', 'parse: success sem campo erro');
  t_ok($p2['erro'] === null, 'parse: erro ausente vira null');

  t_ok(strlen(backup_token_novo()) === 32, 'token: 32 chars');
  t_ok(backup_token_novo() !== backup_token_novo(), 'token: não repete');
  ```

- [ ] **Step 2b: Testes com banco** (mesmo padrão `if (isset($pdo) && $pdo instanceof PDO)`
  de `test_gatilhos.php`) cobrindo, com limpeza em `finally`:
  - criar máquina → token único, aparece em `backup_maquinas_listar`
  - `backup_maquina_por_token` com token errado → `null`
  - `backup_registrar_execucao` grava linha + atualiza `ultimo_contato`/`ultimo_status`/`ultima_politica`
  - excluir máquina → `portal_backup_execucoes` some junto (CASCADE)

- [ ] **Step 3:** `php -l backup_lib.php` (local se houver PHP, senão no deploy).

- [ ] **Step 4: Commit**
  ```bash
  git add backup_lib.php wpp/tests/test_backup_lib.php
  git commit -m "feat: backup_lib - tabelas, parse do webhook e CRUD de maquinas de backup"
  ```

---

## Task 2: catálogo — 2 tipos novos em `alertas_tipos.php`

**Files:**
- Modify: `alertas_tipos.php` (só acréscimo: 1 `require_once` no topo, 2 entradas em `alertas_catalogo()`)
- Modify: `wpp/tests/test_backup_lib.php` (+ os 2 `alerta_check_backup_*`)

**Interfaces:** consome `backup_lib.php` (Task 1); produz as 2 entradas do
catálogo lidas por `alertas.php` / `alertas_config.php` / `gat_alertas` sem
mudança nesses 3 arquivos.

- [ ] **Step 1: Implementar `alerta_check_backup_erro` e `alerta_check_backup_silencio`
  em `backup_lib.php`** (SQL: `SELECT` com subquery/`GROUP BY` pegando a
  última `recebido_em` por `(maquina_id, politica)` para o tipo erro; para
  silêncio, `LEFT JOIN` simples em `portal_backup_maquinas` filtrando
  `ativo=1` e `ultimo_contato` nulo ou mais velho que o limiar). Ambas
  retornam `chave/titulo/loja/detalhe` (ver Interfaces). `loja` sempre `''`.

- [ ] **Step 2: Implementar `alerta_render_backup_erro` e `_silencio`** —
  tabela simples `<table>` reaproveitando as classes CSS já existentes em
  `alertas.php` (`.vazio` quando lista vazia, `<table><tbody>` com colunas
  Máquina / Detalhe), mesmo estilo de `alerta_render_disco_cheio`.

- [ ] **Step 3: `alertas_tipos.php`** — no topo, depois de
  `require_once __DIR__ . '/alertas_lib.php';`, acrescentar:
  ```php
  require_once __DIR__ . '/backup_lib.php';
  ```
  Dentro de `alertas_catalogo()`, acrescentar as 2 entradas (ver spec) ao
  array de retorno — **sem tocar em nenhuma entrada existente**.

- [ ] **Step 4: Testes** — em `test_backup_lib.php`, bloco com banco:
  criar máquina + inserir execução `error` → `alerta_check_backup_erro`
  retorna 1 ocorrência com `chave/titulo/detalhe`; inserir execução
  `success` pra mesma política → ocorrência some. Máquina sem
  `ultimo_contato` (recém-criada) → aparece em `alerta_check_backup_silencio`
  se `horas` do param for baixo o bastante; some depois de
  `backup_registrar_execucao`.

- [ ] **Step 5:** `php -l alertas_tipos.php backup_lib.php`.

- [ ] **Step 6: Rodar `wpp/tests/run.php` no deploy** (Task 5 cobre o deploy
  real; se puder testar isolado antes, melhor) — confirmar que
  `test_alertas_tipos.php` (os 2 tipos antigos) continua 100% verde,
  provando que nada quebrou.

- [ ] **Step 7: Commit**
  ```bash
  git add alertas_tipos.php backup_lib.php wpp/tests/test_backup_lib.php
  git commit -m "feat: central de alertas - tipos backup_erro e backup_silencio no catalogo"
  ```

---

## Task 3: `webhook_backup.php` — receptor do back-gmais

**Files:**
- Create: `webhook_backup.php`

**Interfaces:** consome `backup_maquina_por_token`, `backup_parse_mensagem`,
`backup_registrar_execucao` (Task 1). Sem produtos consumidos por outro
arquivo — é o ponto de entrada externo.

- [ ] **Step 1: Escrever `webhook_backup.php`** seguindo o esqueleto de
  `wpp/webhook.php` (boot em try/catch antes de qualquer require perigoso,
  sempre responde 200, nunca web variável de erro pro cliente):
  ```php
  <?php
  // Endpoint chamado pelo back-gmais (webhook de resultado de job de backup).
  // Responde 200 SEMPRE — token invalido ou payload quebrado nao devem
  // fazer o back-gmais reentregar em loop nem revelar nada a quem sondar.
  header('Content-Type: application/json');
  $boot_ok = true;
  try {
      require_once __DIR__ . '/agenda/db.php';
      require_once __DIR__ . '/backup_lib.php';
  } catch (\Throwable $e) {
      $boot_ok = false;
      error_log('webhook_backup: boot falhou: ' . $e->getMessage());
  }

  if ($boot_ok) {
      try {
          global $pdo;
          $token = (string) ($_GET['m'] ?? '');
          $maquina = $token !== '' ? backup_maquina_por_token($pdo, $token) : null;
          if ($maquina !== null) {
              $raw = file_get_contents('php://input');
              $payload = json_decode((string) $raw, true);
              $mensagem = is_array($payload) ? (string) ($payload['message'] ?? '') : '';
              $p = backup_parse_mensagem($mensagem);
              $status = in_array($p['status'], ['success','warning','error','cancelled','running'], true)
                  ? $p['status'] : 'error';   // status desconhecido/ausente -> trata como erro (mais seguro que ignorar)
              backup_registrar_execucao($pdo, (int) $maquina['id'], $p['politica'], $status, $mensagem);
          }
      } catch (\Throwable $e) {
          error_log('webhook_backup: ' . $e->getMessage());
      }
  }
  echo json_encode(['ok' => true]);
  ```

- [ ] **Step 2: `php -l webhook_backup.php`**

- [ ] **Step 3: Teste manual com `curl`** (no deploy, Task 5) simulando o
  back-gmais:
  ```bash
  curl -s -X POST "http://192.168.1.198:7412/glpi2/portal-glpi/webhook_backup.php?m=<token de teste>" \
       -H "Content-Type: application/json" \
       -d '{"subject":"[BackupGMAIS] Teste: error","message":"Política: Teste\nStatus: error\nErro: simulado","timestamp":"2026-09-12T00:00:00Z","source":"back-gmais"}'
  ```
  Esperado: `{"ok":true}`, e `SELECT` em `portal_backup_execucoes` mostra a
  linha. Token errado → mesma resposta, nenhuma linha gravada.

- [ ] **Step 4: Commit**
  ```bash
  git add webhook_backup.php
  git commit -m "feat: webhook_backup - recebe resultado de job do back-gmais"
  ```

---

## Task 4: `backup_maquinas.php` — admin de máquinas

**Files:**
- Create: `backup_maquinas.php`

**Interfaces:** consome o CRUD de `backup_lib.php` (Task 1). Link de entrada
a partir de `alertas.php` — **acrescentar 1 link na topbar**, do mesmo jeito
que o link "Configurar alertas" já existe (não mexe no resto do arquivo).

- [ ] **Step 1: Página com sessão** (`require_once auth_guard.php`, mesmo
  guard de `alertas.php`) — lista máquinas (nome, ativo, último contato,
  último status, URL do webhook com botão copiar), formulário de
  criar/editar (nome, ativo, horas de silêncio opcional), botão excluir com
  confirmação. Endpoints AJAX (`?action=criar|salvar|excluir`) espelhando o
  padrão de `alertas_config.php` (JSON, POST-only pra ações que escrevem).
  URL mostrada: `http://192.168.1.198:7412/glpi2/portal-glpi/webhook_backup.php?m=<token>`.

- [ ] **Step 2: Link em `alertas.php`** — 1 linha nova na topbar, ao lado de
  "Configurar alertas":
  ```php
  <a href="backup_maquinas.php"><i class="bi bi-hdd-network me-1"></i>Máquinas de backup</a>
  ```

- [ ] **Step 3: `php -l backup_maquinas.php`**

- [ ] **Step 4: Commit**
  ```bash
  git add backup_maquinas.php alertas.php
  git commit -m "feat: backup_maquinas - CRUD de maquinas de backup + link na Central de Alertas"
  ```

---

## Task 5: Deploy + verificação end-to-end

**Files:** nenhum novo.

- [ ] **Step 1: `scp` pro servidor** — `backup_lib.php`, `alertas_tipos.php`,
  `webhook_backup.php`, `backup_maquinas.php`, `alertas.php`,
  `wpp/tests/test_backup_lib.php` — todos como `.new` primeiro.

- [ ] **Step 2: Lint no container** — `php -l` em cada `.new`.

- [ ] **Step 3: `Move-Item -Force`** de cada `.new` por cima do arquivo real
  (peço confirmação antes de qualquer swap em arquivo que já está em produção,
  mesmo procedimento desta sessão).

- [ ] **Step 4: `wpp/tests/run.php`** — 0 falhas, **incluindo os testes dos
  tipos antigos** (prova de não-regressão).

- [ ] **Step 5: Teste `curl` do webhook** (Task 3 Step 3) com uma máquina
  de teste cadastrada via `backup_maquinas.php`; conferir que a ocorrência
  aparece em `alertas.php` e que ativar `notif_whatsapp` no tipo manda 🔔
  pro grupo Alertas (sem re-enviar as antigas — o anti-blast da Etapa 2 já
  cobre isso automaticamente pra qualquer tipo novo).

- [ ] **Step 6: Cadastrar as 2 máquinas reais**, gerar as 2 URLs, e o
  usuário edita o `config.yaml` de cada servidor de backup
  (`notifier.webhook.enabled: true`, `url: <gerada>`, e a política com
  `notify_on` incluindo pelo menos `error` — idealmente `success` também,
  pra alimentar o "último contato" sem depender só de erro).

- [ ] **Step 7: Confirmar de ponta a ponta** — rodar (ou esperar) 1 job real
  em cada servidor, ver a execução chegar em `portal_backup_execucoes` e,
  se for erro proposital de teste, ver o 🔔 chegar no grupo.

- [ ] **Step 8: Limpar arquivos de teste/debug temporários** se algum ficou
  no servidor durante a verificação (mesmo cuidado do fix do card Rádios).

---

## Self-Review

- **Não-regressão:** nenhuma task toca `alerta_check_sem_inventario`,
  `disco_cheio`, `gat_alertas*`, `portal_alertas_config`. `alertas.php` só
  ganha 1 link; `alertas_tipos.php` só ganha 1 require + 2 entradas de
  array. Verificado lendo os 3 arquivos antes de planejar — todos já
  genéricos.
- **TLS:** webhook usa `http://`, não `https://`, evitando o problema do
  certificado autoassinado no `:7412` (back-gmais não tem
  `InsecureSkipVerify`).
- **Fora de escopo (mantido):** abrir chamado automático (`abre_chamado`),
  editar o back-gmais, autenticação além do token na URL, poda automática de
  `portal_backup_execucoes` (pode virar spec própria se a tabela crescer
  demais — não é problema no primeiro mês com só 2 máquinas).
- **Dependência entre tasks:** 2 depende de 1; 3 e 4 dependem de 1 (e 4
  também lê o que 2 não afeta); 5 é sempre por último.
