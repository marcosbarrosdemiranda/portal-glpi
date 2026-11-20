<?php
/**
 * reconhecimento_lib.php — saúde da aplicação de Reconhecimento Facial (coletor FaceEventsIntelbras)
 * pra Central de Alertas.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria a tabela ao ser incluído (padrão do portal, igual a solides_lib.php).
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/wpp/db.php'; // wpp_cfg_get()/wpp_cfg_set()

const RECONHECIMENTO_ORIGEM = 'reconhecimento';

// Cria a tabela ao incluir (padrão do portal). 1 linha por origem.
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_reconhecimento_saude (
        origem      VARCHAR(40) PRIMARY KEY,
        status      ENUM('ok','alerta','falha') NOT NULL,
        resumo      VARCHAR(1000) NOT NULL DEFAULT '',
        checagens   JSON,
        evento      VARCHAR(20) NOT NULL DEFAULT 'saude',
        recebido_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Tokens / config ───────────────────────────── */

/** Token que o coletor manda na URL do webhook (?token=). '' = não gerado ainda. */
function reconhecimento_webhook_token(): string
{
    return (string) wpp_cfg_get('reconhecimento_webhook_token', '');
}

/** Gera e grava um novo token de entrada (invalida o anterior). */
function reconhecimento_gerar_webhook_token(): string
{
    $token = bin2hex(random_bytes(16));
    wpp_cfg_set('reconhecimento_webhook_token', $token);
    return $token;
}

/* ───────────────────────────── Webhook: gravação ───────────────────────────── */

/** Grava o diagnóstico recebido como o último da origem (upsert). */
function reconhecimento_registrar_payload(PDO $pdo, array $payload, string $origem = RECONHECIMENTO_ORIGEM): void
{
    $status = (string) ($payload['status'] ?? '');
    if (!in_array($status, ['ok', 'alerta', 'falha'], true)) $status = 'falha';

    $checagens = is_array($payload['checagens'] ?? null) ? array_values($payload['checagens']) : [];
    $pdo->prepare("INSERT INTO portal_reconhecimento_saude (origem, status, resumo, checagens, evento, recebido_em)
                   VALUES (?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE status = VALUES(status), resumo = VALUES(resumo),
                       checagens = VALUES(checagens), evento = VALUES(evento), recebido_em = VALUES(recebido_em)")
        ->execute([
            $origem,
            $status,
            mb_substr((string) ($payload['resumo'] ?? ''), 0, 1000),
            json_encode($checagens, JSON_UNESCAPED_UNICODE),
            mb_substr((string) ($payload['evento'] ?? 'saude'), 0, 20),
        ]);
}

/** @return array|null linha de portal_reconhecimento_saude */
function reconhecimento_ultimo_diagnostico(PDO $pdo, string $origem = RECONHECIMENTO_ORIGEM): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_reconhecimento_saude WHERE origem = ?");
    $st->execute([$origem]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ───────────────────────────── Regras de alerta (puras) ───────────────────────────── */

/** Minutos inteiros desde recebido_em até $agoraTs. */
function reconhecimento_minutos_desde(string $recebidoEm, int $agoraTs): int
{
    return max(0, (int) floor(($agoraTs - strtotime($recebidoEm)) / 60));
}

/**
 * 1 ocorrência por checagem do Reconhecimento Facial que não está ok.
 * @return array ocorrências: cada uma ['chave','titulo','loja','detalhe']
 */
function reconhecimento_ocorrencias_checagem(?array $linha, int $minSemContato, int $agoraTs): array
{
    if ($linha === null) return [];
    if (reconhecimento_minutos_desde((string) $linha['recebido_em'], $agoraTs) > $minSemContato) return [];

    $checagens = json_decode((string) $linha['checagens'], true);
    if (!is_array($checagens)) return [];

    $out = [];
    foreach ($checagens as $c) {
        if (!is_array($c) || ($c['status'] ?? 'ok') === 'ok') continue;
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $out[] = [
            'chave'   => 'reconhecimento:' . $id,
            'titulo'  => (string) ($c['nome'] ?? $id),
            'loja'    => '',
            'detalhe' => '[' . $c['status'] . '] ' . (string) ($c['detalhe'] ?? ''),
        ];
    }
    return $out;
}

/** @return array 0 ou 1 ocorrência: o coletor parou de mandar o POST. */
function reconhecimento_ocorrencias_sem_contato(?array $linha, int $minSemContato, int $agoraTs): array
{
    if ($linha === null) {
        $detalhe = 'nunca recebeu o diagnóstico do Reconhecimento Facial (webhook configurado no coletor?)';
    } else {
        $min = reconhecimento_minutos_desde((string) $linha['recebido_em'], $agoraTs);
        if ($min <= $minSemContato) return [];
        $detalhe = 'sem diagnóstico do Reconhecimento Facial há ' . ($min < 60 ? $min . ' min' : floor($min / 60) . 'h' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT))
                 . ' (esperado a cada ' . $minSemContato . ' min)';
    }
    return [[
        'chave'   => 'reconhecimento_sem_contato',
        'titulo'  => 'Reconhecimento Facial',
        'loja'    => '',
        'detalhe' => $detalhe,
    ]];
}

/* ───────────────────────────── Catálogo da Central de Alertas ───────────────────────────── */

function alerta_check_reconhecimento_checagem(PDO $pdo, array $p): array
{
    return reconhecimento_ocorrencias_checagem(reconhecimento_ultimo_diagnostico($pdo), (int) ($p['minutos'] ?? 15), time());
}

function alerta_check_reconhecimento_sem_contato(PDO $pdo, array $p): array
{
    return reconhecimento_ocorrencias_sem_contato(reconhecimento_ultimo_diagnostico($pdo), (int) ($p['minutos'] ?? 15), time());
}

/** innerHTML do corpo da seção. */
function alerta_render_reconhecimento(array $ocorr, string $tipo = ''): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Reconhecimento Facial sem problemas.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<table><thead><tr><th>Checagem</th><th>Detalhe</th><th></th></tr></thead><tbody>';
    foreach ($ocorr as $o) {
        $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td>'
              . '<td>' . alerta_botao_dispensar_html($tipo, (string) $o['chave']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
