<?php
// Testes de dedup de mensagens recebidas. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
global $pdo;

$idTeste = 'TESTE_MSG_' . bin2hex(random_bytes(6));

try {
    t_ok(!wpp_msg_ja_vista($idTeste), 'mensagem nova: ainda nao vista');

    wpp_marcar_msg_vista($idTeste);
    t_ok(wpp_msg_ja_vista($idTeste), 'apos marcar: ja vista');

    // idempotente: marcar 2x nao lanca (INSERT IGNORE / ON DUPLICATE)
    wpp_marcar_msg_vista($idTeste);
    t_ok(wpp_msg_ja_vista($idTeste), 'marcar 2x nao quebra');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_msgs_vistas WHERE message_id = " . $pdo->quote($idTeste));
}
