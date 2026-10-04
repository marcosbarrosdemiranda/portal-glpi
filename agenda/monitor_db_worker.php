<?php
// agenda/monitor_db_worker.php
// Monitora DB Central via postgres_status.php e alerta via Webhook

$status_url = 'http://localhost/agenda/postgres_status.php'; // Acessível internamente no container
$webhook_url = getenv('DB_MONITOR_WEBHOOK');

if (!$webhook_url) {
    exit("Webhook não configurado.\n");
}

$json = file_get_contents($status_url);
$data = json_decode($json, true);

if ($data && isset($data['alerta']) && $data['alerta']) {
    $msg = "DB Central Alerta: " . $data['msg'] . " (Conexões: " . $data['conexoes'] . ", CPU: " . $data['cpu_usage'] . "%)";

    $payload = json_encode(['text' => $msg]);

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    echo "Alerta disparado: $msg\n";
}
EOF
