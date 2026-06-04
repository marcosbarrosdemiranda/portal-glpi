<?php
/**
 * ping.php — Verifica se um IP está acessível na rede
 *
 * GET ?ip=192.168.x.x
 * Retorna: {"online": true|false}
 *
 * Método 1: TCP socket porta 445 (SMB — sempre aberta em Windows ligado)
 * Método 2: fallback ICMP ping via exec()
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$ip = trim($_GET['ip'] ?? '');

// Valida IP
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['online' => false, 'erro' => 'IP inválido']);
    exit;
}

$online = false;

// ── Método 1: TCP socket (porta 445 = SMB Windows) ────────────
// Timeout de 1 segundo — muito mais rápido que ping ICMP em host offline
$conn = @fsockopen($ip, 445, $errno, $errstr, 1);
if ($conn !== false) {
    fclose($conn);
    $online = true;
}

// ── Método 2: fallback ICMP ping ──────────────────────────────
if (!$online) {
    $ip_safe = escapeshellarg($ip);

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows (XAMPP produção)
        exec("ping -n 1 -w 1000 {$ip_safe}", $out, $code);
    } else {
        // Linux (Docker dev)
        exec("ping -c 1 -W 1 {$ip_safe}", $out, $code);
    }

    $online = ($code === 0);
}

echo json_encode(['online' => $online]);
