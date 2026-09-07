<?php
// Cliente REST da Evolution API. Sem HTML, sem sessão. Nunca lança.
// Erro amigável se o operador pulou a etapa do runbook (config.php é gitignored).
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('wpp/config.php não encontrado — crie a partir de wpp/config.example.php (ver wpp/README.md).');
}
require_once __DIR__ . '/config.php';

function evo_url(string $path): string {
    return rtrim(EVO_URL, '/') . '/' . ltrim($path, '/');
}

function evo_request(string $method, string $path, ?array $json = null, int $timeout = 20): array {
    $ch = curl_init(evo_url($path));
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'erro' => 'URL inválida (EVO_URL)'];
    }
    $headers = ['apikey: ' . EVO_API_KEY];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'erro' => "cURL: $err"];
    }
    $data = json_decode((string) $body, true);
    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => $data,
        'erro'   => ($status >= 400) ? (is_array($data) ? json_encode($data) : (string) $body) : null,
    ];
}

function evo_status(): array {
    $r = evo_request('GET', '/instance/connectionState/' . EVO_INSTANCE, null, 10);
    $estado = $r['data']['instance']['state'] ?? ($r['data']['state'] ?? 'desconhecido');
    return ['ok' => $r['ok'], 'estado' => is_string($estado) ? $estado : 'desconhecido'];
}

function evo_qr(): array {
    $r = evo_request('GET', '/instance/connect/' . EVO_INSTANCE, null, 20);
    $b64 = $r['data']['base64'] ?? ($r['data']['qrcode']['base64'] ?? null);
    return ['ok' => $r['ok'] && $b64 !== null, 'base64' => $b64, 'erro' => $r['erro']];
}

function evo_groups(): array {
    $r = evo_request('GET', '/group/fetchAllGroups/' . EVO_INSTANCE . '?getParticipants=false', null, 30);
    $lista = [];
    foreach ((is_array($r['data']) ? $r['data'] : []) as $g) {
        if (!isset($g['id'])) continue;
        $lista[] = [
            'jid'      => $g['id'],
            'nome'     => is_string($g['subject'] ?? null) ? $g['subject'] : '(sem nome)',
            'tamanho'  => (int) ($g['size'] ?? 0),
        ];
    }
    usort($lista, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return ['ok' => $r['ok'], 'grupos' => $lista, 'erro' => $r['erro']];
}

function evo_logout(): array {
    $r = evo_request('DELETE', '/instance/logout/' . EVO_INSTANCE, null, 15);
    return ['ok' => $r['ok'], 'erro' => $r['erro']];
}

function evo_ensure_instance(): array {
    $r = evo_request('GET', '/instance/fetchInstances', null, 15);
    if (!$r['ok']) return ['ok' => false, 'criada' => false, 'erro' => $r['erro'] ?? 'falha ao listar instâncias'];

    foreach ((is_array($r['data']) ? $r['data'] : []) as $inst) {
        $nome = $inst['name'] ?? ($inst['instance']['instanceName'] ?? null);
        if ($nome === EVO_INSTANCE) return ['ok' => true, 'criada' => false, 'erro' => null];
    }

    $c = evo_request('POST', '/instance/create', [
        'instanceName'    => EVO_INSTANCE,
        'qrcode'          => true,
        'integration'     => 'WHATSAPP-BAILEYS',
        'groupsIgnore'    => true,
        'alwaysOnline'    => false,
        'readMessages'    => false,
        'readStatus'      => false,
        'syncFullHistory' => false,
    ], 30);
    return ['ok' => $c['ok'], 'criada' => $c['ok'], 'erro' => $c['erro']];
}
