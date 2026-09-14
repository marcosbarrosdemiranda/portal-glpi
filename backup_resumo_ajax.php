<?php
/**
 * backup_resumo_ajax.php — endpoint AJAX que devolve o resumo de backups de
 * 1 dia em texto pronto, usado pra pré-preencher a resposta do chamado
 * recorrente "Backup, Relatórios e Banco de Dados - Rotina Diária" (Agenda).
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
require_once __DIR__ . '/backup_lib.php';

header('Content-Type: application/json');

$data = trim((string) ($_GET['data'] ?? ''));
if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    $data = date('Y-m-d', strtotime('-1 day')); // padrão: dia calendário de ontem
}

$execucoes = backup_resumo_dia($pdo, $data);
echo json_encode([
    'ok'    => true,
    'data'  => $data,
    'texto' => backup_resumo_texto($execucoes, $data),
]);
