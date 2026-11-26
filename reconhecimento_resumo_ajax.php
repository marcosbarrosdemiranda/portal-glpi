<?php
/**
 * reconhecimento_resumo_ajax.php — endpoint AJAX que devolve o relatório de
 * anormalidades do Reconhecimento Facial de ontem + hoje em texto pronto, usado
 * pra pré-preencher a resposta do chamado recorrente "Backup, Relatórios e
 * Banco de Dados - Rotina Diária" (Agenda). Mesmo padrão de
 * solides_resumo_ajax.php: só exige login.
 *
 * [SPEC: reconhecimento-resumo-rotina-diaria]
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/reconhecimento_lib.php';

header('Content-Type: application/json');

$ultimo = reconhecimento_ultimo_diagnostico($pdo);
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
} else {
    $statusTexto = 'Reconhecimento Facial: nenhum diagnóstico recebido ainda via webhook.';
}

$relTexto = reconhecimento_relatorio_texto(reconhecimento_relatorio_dias($pdo, time()));

$face = null;
if ($ultimo && !empty($ultimo['ultima_face_quando'])) {
    $face = [
        'quando'    => $ultimo['ultima_face_quando'],
        'nome'      => (string) ($ultimo['ultima_face_nome'] ?? ''),
        'canal'     => (string) ($ultimo['ultima_face_canal'] ?? ''),
        'thumb_b64' => $ultimo['ultima_face_thumb'] !== null ? (string) $ultimo['ultima_face_thumb'] : null,
    ];
}

echo json_encode([
    'ok'    => true,
    'texto' => implode("\n\n", [$statusTexto, $relTexto]),
    'face'  => $face,
]);
