<?php
require_once __DIR__ . '/db.php';

// Função para listar tipos
function listarTipos($pdo) {
    return $pdo->query("SELECT * FROM glpi_portal_despesas_tipos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

// Ações POST (ex: salvar/excluir tipois)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tipo'])) {
    if ($_POST['action_tipo'] === 'add') {
        $pdo->prepare("INSERT INTO glpi_portal_despesas_tipos (nome) VALUES (?)")->execute([$_POST['nome']]);
    }
    header("Location: " . $_SERVER['HTTP_REFERER']); exit;
}
?>
