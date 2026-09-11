<?php
// Cliente REST da Evolution API. Sem HTML, sem sessão. Nunca lança.
// Erro amigável se o operador pulou a etapa do runbook (config.php é gitignored).
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('wpp/config.php não encontrado — crie a partir de wpp/config.example.php (ver wpp/README.md).');
}
require_once __DIR__ . '/config.php';
// Guardrail de saída: evo_send_* roteia por evo_guarded_send() (guardrails.php puxa db.php).
require_once __DIR__ . '/guardrails.php';

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

// Converte o destino pro formato que a Evolution espera no campo "number":
// JID (tem '@', ex: grupo '...@g.us') passa como veio; caso contrário
// normaliza os dígitos e vira "<digitos>@s.whatsapp.net".
function evo_destino_payload(string $destino): string {
    if (strpos($destino, '@') !== false) {
        return $destino;
    }
    return wpp_norm_telefone($destino) . '@s.whatsapp.net';
}

// Envia texto simples. Passa o $destino ORIGINAL pro guardrail (ele lida com
// dígitos e com JID de grupo); o número normalizado vai só no payload.
function evo_send_text(string $destino, string $texto): array {
    $number = evo_destino_payload($destino);
    return evo_guarded_send(
        $destino,
        fn() => evo_request('POST', '/message/sendText/' . EVO_INSTANCE, [
            'number' => $number,
            'text'   => $texto,
        ]),
        mb_substr($texto, 0, 80)
    );
}

// Envia mídia (imagem) com legenda. $base64 é o conteúdo do arquivo em base64.
function evo_send_media(
    string $destino,
    string $base64,
    string $legenda,
    string $mime = 'image/jpeg',
    string $nome = 'arquivo'
): array {
    $number = evo_destino_payload($destino);
    return evo_guarded_send(
        $destino,
        fn() => evo_request('POST', '/message/sendMedia/' . EVO_INSTANCE, [
            'number'    => $number,
            'mediatype' => 'image',
            'mimetype'  => $mime,
            'caption'   => $legenda,
            'media'     => $base64,
            'fileName'  => $nome,
        ]),
        'media: ' . mb_substr($legenda, 0, 60)
    );
}

// Liga/desliga o webhook de mensagens da instância. Sem WPP_WEBHOOK_URL
// definida (config.php desatualizado), falha limpo sem tentar a rede.
function evo_set_webhook(bool $ligar): array {
    if ($ligar && (!defined('WPP_WEBHOOK_URL') || WPP_WEBHOOK_URL === '')) {
        return ['ok' => false, 'erro' => 'WPP_WEBHOOK_URL não configurada em wpp/config.php'];
    }
    $body = $ligar
        ? ['webhook' => [
              'enabled'         => true,
              'url'             => WPP_WEBHOOK_URL,
              'webhookByEvents' => false,
              // Segredo compartilhado: a Evolution reenvia estes headers em
              // todo delivery; wpp/webhook.php confere com hash_equals.
              'headers'         => [
                  'X-Wpp-Secret' => defined('WPP_WEBHOOK_SECRET') ? WPP_WEBHOOK_SECRET : '',
              ],
              'events'          => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
          ]]
        : ['webhook' => ['enabled' => false]];
    $r = evo_request('POST', '/webhook/set/' . EVO_INSTANCE, $body, 15);
    return ['ok' => $r['ok'], 'erro' => $r['erro']];
}

// Estado atual do webhook na Evolution (pra pintar o botão na tela).
function evo_webhook_status(): array {
    $r = evo_request('GET', '/webhook/find/' . EVO_INSTANCE, null, 10);
    $ativo = is_array($r['data']) && !empty($r['data']['enabled']);
    return ['ok' => $r['ok'], 'ativo' => $ativo, 'erro' => $r['erro']];
}
