<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/agenda/orcamento_db.php'; // Inclui a definição da tabela

if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$_cards_orc  = $_SESSION['portal_perfil_cards'] ?? null;
$orc_ouvinte = ($_cards_orc !== null) && (($_cards_orc['orcamento'] ?? 'ouvinte') === 'ouvinte');

// ── CRUD básico via POST (se houver ação) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$orc_ouvinte) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'save') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $sql = $id
                ? "UPDATE glpi_portal_orcamento SET categoria=?, descricao=?, mes_ano=?, qty_prevista=?, unit_previsto=?, qty_realizada=?, unit_realizado=?, observacao=? WHERE id=?"
                : "INSERT INTO glpi_portal_orcamento (categoria, descricao, mes_ano, qty_prevista, unit_previsto, qty_realizada, unit_realizado, observacao) VALUES (?,?,?,?,?,?,?,?)";
            $params = [$_POST['categoria'], $_POST['descricao'], $_POST['mes_ano'], (int)$_POST['qty_prevista'], (float)$_POST['unit_previsto'], (int)$_POST['qty_realizada'], (float)$_POST['unit_realizado'], $_POST['observacao']];
            if ($id) $params[] = $id;
            $pdo->prepare($sql)->execute($params);
        } elseif ($_POST['action'] === 'delete') {
            $pdo->prepare("DELETE FROM glpi_portal_orcamento WHERE id=?")->execute([(int)$_POST['id']]);
        }
        header("Location: orcamento.php"); exit;
    }
}

// ── Fetch dos dados ───────────────────────────────────────────
$stmt = $pdo->query("SELECT *, (qty_prevista * unit_previsto) as total_previsto, (qty_realizada * unit_realizado) as total_realizado FROM glpi_portal_orcamento ORDER BY mes_ano DESC, id DESC");
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <select id="f-cat" class="form-select form-select-sm" style="width:175px">
      <option value="">Todas as categorias</option>
      <option value="Hardware">Hardware</option>
      <option value="Software">Software</option>
      <option value="Serviços">Serviços</option>
      <option value="Infraestrutura">Infraestrutura</option>
      <option value="Treinamento">Treinamento</option>
      <option value="Outros">Outros</option>
    </select>
    <!-- Filtros de Mês/Ano -->
    <select id="f-mes" class="form-select form-select-sm" style="width:155px">
      <option value="">Todos os Meses</option>
      <?php
      $meses = [
          '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
          '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
      ];
      foreach($meses as $num => $nome): ?>
          <option value="<?= $num ?>"><?= $nome ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" id="f-ano" class="form-control form-control-sm" style="width:100px" placeholder="Ano" min="2000" max="2100" />
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:200px"
           placeholder="🔍 Buscar descrição..." />
    <button class="btn btn-sm btn-primary" onclick="filtrar()"><i class="bi bi-search"></i> Filtrar</button>
    <div style="flex:1"></div>
    <button class="btn btn-sm btn-outline-danger" onclick="exportarPDF('mes')"><i class="bi bi-file-earmark-pdf"></i> PDF Mês</button>
    <button class="btn btn-sm btn-outline-danger" onclick="exportarPDF('ano')"><i class="bi bi-file-earmark-pdf"></i> PDF Ano</button>
    <?php if (!$orc_ouvinte): ?>
    <button class="btn-novo" onclick="abrirModal()">
      <i class="bi bi-plus-lg me-1"></i>Novo Item
    </button>
    <?php endif; ?>
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
            <th style="text-align:center">Qtd x Unit (Prev)</th>
            <th>Total Prev</th>
            <th style="text-align:center">Qtd x Unit (Real)</th>
            <th>Total Real</th>
            <th>Saldo</th>
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
        <form id="form-item" method="POST">
          <input type="hidden" name="action" value="save"/>
          <input type="hidden" name="id" id="item-id"/>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Categoria <span class="text-danger">*</span></label>
              <select class="form-select" name="categoria" id="item-cat">
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
              <input type="month" class="form-control" name="mes_ano" id="item-mes"/>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Descrição <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="descricao" id="item-desc" placeholder="Ex: Compra de nobreaks para loja Centro"/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Qtd Prevista</label>
              <input type="number" class="form-control" name="qty_prevista" id="qty_prevista" min="0" value="1" oninput="calcTotal('prev')"/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Vl Unit Prev (R$)</label>
              <input type="number" class="form-control" name="unit_previsto" id="unit_previsto" min="0" step="0.01" value="0.00" oninput="calcTotal('prev')"/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Total Previsto (R$)</label>
              <input type="number" class="form-control" id="total_previsto" disabled/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Qtd Realizada</label>
              <input type="number" class="form-control" name="qty_realizada" id="qty_realizada" min="0" value="0" oninput="calcTotal('real')"/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Vl Unit Real (R$)</label>
              <input type="number" class="form-control" name="unit_realizado" id="unit_realizado" min="0" step="0.01" value="0.00" oninput="calcTotal('real')"/>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Total Realizado (R$)</label>
              <input type="number" class="form-control" id="total_realizado" disabled/>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Observação</label>
              <textarea class="form-control" name="observacao" id="item-obs" rows="2" placeholder="Notas adicionais..."></textarea>
            </div>
          </div>
        </form>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<script>
const MODO_OUVINTE_ORC = <?= $orc_ouvinte ? 'true' : 'false' ?>;
let modal;
let modalConcretizar;
let itens = <?= json_encode($itens) ?>;

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalItem'));
  modalConcretizar = new bootstrap.Modal(document.getElementById('modalConcretizar'));
  const anoAtual = new Date().getFullYear();
  document.getElementById('f-mes').value = '';
  document.getElementById('f-ano').value = anoAtual;
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

function fmt(v) {
  return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function atualizarStats(listaItems = itens) {
  const plan  = listaItems.reduce((s, i) => s + Number(i.total_previsto || 0), 0);
  const real  = listaItems.reduce((s, i) => s + Number(i.total_realizado || 0), 0);
  const saldo = plan - real;
  const pct   = plan > 0 ? Math.min(Math.round(real / plan * 100), 100) : 0;
  const corPct = pct >= 90 ? '#e53935' : pct >= 70 ? '#fb8c00' : '#43a047';

  document.getElementById('s-planejado').textContent     = fmt(plan);
  document.getElementById('s-planejado-sub').textContent = listaItems.length + ' ite' + (listaItems.length === 1 ? 'm' : 'ns');
  document.getElementById('s-realizado').textContent     = fmt(real);
  document.getElementById('s-realizado-sub').textContent = listaItems.filter(i => Number(i.total_realizado) > 0).length + ' com valor';
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
  const ano  = document.getElementById('f-ano').value;
  const q    = document.getElementById('f-busca').value.toLowerCase();
  console.log('Filtrando:', {cat, mes, ano, q});

  const lista = itens.filter(i => {
    if (cat && i.categoria !== cat) return false;
    if (mes && i.mes_ano && i.mes_ano.substring(5, 7) !== mes) return false;
    // Forçar comparação estrita para o ano
    if (ano && i.mes_ano && i.mes_ano.substring(0, 4) !== String(ano)) return false;
    if (q && !(i.descricao || '').toLowerCase().includes(q)) return false;
    return true;
  });
  atualizarStats(lista);
  renderTabela(lista);
}

function renderTabela(lista) {
  const tbody = document.getElementById('tbl-body');
  document.getElementById('tbl-count').textContent = lista.length + ' ite' + (lista.length === 1 ? 'm' : 'ns');
  if (!lista.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="9"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhum item encontrado.</td></tr>';
    return;
  }
  tbody.innerHTML = lista.map(i => {
    const plan  = Number(i.total_previsto || 0);
    const real  = Number(i.total_realizado || 0);
    const saldo = plan - real;
    const cls   = CAT_CLASS[i.categoria] || 'cat-outros';
    return `<tr>
      <td><span class="badge-cat ${cls}">${esc(i.categoria)}</span></td>
      <td style="max-width:220px">${esc(i.descricao)}</td>
      <td>${i.mes_ano || '—'}</td>
      <td style="font-size:.8rem;color:#6b7280;text-align:center">${i.qty_prevista || 0} x ${fmt(i.unit_previsto || 0)}</td>
      <td style="font-weight:600;color:#1a73e8">${fmt(plan)}</td>
      <td style="font-size:.8rem;color:#6b7280;text-align:center">${i.qty_realizada || 0} x ${fmt(i.unit_realizado || 0)}</td>
      <td style="font-weight:600;color:#e53935">${fmt(real)}</td>
      <td class="${saldo >= 0 ? 'saldo-pos' : 'saldo-neg'}">${saldo >= 0 ? '' : '-'}${fmt(Math.abs(saldo))}</td>
      <td style="text-align:center;white-space:nowrap">
        ${MODO_OUVINTE_ORC ? '' : `
        <button class="btn-acao text-primary" title="Editar" onclick="editarItem('${i.id}')"><i class="bi bi-pencil-fill"></i></button>
        <button class="btn-acao text-danger"  title="Excluir" onclick="excluirDireto('${i.id}')"><i class="bi bi-trash-fill"></i></button>
        <button class="btn-acao text-success" title="Concretizar Despesa" onclick="abrirModalConcretizar('${i.id}')"><i class="bi bi-check-circle-fill"></i></button>
        `}
      </td>
    </tr>`;
  }).join('');
}

function abrirModal() {
  document.getElementById('item-id').value   = '';
  document.getElementById('item-cat').value  = 'Hardware';
  document.getElementById('item-desc').value = '';
  document.getElementById('qty_prevista').value = '1';
  document.getElementById('unit_previsto').value = '0.00';
  document.getElementById('qty_realizada').value = '0';
  document.getElementById('unit_realizado').value = '0.00';
  document.getElementById('item-obs').value  = '';
  document.getElementById('item-mes').value  = new Date().toISOString().slice(0, 7);
  calcTotal('prev');
  calcTotal('real');
  document.getElementById('btn-excluir').style.display = 'none';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-cash-coin me-2"></i>Novo Item de Orçamento';
  modal.show();
}

function editarItem(id) {
  const i = itens.find(x => String(x.id) === String(id));
  if (!i) return;
  document.getElementById('item-id').value   = i.id;
  document.getElementById('item-cat').value  = i.categoria;
  document.getElementById('item-desc').value = i.descricao;
  document.getElementById('qty_prevista').value = i.qty_prevista || '1';
  document.getElementById('unit_previsto').value = i.unit_previsto || '0.00';
  document.getElementById('qty_realizada').value = i.qty_realizada || '0';
  document.getElementById('unit_realizado').value = i.unit_realizado || '0.00';
  document.getElementById('item-obs').value  = i.observacao || '';
  document.getElementById('item-mes').value  = i.mes_ano || '';
  calcTotal('prev');
  calcTotal('real');
  document.getElementById('btn-excluir').style.display = '';
  document.getElementById('modal-titulo').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>Editar Item';
  modal.show();
}

function salvarItem() {
  const desc = document.getElementById('item-desc').value.trim();
  if (!desc) { alert('Informe a descrição do item.'); return; }
  document.getElementById('form-item').submit();
}

function excluirItem() {
  if (!confirm('Excluir este item de orçamento?')) return;
  const id = document.getElementById('item-id').value;
  document.getElementById('del-id').value = id;
  document.getElementById('form-delete').submit();
}

function excluirDireto(id) {
  if (!confirm('Excluir este item de orçamento?')) return;
  document.getElementById('del-id').value = id;
  document.getElementById('form-delete').submit();
}

function abrirModalConcretizar(id) {
  const i = itens.find(x => String(x.id) === String(id));
  if (!i) return;
  document.getElementById('conc-orc-id').value = i.id;
  document.getElementById('conc-desc').value   = i.descricao;
  document.getElementById('conc-valor').value  = i.total_realizado || 0;
  modalConcretizar.show();
}

function calcTotal(tipo) {
    if (tipo === 'prev') {
        const qty = parseFloat(document.getElementById('qty_prevista').value) || 0;
        const unit = parseFloat(document.getElementById('unit_previsto').value) || 0;
        document.getElementById('total_previsto').value = (qty * unit).toFixed(2);
    } else {
        const qty = parseFloat(document.getElementById('qty_realizada').value) || 0;
        const unit = parseFloat(document.getElementById('unit_realizado').value) || 0;
        document.getElementById('total_realizado').value = (qty * unit).toFixed(2);
    }
}

function exportarPDF(tipo) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const titulo = 'Relatório de Orçamento - ' + (tipo === 'mes' ? document.getElementById('f-mes').value : document.getElementById('f-ano').value);

    doc.text(titulo, 14, 15);

    const columns = ['Categoria', 'Descricao', 'Mês', 'Qtd Prev','Vl Unit Prev','Total Prev','Qtd Real','Vl Unit Real','Total Real'];
    const rows = itens
        .filter(i => (tipo === 'mes' ? (i.mes_ano === document.getElementById('f-mes').value) : (i.mes_ano.startsWith(document.getElementById('f-ano').value))))
        .map(i => [
            i.categoria,
            i.descricao,
            i.mes_ano,
            i.qty_prevista || 0,
            fmt(i.unit_previsto || 0),
            fmt(i.total_previsto || 0),
            i.qty_realizada || 0,
            fmt(i.unit_realizado || 0),
            fmt(i.total_realizado || 0)
        ]);

    doc.autoTable({ head: [columns], body: rows, startY: 20 });
    doc.save('orcamento_' + tipo + '.pdf');
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>


<!-- Modal Concretizar -->
<div class="modal fade" id="modalConcretizar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title fw-bold"><i class="bi bi-check-circle-fill me-2"></i>Concretizar Despesa</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-concretizar" method="POST" action="despesas.php">
          <input type="hidden" name="action" value="save"/>
          <input type="hidden" name="orcamento_id" id="conc-orc-id"/>
          <input type="hidden" name="descricao" id="conc-desc"/>
          <input type="hidden" name="valor_pago" id="conc-valor"/>

          <div class="mb-3">
              <label class="form-label">Fornecedor <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="fornecedor" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Data Pagamento <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="data_pagamento" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Nota Fiscal</label>
              <input type="text" class="form-control" name="numero_nf">
          </div>
          <div class="mb-3">
              <label class="form-label">Método Pagamento</label>
              <select class="form-select" name="metodo">
                  <option value="Boleto">Boleto</option>
                  <option value="Pix">Pix</option>
                  <option value="Cartão">Cartão</option>
                  <option value="Transferência">Transferência</option>
              </select>
          </div>
          <div class="mb-3">
              <label class="form-label">Observação</label>
              <textarea class="form-control" name="observacao" rows="2"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success fw-bold" onclick="document.getElementById('form-concretizar').submit()">
            <i class="bi bi-check-lg me-1"></i>Confirmar Despesa
        </button>
      </div>
    </div>
  </div>
</div>

<form id="form-delete" method="POST" style="display:none">
  <input type="hidden" name="action" value="delete"/>
  <input type="hidden" name="id" id="del-id"/>
</form>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Orçamento</footer>
</body>
</html>
