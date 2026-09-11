<?php
// Parser puro do payload de webhook da Evolution API. Sem HTML, sem banco,
// nunca lança — payload não reconhecido devolve null, quem chama decide o
// que fazer (webhook.php simplesmente ignora).

// Normaliza o payload de um evento MESSAGES_UPSERT pro formato que
// wpp_origem_permitida() e o chatbot esperam. null se não reconhecer.
function wpp_extrair_msg(array $payload): ?array {
    $data = $payload['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }

    // fallback: alguns eventos vêm como data.messages[0] (formato Baileys puro)
    // em vez do objeto de mensagem direto (formato Evolution v2).
    if (isset($data['messages']) && is_array($data['messages'])) {
        $data = $data['messages'][0] ?? null;
        if (!is_array($data)) {
            return null;
        }
    }

    $key = $data['key'] ?? null;
    if (!is_array($key)) {
        return null;
    }

    $remoteJid = (string) ($key['remoteJid'] ?? '');
    $id        = (string) ($key['id'] ?? '');
    if ($remoteJid === '' || $id === '') {
        return null;
    }

    $ts = $data['messageTimestamp'] ?? null;
    if (is_array($ts)) {
        // protobuf Long serializado ({low, high, unsigned})
        $ts = $ts['low'] ?? 0;
    }
    $ts = (int) $ts;
    if ($ts <= 0) {
        // sem timestamp confiável: trata como agora, nunca como "antigo"
        // (senão wpp_origem_permitida descartaria por engano)
        $ts = time();
    }

    $message = is_array($data['message'] ?? null) ? $data['message'] : [];

    return [
        'id'        => $id,
        'remoteJid' => $remoteJid,
        'fromMe'    => (bool) ($key['fromMe'] ?? false),
        'timestamp' => $ts,
        'texto'     => wpp_extrair_texto($message),
        'temMidia'  => isset($message['imageMessage']),
    ];
}

// Extrai o texto de qualquer um dos formatos de mensagem que o bot precisa
// entender: texto simples, texto citado/longo, e a resposta de uma lista
// interativa ou de botões.
function wpp_extrair_texto(array $message): string {
    return (string) (
        $message['conversation']
        ?? $message['extendedTextMessage']['text']
        ?? $message['listResponseMessage']['title']
        ?? $message['buttonsResponseMessage']['selectedDisplayText']
        ?? ''
    );
}
