<?php
header('Content-Type: application/json');
require_once 'glpi_api.php';
require_once 'db.php';

$tickets = glpi_get_tickets();

// Busca ticket_ids já agendados no banco
$agendados = [];
try {
    $rows = $pdo->query("SELECT DISTINCT ticket_id FROM glpi_plugin_agenda_events WHERE ticket_id IS NOT NULL")->fetchAll();
    foreach ($rows as $r) {
        $agendados[(string)$r['ticket_id']] = true;
    }
} catch (Exception $e) { /* ignora se tabela ainda não existir */ }

// Marca tickets já agendados (em vez de remover — permitem novo período)
$tickets = array_map(function($t) use ($agendados) {
    $t['agendado'] = isset($agendados[(string)$t['id']]);
    return $t;
}, $tickets);

// Não-agendados primeiro, depois os em andamento
usort($tickets, fn($a, $b) => $a['agendado'] <=> $b['agendado']);

echo json_encode(array_values($tickets));
