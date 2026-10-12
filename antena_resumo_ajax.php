<?php
/**
 * antena_resumo_ajax.php — endpoint AJAX que devolve o status ATUAL (último
 * estado conhecido, sem disparar nova varredura SSH) das antenas UniFi, usado
 * pra pré-preencher a resposta do chamado recorrente "Firewal, Unifi e
 * Comunicação Lojas" (Agenda) — mesmo padrão de
 * backup_resumo_ajax.php/monitor_resumo_ajax.php.
 *
 * Antes devolvia o resumo de QUEDAS do dia anterior
 * (antena_resumo_quedas_dia()/antena_resumo_texto(), ainda em
 * monitor_antenas_lib.php e cobertos por teste próprio) — trocado 2026-10-12
 * a pedido do usuário: o histórico de quedas pega falso positivo de
 * reinício de VPN de madrugada, que não reflete se a antena está online no
 * momento em que o técnico responde o chamado.
 *
 * Sem restrição de perfil além de login — qualquer técnico que responde
 * chamado pela Agenda pode puxar esse status.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/monitor_antenas_lib.php';

header('Content-Type: application/json');

// Lê o último estado já conhecido (mesmo que a Central de Alertas mantém via
// seu próprio ciclo) — sem disparar SSH novo aqui.
$antenas = monitor_antena_listar($pdo);
echo json_encode([
    'ok'    => true,
    'texto' => antena_status_atual_texto($antenas),
]);
