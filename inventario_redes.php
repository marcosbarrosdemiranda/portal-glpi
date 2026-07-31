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
