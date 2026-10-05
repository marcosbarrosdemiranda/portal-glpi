<?php
/**
 * db_central_resumo_ajax.php — endpoint AJAX que devolve o status do Banco
 * de Dados Central (conexões/CPU/memória) em texto pronto, usado pra
 * pré-preencher a resposta do chamado recorrente "Backup, Relatórios e
 * Banco de Dados - Rotina Diária" (Agenda). Mesmo padrão de
 * backup_resumo_ajax.php/solides_resumo_ajax.php: só exige login.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/db_central_lib.php';

header('Content-Type: application/json');

$status = db_central_status();
echo json_encode([
    'ok'    => true,
    'texto' => db_central_status_texto($status),
]);
