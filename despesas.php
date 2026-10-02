<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/agenda/despesas_db.php';

if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

// CRUD despesas via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $sql = "INSERT INTO glpi_portal_despesas (orcamento_id, descricao, valor_pago, data_pagamento, numero_nf, fornecedor, metodo_pagamento, observacao) VALUES (?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            !empty($_POST['orcamento_id']) ? (int)$_POST['orcamento_id'] : null,
            $_POST['descricao'],
            (float)$_POST['valor_pago'],
            $_POST['data_pagamento'],
            $_POST['numero_nf'],
            $_POST['fornecedor'],
            $_POST['metodo'],
            $_POST['observacao']
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

if ($f_mes) { $where[] = "MONTH(data_pagamento) = ?"; $params[] = (int)$f_mes; }
if ($f_ano) { $where[] = "YEAR(data_pagamento) = ?"; $params[] = (int)$f_ano; }

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Fetch com filtros
$sql = "SELECT * FROM glpi_portal_despesas $where_sql ORDER BY data_pagamento DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$despesas = $st->fetchAll(PDO::FETCH_ASSOC);

// Somatória
$sql_total = "SELECT SUM(valor_pago) as total FROM glpi_portal_despesas $where_sql";
$st_total = $pdo->prepare($sql_total);
$st_total->execute($params);
$total_pago = $st_total->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <title>Gestão de Despesas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between mb-4">
        <h2>Gestão de Despesas</h2>
        <div>
            <a href="dashboard.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <!-- Cards de Resumo -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card p-3 shadow-sm text-center">
                <h6 class="text-muted">Total Pago</h6>
                <h4 class="text-primary">R$ <?= number_format($total_pago, 2, ',', '.') ?></h4>
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
                    <th>Descrição</th>
                    <th>Fornecedor</th>
                    <th>Valor</th>
                    <th>NF</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($despesas as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['data_pagamento']) ?></td>
                    <td><?= htmlspecialchars($d['descricao']) ?></td>
                    <td><?= htmlspecialchars($d['fornecedor']) ?></td>
                    <td>R$ <?= number_format($d['valor_pago'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($d['numero_nf']) ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Excluir esta despesa?')">
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

<script>
    function exportarPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        doc.text("Relatório de Despesas", 14, 15);
        doc.autoTable({ html: '#tabelaDespesas', startY: 20 });
        doc.save('despesas.pdf');
    }
</script>
</body>
</html>
