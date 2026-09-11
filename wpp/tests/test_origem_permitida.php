<?php
// Testes do guardrail de ENTRADA. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';

// Nome longo de propósito: run.php dá require em TODOS os test_*.php no mesmo
// processo, então helper global com nome genérico colide com o do vizinho.
function _msg_origem_permitida(array $over = []): array {
    return array_merge([
        'id'        => 'MSGID1',
        'remoteJid' => '556799998888@s.whatsapp.net',
        'fromMe'    => false,
        'timestamp' => time(),
    ], $over);
}

// Quantas linhas de log de ENTRADA existem hoje pra esse JID.
function _log_in_count_origem_permitida(string $jid): int {
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM portal_wpp_log WHERE direcao = 'in' AND destino = ?");
    $st->execute([$jid]);
    return (int) $st->fetchColumn();
}

t_ok(wpp_origem_permitida(_msg_origem_permitida()), 'privado, recente, nao fromMe: permitida');

t_ok(!wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => '120363412180101593@g.us'])), 'grupo: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => 'status@broadcast'])), 'status broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => '123@broadcast'])), 'broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['fromMe' => true])), 'mensagem propria (fromMe): BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['timestamp' => time() - 121])), 'timestamp com 121s: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg_origem_permitida(['timestamp' => time() - 119])), 'timestamp com 119s: permitida (dentro da janela)');
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => '556799998888@newsletter'])), 'sufixo nao suportado: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => '556799998888@c.us'])), 'sufixo @c.us (legado): permitida');

// --- efeito colateral de LOG: o que é auditoria e o que é ruído ---

// grupo de TERCEIRO (fromMe=false): bloqueia E loga (rastro de auditoria)
$jid_grp = '120363412180101593@g.us';
$antes   = _log_in_count_origem_permitida($jid_grp);
wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => $jid_grp]));
t_eq(_log_in_count_origem_permitida($jid_grp) - $antes, 1, 'grupo de terceiro: gera 1 linha de bloqueio no log');

// eco do PRÓPRIO bot no grupo (fromMe=true): bloqueia em SILENCIO
$antes = _log_in_count_origem_permitida($jid_grp);
t_ok(!wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => $jid_grp, 'fromMe' => true])), 'eco proprio em grupo (fromMe): BLOQUEADA');
t_eq(_log_in_count_origem_permitida($jid_grp) - $antes, 0, 'eco proprio em grupo (fromMe): NAO loga (ruido normal)');

// status@broadcast: bloqueia em SILENCIO (ruído de altíssimo volume)
$jid_bc = 'status@broadcast';
$antes  = _log_in_count_origem_permitida($jid_bc);
wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => $jid_bc]));
t_eq(_log_in_count_origem_permitida($jid_bc) - $antes, 0, 'status@broadcast: NAO loga (ruido de alto volume)');

// sufixo desconhecido segue logando (é raro e vale investigar)
$jid_nl = '556799998888@newsletter';
$antes  = _log_in_count_origem_permitida($jid_nl);
wpp_origem_permitida(_msg_origem_permitida(['remoteJid' => $jid_nl]));
t_eq(_log_in_count_origem_permitida($jid_nl) - $antes, 1, 'sufixo nao suportado: segue gerando 1 linha no log');
