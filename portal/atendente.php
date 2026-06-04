<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../auth.php'); exit; }

$nome     = $_SESSION['nome']    ?? $_SESSION['usuario'] ?? '';
$usuario  = $_SESSION['usuario'] ?? '';
$user_id  = $_SESSION['user_id'] ?? null;

require_once __DIR__ . '/../agenda/config.php';

// Busca entidade do usuário via API
function get_user_entity(int $user_id): string {
    if (!$user_id) return 'Entidade raiz';
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch   = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return 'Entidade raiz';

    $ch2 = curl_init(GLPI_URL . '/apirest.php/User/' . $user_id . '?expand_dropdowns=true');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
    $u = json_decode(curl_exec($ch2), true); curl_close($ch2);

    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
    curl_exec($ch3); curl_close($ch3);

    return $u['entities_id'] ?? 'Entidade raiz';
}

$entidade = get_user_entity((int)$user_id);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Abrir Chamado — Atendente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --accent: #1a73e8; }
    body { background: #f0f4f9; font-family: 'Segoe UI', sans-serif; min-height: 100vh; }

    /* Navbar */
    .topbar {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: .9rem 2rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 12px rgba(0,0,0,.25);
    }
    .topbar .brand { font-size: 1.1rem; font-weight: 700; display:flex; align-items:center; gap:.5rem; }
    .btn-back { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); color:white;
                border-radius:8px; padding:.3rem .8rem; font-size:.82rem; text-decoration:none; transition:background .2s; }
    .btn-back:hover { background:rgba(255,255,255,.25); color:white; }

    /* Hero */
    .hero { background: linear-gradient(135deg, var(--primary), #1565c0); color:white; padding:2.5rem 1rem 5rem; text-align:center; }
    .hero h1 { font-size:1.6rem; font-weight:700; }
    .hero p  { opacity:.8; font-size:.95rem; }

    /* Card */
    .form-card {
      max-width: 760px; margin: -3.5rem auto 3rem;
      background: white; border-radius: 16px;
      box-shadow: 0 8px 32px rgba(0,0,0,.12);
      padding: 2rem 2.5rem;
    }

    /* Requerente banner */
    .req-banner {
      background: #e8f0fe; border: 1px solid #c5d8fb;
      border-radius: 10px; padding: .75rem 1.1rem;
      display: flex; align-items: center; gap: .75rem;
      margin-bottom: 1.5rem;
    }
    .req-avatar {
      width: 40px; height: 40px; background: var(--accent);
      border-radius: 50%; display:flex; align-items:center; justify-content:center;
      color:white; font-size:1.1rem; flex-shrink:0;
    }
    .req-info .name { font-weight: 700; color: #1a237e; }
    .req-info .entity { font-size: .82rem; color: #555; }

    /* Tipo */
    .tipo-btn {
      border: 2px solid #dee2e6; border-radius: 12px;
      padding: 1.1rem; text-align: center; cursor: pointer;
      transition: all .2s; flex: 1;
    }
    .tipo-btn:hover, .tipo-btn.active { border-color: var(--accent); background: #e8f0fe; }
    .tipo-btn i { font-size: 1.8rem; color: var(--accent); display:block; margin-bottom:.4rem; }
    .tipo-btn strong { display:block; }
    .tipo-btn small { color: #888; }

    /* Urgência */
    .urg-pill {
      cursor: pointer; border-radius: 20px; padding: .4rem 1.1rem;
      border: 2px solid #dee2e6; font-size: .85rem; transition: all .2s;
    }
    .urg-pill:hover { border-color: var(--accent); }
    .urg-pill.active-1 { background:#d4edda; border-color:#28a745; color:#155724; font-weight:600; }
    .urg-pill.active-2 { background:#e3f2fd; border-color:#1a73e8; color:#0d47a1; font-weight:600; }
    .urg-pill.active-3 { background:#fff3e0; border-color:#fd7e14; color:#7c3f00; font-weight:600; }
    .urg-pill.active-4 { background:#fce4ec; border-color:#dc3545; color:#721c24; font-weight:600; }

    /* Drop zone */
    .drop-zone {
      border: 2px dashed #ccc; border-radius: 10px;
      padding: 1.5rem; text-align: center; cursor: pointer;
      transition: all .2s; background: #fafafa;
    }
    .drop-zone:hover, .drop-zone.dragover { border-color: var(--accent); background: #f0f4ff; }
    .arquivo-chip {
      display:flex; align-items:center; gap:.4rem;
      background:#f1f3f4; border-radius:20px; padding:.3rem .75rem;
      font-size:.8rem; max-width:200px;
    }
    .arquivo-chip .rm { cursor:pointer; color:#999; }
    .arquivo-chip .rm:hover { color:#d93025; }

    /* Btn submit */
    .btn-submit {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      border:none; color:white; padding:.75rem 2.5rem;
      border-radius:10px; font-size:1rem; font-weight:600;
      transition:opacity .2s; width:100%;
    }
    .btn-submit:hover { opacity:.9; color:white; }
    .btn-submit:disabled { opacity:.6; }

    /* Sucesso */
    .success-box {
      text-align:center; padding:3rem 1rem; display:none;
    }
    .success-box .icon { font-size:4rem; color:#1e8e3e; }
    .success-box h4 { font-weight:700; margin:.75rem 0 .3rem; }

    /* Divider */
    .section-title { font-weight:700; font-size:.85rem; color:#666; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.75rem; }
  </style>
</head>
<body>

<!-- Navbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-ticket-perforated"></i> Abrir Chamado</div>
  <a href="../dashboard.php" class="btn-back"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-ticket-perforated me-2"></i>Novo Chamado</h1>
  <p>Preencha os dados abaixo para registrar o chamado no GLPI</p>
</div>

<!-- Formulário -->
<div class="form-card" id="formWrap">

  <!-- Requerente automático -->
  <div class="req-banner">
    <div class="req-avatar"><i class="bi bi-person-fill"></i></div>
    <div class="req-info">
      <div class="name"><?= htmlspecialchars($nome) ?></div>
      <div class="entity"><i class="bi bi-building me-1"></i><?= htmlspecialchars($entidade) ?></div>
    </div>
    <span class="ms-auto badge bg-primary">Requerente</span>
  </div>

  <!-- Tipo -->
  <div class="section-title">Tipo de solicitação</div>
  <div class="d-flex gap-3 mb-4">
    <div class="tipo-btn active" data-tipo="1" onclick="selectTipo(this)">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <strong>Incidente</strong>
      <small>Algo parou de funcionar</small>
    </div>
    <div class="tipo-btn" data-tipo="2" onclick="selectTipo(this)">
      <i class="bi bi-clipboard-plus"></i>
      <strong>Requisição</strong>
      <small>Preciso de algo novo</small>
    </div>
  </div>
  <input type="hidden" id="tipo" value="1"/>

  <!-- Urgência -->
  <div class="section-title mb-2">Urgência</div>
  <div class="d-flex gap-2 flex-wrap mb-4">
    <span class="urg-pill" data-urg="1" onclick="selectUrg(this)">🟢 Muito Baixa</span>
    <span class="urg-pill active-2" data-urg="2" onclick="selectUrg(this)">🔵 Baixa</span>
    <span class="urg-pill" data-urg="3" onclick="selectUrg(this)">🟡 Média</span>
    <span class="urg-pill" data-urg="4" onclick="selectUrg(this)">🔴 Alta</span>
  </div>
  <input type="hidden" id="urgencia" value="2"/>

  <!-- Título -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Título <span class="text-danger">*</span></label>
    <input type="text" id="titulo" class="form-control" placeholder="Resumo do chamado" maxlength="150"/>
  </div>

  <!-- Descrição -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Descrição <span class="text-danger">*</span></label>
    <textarea id="descricao" class="form-control" rows="5"
      placeholder="Descreva detalhadamente: o que aconteceu, quando, qual equipamento, mensagens de erro..."></textarea>
  </div>

  <!-- Anexos -->
  <div class="mb-4">
    <label class="form-label fw-semibold">Anexos <span class="text-muted fw-normal">(opcional)</span></label>
    <div class="drop-zone" id="dropZone" onclick="document.getElementById('inputArquivos').click()">
      <i class="bi bi-cloud-upload fs-3 text-muted d-block mb-1"></i>
      <span class="text-muted small">Clique ou arraste imagens, PDFs, documentos</span>
      <input type="file" id="inputArquivos" multiple accept="image/*,.pdf,.doc,.docx,.txt,.zip,.xls,.xlsx" class="d-none" onchange="adicionarArquivos(this.files)"/>
    </div>
    <div id="listaArquivos" class="d-flex flex-wrap gap-2 mt-2"></div>
  </div>

  <!-- Botão -->
  <button class="btn-submit" id="btnEnviar" onclick="enviarChamado()">
    <i class="bi bi-send-fill me-2"></i>Abrir Chamado
  </button>
</div>

<!-- Sucesso -->
<div class="form-card success-box" id="successBox">
  <div class="icon"><i class="bi bi-check-circle-fill"></i></div>
  <h4>Chamado aberto com sucesso!</h4>
  <p class="text-muted" id="successMsg"></p>
  <div class="d-flex gap-2 justify-content-center mt-3">
    <button class="btn btn-outline-primary" onclick="novoChamado()">
      <i class="bi bi-plus-lg me-1"></i>Abrir outro
    </button>
    <a href="../dashboard.php" class="btn btn-primary">
      <i class="bi bi-grid me-1"></i>Início
    </a>
  </div>
</div>

<script>
let arquivos = [];

// ── Tipo ───────────────────────────────────
function selectTipo(el) {
  document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tipo').value = el.dataset.tipo;
}

// ── Urgência ──────────────────────────────
function selectUrg(el) {
  document.querySelectorAll('.urg-pill').forEach(p => p.className = 'urg-pill');
  el.classList.add('active-' + el.dataset.urg);
  document.getElementById('urgencia').value = el.dataset.urg;
}

// ── Arquivos ──────────────────────────────
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('dragover'); adicionarArquivos(e.dataTransfer.files); });

function adicionarArquivos(files) {
  for (const f of files) {
    if (!arquivos.find(a => a.name === f.name)) arquivos.push(f);
  }
  renderArquivos();
}

function renderArquivos() {
  const lista = document.getElementById('listaArquivos');
  lista.innerHTML = arquivos.map((f, i) => {
    const icone = f.type.startsWith('image/') ? 'bi-image' : 'bi-file-earmark';
    const nome  = f.name.length > 22 ? f.name.slice(0, 20) + '…' : f.name;
    return `<div class="arquivo-chip">
      <i class="bi ${icone} text-secondary"></i>
      <span title="${f.name}">${nome}</span>
      <i class="bi bi-x rm" onclick="removerArquivo(${i})"></i>
    </div>`;
  }).join('');
}

function removerArquivo(i) { arquivos.splice(i, 1); renderArquivos(); }

// ── Enviar ────────────────────────────────
async function enviarChamado() {
  const titulo    = document.getElementById('titulo').value.trim();
  const descricao = document.getElementById('descricao').value.trim();
  if (!titulo)    { alert('Preencha o título do chamado.'); return; }
  if (!descricao) { alert('Preencha a descrição do chamado.'); return; }

  const btn = document.getElementById('btnEnviar');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Abrindo chamado...';

  const form = new FormData();
  form.append('titulo',    titulo);
  form.append('descricao', descricao);
  form.append('tipo',      document.getElementById('tipo').value);
  form.append('urgencia',  document.getElementById('urgencia').value);
  form.append('requerente_id', <?= $user_id ?? 0 ?>);
  arquivos.forEach(f => form.append('arquivos[]', f));

  try {
    const res  = await fetch('api_atendente.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('formWrap').style.display   = 'none';
      document.getElementById('successBox').style.display = 'block';
      const extra = data.anexos > 0 ? ` com ${data.anexos} anexo(s)` : '';
      document.getElementById('successMsg').textContent   = `Chamado #${data.ticket_id} registrado${extra}.`;
    } else {
      alert('Erro: ' + (data.msg || 'Falha ao abrir chamado.'));
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Abrir Chamado';
    }
  } catch(e) {
    alert('Erro de conexão: ' + e.message);
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Abrir Chamado';
  }
}

function novoChamado() {
  document.getElementById('formWrap').style.display   = 'block';
  document.getElementById('successBox').style.display = 'none';
  document.getElementById('titulo').value    = '';
  document.getElementById('descricao').value = '';
  document.getElementById('inputArquivos').value = '';
  arquivos = [];
  renderArquivos();
  selectTipo(document.querySelector('[data-tipo="1"]'));
  selectUrg(document.querySelector('[data-urg="2"]'));
}
</script>
</body>
</html>
