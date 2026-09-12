<?php
// Endpoint chamado pelo back-gmais (webhook de resultado de job de backup).
// Responde 200 SEMPRE — token inválido ou payload quebrado não devem fazer
// o back-gmais reentregar em loop nem revelar nada a quem sondar a URL.
// Sem sessão/login: quem autentica é o token (?m=) cadastrado em
// backup_maquinas.php, dentro da URL colada no config.yaml de cada servidor.
header('Content-Type: application/json');

$boot_ok = true;
try {
    require_once __DIR__ . '/agenda/db.php';
    require_once __DIR__ . '/backup_lib.php';
} catch (\Throwable $e) {
    $boot_ok = false;
    error_log('webhook_backup: boot falhou: ' . $e->getMessage());
}

if ($boot_ok) {
    try {
        global $pdo;
        $token   = (string) ($_GET['m'] ?? '');
        $maquina = $token !== '' ? backup_maquina_por_token($pdo, $token) : null;

        if ($maquina !== null) {
            $raw      = file_get_contents('php://input');
            $payload  = json_decode((string) $raw, true);
            $mensagem = is_array($payload) ? (string) ($payload['message'] ?? '') : '';
            $p        = backup_parse_mensagem($mensagem);

            $statusesValidos = ['success', 'warning', 'error', 'cancelled', 'running'];
            // status desconhecido/ausente -> trata como erro (mais seguro que ignorar em silêncio)
            $status = in_array($p['status'], $statusesValidos, true) ? $p['status'] : 'error';

            backup_registrar_execucao($pdo, (int) $maquina['id'], $p['politica'], $status, $mensagem);
        }
    } catch (\Throwable $e) {
        error_log('webhook_backup: ' . $e->getMessage());
    }
}

echo json_encode(['ok' => true]);
