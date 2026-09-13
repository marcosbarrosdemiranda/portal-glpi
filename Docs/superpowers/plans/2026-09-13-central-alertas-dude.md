# Central de Alertas — The Dude (rede) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 5 tipos novos no catálogo de alertas, alimentados por push (webhook
GET) do The Dude (192.168.1.246, standalone, sem API REST): **Sem comunicação
(IPs/dispositivos)**, **Enlace offline (VPN/Internet)**, **Latência alta**,
**Serviço offline**, **The Dude não está notificando**. Tudo configurável
pelo painel do portal (ativo/WhatsApp/lembrete por tipo, token do webhook);
o que é monitorado e qual o limiar fica no Dude.

**Spec base:** `Docs/superpowers/specs/2026-09-08-central-alertas-motor-design.md`
§9, com 2 desvios combinados em 2026-09-13:
1. Sem 3º argumento no dispatcher genérico — 5 funções `check` de 2
   argumentos (mesma assinatura de todos os outros tipos), cada uma
   fixando o `tipo` internamente.
2. Painel do token/URL em tela própria (`dude_config.php`), não um bloco
   especial dentro do `alertas_config.php` genérico.

**Architecture:** `dude_lib.php` (novo, paralelo a `backup_lib.php`) cria a
tabela `portal_dude_estado` e expõe as 5 funções de check + 1 render
compartilhado + helpers de token/CRUD. `alertas_tipos.php` ganha 1 require +
5 entradas no catálogo — nada mais nesse arquivo muda.
`the_dude_webhook.php` (novo, sem sessão, GET) recebe a notificação e grava.
`dude_config.php` (novo, com sessão) mostra a URL/token, testa a conexão de
ponta a ponta (self-HTTP real, mesmo caminho que o Dude vai usar) e mostra
"última notificação há X".

**Tech Stack:** igual ao resto — PHP 8.2 + PDO/MariaDB, `CREATE TABLE IF NOT
EXISTS` inline, testes em `wpp/tests/`, deploy por scp + `.new` + `php -l` +
confirmação antes de sobrescrever arquivo já em produção.

## Global Constraints

- **Aditivo.** Nenhuma task edita `alertas.php`, `alertas_config.php`,
  `gat_alertas*`, nem qualquer tipo/arquivo do backup ou do GLPI já
  existente. Dispatcher genérico continua recebendo só `(PDO $pdo, array
  $params)` — sem 3º argumento.
- **Token único global** (não por dispositivo — só existe 1 Dude). Reusa
  `wpp_cfg_get`/`wpp_cfg_set` (`wpp/db.php`), mesmo mecanismo já usado pra
  `grupo_alertas_jid`. Enquanto não houver token gerado, o webhook rejeita
  tudo (não aceita token vazio como "sem autenticação").
- **`tipo` é VARCHAR livre, não ENUM rígido** — evita migração de schema se
  aparecer uma 6ª categoria no futuro (ex.: "Serviço" foi pedido em cima da
  hora; o próximo pode ser assim também).
- **Cuidado com teste em produção (lição do backup-gmais):** ao ativar um
  tipo novo no catálogo sem linha em `portal_alertas_config`, ele nasce
  `ativo=true` E `notif_whatsapp=true` por default. **Antes de qualquer
  teste com o worker rodando**, inserir as 5 linhas em
  `portal_alertas_config` com `notif_whatsapp=0` (mesmo padrão usado pro
  backup). Só ligar de verdade depois que o usuário revisar em Configurar
  Alertas.
- **Webhook GET, autenticado por token na query.** `hash_equals()` pra
  comparação (evita timing attack). Token errado → `403` + log; payload
  inválido (`tipo`/`estado` fora do esperado) → `400`. Nunca lança; sempre
  loga em `portal_wpp_log` (direcao='in') pra alimentar "última notificação".
- **Deploy:** `scp` → `.new` → `php -l` no container → confirmação → `Move-Item`.
  `wpp/tests/run.php` verde antes e depois (não-regressão nos tipos
  existentes: GLPI + backup).

---

## File Structure

| Arquivo | Papel |
|---|---|
| `dude_lib.php` | **novo** — tabela `portal_dude_estado`; token (gera/renova via `wpp_cfg_get/set`); `dude_registrar_estado`; `dude_ultima_notificacao`; 5 `alerta_check_dude_*`; 1 `alerta_render_dude` compartilhado |
| `alertas_tipos.php` | +1 require + 5 entradas no catálogo. Nada mais. |
| `the_dude_webhook.php` | **novo**, raiz do portal, sem sessão — recebe o GET do Dude |
| `dude_config.php` | **novo**, com sessão — token/URL, testar, última notificação, runbook curto |
| `wpp/tests/test_dude_lib.php` | **novo** — testes puros (token, validação de tipo/estado) + testes com banco (registrar/check/resolver) |

---

## Interfaces

```php
// dude_lib.php

function dude_token_atual(PDO $pdo): string
// wpp_cfg_get('dude_token', ''); string vazia = ainda não gerado

function dude_gerar_novo_token(PDO $pdo): string
// bin2hex(random_bytes(16)); grava via wpp_cfg_set('dude_token', ...); retorna o novo

const DUDE_TIPOS_VALIDOS = ['device', 'link', 'latencia', 'service'];

/** Upsert em portal_dude_estado. $tipo já validado pelo chamador. */
function dude_registrar_estado(PDO $pdo, string $tipo, string $chave, string $nome, string $endereco, string $status, string $detalhe): void

function dude_ultima_notificacao(PDO $pdo): ?string
// MAX(atualizado_em) de portal_dude_estado, formato datetime string ou null

// Catálogo — 4 tipos "down atual" + 1 watchdog de silêncio geral
function alerta_check_dude_device(PDO $pdo, array $p): array    // status='down' AND tipo='device'
function alerta_check_dude_link(PDO $pdo, array $p): array      // tipo='link'
function alerta_check_dude_latencia(PDO $pdo, array $p): array  // tipo='latencia'
function alerta_check_dude_service(PDO $pdo, array $p): array   // tipo='service'
// os 4 acima chamam um helper privado dude_check_tipo(PDO, string): array
//   ['chave'=>'dude:<tipo>:<chave>', 'titulo'=>nome ?: chave, 'loja'=>'',
//    'detalhe'=>trim(endereco.' · '.detalhe) . ' (desde <hh:mm>)']

function alerta_check_dude_sem_contato(PDO $pdo, array $p): array
// $h = (int)($p['horas'] ?? 6); compara com dude_ultima_notificacao().
// vazio se token nunca foi gerado (nada rodou ainda, não é "sem contato").

function alerta_render_dude(array $ocorr): string   // tabela genérica, reusada pelos 5 tipos
```

```sql
CREATE TABLE IF NOT EXISTS portal_dude_estado (
    tipo          VARCHAR(20)  NOT NULL,
    chave         VARCHAR(160) NOT NULL,
    nome          VARCHAR(160) DEFAULT '',
    endereco      VARCHAR(120) DEFAULT '',
    status        ENUM('up','down') NOT NULL,
    detalhe       VARCHAR(255) DEFAULT '',
    atualizado_em DATETIME NOT NULL,
    PRIMARY KEY (tipo, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Catálogo (`alertas_tipos.php`), lembrete default **15 min** pros 4 tipos de
rede (backup ficou em 0 — outage de rede é mais urgente de não esquecer):

```php
'dude_device'      => ['nome'=>'Sem comunicação (IPs/dispositivos)', 'params'=>[], 'check'=>'alerta_check_dude_device', 'render'=>'alerta_render_dude', 'icone'=>'bi-hdd-network', 'cor'=>'danger'],
'dude_link'        => ['nome'=>'Enlace offline (VPN/Internet)', 'params'=>[], 'check'=>'alerta_check_dude_link', 'render'=>'alerta_render_dude', 'icone'=>'bi-diagram-3', 'cor'=>'danger'],
'dude_latencia'    => ['nome'=>'Latência alta entre links', 'params'=>[], 'check'=>'alerta_check_dude_latencia', 'render'=>'alerta_render_dude', 'icone'=>'bi-speedometer2', 'cor'=>'warning'],
'dude_service'     => ['nome'=>'Serviço offline (The Dude)', 'params'=>[], 'check'=>'alerta_check_dude_service', 'render'=>'alerta_render_dude', 'icone'=>'bi-hdd-stack', 'cor'=>'danger'],
'dude_sem_contato' => ['nome'=>'The Dude não está notificando', 'params'=>['horas'=>['label'=>'Horas sem notificação','default'=>6,'min'=>1,'max'=>168]], 'check'=>'alerta_check_dude_sem_contato', 'render'=>'alerta_render_dude', 'icone'=>'bi-plug', 'cor'=>'warning'],
```
(`lembrete_min` default 15 é setado na semente da linha em
`portal_alertas_config` na Task 5, não no catálogo — o catálogo não tem
campo de lembrete default, isso é config, não metadado do tipo.)

---

## Task 1: `dude_lib.php` — tabela, token, registrar, checks, testes puros

**Files:** Create `dude_lib.php`, `wpp/tests/test_dude_lib.php`

- [ ] **Step 1:** `dude_lib.php` — `require_once agenda/db.php` + `require_once wpp/db.php`
  (pra `wpp_cfg_get`/`wpp_cfg_set` — mesma dependência que `wpp/gatilhos.php`
  já tem). IIFE criando `portal_dude_estado`. Implementar
  `dude_token_atual`, `dude_gerar_novo_token`, `dude_registrar_estado`,
  `dude_ultima_notificacao`.

- [ ] **Step 2:** Helper privado + 4 wrappers:
  ```php
  function dude_check_tipo(PDO $pdo, string $tipo): array {
      $st = $pdo->prepare("SELECT chave, nome, endereco, detalhe, atualizado_em
                            FROM portal_dude_estado WHERE tipo = ? AND status = 'down'
                            ORDER BY atualizado_em");
      $st->execute([$tipo]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $desde = date('H:i', strtotime($r['atualizado_em']));
          $out[] = [
              'chave'   => 'dude:' . $tipo . ':' . $r['chave'],
              'titulo'  => $r['nome'] !== '' ? $r['nome'] : $r['chave'],
              'loja'    => '',
              'detalhe' => trim(trim($r['endereco'] . ' · ' . $r['detalhe'], ' ·')) . " (desde {$desde})",
          ];
      }
      return $out;
  }
  function alerta_check_dude_device(PDO $pdo, array $p): array   { return dude_check_tipo($pdo, 'device'); }
  function alerta_check_dude_link(PDO $pdo, array $p): array     { return dude_check_tipo($pdo, 'link'); }
  function alerta_check_dude_latencia(PDO $pdo, array $p): array { return dude_check_tipo($pdo, 'latencia'); }
  function alerta_check_dude_service(PDO $pdo, array $p): array  { return dude_check_tipo($pdo, 'service'); }
  ```

- [ ] **Step 3:** `alerta_check_dude_sem_contato`:
  ```php
  function alerta_check_dude_sem_contato(PDO $pdo, array $p): array {
      $ultima = dude_ultima_notificacao($pdo);
      if ($ultima === null) return []; // nunca configurado/nunca recebeu nada -> não é "silêncio", é "nunca usado"
      $horas = (int) ($p['horas'] ?? 6);
      $decorrido = time() - strtotime($ultima);
      if ($decorrido < $horas * 3600) return [];
      $h = max(0, (int) floor($decorrido / 3600));
      return [[
          'chave' => 'dude:sem_contato', 'titulo' => 'The Dude não está notificando',
          'loja' => '', 'detalhe' => "última notificação há {$h}h — verifique o Dude/rede até ele",
      ]];
  }
  ```

- [ ] **Step 4:** `alerta_render_dude` — tabela genérica igual às outras
  (`vazio` quando `[]`, `<table>` com Máquina/Detalhe quando tem dado).

- [ ] **Step 5: Testes puros** em `test_dude_lib.php` (sem banco): token
  tem 32 chars e não repete.

- [ ] **Step 6: Testes com banco:** registrar down → aparece no check do
  tipo certo, não aparece nos outros tipos; registrar up pro mesmo
  (tipo,chave) → some; `dude_ultima_notificacao` reflete o registro mais
  recente; `alerta_check_dude_sem_contato` vazio com token nunca gerado,
  vazio com contato recente, populado com `atualizado_em` forçado pro
  passado.

- [ ] **Step 7:** `php -l dude_lib.php` no deploy.

- [ ] **Step 8: Commit**
  ```bash
  git add dude_lib.php wpp/tests/test_dude_lib.php
  git commit -m "feat: dude_lib - tabela de estado, token e os 5 checks do The Dude"
  ```

---

## Task 2: catálogo — 5 tipos novos em `alertas_tipos.php`

**Files:** Modify `alertas_tipos.php` (só acréscimo)

- [ ] **Step 1:** `require_once __DIR__ . '/dude_lib.php';` no topo, junto
  dos outros requires.
- [ ] **Step 2:** as 5 entradas em `alertas_catalogo()` (ver Interfaces).
- [ ] **Step 3:** `php -l` no deploy; rodar `wpp/tests/run.php` — confirmar
  que os tipos antigos (GLPI + backup) continuam 100% verdes.
- [ ] **Step 4: Commit**
  ```bash
  git add alertas_tipos.php
  git commit -m "feat: central de alertas - 5 tipos do The Dude no catalogo"
  ```

---

## Task 3: `the_dude_webhook.php` — receptor

**Files:** Create `the_dude_webhook.php`

- [ ] **Step 1:** Boot em try/catch (mesmo padrão de `webhook_backup.php`),
  mas aqui os erros de validação viram status HTTP de verdade (o Dude não
  reentrega em loop feito o back-gmais, e status code ajuda a debugar a
  Notification no cliente):
  ```php
  <?php
  header('Content-Type: application/json');
  http_response_code(200); // default; sobrescrito abaixo se inválido

  $boot_ok = true;
  try {
      require_once __DIR__ . '/agenda/db.php';
      require_once __DIR__ . '/dude_lib.php';
  } catch (\Throwable $e) {
      $boot_ok = false;
      error_log('the_dude_webhook: boot falhou: ' . $e->getMessage());
  }

  if (!$boot_ok) { echo json_encode(['ok' => false]); exit; }

  global $pdo;
  $token = (string) ($_GET['token'] ?? '');
  $atual = dude_token_atual($pdo);
  if ($atual === '' || !hash_equals($atual, $token)) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'erro' => 'token invalido']);
      exit;
  }

  $tipo   = (string) ($_GET['tipo'] ?? '');
  $estado = (string) ($_GET['estado'] ?? '');
  if (!in_array($tipo, DUDE_TIPOS_VALIDOS, true) || !in_array($estado, ['up', 'down'], true)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'erro' => 'tipo ou estado invalido']);
      exit;
  }

  $chave    = trim((string) ($_GET['chave'] ?? ''));
  $nome     = trim((string) ($_GET['nome'] ?? ''));
  $endereco = trim((string) ($_GET['addr'] ?? ''));
  $loja     = trim((string) ($_GET['loja'] ?? '')); // fixo por mapa do Dude (ex.: "Loja 05")
  $detalhe  = trim((string) ($_GET['detalhe'] ?? ''));
  if ($chave === '') $chave = $nome !== '' ? $nome : ($endereco !== '' ? $endereco : 'sem-id');

  try {
      dude_registrar_estado($pdo, $tipo, $chave, $nome, $endereco, $loja, $estado, $detalhe);
  } catch (\Throwable $e) {
      error_log('the_dude_webhook: ' . $e->getMessage());
  }

  echo json_encode(['ok' => true]);
  ```
  > Nota (2026-09-13): assinatura de `dude_registrar_estado` ganhou o
  > parâmetro `$loja` entre `$endereco` e `$status` — ver commit `fa6b2e2`.
- [ ] **Step 2:** `php -l` no deploy.
- [ ] **Step 3: Teste manual com curl** (Task 5) simulando o Dude: token
  errado → 403; tipo inválido → 400; down → aparece no check certo; up →
  some.
- [ ] **Step 4: Commit**
  ```bash
  git add the_dude_webhook.php
  git commit -m "feat: the_dude_webhook - recebe notificacao de estado do The Dude"
  ```

---

## Task 4: `dude_config.php` — token, URL, testar, última notificação

**Files:** Create `dude_config.php`; Modify `alertas_config.php` (1 link)

- [ ] **Step 1:** Página com sessão (mesmo guard de `backup_maquinas.php`).
  Mostra: token atual (mascarado, com botão mostrar/copiar), URL montada
  (`https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php?token=<T>`),
  botão **Gerar novo token** (confirma antes — invalida o antigo na hora),
  botão **Testar conexão** (faz uma requisição HTTP real pra própria URL
  com `tipo=device&chave=__teste_dude__&estado=down`, espera 200/ok,
  manda de novo com `estado=up` pra não deixar ocorrência de teste
  pendurada — mesmo cuidado do bug do backup), "Última notificação
  recebida: há Xh" (ou "nunca" se token nunca gerado). Runbook curto:
  passo a passo de configurar a Notification no cliente do Dude
  (Settings → Notifications → tipo Execute/HTTP, variáveis `[Device.Name]`
  etc., disparar em down **e** up) — **incluindo `&loja=<nome fixo>`** na
  URL de cada mapa (usuário tem 4 mapas, um por loja; ver [[central-alertas-dude]]).
- [ ] **Step 2:** link em `alertas_config.php` (topbar, ao lado de
  "Máquinas de backup"):
  ```php
  <a href="dude_config.php"><i class="bi bi-diagram-3 me-1"></i>The Dude</a>
  ```
- [ ] **Step 3:** `php -l dude_config.php`.
- [ ] **Step 4: Commit**
  ```bash
  git add dude_config.php alertas_config.php
  git commit -m "feat: dude_config - token/URL do webhook, testar conexao, ultima notificacao"
  ```

---

## Task 5: Deploy + anti-blast + verificação end-to-end

- [ ] **Step 1:** `scp` de todos os arquivos novos/alterados como `.new`;
  `php -l` em cada um no container.
- [ ] **Step 2:** **Antes de mover qualquer `.new` pro lugar**, inserir as
  5 linhas em `portal_alertas_config` com `ativo=1, notif_whatsapp=0,
  lembrete_min=15` (0 pro `dude_sem_contato`, que é watchdog único, não
  precisa lembrete) — feito via script direto no banco, não pela UI (a UI
  só existe depois do deploy). Isso evita repetir o vazamento de mensagem
  que aconteceu com o backup.
- [ ] **Step 3:** `Move-Item -Force` de cada `.new` (com confirmação antes,
  igual sempre).
- [ ] **Step 4:** `wpp/tests/run.php` — 0 falhas novas (mesmas 27
  pré-existentes de chatbot/atribuição, não relacionadas).
- [ ] **Step 5:** Gerar o token em `dude_config.php`, usar "Testar conexão"
  — confirma path completo (nginx + TLS + hostname, igual ao backup).
- [ ] **Step 6:** Passar a URL + token pro usuário configurar 1 Notification
  de teste no cliente do Dude (1 device qualquer), disparar down manualmente
  se o Dude permitir, conferir que aparece em `alertas.php` sem mandar
  WhatsApp (notif_whatsapp ainda 0).
- [ ] **Step 7:** Só depois de validado: usuário liga `notif_whatsapp` dos
  tipos que quiser em Configurar Alertas.

---

## Self-Review

- **Não-regressão:** catálogo genérico intocado; `alertas.php`/
  `alertas_config.php`/`gat_alertas*` não mudam. Confirmado lendo o código
  antes de planejar (mesma verificação feita pro backup).
- **Sem 3º argumento no dispatcher** — desvio consciente do spec original
  §9, mantendo a assinatura `(PDO, array)` de todo tipo do catálogo.
- **Token único, não por dispositivo** — só existe 1 Dude; token vazio
  nunca é tratado como "sem autenticação" (webhook rejeita até alguém
  gerar o primeiro token em `dude_config.php`).
- **Fora de escopo:** abrir chamado automático; ler o Dude pela porta 2210
  (protocolo binário) — descartado, é 100% push; debounce de flapping —
  fica a cargo da configuração do probe dentro do próprio Dude.
