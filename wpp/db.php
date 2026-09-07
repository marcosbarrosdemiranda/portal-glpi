<?php
// Acesso ao banco pro módulo WhatsApp. Reusa o $pdo do portal.
require_once __DIR__ . '/../agenda/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_config (
    chave VARCHAR(64) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function wpp_cfg_get(string $chave, ?string $default = null): ?string {
    global $pdo;
    $st = $pdo->prepare("SELECT valor FROM portal_wpp_config WHERE chave = ?");
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function wpp_cfg_set(string $chave, string $valor): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_config (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    $st->execute([$chave, $valor]);
}
