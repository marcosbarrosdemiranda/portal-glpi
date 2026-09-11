<?php
// Testes do guardrail de ENTRADA. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';

function _msg(array $over = []): array {
    return array_merge([
        'id'        => 'MSGID1',
        'remoteJid' => '556799998888@s.whatsapp.net',
        'fromMe'    => false,
        'timestamp' => time(),
    ], $over);
}

t_ok(wpp_origem_permitida(_msg()), 'privado, recente, nao fromMe: permitida');

t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '120363412180101593@g.us'])), 'grupo: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => 'status@broadcast'])), 'status broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '123@broadcast'])), 'broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['fromMe' => true])), 'mensagem propria (fromMe): BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['timestamp' => time() - 121])), 'timestamp com 121s: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg(['timestamp' => time() - 119])), 'timestamp com 119s: permitida (dentro da janela)');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '556799998888@newsletter'])), 'sufixo nao suportado: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg(['remoteJid' => '556799998888@c.us'])), 'sufixo @c.us (legado): permitida');
