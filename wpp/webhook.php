<?php
// Endpoint chamado pela Evolution API (webhook da instância portal_ti).
// Responde 200 SEMPRE e rápido — a Evolution reentrega em loop se não for 2xx.
// Fase 3 Etapa 1: só valida a origem e loga. O chatbot em si (FSM, fluxos)
// entra na Etapa 2 — troca o wpp_log() do bloco "recebido" por
// wpp_chatbot_processar($msg['remoteJid'], $msg).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guardrails.php';
require_once __DIR__ . '/webhook_parse.php';

header('Content-Type: application/json');

$raw     = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);

try {
    if (is_array($payload)) {
        $evento = (string) ($payload['event'] ?? '');

        if ($evento === 'connection.update' || $evento === 'CONNECTION_UPDATE') {
            $estado = $payload['data']['state'] ?? null;
            if (is_string($estado) && $estado !== '') {
                global $pdo;
                wpp_cfg_set('conn_status', $estado);
                wpp_cfg_set('conn_ultima', wpp_agora_db($pdo));
            }
        } elseif ($evento === 'messages.upsert' || $evento === 'MESSAGES_UPSERT') {
            if (wpp_cfg_get('on_chatbot', '0') === '1') {
                $msg = wpp_extrair_msg($payload);
                if ($msg !== null && !wpp_msg_ja_vista($msg['id'])) {
                    wpp_marcar_msg_vista($msg['id']);
                    if (wpp_origem_permitida($msg)) {
                        // Etapa 1: chatbot ainda não existe. Etapa 2 troca esta
                        // linha por: wpp_chatbot_processar($msg['remoteJid'], $msg);
                        wpp_log('in', $msg['remoteJid'], 'recebido (chatbot ainda nao implementado)', 'ok');
                    }
                    // origem não permitida: wpp_origem_permitida() já logou o bloqueio
                }
                // já visto (reentrega) ou payload não reconhecido: silencioso, de propósito
            }
        }
    }
} catch (\Throwable $e) {
    // nunca deixa o webhook cair em erro pra Evolution — só registra
    wpp_log('sys', 'webhook', 'erro: ' . $e->getMessage(), 'erro');
}

http_response_code(200);
echo json_encode(['ok' => true]);
