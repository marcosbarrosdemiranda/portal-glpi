<?php
require_once __DIR__ . '/agenda/db.php';

$sql = "
SELECT
    ao.tipo,
    ao.chave AS alert_chave,
    ao.primeiro_visto,
    pd.nome AS dude_name,
    pd.loja,
    pd.status AS dude_status,
    pd.detalhe AS dude_detalhe
FROM portal_alertas_ocorrencias ao
JOIN portal_dude_estado pd ON
    pd.tipo = SUBSTRING_INDEX(SUBSTRING_INDEX(ao.chave, ':', 2), ':', -1)
    AND pd.chave = SUBSTRING(ao.chave, LOCATE(':', ao.chave, LOCATE(':', ao.chave)+1)+1)
WHERE ao.tipo IN ('dude_device', 'dude_link', 'dude_latencia', 'dude_service')
  AND pd.status != 'down';
";

try {
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        echo "Nenhum alerta preso encontrado.\n";
    } else {
        echo "Alertas presos (equipamento voltou mas alerta ainda ativo):\n";
        echo "=================================================================\n";
        foreach ($results as $row) {
            echo "Tipo: {$row['tipo']}\n";
            echo "Chave do alerta: {$row['alert_chave']}\n";
            echo "Primeiro visto: {$row['primeiro_visto']}\n";
            echo "Nome do Dude: {$row['dude_name']}\n";
            echo "Loja: {$row['loja']}\n";
            echo "Status do Dude: {$row['dude_status']}\n";
            echo "Detalhe do Dude: {$row['dude_detalhe']}\n";
            echo "-----------------------------------------------------------------\n";
        }
        echo "Total: " . count($results) . " alertas presos\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
?>