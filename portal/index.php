<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../auth.php'); exit; }
$nome    = $_SESSION['nome']    ?? $_SESSION['usuario'] ?? '';
$usuario = $_SESSION['usuario'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Abrir Chamado — Atendente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <!-- Quill editor -->
  <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; --border:#d1d5db; }
    body { background:#f3f4f6; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    /* Topbar */
    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; transition:background .2s; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    /* Layout */
    .page-wrap { max-width:1280px; margin:0 auto; padding:1.25rem 1rem 3rem; }
    .page-title { font-size:1rem; font-weight:700; color:#374151; margin-bottom:1rem;
                  display:flex; align-items:center; gap:.5rem; }

    .two-col { display:grid; grid-template-columns:1fr 340px; gap:1rem; align-items:start; }

    /* Cards */
    .glpi-card {
      background:white; border-radius:10px;
      border:1px solid var(--border);
      box-shadow:0 1px 4px rgba(0,0,0,.06);
      overflow:hidden; margin-bottom:1rem;
    }
    .glpi-card-header {
      background:#f9fafb; border-bottom:1px solid var(--border);
      padding:.6rem 1rem; font-weight:700; font-size:.85rem; color:#374151;
      display:flex; align-items:center; gap:.4rem; cursor:pointer;
      user-select:none;
    }
    .glpi-card-body { padding:1rem; }

    /* Entidade banner */
    .entidade-banner {
      background:#f0fdf4; border-left:4px solid #16a34a;
      padding:.6rem 1rem; border-radius:6px; font-size:.85rem;
      color:#166534; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem;
    }

    /* Form fields */
    .field-row { display:grid; grid-template-columns:130px 1fr; align-items:center;
                 gap:.5rem; padding:.5rem 0; border-bottom:1px solid #f3f4f6; }
    .field-row:last-child { border-bottom:none; }
    .field-label { font-size:.82rem; color:#6b7280; text-align:right; font-weight:500; }
    .field-val   { font-size:.85rem; }
    .field-val select, .field-val input { font-size:.83rem; }

    /* Status dot */
    .status-dot { width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px; }

    /* Atores */
    .ator-section { margin-bottom:.75rem; }
    .ator-label   { font-size:.78rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.3rem; }
    .ator-chip    { display:inline-flex; align-items:center; gap:.4rem; background:#eff6ff;
                    border:1px solid #bfdbfe; border-radius:20px; padding:.25rem .7rem;
                    font-size:.82rem; color:#1d4ed8; }
    .ator-chip .rm { cursor:pointer; color:#93c5fd; }
    .ator-chip .rm:hover { color:#d93025; }
    .ator-search { position:relative; }
    .ator-search input { font-size:.82rem; }
    .ator-dropdown {
      position:absolute; top:100%; left:0; right:0; background:white;
      border:1px solid var(--border); border-radius:6px; max-height:160px;
      overflow-y:auto; z-index:50; box-shadow:0 4px 12px rgba(0,0,0,.1); display:none;
    }
    .ator-dropdown .ator-opt {
      padding:.4rem .75rem; font-size:.82rem; cursor:pointer; transition:background .1s;
    }
    .ator-dropdown .ator-opt:hover { background:#f0f4ff; }

    /* Quill */
    .ql-container { font-size:.9rem; min-height:160px; }
    .ql-toolbar  { border-radius:6px 6px 0 0; border-color:var(--border)!important; }
    .ql-container{ border-radius:0 0 6px 6px; border-color:var(--border)!important; }

    /* Drop zone */
    .drop-zone {
      border:2px dashed #d1d5db; border-radius:8px; padding:1.2rem;
      text-align:center; cursor:pointer; transition:all .2s; background:#fafafa;
    }
    .drop-zone:hover,.drop-zone.dragover { border-color:var(--accent); background:#f0f4ff; }
    .arquivo-chip { display:inline-flex; align-items:center; gap:.35rem;
                    background:#f1f5f9; border-radius:20px; padding:.25rem .65rem; font-size:.78rem; }
    .arquivo-chip .rm { cursor:pointer; color:#9ca3af; }
    .arquivo-chip .rm:hover { color:#d93025; }

    /* Btn */
    .btn-abrir {
      background:linear-gradient(135deg,var(--primary),var(--accent));
      border:none; color:white; padding:.65rem 2rem; border-radius:8px;
      font-weight:700; font-size:.95rem; width:100%; transition:opacity .2s;
    }
    .btn-abrir:hover { opacity:.9; color:white; }
    .btn-abrir:disabled { opacity:.6; }

    /* Prioridade badge */
    .prio-badge { display:inline-block; width:12px;height:12px;border-radius:50%;margin-right:4px; }

    @media(max-width:900px) { .two-col { grid-template-columns:1fr; } }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-ticket-perforated"></i> Novo Chamado — Atendente</div>
  <div class="d-flex gap-2">
    <a href="../dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="page-wrap">
  <div class="page-title"><i class="bi bi-plus-circle text-primary"></i> Adicionar um chamado</div>

  <div class="two-col">

    <!-- ── COLUNA ESQUERDA ── -->
    <div>
      <!-- Entidade -->
      <div class="entidade-banner" id="entidadeBanner">
        <i class="bi bi-building"></i>
        <span>Chamado será adicionado na entidade <strong id="entidadeNome">Carregando...</strong></span>
      </div>

      <!-- Título -->
      <div class="glpi-card mb-3">
        <div class="glpi-card-body">
          <label class="form-label fw-semibold small text-secondary">Título</label>
          <input type="text" id="titulo" class="form-control" placeholder="Título do chamado"/>
        </div>
      </div>

      <!-- Descrição (Quill) -->
      <div class="glpi-card mb-3">
        <div class="glpi-card-body">
          <label class="form-label fw-semibold small text-secondary">Descrição <span class="text-danger">*</span></label>
          <div id="quillEditor"></div>
          <input type="hidden" id="descricao"/>
        </div>
      </div>

      <!-- Arquivos -->
      <div class="glpi-card mb-3">
        <div class="glpi-card-body">
          <div class="drop-zone" id="dropZone" onclick="document.getElementById('inputArquivos').click()">
            <i class="bi bi-paperclip fs-4 text-muted d-block mb-1"></i>
            <span class="text-muted small">Arquivo(s) — Arraste e solte ou clique para escolher</span>
            <input type="file" id="inputArquivos" multiple class="d-none"
                   accept="image/*,.pdf,.doc,.docx,.txt,.zip,.xls,.xlsx"
                   onchange="adicionarArquivos(this.files)"/>
          </div>
          <div id="listaArquivos" class="d-flex flex-wrap gap-2 mt-2"></div>
        </div>
      </div>

      <!-- Atores -->
      <div class="glpi-card mb-3">
        <div class="glpi-card-header" onclick="toggleCard('atores')">
          <i class="bi bi-people"></i> Atores
          <i class="bi bi-chevron-up ms-auto" id="icon-atores"></i>
        </div>
        <div class="glpi-card-body" id="body-atores">

          <!-- Requerente -->
          <div class="ator-section">
            <div class="ator-label">Requerente <span class="text-danger">*</span></div>
            <select class="form-select form-select-sm" id="sel-requerente">
              <option value="">Carregando...</option>
            </select>
          </div>


        </div>
      </div>

      <!-- Botão -->
      <button class="btn-abrir" id="btnAbrir" onclick="abrirChamado()">
        <i class="bi bi-plus-circle me-2"></i>Adicionar
      </button>

    </div><!-- /col esquerda -->

    <!-- ── COLUNA DIREITA ── -->
    <div>
      <div class="glpi-card">
        <div class="glpi-card-header"><i class="bi bi-info-circle"></i> Chamado</div>
        <div class="glpi-card-body p-0">

          <div class="px-3 pt-2">

            <div class="field-row">
              <div class="field-label">Entidade</div>
              <div class="field-val">
                <select class="form-select form-select-sm" id="sel-entidade">
                  <option value="">Carregando...</option>
                </select>
              </div>
            </div>

            <div class="field-row">
              <div class="field-label">Data de abertura</div>
              <div class="field-val">
                <input type="datetime-local" class="form-control form-control-sm" id="data-abertura"
                       value="<?= date('Y-m-d\TH:i') ?>"/>
              </div>
            </div>

            <div class="field-row">
              <div class="field-label">Tipo</div>
              <div class="field-val">
                <select class="form-select form-select-sm" id="sel-tipo">
                  <option value="1">Incidente</option>
                  <option value="2">Requisição</option>
                </select>
              </div>
            </div>

            <div class="field-row">
              <div class="field-label">Categoria</div>
              <div class="field-val">
                <select class="form-select form-select-sm" id="sel-categoria">
                  <option value="">------</option>
                </select>
              </div>
            </div>


            <div class="field-row">
              <div class="field-label">Origem</div>
              <div class="field-val">
                <select class="form-select form-select-sm" id="sel-origem">
                  <option value="1">Helpdesk</option>
                  <option value="2">E-mail</option>
                  <option value="3">Telefone</option>
                  <option value="4">Presencial</option>
                </select>
              </div>
            </div>

            <div class="field-row">
              <div class="field-label">Urgência</div>
              <div class="field-val">
                <select class="form-select form-select-sm" id="sel-urgencia" onchange="calcPrioridade()">
                  <option value="1">Muito baixa</option>
                  <option value="2" selected>Baixa</option>
                  <option value="3">Média</option>
                  <option value="4">Alta</option>
                  <option value="5">Muito alta</option>
                </select>
              </div>
            </div>


            <div class="field-row">
              <div class="field-label">Prioridade</div>
              <div class="field-val d-flex align-items-center">
                <span class="prio-badge" id="prio-dot" style="background:#f59e0b"></span>
                <span id="prio-label" class="small fw-semibold">Média</span>
              </div>
            </div>

          </div><!-- /px-3 -->
        </div>
      </div>
    </div><!-- /col direita -->

  </div><!-- /two-col -->
</div>

<!-- Success -->
<div class="modal fade" id="modalSucesso" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content text-center p-3">
      <div style="font-size:3.5rem;color:#1e8e3e"><i class="bi bi-check-circle-fill"></i></div>
      <h5 class="fw-bold mt-2">Chamado aberto!</h5>
      <p class="text-muted small" id="msg-sucesso"></p>
      <div class="d-flex gap-2 justify-content-center">
        <button class="btn btn-outline-primary btn-sm" onclick="novoChamado()">Novo chamado</button>
        <a href="../dashboard.php" class="btn btn-primary btn-sm">Início</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
// ── Quill ──────────────────────────────────────────────────────
const quill = new Quill('#quillEditor', {
  theme: 'snow',
  placeholder: 'Descreva o chamado detalhadamente...',
  modules: { toolbar: [
    ['bold','italic','underline'],
    [{ list:'ordered'},{list:'bullet'}],
    ['link','image'],
    ['clean']
  ]}
});

// ── Dados GLPI ────────────────────────────────────────────────
let todosUsuarios = [];
let modalSucesso;

document.addEventListener('DOMContentLoaded', () => {
  modalSucesso = new bootstrap.Modal(document.getElementById('modalSucesso'));
  calcPrioridade();

  const dz = document.getElementById('dropZone');
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('dragover'); adicionarArquivos(e.dataTransfer.files); });

  carregarDados();
});

function popularDropdownRequerente(usuarios) {
  const sel = document.getElementById('sel-requerente');
  const prevVal = sel.value;
  sel.innerHTML = '<option value="">Selecione o requerente...</option>' +
    usuarios.map(u => `<option value="${u.id}">${escH(u.nome || u.login)}</option>`).join('');
  // Tenta preservar seleção anterior; senão usa o usuário logado
  if (prevVal && usuarios.find(u => String(u.id) === prevVal)) {
    sel.value = prevVal;
  } else if (usuarios.find(u => u.id == USUARIO_LOGADO.id)) {
    sel.value = String(USUARIO_LOGADO.id);
  }
}

function carregarDados() {
  fetch('dados_glpi.php')
    .then(r => r.json())
    .then(d => {
      // Requerente — carrega uma vez, nunca recarrega
      todosUsuarios = d.usuarios || [];
      popularDropdownRequerente(todosUsuarios);

      // Entidades — apenas atualiza o banner, sem recarregar usuários
      const selEnt = document.getElementById('sel-entidade');
      selEnt.innerHTML = d.entidades.map(e => `<option value="${e.id}">${escH(e.nome)}</option>`).join('') || '<option value="">Entidade raiz</option>';
      document.getElementById('entidadeNome').textContent = selEnt.options[0]?.text || 'Entidade raiz';
      selEnt.addEventListener('change', () => {
        document.getElementById('entidadeNome').textContent = selEnt.options[selEnt.selectedIndex]?.text || '';
      });

      // Categorias
      const selCat = document.getElementById('sel-categoria');
      selCat.innerHTML = '<option value="">------</option>' +
        (d.categorias || []).map(c => `<option value="${c.id}">${escH(c.nome)}</option>`).join('');
    })
    .catch(() => {});
}

// ── Prioridade (média urgência + impacto) ─────────────────────
const PRIO = {
  1:{label:'Muito baixa',cor:'#6b7280'},
  2:{label:'Baixa',cor:'#3b82f6'},
  3:{label:'Média',cor:'#f59e0b'},
  4:{label:'Alta',cor:'#ef4444'},
  5:{label:'Muito alta',cor:'#7c3aed'},
};
function calcPrioridade() {
  // Impacto fixo = 3 (Médio) — usa floor para garantir Baixa como padrão
  const u = parseInt(document.getElementById('sel-urgencia').value);
  const p = Math.min(5, Math.max(1, Math.floor((u + 3) / 2)));
  document.getElementById('prio-dot').style.background = PRIO[p].cor;
  document.getElementById('prio-label').textContent     = PRIO[p].label;
  document.getElementById('prio-label').style.color     = PRIO[p].cor;
}

// ── Atores ────────────────────────────────────────────────────
const USUARIO_LOGADO = { id: <?= $user_id ?>, nome: <?= json_encode($nome) ?> };

// ── Arquivos ──────────────────────────────────────────────────
let arquivos = [];
function adicionarArquivos(files) {
  for (const f of files) if (!arquivos.find(a => a.name===f.name)) arquivos.push(f);
  renderArquivos();
}
function renderArquivos() {
  document.getElementById('listaArquivos').innerHTML = arquivos.map((f,i) => {
    const ic = f.type.startsWith('image/') ? 'bi-image' : 'bi-file-earmark';
    return `<span class="arquivo-chip"><i class="bi ${ic}"></i>${f.name.slice(0,20)}<i class="bi bi-x rm" onclick="rmArq(${i})"></i></span>`;
  }).join('');
}
function rmArq(i) { arquivos.splice(i,1); renderArquivos(); }

// ── Collapse cards ────────────────────────────────────────────
function toggleCard(id) {
  const body = document.getElementById('body-'+id);
  const icon = document.getElementById('icon-'+id);
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : '';
  icon.className = 'bi ms-auto ' + (open ? 'bi-chevron-down' : 'bi-chevron-up');
}

// ── Enviar ────────────────────────────────────────────────────
async function abrirChamado() {
  const titulo = document.getElementById('titulo').value.trim();
  const descHTML = quill.root.innerHTML;
  const descTexto = quill.getText().trim();

  const reqId = document.getElementById('sel-requerente').value;
  if (!titulo)  { alert('Preencha o título.'); return; }
  if (!descTexto) { alert('Preencha a descrição.'); return; }
  if (!reqId)   { alert('Selecione o requerente.'); return; }

  const btn = document.getElementById('btnAbrir');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Abrindo...';

  const form = new FormData();
  form.append('titulo',       titulo);
  form.append('descricao',    descHTML);
  form.append('tipo',         document.getElementById('sel-tipo').value);
  form.append('urgencia',     document.getElementById('sel-urgencia').value);
  form.append('categoria',    document.getElementById('sel-categoria').value);
  form.append('entidade',     document.getElementById('sel-entidade').value);
  form.append('origem',       document.getElementById('sel-origem').value);
  form.append('data_abertura',document.getElementById('data-abertura').value);
  form.append('requerente_id', reqId);
  arquivos.forEach(f => form.append('arquivos[]', f));

  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 40000);

  try {
    const res  = await fetch('api_atendente.php', { method:'POST', body:form, signal: ctrl.signal });
    clearTimeout(timer);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch(_) {
      alert('Resposta inesperada do servidor:\n' + text.slice(0, 300));
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Adicionar';
      return;
    }
    if (data.ok) {
      const extra = data.anexos > 0 ? ` com ${data.anexos} anexo(s)` : '';
      document.getElementById('msg-sucesso').textContent = `Chamado #${data.ticket_id} criado${extra}.`;
      modalSucesso.show();
    } else {
      alert('Erro: ' + (data.msg || 'Falha ao abrir chamado'));
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Adicionar';
    }
  } catch(e) {
    clearTimeout(timer);
    const msg = e.name === 'AbortError' ? 'Tempo esgotado — o servidor GLPI não respondeu.' : e.message;
    alert('Erro: ' + msg);
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Adicionar';
  }
}

function novoChamado() {
  modalSucesso.hide();
  document.getElementById('titulo').value = '';
  quill.setText('');
  arquivos = [];
  renderArquivos();
  document.getElementById('sel-requerente').value = USUARIO_LOGADO.id || '';
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
</body>
</html>
