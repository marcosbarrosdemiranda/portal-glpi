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
  <title>Contratos de TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #5e35b1; }
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

    /* ── Stats ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem; margin-bottom: 1.25rem;
    }
    .stat-card {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.1rem 1.25rem;
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
    .btn-novo:hover { background: #4527a0; }

    /* ── Cards de contratos ── */
    .contratos-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;
    }
    .card-contrato {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.1rem 1.25rem;
      border-left: 4px solid var(--mod); transition: transform .15s, box-shadow .15s; cursor: pointer;
    }
    .card-contrato:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }

    .ct-header { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; margin-bottom: .6rem; }
    .ct-nome   { font-weight: 700; font-size: .95rem; color: #1f2937; }
    .ct-fornecedor { font-size: .8rem; color: #6b7280; margin-bottom: .5rem; }

    .ct-meta {
      display: flex; flex-wrap: wrap; gap: .4rem .75rem;
      font-size: .75rem; color: #9ca3af; border-top: 1px solid #f3f4f6;
      padding-top: .55rem; margin-top: .5rem;
    }
    .ct-meta span { display: flex; align-items: center; gap: .25rem; }

    /* Badges tipo */
    .badge-tipo {
      font-size: .68rem; padding: .18rem .5rem; border-radius: 8px; font-weight: 600; white-space: nowrap;
    }
    .tipo-manutencao   { background: #e8f0fe; color: #1a73e8; }
    .tipo-isp          { background: #e0f7fa; color: #0097a7; }
    .tipo-software     { background: #e8f5e9; color: #2e7d32; }
    .tipo-hardware     { background: #fff3e0; color: #e65100; }
    .tipo-seguranca    { background: #fce4ec; color: #c62828; }
    .tipo-outro        { background: #f3f4f6; color: #4b5563; }

    /* Badges status/alerta */
    .badge-status {
      font-size: .68rem; padding: .22rem .6rem; border-radius: 8px; font-weight: 700; text-transform: uppercase; white-space: nowrap;
    }
    .st-ativo     { background: #e8f5e9; color: #1b5e20; }
    .st-vencido   { background: #ffebee; color: #b71c1c; }
    .st-cancelado { background: #f3f4f6; color: #616161; }
    .al-vencido   { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
    .al-30d       { background: #fff9c4; color: #f57f17; border: 1px solid #ffee58; }
    .al-60d       { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }

    .empty-msg {
      text-align: center; color: #9ca3af; padding: 3rem; grid-column: 1 / -1;
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
    }

    /* Botão ações inline */
    .btn-acao {
      background: none; border: none; cursor: pointer; font-size: .9rem; padding: .15rem .3rem; border-radius: 4px;
    }
    .btn-acao:hover { background: #f3f4f6; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-file-earmark-text-fill"></i> Contratos de TI</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-file-earmark-text-fill me-2"></i>Contratos de TI</h1>
  <p>Gestão de contratos ativos — manutenção, ISP, licenças — com alertas de vencimento</p>
</div>

<div class="wrap">

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card" style="border-left:4px solid var(--mod)">
      <div class="s-label"><i class="bi bi-file-earmark-text me-1"></i>Total Contratos</div>
      <div class="s-value" id="s-total">0</div>
      <div class="s-sub">cadastrados</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #2e7d32">
      <div class="s-label"><i class="bi bi-check-circle me-1"></i>Ativos</div>
      <div class="s-value" id="s-ativos" style="color:#2e7d32">0</div>
      <div class="s-sub">em vigor</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #fb8c00">
      <div class="s-label"><i class="bi bi-exclamation-triangle me-1"></i>Alertas</div>
      <div class="s-value" id="s-alertas" style="color:#fb8c00">0</div>
      <div class="s-sub">vencendo em 60 dias</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #1a73e8">
      <div class="s-label"><i class="bi bi-currency-dollar me-1"></i>Valor Mensal Total</div>
      <div class="s-value" id="s-mensal" style="color:#1a73e8">R$ 0,00</div>
      <div class="s-sub">soma dos contratos ativos</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="filtros-bar">
    <select id="f-tipo" class="form-select form-select-sm" style="width:160px" onchange="filtrar()">
      <option value="">Todos os tipos</option>
      <option value="Manutenção">Manutenção</option>
      <option value="ISP/Internet">ISP/Internet</option>
      <option value="Software">Software</option>
      <option value="Hardware">Hardware</option>
      <option value="Segurança">Segurança</option>
      <option value="Outro">Outro</option>
    </select>
    <select id="f-status" class="form-select form-select-sm" style="width:140px" onchange="filtrar()">
      <option value="">Todos os status</option>
      <option value="ativo">Ativo</option>
      <option value="vencido">Vencido</option>
      <option value="cancelado">Cancelado</option>
    </select>
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:200px"
           placeholder="🔍 Buscar contrato..." oninput="filtrar()"/>
    <div style="flex:1"></div>
    <button class="btn-novo" onclick="abrirModal()">
      <i class="bi bi-plus-lg me-1"></i>Novo Contrato
    </button>
  </div>

  <!-- Cards -->
  <div class="contratos-grid" id="contratos-grid">
    <div class="empty-msg"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhum contrato cadastrado. Clique em "Novo Contrato" para começar.</div>
  </div>

</div>

<!-- Modal criar/editar -->
<div class="modal fade" id="modalContrato" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--mod),#4527a0);color:white">
        <h5 class="modal-title fw-bold" id="modal-titulo">
          <i class="bi bi-file-earmark-text-fill me-2"></i>Novo Contrato
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ct-id"/>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label fw-semibold">Nome do Contrato <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ct-nome" placeholder="Ex: Contrato NOC — Empresa XYZ"/>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
            <select class="form-select" id="ct-tipo">
              <option value="Manutenção">Manutenção</option>
              <option value="ISP/Internet">ISP/Internet</option>
              <option value="Software">Software</option>
              <option value="Hardware">Hardware</option>
              <option value="Segurança">Segurança</option>
              <option value="Outro">Outro</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Fornecedor <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ct-fornecedor" placeholder="Nome da empresa fornecedora"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Valor Mensal (R$)</label>
            <input type="number" class="form-control" id="ct-valor" min="0" step="0.01" placeholder="0,00"/>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Data Início</label>
            <input type="date" class="form-control" id="ct-inicio"/>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Data Vencimento</label>
            <input type="date" class="form-control" id="ct-vencimento"/>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select class="form-select" id="ct-status">
              <option value="ativo">Ativo</option>
              <option value="vencido">Vencido</option>
              <option value="cancelado">Cancelado</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Observação</label>
            <textarea class="form-control" id="ct-obs" rows="2" placeholder="Notas, cláusulas especiais..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btn-excluir" style="display:none" onclick="excluirContrato()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" onclick="salvarContrato()"
                style="background:var(--mod);border-color:var(--mod);color:white">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const STORE_KEY = 'ti_contratos';
let modal;
let contratos = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalContrato'));
  atualizarStats();
  filtrar();
});

const TIPO_CLASS = {
  'Manutenção':  'tipo-manutencao',
  'ISP/Internet':'tipo-isp',
  'Software':    'tipo-software',
  'Hardware':    'tipo-hardware',
  'Segurança':   'tipo-seguranca',
  'Outro':       'tipo-outro',
};

function salvarLS() { localStorage.setItem(STORE_KEY, JSON.stringify(contratos)); }

function fmt(v) {
  return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function diasParaVencer(dataStr) {
  if (!dataStr) return null;
  const venc  = new Date(dataStr + 'T00:00:00');
  const hoje  = new Date(); hoje.setHours(0,0,0,0);
  return Math.ceil((venc - hoje) / (1000 * 60 * 60 * 24));
}

function badgeAlerta(ct) {
  if (ct.status === 'cancelado') return '<span class="badge-status st-cancelado">Cancelado</span>';
  const dias = diasParaVencer(ct.data_vencimento);
  if (ct.status === 'vencido' || (dias !== null && dias < 0)) {
    return '<span class="badge-status al-vencido"><i class="bi bi-x-circle me-1"></i>VENCIDO</span>';
  }
  if (dias !== null && dias <= 30) {
    return `<span class="badge-status al-30d"><i class="bi bi-exclamation-triangle me-1"></i>VENCE EM ${dias}D</span>`;
  }
  if (dias !== null && dias <= 60) {
    return `<span class="badge-status al-60d"><i class="bi bi-clock me-1"></i>VENCE EM ${dias}D</span>`;
  }
  return '<span class="badge-status st-ativo"><i class="bi bi-check-circle me-1"></i>Ativo</span>';
}

function atualizarStats() {
  const hoje = new Date(); hoje.setHours(0,0,0,0);
  const ativos   = contratos.filter(c => c.status === 'ativo' && diasParaVencer(c.data_vencimento) >= 0);
  const alertas  = contratos.filter(c => {
    const dias = diasParaVencer(c.data_vencimento);
    return c.status === 'ativo' && dias !== null && dias >= 0 && dias <= 60;
  });
  const mensal   = ativos.reduce((s, c) => s + Number(c.valor_mensal || 0), 0);
  document.getElementById('s-total').textContent  = contratos.length;
  document.getElementById('s-ativos').textContent = ativos.length;
  document.getElementById('s-alertas').textContent = alertas.length;
  document.getElementById('s-mensal').textContent = fmt(mensal);
}

function filtrar() {
  const tipo   = document.getElementById('f-tipo').value;
  const status = document.getElementById('f-status').value;
  const q      = document.getElementById('f-busca').value.toLowerCase();
  const lista  = contratos.filter(c =>
    (!tipo   || c.tipo === tipo) &&
    (!status || c.status === status) &&
    (!q      || (c.nome_contrato || '').toLowerCase().includes(q) || (c.fornecedor || '').toLowerCase().includes(q))
  );
  renderCards(lista);
}

function renderCards(lista) {
  const grid = document.getElementById('contratos-grid');
  if (!lista.length) {
    grid.innerHTML = '<div class="empty-msg"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhum contrato encontrado.</div>';
    return;
  }
  // Ordenar: alertas primeiro, depois por vencimento
  const ordenado = [...lista].sort((a, b) => {
    const da = diasParaVencer(a.data_vencimento) ?? 9999;
    const db = diasParaVencer(b.data_vencimento) ?? 9999;
    return da - db;
  });
  grid.innerHTML = ordenado.map(c => {
    const tClass = TIPO_CLASS[c.tipo] || 'tipo-outro';
    const dias   = diasParaVencer(c.data_vencimento);
    const corBrd = (c.status==='cancelado') ? '#9ca3af'
                 : (c.status==='vencido' || dias<0) ? '#e53935'
                 : (dias!==null && dias<=30) ? '#fb8c00'
                 : (dias!==null && dias<=60) ? '#f57c00'
                 : '#5e35b1';
    return `<div class="card-contrato" style="border-left-color:${corBrd}" onclick="editarContrato('${c.id}')">
      <div class="ct-header">
        <div class="ct-nome">${esc(c.nome_contrato)}</div>
        ${badgeAlerta(c)}
      </div>
      <div class="ct-fornecedor"><i class="bi bi-building me-1"></i>${esc(c.fornecedor || '—')}</div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge-tipo ${tClass}">${esc(c.tipo)}</span>
        <span style="font-size:.8rem;font-weight:700;color:#1a73e8">${fmt(c.valor_mensal)}/mês</span>
      </div>
      <div class="ct-meta">
        ${c.data_inicio     ? `<span><i class="bi bi-calendar-event"></i>${c.data_inicio}</span>` : ''}
        ${c.data_vencimento ? `<span><i class="bi bi-calendar-x"></i>Vence: ${c.data_vencimento}</span>` : ''}
        ${c.observacao ? `<span style="color:#6b7280">${esc(c.observacao).slice(0,60)}${c.observacao.length>60?'…':''}</span>` : ''}
      </div>
    </div>`;
  }).join('');
}

function abrirModal() {
  document.getElementById('ct-id').value         = '';
  document.getElementById('ct-nome').value       = '';
  document.getElementById('ct-fornecedor').value = '';
  document.getElementById('ct-tipo').value       = 'Manutenção';
  document.getElementById('ct-valor').value      = '';
  document.getElementById('ct-inicio').value     = '';
  document.getElementById('ct-vencimento').value = '';
  document.getElementById('ct-status').value     = 'ativo';
  document.getElementById('ct-obs').value        = '';
  document.getElementById('btn-excluir').style.display = 'none';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-file-earmark-text-fill me-2"></i>Novo Contrato';
  modal.show();
}

function editarContrato(id) {
  const c = contratos.find(x => x.id === id);
  if (!c) return;
  document.getElementById('ct-id').value         = c.id;
  document.getElementById('ct-nome').value       = c.nome_contrato;
  document.getElementById('ct-fornecedor').value = c.fornecedor || '';
  document.getElementById('ct-tipo').value       = c.tipo;
  document.getElementById('ct-valor').value      = c.valor_mensal || '';
  document.getElementById('ct-inicio').value     = c.data_inicio || '';
  document.getElementById('ct-vencimento').value = c.data_vencimento || '';
  document.getElementById('ct-status').value     = c.status;
  document.getElementById('ct-obs').value        = c.observacao || '';
  document.getElementById('btn-excluir').style.display = '';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>Editar Contrato';
  modal.show();
}

function salvarContrato() {
  const nome = document.getElementById('ct-nome').value.trim();
  const forn = document.getElementById('ct-fornecedor').value.trim();
  if (!nome) { alert('Informe o nome do contrato.'); return; }
  if (!forn) { alert('Informe o fornecedor.'); return; }
  const id  = document.getElementById('ct-id').value || ('ct_' + Date.now());
  const obj = {
    id,
    nome_contrato:   nome,
    fornecedor:      forn,
    tipo:            document.getElementById('ct-tipo').value,
    valor_mensal:    parseFloat(document.getElementById('ct-valor').value) || 0,
    data_inicio:     document.getElementById('ct-inicio').value,
    data_vencimento: document.getElementById('ct-vencimento').value,
    status:          document.getElementById('ct-status').value,
    observacao:      document.getElementById('ct-obs').value.trim(),
  };
  const idx = contratos.findIndex(x => x.id === id);
  if (idx >= 0) contratos[idx] = obj; else contratos.unshift(obj);
  salvarLS();
  modal.hide();
  atualizarStats();
  filtrar();
}

function excluirContrato() {
  const id = document.getElementById('ct-id').value;
  if (!confirm('Excluir este contrato?')) return;
  contratos = contratos.filter(x => x.id !== id);
  salvarLS();
  modal.hide();
  atualizarStats();
  filtrar();
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Contratos</footer>
</body>
</html>
