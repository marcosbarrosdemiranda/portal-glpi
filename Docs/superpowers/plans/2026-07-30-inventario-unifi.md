# Inventário UniFi Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Activate the disabled "Redes" card in the Inventário hub with a page that monitors access points across the team's 4 UniFi controllers (Cloud Key / classic software controller, port 8443) — online/offline status, connected clients, model, uptime.

**Architecture:** A dependency-free API client (`unifi_client.php`) handles cookie-session login and device listing against the classic UniFi controller REST API. A new page `inventario_redes.php` provides an admin-gated CRUD panel for registering controllers (credentials encrypted with the existing `vault_encrypt`/`vault_decrypt` scheme) plus a live, no-cache accordion view of each controller's access points, open to any authenticated non-self-service user. `inventario.php` gets its one-line edit to turn the existing "Redes" card from disabled to a real link.

**Tech Stack:** PHP 8.2, PDO/MySQL (MariaDB 10.4), cURL with cookie-jar session handling (same technique already in `pfsense_proxy.php`), Bootstrap 5.3 + Bootstrap Icons (CDN), vanilla JS (fetch). Deployed via `scp` to the Docker container `glpi-web` on `192.168.1.198:7412`, same workflow as every other page in this repo.

## Global Constraints

- No automated test framework exists in this codebase. Verification = `php -l` syntax check inside the `glpi-web` container + an HTTP smoke check (`curl` expecting `302`, since every page requires auth).
- `unifi_client.php` cannot be verified against a real controller during implementation (no controller IP/credentials available yet) — verification for that file is lint + a graceful-failure check against a deliberately unreachable address (confirms the error-handling path works, mirrors how `github_client.php` was verified with a deliberately invalid token in an earlier plan).
- Every new/changed file must be synced to the production server via `scp` to `glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/<relative path>"` immediately after it's written.
- Comments in code: Portuguese. Commit messages: English subject, Portuguese body — matches the actual convention already used on this branch.
- Controller passwords are secrets: never logged, never sent to the browser/JS, encrypted at rest with the existing `vault_encrypt()`/`vault_decrypt()` from `vault_crypto.php` (same scheme Cofre TI, Ferramentas Gmais, and the GitHub accounts feature already use), decrypted only server-side, just-in-time for the API call.
- All state-changing AJAX endpoints (`action=controladora_add|controladora_save|controladora_delete`) are POST-only, gated by a `Content-Type: application/json` check before any DB write — the CSRF fix pattern already applied in `ferramentas_gmais.php` (commit `858505c`) and `projetos.php`. Do not reintroduce a GET-based mutation.
- Writing to `portal_unifi_controladoras` (add/save/delete) requires `$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico'])` (same check already used in `acessos.php`/`pfsense_proxy.php`). Reading the controller list and AP data is open to any authenticated non-self-service user — this is shared company infrastructure, not per-user data (unlike the GitHub accounts feature).
- `CURLOPT_SSL_VERIFYPEER`/`CURLOPT_SSL_VERIFYHOST => false` is required and acceptable here (self-signed certs on internal-network controllers) — same justification and precedent as `pfsense_proxy.php`.
- All output derived from external input (UniFi API responses, admin-entered apelido/url) goes through the existing `esc()`-equivalent — this codebase's convention is `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`; define a local `esc()` helper in `inventario_redes.php` if one isn't already in scope (it is a new file, so it needs its own, matching the exact signature used in `projetos.php:139`).

---

## File Structure

- **Create `unifi_client.php`** (repo root, alongside `github_client.php`) — three pure functions, no HTML, no session/DB access: `unifi_login`, `unifi_testar_login`, `unifi_listar_aps`.
- **Create `inventario_redes.php`** (repo root, alongside `inventario_pcs.php`/`inventario_balancas.php`) — one self-contained page: table bootstrap, AJAX CRUD endpoints for controllers, and the HTML/CSS/JS accordion UI.
- **Modify `inventario.php`** — one card block, turning the disabled "Redes" placeholder into a real link (same edit shape as how "PCs"/"Balanças" are already real links in the same file).

---

### Task 1: `unifi_client.php` — UniFi controller API client

**Files:**
- Create: `unifi_client.php`

**Interfaces:**
- Consumes: nothing (pure HTTP client, no dependency on the rest of the app).
- Produces: `unifi_login(string $url, string $usuario, string $senha): array` returning `['ok'=>bool, 'cookieFile'=>?string, 'msg'=>string]` (caller must `unlink()` the cookie file when done, unless `ok` is false, in which case it's already cleaned up); `unifi_testar_login(string $url, string $usuario, string $senha): array` returning `['ok'=>bool, 'msg'=>string]`; `unifi_listar_aps(string $url, string $usuario, string $senha, string $site = 'default'): array` returning either a **flat list** of AP arrays (each with keys `nome, modelo, mac, ip, status, clientes, uptime_seg`) on success, or `['erro'=>string]` on failure. Consumed by Task 2 (`unifi_testar_login`) and Task 3 (`unifi_listar_aps`).

- [ ] **Step 1: Write `unifi_client.php`**

```php
<?php
/**
 * unifi_client.php — cliente da API da controladora UniFi
 * (Cloud Key / software controller clássico, porta 8443).
 * Sem HTML/sessão — só HTTP + parsing. Usado por inventario_redes.php.
 */

function unifi_login(string $url, string $usuario, string $senha): array {
    $url        = rtrim($url, '/');
    $cookieFile = tempnam(sys_get_temp_dir(), 'unifi_');

    $ch = curl_init($url . '/api/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['username' => $usuario, 'password' => $senha]),
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        @unlink($cookieFile);
        return ['ok' => false, 'cookieFile' => null, 'msg' => 'Falha de conexão com a controladora'];
    }

    $data = json_decode($body, true);
    $rc   = $data['meta']['rc'] ?? '';
    if ($code !== 200 || $rc !== 'ok') {
        @unlink($cookieFile);
        return ['ok' => false, 'cookieFile' => null, 'msg' => 'Login falhou (usuário/senha inválidos ou HTTP ' . $code . ')'];
    }

    return ['ok' => true, 'cookieFile' => $cookieFile, 'msg' => 'OK'];
}

function unifi_testar_login(string $url, string $usuario, string $senha): array {
    $login = unifi_login($url, $usuario, $senha);
    if ($login['cookieFile']) @unlink($login['cookieFile']);
    return ['ok' => $login['ok'], 'msg' => $login['msg']];
}

function unifi_listar_aps(string $url, string $usuario, string $senha, string $site = 'default'): array {
    $login = unifi_login($url, $usuario, $senha);
    if (!$login['ok']) return ['erro' => $login['msg']];

    $urlBase = rtrim($url, '/');
    $ch = curl_init($urlBase . '/api/s/' . rawurlencode($site) . '/stat/device');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE     => $login['cookieFile'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($login['cookieFile']);

    if ($errno) return ['erro' => 'Falha de conexão com a controladora'];
    if ($code !== 200) return ['erro' => 'Controladora retornou HTTP ' . $code];

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return ['erro' => 'Resposta inesperada da controladora'];
    }

    $aps = [];
    foreach ($data['data'] as $dev) {
        if (($dev['type'] ?? '') !== 'uap') continue;
        $aps[] = [
            'nome'       => $dev['name'] ?? ($dev['model'] ?? 'AP sem nome'),
            'modelo'     => $dev['model'] ?? '',
            'mac'        => $dev['mac'] ?? '',
            'ip'         => $dev['ip'] ?? '',
            'status'     => ((int)($dev['state'] ?? 0) === 1) ? 'online' : 'offline',
            'clientes'   => (int)($dev['num_sta'] ?? 0),
            'uptime_seg' => (int)($dev['uptime'] ?? 0),
        ];
    }

    return $aps;
}
```

- [ ] **Step 2: Deploy to the server**

```bash
scp "C:/claude code/portal-glpi/unifi_client.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/unifi_client.php"
```

- [ ] **Step 3: Lint-check on the server**

```bash
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/unifi_client.php"
```
Expected: `No syntax errors detected in /var/www/html/glpi2/portal-glpi/unifi_client.php`

- [ ] **Step 4: Graceful-failure smoke test (no real controller available)**

Create a local scratch file (do NOT commit — deleted in Step 6) at `C:/claude code/portal-glpi/unifi_client_smoketest.php`:

```php
<?php
require __DIR__ . '/unifi_client.php';
$r = unifi_testar_login('https://192.0.2.1:8443', 'nobody', 'nopass');
echo json_encode($r), "\n";
```

(`192.0.2.1` is a TEST-NET-1 address per RFC 5737 — guaranteed non-routable/unreachable, so this exercises the connection-failure path deterministically without needing a real controller.)

Deploy and run it inside the container:

```bash
scp "C:/claude code/portal-glpi/unifi_client_smoketest.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/unifi_client_smoketest.php"
ssh glpi-server "docker exec glpi-web php /var/www/html/glpi2/portal-glpi/unifi_client_smoketest.php"
```

Expected output: a JSON line with `"ok":false` and a `"msg"` mentioning connection failure, e.g. `{"ok":false,"msg":"Falha de conex\u00e3o com a controladora"}`.

- [ ] **Step 5: Delete the scratch smoke-test file, locally and on the server**

```bash
rm "C:/claude code/portal-glpi/unifi_client_smoketest.php"
ssh glpi-server "docker exec glpi-web rm /var/www/html/glpi2/portal-glpi/unifi_client_smoketest.php"
```

- [ ] **Step 6: Commit**

```bash
git add unifi_client.php
git commit -m "$(cat <<'EOF'
feat: adiciona cliente da API de controladoras UniFi (unifi_client.php)

Login via cookie de sessao (POST /api/login) e listagem de access
points (GET /api/s/{site}/stat/device, filtrado por type=uap) para
controladoras classicas (Cloud Key / software controller, porta 8443).
Usado pelo modulo de Inventario para ativar o card "Redes".
EOF
)"
```

---

### Task 2: `portal_unifi_controladoras` — tabela e painel de administração

**Files:**
- Create: `inventario_redes.php`
- Modify: `inventario.php` (the "Redes" card)

**Interfaces:**
- Consumes: `unifi_testar_login(string $url, string $usuario, string $senha): array` from Task 1 (`unifi_client.php`); the existing `vault_encrypt()`/`vault_decrypt()` from `vault_crypto.php`.
- Produces: the table `portal_unifi_controladoras` (columns: `id, apelido, url, usuario, senha_enc, site, ativo, ultimo_teste_ok, ultima_verificacao, criado_em`), and the PHP variable `$controladoras` (array of active controller rows) — both consumed by Task 3.

- [ ] **Step 1: Write `inventario_redes.php`**

```php
<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/unifi_client.php';

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'super-admin', 'tecnico']);

// ── Tabela de controladoras UniFi ────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_unifi_controladoras (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        apelido            VARCHAR(60)   NOT NULL,
        url                VARCHAR(255)  NOT NULL,
        usuario            VARCHAR(100)  NOT NULL,
        senha_enc          TEXT          NOT NULL,
        site               VARCHAR(60)   NOT NULL DEFAULT 'default',
        ativo              TINYINT(1)    DEFAULT 1,
        ultimo_teste_ok    TINYINT(1)    DEFAULT NULL,
        ultima_verificacao DATETIME      DEFAULT NULL,
        criado_em          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX: CRUD de controladoras (somente admin) ─────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        echo json_encode(['ok' => false, 'msg' => 'Requisição inválida']); exit;
    }
    if (!$is_admin) { echo json_encode(['ok' => false, 'msg' => 'Sem permissão']); exit; }

    if ($action === 'controladora_add' || $action === 'controladora_save') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $apelido = trim($body['apelido'] ?? '');
        $url     = trim($body['url'] ?? '');
        $usuario = trim($body['usuario'] ?? '');
        $senha   = trim($body['senha'] ?? '');
        $site    = trim($body['site'] ?? '') ?: 'default';
        $id      = (int)($body['id'] ?? 0);

        if (!$apelido || !$url || !$usuario) {
            echo json_encode(['ok' => false, 'msg' => 'Apelido, URL e usuário são obrigatórios']); exit;
        }
        if (!preg_match('~^https?://~i', $url)) {
            echo json_encode(['ok' => false, 'msg' => 'URL deve começar com http:// ou https://']); exit;
        }

        // Edição sem nova senha = mantém a senha atual, não re-testa
        if ($action === 'controladora_save' && $id && $senha === '') {
            $st = $pdo->prepare("UPDATE portal_unifi_controladoras SET apelido=?, url=?, usuario=?, site=? WHERE id=?");
            $st->execute([$apelido, $url, $usuario, $site, $id]);
            echo json_encode(['ok' => true]); exit;
        }

        if (!$senha) { echo json_encode(['ok' => false, 'msg' => 'Senha é obrigatória']); exit; }
        $teste = unifi_testar_login($url, $usuario, $senha);
        if (!$teste['ok']) { echo json_encode(['ok' => false, 'msg' => 'Login falhou: ' . $teste['msg']]); exit; }

        $senhaEnc = vault_encrypt($senha);
        if ($action === 'controladora_add') {
            $st = $pdo->prepare("INSERT INTO portal_unifi_controladoras (apelido,url,usuario,senha_enc,site,ultimo_teste_ok,ultima_verificacao) VALUES (?,?,?,?,?,1,NOW())");
            $st->execute([$apelido, $url, $usuario, $senhaEnc, $site]);
            echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            $st = $pdo->prepare("UPDATE portal_unifi_controladoras SET apelido=?, url=?, usuario=?, senha_enc=?, site=?, ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=?");
            $st->execute([$apelido, $url, $usuario, $senhaEnc, $site, $id]);
            echo json_encode(['ok' => true]);
        }
        exit;
    }

    if ($action === 'controladora_testar') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM portal_unifi_controladoras WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Controladora não encontrada']); exit; }

        $teste = unifi_testar_login($row['url'], $row['usuario'], vault_decrypt($row['senha_enc']));
        $pdo->prepare("UPDATE portal_unifi_controladoras SET ultimo_teste_ok=?, ultima_verificacao=NOW() WHERE id=?")
            ->execute([$teste['ok'] ? 1 : 0, $id]);
        echo json_encode($teste);
        exit;
    }

    if ($action === 'controladora_delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM portal_unifi_controladoras WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
    exit;
}

// ── Controladoras ativas (painel + próxima etapa: listagem de APs) ──
$controladoras = $pdo->query("SELECT * FROM portal_unifi_controladoras WHERE ativo=1 ORDER BY apelido")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Redes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    * { box-sizing:border-box; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:2rem auto 3rem; padding:0 1rem; }

    /* ── Controladoras ─────────────────────────────────────────── */
    .ctrl-section { margin-bottom:1.5rem; }
    .ctrl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:.75rem; }
    .ctrl-card { background:#fff; border:2px solid #e5e7eb; border-radius:12px;
                 padding:1rem; position:relative; transition:all .15s; }
    .ctrl-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
    .ctrl-topo { display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem; }
    .ctrl-badge.ok   { color:#1e8e3e; }
    .ctrl-badge.erro { color:#d93025; }
    .ctrl-cfg { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; }
    .ctrl-cfg:hover { color:#1a237e; }
    .ctrl-apelido { font-weight:700; font-size:.88rem; }
    .ctrl-url { font-size:.72rem; color:#6b7280; word-break:break-all; }
    .ctrl-add { display:flex; flex-direction:column; align-items:center; justify-content:center;
                gap:.35rem; min-height:64px; border:2px dashed #d1d5db; color:#9ca3af;
                cursor:pointer; border-radius:12px; }
    .ctrl-add:hover { border-color:#1a237e; color:#1a237e; }

    .badge-obsidian { display:none; } /* placeholder de compatibilidade visual, sem uso aqui */
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-wifi me-2"></i>Inventário — Redes</div>
  <a href="inventario.php"><i class="bi bi-grid me-1"></i>Inventário</a>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-wifi me-2"></i>Redes — Access Points UniFi
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Status ao vivo das controladoras UniFi do grupo</p>
</div>

<div class="wrap">

<div class="ctrl-section">
  <h6 class="fw-bold mb-2" style="color:#374151">
    <i class="bi bi-hdd-network me-2"></i>Controladoras UniFi
  </h6>
  <div class="ctrl-grid">
    <?php foreach ($controladoras as $c): ?>
      <div class="ctrl-card">
        <div class="ctrl-topo">
          <span class="ctrl-badge <?= $c['ultimo_teste_ok'] ? 'ok' : 'erro' ?>">
            <i class="bi <?= $c['ultimo_teste_ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
          </span>
          <?php if ($is_admin): ?>
          <button type="button" class="ctrl-cfg" onclick='editarControladora(<?= json_encode(['id'=>$c['id'],'apelido'=>$c['apelido'],'url'=>$c['url'],'usuario'=>$c['usuario'],'site'=>$c['site']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)' title="Editar">
            <i class="bi bi-gear-fill"></i>
          </button>
          <?php endif; ?>
        </div>
        <div class="ctrl-apelido"><?= esc($c['apelido']) ?></div>
        <div class="ctrl-url"><?= esc($c['url']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($is_admin): ?>
      <div class="ctrl-card ctrl-add" onclick="abrirModalControladora()">
        <i class="bi bi-plus-circle" style="font-size:1.5rem"></i>
        <div class="ctrl-apelido">Adicionar</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════ ACCESS POINTS (próxima etapa) ═══════════════ -->
<div class="text-muted small mt-4" id="unifi-aps-placeholder">
  <?= $controladoras ? 'Carregando access points...' : 'Cadastre uma controladora acima para ver os access points aqui.' ?>
</div>

</div><!-- /wrap -->

<!-- Modal: adicionar/editar controladora UniFi -->
<div class="modal fade" id="modalControladora" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalControladoraTitulo"><i class="bi bi-wifi me-2"></i>Nova Controladora</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ctrl-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Apelido</label>
          <input type="text" class="form-control" id="ctrl-apelido" placeholder="Ex: Loja 101"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">URL <span class="text-muted small">(ex: https://192.168.1.10:8443)</span></label>
          <input type="text" class="form-control font-monospace" id="ctrl-url" placeholder="https://192.168.x.x:8443"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário</label>
          <input type="text" class="form-control" id="ctrl-usuario" placeholder="admin"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha</label>
          <input type="password" class="form-control font-monospace" id="ctrl-senha" placeholder="••••••••" autocomplete="new-password"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Site <span class="text-muted small">(padrão: default)</span></label>
          <input type="text" class="form-control" id="ctrl-site" placeholder="default"/>
        </div>
        <div id="ctrl-erro" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="btn-excluir-ctrl" style="display:none" onclick="excluirControladora()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarControladora()" style="background:#1a237e;border-color:#1a237e"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalControladora;
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('modalControladora');
  if (el) modalControladora = new bootstrap.Modal(el);
});

function abrirModalControladora() {
  document.getElementById('ctrl-id').value = '';
  document.getElementById('ctrl-apelido').value = '';
  document.getElementById('ctrl-url').value = '';
  document.getElementById('ctrl-usuario').value = '';
  document.getElementById('ctrl-senha').value = '';
  document.getElementById('ctrl-senha').placeholder = '••••••••';
  document.getElementById('ctrl-site').value = '';
  document.getElementById('ctrl-erro').style.display = 'none';
  document.getElementById('modalControladoraTitulo').innerHTML = '<i class="bi bi-wifi me-2"></i>Nova Controladora';
  document.getElementById('btn-excluir-ctrl').style.display = 'none';
  modalControladora.show();
}

function editarControladora(c) {
  document.getElementById('ctrl-id').value = c.id;
  document.getElementById('ctrl-apelido').value = c.apelido;
  document.getElementById('ctrl-url').value = c.url;
  document.getElementById('ctrl-usuario').value = c.usuario;
  document.getElementById('ctrl-senha').value = '';
  document.getElementById('ctrl-senha').placeholder = 'Deixe em branco para manter a senha atual';
  document.getElementById('ctrl-site').value = c.site;
  document.getElementById('ctrl-erro').style.display = 'none';
  document.getElementById('modalControladoraTitulo').textContent = c.apelido;
  document.getElementById('btn-excluir-ctrl').style.display = 'inline-block';
  modalControladora.show();
}

async function salvarControladora() {
  const id      = document.getElementById('ctrl-id').value;
  const apelido = document.getElementById('ctrl-apelido').value.trim();
  const url     = document.getElementById('ctrl-url').value.trim();
  const usuario = document.getElementById('ctrl-usuario').value.trim();
  const senha   = document.getElementById('ctrl-senha').value.trim();
  const site    = document.getElementById('ctrl-site').value.trim();
  const erroEl  = document.getElementById('ctrl-erro');
  erroEl.style.display = 'none';

  if (!apelido || !url || !usuario) {
    erroEl.textContent = 'Preencha apelido, URL e usuário.';
    erroEl.style.display = '';
    return;
  }

  const action = id ? 'controladora_save' : 'controladora_add';
  const r = await fetch(`inventario_redes.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, apelido, url, usuario, senha, site}),
  });
  const d = await r.json();
  if (d.ok) { modalControladora.hide(); location.reload(); }
  else { erroEl.textContent = d.msg || 'Erro ao salvar'; erroEl.style.display = ''; }
}

async function excluirControladora() {
  const id = document.getElementById('ctrl-id').value;
  if (!id || !confirm('Excluir esta controladora? Os APs dela deixarão de aparecer.')) return;
  const r = await fetch('inventario_redes.php?action=controladora_delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (d.ok) { modalControladora.hide(); location.reload(); }
  else alert(d.msg || 'Erro ao excluir');
}
</script>
</body>
</html>
```

- [ ] **Step 2: Turn the "Redes" card into a real link in `inventario.php`**

Find (exact current content):
```php
  <div class="cat-card disabled">
    <div class="cat-icon net-icon"><i class="bi bi-diagram-3"></i></div>
    <h3>Redes</h3>
    <p>Switches, roteadores, access points</p>
    <span class="badge-embreve">Em breve</span>
  </div>
```
Replace with:
```php
  <a href="inventario_redes.php" class="cat-card" style="border-top-color:#2e7d32">
    <div class="cat-icon net-icon"><i class="bi bi-diagram-3"></i></div>
    <h3>Redes</h3>
    <p>Access points UniFi — status, clientes e uptime</p>
  </a>
```

- [ ] **Step 3: Deploy, lint, smoke-check**

```bash
scp "C:/claude code/portal-glpi/inventario_redes.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/inventario_redes.php"
scp "C:/claude code/portal-glpi/inventario.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/inventario.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/inventario_redes.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/inventario.php"
ssh glpi-server "curl -4 -s -o NUL -w \"inventario_redes: %{http_code}\n\" http://192.168.1.198:7412/glpi2/portal-glpi/inventario_redes.php"
ssh glpi-server "curl -4 -s -o NUL -w \"inventario: %{http_code}\n\" http://192.168.1.198:7412/glpi2/portal-glpi/inventario.php"
```
Expected: `No syntax errors detected ...` (both files) and `302` (both smoke checks).

If any check fails, fix, redeploy, and re-check before continuing.

- [ ] **Step 4: Commit**

```bash
git add inventario_redes.php inventario.php
git commit -m "$(cat <<'EOF'
feat: painel de controladoras UniFi em Inventario > Redes

Adiciona tabela portal_unifi_controladoras e pagina inventario_redes.php
com CRUD de controladoras (admin) + teste de login, ativando o card
"Redes" (antes desativado) no hub de Inventario. Listagem dos access
points fica para a proxima etapa.
EOF
)"
```

---

### Task 3: Listagem de access points por controladora

**Files:**
- Modify: `inventario_redes.php` (replace the `#unifi-aps-placeholder` div with the real accordion; add CSS for it)

**Interfaces:**
- Consumes: `unifi_listar_aps(string $url, string $usuario, string $senha, string $site): array` from Task 1; `$controladoras`, `vault_decrypt()`, `esc()` from Task 2 / existing file.
- Produces: nothing consumed by a later task (this is the last task).

- [ ] **Step 1: Add CSS for the AP accordion/cards**

Find (added by Task 2, end of the controller-panel CSS block):
```css
    .ctrl-add:hover { border-color:#1a237e; color:#1a237e; }
```
Insert immediately after it:
```css

    .badge-obsidian { display:none; }

    .unifi-erro-ctrl { background:#fff3e0; color:#854d0e; border:1px solid #fde68a;
                        border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:.75rem; }

    .unifi-grupo-section { margin-bottom:1.5rem; }
    .unifi-grupo-header { border-radius:12px 12px 0 0; padding:.65rem 1.1rem;
                           display:flex; align-items:center; justify-content:space-between;
                           color:#fff; font-weight:700; font-size:.85rem; background:#2e7d32; }
    .unifi-grupo-count { font-size:.72rem; opacity:.85; font-weight:400; }
    .unifi-grupo-body { background:#fff; border:1px solid #e5e7eb; border-top:none;
                         border-radius:0 0 12px 12px; padding:1rem; }
    .unifi-ap-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.85rem; }
    .unifi-ap-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem;
                      background:#fff; display:flex; flex-direction:column; gap:.4rem; }
    .unifi-ap-topo { display:flex; align-items:center; gap:.5rem; }
    .unifi-ap-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .unifi-ap-dot.online  { background:#1e8e3e; }
    .unifi-ap-dot.offline { background:#d93025; }
    .unifi-ap-nome { font-weight:700; font-size:.88rem; }
    .unifi-ap-modelo { font-size:.75rem; color:#6b7280; }
    .unifi-ap-meta { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.4rem;
                      padding-top:.5rem; border-top:1px solid #f3f4f6; font-size:.72rem; color:#6b7280; }
```
(Note: the duplicate `.badge-obsidian { display:none; }` rule already exists from Task 2's copy-paste — remove the one Task 2 added if this step's diff would otherwise create two identical rules; keep exactly one.)

- [ ] **Step 2: Replace the placeholder with the real accordion**

Find (added by Task 2):
```php
<!-- ═══════════════ ACCESS POINTS (próxima etapa) ═══════════════ -->
<div class="text-muted small mt-4" id="unifi-aps-placeholder">
  <?= $controladoras ? 'Carregando access points...' : 'Cadastre uma controladora acima para ver os access points aqui.' ?>
</div>
```
Replace with:
```php
<!-- ═══════════════ ACCESS POINTS ═══════════════ -->
<?php
$errosControladoras = [];
$apsPorControladora  = [];

foreach ($controladoras as $c) {
    $senha     = vault_decrypt($c['senha_enc']);
    $resultado = unifi_listar_aps($c['url'], $c['usuario'], $senha, $c['site']);
    if (isset($resultado['erro'])) {
        $errosControladoras[] = ['apelido' => $c['apelido'], 'msg' => $resultado['erro']];
        continue;
    }
    $apsPorControladora[$c['id']] = $resultado;
}
?>

<?php foreach ($errosControladoras as $err): ?>
  <div class="unifi-erro-ctrl">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong><?= esc($err['apelido']) ?>:</strong> não foi possível carregar — <?= esc($err['msg']) ?>
  </div>
<?php endforeach; ?>

<?php if (!$controladoras): ?>
  <div class="text-muted small mt-4">Cadastre uma controladora acima para ver os access points aqui.</div>
<?php else: ?>
  <?php foreach ($controladoras as $c): if (!isset($apsPorControladora[$c['id']])) continue; ?>
    <?php $aps = $apsPorControladora[$c['id']]; ?>
    <div class="unifi-grupo-section">
      <div class="unifi-grupo-header">
        <span><i class="bi bi-wifi me-2"></i><?= esc($c['apelido']) ?></span>
        <span class="unifi-grupo-count"><?= count($aps) ?> access point(s)</span>
      </div>
      <div class="unifi-grupo-body">
        <?php if ($aps): ?>
          <div class="unifi-ap-grid">
            <?php foreach ($aps as $ap): ?>
              <div class="unifi-ap-card">
                <div class="unifi-ap-topo">
                  <span class="unifi-ap-dot <?= $ap['status'] ?>"></span>
                  <span class="unifi-ap-nome"><?= esc($ap['nome']) ?></span>
                </div>
                <div class="unifi-ap-modelo"><?= esc($ap['modelo']) ?></div>
                <div class="unifi-ap-meta">
                  <span><i class="bi bi-people-fill me-1"></i><?= (int)$ap['clientes'] ?> clientes</span>
                  <span><i class="bi bi-clock-history me-1"></i><?= esc(formatarUptime($ap['uptime_seg'])) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted small mb-0">Nenhum access point encontrado nessa controladora.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
```

- [ ] **Step 3: Add the `formatarUptime()` helper**

Find (in `inventario_redes.php`, the `esc()` function added by Task 2):
```php
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```
Insert immediately after it:
```php

function formatarUptime(int $segundos): string {
    if ($segundos <= 0) return '—';
    $dias    = intdiv($segundos, 86400);
    $horas   = intdiv($segundos % 86400, 3600);
    if ($dias > 0)  return "{$dias}d {$horas}h";
    $minutos = intdiv($segundos % 3600, 60);
    if ($horas > 0) return "{$horas}h {$minutos}min";
    return "{$minutos}min";
}
```

- [ ] **Step 4: Deploy, lint, smoke-check**

```bash
scp "C:/claude code/portal-glpi/inventario_redes.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/inventario_redes.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/inventario_redes.php"
ssh glpi-server "curl -4 -s -o NUL -w \"inventario_redes: %{http_code}\n\" http://192.168.1.198:7412/glpi2/portal-glpi/inventario_redes.php"
```
Expected: `No syntax errors detected ...` and `302`.

If either fails, fix, redeploy, and re-check before continuing.

- [ ] **Step 5: Commit**

```bash
git add inventario_redes.php
git commit -m "$(cat <<'EOF'
feat: lista access points UniFi por controladora em Inventario > Redes

Busca os APs (via unifi_listar_aps) de cada controladora cadastrada
e renderiza em acordeao: status online/offline, modelo, clientes
conectados e uptime formatado. Controladora com erro (login invalido,
fora do ar) mostra aviso inline sem derrubar as demais.
EOF
)"
```

- [ ] **Step 6: Manual QA (requires real UniFi controller credentials — hand off to user)**

This needs real controller IPs/credentials, which the controller/implementer should not fabricate. Ask the user (or do it yourself if you're given credentials) to walk through the checklist from `docs/superpowers/specs/2026-07-30-inventario-unifi-design.md` under "Teste manual":

1. Como admin: cadastrar uma controladora com credenciais válidas → aparece na lista, indicador ok.
2. Cadastrar com credenciais inválidas → erro exibido, não salva.
3. APs da controladora aparecem no acordeão com status, modelo, clientes e uptime corretos (conferir contra a interface web da própria controladora).
4. Derrubar/desligar uma controladora (ou usar URL errada) → aviso inline nessa seção, as outras continuam normais.
5. Usuário não-admin: vê a lista de APs normalmente, mas não vê botão de adicionar/editar/excluir controladora.
6. Card "Redes" no Inventário não aparece mais como "Em breve" e linka corretamente.

Report back any issue found so it can be fixed before considering this done.

---

## Self-Review Notes

- **Spec coverage:** `unifi_client.php` with login/testar/listar ✅ (Task 1); `portal_unifi_controladoras` table + admin CRUD panel ✅ (Task 2); "Redes" card activated ✅ (Task 2 Step 2); AP listing with status/model/clients/uptime, per-controller fault isolation ✅ (Task 3); manual test checklist from spec ✅ (Task 3 Step 6). "Fora de escopo" items (UniFi OS, cloud SSO, switch/gateway monitoring, remote management actions) are intentionally not implemented.
- **Placeholder scan:** no TBD/TODO. The `#unifi-aps-placeholder` div added in Task 2 is intentional and explicitly replaced in Task 3 Step 2, not left in place. The duplicate `.badge-obsidian` CSS rule is explicitly flagged in Task 3 Step 1 for de-duplication, not silently left as dead weight.
- **Type/naming consistency:** `action` query parameter values (`controladora_add`, `controladora_save`, `controladora_testar`, `controladora_delete`) are spelled identically between the PHP dispatch (Task 2) and the JS `fetch()` calls (Task 2). `portal_unifi_controladoras` column names match between `CREATE TABLE` (Task 2) and every `SELECT`/`INSERT`/`UPDATE` referencing them (Tasks 2–3). `unifi_listar_aps()`'s return shape (flat list vs. `['erro'=>...]`) from Task 1 matches exactly how Task 3 Step 2 consumes it (`isset($resultado['erro'])`) — same discriminator pattern already proven in `github_client.php`/`projetos.php`. CSS classes introduced in Task 2 (`.ctrl-*`) and Task 3 (`.unifi-grupo-*`, `.unifi-ap-*`) are used consistently between their `<style>` definitions and the HTML that references them.
