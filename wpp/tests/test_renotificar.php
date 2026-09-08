<?php
// Testes do reenvio manual (wpp/renotificar.php).
// Roda com `php wpp/tests/run.php`. As validações puras (ticket/alvo inválidos)
// não tocam o banco — retornam antes de qualquer query. Os casos que dependem
// de banco/envio real (ticket real, técnico com telefone, mock de envio) ficam
// para o teste manual no deploy.

require_once __DIR__ . '/../renotificar.php';

// Um PDO qualquer só para satisfazer a assinatura; os casos abaixo retornam
// antes de usá-lo. Usa o $pdo real se já existir (suíte roda contra a PROD),
// senão um SQLite em memória.
global $pdo;
$pdoTeste = ($pdo instanceof PDO) ? $pdo : null;
if ($pdoTeste === null) {
    try { $pdoTeste = new PDO('sqlite::memory:'); } catch (\Throwable $e) { $pdoTeste = null; }
}

if ($pdoTeste instanceof PDO) {
    // ticket_id <= 0 -> {ok:false} sem tocar no banco
    $r = wpp_renotificar($pdoTeste, 0, 'grupo');
    t_ok(($r['ok'] ?? null) === false && !empty($r['erro']), 'wpp_renotificar: ticket_id=0 retorna ok=false');

    $r = wpp_renotificar($pdoTeste, -5, 'tecnico');
    t_ok(($r['ok'] ?? null) === false, 'wpp_renotificar: ticket_id negativo retorna ok=false');

    // alvo inválido -> {ok:false} sem tocar no banco
    $r = wpp_renotificar($pdoTeste, 123, 'ninguem');
    t_eq($r['erro'] ?? null, 'alvo inválido', 'wpp_renotificar: alvo inválido retorna erro "alvo inválido"');

    $r = wpp_renotificar($pdoTeste, 123, '');
    t_ok(($r['ok'] ?? null) === false, 'wpp_renotificar: alvo vazio retorna ok=false');
} else {
    echo "  -- wpp_renotificar(): sem PDO disponível (nem SQLite), testes puros pulados\n";
}
