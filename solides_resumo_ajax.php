<?php
/**
 * solides_resumo_ajax.php — endpoint AJAX que devolve o relatório de
 * anormalidades do Ponto (API Sólides) de ontem + hoje em texto pronto, usado
 * pra pré-preencher a resposta do chamado recorrente "Backup, Relatórios e
 * Banco de Dados - Rotina Diária" (Agenda). Mesmo padrão de
 * backup_resumo_ajax.php: só exige login.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/solides_lib.php';

header('Content-Type: application/json');

$r = solides_buscar_relatorio();
echo json_encode($r['ok']
    ? ['ok' => true, 'texto' => solides_relatorio_texto($r['relatorio'])]
    : ['ok' => false, 'erro' => $r['erro']]);
