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
    $TIPO_TESTE = '__teste_dude__';
    $limpa = function () use ($pdo, $TIPO_TESTE) {
        $pdo->prepare("DELETE FROM portal_dude_estado WHERE tipo = ?")->execute([$TIPO_TESTE]);
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
        dude_registrar_estado($pdo, 'device', 'PC-01', 'PC Caixa 1', '10.0.0.5', 'down', 'sem resposta ao ping');
        $ocDevice = alerta_check_dude_device($pdo, []);
        t_ok((bool) array_filter($ocDevice, fn($o) => $o['chave'] === 'dude:device:PC-01'), 'check_dude_device: aparece quando status=down');
        t_ok(str_contains(array_values(array_filter($ocDevice, fn($o) => $o['chave'] === 'dude:device:PC-01'))[0]['detalhe'], 'sem resposta ao ping'), 'check_dude_device: detalhe traz o motivo');

        $ocLink = alerta_check_dude_link($pdo, []);
        t_ok(!array_filter($ocLink, fn($o) => $o['chave'] === 'dude:device:PC-01'), 'check_dude_link: não mostra ocorrência de outro tipo (device)');

        // --- up resolve ---
        dude_registrar_estado($pdo, 'device', 'PC-01', 'PC Caixa 1', '10.0.0.5', 'up', '');
        $ocDevice2 = alerta_check_dude_device($pdo, []);
        t_ok(!array_filter($ocDevice2, fn($o) => $o['chave'] === 'dude:device:PC-01'), 'check_dude_device: some depois de um up');

        // --- ultima_notificacao reflete o registro mais recente ---
        $ultima = dude_ultima_notificacao($pdo);
        t_ok($ultima !== null, 'ultima_notificacao: não é null depois de registrar algo');

        // --- sem_contato: contato recente -> vazio; forçando pro passado -> aparece ---
        $ocSC = alerta_check_dude_sem_contato($pdo, ['horas' => 6]);
        t_ok(!$ocSC, 'check_dude_sem_contato: contato recente não dispara');

        $pdo->exec("UPDATE portal_dude_estado SET atualizado_em = NOW() - INTERVAL 10 HOUR");
        $ocSC2 = alerta_check_dude_sem_contato($pdo, ['horas' => 6]);
        t_ok((bool) $ocSC2, 'check_dude_sem_contato: dispara quando passou do limiar');
        t_ok(str_contains($ocSC2[0]['detalhe'], 'última notificação há'), 'check_dude_sem_contato: detalhe tem o texto esperado');

        // --- renders ---
        t_ok(strpos(alerta_render_dude([]), 'vazio') !== false, 'render_dude([]) tem a msg vazia');
        t_ok(strlen(alerta_render_dude($ocDevice)) > 20, 'render_dude(ocorr) devolve HTML');
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
