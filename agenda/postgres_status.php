<?php
// agenda/postgres_status.php
// Endpoint JSON de leitura única do banco de dados central (SSH).
// Lógica real em db_central_lib.php (reaproveitada pela Central de Alertas).

require_once __DIR__ . '/../db_central_lib.php';

header('Content-Type: application/json');
echo json_encode(db_central_status());
