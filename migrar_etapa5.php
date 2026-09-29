<?php
require_once __DIR__ . '/agenda/db.php';
try {
    $pdo->exec("ALTER TABLE portal_monitor_grupos ADD COLUMN notif_whatsapp TINYINT(1) DEFAULT 1, ADD COLUMN lembrete_min INT DEFAULT 0, ADD COLUMN aviso_queda_curta VARCHAR(20) DEFAULT 'registro'");
    echo "Alterado com sucesso!\n";
} catch (Exception $e) {
    echo "Erro (talvez já exista?): " . $e->getMessage() . "\n";
}
?>
