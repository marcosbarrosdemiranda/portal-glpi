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
} else {
    echo "  -- testes de banco (dude_lib): banco indisponível, pulados\n";
}
