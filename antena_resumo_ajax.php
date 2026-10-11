<?php
/**
 * antena_resumo_ajax.php — endpoint AJAX que devolve o resumo de quedas de
 * antenas UniFi de 1 dia em texto pronto, usado pra pré-preencher a
 * resposta do chamado recorrente "Firewal, Unifi e Comunicação Lojas"
 * (Agenda) — mesmo padrão de backup_resumo_ajax.php/monitor_resumo_ajax.php.
 * Sem restrição de perfil além de login — qualquer técnico que responde
 * chamado pela Agenda pode puxar esse resumo.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
// alertas_tipos.php traz portal_alertas_historico (fonte das quedas) e já
// requer monitor_antenas_lib.php por dentro — evita require circular.
require_once __DIR__ . '/alertas_tipos.php';

header('Content-Type: application/json');

$data = trim((string) ($_GET['data'] ?? ''));
if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    $data = date('Y-m-d', strtotime('-1 day')); // padrão: dia calendário de ontem
}

$resumo = antena_resumo_quedas_dia($pdo, $data);
echo json_encode([
    'ok'    => true,
    'data'  => $data,
    'texto' => antena_resumo_texto($resumo, $data),
]);
