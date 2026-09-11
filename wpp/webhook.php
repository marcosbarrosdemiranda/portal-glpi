<?php
// Endpoint chamado pela Evolution API (webhook da instância portal_ti).
// Responde 200 SEMPRE e rápido — a Evolution reentrega em loop se não for 2xx.
// Isso vale INCLUSIVE quando o boot falha (glpi-db fora do ar): os require
// ficam dentro de try/catch próprio e o 200 sai do mesmo jeito. Sem isso,
// agenda/db.php (new PDO com ERRMODE_EXCEPTION, sem catch) derrubaria o
// arquivo em fatal error antes do try principal -> HTTP 500 em produção.
// Endpoint público: autenticado pelo segredo compartilhado WPP_WEBHOOK_SECRET
// (header X-Wpp-Secret que a Evolution reenvia) + conferência da instância.
// Fase 3 Etapa 2: chatbot ligado. Mensagem de número vinculado e permitido
// entra na FSM via wpp_chatbot_processar(wpp_norm_telefone($msg['remoteJid']), $msg).
$boot_ok = true;
try {
    // require de arquivo inexistente é fatal NÃO capturável — checa antes.
    // config.php é gitignored: sem ele não há EVO_INSTANCE nem segredo pra
    // conferir, então o endpoint vira no-op (e ainda assim responde 200).
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new \RuntimeException('wpp/config.php não encontrado');
    }
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/guardrails.php';
    require_once __DIR__ . '/webhook_parse.php';
    require_once __DIR__ . '/evo_api.php';
    require_once __DIR__ . '/glpi_bot.php';
    require_once __DIR__ . '/chatbot.php';
} catch (\Throwable $e) {
    $boot_ok = false;
    // wpp_log() precisa do banco que acabou de falhar — não pode ser usado aqui.
    error_log('wpp/webhook: boot falhou: ' . $e->getMessage());
}

header('Content-Type: application/json');

if ($boot_ok) {
    $raw     = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);

    try {
        // Autenticação, ANTES de olhar o conteúdo do payload:
        //  - segredo compartilhado no header X-Wpp-Secret (gate primário);
        //  - campo "instance" do payload igual a EVO_INSTANCE (defesa em
        //    profundidade).
        // Request reprovado é descartado em SILENCIO — nada de status code
        // diferente, o 200 sai igual pra não sinalizar sucesso/falha a quem
        // estiver sondando o endpoint.
        $autenticado = wpp_webhook_secret_ok($_SERVER['HTTP_X_WPP_SECRET'] ?? null)
            && wpp_evento_da_instancia(is_array($payload) ? $payload : []);

        if ($autenticado && is_array($payload)) {
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
                            // wpp_chatbot_processar() guarda/lê estado em
                            // portal_wpp_conversas.telefone comparando o valor
                            // cru (sem normalizar) — precisa receber só dígitos,
                            // não o JID completo (achado no smoke test desta
                            // task: JID com sufixo @s.whatsapp.net truncava a
                            // coluna e quebrava o casamento com
                            // wpp_destino_permitido(), que já compara normalizado).
                            // Log de auditoria da entrada (mesmo papel do que a
                            // Etapa 1 tinha): sem ele, "mandei mensagem e não
                            // aconteceu nada" fica indiagnosticável no
                            // portal_wpp_log. Destino = JID cru, igual aos logs
                            // de bloqueio; texto truncado em 80 chars.
                            wpp_log('in', $msg['remoteJid'], mb_substr((string) ($msg['texto'] ?? ''), 0, 80), 'ok');
                            wpp_chatbot_processar(wpp_norm_telefone($msg['remoteJid']), $msg);
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
}

http_response_code(200);
echo json_encode(['ok' => true]);
