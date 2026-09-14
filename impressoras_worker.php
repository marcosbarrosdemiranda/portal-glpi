<?php
// impressoras_worker.php — consulta SNMP de cada impressora ativa e grava
// status + histórico. Roda em loop pelo container portal-impressoras-worker
// (20 min entre execuções — página/toner não muda rápido).
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/impressoras_lib.php';

global $pdo;

impressora_atualizar_todas($pdo);
