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
    ['id' => 1, 'nome' => 'Loja A', 'ip' => '10.0.0.1', 'loja' => 'Lj 001', 'status' => 'offline', 'ultima_verificacao' => '2026-10-10 08:00:00'],
    ['id' => 2, 'nome' => 'Loja B', 'ip' => '10.0.0.2', 'status' => 'online'],
]);
t_eq(count($offline), 1, 'ocorrencias_offline: só a antena offline gera ocorrência');
t_eq($offline[0]['chave'], 'antena_offline:1', 'ocorrencias_offline: chave é antena_offline:<id>');
t_eq($offline[0]['loja'], 'Lj 001', 'ocorrencias_offline: propaga a loja da antena');
t_eq(antena_ocorrencias_offline([['id' => 1, 'status' => 'offline']])[0]['loja'], '', 'ocorrencias_offline: sem loja cadastrada -> string vazia (não null)');

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
// antena_status_atual_texto() — pura, sem banco/rede (texto de status atual
// usado por antena_resumo_ajax.php desde 2026-10-12, no lugar do resumo de
// quedas do dia anterior)
// ---------------------------------------------------------------------------
t_eq(
    antena_status_atual_texto([
        ['nome' => 'AP Salão', 'loja' => 'Lj 001', 'status' => 'online', 'ativo' => 1, 'ultima_verificacao' => '2026-10-12 08:00:00'],
        ['nome' => 'AP Estoque', 'loja' => 'Lj 002', 'status' => 'online', 'ativo' => 1, 'ultima_verificacao' => '2026-10-12 08:05:00'],
    ]),
    'Antenas UniFi — status atual (última verificação 08:05): ✅ todas as 2 online.',
    'status_atual_texto: todas online -> 1 linha com ✅ e a verificação mais recente'
);

$textoOffline = antena_status_atual_texto([
    ['nome' => 'AP Salão', 'loja' => 'Lj 001', 'status' => 'offline', 'ativo' => 1, 'ultima_verificacao' => '2026-10-12 08:00:00'],
    ['nome' => 'AP Estoque', 'loja' => 'Lj 002', 'status' => 'online', 'ativo' => 1, 'ultima_verificacao' => '2026-10-12 08:05:00'],
]);
t_ok(strpos($textoOffline, '⚠️ 1 de 2 offline:') !== false, 'status_atual_texto: com 1 offline -> cita "1 de 2 offline"');
t_ok(strpos($textoOffline, 'AP Salão (Lj 001)') !== false, 'status_atual_texto: lista a antena offline com a loja entre parênteses');
t_ok(strpos($textoOffline, '✅ 1 online.') !== false, 'status_atual_texto: fecha com a contagem de online');

$textoDesconhecido = antena_status_atual_texto([
    ['nome' => 'AP Depósito', 'loja' => '', 'status' => 'desconhecido', 'ativo' => 1, 'ultima_verificacao' => null],
]);
t_ok(strpos($textoDesconhecido, '1 com status desconhecido') !== false, 'status_atual_texto: desconhecido entra em bloco próprio, não some nem conta como offline');
t_ok(strpos($textoDesconhecido, 'AP Depósito') !== false && strpos($textoDesconhecido, 'AP Depósito (') === false, 'status_atual_texto: sem loja cadastrada -> nome sem parênteses coladas');

t_eq(
    antena_status_atual_texto([
        ['nome' => 'AP Ativa', 'loja' => '', 'status' => 'online', 'ativo' => 1, 'ultima_verificacao' => '2026-10-12 08:00:00'],
        ['nome' => 'AP Desativada', 'loja' => '', 'status' => 'offline', 'ativo' => 0, 'ultima_verificacao' => '2026-10-12 08:00:00'],
    ]),
    'Antenas UniFi — status atual (última verificação 08:00): ✅ todas as 1 online.',
    'status_atual_texto: antena inativa (ativo=0) não entra na contagem'
);

t_eq(antena_status_atual_texto([]), 'Antenas UniFi: nenhuma antena ativa cadastrada.', 'status_atual_texto: lista vazia -> mensagem explícita, não ausência silenciosa');

// ---------------------------------------------------------------------------
// monitor_antena_reiniciar() — comando de reboot via SSH (sem banco)
// ---------------------------------------------------------------------------
t_eq(monitor_antena_reiniciar('', 'admin', 'senha')['ok'], false, 'reiniciar: sem IP -> ok=false (retorna antes de abrir SSH)');
t_eq(monitor_antena_reiniciar('10.9.9.9', '', 'senha')['ok'], false, 'reiniciar: sem usuário -> ok=false');
t_eq(monitor_antena_reiniciar('10.9.9.9', 'admin', '')['ok'], false, 'reiniciar: sem senha -> ok=false');

$GLOBALS['__monitor_antena_ssh_reboot_fake'] = fn($ip, $u, $s) => ['ok' => true, 'erro' => null];
t_eq(monitor_antena_reiniciar('10.9.9.9', 'admin', 'senha-teste')['ok'], true, 'reiniciar: SSH aceitou o comando -> ok=true');

$GLOBALS['__monitor_antena_ssh_reboot_fake'] = fn($ip, $u, $s) => ['ok' => false, 'erro' => 'Permission denied, please try again.'];
$falhaAuth = monitor_antena_reiniciar('10.9.9.9', 'admin', 'senha-errada');
t_eq($falhaAuth['ok'], false, 'reiniciar: Permission denied -> ok=false');
t_ok(strpos($falhaAuth['erro'], 'Permission denied') !== false, 'reiniciar: erro propaga a mensagem original do SSH');
unset($GLOBALS['__monitor_antena_ssh_reboot_fake']);

// ---------------------------------------------------------------------------
// monitor_antenas_varrer() com SSH mockado — precisa de banco
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $NOME_TESTE = '__teste_antena__';
    $NOME_ENTIDADE_TESTE = 'teste_antena_loja';
    $limpa = function () use ($pdo, $NOME_TESTE, $NOME_ENTIDADE_TESTE) {
        $pdo->prepare("DELETE FROM portal_monitor_antenas WHERE nome = ?")->execute([$NOME_TESTE]);
        $pdo->prepare("DELETE FROM glpi_entities WHERE name = ?")->execute([$NOME_ENTIDADE_TESTE]);
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
        // entities_id = FK solta pra glpi_entities (sem constraint real), mesmo
        // padrão de test_chatbot_menu.php/test_glpi_bot_lojas.php: insere uma
        // entidade sentinela em vez de depender de um id real de produção.
        // glpi_entities.id NÃO é auto_increment no schema real do GLPI (id=0 já
        // existe), daí o MAX(id)+1000.
        $idEntidadeTeste = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM glpi_entities")->fetchColumn() + 1000;
        $pdo->exec("INSERT INTO glpi_entities (id, name, completename, level) VALUES ($idEntidadeTeste, '$NOME_ENTIDADE_TESTE', 'Entidade raiz > teste_antena_loja', 2)");

        monitor_antena_salvar($pdo, null, $NOME_TESTE, '10.9.9.9', 'admin', 'senha-teste', true, $idEntidadeTeste);
        $id = $antenaId();
        t_ok($id > 0, 'salvar: cria a antena de teste');
        $entitiesIdSalvo = (int) $pdo->query("SELECT entities_id FROM portal_monitor_antenas WHERE id = $id")->fetchColumn();
        t_eq($entitiesIdSalvo, $idEntidadeTeste, 'salvar: persiste entities_id (FK solta pra glpi_entities, mesmo padrão de inventario_pc.php)');

        $listagem = monitor_antena_listar($pdo);
        $itemListado = current(array_filter($listagem, fn($x) => (int) $x['id'] === $id));
        t_eq($itemListado['loja'], 'Entidade raiz > teste_antena_loja', 'listar: resolve loja via apelido_entidade() a partir de entities_id (sem mapeamento cadastrado -> cai no completename cru)');

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

// ---------------------------------------------------------------------------
// antena_resumo_quedas_dia() / antena_resumo_texto() — resumo diário (Fase 3),
// a partir de portal_alertas_historico (tabela escrita por alertas_tipos.php).
// Ids sentinela (99999xx) garantem isolamento de qualquer antena real.
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $DIA_TESTE = '2026-10-01';
    $CHAVE1 = 'antena_offline:9999901'; // sem linha em portal_monitor_antenas -> nome cai no fallback
    $CHAVE2 = 'antena_offline:9999902'; // queda ainda aberta no fim do dia

    $limpaHist = function () use ($pdo, $CHAVE1, $CHAVE2) {
        $pdo->prepare("DELETE FROM portal_alertas_historico WHERE chave IN (?, ?)")->execute([$CHAVE1, $CHAVE2]);
    };
    $inserirEvento = function (string $chave, string $evento, string $quando) use ($pdo) {
        $pdo->prepare("INSERT INTO portal_alertas_historico (tipo, chave, evento, titulo, criado_em) VALUES ('antena_offline', ?, ?, 'teste', ?)")
            ->execute([$chave, $evento, $quando]);
    };

    try {
        $limpaHist();

        t_eq(antena_resumo_quedas_dia($pdo, $DIA_TESTE), [], 'resumo_quedas_dia: sem eventos no dia -> array vazio');
        t_eq(antena_resumo_texto([], $DIA_TESTE), 'Antenas UniFi: sem quedas em 01/10/2026.', 'resumo_texto: vazio -> "sem quedas", não ausência silenciosa');

        $inserirEvento($CHAVE1, 'nova', "$DIA_TESTE 08:00:00");
        $inserirEvento($CHAVE1, 'resolvida', "$DIA_TESTE 08:15:00");
        $resumo1 = antena_resumo_quedas_dia($pdo, $DIA_TESTE);
        t_eq($resumo1[9999901]['nome'], 'Antena #9999901', 'resumo_quedas_dia: sem linha em portal_monitor_antenas -> nome de fallback');
        t_eq($resumo1[9999901]['loja'], '', 'resumo_quedas_dia: sem linha em portal_monitor_antenas -> loja vazia (não null)');
        t_eq($resumo1[9999901]['quedas'], 1, 'resumo_quedas_dia: 1 par nova/resolvida -> 1 queda');
        t_eq($resumo1[9999901]['minutos_offline'], 15, 'resumo_quedas_dia: soma os minutos entre nova e resolvida');

        $inserirEvento($CHAVE1, 'nova', "$DIA_TESTE 10:00:00");
        $inserirEvento($CHAVE1, 'resolvida', "$DIA_TESTE 10:05:00");
        $resumo2 = antena_resumo_quedas_dia($pdo, $DIA_TESTE);
        t_eq($resumo2[9999901]['quedas'], 2, 'resumo_quedas_dia: 2 quedas no mesmo dia -> soma as duas');
        t_eq($resumo2[9999901]['minutos_offline'], 20, 'resumo_quedas_dia: soma os minutos das 2 quedas (15+5)');

        $inserirEvento($CHAVE2, 'nova', "$DIA_TESTE 23:50:00");
        $resumo3 = antena_resumo_quedas_dia($pdo, $DIA_TESTE);
        t_eq($resumo3[9999902]['quedas'], 1, 'resumo_quedas_dia: queda ainda aberta no fim do dia -> conta como 1 queda');
        t_eq($resumo3[9999902]['minutos_offline'], 10, 'resumo_quedas_dia: queda aberta -> soma minutos parciais até 23:59:59 (10min)');

        $textoFinal = antena_resumo_texto(array_values($resumo3), $DIA_TESTE);
        t_ok(strpos($textoFinal, 'Antena #9999901') !== false, 'resumo_texto: cita a antena sem cadastro pelo nome de fallback');
        t_ok(strpos($textoFinal, '2 quedas') !== false, 'resumo_texto: pluraliza "quedas" corretamente');
        t_ok(strpos($textoFinal, '1 queda,') !== false, 'resumo_texto: não pluraliza no singular');

        // agrupamento por loja (pura, sem banco) — só agrupa quando pelo menos
        // 1 item tem loja preenchida (coluna nova, retrocompatível com o texto
        // plano de quando nenhuma antena tinha loja cadastrada)
        $textoComLoja = antena_resumo_texto([
            ['nome' => 'AP Salão', 'loja' => 'Lj 001', 'quedas' => 1, 'minutos_offline' => 5],
            ['nome' => 'AP Estoque', 'loja' => 'Lj 002', 'quedas' => 2, 'minutos_offline' => 10],
            ['nome' => 'AP Sem Loja', 'loja' => '', 'quedas' => 1, 'minutos_offline' => 3],
        ], $DIA_TESTE);
        t_ok(strpos($textoComLoja, 'Lj 001:') !== false && strpos($textoComLoja, 'Lj 002:') !== false,
            'resumo_texto: com pelo menos 1 loja preenchida -> agrupa por loja');
        t_ok(strpos($textoComLoja, 'Sem loja:') !== false, 'resumo_texto: antena sem loja cai no grupo "Sem loja"');
    } finally {
        $limpaHist();
    }
} else {
    echo "  -- testes de banco (antena_resumo_quedas_dia): banco indisponível, pulados\n";
}
