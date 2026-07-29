# Projetos via GitHub Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flaky network-folder source of the Projetos module with GitHub repositories that each technician registers via their own personal access token, grouped into 3 manually-controlled status sections (Futuros / Em Execução / Concluídos).

**Architecture:** A new dependency-free library file (`github_client.php`) wraps the two GitHub REST calls needed (test a token, list a user's own non-fork repos). `projetos.php` gains two new tables (GitHub accounts per user, manual status per repo), an "accounts" panel with add/edit/delete/test, and — building on that — a 3-section repo listing that replaces the old andamento/concluídos card list. The legacy network-folder code (parsing, detail view, Gantt, export) stays in the file untouched but unused by the main list view.

**Tech Stack:** PHP 8.2, PDO/MySQL (MariaDB 10.4), cURL (already used elsewhere in this codebase, e.g. `perfis.php`), Bootstrap 5.3 + Bootstrap Icons (CDN), vanilla JS (fetch). Deployed via `scp` to the Docker container `glpi-web` on `192.168.1.198:7412`, same workflow as every other page in this repo.

## Global Constraints

- No automated test framework exists in this codebase. Verification = `php -l` syntax check inside the `glpi-web` container, an HTTP smoke check (`curl` expecting `302`), and — for `github_client.php` specifically — a real round-trip against `api.github.com` with a deliberately invalid token (no real credentials needed, confirms the HTTP/JSON plumbing works end to end).
- Every new/changed file must be synced to the production server via `scp` to `glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/<relative path>"` immediately after it's written.
- Comments in code: Portuguese. Commit messages: English subject conventionally (`feat:`/`fix:`), body in Portuguese — matches the actual convention already used on this branch (see prior commits `0407d17`, `8de98b8`).
- GitHub tokens are secrets: never logged, never sent to the browser/JS, encrypted at rest with the existing `vault_encrypt()`/`vault_decrypt()` from `vault_crypto.php` (same scheme Cofre TI already uses), decrypted only server-side, just-in-time for the API call.
- All state-changing AJAX endpoints (`gh_action=conta_add|conta_save|conta_delete|status_set`) are POST-only, gated by a `Content-Type: application/json` check before any DB write — this is the CSRF fix already applied to `ferramentas_gmais.php` (commit `858505c`) after code review; do not reintroduce a GET-based mutation.
- Every account/status query is scoped to `WHERE user_id = ?` / a `JOIN`/ownership check against `$_SESSION['user_id']` — a user must never be able to read, edit, or delete another user's GitHub account row, even by guessing an `id`.
- All output derived from external input (GitHub API responses, user-entered apelido) goes through the existing `esc()` helper (`projetos.php:139`, `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`) before being echoed into HTML.
- Do not modify or remove any of the existing legacy code (`parseProjeto`, `carregarProjetosDaPasta`, the Gantt/forecast block, the `.md` download/export action, the detail view branch) — it must keep working exactly as today if the network folder ever becomes reachable again. Only the **list view** (`elseif (!$modoDetalhe)` branch) is replaced.

---

## File Structure

- **Create `github_client.php`** (repo root, alongside `agenda/db.php`) — two pure functions, no HTML, no session/DB access: `github_testar_token(string $token): array` and `github_listar_repos(string $token): array`. Single responsibility: talk to `api.github.com`, return plain arrays, never throw.
- **Modify `projetos.php`** — three localized additions, each independently testable:
  1. Near the top (after the existing auth-guard lines, before `function parseProjeto`): new `require_once`s, the two new `CREATE TABLE IF NOT EXISTS`, and the `gh_action` AJAX dispatch block for account CRUD.
  2. Inside the `elseif (!$modoDetalhe)` branch (today's "LISTA DE CARDS" section, `projetos.php:706-750` before this plan's edits): replaced with the "Minhas Contas GitHub" panel + modal + JS (Task 2), then extended with the repo-fetch + 3-section rendering + status dropdown (Task 3).
  3. The bottom-of-file search filter script (today `projetos.php:1155-1168`) retargeted from the old `.proj-card` class to the new `.gh-proj-card` class (Task 3, since that's when the new class starts existing).

---

### Task 1: `github_client.php` — GitHub API client

**Files:**
- Create: `github_client.php`

**Interfaces:**
- Consumes: nothing (no dependency on the rest of the app — pure HTTP client).
- Produces: `github_testar_token(string $token): array` returning `['ok'=>bool, 'login'=>?string, 'msg'=>string]`; `github_listar_repos(string $token): array` returning either a **flat list** of repo arrays (each with keys `nome, descricao, url, privado, linguagem, ultimo_push, issues_abertas, arquivado`) on success, or `['erro'=>string]` on failure. Both are consumed by Task 2 (`github_testar_token`) and Task 3 (`github_listar_repos`).

- [ ] **Step 1: Write `github_client.php`**

```php
<?php
/**
 * github_client.php — chamadas à API do GitHub (api.github.com)
 * Sem HTML/sessão — só HTTP + parsing. Usado por projetos.php.
 */

function github_testar_token(string $token): array {
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) return ['ok' => false, 'login' => null, 'msg' => 'Falha de conexão com o GitHub'];
    if ($code !== 200) return ['ok' => false, 'login' => null, 'msg' => 'Token inválido ou sem permissão (HTTP ' . $code . ')'];

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['login'])) return ['ok' => false, 'login' => null, 'msg' => 'Resposta inesperada do GitHub'];

    return ['ok' => true, 'login' => $data['login'], 'msg' => 'OK'];
}

function github_listar_repos(string $token): array {
    $repos  = [];
    $pagina = 1;
    do {
        $ch = curl_init('https://api.github.com/user/repos?affiliation=owner&per_page=100&sort=pushed&page=' . $pagina);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: portal-glpi',
            ],
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) return ['erro' => 'Falha de conexão com o GitHub'];
        if ($code !== 200) return ['erro' => 'GitHub retornou HTTP ' . $code];

        $pageData = json_decode($body, true);
        if (!is_array($pageData)) return ['erro' => 'Resposta inesperada do GitHub'];

        foreach ($pageData as $r) {
            if (!empty($r['fork'])) continue;
            $repos[] = [
                'nome'           => $r['name'] ?? '',
                'descricao'      => $r['description'] ?? '',
                'url'            => $r['html_url'] ?? '',
                'privado'        => !empty($r['private']),
                'linguagem'      => $r['language'] ?? '',
                'ultimo_push'    => $r['pushed_at'] ?? null,
                'issues_abertas' => (int)($r['open_issues_count'] ?? 0),
                'arquivado'      => !empty($r['archived']),
            ];
        }

        $pagina++;
        $continua = count($pageData) === 100;
    } while ($continua);

    return $repos;
}
```

- [ ] **Step 2: Deploy to the server**

```bash
scp "C:/claude code/portal-glpi/github_client.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/github_client.php"
```

- [ ] **Step 3: Lint-check on the server**

```bash
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/github_client.php"
```
Expected: `No syntax errors detected in /var/www/html/glpi2/portal-glpi/github_client.php`

- [ ] **Step 4: Real round-trip smoke test against api.github.com (no real token needed)**

Create a local scratch file (do NOT commit this — it's deleted in Step 6) at `C:/claude code/portal-glpi/github_client_smoketest.php`:

```php
<?php
require __DIR__ . '/github_client.php';
$r = github_testar_token('ghp_deliberatelyInvalidToken0000000000');
echo json_encode($r), "\n";
```

Deploy it next to `github_client.php`:

```bash
scp "C:/claude code/portal-glpi/github_client_smoketest.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/github_client_smoketest.php"
```

Run it inside the container (this makes a real HTTPS call to `api.github.com` — confirms the container has outbound internet access and that the function correctly parses a real 401 response):

```bash
ssh glpi-server "docker exec glpi-web php /var/www/html/glpi2/portal-glpi/github_client_smoketest.php"
```

Expected output: a JSON line with `"ok":false` and a `"msg"` mentioning HTTP 401, e.g. `{"ok":false,"login":null,"msg":"Token inv\u00e1lido ou sem permiss\u00e3o (HTTP 401)"}`.

If the container cannot reach `api.github.com` at all (curl error, `errno` branch), Step 4 will instead show `"msg":"Falha de conex\u00e3o com o GitHub"` — if that happens, STOP and report BLOCKED: it means the container has no outbound internet access, which is a Docker/network configuration problem outside this task's scope, not a bug in `github_client.php`.

- [ ] **Step 5: Delete the scratch smoke-test file, locally and on the server**

```bash
rm "C:/claude code/portal-glpi/github_client_smoketest.php"
ssh glpi-server "del \"D:\\docker\\glpi-portal\\glpi2\\portal-glpi\\github_client_smoketest.php\""
```

- [ ] **Step 6: Commit**

```bash
git add github_client.php
git commit -m "$(cat <<'EOF'
feat: adiciona cliente da API do GitHub (github_client.php)

Duas funcoes puras (sem HTML/sessao): testar um token e listar
repositorios proprios (nao-fork) de uma conta. Usado pelo modulo
Projetos para substituir a origem de dados por pasta de rede.
EOF
)"
```

---

### Task 2: Contas GitHub — tabelas, painel e CRUD

**Files:**
- Modify: `projetos.php` (insert after line 4, before `function parseProjeto`; replace the "LISTA DE CARDS" block, `projetos.php:698-750` in the current file, i.e. the `<?php if (!$projetos): ?> ... <?php elseif (!$modoDetalhe): ?> ... <?php else: ?>` structure up through the `toggleConcluidos` `</script>`)

**Interfaces:**
- Consumes: `github_testar_token(string $token): array` from Task 1 (`github_client.php`); `vault_encrypt(string): string` / `vault_decrypt(string): string` from the existing `vault_crypto.php`; the existing `esc(string): string` helper already defined at `projetos.php:139`.
- Produces: the table `portal_github_contas` (columns: `id, user_id, apelido, usuario_github, token_enc, ativo, ultimo_teste_ok, ultima_verificacao, criado_em`) and `portal_projetos_status` (columns: `id, conta_id, repo_nome, status, atualizado_em`, `UNIQUE(conta_id, repo_nome)`, `FOREIGN KEY(conta_id) REFERENCES portal_github_contas(id) ON DELETE CASCADE`) — both created together in this task since the second has a hard FK dependency on the first, even though `portal_projetos_status` isn't read/written until Task 3. Also produces the PHP variable `$minhasContas` (array of the logged-in user's account rows) and `$uid` (int, `$_SESSION['user_id']`), both consumed by Task 3.

- [ ] **Step 1: Insert requires, table creation, and the accounts AJAX dispatch**

Find (`projetos.php:1-4`, exact current content):
```php
<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }
```

Insert immediately after it (before the blank line and `// ── Parser de projeto Markdown ──` comment that currently follows):

```php

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/github_client.php';

$uid = (int)($_SESSION['user_id'] ?? 0);

// ── Tabelas de contas GitHub e status manual dos projetos ──────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_github_contas (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        user_id            INT           NOT NULL,
        apelido            VARCHAR(60)   NOT NULL,
        usuario_github     VARCHAR(100)  NOT NULL,
        token_enc          TEXT          NOT NULL,
        ativo              TINYINT(1)    DEFAULT 1,
        ultimo_teste_ok    TINYINT(1)    DEFAULT NULL,
        ultima_verificacao DATETIME      DEFAULT NULL,
        criado_em          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_projetos_status (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        conta_id      INT           NOT NULL,
        repo_nome     VARCHAR(255)  NOT NULL,
        status        ENUM('futuro','em_execucao','concluido') NOT NULL DEFAULT 'em_execucao',
        atualizado_em TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_conta_repo (conta_id, repo_nome),
        FOREIGN KEY (conta_id) REFERENCES portal_github_contas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX: contas GitHub (cada usuário só mexe nas próprias) ────
$ghAction = $_GET['gh_action'] ?? '';
if ($ghAction) {
    header('Content-Type: application/json');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        echo json_encode(['ok'=>false,'msg'=>'Requisição inválida']); exit;
    }
    if (!$uid) { echo json_encode(['ok'=>false,'msg'=>'Sessão inválida']); exit; }

    if ($ghAction === 'conta_add' || $ghAction === 'conta_save') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $apelido = trim($body['apelido'] ?? '');
        $usuario = trim($body['usuario_github'] ?? '');
        $token   = trim($body['token'] ?? '');
        $id      = (int)($body['id'] ?? 0);

        if (!$apelido || !$usuario) { echo json_encode(['ok'=>false,'msg'=>'Apelido e usuário são obrigatórios']); exit; }

        // Edição sem novo token = mantém o token atual, não re-testa
        if ($ghAction === 'conta_save' && $id && $token === '') {
            $st = $pdo->prepare("UPDATE portal_github_contas SET apelido=?, usuario_github=? WHERE id=? AND user_id=?");
            $st->execute([$apelido, $usuario, $id, $uid]);
            echo json_encode(['ok'=>true]); exit;
        }

        if (!$token) { echo json_encode(['ok'=>false,'msg'=>'Token é obrigatório']); exit; }
        $teste = github_testar_token($token);
        if (!$teste['ok']) { echo json_encode(['ok'=>false,'msg'=>'Token inválido: '.$teste['msg']]); exit; }

        $tokenEnc = vault_encrypt($token);
        if ($ghAction === 'conta_add') {
            $st = $pdo->prepare("INSERT INTO portal_github_contas (user_id,apelido,usuario_github,token_enc,ultimo_teste_ok,ultima_verificacao) VALUES (?,?,?,?,1,NOW())");
            $st->execute([$uid, $apelido, $usuario, $tokenEnc]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        } else {
            $st = $pdo->prepare("UPDATE portal_github_contas SET apelido=?, usuario_github=?, token_enc=?, ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=? AND user_id=?");
            $st->execute([$apelido, $usuario, $tokenEnc, $id, $uid]);
            echo json_encode(['ok'=>true]);
        }
        exit;
    }

    if ($ghAction === 'conta_testar') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT token_enc FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$id, $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $teste = github_testar_token(vault_decrypt($row['token_enc']));
        $pdo->prepare("UPDATE portal_github_contas SET ultimo_teste_ok=?, ultima_verificacao=NOW() WHERE id=?")
            ->execute([$teste['ok'] ? 1 : 0, $id]);
        echo json_encode($teste);
        exit;
    }

    if ($ghAction === 'conta_delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM portal_github_contas WHERE id=? AND user_id=?")->execute([$id, $uid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Minhas contas GitHub (para o painel e, na próxima etapa, a listagem) ──
$minhasContas = [];
if ($uid) {
    $st = $pdo->prepare("SELECT * FROM portal_github_contas WHERE user_id=? ORDER BY criado_em");
    $st->execute([$uid]);
    $minhasContas = $st->fetchAll(PDO::FETCH_ASSOC);
}
```

- [ ] **Step 2: Add the CSS for the accounts panel**

Find (`projetos.php`, inside the `<style>` block, the closing of the `.badge-obsidian` rule — search for this exact line):
```css
.badge-obsidian { background:#7c3aed; color:#fff; font-size:.65rem;
                  padding:.15rem .5rem; border-radius:8px; font-weight:600; }
```
Insert immediately after it:
```css

/* ── Contas GitHub ─────────────────────────────────────────── */
.gh-contas-section { margin-bottom: 1.5rem; }
.gh-contas-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.75rem; }
.gh-conta-card { background:#fff; border:2px solid #e5e7eb; border-radius:12px;
                 padding:1rem; position:relative; transition:all .15s; }
.gh-conta-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.gh-conta-topo { display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem; }
.gh-conta-badge.ok   { color:#1e8e3e; }
.gh-conta-badge.erro { color:#d93025; }
.gh-conta-cfg { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; }
.gh-conta-cfg:hover { color:#1a237e; }
.gh-conta-apelido { font-weight:700; font-size:.88rem; }
.gh-conta-usuario { font-size:.75rem; color:#6b7280; }
.gh-conta-add { display:flex; flex-direction:column; align-items:center; justify-content:center;
                gap:.35rem; min-height:64px; border:2px dashed #d1d5db; color:#9ca3af;
                cursor:pointer; border-radius:12px; }
.gh-conta-add:hover { border-color:#1a237e; color:#1a237e; }
```

- [ ] **Step 3: Replace the "LISTA DE CARDS" block**

Find this exact block (current `projetos.php:698-750`, from the empty-state check through the `toggleConcluidos` script):
```php
<?php if (!$projetos): ?>
  <div class="card-box text-center py-5 text-muted">
    <i class="bi bi-folder-x fs-1 d-block mb-2"></i>
    <p>Nenhum projeto em <code>Docs/wiki/projects/</code></p>
    <p class="small">Crie um arquivo <code>.md</code> no Obsidian para aparecer aqui.</p>
  </div>

<?php elseif (!$modoDetalhe): ?>
  <!-- ═══════════════ LISTA DE CARDS ═══════════════ -->
  <?php
  $projetosAndamento = array_values(array_filter($projetos, fn($p) => $p['pct'] < 100));
  $projetosConcluidos = array_values(array_filter($projetos, fn($p) => $p['pct'] >= 100));
  ?>

  <div id="secAndamento">
    <h6 class="fw-bold mb-2" style="color:#374151">
      <i class="bi bi-hourglass-split me-2 text-primary"></i>Em andamento
      <span class="text-muted fw-normal">(<?= count($projetosAndamento) ?>)</span>
    </h6>
    <?php if ($projetosAndamento): ?>
      <div class="row g-3 mb-4">
        <?php foreach ($projetosAndamento as $p) renderProjCard($p); ?>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-4">Nenhum projeto em andamento.</p>
    <?php endif; ?>
  </div>

  <div id="secConcluidos">
    <h6 class="fw-bold mb-2" style="color:#374151;cursor:pointer" onclick="toggleConcluidos()">
      <i class="bi bi-check-circle-fill me-2 text-success"></i>Concluídos
      <span class="text-muted fw-normal">(<?= count($projetosConcluidos) ?>)</span>
      <i class="bi bi-chevron-down ms-1" id="chvConcluidos" style="font-size:.75rem;transition:transform .2s"></i>
    </h6>
    <?php if ($projetosConcluidos): ?>
      <div class="row g-3" id="gridConcluidos" style="display:none">
        <?php foreach ($projetosConcluidos as $p) renderProjCard($p); ?>
      </div>
    <?php else: ?>
      <p class="text-muted small">Nenhum projeto concluído ainda.</p>
    <?php endif; ?>
  </div>

  <script>
  function toggleConcluidos() {
    const grid = document.getElementById('gridConcluidos');
    const chv  = document.getElementById('chvConcluidos');
    if (!grid) return;
    const aberto = grid.style.display !== 'none';
    grid.style.display = aberto ? 'none' : '';
    chv.style.transform = aberto ? '' : 'rotate(-180deg)';
  }
  </script>

<?php else: ?>
```

Replace it with:
```php
<?php if (!$modoDetalhe): ?>
  <!-- ═══════════════ CONTAS GITHUB ═══════════════ -->
  <div class="gh-contas-section">
    <h6 class="fw-bold mb-2" style="color:#374151">
      <i class="bi bi-github me-2"></i>Minhas Contas GitHub
    </h6>
    <div class="gh-contas-grid">
      <?php foreach ($minhasContas as $c): ?>
        <div class="gh-conta-card">
          <div class="gh-conta-topo">
            <span class="gh-conta-badge <?= $c['ultimo_teste_ok'] ? 'ok' : 'erro' ?>">
              <i class="bi <?= $c['ultimo_teste_ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
            </span>
            <button type="button" class="gh-conta-cfg" onclick='editarConta(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Editar">
              <i class="bi bi-gear-fill"></i>
            </button>
          </div>
          <div class="gh-conta-apelido"><?= esc($c['apelido']) ?></div>
          <div class="gh-conta-usuario">@<?= esc($c['usuario_github']) ?></div>
        </div>
      <?php endforeach; ?>
      <div class="gh-conta-card gh-conta-add" onclick="abrirModalConta()">
        <i class="bi bi-plus-circle" style="font-size:1.5rem"></i>
        <div class="gh-conta-apelido">Adicionar</div>
      </div>
    </div>
  </div>

  <!-- ═══════════════ PROJETOS (próxima etapa) ═══════════════ -->
  <div class="text-muted small mt-4" id="gh-projetos-placeholder">
    Cadastre uma conta GitHub acima para ver seus projetos aqui.
  </div>

<?php else: ?>
```

Note the removed `<?php if (!$projetos): ?>` empty-state branch — it referred to the legacy network-folder emptiness, which no longer governs what the list view shows (the new view has its own empty state, "Cadastre uma conta GitHub..."). `$projetos` (from the legacy loader) is still computed earlier in the file and still used by the untouched detail-view branch below (`<?php else: ?>` for `$modoDetalhe`), so nothing else breaks.

- [ ] **Step 4: Add the account modal + JS**

Find the closing `</body>` tag near the end of the file (`projetos.php`, after the existing filter `<script>` block that is gated by `<?php if (!$modoDetalhe): ?>` around `document.getElementById('searchProj')`). Insert immediately before `</body>`:

```html
<!-- Modal: adicionar/editar conta GitHub -->
<div class="modal fade" id="modalConta" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalContaTitulo"><i class="bi bi-github me-2"></i>Nova Conta GitHub</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="conta-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Apelido</label>
          <input type="text" class="form-control" id="conta-apelido" placeholder="Ex: Pessoal"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário GitHub</label>
          <input type="text" class="form-control" id="conta-usuario" placeholder="ex: joaosilva"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Personal Access Token</label>
          <input type="password" class="form-control font-monospace" id="conta-token" placeholder="ghp_..." autocomplete="new-password"/>
          <div class="form-text" id="conta-token-hint">Somente leitura, escopo <code>repo</code>. <a href="https://github.com/settings/tokens?type=beta" target="_blank" rel="noopener">Gerar token</a></div>
        </div>
        <div id="conta-erro" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="btn-excluir-conta" style="display:none" onclick="excluirConta()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarConta()" style="background:#1a237e;border-color:#1a237e"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<script>
let modalConta;
document.addEventListener('DOMContentLoaded', () => {
  const elConta = document.getElementById('modalConta');
  if (elConta) modalConta = new bootstrap.Modal(elConta);
});

function abrirModalConta() {
  document.getElementById('conta-id').value = '';
  document.getElementById('conta-apelido').value = '';
  document.getElementById('conta-usuario').value = '';
  document.getElementById('conta-token').value = '';
  document.getElementById('conta-token').placeholder = 'ghp_...';
  document.getElementById('conta-erro').style.display = 'none';
  document.getElementById('modalContaTitulo').innerHTML = '<i class="bi bi-github me-2"></i>Nova Conta GitHub';
  document.getElementById('btn-excluir-conta').style.display = 'none';
  modalConta.show();
}

function editarConta(c) {
  document.getElementById('conta-id').value = c.id;
  document.getElementById('conta-apelido').value = c.apelido;
  document.getElementById('conta-usuario').value = c.usuario_github;
  document.getElementById('conta-token').value = '';
  document.getElementById('conta-token').placeholder = 'Deixe em branco para manter o token atual';
  document.getElementById('conta-erro').style.display = 'none';
  document.getElementById('modalContaTitulo').textContent = c.apelido;
  document.getElementById('btn-excluir-conta').style.display = 'inline-block';
  modalConta.show();
}

async function salvarConta() {
  const id             = document.getElementById('conta-id').value;
  const apelido        = document.getElementById('conta-apelido').value.trim();
  const usuario_github = document.getElementById('conta-usuario').value.trim();
  const token          = document.getElementById('conta-token').value.trim();
  const erroEl         = document.getElementById('conta-erro');
  erroEl.style.display = 'none';

  if (!apelido || !usuario_github) {
    erroEl.textContent = 'Preencha apelido e usuário.';
    erroEl.style.display = '';
    return;
  }

  const action = id ? 'conta_save' : 'conta_add';
  const r = await fetch(`projetos.php?gh_action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, apelido, usuario_github, token}),
  });
  const d = await r.json();
  if (d.ok) { modalConta.hide(); location.reload(); }
  else { erroEl.textContent = d.msg || 'Erro ao salvar'; erroEl.style.display = ''; }
}

async function excluirConta() {
  const id = document.getElementById('conta-id').value;
  if (!id || !confirm('Excluir esta conta GitHub? Os projetos dela deixarão de aparecer.')) return;
  const r = await fetch('projetos.php?gh_action=conta_delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (d.ok) { modalConta.hide(); location.reload(); }
  else alert(d.msg || 'Erro ao excluir');
}
</script>
```

- [ ] **Step 5: Deploy, lint, smoke-check**

```bash
scp "C:/claude code/portal-glpi/projetos.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/projetos.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/projetos.php"
ssh glpi-server "curl -4 -s -o NUL -w \"projetos: %{http_code}\n\" http://192.168.1.198:7412/glpi2/portal-glpi/projetos.php"
```
Expected: `No syntax errors detected ...` and `projetos: 302`.

If either fails, fix, redeploy, and re-check before continuing.

- [ ] **Step 6: Commit**

```bash
git add projetos.php
git commit -m "$(cat <<'EOF'
feat: painel de contas GitHub em Projetos (CRUD + teste de token)

Adiciona tabelas portal_github_contas e portal_projetos_status,
endpoints AJAX (gh_action=conta_add|conta_save|conta_delete|conta_testar)
restritos ao usuario logado, e o painel "Minhas Contas GitHub" na lista
de projetos. Codigo legado (pasta de rede, view de detalhe, export)
permanece intacto e nao e mais chamado pela lista principal.
EOF
)"
```

---

### Task 3: Listagem de projetos por status (3 seções) + troca de status

**Files:**
- Modify: `projetos.php` (extend the `gh_action` dispatch added in Task 2 with a `status_set` branch; replace the `#gh-projetos-placeholder` div from Task 2 with the real 3-section rendering; add CSS for the new card/section classes; retarget the bottom-of-file search filter script)

**Interfaces:**
- Consumes: `github_listar_repos(string $token): array` from Task 1; `$minhasContas`, `$uid`, `vault_decrypt()`, `esc()` from Task 2 / existing file.
- Produces: nothing consumed by a later task (this is the last task).

- [ ] **Step 1: Add the `status_set` branch to the existing `gh_action` dispatch**

Find (added by Task 2, inside the `if ($ghAction) { ... }` block):
```php
    if ($ghAction === 'conta_delete') {
```
Insert immediately before it:
```php
    if ($ghAction === 'status_set') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $contaId  = (int)($body['conta_id'] ?? 0);
        $repoNome = trim($body['repo_nome'] ?? '');
        $status   = $body['status'] ?? '';
        if (!in_array($status, ['futuro','em_execucao','concluido'], true)) {
            echo json_encode(['ok'=>false,'msg'=>'Status inválido']); exit;
        }
        if (!$repoNome) { echo json_encode(['ok'=>false,'msg'=>'Repositório inválido']); exit; }

        // Confirma que a conta pertence ao usuário logado antes de gravar
        $st = $pdo->prepare("SELECT id FROM portal_github_contas WHERE id=? AND user_id=?");
        $st->execute([$contaId, $uid]);
        if (!$st->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Conta não encontrada']); exit; }

        $pdo->prepare("
            INSERT INTO portal_projetos_status (conta_id, repo_nome, status)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ")->execute([$contaId, $repoNome, $status]);
        echo json_encode(['ok'=>true]);
        exit;
    }

```

- [ ] **Step 2: Add the helper `dataRelativa()`**

Find (existing function definition, `projetos.php` — search for the `esc()` function added at line 139 in the original file):
```php
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```
Insert immediately after it:
```php

function dataRelativa(?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 3600)     return 'há ' . max(1, (int)($diff / 60)) . ' min';
    if ($diff < 86400)    return 'há ' . (int)($diff / 3600) . 'h';
    if ($diff < 86400*30) return 'há ' . (int)($diff / 86400) . 'd';
    return date('d/m/Y', $ts);
}
```

- [ ] **Step 3: Add CSS for the section/card layout**

Find (added by Task 2, at the end of the accounts-panel CSS block):
```css
.gh-conta-add:hover { border-color:#1a237e; color:#1a237e; }
```
Insert immediately after it:
```css

.gh-erro-conta { background:#fff3e0; color:#854d0e; border:1px solid #fde68a;
                 border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:.75rem; }

.gh-grupo-section { margin-bottom:1.5rem; }
.gh-grupo-header { border-radius:12px 12px 0 0; padding:.65rem 1.1rem;
                   display:flex; align-items:center; justify-content:space-between;
                   color:#fff; font-weight:700; font-size:.85rem; }
.gh-grupo-count { font-size:.72rem; opacity:.85; font-weight:400; }
.gh-grupo-body { background:#fff; border:1px solid #e5e7eb; border-top:none;
                 border-radius:0 0 12px 12px; padding:1rem; }
.gh-proj-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:.85rem; }
.gh-proj-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem;
                transition:all .15s; background:#fff; }
.gh-proj-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); border-color:#1a237e; }
.gh-proj-topo { display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; }
.gh-proj-nome { font-weight:700; font-size:.9rem; color:#1a237e; text-decoration:none; }
.gh-proj-nome:hover { text-decoration:underline; }
.gh-proj-menu { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0 .25rem; }
.gh-proj-menu:hover { color:#374151; }
.gh-proj-desc { font-size:.78rem; color:#6b7280; margin:.4rem 0; }
.gh-proj-meta { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.6rem;
                padding-top:.6rem; border-top:1px solid #f3f4f6; }
```

- [ ] **Step 4: Replace the Task 2 placeholder with the real listing**

Find (added by Task 2):
```php
  <!-- ═══════════════ PROJETOS (próxima etapa) ═══════════════ -->
  <div class="text-muted small mt-4" id="gh-projetos-placeholder">
    Cadastre uma conta GitHub acima para ver seus projetos aqui.
  </div>
```

Replace it with:
```php
  <!-- ═══════════════ PROJETOS (GitHub, 3 seções) ═══════════════ -->
  <?php
  $reposPorSecao = ['futuro' => [], 'em_execucao' => [], 'concluido' => []];
  $errosContas   = [];

  if ($minhasContas) {
      $contaIds  = array_column($minhasContas, 'id');
      $statusMap = [];
      $ph = implode(',', array_fill(0, count($contaIds), '?'));
      $st = $pdo->prepare("SELECT conta_id, repo_nome, status FROM portal_projetos_status WHERE conta_id IN ($ph)");
      $st->execute($contaIds);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $statusMap[$row['conta_id'] . ':' . $row['repo_nome']] = $row['status'];
      }

      foreach ($minhasContas as $conta) {
          if (!$conta['ativo']) continue;
          $token      = vault_decrypt($conta['token_enc']);
          $resultado  = github_listar_repos($token);
          if (isset($resultado['erro'])) {
              $errosContas[] = ['apelido' => $conta['apelido'], 'msg' => $resultado['erro']];
              continue;
          }
          foreach ($resultado as $repo) {
              $chave  = $conta['id'] . ':' . $repo['nome'];
              $status = $statusMap[$chave] ?? 'em_execucao';
              $repo['conta_id']      = $conta['id'];
              $repo['conta_apelido'] = $conta['apelido'];
              $reposPorSecao[$status][] = $repo;
          }
      }
  }

  $secoesInfo = [
      'futuro'      => ['label' => 'Futuros',     'icon' => 'bi-lightbulb-fill',    'cor' => '#7c3aed'],
      'em_execucao' => ['label' => 'Em Execução', 'icon' => 'bi-hourglass-split',   'cor' => '#1a237e'],
      'concluido'   => ['label' => 'Concluídos',  'icon' => 'bi-check-circle-fill', 'cor' => '#1e8e3e'],
  ];
  ?>

  <?php foreach ($errosContas as $err): ?>
    <div class="gh-erro-conta">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong><?= esc($err['apelido']) ?>:</strong> não foi possível carregar — <?= esc($err['msg']) ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$minhasContas): ?>
    <div class="text-muted small mt-4">Cadastre uma conta GitHub acima para ver seus projetos aqui.</div>
  <?php else: ?>
    <?php foreach ($secoesInfo as $chaveSecao => $info): ?>
      <div class="gh-grupo-section">
        <div class="gh-grupo-header" style="background:<?= $info['cor'] ?>">
          <span><i class="bi <?= $info['icon'] ?> me-2"></i><?= $info['label'] ?></span>
          <span class="gh-grupo-count"><?= count($reposPorSecao[$chaveSecao]) ?> projeto(s)</span>
        </div>
        <div class="gh-grupo-body">
          <?php if ($reposPorSecao[$chaveSecao]): ?>
            <div class="gh-proj-grid">
              <?php foreach ($reposPorSecao[$chaveSecao] as $repo): ?>
                <div class="gh-proj-card">
                  <div class="gh-proj-topo">
                    <a href="<?= esc($repo['url']) ?>" target="_blank" rel="noopener" class="gh-proj-nome">
                      <i class="bi bi-github me-1"></i><?= esc($repo['nome']) ?>
                    </a>
                    <div class="dropdown">
                      <button type="button" class="gh-proj-menu" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($secoesInfo as $optKey => $optInfo): ?>
                          <li><a class="dropdown-item" href="#" onclick="mudarStatus(event,<?= (int)$repo['conta_id'] ?>,'<?= esc($repo['nome']) ?>','<?= $optKey ?>')"><i class="bi <?= $optInfo['icon'] ?> me-2"></i><?= $optInfo['label'] ?></a></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                  <?php if ($repo['descricao']): ?>
                    <div class="gh-proj-desc"><?= esc($repo['descricao']) ?></div>
                  <?php endif; ?>
                  <div class="gh-proj-meta">
                    <?php if ($repo['linguagem']): ?><span class="meta-pill"><i class="bi bi-circle-fill" style="font-size:.5rem"></i><?= esc($repo['linguagem']) ?></span><?php endif; ?>
                    <span class="meta-pill"><i class="bi bi-exclamation-circle"></i><?= (int)$repo['issues_abertas'] ?> issues</span>
                    <span class="meta-pill"><i class="bi bi-clock-history"></i><?= esc(dataRelativa($repo['ultimo_push'])) ?></span>
                    <span class="meta-pill"><i class="bi bi-person"></i><?= esc($repo['conta_apelido']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted small mb-0">Nenhum projeto aqui ainda.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
```

Note: `esc($repo['nome'])` is used unescaped-for-JS directly inside a single-quoted JS string literal within the `onclick` attribute. This is safe because GitHub repository names are constrained by GitHub itself to `[A-Za-z0-9._-]` — they cannot contain a `'` or `"` — so there is no JS-string-breakout risk here (unlike the URL case fixed in `ferramentas_gmais.php`, which was free-form admin input). `esc()`'s `ENT_QUOTES` still covers the surrounding HTML-attribute context.

- [ ] **Step 5: Add the `mudarStatus()` JS function**

Find (in the account modal `<script>` block added by Task 2):
```js
async function excluirConta() {
```
Insert immediately before it:
```js
async function mudarStatus(ev, contaId, repoNome, status) {
  ev.preventDefault();
  const r = await fetch('projetos.php?gh_action=status_set', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({conta_id: contaId, repo_nome: repoNome, status}),
  });
  const d = await r.json();
  if (d.ok) location.reload();
  else alert(d.msg || 'Erro ao mudar status');
}

```

- [ ] **Step 6: Retarget the search filter to the new card class**

Find (existing, at the bottom of the file, `projetos.php:1155-1168` in the original file):
```js
document.getElementById('searchProj')?.addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.proj-card').forEach(card => {
    const cardWrap = card.closest('.col-md-6');
    if (!cardWrap) return;
    const txt = card.textContent.toLowerCase();
    cardWrap.style.display = (!q || txt.includes(q)) ? '' : 'none';
  });
});
```
Replace with:
```js
document.getElementById('searchProj')?.addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.gh-proj-card').forEach(card => {
    const txt = card.textContent.toLowerCase();
    card.style.display = (!q || txt.includes(q)) ? '' : 'none';
  });
});
```

- [ ] **Step 7: Deploy, lint, smoke-check**

```bash
scp "C:/claude code/portal-glpi/projetos.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/projetos.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/projetos.php"
ssh glpi-server "curl -4 -s -o NUL -w \"projetos: %{http_code}\n\" http://192.168.1.198:7412/glpi2/portal-glpi/projetos.php"
```
Expected: `No syntax errors detected ...` and `projetos: 302`.

If either fails, fix, redeploy, and re-check before continuing.

- [ ] **Step 8: Commit**

```bash
git add projetos.php
git commit -m "$(cat <<'EOF'
feat: lista projetos do GitHub em 3 secoes com status manual

Busca os repositorios (nao-fork) de cada conta GitHub cadastrada,
cruza com portal_projetos_status (default em_execucao) e renderiza
em Futuros/Em Execucao/Concluidos. Cada card tem um menu pra trocar
o status (endpoint gh_action=status_set, POST, escopado ao dono da
conta). Erro de uma conta (token revogado etc.) nao derruba a pagina.
EOF
)"
```

- [ ] **Step 9: Manual QA (requires GitHub token — hand off to user)**

This needs a real GitHub personal access token, which the controller/implementer should not generate or ask for directly. Ask the user (or do it yourself if you're given credentials) to walk through the checklist already written in `docs/superpowers/specs/2026-07-27-projetos-github-design.md` under "Teste manual":

1. Cadastrar uma conta GitHub com token válido → aparece no painel, indicador ok.
2. Cadastrar uma conta com token inválido → erro exibido no modal, não salva.
3. Repos da conta aparecem nas 3 seções (todos em "Em Execução" na primeira vez).
4. Trocar o status de um card → ele se move pra seção correta.
5. Revogar um token no GitHub e recarregar a página → conta aparece com aviso de erro, resto da página funciona normal.
6. Confirmar que os 3 projetos antigos (pasta de rede) não aparecem mais na lista principal, mas o código deles continua no arquivo (abrir `?proj=` de um deles manualmente, se ainda existir localmente, para confirmar a view de detalhe ainda funciona).

Report back any issue found so it can be fixed before considering this done.

---

## Self-Review Notes

- **Spec coverage:** `github_client.php` with the 2 functions ✅ (Task 1); `portal_github_contas` table + CRUD + token test ✅ (Task 2); `portal_projetos_status` table + 3-section rendering + status change ✅ (Task 3); tolerant per-account error handling (spec section 5) ✅ (Task 3 Step 4, `$errosContas`); legacy code untouched ✅ (no task touches `parseProjeto`/`carregarProjetosDaPasta`/the detail-view branch/the Gantt block/the download action); security requirements from the spec (encrypted tokens, per-user scoping, Content-Type CSRF check, output escaping) ✅ (Task 2 Step 1, Task 3 Step 1). Manual test checklist from the spec ✅ (Task 3 Step 9).
- **Placeholder scan:** no TBD/TODO. The one literal placeholder-like element (`#gh-projetos-placeholder` div added in Task 2) is intentional and explicitly replaced in Task 3 Step 4 — not left in place.
- **Type/naming consistency:** `gh_action` query parameter values (`conta_add`, `conta_save`, `conta_testar`, `conta_delete`, `status_set`) are spelled identically between the PHP dispatch (Tasks 2–3) and the JS `fetch()` calls (Tasks 2–3). `portal_github_contas`/`portal_projetos_status` column names match between `CREATE TABLE` (Task 2) and every `SELECT`/`INSERT`/`UPDATE` referencing them (Tasks 2–3). `github_listar_repos()`'s return shape (flat list vs. `['erro'=>...]`) from Task 1 matches exactly how Task 3 Step 4 consumes it (`isset($resultado['erro'])`). CSS classes introduced in Task 2 (`.gh-conta-*`) and Task 3 (`.gh-grupo-*`, `.gh-proj-*`) are used consistently between their `<style>` block definitions and the HTML that references them, including the Task 3 Step 6 retarget of the pre-existing search filter.
