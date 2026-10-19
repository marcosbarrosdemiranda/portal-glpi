<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/agenda/orcamento_db.php'; // Inclui a definição da tabela
require_once __DIR__ . '/agenda/projetos_db.php';  // Catálogo de Projetos (agrupamento acima da categoria)

if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$_cards_orc  = $_SESSION['portal_perfil_cards'] ?? null;
$orc_ouvinte = ($_cards_orc !== null) && (($_cards_orc['orcamento'] ?? 'ouvinte') === 'ouvinte');

// ── CRUD básico via POST (se houver ação) ──────────────────────
// Responde em JSON (não redireciona) — o front atualiza só o item mudado
// no array `itens` e re-renderiza via filtrar(), sem reload de página.
// Isso evita perder filtro/agrupamento/colapso, que só existem em memória
// no JS e nunca persistiram a um F5 nem ao redirect antigo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$orc_ouvinte) {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');

        if ($_POST['action'] === 'save') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $sql = $id
                ? "UPDATE glpi_portal_orcamento SET categoria=?, descricao=?, mes_ano=?, qty_prevista=?, unit_previsto=?, qty_realizada=?, unit_realizado=?, observacao=?, loja=?, projeto_id=? WHERE id=?"
                : "INSERT INTO glpi_portal_orcamento (categoria, descricao, mes_ano, qty_prevista, unit_previsto, qty_realizada, unit_realizado, observacao, loja, projeto_id) VALUES (?,?,?,?,?,?,?,?,?,?)";
            $projetoId = !empty($_POST['projeto_id']) ? (int)$_POST['projeto_id'] : null;
            $params = [$_POST['categoria'], $_POST['descricao'], $_POST['mes_ano'], (int)$_POST['qty_prevista'], (float)$_POST['unit_previsto'], (int)$_POST['qty_realizada'], (float)$_POST['unit_realizado'], $_POST['observacao'], $_POST['loja'], $projetoId];
            if ($id) $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            $id = $id ?: (int)$pdo->lastInsertId();

            // Devolve o item já com projeto_nome e totais calculados — a
            // mesma forma do SELECT principal — pro front trocar só essa
            // linha no array local.
            $st = $pdo->prepare("
                SELECT o.*, p.nome AS projeto_nome,
                       (o.qty_prevista * o.unit_previsto) as total_previsto,
                       (o.qty_realizada * o.unit_realizado) as total_realizado
                FROM glpi_portal_orcamento o
                LEFT JOIN glpi_portal_projetos p ON p.id = o.projeto_id
                WHERE o.id = ?
            ");
            $st->execute([$id]);
            echo json_encode(['ok' => true, 'item' => $st->fetch(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM glpi_portal_orcamento WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        if ($_POST['action'] === 'toggle_ativo') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE glpi_portal_orcamento SET ativo = 1 - ativo WHERE id=?")->execute([$id]);
            $st = $pdo->prepare("SELECT ativo FROM glpi_portal_orcamento WHERE id=?");
            $st->execute([$id]);
            echo json_encode(['ok' => true, 'id' => $id, 'ativo' => (int)$st->fetchColumn()]);
            exit;
        }

        echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
        exit;
    }
}

// ── Fetch dos dados ───────────────────────────────────────────
$stmt = $pdo->query("
    SELECT o.*, p.nome AS projeto_nome,
           (o.qty_prevista * o.unit_previsto) as total_previsto,
           (o.qty_realizada * o.unit_realizado) as total_realizado
    FROM glpi_portal_orcamento o
    LEFT JOIN glpi_portal_projetos p ON p.id = o.projeto_id
    ORDER BY o.mes_ano ASC, o.id ASC
");
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Catálogo de projetos (dropdown + gestão rápida)
$projetos = listarProjetos($pdo);

// Fetch Lojas (Entidades GLPI)
$stmt_lojas = $pdo->query("SELECT name FROM glpi_entities ORDER BY name ASC");
$lojas = $stmt_lojas->fetchAll(PDO::FETCH_COLUMN);
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

    .wrap { max-width: 1600px; margin: -3rem auto 3rem; padding: 0 1rem; }

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
      padding: .25rem .75rem; font-size: .8rem; font-weight: 600; cursor: pointer;
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
    /* min-width força a tabela a não espremer coluna em tela estreita —
       table-responsive (Bootstrap) já dá a barra de rolagem horizontal
       quando a tela/monitor não comporta essa largura toda. */
    table { width: 100%; min-width: 1450px; border-collapse: collapse; font-size: .85rem; }
    thead th {
      background: #f9fafb; padding: .65rem 1rem; text-align: left;
      font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb;
      font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
      white-space: nowrap;
    }
    thead th:nth-child(1)  { width: 100px; }  /* Projeto   */
    thead th:nth-child(2)  { width: 130px; }  /* Categoria */
    thead th:nth-child(3)  { width: 140px; }  /* Loja      */
    thead th:nth-child(4)  { width: auto;  }  /* Descrição — ocupa o resto */
    thead th:nth-child(5)  { width: 130px; }  /* Qtd x Unit (Prev) */
    thead th:nth-child(6)  { width: 120px; }  /* Total Prev */
    thead th:nth-child(7)  { width: 130px; }  /* Qtd x Unit (Real) */
    thead th:nth-child(8)  { width: 120px; }  /* Total Real */
    thead th:nth-child(9)  { width: 110px; }  /* Saldo */
    thead th:nth-child(10) { width: 170px; }  /* Ações */
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

    /* Badge projeto — chip neutro, não reusa cor de categoria (dimensão diferente) */
    .badge-projeto {
      font-size: .7rem; padding: .2rem .55rem; border-radius: 8px; font-weight: 600;
      white-space: nowrap; background: #eef2ff; color: #4338ca;
      display: inline-block; max-width: 72px; overflow: hidden; text-overflow: ellipsis;
      vertical-align: middle;
    }
    .badge-projeto.sem-projeto { background: #f3f4f6; color: #9ca3af; font-weight: 500; }

    /* Cabeçalho de grupo (Projeto/Categoria/Loja) — clicável, minimiza/expande */
    tr.grupo-header:hover { background: #e2e8f0 !important; }

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

    /* Item suspenso — riscado, tom claro, não entra nas somas */
    tr.row-suspenso td { color: #9ca3af; }
    tr.row-suspenso td:not(:last-child) {
      text-decoration: line-through; text-decoration-color: #e53935; text-decoration-thickness: 1.5px;
    }
    tr.row-suspenso .badge-cat { opacity: .55; }
    tr.row-suspenso:hover { background: #fafafa; }

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
    <div class="d-flex flex-wrap gap-2 align-items-center w-100">
      <div class="d-flex gap-1 align-items-center">
        <select id="f-projeto" class="select-projeto form-select form-select-sm" style="width:175px" onchange="onChangeSelectProjeto(this, filtrar)">
          <option value="">Todos os projetos</option>
          <?php foreach ($projetos as $pj): ?>
              <option value="<?= (int)$pj['id'] ?>"><?= htmlspecialchars($pj['nome']) ?></option>
          <?php endforeach; ?>
          <option value="__novo__">+ Novo projeto...</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-secondary" title="Renomear projeto selecionado no filtro" onclick="renomearProjetoFiltro()">
          <i class="bi bi-pencil"></i>
        </button>
      </div>
      <select id="f-cat" class="form-select form-select-sm" style="width:175px" onchange="filtrar()">
        <option value="">Todas as categorias</option>
        <option value="Hardware">Hardware</option>
        <option value="Software">Software</option>
        <option value="Serviços">Serviços</option>
        <option value="Infraestrutura">Infraestrutura</option>
        <option value="Treinamento">Treinamento</option>
        <option value="Outros">Outros</option>
      </select>
      <select id="f-loja" class="form-select form-select-sm" style="width:175px" onchange="filtrar()">
        <option value="">Todas as lojas</option>
        <?php foreach ($lojas as $loja): ?>
            <option value="<?= htmlspecialchars($loja) ?>"><?= htmlspecialchars($loja) ?></option>
        <?php endforeach; ?>
      </select>
      <!-- Filtros de Mês/Ano -->
      <select id="f-mes" class="form-select form-select-sm" style="width:155px" onchange="filtrar()">
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
      <select id="f-ano" class="form-select form-select-sm" style="width:100px" onchange="filtrar()">
        <?php
        $anoAtual = date('Y');
        for($y = $anoAtual - 2; $y <= $anoAtual + 2; $y++): ?>
            <option value="<?= $y ?>" <?= $y == $anoAtual ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <input type="text" id="f-busca" class="form-control form-control-sm" style="width:180px"
             placeholder="🔍 Buscar descrição..." oninput="filtrar()" />
      <button class="btn btn-novo" onclick="filtrar()"><i class="bi bi-search"></i> Filtrar</button>
      <?php if (!$orc_ouvinte): ?>
        <button class="btn btn-novo" onclick="abrirModal()">
          <i class="bi bi-plus-lg me-1"></i>Novo Item
        </button>
      <?php endif; ?>
      <div class="ms-auto pt-2 border-top w-100 d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group btn-group-sm" role="group">
          <button type="button" id="btn-grp-projeto" class="btn btn-outline-secondary" onclick="toggleAgrupamento('projeto')">
            <i class="bi bi-folder me-1"></i>Projetos
          </button>
          <button type="button" id="btn-grp-cat" class="btn btn-outline-secondary" onclick="toggleAgrupamento('categoria')">
            <i class="bi bi-tags me-1"></i>Categorias
          </button>
          <button type="button" id="btn-grp-loja" class="btn btn-outline-secondary" onclick="toggleAgrupamento('loja')">
            <i class="bi bi-shop me-1"></i>Lojas
          </button>
        </div>
        <button class="btn btn-sm btn-outline-danger" onclick="exportarPDF('mes')"><i class="bi bi-file-earmark-pdf"></i> PDF Mês</button>
        <button class="btn btn-sm btn-outline-danger" onclick="exportarPDF('ano')"><i class="bi bi-file-earmark-pdf"></i> PDF Ano</button>
      </div>
    </div>
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
            <th>Projeto</th>
            <th>Categoria</th>
            <th>Loja</th>
            <th>Descrição</th>
            <th style="text-align:center">Qtd x Unit (Prev)</th>
            <th>Total Prev</th>
            <th style="text-align:center">Qtd x Unit (Real)</th>
            <th>Total Real</th>
            <th>Saldo</th>
            <th style="text-align:center">Ações</th>
          </tr>
        </thead>
        <tbody id="tbl-body">
          <tr class="empty-row"><td colspan="10"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhum item cadastrado. Clique em "Novo Item" para começar.</td></tr>
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
              <label class="form-label fw-semibold">Projeto</label>
              <select class="select-projeto form-select" name="projeto_id" id="item-projeto" onchange="onChangeSelectProjeto(this)">
                <option value="">— Sem projeto —</option>
                <?php foreach ($projetos as $pj): ?>
                    <option value="<?= (int)$pj['id'] ?>"><?= htmlspecialchars($pj['nome']) ?></option>
                <?php endforeach; ?>
                <option value="__novo__">+ Novo projeto...</option>
              </select>
            </div>
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
              <label class="form-label fw-semibold">Loja</label>
              <select class="form-select" name="loja" id="item-loja">
                <option value="">Selecione a loja...</option>
                <?php foreach ($lojas as $loja): ?>
                    <option value="<?= htmlspecialchars($loja) ?>"><?= htmlspecialchars($loja) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Mês/Ano <span class="text-danger">*</span></label>
              <input type="month" class="form-control" name="mes_ano" id="item-mes"/>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Descrição (equipamento/serviço) <span class="text-danger">*</span></label>
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
let modalDetalhesOrc;
let itens = <?= json_encode($itens) ?>;
let projetosLista = <?= json_encode($projetos) ?>;

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalItem'));
  modalConcretizar = new bootstrap.Modal(document.getElementById('modalConcretizar'));
  modalDetalhesOrc = new bootstrap.Modal(document.getElementById('modalDetalhesOrc'));

  // Carregar filtros salvos
  const filtrosSaved = JSON.parse(localStorage.getItem('orc_filtros') || '{}');
  if (filtrosSaved.projeto) document.getElementById('f-projeto').value = filtrosSaved.projeto;
  if (filtrosSaved.cat)  document.getElementById('f-cat').value   = filtrosSaved.cat;
  if (filtrosSaved.mes)  document.getElementById('f-mes').value   = filtrosSaved.mes;
  if (filtrosSaved.ano)  document.getElementById('f-ano').value   = filtrosSaved.ano;
  if (filtrosSaved.q)    document.getElementById('f-busca').value = filtrosSaved.q;
  if (filtrosSaved.loja) document.getElementById('f-loja').value  = filtrosSaved.loja;

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

function ativoItem(i) {
  return Number(i.ativo ?? 1) !== 0;
}

function atualizarStats(listaItems = itens) {
  const somaveis = listaItems.filter(ativoItem);
  const plan  = somaveis.reduce((s, i) => s + Number(i.total_previsto || 0), 0);
  const real  = somaveis.reduce((s, i) => s + Number(i.total_realizado || 0), 0);
  const saldo = plan - real;
  const pct   = plan > 0 ? Math.min(Math.round(real / plan * 100), 100) : 0;
  const corPct = pct >= 90 ? '#e53935' : pct >= 70 ? '#fb8c00' : '#43a047';

  document.getElementById('s-planejado').textContent     = fmt(plan);
  document.getElementById('s-planejado-sub').textContent = listaItems.length + ' ite' + (listaItems.length === 1 ? 'm' : 'ns');
  document.getElementById('s-realizado').textContent     = fmt(real);
  document.getElementById('s-realizado-sub').textContent = somaveis.filter(i => Number(i.total_realizado) > 0).length + ' com valor';
  document.getElementById('s-saldo').textContent         = fmt(Math.abs(saldo));
  document.getElementById('s-saldo').className           = 's-value ' + (saldo >= 0 ? 'saldo-pos' : 'saldo-neg');
  document.getElementById('s-saldo-sub').textContent     = saldo >= 0 ? 'Dentro do orçamento' : 'Acima do orçamento';
  document.getElementById('s-pct').textContent           = pct + '%';
  document.getElementById('s-pct').style.color           = corPct;
  document.getElementById('s-pct-bar').style.width       = pct + '%';
  document.getElementById('s-pct-bar').style.background  = corPct;
}

function filtrar() {
  const projeto = document.getElementById('f-projeto').value;
  const cat  = document.getElementById('f-cat').value;
  const mes  = document.getElementById('f-mes').value;
  const ano  = document.getElementById('f-ano').value;
  const q    = document.getElementById('f-busca').value.toLowerCase();
  const loja = document.getElementById('f-loja').value.toLowerCase();

  // Salvar estado dos filtros
  localStorage.setItem('orc_filtros', JSON.stringify({projeto, cat, mes, ano, q, loja}));

  const lista = itens.filter(i => {
    if (projeto && String(i.projeto_id || '') !== String(projeto)) return false;
    if (cat && i.categoria !== cat) return false;
    if (mes && i.mes_ano && i.mes_ano.substring(5, 7) !== mes) return false;
    // Forçar comparação estrita para o ano
    if (ano && i.mes_ano && i.mes_ano.substring(0, 4) !== String(ano)) return false;
    if (q && !(i.descricao || '').toLowerCase().includes(q)) return false;
    if (loja && !(i.loja || '').toLowerCase().includes(loja)) return false;
    return true;
  });
  atualizarStats(lista);
  renderTabela(lista);
}

// Ordem da hierarquia de agrupamento: Projeto → Categoria → Loja
// (Equipamento/Descrição é o nível mais fino, cada linha já é um item).
const DIMENSOES_AGRUPAMENTO = [
  { chave: 'projeto',   rotulo: 'Projeto',   icone: 'bi-folder', btn: 'btn-grp-projeto', valor: i => (i.projeto_nome && i.projeto_nome.trim() !== '') ? i.projeto_nome : 'Sem Projeto' },
  { chave: 'categoria', rotulo: 'Categoria', icone: 'bi-tags',   btn: 'btn-grp-cat',     valor: i => (i.categoria && i.categoria.trim() !== '') ? i.categoria : 'Outros' },
  { chave: 'loja',      rotulo: 'Loja',      icone: 'bi-shop',   btn: 'btn-grp-loja',    valor: i => (i.loja && i.loja.trim() !== '') ? i.loja : 'Sem Loja Definida' },
];

let modosAgrupamento = { projeto: false, categoria: false, loja: false };
// Grupos abertos manualmente pelo usuário (clique no cabeçalho) — toda vez que
// uma dimensão é ligada/desligada, reseta: começa tudo minimizado de novo.
let gruposExpandidos = new Set();
// idx (ordem de render) -> chave do grupo, pro onclick do cabeçalho saber qual expandir/retrair.
let gruposIdxAtual = [];

function toggleAgrupamento(tipo) {
  modosAgrupamento[tipo] = !modosAgrupamento[tipo];
  gruposExpandidos.clear();

  DIMENSOES_AGRUPAMENTO.forEach(d => {
    const btn = document.getElementById(d.btn);
    if (!btn) return;
    btn.className = modosAgrupamento[d.chave]
      ? 'btn btn-primary active text-white'
      : 'btn btn-outline-secondary';
  });

  filtrar();
}

function toggleGrupoColapso(idx) {
  const chave = gruposIdxAtual[idx];
  if (chave === undefined) return;
  if (gruposExpandidos.has(chave)) gruposExpandidos.delete(chave);
  else gruposExpandidos.add(chave);
  filtrar();
}

function renderTabela(lista) {
  const tbody = document.getElementById('tbl-body');
  const cat  = document.getElementById('f-cat').value;
  const mes  = document.getElementById('f-mes').value;

  document.getElementById('tbl-count').textContent = lista.length + ' ite' + (lista.length === 1 ? 'm' : 'ns');
  if (!lista.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="10"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhum item encontrado.</td></tr>';
    return;
  }

  const mesesNomes = {
    '01': 'Janeiro', '02': 'Fevereiro', '03': 'Março', '04': 'Abril', '05': 'Maio', '06': 'Junho',
    '07': 'Julho', '08': 'Agosto', '09': 'Setembro', '10': 'Outubro', '11': 'Novembro', '12': 'Dezembro'
  };

  // Dimensões ativas, já na ordem fixa da hierarquia (Projeto → Categoria → Loja)
  const dimensoesAtivas = DIMENSOES_AGRUPAMENTO.filter(d => modosAgrupamento[d.chave]);
  gruposIdxAtual = [];

  let agrupado = {};
  if (dimensoesAtivas.length) {
    agrupado = lista.reduce((acc, i) => {
      const chave = dimensoesAtivas.map(d => `${d.rotulo}: ${d.valor(i)}`).join(' | ');
      (acc[chave] = acc[chave] || []).push(i);
      return acc;
    }, {});
  } else {
    agrupado = (!mes) ? lista.reduce((acc, i) => {
      (acc[i.mes_ano] = acc[i.mes_ano] || []).push(i);
      return acc;
    }, {}) : { 'Filtrado': lista };
  }

  tbody.innerHTML = Object.entries(agrupado).map(([grupo, items]) => {
    let rows = '';
    let colapsado = false;
    if (dimensoesAtivas.length) {
      const totalPrevGrupo = items.filter(ativoItem).reduce((s, i) => s + Number(i.total_previsto || 0), 0);
      const totalRealGrupo = items.filter(ativoItem).reduce((s, i) => s + Number(i.total_realizado || 0), 0);
      const icone = dimensoesAtivas.length > 1 ? 'bi-diagram-3-fill' : dimensoesAtivas[0].icone;
      const expandido = gruposExpandidos.has(grupo);
      colapsado = !expandido;
      const idx = gruposIdxAtual.length;
      gruposIdxAtual.push(grupo);
      rows += `<tr class="grupo-header" style="background:#eef2f6; font-weight:bold; cursor:pointer" onclick="toggleGrupoColapso(${idx})">
        <td colspan="10">
          <i class="bi ${expandido ? 'bi-chevron-down' : 'bi-chevron-right'} me-2 text-muted" style="font-size:.75rem"></i>
          <i class="bi ${icone} me-1 text-primary"></i> ${esc(grupo)}
          <span class="badge bg-secondary ms-2">${items.length} ${items.length === 1 ? 'item' : 'itens'}</span>
          <span class="ms-3 text-muted fw-normal" style="font-size:.8rem">Previsto: <b class="text-primary">${fmt(totalPrevGrupo)}</b> | Realizado: <b class="text-danger">${fmt(totalRealGrupo)}</b></span>
        </td>
      </tr>`;
    } else if (!mes && Object.keys(agrupado).length > 1) {
      const split = grupo.split('-');
      const nomeMes = mesesNomes[split[1]] || split[1];
      const tituloMes = `${nomeMes} ${split[0]}`;
      rows += `<tr style="background:#eef2f6; font-weight:bold"><td colspan="10">${tituloMes}</td></tr>`;
    }

    if (colapsado) return rows;

    rows += items.map(i => {
      const plan   = Number(i.total_previsto || 0);
      const real   = Number(i.total_realizado || 0);
      const saldo  = plan - real;
      const cls    = CAT_CLASS[i.categoria] || 'cat-outros';
      const susp   = !ativoItem(i);
      const temProjeto = i.projeto_nome && i.projeto_nome.trim() !== '';
      return `<tr class="${susp ? 'row-suspenso' : ''}">
        <td><span class="badge-projeto ${temProjeto ? '' : 'sem-projeto'}" title="${esc(temProjeto ? i.projeto_nome : '— Sem projeto —')}">${esc(temProjeto ? i.projeto_nome : '— Sem projeto —')}</span></td>
        <td><span class="badge-cat ${cls}">${esc(i.categoria)}</span></td>
        <td>${esc(i.loja || '-')}</td>
        <td>${esc(i.descricao)}</td>
        <td style="font-size:.8rem;color:#6b7280;text-align:center">${i.qty_prevista || 0} x ${fmt(i.unit_previsto || 0)}</td>
        <td style="font-weight:600;color:#1a73e8">${fmt(plan)}</td>
        <td style="font-size:.8rem;color:#6b7280;text-align:center">${i.qty_realizada || 0} x ${fmt(i.unit_realizado || 0)}</td>
        <td style="font-weight:600;color:#e53935">${fmt(real)}</td>
        <td class="${saldo >= 0 ? 'saldo-pos' : 'saldo-neg'}">${saldo >= 0 ? '' : '-'}${fmt(Math.abs(saldo))}</td>
        <td style="text-align:center;white-space:nowrap">
          <button class="btn-acao text-secondary" title="Ver detalhes" onclick="verDetalhesOrc('${i.id}')"><i class="bi bi-eye-fill"></i></button>
          ${MODO_OUVINTE_ORC ? '' : `
          <button class="btn-acao text-primary" title="Editar" onclick="editarItem('${i.id}')"><i class="bi bi-pencil-fill"></i></button>
          <button class="btn-acao text-danger"  title="Excluir" onclick="excluirDireto('${i.id}')"><i class="bi bi-trash-fill"></i></button>
          <button class="btn-acao text-success" title="Concretizar Despesa" onclick="abrirModalConcretizar('${i.id}')"><i class="bi bi-check-circle-fill"></i></button>
          <button class="btn-acao ${susp ? 'text-success' : 'text-warning'}" title="${susp ? 'Reativar item' : 'Suspender item'}" onclick="toggleAtivoItem('${i.id}', ${susp})"><i class="bi ${susp ? 'bi-play-circle-fill' : 'bi-pause-circle-fill'}"></i></button>
          `}
        </td>
      </tr>`;
    }).join('');
    return rows;
  }).join('');
}

// Criação rápida de projeto direto do select (opção "+ Novo projeto...").
// onDone é opcional — usado pelo filtro (f-projeto) pra disparar filtrar()
// tanto numa troca normal quanto depois de criar o projeto novo; o select
// do modal de item não passa onDone (não precisa refiltrar nada).
function onChangeSelectProjeto(select, onDone) {
  if (select.value !== '__novo__') {
    if (onDone) onDone();
    return;
  }
  const nome = prompt('Nome do novo projeto:');
  if (!nome || !nome.trim()) { select.value = ''; return; }
  criarProjetoRapido(nome.trim(), select, onDone);
}

function criarProjetoRapido(nome, selectOrigem, onDone) {
  const body = new URLSearchParams({ action_projeto: 'add', nome });
  fetch('orcamento.php', { method: 'POST', body })
    .then(r => r.json())
    .then(resp => {
      if (!resp.ok) {
        alert('Não foi possível criar o projeto: ' + (resp.erro || ''));
        if (selectOrigem) selectOrigem.value = '';
        return;
      }
      projetosLista.push({ id: resp.id, nome: resp.nome });
      projetosLista.sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
      // Replica a opção nova em todo select de projeto da página (filtro + modal)
      document.querySelectorAll('.select-projeto').forEach(sel => {
        if (Array.from(sel.options).some(o => o.value === String(resp.id))) return;
        const opt = document.createElement('option');
        opt.value = resp.id;
        opt.textContent = resp.nome;
        const optNovo = Array.from(sel.options).find(o => o.value === '__novo__');
        if (optNovo) sel.insertBefore(opt, optNovo); else sel.appendChild(opt);
      });
      if (selectOrigem) selectOrigem.value = resp.id;
      if (onDone) onDone();
    })
    .catch(() => {
      alert('Erro de rede ao criar o projeto.');
      if (selectOrigem) selectOrigem.value = '';
    });
}

// Renomear o projeto atualmente selecionado no filtro "Todos os projetos".
// Só o nome muda — nenhum item precisa ser revinculado, mas a label
// precisa ser sincronizada em todo select .select-projeto e nos itens
// já carregados em memória (projeto_nome), pra refletir sem reload.
function renomearProjetoFiltro() {
  const select = document.getElementById('f-projeto');
  const id = select.value;
  if (!id || id === '__novo__') { alert('Selecione um projeto no filtro pra renomear.'); return; }
  const atual = select.options[select.selectedIndex].textContent;
  const novoNome = prompt('Novo nome do projeto:', atual);
  if (!novoNome || !novoNome.trim() || novoNome.trim() === atual) return;

  const body = new URLSearchParams({ action_projeto: 'rename', id, nome: novoNome.trim() });
  fetch('orcamento.php', { method: 'POST', body })
    .then(r => r.json())
    .then(resp => {
      if (!resp.ok) { alert('Não foi possível renomear o projeto: ' + (resp.erro || '')); return; }
      const pIdx = projetosLista.findIndex(p => String(p.id) === String(resp.id));
      if (pIdx >= 0) projetosLista[pIdx].nome = resp.nome;
      document.querySelectorAll('.select-projeto').forEach(sel => {
        const opt = Array.from(sel.options).find(o => o.value === String(resp.id));
        if (opt) opt.textContent = resp.nome;
      });
      itens.forEach(i => { if (String(i.projeto_id) === String(resp.id)) i.projeto_nome = resp.nome; });
      filtrar();
    })
    .catch(() => alert('Erro de rede ao renomear o projeto.'));
}

function abrirModal() {
  document.getElementById('item-id').value   = '';
  document.getElementById('item-projeto').value = '';
  document.getElementById('item-cat').value  = 'Hardware';
  document.getElementById('item-desc').value = '';
  document.getElementById('item-loja').value = '';
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
  document.getElementById('item-projeto').value = i.projeto_id || '';
  document.getElementById('item-cat').value  = i.categoria;
  document.getElementById('item-desc').value = i.descricao;
  document.getElementById('item-loja').value = i.loja || '';
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

// Salva via fetch (sem reload) — só assim filtro/agrupamento/colapso
// (que só existem em memória no JS) sobrevivem ao salvar um item.
function salvarItem() {
  const desc = document.getElementById('item-desc').value.trim();
  if (!desc) { alert('Informe a descrição do item.'); return; }

  const body = new FormData(document.getElementById('form-item'));
  fetch('orcamento.php', { method: 'POST', body })
    .then(r => r.json())
    .then(resp => {
      if (!resp.ok) { alert('Não foi possível salvar o item.'); return; }
      const idx = itens.findIndex(i => String(i.id) === String(resp.item.id));
      if (idx >= 0) itens[idx] = resp.item; else itens.push(resp.item);
      modal.hide();
      filtrar(); // reaplica filtro/agrupamento atuais, sem recarregar a página
    })
    .catch(() => alert('Erro de rede ao salvar o item.'));
}

function excluirPorId(id) {
  const body = new URLSearchParams({ action: 'delete', id });
  fetch('orcamento.php', { method: 'POST', body })
    .then(r => r.json())
    .then(resp => {
      if (!resp.ok) { alert('Não foi possível excluir o item.'); return; }
      itens = itens.filter(i => String(i.id) !== String(resp.id));
      filtrar();
    })
    .catch(() => alert('Erro de rede ao excluir o item.'));
}

function excluirItem() {
  if (!confirm('Excluir este item de orçamento?')) return;
  const id = document.getElementById('item-id').value;
  modal.hide();
  excluirPorId(id);
}

function excluirDireto(id) {
  if (!confirm('Excluir este item de orçamento?')) return;
  excluirPorId(id);
}

function toggleAtivoItem(id, estaSuspenso) {
  if (!estaSuspenso && !confirm('Suspender este item? Os valores dele deixam de ser somados no orçamento até você reativar.')) return;
  const body = new URLSearchParams({ action: 'toggle_ativo', id });
  fetch('orcamento.php', { method: 'POST', body })
    .then(r => r.json())
    .then(resp => {
      if (!resp.ok) { alert('Não foi possível atualizar o item.'); return; }
      const idx = itens.findIndex(i => String(i.id) === String(resp.id));
      if (idx >= 0) itens[idx].ativo = resp.ativo;
      filtrar();
    })
    .catch(() => alert('Erro de rede ao atualizar o item.'));
}

function verDetalhesOrc(id) {
  const i = itens.find(x => String(x.id) === String(id));
  if (!i) return;
  const plan  = Number(i.total_previsto || 0);
  const real  = Number(i.total_realizado || 0);
  const saldo = plan - real;
  const corpo = document.getElementById('detalhes-orc-corpo');
  corpo.innerHTML = `
    <dl class="row mb-0">
      <dt class="col-sm-4">Projeto</dt><dd class="col-sm-8">${esc(i.projeto_nome || '— Sem projeto —')}</dd>
      <dt class="col-sm-4">Categoria</dt><dd class="col-sm-8">${esc(i.categoria)}</dd>
      <dt class="col-sm-4">Loja</dt><dd class="col-sm-8">${esc(i.loja || '-')}</dd>
      <dt class="col-sm-4">Mês/Ano</dt><dd class="col-sm-8">${esc(i.mes_ano || '-')}</dd>
      <dt class="col-sm-4">Descrição</dt><dd class="col-sm-8">${esc(i.descricao)}</dd>
      <dt class="col-sm-4">Previsto</dt><dd class="col-sm-8">${i.qty_prevista || 0} x ${fmt(i.unit_previsto || 0)} = <b>${fmt(plan)}</b></dd>
      <dt class="col-sm-4">Realizado</dt><dd class="col-sm-8">${i.qty_realizada || 0} x ${fmt(i.unit_realizado || 0)} = <b>${fmt(real)}</b></dd>
      <dt class="col-sm-4">Saldo</dt><dd class="col-sm-8 ${saldo >= 0 ? 'saldo-pos' : 'saldo-neg'}">${saldo >= 0 ? '' : '-'}${fmt(Math.abs(saldo))}</dd>
      <dt class="col-sm-4">Status</dt><dd class="col-sm-8">${ativoItem(i) ? '<span class="text-success fw-semibold">Ativo</span>' : '<span class="text-danger fw-semibold">Suspenso (fora das somas)</span>'}</dd>
      <dt class="col-sm-4">Concretizado</dt><dd class="col-sm-8">${Number(i.concluido || 0) ? 'Sim' : 'Não'}</dd>
      <dt class="col-sm-4">Observação</dt><dd class="col-sm-8">${esc(i.observacao || '-')}</dd>
    </dl>`;
  modalDetalhesOrc.show();
}

function abrirModalConcretizar(id) {
  const i = itens.find(x => String(x.id) === String(id));
  if (!i) return;
  document.getElementById('conc-orc-id').value = i.id;
  document.getElementById('conc-desc').value   = i.descricao;
  document.getElementById('conc-valor').value  = i.total_realizado || 0;
  document.getElementById('conc-projeto-id').value = i.projeto_id || '';
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

    const columns = ['Projeto', 'Categoria', 'Loja', 'Descrição', 'Mês', 'Qtd Prev','Vl Unit Prev','Total Prev','Qtd Real','Vl Unit Real','Total Real'];
    const rows = itens
        .filter(i => (tipo === 'mes' ? (i.mes_ano === document.getElementById('f-mes').value) : (i.mes_ano.startsWith(document.getElementById('f-ano').value))))
        .map(i => [
            i.projeto_nome || '-',
            i.categoria,
            i.loja || '-',
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
          <input type="hidden" name="projeto_id" id="conc-projeto-id"/>

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

<!-- Modal Ver Detalhes -->
<div class="modal fade" id="modalDetalhesOrc" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#374151;color:white">
        <h5 class="modal-title fw-bold"><i class="bi bi-eye-fill me-2"></i>Detalhes do Item</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detalhes-orc-corpo"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Orçamento</footer>
</body>
</html>
