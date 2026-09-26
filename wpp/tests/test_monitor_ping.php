<?php
// Testes do motor de ping do Monitor de rede (etapa 2 — modo sombra).
// Roda com `php wpp/tests/run.php`. Ping real nunca é disparado aqui: tudo
// passa pelo seam $GLOBALS['__monitor_ping_fake'] e a rodada só mexe nos
// equipamentos de teste (grupo '__teste_ping__').
require_once __DIR__ . '/../../monitor_lib.php';
global $pdo;

// ---------------------------------------------------------------------------
// monitor_parse_latencia() — saída do ping do iputils
// ---------------------------------------------------------------------------
t_eq(monitor_parse_latencia("64 bytes from 192.168.2.22: icmp_seq=1 ttl=128 time=0.482 ms"), 0.48, 'parse_latencia: time=0.482 ms');
t_eq(monitor_parse_latencia("64 bytes from 192.168.2.22: icmp_seq=1 ttl=128 time=12 ms"), 12.0, 'parse_latencia: inteiro');
t_eq(monitor_parse_latencia("1 packets transmitted, 0 received, 100% packet loss"), null, 'parse_latencia: sem resposta -> null');

// ---------------------------------------------------------------------------
// monitor_aplicar_resultado() — pura. Grupo com tolerância 3 falhas / 2 sucessos.
// ---------------------------------------------------------------------------
$T = '2026-09-25 10:00:00';
$ap = fn(array $d, bool $ok, string $agora = '2026-09-25 10:00:00') => monitor_aplicar_resultado($d, $ok, 3, 2, $agora);
$up = ['status' => 'up', 'status_desde' => '2026-09-25 08:00:00', 'falhas_seguidas' => 0, 'sucessos_seguidos' => 0, 'falha_desde' => null];

$r = $ap($up, false);
t_eq([$r['status'], $r['falhas_seguidas'], $r['transicao']], ['up', 1, null], 'aplicar: 1 falha não derruba');
t_eq($r['falha_desde'], $T, 'aplicar: 1ª falha marca falha_desde');

$r2 = $ap($r, false, '2026-09-25 10:01:00');
t_eq([$r2['status'], $r2['falhas_seguidas'], $r2['falha_desde']], ['up', 2, $T], 'aplicar: 2 falhas não derrubam, falha_desde mantém a 1ª');

$r3 = $ap($r2, false, '2026-09-25 10:02:00');
t_eq([$r3['status'], $r3['transicao']], ['down', 'caiu'], 'aplicar: 3ª falha derruba (transição caiu)');
t_eq($r3['status_desde'], $T, 'aplicar: caído desde a 1ª falha, não desde a 3ª');

$curta = $ap($r2, true, '2026-09-25 10:02:00');
t_eq([$curta['status'], $curta['transicao'], $curta['falhas_seguidas']], ['up', null, 0], 'aplicar: voltou antes da tolerância -> continua up');
t_eq($curta['queda_curta'], ['inicio' => $T, 'fim' => '2026-09-25 10:02:00', 'falhas' => 2], 'aplicar: registra queda curta (reinício) com início/fim/falhas');
t_eq($curta['status_desde'], '2026-09-25 08:00:00', 'aplicar: queda curta não zera o "up desde"');

$down = ['status' => 'down', 'status_desde' => $T, 'falhas_seguidas' => 3, 'sucessos_seguidos' => 0, 'falha_desde' => $T];
$v1 = $ap($down, true, '2026-09-25 10:10:00');
t_eq([$v1['status'], $v1['sucessos_seguidos'], $v1['transicao']], ['down', 1, null], 'aplicar: 1 sucesso não levanta');
$v2 = $ap($v1, true, '2026-09-25 10:11:00');
t_eq([$v2['status'], $v2['transicao'], $v2['falhas_seguidas'], $v2['falha_desde']], ['up', 'voltou', 0, null], 'aplicar: 2º sucesso levanta (transição voltou) e limpa as falhas');
$vf = $ap($v1, false, '2026-09-25 10:11:00');
t_eq([$vf['status'], $vf['sucessos_seguidos']], ['down', 0], 'aplicar: falha no meio da volta zera os sucessos');

$novo = ['status' => 'desconhecido', 'status_desde' => null, 'falhas_seguidas' => 0, 'sucessos_seguidos' => 0, 'falha_desde' => null];
$n1 = $ap($novo, true);
t_eq([$n1['status'], $n1['transicao'], $n1['status_desde']], ['up', null, $T], 'aplicar: desconhecido + sucesso -> up na hora, sem transição');
$nf = $ap($ap($ap($novo, false), false), false);
t_eq([$nf['status'], $nf['transicao']], ['down', null], 'aplicar: desconhecido + 3 falhas -> down sem transição (não era up antes)');

// ---------------------------------------------------------------------------
// monitor_ping_lote() com seam — contrato de retorno
// ---------------------------------------------------------------------------
$GLOBALS['__monitor_ping_fake'] = fn(string $ip) => $ip === '10.255.254.1' ? 1.5 : null;
$lote = monitor_ping_lote(['10.255.254.1', '10.255.254.2']);
t_eq($lote['10.255.254.1'], 1.5, 'ping_lote: respondeu -> latência');
t_eq($lote['10.255.254.2'], null, 'ping_lote: não respondeu -> null');
t_eq(monitor_ping_lote([]), [], 'ping_lote: lista vazia -> []');

// ---------------------------------------------------------------------------
// monitor_rodada() — só nos equipamentos de teste (parâmetro $somenteIds)
// ---------------------------------------------------------------------------
if ($pdo instanceof PDO) {
    $G = '__teste_ping__';
    $limpa = function () use ($pdo, $G) {
        $pdo->prepare("DELETE q FROM portal_monitor_quedas_curtas q JOIN portal_monitor_dispositivos d ON d.id = q.dispositivo_id WHERE d.grupo = ?")->execute([$G]);
        $pdo->prepare("DELETE FROM portal_monitor_dispositivos WHERE grupo = ?")->execute([$G]);
        $pdo->prepare("DELETE FROM portal_monitor_grupos WHERE grupo = ?")->execute([$G]);
    };
    try {
        $limpa();
        monitor_grupo_salvar($pdo, $G, 'Teste ping', ['intervalo_seg' => 30, 'falhas_para_cair' => 2, 'sucessos_para_voltar' => 1, 'monitorar_novos' => 1]);
        $a = monitor_manual_criar($pdo, '__teste_ping_a__', '10.255.254.1', 'Lj 003', $G); // responde
        $b = monitor_manual_criar($pdo, '__teste_ping_b__', '10.255.254.2', 'Lj 003', $G); // não responde
        $ids = [$a, $b];

        $res = monitor_rodada($pdo, $ids);
        t_eq($res['pingados'], 2, 'rodada: pinga os 2 equipamentos de teste');
        $da = monitor_dispositivo($pdo, $a);
        t_eq([$da['status'], (float) $da['latencia_ms'], $da['ip_respondeu']], ['up', 1.5, '10.255.254.1'], 'rodada: quem respondeu fica up com latência e IP que respondeu');
        t_eq((int) monitor_dispositivo($pdo, $b)['falhas_seguidas'], 1, 'rodada: quem não respondeu soma 1 falha');

        $res2 = monitor_rodada($pdo, $ids);
        t_eq($res2['pingados'], 0, 'rodada: respeita o intervalo do grupo (não pinga de novo antes de 30 s)');

        $pdo->prepare("UPDATE portal_monitor_dispositivos SET ultimo_ping = NOW() - INTERVAL 60 SECOND WHERE id IN (?, ?)")->execute($ids);
        monitor_rodada($pdo, $ids);
        t_eq(monitor_dispositivo($pdo, $b)['status'], 'down', 'rodada: 2ª falha (tolerância do grupo = 2) -> down');

        // queda curta: a responde, falha 1x, volta
        $GLOBALS['__monitor_ping_fake'] = fn(string $ip) => null;
        $pdo->prepare("UPDATE portal_monitor_dispositivos SET ultimo_ping = NOW() - INTERVAL 60 SECOND WHERE id = ?")->execute([$a]);
        monitor_rodada($pdo, [$a]);
        $GLOBALS['__monitor_ping_fake'] = fn(string $ip) => 2.0;
        $pdo->prepare("UPDATE portal_monitor_dispositivos SET ultimo_ping = NOW() - INTERVAL 60 SECOND WHERE id = ?")->execute([$a]);
        monitor_rodada($pdo, [$a]);
        $st = $pdo->prepare("SELECT COUNT(*) FROM portal_monitor_quedas_curtas WHERE dispositivo_id = ?");
        $st->execute([$a]);
        t_eq((int) $st->fetchColumn(), 1, 'rodada: queda curta gravada em portal_monitor_quedas_curtas');
        t_eq(monitor_dispositivo($pdo, $a)['status'], 'up', 'rodada: depois da queda curta continua up');

        // equipamento desligado (Monitorar = não) não é pingado
        monitor_set_monitorar($pdo, $b, false);
        $pdo->prepare("UPDATE portal_monitor_dispositivos SET ultimo_ping = NOW() - INTERVAL 60 SECOND WHERE id IN (?, ?)")->execute($ids);
        t_eq(monitor_rodada($pdo, $ids)['pingados'], 1, 'rodada: Monitorar desligado não é pingado');
    } finally {
        unset($GLOBALS['__monitor_ping_fake']);
        $limpa();
    }
}
unset($GLOBALS['__monitor_ping_fake']);
