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
    $limpa = function () use ($pdo, $CHAVE_TESTE) {
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave = ?")->execute([$CHAVE_TESTE]);
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
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', 'PDV', 'down', 'sem resposta ao ping');
        $ocDevice = alerta_check_dude_device($pdo, []);
        $chaveEsperada = 'dude:device:' . $CHAVE_TESTE;
        t_ok((bool) array_filter($ocDevice, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_device: aparece quando status=down');
        $achouPC01 = array_values(array_filter($ocDevice, fn($o) => $o['chave'] === $chaveEsperada))[0];
        t_ok(str_contains($achouPC01['detalhe'], 'sem resposta ao ping'), 'check_dude_device: detalhe traz o motivo');
        t_eq($achouPC01['loja'], 'Loja 05', 'check_dude_device: loja vem do que o mapa do Dude mandou');
        t_eq($achouPC01['categoria'], 'PDV', 'check_dude_device: categoria vem do que a notification mandou');

        $ocLink = alerta_check_dude_link($pdo, []);
        t_ok(!array_filter($ocLink, fn($o) => $o['chave'] === $chaveEsperada), 'check_dude_link: não mostra ocorrência de outro tipo (device)');

        // --- up resolve ---
        dude_registrar_estado($pdo, 'device', $CHAVE_TESTE, 'PC Caixa 1', '10.0.0.5', 'Loja 05', 'PDV', 'up', '');
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

        // --- renders ---
        t_ok(strpos(alerta_render_dude([]), 'vazio') !== false, 'render_dude([]) tem a msg vazia');
        t_ok(strlen(alerta_render_dude($ocDevice)) > 20, 'render_dude(ocorr) devolve HTML');
        t_ok(str_contains(alerta_render_dude($ocDevice), 'PDV'), 'render_dude: agrupa por categoria quando preenchida');
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
