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
}

// Fetch
$despesas = $pdo->query("SELECT * FROM glpi_portal_despesas ORDER BY data_pagamento DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <title>Gestão de Despesas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between mb-4">
        <h2>Gestão de Despesas</h2>
        <a href="dashboard.php" class="btn btn-secondary">Voltar</a>
    </div>

    <!-- Lista -->
    <div class="card shadow-sm p-3">
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Fornecedor</th>
                    <th>Valor</th>
                    <th>NF</th>
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
