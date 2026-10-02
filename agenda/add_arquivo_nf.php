<?php
require_once __DIR__ . '/db.php';
try {
    $pdo->exec("ALTER TABLE glpi_portal_despesas ADD COLUMN arquivo_nf VARCHAR(255) NULL");
    echo "Coluna arquivo_nf adicionada com sucesso.";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
