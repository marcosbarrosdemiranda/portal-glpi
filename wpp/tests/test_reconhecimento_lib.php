<?php
require_once __DIR__ . '/../agenda/db.php';
require_once __DIR__ . '/../../reconhecimento_lib.php';

global $pdo;
echo "Testando reconhecimento_lib...\n";

function t_eq($a, $b, $msg) {
    if ($a !== $b) {
        throw new \Exception("FALHOU: $msg (esperado " . var_export($b, true) . ", obtido " . var_export($a, true) . ")");
    }
    echo "  OK: $msg\n";
}

function t_ok($cond, $msg) {
    if (!$cond) {
        throw new \Exception("FALHOU: $msg");
    }
    echo "  OK: $msg\n";
}

$agora = strtotime('2026-11-20 10:00:00');
$linha = fn(string $recebido, string $status, array $checagens) => [
    'recebido_em' => $recebido,
    'status'      => $status,
    'resumo'      => 'x',
    'checagens'   => json_encode($checagens),
];

$chkOk    = ['id' => 'defense', 'nome' => 'Hikvision Defense', 'status' => 'ok', 'detalhe' => 'Conectado'];
$chkFalha = ['id' => 'evolution', 'nome' => 'Evolution API', 'status' => 'falha', 'detalhe' => 'Sem resposta do container'];

// Teste de ocorrencias de checagem
$oc = reconhecimento_ocorrencias_checagem($linha('2026-11-20 09:58:00', 'falha', [$chkOk, $chkFalha]), 15, $agora);
t_eq(count($oc), 1, 'checagem: só checagens != ok viram ocorrência');
t_eq($oc[0]['chave'], 'reconhecimento:evolution', 'chave estável');
t_eq($oc[0]['titulo'], 'Evolution API', 'titulo da checagem');

// Teste de sem contato
t_eq(reconhecimento_ocorrencias_sem_contato($linha('2026-11-20 09:50:00', 'ok', []), 15, $agora), [], 'sem_contato: recente -> ok');
$sc = reconhecimento_ocorrencias_sem_contato($linha('2026-11-20 09:30:00', 'ok', []), 15, $agora);
t_eq(count($sc), 1, 'sem_contato: antigo -> 1 ocorrência');
t_eq(reconhecimento_ocorrencias_sem_contato(null, 15, $agora)[0]['chave'], 'reconhecimento_sem_contato', 'nunca recebeu');

// Teste de banco (upsert e ultimo diagnostico)
if ($pdo instanceof PDO) {
    $ORIGEM = '__teste_reconhecimento__';
    try {
        reconhecimento_registrar_payload($pdo, [
            'status' => 'alerta',
            'resumo' => 'Teste resumo',
            'checagens' => [$chkFalha],
            'evento' => 'saude'
        ], $ORIGEM);

        $u = reconhecimento_ultimo_diagnostico($pdo, $ORIGEM);
        t_ok($u !== null, 'registrar_payload: grava com sucesso');
        t_eq($u['status'], 'alerta', 'status gravado');
        t_eq($u['evento'], 'saude', 'evento gravado');
    } finally {
        $pdo->prepare("DELETE FROM portal_reconhecimento_saude WHERE origem = ?")->execute([$ORIGEM]);
    }
}

echo "Todos os testes de reconhecimento_lib passaram com sucesso!\n";
