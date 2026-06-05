<?php
/**
 * DEBUG — Diagnóstico de rotinas no banco
 * Mostra chamados com entities_id=0 encontrados por diferentes queries
 * PARA USO TEMPORÁRIO — remover após diagnóstico
 */
session_start();
if (empty($_SESSION['autenticado'])) { echo "Não autenticado"; exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔍 Diagnóstico Rotinas</h2><hr>";

$hoje = date('Y-m-d');

// Query 1: Todos os tickets entities_id=0 de HOJE (sem JOIN de técnico)
echo "<h3>1. Tickets entities_id=0 de HOJE</h3>";
$stmt = $pdo->prepare("
    SELECT t.id, t.name, t.date, t.entities_id, t.status,
           t.is_deleted
    FROM glpi_tickets t
    WHERE t.entities_id = 0
      AND t.is_deleted = 0
      AND DATE(t.date) = ?
    ORDER BY t.date DESC
    LIMIT 30
");
$stmt->execute([$hoje]);
$tickets = $stmt->fetchAll();
echo "<p>Encontrados: " . count($tickets) . "</p>";
echo "<table border='1' cellpadding='4' style='border-collapse:collapse;font-size:13px'>";
echo "<tr><th>ID</th><th>Título</th><th>Data</th><th>Status</th><th>Entities</th></tr>";
foreach ($tickets as $t) {
    echo "<tr><td>{$t['id']}</td><td>{$t['name']}</td><td>{$t['date']}</td><td>{$t['status']}</td><td>{$t['entities_id']}</td></tr>";
}
echo "</table>";

// Query 2: Tickets entities_id=0 com técnico via glpi_tickets_users.type=2
echo "<h3>2. Tickets entities_id=0 com técnico (tu.type=2) de HOJE</h3>";
$stmt2 = $pdo->prepare("
    SELECT t.id, t.name, t.date, t.status,
           tu.users_id, u.realname, u.firstname, u.name AS username
    FROM glpi_tickets t
    JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
    LEFT JOIN glpi_users u ON u.id = tu.users_id AND u.is_active = 1
    WHERE t.entities_id = 0
      AND t.is_deleted = 0
      AND t.status NOT IN (5, 6)
      AND DATE(t.date) = ?
    ORDER BY t.date DESC
    LIMIT 30
");
$stmt2->execute([$hoje]);
$tickets2 = $stmt2->fetchAll();
echo "<p>Encontrados: " . count($tickets2) . " (com técnico)</p>";
echo "<table border='1' cellpadding='4' style='border-collapse:collapse;font-size:13px'>";
echo "<tr><th>ID</th><th>Título</th><th>Data</th><th>Status</th><th>users_id</th><th>Técnico</th></tr>";
foreach ($tickets2 as $t) {
    $nome = trim(($t['realname']??'') . ' ' . ($t['firstname']??''));
    if (!$nome) $nome = $t['username'] ?? '';
    echo "<tr><td>{$t['id']}</td><td>{$t['name']}</td><td>{$t['date']}</td><td>{$t['status']}</td><td>{$t['users_id']}</td><td>$nome</td></tr>";
}
echo "</table>";

// Query 3: Eventos na agenda com ticket_id (pra ver o que já foi sincronizado)
echo "<h3>3. Eventos na agenda com ticket_id (já sincronizados)</h3>";
$stmt3 = $pdo->query("
    SELECT id, ticket_id, titulo, start, end, atendente
    FROM glpi_plugin_agenda_events
    WHERE ticket_id IS NOT NULL
      AND DATE(start) >= CURDATE()
    ORDER BY start ASC
    LIMIT 30
");
$agenda = $stmt3->fetchAll();
echo "<p>Encontrados: " . count($agenda) . " (na agenda)</p>";
echo "<table border='1' cellpadding='4' style='border-collapse:collapse;font-size:13px'>";
echo "<tr><th>Evento ID</th><th>Ticket ID</th><th>Título</th><th>Start</th><th>End</th><th>Atendente</th></tr>";
foreach ($agenda as $ev) {
    echo "<tr><td>{$ev['id']}</td><td>{$ev['ticket_id']}</td><td>{$ev['titulo']}</td><td>{$ev['start']}</td><td>{$ev['end']}</td><td>{$ev['atendente']}</td></tr>";
}
echo "</table>";

// Query 4: Mostrar DISTINCT entities_id encontrados em tickets com status ativo
echo "<h3>4. Entities distintas em tickets ativos criados HOJE</h3>";
$stmt4 = $pdo->query("
    SELECT DISTINCT t.entities_id, e.completename, COUNT(*) AS qtd
    FROM glpi_tickets t
    LEFT JOIN glpi_entities e ON e.id = t.entities_id
    WHERE t.is_deleted = 0
      AND t.status NOT IN (5, 6)
      AND DATE(t.date) = CURDATE()
    GROUP BY t.entities_id
    ORDER BY qtd DESC
    LIMIT 20
");
$entities = $stmt4->fetchAll();
echo "<p>Encontradas: " . count($entities) . " entidades</p>";
echo "<table border='1' cellpadding='4' style='border-collapse:collapse;font-size:13px'>";
echo "<tr><th>entities_id</th><th>Nome completo</th><th>Qtd tickets</th></tr>";
foreach ($entities as $e) {
    echo "<tr><td>{$e['entities_id']}</td><td>{$e['completename']}</td><td>{$e['qtd']}</td></tr>";
}
echo "</table>";

// Query 5: Mesmo que a 2, mas sem filtro entities_id=0
echo "<h3>5. Tickets COM técnico de HOJE (sem filtro entities_id)</h3>";
$stmt5 = $pdo->prepare("
    SELECT t.id, t.name, t.date, t.status, t.entities_id,
           tu.users_id, u.realname, u.firstname,
           e.completename AS entity_name
    FROM glpi_tickets t
    JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
    LEFT JOIN glpi_users u ON u.id = tu.users_id AND u.is_active = 1
    LEFT JOIN glpi_entities e ON e.id = t.entities_id
    WHERE t.is_deleted = 0
      AND t.status NOT IN (5, 6)
      AND DATE(t.date) = ?
    ORDER BY t.date DESC
    LIMIT 30
");
$stmt5->execute([$hoje]);
$tickets5 = $stmt5->fetchAll();
echo "<p>Encontrados: " . count($tickets5) . " (qualquer entidade)</p>";
echo "<table border='1' cellpadding='4' style='border-collapse:collapse;font-size:13px'>";
echo "<tr><th>ID</th><th>Título</th><th>Data</th><th>Status</th><th>Entidade</th><th>Ent ID</th><th>Técnico</th></tr>";
foreach ($tickets5 as $t) {
    $nome = trim(($t['realname']??'') . ' ' . ($t['firstname']??''));
    echo "<tr><td>{$t['id']}</td><td>{$t['name']}</td><td>{$t['date']}</td><td>{$t['status']}</td><td>{$t['entity_name']}</td><td>{$t['entities_id']}</td><td>$nome</td></tr>";
}
echo "</table>";

echo "<hr><p>Fim do diagnóstico. <a href='sync_rotinas_ajax.php'>Rodar sync manual</a></p>";
