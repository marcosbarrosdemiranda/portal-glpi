<?php
// Guardrail de saída do módulo WhatsApp.
// Toda mensagem outbound tem que passar por evo_guarded_send(), que só
// libera o envio se wpp_destino_permitido() aprovar o destino.
// Sem HTML, sem session_start, nunca lança.
require_once __DIR__ . '/db.php';

// Normaliza telefone: remove tudo que não for dígito. '' se vazio/nulo.
function wpp_norm_telefone(string $v): string {
    return preg_replace('/\D+/', '', $v) ?? '';
}

// Decide se um destino pode receber mensagem.
// $destino pode ser um JID de grupo ('...@g.us') ou um número
// (só dígitos, ou 'digitos@s.whatsapp.net').
function wpp_destino_permitido(string $destino): bool {
    $d = trim($destino);

    // agenda/db.php usa PDO::ERRMODE_EXCEPTION. Qualquer falha de banco em
    // wpp_cfg_get() ou nas consultas abaixo tem que ser contida aqui: fail closed
    // (bloqueia) pra não violar o contrato "nunca lança" do módulo.
    try {
        // vazio ou qualquer coisa com 'broadcast' (status@broadcast etc.) -> bloqueia
        if ($d === '' || stripos($d, 'broadcast') !== false) {
            wpp_log('out', $d, 'destino bloqueado', 'bloqueado');
            return false;
        }

        // JID de grupo: só passa se for exatamente um dos 2 grupos configurados
        if (str_ends_with($d, '@g.us')) {
            $grupos = array_filter([
                wpp_cfg_get('grupo_alertas_jid', ''),
                wpp_cfg_get('grupo_chamados_jid', ''),
            ]);
            if (in_array($d, $grupos, true)) {
                return true;
            }
            wpp_log('out', $d, 'grupo nao cadastrado', 'bloqueado');
            return false;
        }

        // JID com sufixo não suportado (ex: '...@newsletter', '...@lid'):
        // wpp_norm_telefone() jogaria fora o sufixo e aprovaria os dígitos,
        // mas evo_destino_payload() manda o destino VERBATIM pro sufixo errado.
        // (@g.us já foi tratado acima.)
        if (strpos($d, '@') !== false
            && !str_ends_with($d, '@s.whatsapp.net')
            && !str_ends_with($d, '@c.us')) {
            wpp_log('out', $d, 'sufixo JID nao suportado', 'bloqueado');
            return false;
        }

        // número: normaliza e checa nas duas tabelas de allowlist (ativo=1)
        $tel = wpp_norm_telefone(str_replace('@s.whatsapp.net', '', $d));
        if ($tel === '') {
            wpp_log('out', $d, 'numero invalido', 'bloqueado');
            return false;
        }

        global $pdo;
        $st = $pdo->prepare(
            "SELECT 1 FROM portal_wpp_contatos WHERE telefone = ? AND ativo = 1
             UNION SELECT 1 FROM portal_wpp_autorizados WHERE telefone = ? AND ativo = 1 LIMIT 1"
        );
        $st->execute([$tel, $tel]);
        if ($st->fetchColumn()) {
            return true;
        }

        wpp_log('out', $d, 'numero nao cadastrado', 'bloqueado');
        return false;
    } catch (\Throwable $e) {
        wpp_log('out', $d, 'erro na verificacao: ' . $e->getMessage(), 'bloqueado');
        return false;
    }
}

// Único ponto de saída de mensagem. Se o destino não é permitido, NÃO chama
// $enviar. Caso contrário chama $enviar() dentro de try/catch, loga o
// resultado e devolve o array do callable.
function evo_guarded_send(string $destino, callable $enviar, string $resumo): array {
    if (!wpp_destino_permitido($destino)) {
        return ['ok' => false, 'bloqueado' => true];
    }
    try {
        $r = $enviar();
    } catch (\Throwable $e) {
        wpp_log('out', $destino, $resumo . ' :: ' . $e->getMessage(), 'erro');
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
    wpp_log('out', $destino, $resumo, !empty($r['ok']) ? 'ok' : 'erro');
    return is_array($r) ? $r : ['ok' => false];
}

// Guardrail de ENTRADA: decide se uma mensagem recebida pode acionar o
// chatbot. $msg é o array normalizado de wpp_extrair_msg() (webhook_parse.php).
// Nunca lança — qualquer falha aqui tem que fechar (bloquear), nunca abrir.
function wpp_origem_permitida(array $msg): bool {
    try {
        $jid = (string) ($msg['remoteJid'] ?? '');

        // 1º de tudo: mensagem do próprio bot ecoada de volta — não loga, é
        // ruído normal. Vem ANTES da checagem de privado de propósito: tudo
        // que o worker posta nos 2 grupos volta como fromMe=true + @g.us, e
        // isso não é tentativa de acesso, é o nosso próprio eco.
        if (!empty($msg['fromMe'])) {
            return false;
        }

        // só chat privado — @g.us (grupo), @broadcast e qualquer outro sufixo
        // (ex. @newsletter, @lid) ficam de fora por não estarem na allowlist abaixo
        $privado = str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us');
        if (!$privado) {
            // @broadcast (status@broadcast de qualquer contato da agenda) é
            // ruído de altíssimo volume, não evento de segurança: bloqueia em
            // silêncio pra não inundar o log de auditoria.
            if (str_ends_with($jid, '@broadcast')) {
                return false;
            }
            wpp_log('in', $jid, 'origem nao privada (grupo/broadcast/outro)', 'bloqueado');
            return false;
        }

        $ts = (int) ($msg['timestamp'] ?? 0);
        if ($ts < time() - 120) {
            wpp_log('in', $jid, 'timestamp antigo (replay/history-sync)', 'bloqueado');
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        wpp_log('in', (string) ($msg['remoteJid'] ?? '?'), 'erro na verificacao: ' . $e->getMessage(), 'bloqueado');
        return false;
    }
}
