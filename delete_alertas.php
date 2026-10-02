<?php
require_once __DIR__ . '/agenda/db.php';
$st = $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ?");
$st->execute(["monitor_ligado_muito_tempo"]);
echo "Registros removidos: " . $st->rowCount();
?>
