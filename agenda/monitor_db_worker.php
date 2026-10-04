<?php
// agenda/monitor_db_worker.php
require_once __DIR__ . '/../wpp/evo_api.php';
$status_url = 'http://glpi-web/portal-glpi/agenda/postgres_status.php';
$webhook_central_url = getenv('DB_MONITOR_WEBHOOK_CENTRAL');
$nome_grupo_alerta = 'Alertas TI';
$data_json = file_get_contents($status_url);
$data = json_decode($data_json, true);
if ($data && isset($data['alerta']) && $data['alerta']) {
    $msg = "DB Central Alerta: " . $data['msg'] . " (Conexões: " . $data['conexoes'] . ", CPU: " . $data['cpu_usage'] . "%)";
    if ($webhook_central_url) {
        $ch = curl_init($webhook_central_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $msg]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
    $grupos = evo_groups();
    if ($grupos['ok']) {
        foreach ($grupos['grupos'] as $grupo) {
            if ($grupo['nome'] === $nome_grupo_alerta) {
                evo_send_text($grupo['jid'], "🔔 " . $msg);
                break;
            }
        }
    }
}
?>
