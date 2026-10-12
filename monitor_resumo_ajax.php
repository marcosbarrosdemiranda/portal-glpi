<?php
/**
 * monitor_resumo_ajax.php — endpoint AJAX que devolve a latência dos
 * pfSense (Matriz e filiais) + o status dos links de internet por loja
 * (acrescentado 2026-10-12, Fase 1 da expansão do box pfSense — ver
 * monitor_links_lib.php) para preencher a rotina diária de 'Firewal, Unifi
 * e Comunicação Lojas'.
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
require_once __DIR__ . '/monitor_links_lib.php';

header('Content-Type: application/json');

global $pdo;

// Buscar os IPs dos pfSense (Matriz e filiais 003, 010, 030)
$stmt = $pdo->prepare("
    SELECT nome, COALESCE(ip_fixo, ip) AS ip_efetivo, loja
    FROM portal_monitor_dispositivos
    WHERE grupo = 'firewalls'
      AND monitorar = 1
      AND (removido_em IS NULL OR removido_em = '')
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

// Links de internet por loja (status já lido pelo worker a cada 1 min —
// leitura pura, sem nova consulta ao pfSense aqui). Lojas sem pfSense
// direto (motivo conhecido) entram com aviso explícito, não ficam de fora.
$lojasEsperadas = array_values(array_unique(array_column($dispositivos, 'loja')));
$lojasConhecidasSemLink = [
    'Lj 003' => 'atrás de MikroTik',
    'Lj 010' => 'sem pfSense, é MikroTik',
];
$links = array_values(array_filter(monitor_links_listar($pdo), fn($l) => in_array($l['loja'], $lojasEsperadas, true)));
$comDado = array_unique(array_column($links, 'loja'));
$lojasSemDado = [];
foreach ($lojasEsperadas as $loja) {
    if (!in_array($loja, $comDado, true)) {
        $lojasSemDado[$loja] = $lojasConhecidasSemLink[$loja] ?? 'sem dado';
    }
}

$linhas[] = '';
$linhas[] = monitor_links_resumo_texto($links, $lojasSemDado);

echo json_encode(['ok' => true, 'texto' => implode("\n", $linhas)]);
