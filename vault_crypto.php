<?php
/**
 * Cofre TI — criptografia compartilhada
 * Usado por cofre.php (CRUD) e compartilhar.php (link público).
 * Requer config.php carregado antes (GLPI_APP_TOKEN).
 */

if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}

function vault_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}

function vault_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}
