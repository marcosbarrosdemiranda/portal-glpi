<?php
require_once __DIR__ . '/agenda/db.php';
$pdo->exec("ALTER TABLE glpi_portal_orcamento ADD COLUMN concluido TINYINT(1) DEFAULT 0");
echo "Coluna concluido adicionada.";
