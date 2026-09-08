<?php
// Testa alertas_lib.php — só verifica que as funções rodam e retornam array.
// Precisa de banco → roda no deploy (php wpp/tests/run.php).
require_once __DIR__ . '/../../alertas_lib.php';
require_once __DIR__ . '/../../agenda/db.php';
global $pdo;

t_ok(is_array(alertas_sem_inventario($pdo, 7)), 'alertas_sem_inventario retorna array');
t_ok(is_array(alertas_disco_cheio($pdo, 90)), 'alertas_disco_cheio retorna array');

$s = alertas_snapshot($pdo);
t_ok(isset($s['sem_inventario']) && isset($s['disco_cheio']), 'snapshot tem as 2 chaves');
