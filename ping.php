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

// ── Método 1: TCP socket em portas comuns de Windows ligado ────
// 445 SMB · 3389 RDP · 135 RPC · 139 NetBIOS. Timeout curto (0.7s) por porta.
foreach ([445, 3389, 135, 139] as $porta) {
    $conn = @fsockopen($ip, $porta, $errno, $errstr, 0.7);
    if ($conn !== false) {
        fclose($conn);
        $online = true;
        break;
    }
}

// ── Método 2: fallback ICMP ping (quando o binário existe) ─────
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
