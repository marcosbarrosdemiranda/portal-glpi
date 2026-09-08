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
