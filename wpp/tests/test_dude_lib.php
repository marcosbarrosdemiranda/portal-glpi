<?php
// Testes de dude_lib.php — token, registrar estado e os 5 checks do The Dude.
// Roda com `php wpp/tests/run.php` (blocos com banco só valem no deploy).
require_once __DIR__ . '/../../dude_lib.php';
global $pdo;

// ---------------------------------------------------------------------------
// dude_gerar_novo_token() — puro o bastante (só grava em portal_wpp_config,
// mas não depende de estado prévio pra gerar) — testado junto com o resto
// porque precisa do banco pra confirmar persistência.
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    // CHAVE (não tipo) é o que identifica a linha de teste — tipo fica
    // 'device' de verdade, senão não testaria o dispatch real. Cuidado:
    // portal_dude_estado é tabela COMPARTILHADA (pode ter dado real depois
    // que o Dude entrar no ar) — nunca fazer UPDATE/DELETE sem filtrar pela
    // chave de teste. Bug corrigido em 2026-09-13: a versão anterior filtrava
    // a limpeza por um "tipo" que nunca era usado nos inserts, e um UPDATE
    // sem WHERE mexia na tabela inteira — deixou lixo residente que a
    // Central de Alertas pegou e mandou WhatsApp de verdade.
    $CHAVE_TESTE = '__teste_PC-01__';
    $CAT_TESTE = '__cat_teste__';
    $LOJA_TESTE = '__loja_teste__';
    $DATA_TESTE = '2099-12-31'; // data bem no futuro, improvável de colidir com feriado real cadastrado
    $limpa = function () use ($pdo, $CHAVE_TESTE, $CAT_TESTE, $LOJA_TESTE, $DATA_TESTE) {
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave LIKE '__teste_%' OR chave LIKE '__cat_%'")->execute();
        $pdo->prepare("DELETE FROM portal_dude_categoria_config WHERE categoria = ?")->execute([$CAT_TESTE]);
        $pdo->prepare("DELETE FROM portal_dude_horario_excecao WHERE categoria = ?")->execute([$CAT_TESTE]);
        $pdo->prepare("DELETE FROM portal_dude_feriado WHERE data = ? OR loja = ?")->execute([$DATA_TESTE, $LOJA_TESTE]);
    };

    // guarda o token original pra restaurar no finally (não pode invalidar
    // o token de produção que o usuário já colou no Dude)
    $tokenOriginal = dude_token_atual($pdo);

    try {
        // --- token ---
        $t1 = dude_gerar_novo_token($pdo);
        t_ok(strlen($t1) === 32, 'gerar_novo_token: 32 chars');
        t_eq(dude_token_atual($pdo), $t1, 'token_atual: reflete o token recem-gerado');
        $t2 = dude_gerar_novo_token($pdo);
        t_ok($t1 !== $t2, 'gerar_novo_token: novo token invalida o anterior');
        t_eq(dude_token_atual($pdo), $t2, 'token_atual: reflete o segundo token');

        // --- registrar_estado + check do tipo certo / não vaza pra outro tipo ---
        $limpa();
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', '__cat_teste__', 'down', 'sem resposta ao ping');
        $ocDevice = alerta_check_dude_device($pdo, []);
        $chaveEsperada = 'dude:device:' . $CHAVE_TESTE;
        t_ok((bool) array_filter($ocDevice, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_device: aparece quando status=down');
        $achouPC01 = array_values(array_filter($ocDevice, fn($o) => $o['chave'] === $chaveEsperada))[0];
        t_ok(str_contains($achouPC01['detalhe'], 'sem resposta ao ping'), 'check_dude_device: detalhe traz o motivo');
        t_eq($achouPC01['loja'], 'Loja 05', 'check_dude_device: loja vem do que o mapa do Dude mandou');
        t_eq($achouPC01['categoria'], '__cat_teste__', 'check_dude_device: categoria vem do que a notification mandou');

        $ocLink = alerta_check_dude_link($pdo, []);
        t_ok(!array_filter($ocLink, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_link: não mostra ocorrência de outro tipo (device)');

        // --- up resolve ---
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', '__cat_teste__', 'up', '');
        $ocDevice2 = alerta_check_dude_device($pdo, []);
        t_ok(!array_filter($ocDevice2, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_device: some depois de um up');

        // --- ultima_notificacao reflete o registro mais recente ---
        $ultima = dude_ultima_notificacao($pdo);
        t_ok($ultima !== null, 'ultima_notificacao: não é null depois de registrar algo');

        // --- sem_contato: contato recente (o que acabamos de registrar) não dispara.
        // NÃO testamos o caminho "disparou" aqui: dude_ultima_notificacao() é
        // MAX(atualizado_em) da tabela INTEIRA (é um watchdog global, não por
        // dispositivo) — forçar isso pro passado exigiria mexer em toda a
        // tabela compartilhada, o que pode alarmar de verdade se houver dado
        // real. Cobertura desse ramo fica por inspeção manual/E2E, não aqui.
        $ocSC = alerta_check_dude_sem_contato($pdo, ['horas' => 6]);
        t_ok(!$ocSC, 'check_dude_sem_contato: contato recente não dispara');

        // --- categoria_no_horario: sem config = sempre true ---
        t_ok(dude_categoria_no_horario($pdo, $CAT_TESTE), 'categoria_no_horario: sem config -> sempre true');
        t_ok(dude_categoria_no_horario($pdo, ''), 'categoria_no_horario: categoria vazia -> sempre true');

        // --- categoria_no_horario: janela que inclui/exclui a hora atual ---
        $agora = time();
        dude_categoria_config_salvar($pdo, $CAT_TESTE, date('H:i:s', $agora - 3600), date('H:i:s', $agora + 3600), null);
        t_ok(dude_categoria_no_horario($pdo, $CAT_TESTE), 'categoria_no_horario: agora dentro da janela -> true');

        dude_categoria_config_salvar($pdo, $CAT_TESTE, date('H:i:s', $agora + 2 * 3600), date('H:i:s', $agora + 3 * 3600), null);
        t_ok(!dude_categoria_no_horario($pdo, $CAT_TESTE), 'categoria_no_horario: agora fora da janela -> false');

        // --- alerta_check_dude_device respeita o horário (só esse tipo, por decisão do usuário) ---
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', $CAT_TESTE, 'down', 'fora do ar');
        $ocForaHorario = alerta_check_dude_device($pdo, []);
        t_ok(!array_filter($ocForaHorario, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_device: fora do horário da categoria -> não aparece');

        dude_categoria_config_salvar($pdo, $CAT_TESTE, date('H:i:s', $agora - 3600), date('H:i:s', $agora + 3600), null);
        $ocDentroHorario = alerta_check_dude_device($pdo, []);
        t_ok((bool) array_filter($ocDentroHorario, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_device: dentro do horário da categoria -> aparece');

        // --- exceção de horário por loja + dia da semana ---
        $diaHoje = (int) date('w');
        t_ok(dude_lojas_vistas($pdo) !== null, 'lojas_vistas: não lança');

        // categoria fora do horário padrão agora, mas essa loja tem exceção que INCLUI agora
        dude_categoria_config_salvar($pdo, $CAT_TESTE, date('H:i:s', $agora + 2 * 3600), date('H:i:s', $agora + 3 * 3600), null);
        t_ok(!dude_categoria_no_horario($pdo, $CAT_TESTE, $LOJA_TESTE), 'categoria_no_horario(loja): sem exceção ainda, cai no padrão (fora) -> false');

        dude_horario_excecao_salvar($pdo, $CAT_TESTE, $LOJA_TESTE, $diaHoje, date('H:i:s', $agora - 3600), date('H:i:s', $agora + 3600));
        t_ok(dude_categoria_no_horario($pdo, $CAT_TESTE, $LOJA_TESTE), 'categoria_no_horario(loja): exceção de hoje inclui agora -> true (ignora o padrão)');
        t_ok(!dude_categoria_no_horario($pdo, $CAT_TESTE, '__outra_loja_sem_excecao__'), 'categoria_no_horario(loja): outra loja sem exceção continua usando o padrão -> false');

        $listaExc = dude_horario_excecao_listar($pdo);
        $achouExc = array_values(array_filter($listaExc, fn($e) => $e['categoria'] === $CAT_TESTE && $e['loja'] === $LOJA_TESTE));
        t_eq(count($achouExc), 1, 'horario_excecao_listar: exceção salva aparece na lista');
        t_eq((int) $achouExc[0]['dia_semana'], $diaHoje, 'horario_excecao_listar: dia_semana salvo corretamente');

        // dude_check_device respeita a exceção por loja
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', $LOJA_TESTE, $CAT_TESTE, 'down', 'fora do ar');
        $ocComExcecao = alerta_check_dude_device($pdo, []);
        $chaveLojaTeste = 'dude:device:' . $CHAVE_TESTE;
        t_ok((bool) array_filter($ocComExcecao, fn($o) => $o['chave'] === $chaveLojaTeste), 'check_dude_device: exceção da loja inclui agora -> aparece mesmo com padrão da categoria fora');

        // horarioInicio/horarioFim null remove a exceção (volta ao padrão)
        dude_horario_excecao_salvar($pdo, $CAT_TESTE, $LOJA_TESTE, $diaHoje, null, null);
        t_ok(!dude_categoria_no_horario($pdo, $CAT_TESTE, $LOJA_TESTE), 'horario_excecao_salvar(null): remove a exceção -> volta ao padrão (fora)');

        // --- feriados ---
        t_ok(!dude_feriado_hoje($pdo, $LOJA_TESTE), 'feriado_hoje: sem cadastro -> false');

        dude_feriado_salvar($pdo, $DATA_TESTE, $LOJA_TESTE);
        $listaFer = dude_feriado_listar($pdo);
        $achouFer = array_values(array_filter($listaFer, fn($f) => $f['data'] === $DATA_TESTE && $f['loja'] === $LOJA_TESTE));
        t_eq(count($achouFer), 1, 'feriado_listar: feriado salvo aparece na lista');

        // feriado de loja='' (todas) silencia qualquer loja, mesmo sem linha específica
        dude_feriado_salvar($pdo, $DATA_TESTE, '');
        t_ok(dude_feriado_hoje($pdo, '__loja_qualquer_sem_feriado_proprio__') === false, 'feriado_hoje: feriado é numa data futura (DATA_TESTE), não hoje -> false mesmo com linha "todas as lojas"');

        dude_feriado_excluir($pdo, $DATA_TESTE, $LOJA_TESTE);
        dude_feriado_excluir($pdo, $DATA_TESTE, '');
        $listaFer2 = dude_feriado_listar($pdo);
        t_ok(!array_filter($listaFer2, fn($f) => $f['data'] === $DATA_TESTE), 'feriado_excluir: remove os dois cadastrados');

        // feriado_hoje==true de verdade, e bloqueando alerta_check_dude_device — usando CURDATE()
        $hojeStr = date('Y-m-d');
        dude_feriado_salvar($pdo, $hojeStr, $LOJA_TESTE);
        t_ok(dude_feriado_hoje($pdo, $LOJA_TESTE), 'feriado_hoje: cadastrado pra hoje + loja certa -> true');
        t_ok(!dude_feriado_hoje($pdo, '__outra_loja_sem_feriado__'), 'feriado_hoje: cadastrado só pra uma loja não afeta outra');

        // limpa a exceção de horário de novo (senão o próximo check reaparece) antes de testar o feriado bloqueando
        dude_horario_excecao_salvar($pdo, $CAT_TESTE, $LOJA_TESTE, $diaHoje, date('H:i:s', $agora - 3600), date('H:i:s', $agora + 3600));
        $ocComFeriado = alerta_check_dude_device($pdo, []);
        t_ok(!array_filter($ocComFeriado, fn($o) => $o['chave'] === $chaveLojaTeste), 'check_dude_device: feriado de hoje silencia mesmo dentro do horário permitido');
        $pdo->prepare("DELETE FROM portal_dude_feriado WHERE data = ? AND loja = ?")->execute([$hojeStr, $LOJA_TESTE]);
        dude_horario_excecao_excluir($pdo, $CAT_TESTE, $LOJA_TESTE, $diaHoje);
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave = ?")->execute([$CHAVE_TESTE]);

        // --- alerta_check_dude_ligado_muito_tempo: genérico por categoria ---
        dude_categoria_config_salvar($pdo, $CAT_TESTE, null, null, 1); // limite 1h, sem restrição de horário
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', $CAT_TESTE, 'up', '');
        $pdo->prepare("UPDATE portal_dude_estado SET atualizado_em = NOW() - INTERVAL 2 HOUR WHERE chave = ?")->execute([$CHAVE_TESTE]);
        $ocLigado = alerta_check_dude_ligado_muito_tempo($pdo, []);
        $chaveLigado = 'dude:ligado:' . $CHAVE_TESTE;
        t_ok((bool) array_filter($ocLigado, fn($o) => $o['chave'] === $chaveLigado), 'check_dude_ligado_muito_tempo: up há 2h com limite 1h -> aparece');

        $pdo->prepare("UPDATE portal_dude_estado SET atualizado_em = NOW() WHERE chave = ?")->execute([$CHAVE_TESTE]);
        $ocLigado2 = alerta_check_dude_ligado_muito_tempo($pdo, []);
        t_ok(!array_filter($ocLigado2, fn($o) => $o['chave'] === $chaveLigado), 'check_dude_ligado_muito_tempo: up recente -> não aparece');

        // --- categoria_config_listar reflete o que foi salvo ---
        $listaCat = dude_categoria_config_listar($pdo);
        $achouCat = array_values(array_filter($listaCat, fn($c) => $c['categoria'] === $CAT_TESTE));
        t_ok(count($achouCat) === 1, 'categoria_config_listar: categoria vista aparece na lista');
        t_eq((int) $achouCat[0]['ligado_horas_max'], 1, 'categoria_config_listar: ligado_horas_max salvo corretamente');

        // --- renders ---
        t_ok(strpos(alerta_render_dude([]), 'vazio') !== false, 'render_dude([]) tem a msg vazia');
        t_ok(strlen(alerta_render_dude($ocDevice)) > 20, 'render_dude(ocorr) devolve HTML');
        t_ok(str_contains(alerta_render_dude($ocDevice), '__cat_teste__'), 'render_dude: agrupa por categoria quando preenchida');
        t_ok(str_contains(alerta_render_dude($ocDevice), 'Loja 05'), 'render_dude: sub-agrupa por loja dentro da categoria');
        $ocSemNada = [['chave' => 'x', 'titulo' => 'T', 'loja' => '', 'categoria' => '', 'detalhe' => 'D']];
        t_ok(!str_contains(alerta_render_dude($ocSemNada), 'loja-h'), 'render_dude: sem loja nem categoria cai na tabela simples (sem cabeçalho de grupo)');
        $ocSoCategoria = [['chave' => 'y', 'titulo' => 'S', 'loja' => '', 'categoria' => 'Servidor', 'detalhe' => 'D']];
        t_ok(str_contains(alerta_render_dude($ocSoCategoria), 'Servidor') && str_contains(alerta_render_dude($ocSoCategoria), 'Sem loja'), 'render_dude: só categoria preenchida ainda agrupa (loja vira "Sem loja")');
    } finally {
        $limpa();
        // restaura o token original (string vazia = nunca tinha sido gerado)
        if ($tokenOriginal !== '') {
            $pdo->prepare("INSERT INTO portal_wpp_config (chave, valor) VALUES ('dude_token', ?)
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$tokenOriginal]);
        } else {
            $pdo->prepare("DELETE FROM portal_wpp_config WHERE chave = 'dude_token'")->execute();
        }
    }

    // --- dude_ping_ip: usa o seam de teste, não bate rede de verdade ---
    $GLOBALS['__dude_ping_fake'] = fn(string $ip) => $ip === '10.0.0.1';
    t_ok(dude_ping_ip('10.0.0.1'), 'ping_ip: seam de teste responde true pro IP configurado');
    t_ok(!dude_ping_ip('10.0.0.2'), 'ping_ip: seam de teste responde false pra outro IP');
    unset($GLOBALS['__dude_ping_fake']);
    t_ok(!dude_ping_ip('nao-e-um-ip'), 'ping_ip: sem seam, IP invalido -> false, nunca lança');

    // --- dude_verificar_down: corrige pra 'up' quem responde ao ping ---
    $CHAVE_A = '__teste_ping_a__';
    $CHAVE_B = '__teste_ping_b__';
    $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave IN (?, ?)")->execute([$CHAVE_A, $CHAVE_B]);
    try {
        dude_registrar_estado($pdo, 'device', $CHAVE_A, 'Teste A', '10.0.0.1', 'Loja X', '', 'down', 'fora do ar');
        dude_registrar_estado($pdo, 'device', $CHAVE_B, 'Teste B', '10.0.0.2', 'Loja X', '', 'down', 'fora do ar');
        // A fica "down ha muito tempo" (30+ min), B acabou de cair agora
        $pdo->prepare("UPDATE portal_dude_estado SET atualizado_em = NOW() - INTERVAL 45 MINUTE WHERE chave = ?")->execute([$CHAVE_A]);

        $GLOBALS['__dude_ping_fake'] = fn(string $ip) => $ip === '10.0.0.1'; // só o A responde

        // limiar 30min: só A é candidato (B é recente demais) -> só A pode ser corrigido
        $corrigidos30 = dude_verificar_down($pdo, 30);
        t_ok(in_array($CHAVE_A, $corrigidos30, true), 'verificar_down(30min): A (down ha 45min, responde ping) -> corrigido');
        t_ok(!in_array($CHAVE_B, $corrigidos30, true), 'verificar_down(30min): B (down recente) -> nao e nem candidato');

        $statusA = $pdo->query("SELECT status FROM portal_dude_estado WHERE chave = '$CHAVE_A'")->fetchColumn();
        t_eq($statusA, 'up', 'verificar_down: status de A realmente virou up no banco');

        // B ainda down, sem responder ping -> botao manual (minutosMin=0) nao corrige
        $corrigidosManual = dude_verificar_down($pdo, 0);
        t_ok(!in_array($CHAVE_B, $corrigidosManual, true), 'verificar_down(0min manual): B nao responde ping -> nao corrigido');
        $statusB = $pdo->query("SELECT status FROM portal_dude_estado WHERE chave = '$CHAVE_B'")->fetchColumn();
        t_eq($statusB, 'down', 'verificar_down: B continua down (nunca respondeu)');

        unset($GLOBALS['__dude_ping_fake']);
    } finally {
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave IN (?, ?)")->execute([$CHAVE_A, $CHAVE_B]);
    }

    // --- dude_verificar_up: corrige pra 'down' quem NAO responde ao ping (device travado em 'up') ---
    $CAT_U = '__teste_categoria_up__';
    $CHAVE_C = '__teste_ping_c__'; // travado há muito tempo, nao responde -> deve virar down
    $CHAVE_D = '__teste_ping_d__'; // travado há muito tempo, responde -> continua up
    $CHAVE_E = '__teste_ping_e__'; // atualizado recentemente, nao responde -> nao e candidato ainda
    $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave IN (?, ?, ?)")->execute([$CHAVE_C, $CHAVE_D, $CHAVE_E]);
    $configOrig = $pdo->query("SELECT horario_inicio, horario_fim, ligado_horas_max FROM portal_dude_categoria_config WHERE categoria = '$CAT_U'")->fetch(PDO::FETCH_ASSOC);
    try {
        dude_categoria_config_salvar($pdo, $CAT_U, null, null, 24);

        dude_registrar_estado($pdo, 'device', $CHAVE_C, 'Teste C', '10.0.0.3', 'Loja X', $CAT_U, 'up', 'ok');
        dude_registrar_estado($pdo, 'device', $CHAVE_D, 'Teste D', '10.0.0.4', 'Loja X', $CAT_U, 'up', 'ok');
        dude_registrar_estado($pdo, 'device', $CHAVE_E, 'Teste E', '10.0.0.5', 'Loja X', $CAT_U, 'up', 'ok');
        // C e D travados há 5h (candidatos); E atualizado agora mesmo (nao e candidato)
        $pdo->prepare("UPDATE portal_dude_estado SET atualizado_em = NOW() - INTERVAL 5 HOUR WHERE chave IN (?, ?)")->execute([$CHAVE_C, $CHAVE_D]);

        $GLOBALS['__dude_ping_fake'] = fn(string $ip) => $ip === '10.0.0.4'; // só D responde

        // limiar 3h: C e D sao candidatos, E nao
        $corrigidos3h = dude_verificar_up($pdo, 3);
        t_ok(in_array($CHAVE_C, $corrigidos3h, true), 'verificar_up(3h): C (travado 5h, nao responde) -> corrigido pra down');
        t_ok(!in_array($CHAVE_D, $corrigidos3h, true), 'verificar_up(3h): D (travado 5h, responde ping) -> continua up');
        t_ok(!in_array($CHAVE_E, $corrigidos3h, true), 'verificar_up(3h): E (atualizado agora) -> nem e candidato');

        $statusC = $pdo->query("SELECT status FROM portal_dude_estado WHERE chave = '$CHAVE_C'")->fetchColumn();
        t_eq($statusC, 'down', 'verificar_up: status de C realmente virou down no banco');
        $statusD = $pdo->query("SELECT status FROM portal_dude_estado WHERE chave = '$CHAVE_D'")->fetchColumn();
        t_eq($statusD, 'up', 'verificar_up: D continua up (respondeu ping)');
        $statusE = $pdo->query("SELECT status FROM portal_dude_estado WHERE chave = '$CHAVE_E'")->fetchColumn();
        t_eq($statusE, 'up', 'verificar_up: E continua up (ainda nao e candidato)');

        unset($GLOBALS['__dude_ping_fake']);
    } finally {
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave IN (?, ?, ?)")->execute([$CHAVE_C, $CHAVE_D, $CHAVE_E]);
        if ($configOrig) {
            $pdo->prepare("UPDATE portal_dude_categoria_config SET horario_inicio=?, horario_fim=?, ligado_horas_max=? WHERE categoria=?")
                ->execute([$configOrig['horario_inicio'], $configOrig['horario_fim'], $configOrig['ligado_horas_max'], $CAT_U]);
        } else {
            $pdo->prepare("DELETE FROM portal_dude_categoria_config WHERE categoria = ?")->execute([$CAT_U]);
        }
    }
} else {
    echo "  -- testes de banco (dude_lib): banco indisponível, pulados\n";
}
