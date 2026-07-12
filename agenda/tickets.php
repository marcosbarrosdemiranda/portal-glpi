<?php
header('Content-Type: application/json');
require_once 'glpi_api.php';
require_once 'db.php';

$tickets = glpi_get_tickets();

// Busca ticket_ids já agendados no banco + start do próximo evento
$agendados = [];
try {
    $rows = $pdo->query("
        SELECT ticket_id, start
        FROM glpi_plugin_agenda_events
        WHERE ticket_id IS NOT NULL AND concluido = 0
        ORDER BY start ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        $tid = (string)$r['ticket_id'];
        if (!isset($agendados[$tid])) {
            // Converte para ISO 8601 (YYYY-MM-DDTHH:MM:SS) via DateTime — robusto contra formatos
            $dt = new DateTime($r['start']);
            $agendados[$tid] = $dt->format('c');
        }
    }
} catch (Exception $e) { /* ignora se tabela ainda não existir */ }

// Marca tickets já agendados (em vez de remover — permitem novo período)
$tickets = array_map(function($t) use ($agendados) {
    $tid = (string)$t['id'];
    $t['agendado']      = isset($agendados[$tid]);
    $t['agenda_start']  = $agendados[$tid] ?? null;
    return $t;
}, $tickets);

// Ordenação: não-agendados primeiro, depois por prioridade de status (Novo → Atendimento → Pendente)
// Status 1=Novo, 2=Atribuído, 3=Planejado, 4=Pendente
$status_priority = [1=>0, 2=>1, 3=>1, 4=>2]; // 0=primeiro, 2=último
usort($tickets, function($a, $b) use ($status_priority) {
    // 1º critério: não-agendados primeiro
    if ($a['agendado'] !== $b['agendado']) {
        return $a['agendado'] <=> $b['agendado'];
    }
    // 2º critério: prioridade de status (Novo=1 primeiro, Atribuído=2 e Planejado=3 depois, Pendente=4 último)
    $pa = $status_priority[$a['status_n']] ?? 2;
    $pb = $status_priority[$b['status_n']] ?? 2;
    if ($pa !== $pb) return $pa <=> $pb;
    // 3º critério: data de modificação (mais recente primeiro)
    return strcmp($b['date_mod'], $a['date_mod']);
});

echo json_encode(array_values($tickets));
