<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/agenda/despesas_db.php';
require_once __DIR__ . '/agenda/tipos_db.php';
$tipos = listarTipos($pdo);

if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

// CRUD despesas via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $arquivo_nf = null;
        if (!empty($_FILES['arquivo_nf']['name'])) {
            $uploadDir = 'uploads/despesas/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = pathinfo($_FILES['arquivo_nf']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['arquivo_nf']['tmp_name'], $uploadDir . $fileName)) {
                $arquivo_nf = $uploadDir . $fileName;
            }
        }

        $sql = "INSERT INTO glpi_portal_despesas (orcamento_id, descricao, valor_pago, data_pagamento, numero_nf, fornecedor, metodo_pagamento, observacao, arquivo_nf, qty, unit_price, tipo_despesa_id, loja) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            !empty($_POST['orcamento_id']) ? (int)$_POST['orcamento_id'] : null,
            $_POST['descricao'],
            (float)$_POST['valor_pago'],
            $_POST['data_pagamento'],
            $_POST['numero_nf'],
            $_POST['fornecedor'],
            $_POST['metodo'],
            $_POST['observacao'],
            $arquivo_nf,
            (int)$_POST['qty'],
            (float)$_POST['unit_price'],
            (int)$_POST['tipo_despesa_id'],
            $_POST['loja']
        ]);

        if (!empty($_POST['orcamento_id'])) {
            $pdo->prepare("UPDATE glpi_portal_orcamento SET concluido=1 WHERE id=?")->execute([(int)$_POST['orcamento_id']]);
        }
        header("Location: despesas.php"); exit;
    }

    // DELETE
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM glpi_portal_despesas WHERE id=?")->execute([(int)$_POST['id']]);
        header("Location: despesas.php"); exit;
    }
}

// Filtros
$where = [];
$params = [];
$f_mes = $_GET['f_mes'] ?? '';
$f_ano = $_GET['f_ano'] ?? '';
$f_loja = $_GET['f_loja'] ?? '';

if ($f_mes) { $where[] = "MONTH(data_pagamento) = ?"; $params[] = (int)$f_mes; }
if ($f_ano) { $where[] = "YEAR(data_pagamento) = ?"; $params[] = (int)$f_ano; }
if ($f_loja) { $where[] = "loja LIKE ?"; $params[] = "%$f_loja%"; }

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Fetch com filtros
$sql = "SELECT d.*, t.nome as tipo_nome FROM glpi_portal_despesas d LEFT JOIN glpi_portal_despesas_tipos t ON d.tipo_despesa_id=t.id $where_sql ORDER BY d.data_pagamento ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$despesas = $st->fetchAll(PDO::FETCH_ASSOC);

// Somatória
$sql_total = "SELECT SUM(valor_pago) as total FROM glpi_portal_despesas $where_sql";
$st_total = $pdo->prepare($sql_total);
$st_total->execute($params);
$total_pago = $st_total->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Fetch Lojas (Entidades GLPI)
$stmt_lojas = $pdo->query("SELECT name FROM glpi_entities ORDER BY name ASC");
$lojas = $stmt_lojas->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Gestão de Despesas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #1565c0; }
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

    /* Cards e Tabelas */
    .card { border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .table thead th { font-weight: 700; color: #374151; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
</head>
<body class="bg-light">

<!-- Navbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-wallet2"></i> Gestão de Despesas</div>
  <a href="dashboard.php" class="btn btn-sm text-white" style="background: rgba(255,255,255,.15);"><i class="bi bi-house-door-fill me-1"></i> Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-wallet2 me-2"></i>Gestão de Despesas</h1>
  <p>Acompanhe e registre as despesas de TI</p>
</div>

<div class="wrap">
    <div class="d-flex justify-content-between my-4 align-items-center">
        <div></div>
        <button class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);" data-bs-toggle="modal" data-bs-target="#modalDespesa">
            <i class="bi bi-plus-lg me-1"></i> Nova Despesa
        </button>
    </div>

    <!-- Modal Nova Despesa -->
    <div class="modal fade" id="modalDespesa" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <input type="hidden" name="action" value="save">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Despesa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label>Descrição</label>
                        <input type="text" name="descricao" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label>Categoria <a href="#" onclick="abrirGerenciarCategorias(); return false;" title="Gerenciar categorias"><i class="bi bi-gear"></i></a></label>
                        <select name="tipo_despesa_id" id="desp-item-cat" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= $tipo['id'] ?>"><?= htmlspecialchars($tipo['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>Quantidade</label>
                        <input type="number" name="qty" id="qty" class="form-control" value="1" required oninput="calcularTotal()">
                    </div>
                    <div class="mb-2">
                        <label>Valor Unitário</label>
                        <input type="number" step="0.01" name="unit_price" id="unit_price" class="form-control" required oninput="calcularTotal()">
                    </div>
                    <div class="mb-2">
                        <label>Valor Total</label>
                        <input type="number" step="0.01" name="valor_pago" id="valor_total" class="form-control" readonly>
                    </div>

                    <script>
                    function calcularTotal() {
                        const qty = parseFloat(document.getElementById('qty').value) || 0;
                        const price = parseFloat(document.getElementById('unit_price').value) || 0;
                        document.getElementById('valor_total').value = (qty * price).toFixed(2);
                    }
                    </script>
                    <div class="mb-2">
                        <label>Data Pagamento</label>
                        <input type="date" name="data_pagamento" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-2">
                        <label>Loja</label>
                        <select name="loja" class="form-select">
                            <option value="">Selecione a loja...</option>
                            <?php foreach ($lojas as $loja): ?>
                                <option value="<?= htmlspecialchars($loja) ?>"><?= htmlspecialchars($loja) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>Fornecedor</label>
                        <input type="text" name="fornecedor" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label>NF (Opcional)</label>
                        <input type="text" name="numero_nf" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label>Método Pagamento</label>
                        <input type="text" name="metodo" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label>Arquivo NF (PDF/JPG/PNG)</label>
                        <input type="file" name="arquivo_nf" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                     <div class="mb-2">
                        <label>Observação</label>
                        <textarea name="observacao" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-success">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cards de Resumo -->
    <div class="row mb-4">
        <div class="col-md-3 ms-auto">
            <div class="card p-3 shadow-sm text-center" style="border-left: 4px solid var(--mod);">
                <h6 class="text-muted" style="font-size: .72rem; text-transform: uppercase;">Total Pago</h6>
                <h4 class="text-primary" style="font-weight: 700;">R$ <?= number_format($total_pago, 2, ',', '.') ?></h4>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm p-3 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label>Mês</label>
                <select name="f_mes" class="form-select">
                    <option value="">Todos</option>
                    <?php
                    $meses = [
                        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
                        7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                    ];
                    foreach($meses as $num => $nome): ?>
                        <option value="<?= $num ?>" <?= $f_mes == $num ? 'selected' : '' ?>><?= $nome ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label>Loja</label>
                <select name="f_loja" class="form-select">
                    <option value="">Todas as lojas</option>
                    <?php foreach ($lojas as $loja): ?>
                        <option value="<?= htmlspecialchars($loja) ?>" <?= (($_GET['f_loja'] ?? '') == $loja) ? 'selected' : '' ?>><?= htmlspecialchars($loja) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label>Ano</label>
                <input type="number" name="f_ano" class="form-control" value="<?= htmlspecialchars($f_ano) ?>" placeholder="2026">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="despesas.php" class="btn btn-secondary">Limpar</a>
                <button type="button" class="btn btn-success" onclick="exportarPDF()">Gerar PDF</button>
            </div>
        </form>
    </div>

    <!-- Lista -->
    <div class="card shadow-sm p-3">
        <table class="table" id="tabelaDespesas">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Categoria</th>
                    <th>Loja</th>
                    <th>Descrição</th>
                    <th>Qtd</th>
                    <th>Preço Unit</th>
                    <th>Total</th>
                    <th>Fornecedor</th>
                    <th>NF</th>
                    <th>Arquivo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($despesas as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['data_pagamento']) ?></td>
                    <td><?= htmlspecialchars($d['tipo_nome'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($d['loja'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($d['descricao']) ?></td>
                    <td><?= htmlspecialchars($d['qty']) ?></td>
                    <td>R$ <?= number_format($d['unit_price'], 2, ',', '.') ?></td>
                    <td>R$ <?= number_format($d['valor_pago'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($d['fornecedor']) ?></td>
                    <td><?= htmlspecialchars($d['numero_nf']) ?></td>
                    <td>
                        <?php if ($d['arquivo_nf']): ?>
                            <a href="<?= htmlspecialchars($d['arquivo_nf']) ?>" target="_blank" class="btn btn-sm btn-info"><i class="bi bi-file-earmark"></i></a>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Ver detalhes" onclick="verDetalhesDespesa('<?= $d['id'] ?>')"><i class="bi bi-eye-fill"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir esta despesa?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ver Detalhes -->
<div class="modal fade" id="modalDetalhesDespesa" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#374151;color:white">
        <h5 class="modal-title fw-bold"><i class="bi bi-eye-fill me-2"></i>Detalhes da Despesa</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detalhes-despesa-corpo"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script>
    const DESPESAS = <?= json_encode($despesas) ?>;
    let modalDetalhesDespesa;

    document.addEventListener('DOMContentLoaded', () => {
        modalDetalhesDespesa = new bootstrap.Modal(document.getElementById('modalDetalhesDespesa'));
    });

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function fmtMoeda(v) {
        return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function verDetalhesDespesa(id) {
        const d = DESPESAS.find(x => String(x.id) === String(id));
        if (!d) return;
        const arquivo = d.arquivo_nf
            ? `<a href="${esc(d.arquivo_nf)}" target="_blank">Abrir arquivo</a>`
            : '-';
        const corpo = document.getElementById('detalhes-despesa-corpo');
        corpo.innerHTML = `
          <dl class="row mb-0">
            <dt class="col-sm-4">Data Pagamento</dt><dd class="col-sm-8">${esc(d.data_pagamento)}</dd>
            <dt class="col-sm-4">Categoria</dt><dd class="col-sm-8">${esc(d.tipo_nome || '-')}</dd>
            <dt class="col-sm-4">Loja</dt><dd class="col-sm-8">${esc(d.loja || '-')}</dd>
            <dt class="col-sm-4">Descrição</dt><dd class="col-sm-8">${esc(d.descricao)}</dd>
            <dt class="col-sm-4">Qtd x Unit</dt><dd class="col-sm-8">${d.qty || 0} x ${fmtMoeda(d.unit_price || 0)}</dd>
            <dt class="col-sm-4">Total Pago</dt><dd class="col-sm-8"><b>${fmtMoeda(d.valor_pago || 0)}</b></dd>
            <dt class="col-sm-4">Fornecedor</dt><dd class="col-sm-8">${esc(d.fornecedor || '-')}</dd>
            <dt class="col-sm-4">Nota Fiscal</dt><dd class="col-sm-8">${esc(d.numero_nf || '-')}</dd>
            <dt class="col-sm-4">Método Pagamento</dt><dd class="col-sm-8">${esc(d.metodo_pagamento || '-')}</dd>
            <dt class="col-sm-4">Arquivo NF</dt><dd class="col-sm-8">${arquivo}</dd>
            <dt class="col-sm-4">Item de Orçamento</dt><dd class="col-sm-8">${d.orcamento_id ? '#' + esc(d.orcamento_id) : 'Despesa avulsa (sem vínculo)'}</dd>
            <dt class="col-sm-4">Observação</dt><dd class="col-sm-8">${esc(d.observacao || '-')}</dd>
          </dl>`;
        modalDetalhesDespesa.show();
    }

    function exportarPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        doc.text("Relatório de Despesas", 14, 15);
        doc.autoTable({ html: '#tabelaDespesas', startY: 20 });
        doc.save('despesas.pdf');
    }
</script>
<script>window.SELECTS_CATEGORIA = ['desp-item-cat'];</script>
<?php include __DIR__ . '/categorias_modal.php'; ?>
</body>
</html>
