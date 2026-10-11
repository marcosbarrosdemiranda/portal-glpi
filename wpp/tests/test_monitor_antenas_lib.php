<?php
// Testes de monitor_antenas_lib.php + dos 3 checks de antena em alertas_tipos.php.
// Roda com `php wpp/tests/run.php` (precisa de banco). SSH real NUNCA é disparado
// aqui: tudo passa pelo seam $GLOBALS['__monitor_antena_ssh_fake'] (mesmo padrão de
// $GLOBALS['__monitor_ping_fake'] em monitor_lib.php) e a varredura só mexe numa
// antena de teste isolada (nome '__teste_antena__').
require_once __DIR__ . '/../../alertas_tipos.php';
global $pdo;

// ---------------------------------------------------------------------------
// antena_ocorrencias_*() — puras, sem banco/rede (filtros usados pelos 3 checks)
// ---------------------------------------------------------------------------
$offline = antena_ocorrencias_offline([
    ['id' => 1, 'nome' => 'Loja A', 'ip' => '10.0.0.1', 'status' => 'offline', 'ultima_verificacao' => '2026-10-10 08:00:00'],
    ['id' => 2, 'nome' => 'Loja B', 'ip' => '10.0.0.2', 'status' => 'online'],
]);
t_eq(count($offline), 1, 'ocorrencias_offline: só a antena offline gera ocorrência');
t_eq($offline[0]['chave'], 'antena_offline:1', 'ocorrencias_offline: chave é antena_offline:<id>');

t_eq(antena_ocorrencias_offline([['id' => 1, 'status' => 'online']]), [], 'ocorrencias_offline: online -> nenhuma ocorrência');
t_eq(antena_ocorrencias_offline([['id' => 1, 'status' => 'desconhecido']]), [], 'ocorrencias_offline: desconhecido -> nenhuma ocorrência (não é offline confirmado)');

$reiniciou = antena_ocorrencias_reinicio([
    ['id' => 5, 'nome' => 'Loja C', 'uptime_segundos' => 120, 'uptime_anterior' => 50000, 'ultima_verificacao' => '2026-10-10 09:00:00'],
]);
t_eq(count($reiniciou), 1, 'ocorrencias_reinicio: uptime caiu -> gera ocorrência');
t_eq($reiniciou[0]['chave'], 'antena_reinicio:5:2026-10-10 09:00:00', 'ocorrencias_reinicio: chave inclui id + timestamp (não fica presa num reinício só)');

t_eq(antena_ocorrencias_reinicio([['id' => 5, 'uptime_segundos' => 200, 'uptime_anterior' => 120]]), [], 'ocorrencias_reinicio: uptime subiu -> nenhuma ocorrência (sem reinício)');
t_eq(antena_ocorrencias_reinicio([['id' => 5, 'uptime_segundos' => 120, 'uptime_anterior' => null]]), [], 'ocorrencias_reinicio: sem leitura anterior (1ª verificação) -> nenhuma ocorrência');

$excesso = antena_ocorrencias_clientes_excesso([
    ['id' => 7, 'nome' => 'Loja D', 'clientes_conectados' => 45],
], 30);
t_eq(count($excesso), 1, 'ocorrencias_clientes_excesso: acima do limite -> gera ocorrência');
t_ok(strpos($excesso[0]['detalhe'], '45') !== false && strpos($excesso[0]['detalhe'], '30') !== false, 'ocorrencias_clientes_excesso: detalhe cita clientes e limite');

t_eq(antena_ocorrencias_clientes_excesso([['id' => 7, 'clientes_conectados' => 30]], 30), [], 'ocorrencias_clientes_excesso: igual ao limite -> nenhuma ocorrência (só acima, não "ou igual")');
t_eq(antena_ocorrencias_clientes_excesso([['id' => 7, 'clientes_conectados' => 10]], 30), [], 'ocorrencias_clientes_excesso: dentro do limite -> nenhuma ocorrência');

// ---------------------------------------------------------------------------
// monitor_antenas_varrer() com SSH mockado — precisa de banco
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $NOME_TESTE = '__teste_antena__';
    $limpa = function () use ($pdo, $NOME_TESTE) {
        $pdo->prepare("DELETE FROM portal_monitor_antenas WHERE nome = ?")->execute([$NOME_TESTE]);
    };
    $antenaId = function () use ($pdo, $NOME_TESTE) {
        $st = $pdo->prepare("SELECT id FROM portal_monitor_antenas WHERE nome = ?");
        $st->execute([$NOME_TESTE]);
        return (int) $st->fetchColumn();
    };
    $backdatar = function () use ($pdo, $NOME_TESTE) {
        // força a próxima chamada a monitor_antenas_varrer() a ignorar o throttle
        $pdo->prepare("UPDATE portal_monitor_antenas SET ultima_verificacao = NOW() - INTERVAL 1 HOUR WHERE nome = ?")
            ->execute([$NOME_TESTE]);
    };

    try {
        $limpa();
        monitor_antena_salvar($pdo, null, $NOME_TESTE, '10.9.9.9', 'admin', 'senha-teste', true);
        $id = $antenaId();
        t_ok($id > 0, 'salvar: cria a antena de teste');

        // 1ª varredura: SSH ok, antena acabou de "subir"
        $GLOBALS['__monitor_antena_ssh_fake'] = fn($ip, $u, $s) =>
            ['ok' => true, 'uptime' => 5000, 'clientes' => 12, 'firmware' => '6.1.0', 'modelo' => 'U6-LR', 'erro' => null];
        $snap1 = monitor_antenas_varrer($pdo, 20);
        $linha1 = current(array_filter($snap1, fn($a) => (int) $a['id'] === $id));
        t_ok($linha1 !== false, 'varrer: snapshot inclui a antena de teste');
        t_eq($linha1['status'], 'online', 'varrer: SSH ok -> status online');
        t_eq($linha1['uptime_segundos'], 5000, 'varrer: grava uptime do check');
        t_eq($linha1['uptime_anterior'], null, 'varrer: 1ª leitura -> sem uptime_anterior');
        t_eq(antena_ocorrencias_offline([$linha1]), [], 'varrer+offline: online -> nenhuma ocorrência');
        t_eq(antena_ocorrencias_reinicio([$linha1]), [], 'varrer+reinicio: sem anterior -> nenhuma ocorrência');

        // throttle: sem backdatar, 2ª chamada imediata reaproveita o snapshot (não chama SSH de novo)
        $chamadas = 0;
        $GLOBALS['__monitor_antena_ssh_fake'] = function ($ip, $u, $s) use (&$chamadas) {
            $chamadas++;
            return ['ok' => false, 'uptime' => null, 'clientes' => null, 'firmware' => null, 'modelo' => null, 'erro' => 'não deveria rodar'];
        };
        $snap2 = monitor_antenas_varrer($pdo, 20);
        $linha2 = current(array_filter($snap2, fn($a) => (int) $a['id'] === $id));
        t_eq($chamadas, 0, 'varrer: dentro do intervalo de throttle -> NÃO chama SSH de novo');
        t_eq($linha2['status'], 'online', 'varrer: throttle -> mantém o status já persistido');

        // 2ª varredura real: antena caiu (SSH falha) -> offline, uptime_anterior = 5000
        $backdatar();
        $GLOBALS['__monitor_antena_ssh_fake'] = fn($ip, $u, $s) =>
            ['ok' => false, 'uptime' => null, 'clientes' => null, 'firmware' => null, 'modelo' => null, 'erro' => 'Falha ao conectar via SSH (código 255)'];
        $snap3 = monitor_antenas_varrer($pdo, 20);
        $linha3 = current(array_filter($snap3, fn($a) => (int) $a['id'] === $id));
        t_eq($linha3['status'], 'offline', 'varrer: SSH falhou -> status offline');
        t_eq($linha3['uptime_anterior'], 5000, 'varrer: uptime_anterior guarda o valor de antes do UPDATE');
        $ocorrOffline = antena_ocorrencias_offline([$linha3]);
        t_eq(count($ocorrOffline), 1, 'varrer+offline: antena caiu -> gera 1 ocorrência');
        t_eq($ocorrOffline[0]['chave'], "antena_offline:$id", 'varrer+offline: chave usa o id real da antena');

        // 3ª varredura: antena voltou, mas com uptime baixo -> reiniciou de fato
        $backdatar();
        $GLOBALS['__monitor_antena_ssh_fake'] = fn($ip, $u, $s) =>
            ['ok' => true, 'uptime' => 90, 'clientes' => 3, 'firmware' => '6.1.0', 'modelo' => 'U6-LR', 'erro' => null];
        $snap4 = monitor_antenas_varrer($pdo, 20);
        $linha4 = current(array_filter($snap4, fn($a) => (int) $a['id'] === $id));
        t_eq($linha4['status'], 'online', 'varrer: voltou -> status online');
        t_eq($linha4['uptime_anterior'], null, 'varrer: uptime_anterior reflete o valor antes do UPDATE (ficou null na leitura offline)');
        // uptime_anterior ficou null porque a leitura offline anterior não grava uptime —
        // o sinal de reinício "uptime caiu" só se aplica entre duas leituras ONLINE
        // seguidas; esse caso (offline -> online) já é cobrto pelo próprio antena_offline
        // que resolve sozinho. Testa o caso de reinício "silencioso" (sem passar por offline)
        // isoladamente a seguir.

        // caso isolado: duas leituras online seguidas com uptime caindo (reinício sem detectar offline no meio)
        $limpa();
        monitor_antena_salvar($pdo, null, $NOME_TESTE, '10.9.9.9', 'admin', 'senha-teste', true);
        $id2 = $antenaId();
        $GLOBALS['__monitor_antena_ssh_fake'] = fn($ip, $u, $s) =>
            ['ok' => true, 'uptime' => 8000, 'clientes' => 5, 'firmware' => null, 'modelo' => null, 'erro' => null];
        monitor_antenas_varrer($pdo, 20);
        $backdatar();
        $GLOBALS['__monitor_antena_ssh_fake'] = fn($ip, $u, $s) =>
            ['ok' => true, 'uptime' => 60, 'clientes' => 2, 'firmware' => null, 'modelo' => null, 'erro' => null];
        $snap5 = monitor_antenas_varrer($pdo, 20);
        $linha5 = current(array_filter($snap5, fn($a) => (int) $a['id'] === $id2));
        $ocorrReinicio = antena_ocorrencias_reinicio([$linha5]);
        t_eq(count($ocorrReinicio), 1, 'varrer+reinicio: uptime caiu entre 2 leituras online -> gera 1 ocorrência');
        t_ok(strpos($ocorrReinicio[0]['detalhe'], '8000') !== false && strpos($ocorrReinicio[0]['detalhe'], '60') !== false,
            'varrer+reinicio: detalhe cita os 2 uptimes (antes e depois)');

        // clientes acima/dentro do limite, a partir do mesmo snapshot
        t_eq(count(antena_ocorrencias_clientes_excesso([$linha5], 30)), 0, 'varrer+clientes: 2 clientes, limite 30 -> nenhuma ocorrência');
        $linhaMuitosClientes = $linha5;
        $linhaMuitosClientes['clientes_conectados'] = 55;
        t_eq(count(antena_ocorrencias_clientes_excesso([$linhaMuitosClientes], 30)), 1, 'varrer+clientes: 55 clientes, limite 30 -> gera ocorrência');

        // CRUD básico
        monitor_antena_excluir($pdo, $id2);
        $st = $pdo->prepare("SELECT COUNT(*) FROM portal_monitor_antenas WHERE id = ?");
        $st->execute([$id2]);
        t_eq((int) $st->fetchColumn(), 0, 'excluir: remove a antena do banco');
    } finally {
        unset($GLOBALS['__monitor_antena_ssh_fake']);
        $limpa();
    }
} else {
    echo "  -- testes de banco (monitor_antenas_lib): banco indisponível, pulados\n";
}
