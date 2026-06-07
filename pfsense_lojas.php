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

// ── Tabela ─────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_pfsense_lojas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        loja        VARCHAR(100) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        usuario     VARCHAR(100) NOT NULL,
        senha_enc   TEXT         NOT NULL,
        ativo       TINYINT(1)   DEFAULT 1,
        ordem       INT          DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Popula Loja 001 se a tabela estiver vazia
if ($pdo->query("SELECT COUNT(*) FROM portal_pfsense_lojas")->fetchColumn() == 0) {
    $st = $pdo->prepare("INSERT INTO portal_pfsense_lojas (loja,ip,usuario,senha_enc,ordem) VALUES (?,?,?,?,?)");
    $st->execute(['Loja 001', '192.168.1.1', 'admin', vault_encrypt('gm560max2005'), 1]);
}

// ── Pega a primeira loja (pra exibir como destino do card pfSense) ──
$primeira_loja = $pdo->query("SELECT id, loja, ip, usuario FROM portal_pfsense_lojas WHERE ativo=1 ORDER BY ordem, loja LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── AJAX ────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // Listar todas
    if ($action === 'list') {
        $rows = $pdo->query("SELECT id, loja, ip, usuario, ordem FROM portal_pfsense_lojas WHERE ativo=1 ORDER BY ordem, loja")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'dados' => $rows]);
        exit;
    }

    // Revelar senha
    if ($action === 'reveal' && isset($_GET['id'])) {
        $id  = (int)$_GET['id'];
        $st  = $pdo->prepare("SELECT loja, ip, usuario, senha_enc FROM portal_pfsense_lojas WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Loja não encontrada']); exit; }
        echo json_encode([
            'ok'       => true,
            'loja'     => $row['loja'],
            'ip'       => $row['ip'],
            'usuario'  => $row['usuario'],
            'senha'    => vault_decrypt($row['senha_enc']),
        ]);
        exit;
    }

    if (!$is_admin) { echo json_encode(['ok' => false, 'msg' => 'Sem permissão']); exit; }

    // Adicionar
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $loja    = trim($body['loja'] ?? '');
        $ip      = trim($body['ip'] ?? '');
        $usuario = trim($body['usuario'] ?? '');
        $senha   = $body['senha'] ?? '';
        if (!$loja || !$ip || !$usuario || !$senha) {
            echo json_encode(['ok' => false, 'msg' => 'Preencha todos os campos']); exit;
        }
        $maxOrdem = $pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM portal_pfsense_lojas")->fetchColumn();
        $st = $pdo->prepare("INSERT INTO portal_pfsense_lojas (loja,ip,usuario,senha_enc,ordem) VALUES (?,?,?,?,?)");
        $st->execute([$loja, $ip, $usuario, vault_encrypt($senha), $maxOrdem]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // Editar
    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id      = (int)($body['id'] ?? 0);
        $loja    = trim($body['loja'] ?? '');
        $ip      = trim($body['ip'] ?? '');
        $usuario = trim($body['usuario'] ?? '');
        $senha   = $body['senha'] ?? '';
        if (!$id || !$loja || !$ip || !$usuario) {
            echo json_encode(['ok' => false, 'msg' => 'Preencha todos os campos']); exit;
        }
        if ($senha) {
            $st = $pdo->prepare("UPDATE portal_pfsense_lojas SET loja=?, ip=?, usuario=?, senha_enc=? WHERE id=?");
            $st->execute([$loja, $ip, $usuario, vault_encrypt($senha), $id]);
        } else {
            $st = $pdo->prepare("UPDATE portal_pfsense_lojas SET loja=?, ip=?, usuario=? WHERE id=?");
            $st->execute([$loja, $ip, $usuario, $id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Excluir
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $pdo->prepare("DELETE FROM portal_pfsense_lojas WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
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
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

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

    .hero {
      background:linear-gradient(135deg,#7f1d1d,var(--primary));
      color:white; padding:2rem 1rem 4rem; text-align:center;
    }

    .wrap { max-width:800px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    .loja-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      padding:1.25rem 1.5rem; margin-bottom:.75rem;
      display:flex; align-items:center; justify-content:space-between; gap:1rem;
      transition:box-shadow .18s;
    }
    .loja-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }

    .loja-info {
      display:flex; align-items:center; gap:1rem; flex:1; min-width:0;
    }
    .loja-icon {
      width:48px; height:48px; border-radius:12px;
      background:#fee2e2; color:#b91c1c;
      display:flex; align-items:center; justify-content:center;
      font-size:1.4rem; flex-shrink:0;
    }
    .loja-nome { font-weight:700; font-size:1rem; color:#111; }
    .loja-ip   { font-size:.8rem; color:#6b7280; font-family:'Consolas','Courier New',monospace; }
    .loja-user { font-size:.75rem; color:#9ca3af; }

    .loja-actions {
      display:flex; align-items:center; gap:.5rem; flex-shrink:0;
    }
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

    /* Card "Adicionar" */
    .card-add {
      border:2px dashed #d1d5db; background:transparent; border-radius:12px;
      padding:1.5rem; text-align:center; color:#9ca3af; cursor:pointer;
      margin-bottom:.75rem; transition:all .15s;
    }
    .card-add:hover { border-color:#6b7280; color:#374151; background:#f9fafb; }

    /* Stats row */
    .stats-row {
      display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;
    }
    .stat-pill {
      background:white; border:1px solid #e5e7eb; border-radius:10px;
      padding:.5rem 1rem; font-size:.8rem; display:flex; align-items:center; gap:.5rem;
      box-shadow:0 1px 4px rgba(0,0,0,.04);
    }

    /* Toast */
    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

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

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-shield-fill-check me-2"></i>pfSense — Todas as Lojas
  </h1>
  <p style="opacity:.8;margin-top:.5rem">
    Firewalls pfSense do Grupo Gmais — acesso rápido e organizado
  </p>
</div>

<div class="wrap" id="lista-lojas">
  <!-- renderizado via JS -->
</div>

<!-- Modal: adicionar/editar loja -->
<div class="modal fade" id="modalLoja" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);color:white">
        <h5 class="modal-title fw-bold" id="modal-loja-titulo">
          <i class="bi bi-shield-fill-check me-2"></i><span id="modal-loja-label">Nova Loja</span>
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
            Senha <span class="text-muted small">(deixe em branco para manter a atual ao editar)</span>
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

// ── Carregar lista ───────────────────────────────────────────────
async function carregarLojas() {
  const r   = await fetch('pfsense_lojas.php?action=list');
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

  // Stats
  let html = `<div class="stats-row">
    <div class="stat-pill"><i class="bi bi-shield-fill-check text-danger"></i>${lojas.length} loja(s) configurada(s)</div>
    <div class="stat-pill"><i class="bi bi-shield-slash text-muted"></i>Acesso via navegador — sem alteração nos firewalls</div>
  </div>`;

  // Cards
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
          <button class="btn-pfsense" style="background:#b91c1c;color:white" onclick="abrirPfSense('${esc(l.ip)}')">
            <i class="bi bi-box-arrow-up-right"></i>Abrir
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

  // Card adicionar (admin)
  if (isAdmin) {
    html += `<div class="card-add" onclick="abrirModalLoja()">
      <i class="bi bi-plus-circle" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
      <strong>Adicionar nova loja</strong>
      <div style="font-size:.8rem">Cadastre mais um firewall pfSense</div>
    </div>`;
  }

  el.innerHTML = html;
}

// ── Ações ────────────────────────────────────────────────────────
function abrirPfSense(ip) {
  window.open(`https://${ip}`, '_blank', 'noopener');
}

async function revelarSenha(id) {
  const btn   = document.getElementById('reveal-' + id);
  const pwdEl = document.getElementById('pwd-' + id);
  const valEl = document.getElementById('pwd-val-' + id);

  // Se já revelado, só toggle visual
  if (pwdEl.classList.contains('show')) {
    pwdEl.classList.remove('show');
    btn.classList.remove('revealed');
    btn.innerHTML = '<i class="bi bi-eye"></i>';
    return;
  }

  const r = await fetch(`pfsense_lojas.php?action=reveal&id=${id}`);
  const d = await r.json();
  if (!d.ok) { toast(d.msg || 'Erro', 'danger'); return; }

  valEl.textContent = d.senha;
  pwdEl.classList.add('show');
  btn.classList.add('revealed');
  btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
  toast('🔑 Senha revelada — clique no olho para ocultar', 'info');
}

async function copiarSenha(id) {
  const valEl = document.getElementById('pwd-val-' + id);
  try {
    await navigator.clipboard.writeText(valEl.textContent);
    toast('📋 Senha copiada!', 'success');
  } catch {
    // Fallback
    const ta = document.createElement('textarea');
    ta.value = valEl.textContent;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    toast('📋 Senha copiada!', 'success');
  }
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
  document.getElementById('modal-loja-label').textContent = 'Nova Loja';
  document.getElementById('modal-loja-titulo').innerHTML =
    `<i class="bi bi-plus-circle-fill me-2"></i><span id="modal-loja-label">Nova Loja</span>`;
  modalLoja.show();
}

async function editarLoja(id) {
  const r = await fetch(`pfsense_lojas.php?action=reveal&id=${id}`);
  const d = await r.json();
  if (!d.ok) { toast(d.msg || 'Erro', 'danger'); return; }

  document.getElementById('edit-id').value = id;
  document.getElementById('edit-loja').value = d.loja;
  document.getElementById('edit-ip').value = d.ip;
  document.getElementById('edit-usuario').value = d.usuario;
  document.getElementById('edit-senha').value = '';
  document.getElementById('edit-mostrar-senha').checked = false;
  document.getElementById('edit-senha').type = 'password';
  document.getElementById('modal-loja-label').textContent = d.loja;
  document.getElementById('modal-loja-titulo').innerHTML =
    `<i class="bi bi-pencil-fill me-2"></i><span id="modal-loja-label">${esc(d.loja)}</span>`;
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
  const r = await fetch(`pfsense_lojas.php?action=${action}`, {
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
  const r = await fetch(`pfsense_lojas.php?action=delete&id=${id}`);
  const d = await r.json();
  if (d.ok) {
    toast('🗑️ Loja excluída');
    carregarLojas();
  } else {
    toast(d.msg || 'Erro ao excluir', 'danger');
  }
}

function alternarVisibilidadeSenha() {
  const el = document.getElementById('edit-senha');
  el.type = document.getElementById('edit-mostrar-senha').checked ? 'text' : 'password';
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
