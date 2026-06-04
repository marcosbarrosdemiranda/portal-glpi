<?php
/**
 * Salva/retorna link iCal do Google Calendar por usuário
 */
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// Cria tabela se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS glpi_agenda_gcal (
    user_id   INT NOT NULL PRIMARY KEY,
    ical_url  TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$action  = $_GET['action'] ?? 'get';

// Salva URL
if ($action === 'save') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $url  = trim($body['url'] ?? '');

    if (!$url) {
        // Remove configuração
        $pdo->prepare("DELETE FROM glpi_agenda_gcal WHERE user_id=?")->execute([$user_id]);
        echo json_encode(['ok' => true, 'msg' => 'Configuração removida']);
        exit;
    }

    // Valida se é URL iCal do Google
    if (!str_contains($url, 'google.com') && !str_contains($url, 'ical') && !str_contains($url, '.ics')) {
        echo json_encode(['ok' => false, 'msg' => 'URL inválida. Use a URL iCal do Google Calendar.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO glpi_agenda_gcal (user_id, ical_url) VALUES (?,?) ON DUPLICATE KEY UPDATE ical_url=VALUES(ical_url)");
    $stmt->execute([$user_id, $url]);
    echo json_encode(['ok' => true, 'msg' => 'Google Calendar configurado!']);
    exit;
}

// Retorna URL do usuário
if ($action === 'get') {
    $stmt = $pdo->prepare("SELECT ical_url FROM glpi_agenda_gcal WHERE user_id=?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    echo json_encode(['url' => $row['ical_url'] ?? '']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
