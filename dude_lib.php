<?php
/**
 * dude_lib.php — monitoramento de rede (The Dude, 192.168.1.246) pra Central
 * de Alertas.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria a tabela de estado ao ser incluído (padrão do portal, igual a
 * alertas_tipos.php / backup_lib.php). O Dude não tem API — só empurra
 * mudança de estado via GET pro webhook (the_dude_webhook.php), autenticado
 * por um token único global (não por dispositivo — só existe 1 Dude).
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/wpp/db.php'; // wpp_cfg_get()/wpp_cfg_set() — mesmo mecanismo do grupo_alertas_jid

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_dude_estado (
        tipo          VARCHAR(20)  NOT NULL,
        chave         VARCHAR(160) NOT NULL,
        nome          VARCHAR(160) DEFAULT '',
        endereco      VARCHAR(120) DEFAULT '',
        status        ENUM('up','down') NOT NULL,
        detalhe       VARCHAR(255) DEFAULT '',
        atualizado_em DATETIME NOT NULL,
        PRIMARY KEY (tipo, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Token + estado ───────────────────────────── */

const DUDE_TIPOS_VALIDOS = ['device', 'link', 'latencia', 'service'];

/** String vazia = token ainda não gerado (webhook deve rejeitar tudo nesse caso). */
function dude_token_atual(PDO $pdo): string
{
    return (string) wpp_cfg_get('dude_token', '');
}

/** Gera e grava um novo token (invalida o anterior). Retorna o novo token. */
function dude_gerar_novo_token(PDO $pdo): string
{
    $token = bin2hex(random_bytes(16));
    wpp_cfg_set('dude_token', $token);
    return $token;
}

/** Upsert do estado de 1 (tipo, chave). $tipo já deve estar validado pelo chamador. */
function dude_registrar_estado(PDO $pdo, string $tipo, string $chave, string $nome, string $endereco, string $status, string $detalhe): void
{
    $pdo->prepare(
        "INSERT INTO portal_dude_estado (tipo, chave, nome, endereco, status, detalhe, atualizado_em)
         VALUES (?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE nome=VALUES(nome), endereco=VALUES(endereco),
             status=VALUES(status), detalhe=VALUES(detalhe), atualizado_em=NOW()"
    )->execute([$tipo, $chave, $nome, $endereco, $status, $detalhe]);
}

/** Timestamp (string DATETIME) da última notificação recebida de qualquer tipo, ou null se nunca houve. */
function dude_ultima_notificacao(PDO $pdo): ?string
{
    $v = $pdo->query("SELECT MAX(atualizado_em) FROM portal_dude_estado")->fetchColumn();
    return $v !== null && $v !== false ? (string) $v : null;
}

/* ───────────────────────────── Catálogo da Central de Alertas ───────────────────────────── */

/** @return array ocorrências: cada uma ['chave','titulo','loja','detalhe'] */
function dude_check_tipo(PDO $pdo, string $tipo): array
{
    $st = $pdo->prepare(
        "SELECT chave, nome, endereco, detalhe, atualizado_em
         FROM portal_dude_estado WHERE tipo = ? AND status = 'down'
         ORDER BY atualizado_em"
    );
    $st->execute([$tipo]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $desde = date('H:i', strtotime($r['atualizado_em']));
        $out[] = [
            'chave'   => 'dude:' . $tipo . ':' . $r['chave'],
            'titulo'  => $r['nome'] !== '' ? $r['nome'] : $r['chave'],
            'loja'    => '',
            'detalhe' => trim(trim($r['endereco'] . ' · ' . $r['detalhe'], ' ·')) . " (desde {$desde})",
        ];
    }
    return $out;
}

function alerta_check_dude_device(PDO $pdo, array $p): array   { return dude_check_tipo($pdo, 'device'); }
function alerta_check_dude_link(PDO $pdo, array $p): array     { return dude_check_tipo($pdo, 'link'); }
function alerta_check_dude_latencia(PDO $pdo, array $p): array { return dude_check_tipo($pdo, 'latencia'); }
function alerta_check_dude_service(PDO $pdo, array $p): array  { return dude_check_tipo($pdo, 'service'); }

/** @return array ocorrências: 0 ou 1 item (watchdog geral, não por dispositivo) */
function alerta_check_dude_sem_contato(PDO $pdo, array $p): array
{
    $ultima = dude_ultima_notificacao($pdo);
    if ($ultima === null) return []; // nunca recebeu nada ainda -> não é "silêncio", é "nunca configurado"

    $horas     = (int) ($p['horas'] ?? 6);
    $decorrido = time() - strtotime($ultima);
    if ($decorrido < $horas * 3600) return [];

    $h = max(0, (int) floor($decorrido / 3600));
    return [[
        'chave'   => 'dude:sem_contato',
        'titulo'  => 'The Dude não está notificando',
        'loja'    => '',
        'detalhe' => "última notificação há {$h}h — verifique o Dude ou a rede até ele",
    ]];
}

/** innerHTML do corpo da seção — tabela simples, reusada pelos 5 tipos do Dude. */
function alerta_render_dude(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nada fora do ar.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<table><thead><tr><th>Dispositivo/Enlace</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($ocorr as $o) {
        $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
