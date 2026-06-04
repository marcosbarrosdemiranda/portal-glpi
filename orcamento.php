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
  <title>Orçamento de TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #43a047; }
    body  { background: #f0f4f9; font-family: 'Segoe UI', sans-serif; margin: 0; }

    .topbar {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: .75rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: .5rem; }
    .topbar a {
      color: white; text-decoration: none; font-size: .82rem;
      background: rgba(255,255,255,.15); border-radius: 6px; padding: .3rem .75rem;
    }
    .topbar a:hover { background: rgba(255,255,255,.25); }

    .hero {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: 2rem 1rem 4.5rem; text-align: center;
    }
    .hero h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
    .hero p  { opacity: .8; margin-top: .5rem; font-size: .95rem; }

    .wrap { max-width: 1100px; margin: -3rem auto 3rem; padding: 0 1rem; }

    /* ── Stat Cards ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem; margin-bottom: 1.25rem;
    }
    .stat-card {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.1rem 1.25rem;
      border-left: 4px solid var(--mod);
    }
    .stat-card .s-label { font-size: .72rem; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .stat-card .s-value { font-size: 1.55rem; font-weight: 700; margin-top: .2rem; color: #1f2937; }
    .stat-card .s-sub   { font-size: .78rem; color: #6b7280; margin-top: .15rem; }

    /* ── Filtros / ações ── */
    .filtros-bar {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1rem 1.25rem;
      display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem;
    }
    .btn-novo {
      background: var(--mod); border: none; color: white; border-radius: 8px;
      padding: .45rem 1.25rem; font-size: .85rem; font-weight: 600; cursor: pointer;
    }
    .btn-novo:hover { background: #388e3c; }

    /* ── Tabela ── */
    .tbl-wrap {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden;
    }
    .tbl-header {
      background: var(--mod); color: white; padding: .75rem 1.25rem;
      display: flex; align-items: center; justify-content: space-between;
    }
    .tbl-header .title { font-weight: 700; font-size: .95rem; display: flex; align-items: center; gap: .5rem; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    thead th {
      background: #f9fafb; padding: .65rem 1rem; text-align: left;
      font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb;
      font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
    }
    tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
    tbody tr:hover { background: #f0fdf4; }
    tbody td { padding: .65rem 1rem; vertical-align: middle; }
    .empty-row td { text-align: center; color: #9ca3af; padding: 2.5rem; font-size: .9rem; }

    /* Badges categoria */
    .badge-cat {
      font-size: .7rem; padding: .2rem .55rem; border-radius: 8px; font-weight: 600; white-space: nowrap;
    }
    .cat-hardware      { background: #e8f0fe; color: #1a73e8; }
    .cat-software      { background: #e8f5e9; color: #2e7d32; }
    .cat-servicos      { background: #fff3e0; color: #e65100; }
    .cat-infraestrutura{ background: #e0f2f1; color: #00695c; }
    .cat-treinamento   { background: #f3e5f5; color: #6a1b9a; }
    .cat-outros        { background: #f3f4f6; color: #4b5563; }

    /* Saldo */
    .saldo-pos { color: #2e7d32; font-weight: 700; }
    .saldo-neg { color: #c62828; font-weight: 700; }

    /* Barra % utilizado */
    .util-bar { height: 6px; background: #e5e7eb; border-radius: 3px; min-width: 60px; overflow: hidden; }
    .util-fill { height: 100%; border-radius: 3px; transition: width .4s; }

    /* Ações */
    .btn-acao {
      background: none; border: none; cursor: pointer; font-size: .95rem; padding: .15rem .3rem; border-radius: 4px;
    }
    .btn-acao:hover { background: #f3f4f6; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-cash-coin"></i> Orçamento de TI</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-cash-coin me-2"></i>Orçamento de TI</h1>
  <p>Controle de gastos por categoria — planejado vs realizado por período</p>
</div>

<div class="wrap">

  <!-- Stats -->
  <div class="stats-grid" id="stats-grid">
    <div class="stat-card" style="border-left-color:#1a73e8">
      <div class="s-label"><i class="bi bi-calendar3 me-1"></i>Total Planejado</div>
      <div class="s-value" id="s-planejado">R$ 0,00</div>
      <div class="s-sub" id="s-planejado-sub">0 itens</div>
    </div>
    <div class="stat-card" style="border-left-color:#e53935">
      <div class="s-label"><i class="bi bi-receipt me-1"></i>Total Realizado</div>
      <div class="s-value" id="s-realizado">R$ 0,00</div>
      <div class="s-sub" id="s-realizado-sub">0 itens</div>
    </div>
    <div class="stat-card" style="border-left-color:#43a047">
      <div class="s-label"><i class="bi bi-wallet2 me-1"></i>Saldo</div>
      <div class="s-value" id="s-saldo">R$ 0,00</div>
      <div class="s-sub" id="s-saldo-sub">Planejado - Realizado</div>
    </div>
    <div class="stat-card" style="border-left-color:#fb8c00">
      <div class="s-label"><i class="bi bi-percent me-1"></i>% Utilizado</div>
      <div class="s-value" id="s-pct">0%</div>
      <div class="s-sub">
        <div class="util-bar" style="margin-top:.3rem">
          <div class="util-fill" id="s-pct-bar" style="width:0%;background:#43a047"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="filtros-bar">
    <select id="f-cat" class="form-select form-select-sm" style="width:175px" onchange="filtrar()">
      <option value="">Todas as categorias</option>
      <option value="Hardware">Hardware</option>
      <option value="Software">Software</option>
      <option value="Serviços">Serviços</option>
      <option value="Infraestrutura">Infraestrutura</option>
      <option value="Treinamento">Treinamento</option>
      <option value="Outros">Outros</option>
    </select>
    <input type="month" id="f-mes" class="form-control form-control-sm" style="width:155px" onchange="filtrar()"/>
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:200px"
           placeholder="🔍 Buscar descrição..." oninput="filtrar()"/>
    <div style="flex:1"></div>
    <button class="btn-novo" onclick="abrirModal()">
      <i class="bi bi-plus-lg me-1"></i>Novo Item
    </button>
  </div>

  <!-- Tabela -->
  <div class="tbl-wrap">
    <div class="tbl-header">
      <div class="title"><i class="bi bi-table"></i> Itens de Orçamento</div>
      <span id="tbl-count" style="font-size:.78rem;opacity:.85">0 itens</span>
    </div>
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>Categoria</th>
            <th>Descrição</th>
            <th>Mês/Ano</th>
            <th>Planejado</th>
            <th>Realizado</th>
            <th>Saldo</th>
            <th>Observação</th>
            <th style="text-align:center">Ações</th>
          </tr>
        </thead>
        <tbody id="tbl-body">
          <tr class="empty-row"><td colspan="8"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhum item cadastrado. Clique em "Novo Item" para começar.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal criar/editar -->
<div class="modal fade" id="modalItem" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--mod),#2e7d32);color:white">
        <h5 class="modal-title fw-bold" id="modal-titulo">
          <i class="bi bi-cash-coin me-2"></i>Novo Item de Orçamento
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="item-id"/>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Categoria <span class="text-danger">*</span></label>
            <select class="form-select" id="item-cat">
              <option value="Hardware">Hardware</option>
              <option value="Software">Software</option>
              <option value="Serviços">Serviços</option>
              <option value="Infraestrutura">Infraestrutura</option>
              <option value="Treinamento">Treinamento</option>
              <option value="Outros">Outros</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Mês/Ano <span class="text-danger">*</span></label>
            <input type="month" class="form-control" id="item-mes"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Descrição <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="item-desc" placeholder="Ex: Compra de nobreaks para loja Centro"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Valor Planejado (R$)</label>
            <input type="number" class="form-control" id="item-plan" min="0" step="0.01" placeholder="0,00"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Valor Realizado (R$)</label>
            <input type="number" class="form-control" id="item-real" min="0" step="0.01" placeholder="0,00"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Observação</label>
            <textarea class="form-control" id="item-obs" rows="2" placeholder="Notas adicionais..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btn-excluir" style="display:none" onclick="excluirItem()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" onclick="salvarItem()"
                style="background:var(--mod);border-color:var(--mod);color:white">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const STORE_KEY = 'ti_orcamento';
let modal;
let itens = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalItem'));
  // Setar mês atual como padrão do filtro
  const hoje = new Date();
  document.getElementById('f-mes').value = hoje.toISOString().slice(0, 7);
  atualizarStats();
  filtrar();
});

const CAT_CLASS = {
  'Hardware':       'cat-hardware',
  'Software':       'cat-software',
  'Serviços':       'cat-servicos',
  'Infraestrutura': 'cat-infraestrutura',
  'Treinamento':    'cat-treinamento',
  'Outros':         'cat-outros',
};

function salvarLS() { localStorage.setItem(STORE_KEY, JSON.stringify(itens)); }

function fmt(v) {
  return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function atualizarStats() {
  const plan  = itens.reduce((s, i) => s + Number(i.valor_planejado || 0), 0);
  const real  = itens.reduce((s, i) => s + Number(i.valor_realizado || 0), 0);
  const saldo = plan - real;
  const pct   = plan > 0 ? Math.min(Math.round(real / plan * 100), 100) : 0;
  const corPct = pct >= 90 ? '#e53935' : pct >= 70 ? '#fb8c00' : '#43a047';

  document.getElementById('s-planejado').textContent     = fmt(plan);
  document.getElementById('s-planejado-sub').textContent = itens.length + ' ite' + (itens.length === 1 ? 'm' : 'ns');
  document.getElementById('s-realizado').textContent     = fmt(real);
  document.getElementById('s-realizado-sub').textContent = itens.filter(i => Number(i.valor_realizado) > 0).length + ' com valor';
  document.getElementById('s-saldo').textContent         = fmt(Math.abs(saldo));
  document.getElementById('s-saldo').className           = 's-value ' + (saldo >= 0 ? 'saldo-pos' : 'saldo-neg');
  document.getElementById('s-saldo-sub').textContent     = saldo >= 0 ? 'Dentro do orçamento' : 'Acima do orçamento';
  document.getElementById('s-pct').textContent           = pct + '%';
  document.getElementById('s-pct').style.color           = corPct;
  document.getElementById('s-pct-bar').style.width       = pct + '%';
  document.getElementById('s-pct-bar').style.background  = corPct;
}

function filtrar() {
  const cat  = document.getElementById('f-cat').value;
  const mes  = document.getElementById('f-mes').value;
  const q    = document.getElementById('f-busca').value.toLowerCase();
  const lista = itens.filter(i =>
    (!cat || i.categoria === cat) &&
    (!mes || i.mes_ano === mes) &&
    (!q   || (i.descricao || '').toLowerCase().includes(q))
  );
  renderTabela(lista);
}

function renderTabela(lista) {
  const tbody = document.getElementById('tbl-body');
  document.getElementById('tbl-count').textContent = lista.length + ' ite' + (lista.length === 1 ? 'm' : 'ns');
  if (!lista.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="8"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhum item encontrado.</td></tr>';
    return;
  }
  tbody.innerHTML = lista.map(i => {
    const plan  = Number(i.valor_planejado || 0);
    const real  = Number(i.valor_realizado || 0);
    const saldo = plan - real;
    const cls   = CAT_CLASS[i.categoria] || 'cat-outros';
    return `<tr>
      <td><span class="badge-cat ${cls}">${esc(i.categoria)}</span></td>
      <td style="max-width:220px">${esc(i.descricao)}</td>
      <td>${i.mes_ano || '—'}</td>
      <td style="font-weight:600;color:#1a73e8">${fmt(plan)}</td>
      <td style="font-weight:600;color:#e53935">${fmt(real)}</td>
      <td class="${saldo >= 0 ? 'saldo-pos' : 'saldo-neg'}">${saldo >= 0 ? '' : '-'}${fmt(Math.abs(saldo))}</td>
      <td style="max-width:160px;font-size:.78rem;color:#6b7280">${esc(i.observacao || '—')}</td>
      <td style="text-align:center;white-space:nowrap">
        <button class="btn-acao text-primary" title="Editar" onclick="editarItem('${i.id}')"><i class="bi bi-pencil-fill"></i></button>
        <button class="btn-acao text-danger"  title="Excluir" onclick="excluirDireto('${i.id}')"><i class="bi bi-trash-fill"></i></button>
      </td>
    </tr>`;
  }).join('');
}

function abrirModal() {
  document.getElementById('item-id').value   = '';
  document.getElementById('item-cat').value  = 'Hardware';
  document.getElementById('item-desc').value = '';
  document.getElementById('item-plan').value = '';
  document.getElementById('item-real').value = '';
  document.getElementById('item-obs').value  = '';
  document.getElementById('item-mes').value  = new Date().toISOString().slice(0, 7);
  document.getElementById('btn-excluir').style.display = 'none';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-cash-coin me-2"></i>Novo Item de Orçamento';
  modal.show();
}

function editarItem(id) {
  const i = itens.find(x => x.id === id);
  if (!i) return;
  document.getElementById('item-id').value   = i.id;
  document.getElementById('item-cat').value  = i.categoria;
  document.getElementById('item-desc').value = i.descricao;
  document.getElementById('item-plan').value = i.valor_planejado || '';
  document.getElementById('item-real').value = i.valor_realizado || '';
  document.getElementById('item-obs').value  = i.observacao || '';
  document.getElementById('item-mes').value  = i.mes_ano || '';
  document.getElementById('btn-excluir').style.display = '';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>Editar Item';
  modal.show();
}

function salvarItem() {
  const desc = document.getElementById('item-desc').value.trim();
  if (!desc) { alert('Informe a descrição do item.'); return; }
  const id  = document.getElementById('item-id').value || ('orc_' + Date.now());
  const obj = {
    id,
    categoria:       document.getElementById('item-cat').value,
    descricao:       desc,
    valor_planejado: parseFloat(document.getElementById('item-plan').value) || 0,
    valor_realizado: parseFloat(document.getElementById('item-real').value) || 0,
    mes_ano:         document.getElementById('item-mes').value,
    observacao:      document.getElementById('item-obs').value.trim(),
  };
  const idx = itens.findIndex(x => x.id === id);
  if (idx >= 0) itens[idx] = obj; else itens.unshift(obj);
  salvarLS();
  modal.hide();
  atualizarStats();
  filtrar();
}

function excluirItem() {
  const id = document.getElementById('item-id').value;
  if (!confirm('Excluir este item de orçamento?')) return;
  itens = itens.filter(x => x.id !== id);
  salvarLS();
  modal.hide();
  atualizarStats();
  filtrar();
}

function excluirDireto(id) {
  if (!confirm('Excluir este item de orçamento?')) return;
  itens = itens.filter(x => x.id !== id);
  salvarLS();
  atualizarStats();
  filtrar();
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Orçamento</footer>
</body>
</html>
