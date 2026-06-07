<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Cria tabela e popula defaults na primeira vez ─────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_acessos (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(100)  NOT NULL,
        descricao VARCHAR(255)  DEFAULT '',
        grupo     VARCHAR(30)   NOT NULL DEFAULT 'remoto',
        tipo      VARCHAR(20)   DEFAULT 'web',
        url       VARCHAR(500)  DEFAULT '',
        icone     VARCHAR(60)   DEFAULT 'bi-link',
        cor_bg    VARCHAR(20)   DEFAULT '#e8f0fe',
        cor_text  VARCHAR(20)   DEFAULT '#1a73e8',
        ordem     INT           DEFAULT 0,
        ativo     TINYINT(1)    DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($pdo->query("SELECT COUNT(*) FROM portal_acessos")->fetchColumn() == 0) {
    $ins = $pdo->prepare("INSERT INTO portal_acessos
        (nome,descricao,grupo,tipo,url,icone,cor_bg,cor_text,ordem) VALUES (?,?,?,?,?,?,?,?,?)");
    $defaults = [
        // Acesso Remoto
        ['Remote Desktop','Área de Trabalho Remota do Windows (RDP)','remoto','rdp','','bi-display-fill','#dbeafe','#1d4ed8',1],
        ['VNC Viewer','Acesso remoto via browser — noVNC integrado','remoto','web','vnc.php','bi-camera-video-fill','#e0e7ff','#3730a3',2],
        ['AnyDesk','Suporte remoto via AnyDesk','remoto','web','https://anydesk.com','bi-arrows-fullscreen','#ffedd5','#c2410c',3],
        // Infraestrutura
        ['pfSense','Firewall e roteador pfSense','infra','web','','bi-shield-fill-check','#fee2e2','#b91c1c',1],
        ['VMware','Virtualização VMware vSphere / ESXi','infra','web','','bi-cloud-fill','#dbeafe','#1d4ed8',2],
        ['Mikrotik','Roteadores e switches Mikrotik','infra','web','','bi-router-fill','#fce7f3','#9d174d',3],
        ['UniFi','Controladora UniFi — Ubiquiti','infra','web','','bi-wifi','#d1fae5','#065f46',4],
        // ERP
        ['Protheus / ERP','Sistema de gestão empresarial','erp','web','','bi-building-fill-gear','#fef9c3','#854d0e',1],
    ];
    foreach ($defaults as $d) $ins->execute($d);
}

// ── Garante que o pfSense aponte para a central de lojas ────
$pdo->exec("UPDATE portal_acessos SET url='pfsense_proxy.php' WHERE nome='pfSense' AND grupo='infra' AND (url IS NULL OR url='')");

// ── API AJAX ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // Download arquivo .rdp
    if ($action === 'rdp' && isset($_GET['id'])) {
        $row = $pdo->prepare("SELECT * FROM portal_acessos WHERE id=?");
        $row->execute([(int)$_GET['id']]);
        $item = $row->fetch(PDO::FETCH_ASSOC);
        if (!$item || !$item['url']) { echo json_encode(['ok'=>false,'msg'=>'URL não configurada']); exit; }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$item['nome'].'.rdp"');
        $host = $item['url'];
        echo "full address:s:{$host}\r\n";
        echo "prompt for credentials:i:1\r\n";
        echo "administrative session:i:1\r\n";
        echo "desktopwidth:i:1280\r\n";
        echo "desktopheight:i:720\r\n";
        echo "session bpp:i:32\r\n";
        echo "compression:i:1\r\n";
        echo "keyboardhook:i:2\r\n";
        echo "audiomode:i:0\r\n";
        echo "redirectprinters:i:0\r\n";
        echo "autoreconnection enabled:i:1\r\n";
        exit;
    }

    if (!$is_admin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }

    // Salvar URL
    if ($action === 'save') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
        $st = $pdo->prepare("UPDATE portal_acessos SET url=?, nome=?, descricao=? WHERE id=?");
        $st->execute([trim($body['url']??''), trim($body['nome']??''), trim($body['descricao']??''), $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Adicionar item personalizado
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $st = $pdo->prepare("INSERT INTO portal_acessos (nome,descricao,grupo,tipo,url,icone,cor_bg,cor_text,ordem)
                              VALUES (?,?,?,?,?,?,?,?,
                              (SELECT COALESCE(MAX(ordem),0)+1 FROM portal_acessos a2 WHERE a2.grupo=?))");
        $st->execute([
            trim($body['nome']??'Novo'), trim($body['descricao']??''),
            $body['grupo']??'remoto', $body['tipo']??'web',
            trim($body['url']??''),
            $body['icone']??'bi-link', $body['cor_bg']??'#f3f4f6', $body['cor_text']??'#374151',
            $body['grupo']??'remoto',
        ]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        exit;
    }

    // Excluir item personalizado (não exclui os defaults — id ≤ 8)
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id > 8) { // apenas itens adicionados pelo usuário
            $pdo->prepare("DELETE FROM portal_acessos WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Busca todos os itens agrupados ─────────────────────────────
$itens_raw = $pdo->query("SELECT * FROM portal_acessos WHERE ativo=1 ORDER BY grupo, ordem")->fetchAll(PDO::FETCH_ASSOC);
$grupos = ['remoto' => [], 'infra' => [], 'erp' => []];
foreach ($itens_raw as $i) {
    $g = $i['grupo'];
    if (isset($grupos[$g])) $grupos[$g][] = $i;
}

$grupo_info = [
    'remoto' => ['label'=>'Acesso Remoto',      'icon'=>'bi-display',          'cor'=>'#1d4ed8', 'bg'=>'#1e3a8a'],
    'infra'  => ['label'=>'Infraestrutura',      'icon'=>'bi-hdd-network-fill', 'cor'=>'#b91c1c', 'bg'=>'#7f1d1d'],
    'erp'    => ['label'=>'Ferramentas ERP',     'icon'=>'bi-building-fill',    'cor'=>'#065f46', 'bg'=>'#064e3b'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central de Acessos</title>
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

    /* Seção de grupo */
    .grupo-section { margin-bottom:2rem; }
    .grupo-header {
      border-radius:12px 12px 0 0; padding:.75rem 1.25rem;
      display:flex; align-items:center; justify-content:space-between; gap:.75rem;
      color:white; font-weight:700; font-size:.9rem;
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

    /* Card de ferramenta */
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

    /* Badge "Não configurado" */
    .badge-config {
      position:absolute; top:.5rem; right:.5rem;
      background:#fef3c7; color:#92400e; border-radius:6px;
      font-size:.6rem; font-weight:700; padding:.15rem .4rem;
    }

    /* Botão config (admin) */
    .btn-cfg {
      position:absolute; top:.5rem; left:.5rem;
      background:rgba(0,0,0,.06); border:none; border-radius:6px;
      padding:.2rem .4rem; font-size:.75rem; color:#6b7280; cursor:pointer;
      opacity:0; transition:opacity .15s;
    }
    .acesso-card:hover .btn-cfg { opacity:1; }
    .btn-cfg:hover { background:rgba(0,0,0,.12); color:#111; }

    /* Card "Adicionar" */
    .card-add {
      border:2px dashed #d1d5db; background:transparent;
      color:#9ca3af; cursor:pointer; min-height:160px;
      justify-content:center;
    }
    .card-add:hover { border-color:#6b7280; color:#374151; background:#f9fafb; }

    /* Toast */
    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-grid-3x3-gap-fill"></i> Central de Acessos</div>
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
    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Central de Acessos
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Ferramentas e sistemas da equipe de TI em um só lugar</p>
</div>

<div class="wrap">
<?php foreach ($grupos as $grupo_id => $itens):
  $gi = $grupo_info[$grupo_id];
?>
<div class="grupo-section">
  <div class="grupo-header" style="background:linear-gradient(135deg,<?= $gi['bg'] ?>,<?= $gi['cor'] ?>)">
    <span><i class="bi <?= $gi['icon'] ?> me-2"></i><?= $gi['label'] ?></span>
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
        <button class="btn-cfg" onclick="editarAcesso(<?= $item['id'] ?>,<?= htmlspecialchars(json_encode($item)) ?>)" title="Configurar URL">
          <i class="bi bi-gear-fill"></i>
        </button>
        <?php endif; ?>
        <?php if ($sem_url): ?>
        <span class="badge-config">Configurar</span>
        <?php endif; ?>

        <div class="ac-icon" style="background:<?= $item['cor_bg'] ?>;color:<?= $item['cor_text'] ?>">
          <i class="bi <?= $item['icone'] ?>"></i>
        </div>
        <div class="ac-nome"><?= htmlspecialchars($item['nome']) ?></div>
        <div class="ac-desc"><?= htmlspecialchars($item['descricao']) ?></div>

        <?php if ($item['tipo'] === 'rdp'): ?>
          <button class="btn-acessar"
                  style="background:<?= $item['cor_text'] ?>;color:white"
                  onclick="abrirUrl('rdp_central.php')">
            <i class="bi bi-display-fill me-1"></i>Central RDP
          </button>
        <?php else: ?>
          <button class="btn-acessar"
                  style="background:<?= $item['cor_text'] ?>;color:white"
                  <?= $sem_url ? 'disabled title="Configure a URL primeiro"' : "onclick=\"abrirUrl('".htmlspecialchars($item['url'],ENT_QUOTES)."')\"" ?>>
            <i class="bi bi-box-arrow-up-right me-1"></i>Acessar
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    </div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- /wrap -->

<!-- Modal: configurar URL de um acesso -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modal-edit-titulo">
          <i class="bi bi-gear-fill me-2"></i>Configurar Acesso
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <input type="hidden" id="edit-tipo"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome</label>
          <input type="text" class="form-control" id="edit-nome"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" id="edit-url-label">
            URL / Endereço <span class="text-muted small">(ex: https://192.168.1.1)</span>
          </label>
          <input type="text" class="form-control font-monospace" id="edit-url"
                 placeholder="https:// ou IP:porta"/>
          <div class="form-text" id="edit-url-hint"></div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Descrição</label>
          <input type="text" class="form-control" id="edit-descricao"/>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarConfig()" style="background:#1a237e;border-color:#1a237e">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: adicionar nova ferramenta -->
<div class="modal fade" id="modalAdicionar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-plus-circle-fill me-2"></i>Nova Ferramenta
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-8">
            <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="add-nome" placeholder="Ex: Grafana"/>
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold">Grupo</label>
            <select class="form-select" id="add-grupo">
              <option value="remoto">Acesso Remoto</option>
              <option value="infra" selected>Infraestrutura</option>
              <option value="erp">Ferramentas ERP</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">URL / Endereço</label>
            <input type="text" class="form-control font-monospace" id="add-url" placeholder="https://"/>
          </div>
          <div class="col-8">
            <label class="form-label fw-semibold">Descrição</label>
            <input type="text" class="form-control" id="add-descricao"/>
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold">Tipo</label>
            <select class="form-select" id="add-tipo">
              <option value="web">Web</option>
              <option value="rdp">RDP</option>
            </select>
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
            <input type="color" class="form-control form-control-color" id="add-cor-bg" value="#e8f0fe"/>
          </div>
          <div class="col-3">
            <label class="form-label fw-semibold">Cor ícone</label>
            <input type="color" class="form-control form-control-color" id="add-cor-text" value="#1d4ed8"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="adicionarAcesso()" style="background:#1a237e;border-color:#1a237e">
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

document.addEventListener('DOMContentLoaded', () => {
  modalEditar   = new bootstrap.Modal(document.getElementById('modalEditar'));
  modalAdicionar= new bootstrap.Modal(document.getElementById('modalAdicionar'));
});

function abrirUrl(url) {
  if (!url) return;
  // Páginas locais (sem http) abrem na mesma aba; externas em nova aba
  if (!url.startsWith('http://') && !url.startsWith('https://')) {
    location.href = url;
    return;
  }
  window.open(url, '_blank', 'noopener');
}

function baixarRDP(id) {
  window.location.href = `acessos.php?action=rdp&id=${id}`;
}

// ── Configurar URL de um acesso ────────────────────────────────
function editarAcesso(id, item) {
  document.getElementById('edit-id').value       = id;
  document.getElementById('edit-tipo').value     = item.tipo;
  document.getElementById('edit-nome').value     = item.nome;
  document.getElementById('edit-url').value      = item.url || '';
  document.getElementById('edit-descricao').value= item.descricao || '';
  document.getElementById('modal-edit-titulo').innerHTML =
    `<i class="bi bi-gear-fill me-2"></i>${item.nome}`;

  if (item.tipo === 'rdp') {
    document.getElementById('edit-url-label').innerHTML =
      'IP / Hostname do servidor <span class="text-muted small">(ex: 192.168.1.50 ou servidor.local)</span>';
    document.getElementById('edit-url-hint').textContent =
      'Será gerado um arquivo .rdp para conexão direta com o Windows.';
    document.getElementById('edit-url').placeholder = '192.168.x.x ou hostname';
  } else {
    document.getElementById('edit-url-label').innerHTML =
      'URL / Endereço <span class="text-muted small">(ex: https://192.168.1.1)</span>';
    document.getElementById('edit-url-hint').textContent = '';
    document.getElementById('edit-url').placeholder = 'https://';
  }
  modalEditar.show();
}

async function salvarConfig() {
  const id  = document.getElementById('edit-id').value;
  const url = document.getElementById('edit-url').value.trim();
  const nome = document.getElementById('edit-nome').value.trim();
  const desc = document.getElementById('edit-descricao').value.trim();
  const r   = await fetch('acessos.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, url, nome, descricao: desc}),
  });
  const d = await r.json();
  if (d.ok) { modalEditar.hide(); toast('✅ Configuração salva!'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro ao salvar');
}

// ── Adicionar nova ferramenta ──────────────────────────────────
function abrirModalAdicionar() { modalAdicionar.show(); }

async function adicionarAcesso() {
  const nome = document.getElementById('add-nome').value.trim();
  if (!nome) { alert('Informe o nome.'); return; }
  const r = await fetch('acessos.php?action=add', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      nome,
      descricao: document.getElementById('add-descricao').value.trim(),
      grupo:     document.getElementById('add-grupo').value,
      tipo:      document.getElementById('add-tipo').value,
      url:       document.getElementById('add-url').value.trim(),
      icone:     document.getElementById('add-icone').value.trim() || 'bi-link',
      cor_bg:    document.getElementById('add-cor-bg').value,
      cor_text:  document.getElementById('add-cor-text').value,
    }),
  });
  const d = await r.json();
  if (d.ok) { modalAdicionar.hide(); toast('✅ Ferramenta adicionada!'); setTimeout(()=>location.reload(),800); }
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
