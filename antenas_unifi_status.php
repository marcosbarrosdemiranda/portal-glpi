<?php
// antenas_unifi_status.php
// Endpoint JSON (sem sessão — mesmo padrão desprotegido de
// agenda/postgres_status.php, só alcançável de dentro da rede docker
// interna) que varre todas as antenas UniFi ativas via SSH e devolve o
// snapshot persistido. Consumido pelo worker (sem sshpass no container)
// via alertas_tipos.php e lido direto por inventario_redes.php.

require_once __DIR__ . '/monitor_antenas_lib.php';

header('Content-Type: application/json');
echo json_encode(monitor_antenas_varrer($pdo));
