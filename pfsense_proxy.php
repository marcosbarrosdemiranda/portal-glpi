<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

// ── Chave de criptografia (mesma do Cofre TI) ─────────────────
if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}
function vault_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function vault_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

$loja_id = (int)($_GET['loja'] ?? 0);
$path    = $_GET['path'] ?? '/';

// ── Busca dados da loja ─────────────────────────────────────────
$loja = null;
if ($loja_id) {
    $st = $pdo->prepare("SELECT * FROM portal_pfsense_lojas WHERE id=? AND ativo=1");
    $st->execute([$loja_id]);
    $loja = $st->fetch(PDO::FETCH_ASSOC);
    if (!$loja) { http_response_code(404); echo 'Loja não encontrada'; exit; }
}

// ── Login via cURL (primeira vez da sessão) ─────────────────────
if ($loja && !isset($_SESSION['pfsense_logged_' . $loja_id])) {
    $ip    = $loja['ip'];
    $user  = $loja['usuario'];
    $pass  = vault_decrypt($loja['senha_enc']);
    $ckfile = sys_get_temp_dir() . '/pfsense_' . session_id() . '_' . $loja_id . '.ck.txt';

    // ── PASSO 1: GET da página de login pra extrair CSRF ──
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$ip/",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR => $ckfile,
        CURLOPT_COOKIEFILE => $ckfile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $loginPage = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode >= 400) {
        http_response_code(502);
        echo "Erro ao conectar no pfSense ($ip) — código $httpCode";
        exit;
    }

    // Extrai o token CSRF do HTML (formatos: value="xxx" ou value='xxx')
    $csrf = '';
    if (preg_match('/__csrf_magic["\']?\s*(?:value\s*=\s*["\']([^"\']+)["\']|>\s*([^<]+)<)/is', $loginPage, $m)) {
        $csrf = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? trim($m[2]) : '');
    }
    $csrf = html_entity_decode($csrf, ENT_QUOTES | ENT_HTML5);

    // ── PASSO 2: POST com credentials + CSRF ───────────────
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$ip/index.php",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '__csrf_magic' => $csrf,
            'usernamefld'  => $user,
            'passwordfld'  => $pass,
            'login'        => 'Login',
        ]),
        CURLOPT_COOKIEFILE => $ckfile,
        CURLOPT_COOKIEJAR  => $ckfile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $loginResult = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loginErr    = curl_error($ch);
    $redirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    // Verifica se o login redirecionou pro dashboard (sucesso)
    $loginOk = ($httpCode === 200 || $httpCode === 302);
    if ($loginOk && $loginResult) {
        // Se ainda estiver na página de login, falhou
        if (stripos($loginResult, 'usernamefld') !== false || stripos($loginResult, 'Login') !== false) {
            $loginOk = false;
        }
    }

    if (!$loginOk) {
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        http_response_code(502);
        echo "Falha no login do pfSense ($ip). Verifique usuário e senha.";
        if ($loginErr) echo " cURL: $loginErr";
        exit;
    }

    $_SESSION['pfsense_logged_' . $loja_id] = true;
    $_SESSION['pfsense_ck_' . $loja_id] = $ckfile;
    $_SESSION['pfsense_ip_' . $loja_id] = $ip;
}

// ── Proxy de página ──────────────────────────────────────────────
if ($loja && isset($_GET['path'])) {
    $ip    = $loja['ip'];
    $ckfile = $_SESSION['pfsense_ck_' . $loja_id] ?? null;

    if (!$ckfile || !file_exists($ckfile)) {
        // Sessão expirou — limpa pra tentar de novo
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        header('Location: pfsense_proxy.php?loja=' . $loja_id . '&path=' . urlencode($path));
        exit;
    }

    // Se for POST, repassa os dados
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $postData = $isPost ? $_POST : null;

    // Monta URL completa do pfSense
    $url = "https://$ip$path";

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE => $ckfile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ];

    if ($isPost) {
        $curlOpts[CURLOPT_POST] = true;
        // Rebuild POST data with __csrf_magic from our session if present
        if (isset($_POST['__csrf_magic'])) {
            $postData = $_POST;
        }
        $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($postData);
    }

    curl_setopt_array($ch, $curlOpts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 && $httpCode !== 302 && $httpCode !== 200) {
        // Sessão expirou no pfSense — limpa e tenta novamente
        unset($_SESSION['pfsense_logged_' . $loja_id]);
        @unlink($ckfile);
        header('Location: pfsense_proxy.php?loja=' . $loja_id . '&path=' . urlencode($path));
        exit;
    }

    // Extrai headers e body
    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Remove headers problemáticos e repassa
    $blockedHeaders = ['x-frame-options', 'content-security-policy', 'transfer-encoding'];
    foreach (explode("\r\n", $rawHeaders) as $h) {
        $hLower = strtolower(explode(':', $h)[0]);
        if (in_array($hLower, $blockedHeaders)) continue;
        if (empty(trim($h))) continue;
        if (stripos($h, 'HTTP/') === 0) continue; // deixa PHP setar
        header($h, false);
    }

    // Rewrite URLs no HTML para passar pelo proxy
    $qs = 'loja=' . $loja_id . '&path=';
    $body = preg_replace(
        '/(<(?:a|link|script|img|form|iframe|source)\s[^>]*?(?:href|src|action)\s*=\s*["\'])\/([^"\']*)(["\'])/i',
        '$1pfsense_proxy.php?' . $qs . '/$2$3',
        $body
    );

    // Rewrite URLs sem leading slash (relativas)
    $body = preg_replace(
        '/(<(?:a|link|script|img|form|iframe|source)\s[^>]*?(?:href|src|action)\s*=\s*["\'])(?!http|https|\/|#|[a-z]+:)([^"\']*)(["\'])/i',
        '$1pfsense_proxy.php?' . $qs . '/$2$3',
        $body
    );

    // Rewrite URLs em meta refresh
    $body = preg_replace(
        '/(<meta[^>]*url\s*=\s*["\'])\/([^"\']*)(["\'])/i',
        '$1pfsense_proxy.php?' . $qs . '/$2$3',
        $body
    );

    echo $body;
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central pfSense</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#b91c1c; }
    * { box-sizing:border-box; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; margin:0; }

    /* ── Topbar ── */
    .topbar {
      background:linear-gradient(135deg,#7f1d1d,var(--primary));
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    /* ── Hero ── */
    .hero {
      background:linear-gradient(135deg,#7f1d1d,var(--primary));
      color:white; padding:2rem 1rem 4rem; text-align:center;
    }
    .wrap { max-width:800px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    /* ── Card loja ── */
    .loja-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      padding:1.25rem 1.5rem; margin-bottom:.75rem;
      display:flex; align-items:center; justify-content:space-between; gap:1rem;
      transition:box-shadow .18s;
    }
    .loja-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
    .loja-info { display:flex; align-items:center; gap:1rem; flex:1; min-width:0; }
    .loja-icon {
      width:48px; height:48px; border-radius:12px;
      background:#fee2e2; color:#b91c1c;
      display:flex; align-items:center; justify-content:center;
      font-size:1.4rem; flex-shrink:0;
    }
    .loja-nome { font-weight:700; font-size:1rem; color:#111; }
    .loja-ip   { font-size:.8rem; color:#6b7280; font-family:'Consolas','Courier New',monospace; }
    .loja-user { font-size:.75rem; color:#9ca3af; }
    .loja-actions { display:flex; align-items:center; gap:.5rem; flex-shrink:0; }

    .btn-pfsense {
      border:none; border-radius:8px; padding:.5rem .9rem;
      font-size:.8rem; font-weight:600; cursor:pointer;
      transition:all .15s; display:flex; align-items:center; gap:.35rem;
    }
    .btn-pfsense:hover { filter:brightness(1.1); transform:translateY(-1px); }

    .btn-reveal {
      background:#f3f4f6; color:#374151; border:none;
      border-radius:8px; padding:.5rem .65rem; font-size:.85rem;
      cursor:pointer; transition:all .15s;
    }
    .btn-reveal:hover { background:#e5e7eb; }
    .btn-reveal.revealed { background:#fef3c7; color:#92400e; }

    .pwd-display {
      font-family:'Consolas','Courier New',monospace;
      font-size:.85rem; color:#059669; font-weight:700;
      background:#f0fdf4; border-radius:6px; padding:.2rem .6rem;
      display:none; align-items:center; gap:.5rem;
    }
    .pwd-display.show { display:inline-flex; }
    .pwd-display .btn-copy {
      border:none; background:#d1fae5; border-radius:4px;
      padding:.1rem .4rem; cursor:pointer; font-size:.7rem; color:#065f46;
    }
    .pwd-display .btn-copy:hover { background:#a7f3d0; }

    .btn-config {
      background:transparent; border:none; color:#9ca3af;
      cursor:pointer; padding:.3rem; border-radius:6px; font-size:.9rem;
    }
    .btn-config:hover { background:#f3f4f6; color:#374151; }

    .card-add {
      border:2px dashed #d1d5db; background:transparent; border-radius:12px;
      padding:1.5rem; text-align:center; color:#9ca3af; cursor:pointer;
      margin-bottom:.75rem; transition:all .15s;
    }
    .card-add:hover { border-color:#6b7280; color:#374151; background:#f9fafb; }

    .stats-row { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
    .stat-pill {
      background:white; border:1px solid #e5e7eb; border-radius:10px;
      padding:.5rem 1rem; font-size:.8rem; display:flex; align-items:center; gap:.5rem;
      box-shadow:0 1px 4px rgba(0,0,0,.04);
    }

    /* ── Player mode (topbar + iframe) ── */
    .player-topbar {
      background:#1f2937; color:white;
      padding:.5rem 1rem;
      display:flex; align-items:center; gap:.75rem;
      flex-wrap:wrap; font-size:.85rem;
      z-index:200; position:relative;
    }
    .player-topbar .btn-player {
      background:rgba(255,255,255,.12); border:none; color:white;
      border-radius:6px; padding:.35rem .7rem; font-size:.8rem;
      cursor:pointer; transition:all .15s; text-decoration:none;
      display:inline-flex; align-items:center; gap:.35rem;
    }
    .player-topbar .btn-player:hover { background:rgba(255,255,255,.25); }
    .player-topbar .btn-player-outline {
      background:transparent; border:1px solid rgba(255,255,255,.25); color:white;
      border-radius:6px; padding:.35rem .7rem; font-size:.8rem;
      cursor:pointer; transition:all .15s; text-decoration:none;
      display:inline-flex; align-items:center; gap:.35rem;
    }
    .player-topbar .btn-player-outline:hover { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.4); }
    .player-topbar .loja-badge {
      background:#374151; border-radius:4px;
      padding:.2rem .6rem; font-size:.75rem; font-family:monospace;
    }
    .player-topbar .sep { color:#6b7280; font-size:.7rem; }

    .player-frame {
      width:100%; height:calc(100vh - 80px);
      border:none; display:block;
    }

    #view-list { display:block; }
    #view-player { display:none; }

    body.player-mode #view-list { display:none; }
    body.player-mode #view-player { display:block; }
    body.player-mode .hero { display:none; }
    body.player-mode .topbar { display:none; }

    /* ── Toast ── */
    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<!-- ── Topbar padrão (modo lista) ── -->
<div class="topbar">
  <div class="brand"><i class="bi bi-shield-fill-check me-1"></i> Central pfSense</div>
  <div style="display:flex;gap:.5rem">
    <?php if ($is_admin): ?>
    <button onclick="abrirModalLoja()" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer">
      <i class="bi bi-plus-lg me-1"></i>Nova Loja
    </button>
    <?php endif; ?>
    <a href="acessos.php"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Acessos</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<!-- ── Hero (modo lista) ── -->
<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-shield-fill-check me-2"></i>pfSense — Todas as Lojas
  </h1>
  <p style="opacity:.8;margin-top:.5rem">
    Firewalls pfSense do Grupo Gmais — clique em <strong>Abrir</strong> para acessar direto
  </p>
</div>

<!-- ── View: Lista de lojas ── -->
<div id="view-list">
  <div class="wrap" id="lista-lojas"></div>
</div>

<!-- ── View: Player pfSense ── -->
<div id="view-player">
  <div class="player-topbar" id="player-topbar">
    <!-- preenchido via JS -->
  </div>
  <iframe class="player-frame" id="player-frame" sandbox="allow-same-origin allow-forms allow-scripts"></iframe>
</div>

<!-- ── Modal: adicionar/editar loja ── -->
<div class="modal fade" id="modalLoja" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);color:white">
        <h5 class="modal-title fw-bold" id="modal-loja-label">
          <i class="bi bi-shield-fill-check me-2"></i>Nova Loja
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Loja</label>
          <input type="text" class="form-control" id="edit-loja" placeholder="Ex: Loja 001"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">IP do pfSense</label>
          <input type="text" class="form-control font-monospace" id="edit-ip" placeholder="192.168.1.1"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário</label>
          <input type="text" class="form-control font-monospace" id="edit-usuario" placeholder="admin"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">
            Senha <span class="text-muted small">(deixe em branco para manter ao editar)</span>
          </label>
          <input type="password" class="form-control font-monospace" id="edit-senha" placeholder="••••••••"/>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="edit-mostrar-senha" onchange="alternarVisibilidadeSenha()">
            <label class="form-check-label small" for="edit-mostrar-senha">Mostrar senha</label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarLoja()" style="background:#b91c1c;border-color:#b91c1c">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalLoja;
const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
  modalLoja = new bootstrap.Modal(document.getElementById('modalLoja'));
  carregarLojas();
});

// ── Carregar lista de lojas ──────────────────────────────────────
async function carregarLojas() {
  const r   = await fetch('pfsense_proxy.php?action=list');
  const d   = await r.json();
  const el  = document.getElementById('lista-lojas');
  const lojas = d.dados || [];

  if (!lojas.length) {
    el.innerHTML = `
      <div style="text-align:center;padding:3rem 1rem;color:#9ca3af">
        <i class="bi bi-shield-slash" style="font-size:3rem;display:block;margin-bottom:1rem"></i>
        <p>Nenhuma loja cadastrada.</p>
        ${isAdmin ? '<button class="btn btn-outline-danger btn-sm" onclick="abrirModalLoja()">Adicionar primeira loja</button>' : ''}
      </div>`;
    return;
  }

  let html = `<div class="stats-row">
    <div class="stat-pill"><i class="bi bi-shield-fill-check text-danger"></i>${lojas.length} loja(s)</div>
    <div class="stat-pill"><i class="bi bi-layers text-muted"></i>Clique em <strong>Abrir</strong> para acessar sem digitar senha</div>
  </div>`;

  lojas.forEach(l => {
    html += `
      <div class="loja-card" id="loja-${l.id}">
        <div class="loja-info">
          <div class="loja-icon"><i class="bi bi-shield-fill-check"></i></div>
          <div>
            <div class="loja-nome">${esc(l.loja)}</div>
            <div class="loja-ip">${esc(l.ip)}</div>
            <div class="loja-user">${esc(l.usuario)}</div>
          </div>
        </div>
        <div class="loja-actions">
          <div class="pwd-display" id="pwd-${l.id}">
            <span id="pwd-val-${l.id}"></span>
            <button class="btn-copy" onclick="copiarSenha(${l.id})" title="Copiar senha">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
          <button class="btn-reveal" id="reveal-${l.id}" onclick="revelarSenha(${l.id})" title="Ver senha">
            <i class="bi bi-eye"></i>
          </button>
          <button class="btn-pfsense" style="background:#059669;color:white" onclick="abrirPlayer(${l.id})">
            <i class="bi bi-play-fill"></i>Abrir
          </button>
          ${isAdmin ? `
          <button class="btn-config" onclick="editarLoja(${l.id})" title="Editar">
            <i class="bi bi-pencil-fill"></i>
          </button>
          <button class="btn-config" onclick="excluirLoja(${l.id})" title="Excluir" style="color:#ef4444">
            <i class="bi bi-trash-fill"></i>
          </button>` : ''}
        </div>
      </div>`;
  });

  if (isAdmin) {
    html += `<div class="card-add" onclick="abrirModalLoja()">
      <i class="bi bi-plus-circle" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
      <strong>Adicionar nova loja</strong>
      <div style="font-size:.8rem">Cadastre mais um firewall pfSense</div>
    </div>`;
  }

  el.innerHTML = html;
}

// ── Abrir pfSense no player (mesma página) ───────────────────────
function abrirPlayer(lojaId) {
  const iframe = document.getElementById('player-frame');
  const topbar = document.getElementById('player-topbar');

  // Busca dados da loja pra mostrar na barra
  fetch(`pfsense_proxy.php?action=reveal&id=${lojaId}`)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { toast(d.msg || 'Erro', 'danger'); return; }
      topbar.innerHTML = `
        <button class="btn-player" onclick="fecharPlayer()">
          <i class="bi bi-arrow-left"></i> Voltar
        </button>
        <span class="sep">|</span>
        <i class="bi bi-shield-fill-check text-danger"></i>
        <strong>${esc(d.loja)}</strong>
        <span class="loja-badge">${esc(d.ip)}</span>
        <span class="sep">|</span>
        <button class="btn-player-outline" onclick="document.getElementById('player-frame').contentWindow.location.reload()">
          <i class="bi bi-arrow-clockwise"></i> Recarregar
        </button>
        <a class="btn-player-outline" href="https://${esc(d.ip)}" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right"></i> Abrir externo
        </a>
      `;

      // Carrega o proxy no iframe
      iframe.src = `pfsense_proxy.php?loja=${lojaId}&path=/`;
      document.body.classList.add('player-mode');
    })
    .catch(() => toast('Erro ao carregar', 'danger'));
}

function fecharPlayer() {
  document.body.classList.remove('player-mode');
  document.getElementById('player-frame').src = 'about:blank';
}

// ── Revelar senha ────────────────────────────────────────────────
async function revelarSenha(id) {
  const btn   = document.getElementById('reveal-' + id);
  const pwdEl = document.getElementById('pwd-' + id);
  const valEl = document.getElementById('pwd-val-' + id);

  if (pwdEl.classList.contains('show')) {
    pwdEl.classList.remove('show');
    btn.classList.remove('revealed');
    btn.innerHTML = '<i class="bi bi-eye"></i>';
    return;
  }

  const r = await fetch(`pfsense_proxy.php?action=reveal&id=${id}`);
  const d = await r.json();
  if (!d.ok) { toast(d.msg || 'Erro', 'danger'); return; }

  valEl.textContent = d.senha;
  pwdEl.classList.add('show');
  btn.classList.add('revealed');
  btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
  toast('🔑 Senha revelada', 'info');
}

async function copiarSenha(id) {
  const valEl = document.getElementById('pwd-val-' + id);
  try {
    await navigator.clipboard.writeText(valEl.textContent);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = valEl.textContent;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
  toast('📋 Senha copiada!', 'success');
}

// ── Admin: CRUD ──────────────────────────────────────────────────
function abrirModalLoja() {
  document.getElementById('edit-id').value = '';
  document.getElementById('edit-loja').value = '';
  document.getElementById('edit-ip').value = '';
  document.getElementById('edit-usuario').value = '';
  document.getElementById('edit-senha').value = '';
  document.getElementById('edit-mostrar-senha').checked = false;
  document.getElementById('edit-senha').type = 'password';
  document.getElementById('modal-loja-label').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Nova Loja';
  modalLoja.show();
}

async function editarLoja(id) {
  const r = await fetch(`pfsense_proxy.php?action=reveal&id=${id}`);
  const d = await r.json();
  if (!d.ok) { toast(d.msg || 'Erro', 'danger'); return; }

  document.getElementById('edit-id').value = id;
  document.getElementById('edit-loja').value = d.loja;
  document.getElementById('edit-ip').value = d.ip;
  document.getElementById('edit-usuario').value = d.usuario;
  document.getElementById('edit-senha').value = '';
  document.getElementById('edit-mostrar-senha').checked = false;
  document.getElementById('edit-senha').type = 'password';
  document.getElementById('modal-loja-label').innerHTML = `<i class="bi bi-pencil-fill me-2"></i>${esc(d.loja)}`;
  modalLoja.show();
}

async function salvarLoja() {
  const id      = document.getElementById('edit-id').value;
  const loja    = document.getElementById('edit-loja').value.trim();
  const ip      = document.getElementById('edit-ip').value.trim();
  const usuario = document.getElementById('edit-usuario').value.trim();
  const senha   = document.getElementById('edit-senha').value;

  if (!loja || !ip || !usuario) { toast('Preencha loja, IP e usuário', 'danger'); return; }
  if (!id && !senha) { toast('Informe a senha', 'danger'); return; }

  const action = id ? 'edit' : 'add';
  const r = await fetch(`pfsense_proxy.php?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: parseInt(id) || 0, loja, ip, usuario, senha }),
  });
  const d = await r.json();
  if (d.ok) {
    modalLoja.hide();
    toast(id ? '✅ Loja atualizada!' : '✅ Loja adicionada!');
    carregarLojas();
  } else {
    toast(d.msg || 'Erro ao salvar', 'danger');
  }
}

async function excluirLoja(id) {
  if (!confirm('Tem certeza que deseja excluir esta loja?')) return;
  const r = await fetch(`pfsense_proxy.php?action=delete&id=${id}`);
  const d = await r.json();
  if (d.ok) {
    toast('🗑️ Loja excluída');
    carregarLojas();
  } else {
    toast(d.msg || 'Erro ao excluir', 'danger');
  }
}

function alternarVisibilidadeSenha() {
  document.getElementById('edit-senha').type =
    document.getElementById('edit-mostrar-senha').checked ? 'text' : 'password';
}

// ── Helpers ──────────────────────────────────────────────────────
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function toast(msg, type = 'success') {
  const id = 't-' + Date.now();
  const bg = type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : 'bg-secondary';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',
    `<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2">
      <div class="d-flex">
        <div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button>
      </div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
