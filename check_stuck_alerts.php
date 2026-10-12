<?php
/**
 * check_stuck_alerts.php — diagnóstico: acha alertas do monitor de rede
 * "presos" (o dispositivo já voltou, mas a ocorrência em
 * portal_alertas_ocorrencias continua lá). Uso:
 * docker exec glpi-web php .../check_stuck_alerts.php
 *
 * 2026-10-11: reescrito — a versão original (da época do The Dude) comparava
 * contra portal_dude_estado usando 'dude_device'/'dude_link'/etc. e casava
 * pd.chave (nome/IP do device) com um trecho de ao.chave. Dois problemas
 * surgiram com a migração pro monitor próprio: (1) os tipos de alerta foram
 * renomeados pra 'monitor_device'/'monitor_link'/... (291a82e), então o IN()
 * nunca mais casava nada; (2) ao.chave passou a ser 'monitor:<tipo>:<id>'
 * (id numérico de portal_monitor_dispositivos), enquanto portal_dude_estado só
 * guarda tipo='device' chaveado por nome/IP — então mesmo corrigindo o IN(),
 * o JOIN nunca bateria. Como device/link/latencia/service hoje vêm todos de
 * monitor_check_tipo() (monitor_lib.php) com o mesmo esquema de chave, dá pra
 * ler o status direto de portal_monitor_dispositivos, sem o intermediário.
 *
 * Web-gated (precisa login) — CLI (docker exec) não passa por
 * auth_guard, já que só quem tem acesso ao servidor chega lá.
 */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth_guard.php';
    if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
    if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/agenda/db.php';

$sql = "
SELECT
    ao.tipo,
    ao.chave AS alert_chave,
    ao.primeiro_visto,
    d.nome AS device_nome,
    d.loja,
    d.status AS device_status,
    d.ip AS device_ip
FROM portal_alertas_ocorrencias ao
JOIN portal_monitor_dispositivos d ON d.id = CAST(SUBSTRING_INDEX(ao.chave, ':', -1) AS UNSIGNED)
WHERE ao.tipo IN ('monitor_device', 'monitor_link', 'monitor_latencia')
  AND d.status != 'down';
";

try {
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        echo "Nenhum alerta preso encontrado.\n";
    } else {
        echo "Alertas presos (equipamento voltou mas alerta ainda ativo):\n";
        echo "=================================================================\n";
        foreach ($results as $row) {
            echo "Tipo: {$row['tipo']}\n";
            echo "Chave do alerta: {$row['alert_chave']}\n";
            echo "Primeiro visto: {$row['primeiro_visto']}\n";
            echo "Dispositivo: {$row['device_nome']}\n";
            echo "Loja: {$row['loja']}\n";
            echo "Status atual: {$row['device_status']}\n";
            echo "IP: {$row['device_ip']}\n";
            echo "-----------------------------------------------------------------\n";
        }
        echo "Total: " . count($results) . " alertas presos\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
