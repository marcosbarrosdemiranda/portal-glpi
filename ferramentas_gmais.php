<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Cria tabela na primeira vez ─────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_ferramentas_gmais (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(100)  NOT NULL,
        descricao VARCHAR(255)  DEFAULT '',
        url       VARCHAR(500)  DEFAULT '',
        icone     VARCHAR(60)   DEFAULT 'bi-link',
        cor_bg    VARCHAR(20)   DEFAULT '#f3e5f5',
        cor_text  VARCHAR(20)   DEFAULT '#ad1457',
        ordem     INT           DEFAULT 0,
        ativo     TINYINT(1)    DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($pdo->query("SELECT COUNT(*) FROM portal_ferramentas_gmais")->fetchColumn() == 0) {
    $ins = $pdo->prepare("INSERT INTO portal_ferramentas_gmais
        (nome,descricao,url,icone,cor_bg,cor_text,ordem) VALUES (?,?,?,?,?,?,?)");
    $defaults = [
        ['Checklist Gmais','Checklist digital do grupo','https://checklist-gmais.grupogmais.com:7414/login','bi-list-check','#f3e5f5','#ad1457',1],
        ['Ponto Gmais','Controle de ponto eletrônico do grupo','https://ponto.grupogmais.com:7413/','bi-clock-history','#f3e5f5','#ad1457',2],
    ];
    foreach ($defaults as $d) $ins->execute($d);
}

// ── API AJAX ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (!$is_admin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }

    // Salvar edição de item existente
    if ($action === 'save') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
        $st = $pdo->prepare("UPDATE portal_ferramentas_gmais SET url=?, nome=?, descricao=? WHERE id=?");
        $st->execute([trim($body['url']??''), trim($body['nome']??''), trim($body['descricao']??''), $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Adicionar novo subsistema
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $nome = trim($body['nome'] ?? '');
        if (!$nome) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
        $st = $pdo->prepare("INSERT INTO portal_ferramentas_gmais (nome,descricao,url,icone,cor_bg,cor_text,ordem)
                              VALUES (?,?,?,?,?,?,
                              (SELECT COALESCE(MAX(ordem),0)+1 FROM portal_ferramentas_gmais pfg2))");
        $st->execute([
            $nome, trim($body['descricao']??''),
            trim($body['url']??''),
            $body['icone']??'bi-link', $body['cor_bg']??'#f3e5f5', $body['cor_text']??'#ad1457',
        ]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        exit;
    }

    // Excluir item (não exclui os 2 defaults — id 1 e 2)
    if ($action === 'delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id'] ?? 0);
        if ($id > 2) {
            $pdo->prepare("DELETE FROM portal_ferramentas_gmais WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Busca itens ativos ───────────────────────────────────────
$itens = $pdo->query("SELECT * FROM portal_ferramentas_gmais WHERE ativo=1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Ferramentas Gmais</title>
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
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    .grupo-section { margin-bottom:2rem; }
    .grupo-header {
      border-radius:12px 12px 0 0; padding:.75rem 1.25rem;
      display:flex; align-items:center; justify-content:space-between; gap:.75rem;
      color:white; font-weight:700; font-size:.9rem;
      background:linear-gradient(135deg,#880e4f,#ad1457);
    }
    .grupo-body {
      background:white; border-radius:0 0 12px 12px;
      border:1px solid #e5e7eb; border-top:none;
      box-shadow:0 2px 8px rgba(0,0,0,.06);
      padding:1.25rem;
    }
    .grupo-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));
      gap:1rem;
    }

    .acesso-card {
      border-radius:12px; border:2px solid #e5e7eb;
      padding:1.25rem 1rem; text-align:center;
      transition:all .18s; cursor:pointer; background:white;
      display:flex; flex-direction:column; align-items:center; gap:.5rem;
      position:relative;
    }
    .acesso-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.12); }
    .acesso-card.sem-url { opacity:.65; }
    .acesso-card.sem-url:hover { transform:none; box-shadow:none; }

    .ac-icon {
      width:64px; height:64px; border-radius:16px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.8rem; margin-bottom:.25rem; flex-shrink:0;
    }
    .ac-nome { font-weight:700; font-size:.9rem; color:#111; line-height:1.3; }
    .ac-desc { font-size:.73rem; color:#9ca3af; min-height:28px; line-height:1.4; }

    .btn-acessar {
      margin-top:auto; width:100%; border-radius:8px; border:none;
      padding:.4rem .75rem; font-size:.8rem; font-weight:600;
      cursor:pointer; transition:all .15s;
    }
    .btn-acessar:disabled { opacity:.45; cursor:not-allowed; }

    .badge-config {
      position:absolute; top:.5rem; right:.5rem;
      background:#fef3c7; color:#92400e; border-radius:6px;
      font-size:.6rem; font-weight:700; padding:.15rem .4rem;
    }

    .btn-cfg {
      position:absolute; top:.5rem; left:.5rem;
      background:rgba(0,0,0,.06); border:none; border-radius:6px;
      padding:.2rem .4rem; font-size:.75rem; color:#6b7280; cursor:pointer;
      opacity:0; transition:opacity .15s;
    }
    .acesso-card:hover .btn-cfg { opacity:1; }
    .btn-cfg:hover { background:rgba(0,0,0,.12); color:#111; }

    .card-add {
      border:2px dashed #d1d5db; background:transparent;
      color:#9ca3af; cursor:pointer; min-height:160px;
      justify-content:center;
    }
    .card-add:hover { border-color:#6b7280; color:#374151; background:#f9fafb; }

    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-diagram-3-fill"></i> Ferramentas Gmais</div>
  <div style="display:flex;gap:.5rem">
    <?php if ($is_admin): ?>
    <button onclick="abrirModalAdicionar()" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer">
      <i class="bi bi-plus-lg me-1"></i>Adicionar
    </button>
    <?php endif; ?>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-diagram-3-fill me-2"></i>Ferramentas Gmais
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Sistemas internos do grupo em um só lugar</p>
</div>

<div class="wrap">
<div class="grupo-section">
  <div class="grupo-header">
    <span><i class="bi bi-diagram-3-fill me-2"></i>Subsistemas do grupo</span>
    <span style="font-size:.72rem;opacity:.7;font-weight:400"><?= count($itens) ?> ferramenta(s)</span>
  </div>
  <div class="grupo-body">
    <div class="grupo-grid">

    <?php foreach ($itens as $item):
      $sem_url = empty($item['url']);
      $card_cls = $sem_url ? 'acesso-card sem-url' : 'acesso-card';
    ?>
      <div class="<?= $card_cls ?>" id="card-<?= $item['id'] ?>">
        <?php if ($is_admin): ?>
        <button class="btn-cfg" onclick="editarItem(<?= $item['id'] ?>,<?= htmlspecialchars(json_encode($item)) ?>)" title="Configurar">
          <i class="bi bi-gear-fill"></i>
        </button>
        <?php endif; ?>
        <?php if ($sem_url): ?>
        <span class="badge-config">Configurar</span>
        <?php endif; ?>

        <div class="ac-icon" style="background:<?= htmlspecialchars($item['cor_bg']) ?>;color:<?= htmlspecialchars($item['cor_text']) ?>">
          <i class="bi <?= htmlspecialchars($item['icone']) ?>"></i>
        </div>
        <div class="ac-nome"><?= htmlspecialchars($item['nome']) ?></div>
        <div class="ac-desc"><?= htmlspecialchars($item['descricao']) ?></div>

        <button class="btn-acessar" data-url="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>"
                style="background:<?= htmlspecialchars($item['cor_text']) ?>;color:white"
                <?= $sem_url ? 'disabled title="Configure a URL primeiro"' : 'onclick="abrirUrl(this.dataset.url)"' ?>>
          <i class="bi bi-box-arrow-up-right me-1"></i>Acessar
        </button>
      </div>
    <?php endforeach; ?>

    <?php if ($is_admin): ?>
      <div class="acesso-card card-add" onclick="abrirModalAdicionar()">
        <i class="bi bi-plus-circle" style="font-size:1.8rem"></i>
        <div class="ac-nome">Adicionar</div>
      </div>
    <?php endif; ?>

    </div>
  </div>
</div>
</div><!-- /wrap -->

<!-- Modal: configurar item existente -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#880e4f,#ad1457);color:white">
        <h5 class="modal-title fw-bold" id="modal-edit-titulo">
          <i class="bi bi-gear-fill me-2"></i>Configurar
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome</label>
          <input type="text" class="form-control" id="edit-nome"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">URL <span class="text-muted small">(ex: https://sistema.grupogmais.com)</span></label>
          <input type="text" class="form-control font-monospace" id="edit-url" placeholder="https://"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Descrição</label>
          <input type="text" class="form-control" id="edit-descricao"/>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-danger me-auto" id="btn-excluir-item" onclick="excluirItem()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarConfig()" style="background:#ad1457;border-color:#ad1457">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: adicionar novo subsistema -->
<div class="modal fade" id="modalAdicionar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#880e4f,#ad1457);color:white">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-plus-circle-fill me-2"></i>Novo Subsistema
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="add-nome" placeholder="Ex: Frota Gmais"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">URL</label>
            <input type="text" class="form-control font-monospace" id="add-url" placeholder="https://"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Descrição</label>
            <input type="text" class="form-control" id="add-descricao"/>
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Ícone Bootstrap</label>
            <input type="text" class="form-control" id="add-icone" value="bi-link" placeholder="bi-link"/>
            <div class="form-text">
              <a href="https://icons.getbootstrap.com/" target="_blank">Ver ícones disponíveis</a>
            </div>
          </div>
          <div class="col-3">
            <label class="form-label fw-semibold">Cor fundo</label>
            <input type="color" class="form-control form-control-color" id="add-cor-bg" value="#f3e5f5"/>
          </div>
          <div class="col-3">
            <label class="form-label fw-semibold">Cor ícone</label>
            <input type="color" class="form-control form-control-color" id="add-cor-text" value="#ad1457"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="adicionarItem()" style="background:#ad1457;border-color:#ad1457">
          <i class="bi bi-plus-lg me-1"></i>Adicionar
        </button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalEditar, modalAdicionar;
let editandoId = 0;

document.addEventListener('DOMContentLoaded', () => {
  modalEditar    = new bootstrap.Modal(document.getElementById('modalEditar'));
  modalAdicionar = new bootstrap.Modal(document.getElementById('modalAdicionar'));
});

function abrirUrl(url) {
  if (!url) return;
  if (!url.startsWith('http://') && !url.startsWith('https://')) {
    location.href = url;
    return;
  }
  window.open(url, '_blank', 'noopener');
}

// ── Configurar item existente ──────────────────────────────────
function editarItem(id, item) {
  editandoId = id;
  document.getElementById('edit-id').value        = id;
  document.getElementById('edit-nome').value       = item.nome;
  document.getElementById('edit-url').value        = item.url || '';
  document.getElementById('edit-descricao').value  = item.descricao || '';
  document.getElementById('modal-edit-titulo').innerHTML =
    `<i class="bi bi-gear-fill me-2"></i>${item.nome}`;
  document.getElementById('btn-excluir-item').style.display = (id > 2) ? 'inline-block' : 'none';
  modalEditar.show();
}

async function salvarConfig() {
  const id   = document.getElementById('edit-id').value;
  const url  = document.getElementById('edit-url').value.trim();
  const nome = document.getElementById('edit-nome').value.trim();
  const desc = document.getElementById('edit-descricao').value.trim();
  const r = await fetch('ferramentas_gmais.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, url, nome, descricao: desc}),
  });
  const d = await r.json();
  if (d.ok) { modalEditar.hide(); toast('✅ Configuração salva!'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro ao salvar');
}

async function excluirItem() {
  if (!editandoId || !confirm('Excluir este subsistema?')) return;
  const r = await fetch('ferramentas_gmais.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id: editandoId}),
  });
  const d = await r.json();
  if (d.ok) { modalEditar.hide(); toast('🗑️ Removido.'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro ao excluir');
}

// ── Adicionar novo subsistema ──────────────────────────────────
function abrirModalAdicionar() { modalAdicionar.show(); }

async function adicionarItem() {
  const nome = document.getElementById('add-nome').value.trim();
  if (!nome) { alert('Informe o nome.'); return; }
  const r = await fetch('ferramentas_gmais.php?action=add', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      nome,
      descricao: document.getElementById('add-descricao').value.trim(),
      url:       document.getElementById('add-url').value.trim(),
      icone:     document.getElementById('add-icone').value.trim() || 'bi-link',
      cor_bg:    document.getElementById('add-cor-bg').value,
      cor_text:  document.getElementById('add-cor-text').value,
    }),
  });
  const d = await r.json();
  if (d.ok) { modalAdicionar.hide(); toast('✅ Subsistema adicionado!'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro');
}

// ── Toast ──────────────────────────────────────────────────────
function toast(msg, type='success') {
  const id = 'toast-' + Date.now();
  const bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2">
      <div class="d-flex">
        <div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto"
                onclick="document.getElementById('${id}').remove()"></button>
      </div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
