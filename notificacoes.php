<?php
/**
 * Verifica chamados novos desde o último check
 * Usa SQL direto via PDO (ignora search API que tem índice desatualizado)
 * GET ?ultimo=2026-06-01T10:00:00
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { echo json_encode([]); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';

$ultimo = $_GET['ultimo'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
$ultimo = str_replace('T', ' ', $ultimo);

$novos = [];

try {
    // SQL direto — search API tem índice desatualizado
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.date, t.type, e.completename as entity_name
        FROM glpi_tickets t
        LEFT JOIN glpi_entities e ON e.id = t.entities_id
        WHERE t.is_deleted = 0
          AND t.status = 1
          AND t.date > ?
        ORDER BY t.date DESC
        LIMIT 20
    ");
    $stmt->execute([$ultimo]);
    while ($t = $stmt->fetch()) {
        $novos[] = [
            'id'       => (int)$t['id'],
            'titulo'   => $t['name'] ?? 'Sem título',
            'entidade' => apelido_entidade($t['entity_name'] ?? ''),
            'data'     => $t['date'] ?? '',
            'tipo'     => ((int)($t['type'] ?? 1)) === 1 ? 'Incidente' : 'Requisição',
        ];
    }
} catch (Exception $e) {
    /* fallback silencioso */
}

echo json_encode($novos);
