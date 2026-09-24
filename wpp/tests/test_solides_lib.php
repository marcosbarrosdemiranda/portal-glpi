<?php
// Testes de solides_lib.php — saúde do Ponto (API Sólides): webhook push +
// relatório do chamado diário. Roda com `php wpp/tests/run.php`.
// As regras de alerta são funções puras (recebem a linha + o "agora"), então
// não mexem no estado real do Ponto em produção; o bloco de banco usa uma
// origem de teste própria e limpa no final.
require_once __DIR__ . '/../../solides_lib.php';
global $pdo;

$agora = strtotime('2026-09-24 10:00:00');
$linha = fn(string $recebido, string $status, array $checagens) => [
    'recebido_em' => $recebido,
    'status'      => $status,
    'resumo'      => 'x',
    'checagens'   => json_encode($checagens),
];
$chkOk    = ['id' => 'sync_rapido', 'nome' => 'Sync rápido (batidas, 10 min)', 'status' => 'ok', 'detalhe' => 'Último sucesso há 3 min'];
$chkFalha = ['id' => 'sync_inc', 'nome' => 'Sync horário (cadastros + reconciliação, 1h)', 'status' => 'falha', 'detalhe' => 'Sem sucesso há 2h10 (limite 2h00)'];
$chkAlerta = ['id' => 'whatsapp', 'nome' => 'Fila do WhatsApp', 'status' => 'alerta', 'detalhe' => '2 falha(s) nas últimas 24h'];

// ---------------------------------------------------------------------------
// solides_ocorrencias_checagem() — 1 ocorrência por checagem != ok
// ---------------------------------------------------------------------------
$oc = solides_ocorrencias_checagem($linha('2026-09-24 09:58:00', 'falha', [$chkOk, $chkFalha, $chkAlerta]), 15, $agora);
t_eq(count($oc), 2, 'checagem: só as checagens != ok viram ocorrência');
t_eq($oc[0]['chave'], 'solides:sync_inc', 'checagem: chave estável = solides:<id da checagem>');
t_eq($oc[0]['titulo'], 'Sync horário (cadastros + reconciliação, 1h)', 'checagem: titulo = nome da checagem');
t_ok(str_contains($oc[0]['detalhe'], 'Sem sucesso há 2h10'), 'checagem: detalhe traz o detalhe do Ponto');
t_ok(str_contains($oc[0]['detalhe'], 'falha'), 'checagem: detalhe traz a gravidade');
t_eq($oc[1]['chave'], 'solides:whatsapp', 'checagem: segunda ocorrência = whatsapp');

t_eq(solides_ocorrencias_checagem($linha('2026-09-24 09:58:00', 'ok', [$chkOk]), 15, $agora), [], 'checagem: tudo ok -> nenhuma ocorrência');
t_eq(solides_ocorrencias_checagem(null, 15, $agora), [], 'checagem: nunca recebeu -> nenhuma (quem avisa é o sem_contato)');
t_eq(solides_ocorrencias_checagem($linha('2026-09-24 09:40:00', 'falha', [$chkFalha]), 15, $agora), [],
    'checagem: diagnóstico velho (> limite de silêncio) -> nenhuma, pra não duplicar com sem_contato');
t_eq(solides_ocorrencias_checagem(['recebido_em' => '2026-09-24 09:58:00', 'status' => 'falha', 'resumo' => '', 'checagens' => 'lixo'], 15, $agora), [],
    'checagem: JSON de checagens inválido -> nenhuma (não quebra o worker)');

// ---------------------------------------------------------------------------
// solides_ocorrencias_sem_contato() — Ponto parou de mandar o POST
// ---------------------------------------------------------------------------
t_eq(solides_ocorrencias_sem_contato($linha('2026-09-24 09:50:00', 'ok', []), 15, $agora), [], 'sem_contato: POST há 10 min, limite 15 -> ok');
$sc = solides_ocorrencias_sem_contato($linha('2026-09-24 09:30:00', 'ok', []), 15, $agora);
t_eq(count($sc), 1, 'sem_contato: POST há 30 min, limite 15 -> 1 ocorrência');
t_eq($sc[0]['chave'], 'solides_sem_contato', 'sem_contato: chave fixa');
t_ok(str_contains($sc[0]['detalhe'], '30 min'), 'sem_contato: detalhe diz há quanto tempo');
$nunca = solides_ocorrencias_sem_contato(null, 15, $agora);
t_eq(count($nunca), 1, 'sem_contato: nunca recebeu -> 1 ocorrência');
t_ok(str_contains($nunca[0]['detalhe'], 'nunca'), 'sem_contato: detalhe diz que nunca recebeu');

// ---------------------------------------------------------------------------
// solides_relatorio_texto() — texto do chamado diário (formato back-gmais)
// ---------------------------------------------------------------------------
$rel = [
    'resumo' => 'Ontem (23/09): sem anormalidades · Hoje (24/09): 2 anormalidade(s), 1 ainda em aberto',
    'dias' => [
        ['dia' => 'ontem', 'data' => '2026-09-23', 'semHistorico' => false, 'anormalidades' => [], 'resumo' => 'Ontem (23/09): sem anormalidades'],
        ['dia' => 'hoje', 'data' => '2026-09-24', 'semHistorico' => false, 'resumo' => 'Hoje (24/09): 2 anormalidade(s), 1 ainda em aberto', 'anormalidades' => [
            ['titulo' => 'Sync rápido: erro', 'resolvida' => true, 'texto' => 'Sync rápido: erro às 03:00 — resolvido às 03:10 · HTTP 500 em /punch/'],
            ['titulo' => 'WhatsApp: 1 mensagem(ns) com falha', 'resolvida' => false, 'texto' => 'WhatsApp: 1 mensagem(ns) com falha às 08:00 — AINDA EM ABERTO · timeout'],
        ]],
    ],
];
$txt = solides_relatorio_texto($rel);
t_ok(str_starts_with($txt, 'Ponto (API Sólides)'), 'relatorio_texto: começa com o cabeçalho');
t_ok(str_contains($txt, "Ontem (23/09): sem anormalidades"), 'relatorio_texto: linha de resumo de ontem');
t_ok(str_contains($txt, "  ✅ Sync rápido: erro às 03:00 — resolvido às 03:10 · HTTP 500 em /punch/"), 'relatorio_texto: resolvida -> ✅ + texto pronto');
t_ok(str_contains($txt, "  ❌ WhatsApp: 1 mensagem(ns) com falha às 08:00"), 'relatorio_texto: em aberto -> ❌');
t_ok(strpos($txt, 'Ontem') < strpos($txt, 'Hoje'), 'relatorio_texto: ontem antes de hoje');
t_ok(str_starts_with($txt, "Ponto (API Sólides): ❌ 2 anormalidade(s), 1 em aberto"), 'relatorio_texto: cabeçalho diz quantas e quantas em aberto');

$semNada = solides_relatorio_texto(['dias' => [
    ['resumo' => 'Ontem (23/09): sem histórico registrado', 'semHistorico' => true, 'anormalidades' => []],
    ['resumo' => 'Hoje (24/09): sem anormalidades', 'semHistorico' => false, 'anormalidades' => []],
]]);
t_ok(str_starts_with($semNada, "Ponto (API Sólides): ✅ sem anormalidades\n"), 'relatorio_texto: nenhuma anormalidade -> cabeçalho "✅ sem anormalidades"');
t_ok(str_contains($semNada, 'Hoje (24/09): sem anormalidades'), 'relatorio_texto: sem anormalidades ainda lista o resumo de cada dia');

$todasOk = solides_relatorio_texto(['dias' => [
    ['resumo' => 'Hoje (24/09): 1 anormalidade(s), todas resolvidas', 'anormalidades' => [['resolvida' => true, 'texto' => 'x']]],
]]);
t_ok(str_starts_with($todasOk, 'Ponto (API Sólides): ⚠️ 1 anormalidade(s), todas resolvidas'), 'relatorio_texto: todas resolvidas -> ⚠️');

t_eq(solides_relatorio_texto(['dias' => 'lixo']), 'Ponto (API Sólides): relatório veio vazio ou em formato inesperado.', 'relatorio_texto: formato inesperado -> aviso, não quebra');

// ---------------------------------------------------------------------------
// Banco: registrar_payload / ultimo_diagnostico (origem de teste própria)
// ---------------------------------------------------------------------------
if ($pdo instanceof PDO) {
    $ORIGEM = '__teste_solides__';
    try {
        solides_registrar_payload($pdo, ['status' => 'alerta', 'resumo' => 'Ponto com alerta: X', 'checagens' => [$chkAlerta], 'evento' => 'teste'], $ORIGEM);
        $u = solides_ultimo_diagnostico($pdo, $ORIGEM);
        t_ok($u !== null, 'registrar_payload: grava a linha');
        t_eq($u['status'], 'alerta', 'registrar_payload: status gravado');
        t_eq($u['evento'], 'teste', 'registrar_payload: evento gravado');
        t_eq(json_decode($u['checagens'], true)[0]['id'], 'whatsapp', 'registrar_payload: checagens gravadas em JSON');

        solides_registrar_payload($pdo, ['status' => 'coisa-estranha', 'resumo' => 'y'], $ORIGEM);
        $u2 = solides_ultimo_diagnostico($pdo, $ORIGEM);
        t_eq($u2['status'], 'falha', 'registrar_payload: status desconhecido vira falha (mais seguro que ignorar)');
        t_eq($u2['checagens'], '[]', 'registrar_payload: sem checagens -> []');

        t_ok(solides_ultimo_diagnostico($pdo, '__origem_que_nao_existe__') === null, 'ultimo_diagnostico: origem sem registro -> null');
    } finally {
        $pdo->prepare("DELETE FROM portal_solides_saude WHERE origem = ?")->execute([$ORIGEM]);
    }
}
