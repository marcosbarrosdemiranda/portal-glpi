<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/agenda/db.php';

$de  = $_GET['dt_ini'] ?? date('Y-m-01');
$ate = $_GET['dt_fim'] ?? date('Y-m-t');
$ano = date('Y');

$campos = ['a4fv','a4f','a3fv','a3f','a4adv','a4plc','etq5s','a3plc','a3adv'];

// ── Dados por entidade no período filtrado ──────────────────────────────────
$rows = $pdo->prepare("
    SELECT
        t.entities_id,
        e.name AS entidade_nome,
        SUM(f.qtdimpressesafourfvfield)  AS a4fv,
        SUM(f.qtdimpressesafourffield)   AS a4f,
        SUM(f.qtdimpressesathreefvfield) AS a3fv,
        SUM(f.qtdimpressesathreeffield)  AS a3f,
        SUM(f.qtdimpafouradesivofield)   AS a4adv,
        SUM(f.qtdimpafourplacasfield)    AS a4plc,
        SUM(f.qtdetiquetafivefield)      AS etq5s,
        SUM(f.qtdimpathreeplacafield)    AS a3plc,
        SUM(f.qtdimpathreeadesivofield)  AS a3adv
    FROM glpi_plugin_fields_ticketqtdimpressesafourfrenteversos f
    JOIN glpi_tickets t ON t.id = f.items_id
    LEFT JOIN glpi_entities e ON e.id = t.entities_id
    WHERE DATE(t.date) BETWEEN ? AND ?
    GROUP BY t.entities_id, e.name
    ORDER BY e.name
");
$rows->execute([$de, $ate]);
$entidades = $rows->fetchAll(PDO::FETCH_ASSOC);

foreach ($entidades as &$r) {
    foreach ($campos as $k) $r[$k] = (int)$r[$k];
}
unset($r);

// ── Acumulado do ano (independe do filtro) ──────────────────────────────────
$acum = $pdo->prepare("
    SELECT
        SUM(f.qtdimpressesafourfvfield)  AS a4fv,
        SUM(f.qtdimpressesafourffield)   AS a4f,
        SUM(f.qtdimpressesathreefvfield) AS a3fv,
        SUM(f.qtdimpressesathreeffield)  AS a3f,
        SUM(f.qtdimpafouradesivofield)   AS a4adv,
        SUM(f.qtdimpafourplacasfield)    AS a4plc,
        SUM(f.qtdetiquetafivefield)      AS etq5s,
        SUM(f.qtdimpathreeplacafield)    AS a3plc,
        SUM(f.qtdimpathreeadesivofield)  AS a3adv
    FROM glpi_plugin_fields_ticketqtdimpressesafourfrenteversos f
    JOIN glpi_tickets t ON t.id = f.items_id
    WHERE YEAR(t.date) = ?
");
$acum->execute([$ano]);
$acumulado_raw = $acum->fetch(PDO::FETCH_ASSOC);
$acumulado = [];
foreach ($campos as $k) $acumulado[$k] = (int)($acumulado_raw[$k] ?? 0);

// ── Por mês do ano ──────────────────────────────────────────────────────────
$meses = $pdo->prepare("
    SELECT
        MONTH(t.date) AS mes,
        SUM(f.qtdimpressesafourfvfield)  AS a4fv,
        SUM(f.qtdimpressesafourffield)   AS a4f,
        SUM(f.qtdimpressesathreefvfield) AS a3fv,
        SUM(f.qtdimpressesathreeffield)  AS a3f,
        SUM(f.qtdimpafouradesivofield)   AS a4adv,
        SUM(f.qtdimpafourplacasfield)    AS a4plc,
        SUM(f.qtdetiquetafivefield)      AS etq5s,
        SUM(f.qtdimpathreeplacafield)    AS a3plc,
        SUM(f.qtdimpathreeadesivofield)  AS a3adv
    FROM glpi_plugin_fields_ticketqtdimpressesafourfrenteversos f
    JOIN glpi_tickets t ON t.id = f.items_id
    WHERE YEAR(t.date) = ?
    GROUP BY MONTH(t.date)
    ORDER BY mes
");
$meses->execute([$ano]);
$meses_raw = $meses->fetchAll(PDO::FETCH_ASSOC);

// Preenche todos os 12 meses (0 nos que não têm dados)
$por_mes = array_fill(1, 12, array_fill_keys($campos, 0));
foreach ($meses_raw as $m) {
    $n = (int)$m['mes'];
    foreach ($campos as $k) $por_mes[$n][$k] = (int)$m[$k];
}

echo json_encode([
    'entidades'  => $entidades,
    'ano'        => $ano,
    'acumulado'  => $acumulado,
    'por_mes'    => array_values($por_mes),
]);
