<?php
/**
 * unifi_client.php — cliente da API da controladora UniFi
 * (Cloud Key / software controller clássico, porta 8443).
 * Sem HTML/sessão — só HTTP + parsing. Usado por inventario_redes.php.
 */

function unifi_login(string $url, string $usuario, string $senha): array {
    $url        = rtrim($url, '/');
    $cookieFile = tempnam(sys_get_temp_dir(), 'unifi_');

    $ch = curl_init($url . '/api/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['username' => $usuario, 'password' => $senha]),
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        @unlink($cookieFile);
        return ['ok' => false, 'cookieFile' => null, 'msg' => 'Falha de conexão com a controladora'];
    }

    $data = json_decode($body, true);
    $rc   = $data['meta']['rc'] ?? '';
    if ($code !== 200 || $rc !== 'ok') {
        @unlink($cookieFile);
        return ['ok' => false, 'cookieFile' => null, 'msg' => 'Login falhou (usuário/senha inválidos ou HTTP ' . $code . ')'];
    }

    return ['ok' => true, 'cookieFile' => $cookieFile, 'msg' => 'OK'];
}

function unifi_testar_login(string $url, string $usuario, string $senha): array {
    $login = unifi_login($url, $usuario, $senha);
    if ($login['cookieFile']) @unlink($login['cookieFile']);
    return ['ok' => $login['ok'], 'msg' => $login['msg']];
}

function unifi_listar_aps(string $url, string $usuario, string $senha, string $site = 'default'): array {
    $login = unifi_login($url, $usuario, $senha);
    if (!$login['ok']) return ['erro' => $login['msg']];

    $urlBase = rtrim($url, '/');
    $ch = curl_init($urlBase . '/api/s/' . rawurlencode($site) . '/stat/device');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE     => $login['cookieFile'],
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($login['cookieFile']);

    if ($errno) return ['erro' => 'Falha de conexão com a controladora'];
    if ($code !== 200) return ['erro' => 'Controladora retornou HTTP ' . $code];

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return ['erro' => 'Resposta inesperada da controladora'];
    }

    $aps = [];
    foreach ($data['data'] as $dev) {
        if (($dev['type'] ?? '') !== 'uap') continue;
        $aps[] = [
            'nome'       => (string)($dev['name'] ?? ($dev['model'] ?? 'AP sem nome')),
            'modelo'     => (string)($dev['model'] ?? ''),
            'mac'        => (string)($dev['mac'] ?? ''),
            'ip'         => (string)($dev['ip'] ?? ''),
            'status'     => ((int)($dev['state'] ?? 0) === 1) ? 'online' : 'offline',
            'clientes'   => (int)($dev['num_sta'] ?? 0),
            'uptime_seg' => (int)($dev['uptime'] ?? 0),
        ];
    }

    return $aps;
}
