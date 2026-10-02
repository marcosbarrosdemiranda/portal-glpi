<?php
// agenda/despesas_db.php
require_once __DIR__ . '/db.php';

// Garantir tabela de tipos
$pdo->exec("CREATE TABLE IF NOT EXISTS glpi_portal_despesas_tipos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Alterar tabela de despesas
$pdo->exec("ALTER TABLE glpi_portal_despesas
    ADD COLUMN IF NOT EXISTS qty INT DEFAULT 1,
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(15,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS tipo_despesa_id INT NULL,
    ADD CONSTRAINT tipo_fk FOREIGN KEY (tipo_despesa_id) REFERENCES glpi_portal_despesas_tipos(id)
");
?>
