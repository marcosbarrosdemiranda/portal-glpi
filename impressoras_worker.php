<?php
// impressoras_worker.php — consulta SNMP de cada impressora ativa e grava
// status + histórico. Roda em loop pelo container portal-impressoras-worker
// (20 min entre execuções — página/toner não muda rápido).
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/impressoras_lib.php';

global $pdo;

foreach (impressora_listar($pdo) as $imp) {
    try {
        $consulta = impressora_snmp_consultar($imp['ip'], $imp['comunidade']);
        impressora_status_salvar($pdo, (int) $imp['id'], $consulta);
    } catch (\Throwable $e) {
        // 1 impressora falhando (rede fora, IP mudou etc.) nao pode
        // impedir a consulta das outras.
        error_log('impressoras_worker: falha ao consultar ' . $imp['ip'] . ': ' . $e->getMessage());
    }
}
