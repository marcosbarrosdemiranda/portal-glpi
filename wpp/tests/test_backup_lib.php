<?php
// Testes de backup_lib.php — parse do webhook, CRUD de máquina e os 2 tipos
// novos do catálogo (backup_erro / backup_silencio).
// Roda com `php wpp/tests/run.php` (blocos com banco só valem no deploy,
// via `docker exec glpi-web php .../wpp/tests/run.php`).
require_once __DIR__ . '/../../backup_lib.php';
global $pdo;

// ---------------------------------------------------------------------------
// backup_parse_mensagem() / backup_token_novo() — puros, sem banco
// ---------------------------------------------------------------------------
$msg = "Política: Backup Diário\nStatus: error\nModo: mirror\nJob: abc123\nInício: 2026-09-12T03:00:00-04:00\nErro: rede inacessível";
$p = backup_parse_mensagem($msg);
t_eq($p['politica'], 'Backup Diário', 'parse: política');
t_eq($p['status'], 'error', 'parse: status');
t_eq($p['erro'], 'rede inacessível', 'parse: erro');

$p2 = backup_parse_mensagem("Política: X\nStatus: success\n");
t_eq($p2['status'], 'success', 'parse: success sem campo erro');
t_ok($p2['erro'] === null, 'parse: erro ausente vira null');

t_eq(backup_parse_mensagem('')['politica'], null, 'parse: string vazia não lança, tudo null');
t_eq(backup_parse_mensagem("linha sem dois-pontos")['status'], null, 'parse: linha sem ":" é ignorada');

// --- Arquivos/Dados (tamanho) — vem em mensagens de sucesso reais do back-gmais ---
$msgOk = "Política: Zukking Precos - Envio\nStatus: success\nModo: mirror\nJob: 2ebfe544\nInício: 2026-09-14T06:30:00-04:00\nFim: 2026-09-14T06:30:09-04:00\nArquivos: 1\nDados: 13.7 MiB";
$pOk = backup_parse_mensagem($msgOk);
t_eq($pOk['arquivos'], '1', 'parse: arquivos');
t_eq($pOk['dados'], '13.7 MiB', 'parse: dados (tamanho)');
t_eq($pOk['fim'], '2026-09-14T06:30:09-04:00', 'parse: fim');
t_ok(backup_parse_mensagem('')['arquivos'] === null, 'parse: arquivos ausente -> null');

t_ok(strlen(backup_token_novo()) === 32, 'token: 32 chars');
t_ok(backup_token_novo() !== backup_token_novo(), 'token: não repete');

// ---------------------------------------------------------------------------
// CRUD de máquina + checks/renders do catálogo — precisa de banco
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $NOME = '__teste_backup_maquina__';
    $limpa = function () use ($pdo, $NOME) {
        $pdo->prepare("DELETE FROM portal_backup_maquinas WHERE nome LIKE ?")->execute([$NOME . '%']);
    };

    try {
        $limpa();

        // --- criar / listar / buscar por token ---
        $m = backup_maquina_criar($pdo, $NOME, null);
        t_ok(strlen($m['token']) === 32, 'criar: token de 32 chars');
        t_ok((bool) array_filter(backup_maquinas_listar($pdo), fn($r) => $r['nome'] === $NOME), 'criar: aparece em backup_maquinas_listar');

        $achado = backup_maquina_por_token($pdo, $m['token']);
        t_ok($achado !== null && (int) $achado['id'] === (int) $m['id'], 'por_token: acha a máquina certa');
        t_ok(backup_maquina_por_token($pdo, 'token-que-nao-existe') === null, 'por_token: token errado -> null');

        // --- registrar execução: grava linha + atualiza último contato ---
        backup_registrar_execucao($pdo, (int) $m['id'], 'Política Teste', 'error', "Política: Política Teste\nStatus: error\nErro: falha simulada");
        $st = $pdo->prepare("SELECT ultimo_contato, ultima_politica, ultimo_status FROM portal_backup_maquinas WHERE id = ?");
        $st->execute([$m['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        t_ok($row['ultimo_contato'] !== null, 'registrar_execucao: ultimo_contato preenchido');
        t_eq($row['ultima_politica'], 'Política Teste', 'registrar_execucao: ultima_politica gravada');
        t_eq($row['ultimo_status'], 'error', 'registrar_execucao: ultimo_status gravado');

        // --- inativa não entra em por_token, mas continua em backup_erro até... ---
        backup_maquina_atualizar($pdo, (int) $m['id'], $NOME, false, null);
        t_ok(backup_maquina_por_token($pdo, $m['token']) === null, 'atualizar: máquina inativa não é achada por token');
        backup_maquina_atualizar($pdo, (int) $m['id'], $NOME, true, null); // reativa pro resto do teste

        // --- alerta_check_backup_erro: aparece com erro, some com sucesso ---
        $oc = alerta_check_backup_erro($pdo, []);
        $achouErro = array_filter($oc, fn($o) => $o['chave'] === 'backup_erro:' . $m['id'] . ':Política Teste');
        t_ok(count($achouErro) === 1, 'check_backup_erro: 1 ocorrência pra política com última execução = error');
        $o = array_values($achouErro)[0];
        t_eq($o['titulo'], $NOME, 'check_backup_erro: titulo = nome da máquina');
        t_ok(str_contains($o['detalhe'], 'falha simulada'), 'check_backup_erro: detalhe traz o erro');

        backup_registrar_execucao($pdo, (int) $m['id'], 'Política Teste', 'success', "Política: Política Teste\nStatus: success");
        $oc2 = alerta_check_backup_erro($pdo, []);
        t_ok(!array_filter($oc2, fn($o) => $o['chave'] === 'backup_erro:' . $m['id'] . ':Política Teste'), 'check_backup_erro: some depois de um success');

        // --- alerta_check_backup_silencio: nível de MÁQUINA (não por política —
        // servidor roda políticas com frequências bem diferentes, ver comentário
        // de alerta_check_backup_silencio) ---
        $ocS = alerta_check_backup_silencio($pdo, ['horas' => 100000]);
        t_ok(!array_filter($ocS, fn($o) => $o['chave'] === 'backup_silencio:' . $m['id']), 'check_backup_silencio: contato recente não aparece com limiar folgado');

        $ocS2 = alerta_check_backup_silencio($pdo, ['horas' => 0]);
        t_ok((bool) array_filter($ocS2, fn($o) => $o['chave'] === 'backup_silencio:' . $m['id']), 'check_backup_silencio: limiar 0h pega qualquer contato');

        // --- máquina sem NENHUMA execução ainda -> "nunca contatou" ---
        $m2 = backup_maquina_criar($pdo, $NOME . '_nunca', null);
        $ocNunca = alerta_check_backup_silencio($pdo, ['horas' => 999999]);
        t_ok((bool) array_filter($ocNunca, fn($o) => $o['chave'] === 'backup_silencio:' . $m2['id']), 'check_backup_silencio: máquina sem execução alguma aparece como "nunca contatou" mesmo com limiar folgado');
        backup_maquina_excluir($pdo, (int) $m2['id']);

        // --- multi-política: uma política ficar parada NÃO deve alertar sozinha
        // enquanto outra política da mesma máquina continuar reportando dentro
        // do prazo (é o comportamento desejado — silêncio é por servidor) ---
        backup_registrar_execucao($pdo, (int) $m['id'], 'Política A', 'success', "Política: Política A\nStatus: success");
        $pdo->prepare("UPDATE portal_backup_execucoes SET recebido_em = NOW() - INTERVAL 100 HOUR WHERE maquina_id = ? AND politica = 'Política A'")->execute([$m['id']]);
        backup_registrar_execucao($pdo, (int) $m['id'], 'Política B', 'success', "Política: Política B\nStatus: success"); // recente -> ultimo_contato da máquina fica fresco
        $ocMulti = alerta_check_backup_silencio($pdo, ['horas' => 26]);
        t_ok(!array_filter($ocMulti, fn($o) => $o['chave'] === 'backup_silencio:' . $m['id']), 'check_backup_silencio: política B recente mantém a máquina "viva" mesmo com política A parada há 100h');

        // --- renders não lançam e têm o formato esperado ---
        t_ok(strpos(alerta_render_backup_erro([]), 'vazio') !== false, 'render_backup_erro([]) tem a msg vazia');
        t_ok(strpos(alerta_render_backup_silencio([]), 'vazio') !== false, 'render_backup_silencio([]) tem a msg vazia');
        t_ok(strlen(alerta_render_backup_erro($oc)) > 20, 'render_backup_erro(ocorr) devolve HTML');
        t_ok(strlen(alerta_render_backup_silencio($ocS2)) > 20, 'render_backup_silencio(ocorr) devolve HTML');

        // --- backup_resumo_dia / backup_resumo_texto ---
        $DATA_TESTE = '2099-06-15'; // data bem no futuro, nao colide com execucao real
        backup_registrar_execucao($pdo, (int) $m['id'], 'Política Resumo', 'success', "Política: Política Resumo\nStatus: success\nArquivos: 42\nDados: 3.2 GiB\nInício: {$DATA_TESTE}T04:00:00-04:00\nFim: {$DATA_TESTE}T04:05:00-04:00");
        $pdo->prepare("UPDATE portal_backup_execucoes SET recebido_em = ? WHERE maquina_id = ? AND politica = 'Política Resumo'")
            ->execute([$DATA_TESTE . ' 04:00:00', $m['id']]);

        backup_registrar_execucao($pdo, (int) $m['id'], 'Política Resumo Erro', 'error', "Política: Política Resumo Erro\nStatus: error\nErro: disco cheio");
        $pdo->prepare("UPDATE portal_backup_execucoes SET recebido_em = ? WHERE maquina_id = ? AND politica = 'Política Resumo Erro'")
            ->execute([$DATA_TESTE . ' 05:00:00', $m['id']]);

        // execucao de outro dia nao deve entrar no resumo
        backup_registrar_execucao($pdo, (int) $m['id'], 'Política Fora Do Dia', 'success', "Política: Política Fora Do Dia\nStatus: success");
        $pdo->prepare("UPDATE portal_backup_execucoes SET recebido_em = ? WHERE maquina_id = ? AND politica = 'Política Fora Do Dia'")
            ->execute([date('Y-m-d H:i:s', strtotime($DATA_TESTE) + 86400 * 5), $m['id']]);

        $resumo = backup_resumo_dia($pdo, $DATA_TESTE);
        t_eq(count($resumo), 2, 'resumo_dia: só as 2 execuções da data pedida (ignora a de outro dia)');
        $porPolitica = array_column($resumo, null, 'politica');
        t_eq($porPolitica['Política Resumo']['status'], 'success', 'resumo_dia: status da execução success');
        t_eq($porPolitica['Política Resumo']['arquivos'], '42', 'resumo_dia: arquivos extraído da mensagem');
        t_eq($porPolitica['Política Resumo']['dados'], '3.2 GiB', 'resumo_dia: dados (tamanho) extraído da mensagem');
        t_eq($porPolitica['Política Resumo']['maquina'], $NOME, 'resumo_dia: nome da máquina');
        t_eq($porPolitica['Política Resumo']['inicio'], "{$DATA_TESTE}T04:00:00-04:00", 'resumo_dia: inicio extraído da mensagem');
        t_eq($porPolitica['Política Resumo']['fim'], "{$DATA_TESTE}T04:05:00-04:00", 'resumo_dia: fim extraído da mensagem');
        t_eq($porPolitica['Política Resumo Erro']['status'], 'error', 'resumo_dia: status da execução error');
        t_ok($porPolitica['Política Resumo Erro']['inicio'] === null, 'resumo_dia: sem Início na mensagem -> null, não lança');

        t_eq(backup_hora_curta("{$DATA_TESTE}T04:00:00-04:00"), '04:00', 'hora_curta: extrai HH:MM do timestamp ISO');
        t_ok(backup_hora_curta(null) === null, 'hora_curta: null -> null');
        t_ok(backup_hora_curta('lixo-invalido') === null, 'hora_curta: string inválida -> null, não lança');

        $texto = backup_resumo_texto($resumo, $DATA_TESTE);
        t_ok(str_contains($texto, $DATA_TESTE) || str_contains($texto, '15/06/2099'), 'resumo_texto: cita a data');
        t_ok(str_contains($texto, 'Política Resumo') && str_contains($texto, '3.2 GiB'), 'resumo_texto: cita política e tamanho');
        t_ok(str_contains($texto, 'Política Resumo Erro'), 'resumo_texto: cita a que deu erro também');
        t_ok(str_contains($texto, $NOME), 'resumo_texto: cita o nome da máquina');
        t_ok(str_contains($texto, '[04:00–04:05]'), 'resumo_texto: cita o horário de início-fim quando diferentes');

        $resumoVazio = backup_resumo_dia($pdo, '2099-01-01');
        t_eq(count($resumoVazio), 0, 'resumo_dia: data sem execução -> array vazio');
        $textoVazio = backup_resumo_texto($resumoVazio, '2099-01-01');
        t_ok(str_contains($textoVazio, 'Nenhum') || str_contains($textoVazio, 'nenhum'), 'resumo_texto: mensagem clara quando não há execução no dia');

        $pdo->prepare("DELETE FROM portal_backup_execucoes WHERE maquina_id = ? AND politica IN ('Política Resumo','Política Resumo Erro','Política Fora Do Dia')")->execute([$m['id']]);

        // --- excluir: CASCADE limpa as execuções ---
        $idExcluir = (int) $m['id'];
        backup_maquina_excluir($pdo, $idExcluir);
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM portal_backup_execucoes WHERE maquina_id = ?");
        $st2->execute([$idExcluir]);
        t_eq((int) $st2->fetchColumn(), 0, 'excluir: CASCADE apaga as execucoes da maquina');
    } finally {
        $limpa();
    }
} else {
    echo "  -- testes de banco (backup_lib): banco indisponível, pulados\n";
}
