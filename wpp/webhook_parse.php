<?php
// Parser puro do payload de webhook da Evolution API. Sem HTML, sem banco,
// nunca lança — payload não reconhecido devolve null, quem chama decide o
// que fazer (webhook.php simplesmente ignora).

// Autenticação do webhook: confere o segredo compartilhado que a Evolution
// reenvia em todo delivery (header "X-Wpp-Secret", registrado por
// evo_set_webhook). $recebido é $_SERVER['HTTP_X_WPP_SECRET'] ?? null.
// Se WPP_WEBHOOK_SECRET não estiver definida (ou estiver vazia), a checagem é
// PULADA — gap aceito durante o rollout, pra não derrubar tráfego legítimo por
// causa de um wpp/config.php que o operador ainda não atualizou.
// hash_equals: comparação em tempo constante, sem vazar o segredo por timing.
function wpp_webhook_secret_ok(?string $recebido): bool {
    if (!defined('WPP_WEBHOOK_SECRET') || WPP_WEBHOOK_SECRET === '') {
        return true;
    }
    if (!is_string($recebido)) {
        return false;
    }
    return hash_equals((string) WPP_WEBHOOK_SECRET, $recebido);
}

// Defesa em profundidade (o gate primário é o segredo acima): o payload da
// Evolution diz de qual instância veio o evento. Se vier e não for a nossa,
// descarta. Se não vier (versões da Evolution que não mandam o campo), não
// bloqueia — melhor aceitar do que cortar tráfego real por diferença de versão.
function wpp_evento_da_instancia(array $payload): bool {
    $inst = $payload['instance'] ?? null;
    if (!is_string($inst) || $inst === '') {
        return true;
    }
    if (!defined('EVO_INSTANCE')) {
        return true;
    }
    return $inst === EVO_INSTANCE;
}

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
