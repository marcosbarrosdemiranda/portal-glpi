<?php
require_once __DIR__ . '/db.php';

// Função para listar tipos
function listarTipos($pdo) {
    return $pdo->query("SELECT * FROM glpi_portal_despesas_tipos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

// Listagem JSON pro modal "Gerenciar categorias" (criar/renomear) atualizar
// sem reload de página — mesmo catálogo usado por orçamento e despesas.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list_tipos'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['autenticado'])) {
        http_response_code(403); exit('Não autenticado');
    }
    header('Content-Type: application/json');
    echo json_encode(listarTipos($pdo));
    exit;
}

// Ações POST (ex: salvar/excluir tipos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tipo'])) {
    // Guard de sessão — este arquivo pode ser chamado direto (não só via
    // require de página já autenticada), então confirma autenticado=true
    // igual orcamento.php/despesas.php fazem, em vez de confiar no caller.
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['autenticado'])) {
        http_response_code(403); exit('Não autenticado');
    }
    $nome = trim($_POST['nome'] ?? '');
    if ($_POST['action_tipo'] === 'add' && $nome !== '') {
        $pdo->prepare("INSERT INTO glpi_portal_despesas_tipos (nome) VALUES (?)")->execute([$nome]);
    } elseif ($_POST['action_tipo'] === 'edit' && $nome !== '' && !empty($_POST['id'])) {
        // Renomear é compartilhado: orçamento e despesas leem a mesma linha,
        // então a mudança já aparece nos dois lados sem sincronização extra.
        $pdo->prepare("UPDATE glpi_portal_despesas_tipos SET nome=? WHERE id=?")->execute([$nome, (int)$_POST['id']]);
    }
    // Chamada via fetch/AJAX (modal de categorias) não precisa de redirect —
    // só o fluxo antigo (form POST direto) usa o HTTP_REFERER.
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header("Location: " . $_SERVER['HTTP_REFERER']); exit;
}
?>
