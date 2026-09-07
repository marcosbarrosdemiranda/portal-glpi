<?php
/**
 * ping.php — Verifica se um IP está acessível na rede.
 *
 * GET ?ip=192.168.x.x   →   {"online": true|false, "via": "icmp"|"tcp:445"|null}
 *
 * 1) ICMP (mais universal — a maioria dos Windows/impressoras/DVR responde)
 * 2) fallback TCP em portas comuns (host que bloqueia ICMP mas tem serviço)
 *
 * OBS: em produção roda no container Linux (php:8.2-apache). O binário `ping`
 * vem do pacote iputils-ping no Dockerfile. Antes do corte pro Docker era XAMPP
 * no Windows (ping.exe nativo) — por isso o ICMP "parou de funcionar" em jul/2026.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$ip = trim($_GET['ip'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['online' => false, 'erro' => 'IP inválido']);
    exit;
}

$online = false;
$via    = null;

// ── 1) ICMP ───────────────────────────────────────────────────
$ip_safe = escapeshellarg($ip);
$isWin   = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$cmd     = $isWin ? "ping -n 1 -w 1000 $ip_safe" : "ping -c 1 -W 1 $ip_safe 2>/dev/null";
@exec($cmd, $out, $code);
if ($code === 0) { $online = true; $via = 'icmp'; }

// ── 2) fallback TCP ───────────────────────────────────────────
if (!$online) {
    foreach ([445, 3389, 135, 139, 80, 443, 22] as $porta) {
        $conn = @fsockopen($ip, $porta, $errno, $errstr, 0.5);
        if ($conn !== false) {
            fclose($conn);
            $online = true;
            $via    = 'tcp:' . $porta;
            break;
        }
    }
}

echo json_encode(['online' => $online, 'via' => $via]);
