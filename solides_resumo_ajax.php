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
$relTexto = $r['ok'] ? solides_relatorio_texto($r['relatorio']) : null;

// Pega também o último diagnóstico recebido via webhook (push) com o status atual e checagens
$ultimo = solides_ultimo_diagnostico($pdo);
$statusTexto = '';
if ($ultimo) {
    $statusAtualIcon = match ($ultimo['status']) {
        'ok' => '✅',
        'alerta' => '⚠️',
        default => '❌',
    };
    $statusTexto = "Status atual: {$statusAtualIcon} " . ($ultimo['resumo'] !== '' ? $ultimo['resumo'] : $ultimo['status']);
    $checagens = json_decode((string) $ultimo['checagens'], true);
    if (is_array($checagens)) {
        foreach ($checagens as $c) {
            $ico = match ($c['status'] ?? 'ok') {
                'ok' => '✅',
                'alerta' => '⚠️',
                default => '❌',
            };
            $statusTexto .= "\n  {$ico} " . ($c['nome'] ?? $c['id'] ?? '') . (isset($c['detalhe']) && $c['detalhe'] !== '' ? ' — ' . $c['detalhe'] : '');
        }
    }
    $statusTexto .= "\nÚltimo diagnóstico recebido em: " . $ultimo['recebido_em'];
}

$partes = [];
if ($statusTexto !== '') {
    $partes[] = $statusTexto;
}
if ($relTexto !== null) {
    $partes[] = $relTexto;
} elseif (!$ultimo) {
    $partes[] = 'Ponto (API Sólides): falha ao carregar relatório (' . $r['erro'] . ') e nenhum diagnóstico recente recebido via webhook.';
}

echo json_encode([
    'ok'    => true,
    'texto' => implode("\n\n", $partes),
]);
