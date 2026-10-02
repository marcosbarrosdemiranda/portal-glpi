<?php
/**
 * monitor_resumo_ajax.php — endpoint AJAX que devolve a latência
 * dos pfSense (Matriz e filiais) para preencher a rotina diária
 * de 'Firewal, Unifi e Comunicação Lojas'.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'erro' => 'não autenticado']);
    exit;
}

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/monitor_lib.php';

header('Content-Type: application/json');

global $pdo;

// Buscar os IPs dos pfSense (Matriz e filiais 003, 010, 030)
$stmt = $pdo->prepare("
    SELECT nome, COALESCE(ip_fixo, ip) AS ip_efetivo, loja
    FROM portal_monitor_dispositivos
    WHERE grupo = 'firewalls'
      AND monitorar = 1
      AND removido_em IS NULL
      AND (loja LIKE '%001' OR loja LIKE '%003' OR loja LIKE '%010' OR loja LIKE '%030')
    ORDER BY loja
");
$stmt->execute();
$dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ips = array_column($dispositivos, 'ip_efetivo');

// Se não houver, falha graciosamente
if (empty($ips)) {
    echo json_encode(['ok' => true, 'texto' => 'Nenhum pfSense monitorado encontrado para Matriz e filiais (003, 010, 030).']);
    exit;
}

// Obter status ao vivo/cacheado
$status = monitor_status_por_ips($pdo, $ips);

$linhas = ["Monitoramento de Comunicação Lojas (Latência):"];
foreach ($dispositivos as $disp) {
    $ip = $disp['ip_efetivo'];
    $nome = $disp['nome'];

    if (isset($status[$ip])) {
        $st = $status[$ip];
        if ($st['status'] === 'up' && $st['latencia_ms'] !== null) {
            $ms = $st['latencia_ms'];
            $classificacao = '';
            if ($ms < 10) $classificacao = '(🟢 Excelente)';
            elseif ($ms <= 30) $classificacao = '(🟡 Atenção)';
            else $classificacao = '(🔴 Crítico)';

            $linhas[] = "- {$nome}: {$ms}ms {$classificacao}";
        } elseif ($st['status'] === 'down') {
            $linhas[] = "- {$nome}: 🔴 OFFLINE / Sem Resposta";
        } else {
            $linhas[] = "- {$nome}: ⚪ Desconhecido";
        }
    } else {
        $linhas[] = "- {$nome}: ⚪ Sem dados";
    }
}

echo json_encode(['ok' => true, 'texto' => implode("\n", $linhas)]);
