<?php
/**
 * Limpa eventos duplicados e com datas corrompidas da agenda.
 * Execute UMA VEZ e depois delete este arquivo.
 */
require_once 'db.php';

$removidos = 0;
$ok = [];

// 1. Remove eventos onde end está mais de 2 dias após start (datas corrompidas)
$stmt = $pdo->prepare("
    DELETE FROM glpi_plugin_agenda_events
    WHERE TIMESTAMPDIFF(HOUR, start, end) > 48
");
$stmt->execute();
$r = $stmt->rowCount();
$removidos += $r;
$ok[] = "Eventos com datas corrompidas (duração > 48h) removidos: $r";

// 2. Para cada ticket_id, mantém apenas os eventos únicos por (ticket_id + DATE(start) + atendente)
// Remove duplicatas mantendo o que tem start/end mais razoável (menor duração)
$duplicatas = $pdo->query("
    SELECT ticket_id, DATE(start) as dia, atendente, COUNT(*) as total
    FROM glpi_plugin_agenda_events
    WHERE ticket_id IS NOT NULL
    GROUP BY ticket_id, DATE(start), atendente
    HAVING COUNT(*) > 1
")->fetchAll();

foreach ($duplicatas as $d) {
    // Busca todos os IDs desse grupo, ordena por duração (menor primeiro = mais razoável)
    $ids = $pdo->prepare("
        SELECT id FROM glpi_plugin_agenda_events
        WHERE ticket_id = ? AND DATE(start) = ? AND (atendente = ? OR (atendente IS NULL AND ? IS NULL))
        ORDER BY ABS(TIMESTAMPDIFF(MINUTE, start, end)) ASC
    ");
    $ids->execute([$d['ticket_id'], $d['dia'], $d['atendente'], $d['atendente']]);
    $rows = $ids->fetchAll(PDO::FETCH_COLUMN);

    // Mantém o primeiro (menor duração), deleta o resto
    array_shift($rows);
    foreach ($rows as $del_id) {
        $pdo->prepare("DELETE FROM glpi_plugin_agenda_events WHERE id = ?")->execute([$del_id]);
        $removidos++;
    }
}
$ok[] = "Grupos com duplicatas processados: " . count($duplicatas);

echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:2rem'>";
echo "<h2>Limpeza de eventos</h2>";
echo "<p><strong>Total removido: $removidos registros</strong></p>";
foreach ($ok as $m) echo "<p>✅ $m</p>";
echo "<hr><p>Total atual na tabela: " . $pdo->query("SELECT COUNT(*) FROM glpi_plugin_agenda_events")->fetchColumn() . " eventos</p>";
echo "<p><strong>Delete este arquivo após verificar.</strong></p>";
echo "</body></html>";
