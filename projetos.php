<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Projetos de TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:white; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar a { color:white; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15);
                border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4rem; text-align:center; }
    .wrap { max-width:1100px; margin:-2.5rem auto 3rem; padding:0 1rem; }
    .card-proj { background:white; border-radius:12px; border:1px solid #e5e7eb;
                 box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.25rem; margin-bottom:1rem; }
    .prog-bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; margin-top:.4rem; }
    .prog-fill { height:100%; border-radius:4px; transition:width .5s; }
    .status-badge { font-size:.72rem; padding:.2rem .6rem; border-radius:10px; font-weight:600; }
    .st-planejamento { background:#e8f0fe; color:#1a73e8; }
    .st-andamento    { background:#fff8e1; color:#f57c00; }
    .st-concluido    { background:#e8f5e9; color:#2e7d32; }
    .st-pausado      { background:#f3e5f5; color:#7b1fa2; }
    .st-cancelado    { background:#ffebee; color:#c62828; }
    .btn-novo { background:#e91e63; border:none; color:white; border-radius:8px;
                padding:.45rem 1.25rem; font-size:.85rem; font-weight:600; cursor:pointer; }
  </style>
</head>
<body>
<div class="topbar">
  <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem">
    <i class="bi bi-kanban-fill"></i> Projetos de TI
  </div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0"><i class="bi bi-kanban-fill me-2"></i>Projetos de TI</h1>
  <p style="opacity:.8;margin-top:.5rem">Gerencie implantações, migrações e upgrades da equipe</p>
</div>

<div class="wrap">

  <!-- Filtros + novo -->
  <div style="background:white;border-radius:12px;border:1px solid #e5e7eb;padding:1rem 1.25rem;
              margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;
              box-shadow:0 2px 8px rgba(0,0,0,.06)">
    <select id="f-status" class="form-select form-select-sm" style="width:160px" onchange="filtrar()">
      <option value="">Todos os status</option>
      <option value="planejamento">Planejamento</option>
      <option value="andamento">Em andamento</option>
      <option value="concluido">Concluído</option>
      <option value="pausado">Pausado</option>
      <option value="cancelado">Cancelado</option>
    </select>
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:220px"
           placeholder="🔍 Buscar projeto..." oninput="filtrar()"/>
    <div style="flex:1"></div>
    <button class="btn-novo" onclick="abrirModal()">
      <i class="bi bi-plus-lg me-1"></i>Novo Projeto
    </button>
  </div>

  <!-- Stats -->
  <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
    <div style="background:white;border-radius:10px;border:1px solid #e5e7eb;padding:.5rem 1rem;
                font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.4rem;
                box-shadow:0 1px 4px rgba(0,0,0,.05)">
      <i class="bi bi-kanban text-primary"></i> Total: <strong id="cnt-total">0</strong>
    </div>
    <div style="background:white;border-radius:10px;border:1px solid #e5e7eb;padding:.5rem 1rem;
                font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.4rem;
                box-shadow:0 1px 4px rgba(0,0,0,.05)">
      <i class="bi bi-arrow-repeat" style="color:#f57c00"></i> Em andamento: <strong id="cnt-andamento">0</strong>
    </div>
    <div style="background:white;border-radius:10px;border:1px solid #e5e7eb;padding:.5rem 1rem;
                font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.4rem;
                box-shadow:0 1px 4px rgba(0,0,0,.05)">
      <i class="bi bi-check-circle-fill text-success"></i> Concluídos: <strong id="cnt-concluido">0</strong>
    </div>
  </div>

  <div id="lista-projetos"></div>
  <p id="sem-projetos" class="text-center text-muted py-4" style="display:none">
    <i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhum projeto encontrado. Clique em "Novo Projeto" para começar.
  </p>
</div>

<!-- Modal novo/editar projeto -->
<div class="modal fade" id="modalProjeto" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#e91e63,#c2185b);color:white">
        <h5 class="modal-title fw-bold" id="modal-titulo">
          <i class="bi bi-kanban-fill me-2"></i>Novo Projeto
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="proj-id"/>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Nome do Projeto <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="proj-nome" placeholder="Ex: Migração para Windows 11"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Status</label>
            <select class="form-select" id="proj-status">
              <option value="planejamento">📋 Planejamento</option>
              <option value="andamento">🔄 Em andamento</option>
              <option value="concluido">✅ Concluído</option>
              <option value="pausado">⏸ Pausado</option>
              <option value="cancelado">❌ Cancelado</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Prioridade</label>
            <select class="form-select" id="proj-prioridade">
              <option value="baixa">🟢 Baixa</option>
              <option value="media" selected>🟡 Média</option>
              <option value="alta">🔴 Alta</option>
              <option value="critica">🟣 Crítica</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Início</label>
            <input type="date" class="form-control" id="proj-inicio"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Prazo</label>
            <input type="date" class="form-control" id="proj-prazo"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Responsável</label>
            <input type="text" class="form-control" id="proj-responsavel" placeholder="Nome do técnico"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Progresso: <span id="prog-val">0</span>%</label>
            <input type="range" class="form-range" id="proj-progresso" min="0" max="100" value="0"
                   oninput="document.getElementById('prog-val').textContent=this.value"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Descrição</label>
            <textarea class="form-control" id="proj-descricao" rows="3"
                      placeholder="Objetivos, escopo, observações..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btn-excluir" style="display:none" onclick="excluirProjeto()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarProjeto()" style="background:#e91e63;border-color:#e91e63">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const STORE_KEY = 'ti_projetos';
let modal;
let projetos = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalProjeto'));
  renderizar(projetos);
});

const STATUS_LABEL = {
  planejamento: 'Planejamento',
  andamento:    'Em andamento',
  concluido:    'Concluído',
  pausado:      'Pausado',
  cancelado:    'Cancelado',
};
const PRIO_COR = { baixa:'#2e7d32', media:'#f57c00', alta:'#d32f2f', critica:'#6a1b9a' };
const PROG_COR = p => p >= 80 ? '#1e8e3e' : p >= 40 ? '#f57c00' : '#1a73e8';

function salvar() { localStorage.setItem(STORE_KEY, JSON.stringify(projetos)); }

function renderizar(lista) {
  const cont = document.getElementById('lista-projetos');
  const sem  = document.getElementById('sem-projetos');
  document.getElementById('cnt-total').textContent    = projetos.length;
  document.getElementById('cnt-andamento').textContent = projetos.filter(p=>p.status==='andamento').length;
  document.getElementById('cnt-concluido').textContent = projetos.filter(p=>p.status==='concluido').length;

  if (!lista.length) { cont.innerHTML=''; sem.style.display=''; return; }
  sem.style.display = 'none';

  cont.innerHTML = lista.map(p => {
    const diasRestantes = p.prazo ? Math.ceil((new Date(p.prazo)-new Date())/(1000*60*60*24)) : null;
    const alerta = diasRestantes !== null && diasRestantes < 7 && p.status !== 'concluido' && p.status !== 'cancelado';
    return `
    <div class="card-proj" style="border-left:4px solid ${PRIO_COR[p.prioridade]||'#ccc'};cursor:pointer"
         onclick="editarProjeto('${p.id}')">
      <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
        <div>
          <span class="fw-bold" style="font-size:.95rem">${esc(p.nome)}</span>
          ${alerta ? `<span class="badge bg-danger ms-2" style="font-size:.65rem">⚠️ ${diasRestantes}d</span>` : ''}
        </div>
        <div class="d-flex gap-2 align-items-center">
          <span class="status-badge st-${p.status}">${STATUS_LABEL[p.status]||p.status}</span>
        </div>
      </div>
      ${p.descricao ? `<div style="font-size:.8rem;color:#6b7280;margin-top:.35rem">${esc(p.descricao).slice(0,120)}${p.descricao.length>120?'…':''}</div>` : ''}
      <div class="d-flex gap-3 mt-2 flex-wrap" style="font-size:.75rem;color:#9ca3af">
        ${p.responsavel ? `<span><i class="bi bi-person me-1"></i>${esc(p.responsavel)}</span>` : ''}
        ${p.inicio ? `<span><i class="bi bi-calendar-event me-1"></i>${p.inicio}</span>` : ''}
        ${p.prazo  ? `<span><i class="bi bi-calendar-check me-1"></i>Prazo: ${p.prazo}</span>` : ''}
      </div>
      <div class="d-flex align-items-center gap-2 mt-2">
        <div class="prog-bar" style="flex:1">
          <div class="prog-fill" style="width:${p.progresso||0}%;background:${PROG_COR(p.progresso||0)}"></div>
        </div>
        <span style="font-size:.75rem;font-weight:700;color:${PROG_COR(p.progresso||0)};min-width:32px">${p.progresso||0}%</span>
      </div>
    </div>`}).join('');
}

function filtrar() {
  const st = document.getElementById('f-status').value;
  const q  = document.getElementById('f-busca').value.toLowerCase();
  renderizar(projetos.filter(p =>
    (!st || p.status === st) &&
    (!q  || p.nome.toLowerCase().includes(q) || (p.descricao||'').toLowerCase().includes(q))
  ));
}

function abrirModal(id = null) {
  document.getElementById('proj-id').value = '';
  document.getElementById('proj-nome').value = '';
  document.getElementById('proj-descricao').value = '';
  document.getElementById('proj-status').value = 'planejamento';
  document.getElementById('proj-prioridade').value = 'media';
  document.getElementById('proj-inicio').value = '';
  document.getElementById('proj-prazo').value = '';
  document.getElementById('proj-responsavel').value = '';
  document.getElementById('proj-progresso').value = 0;
  document.getElementById('prog-val').textContent = '0';
  document.getElementById('btn-excluir').style.display = 'none';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-kanban-fill me-2"></i>Novo Projeto';
  modal.show();
}

function editarProjeto(id) {
  const p = projetos.find(x => x.id === id);
  if (!p) return;
  document.getElementById('proj-id').value = p.id;
  document.getElementById('proj-nome').value = p.nome;
  document.getElementById('proj-descricao').value = p.descricao || '';
  document.getElementById('proj-status').value = p.status;
  document.getElementById('proj-prioridade').value = p.prioridade;
  document.getElementById('proj-inicio').value = p.inicio || '';
  document.getElementById('proj-prazo').value = p.prazo || '';
  document.getElementById('proj-responsavel').value = p.responsavel || '';
  document.getElementById('proj-progresso').value = p.progresso || 0;
  document.getElementById('prog-val').textContent = p.progresso || 0;
  document.getElementById('btn-excluir').style.display = '';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>Editar Projeto';
  modal.show();
}

function salvarProjeto() {
  const nome = document.getElementById('proj-nome').value.trim();
  if (!nome) { alert('Informe o nome do projeto.'); return; }
  const id = document.getElementById('proj-id').value || ('proj_' + Date.now());
  const obj = {
    id, nome,
    descricao:    document.getElementById('proj-descricao').value.trim(),
    status:       document.getElementById('proj-status').value,
    prioridade:   document.getElementById('proj-prioridade').value,
    inicio:       document.getElementById('proj-inicio').value,
    prazo:        document.getElementById('proj-prazo').value,
    responsavel:  document.getElementById('proj-responsavel').value.trim(),
    progresso:    parseInt(document.getElementById('proj-progresso').value),
  };
  const idx = projetos.findIndex(x => x.id === id);
  if (idx >= 0) projetos[idx] = obj; else projetos.unshift(obj);
  salvar();
  modal.hide();
  renderizar(projetos);
}

function excluirProjeto() {
  const id = document.getElementById('proj-id').value;
  if (!confirm('Excluir este projeto?')) return;
  projetos = projetos.filter(x => x.id !== id);
  salvar();
  modal.hide();
  renderizar(projetos);
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
