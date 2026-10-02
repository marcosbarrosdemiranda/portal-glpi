<?php
// agenda/despesas_db.php
require_once __DIR__ . '/db.php';

// Cria/Garante a tabela no banco glpi2 (usando o $pdo do sistema)
$pdo->exec("CREATE TABLE IF NOT EXISTS glpi_portal_despesas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orcamento_id INT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor_pago DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    data_pagamento DATE NOT NULL,
    numero_nf VARCHAR(50),
    fornecedor VARCHAR(100),
    metodo_pagamento VARCHAR(50),
    observacao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (orcamento_id) REFERENCES glpi_portal_orcamento(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
?>
