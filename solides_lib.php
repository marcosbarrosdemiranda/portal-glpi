<?php
/**
 * solides_lib.php — saúde do Ponto (API Sólides, 192.168.1.246) pra Central
 * de Alertas e pro chamado diário "Backup, Relatórios e Banco de Dados".
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria a tabela ao ser incluído (padrão do portal, igual a backup_lib.php).
 *
 * Dois fluxos, mesmo token de cada lado:
 *   - PUSH (alerta): o Ponto faz POST do diagnóstico a cada 5 min em
 *     webhook_solides.php?token=<solides_webhook_token>. Guardamos só o
 *     último diagnóstico — o motor da Central decide 🔔/✅/⏰ a partir dele.
 *   - PULL (relatório): solides_resumo_ajax.php busca /api/saude/relatorio
 *     no Ponto com o header X-Token-Saude (token gerado no painel do Ponto).
 * Os dois tokens ficam em portal_wpp_config (wpp_cfg_*), nunca no git.
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/wpp/db.php'; // wpp_cfg_get()/wpp_cfg_set() — mesmo mecanismo do token do Dude

const SOLIDES_ORIGEM         = 'ponto-gmais';
const SOLIDES_BASE_URL_PADRAO = 'https://ponto.grupogmais.com:7413'; // pelo IP o Caddy não fecha TLS (sem SNI)

// cria a tabela ao incluir (padrão do portal). 1 linha por origem — hoje só
// existe o Ponto; a origem separa também a linha usada pelos testes.
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_solides_saude (
        origem      VARCHAR(40) PRIMARY KEY,
        status      ENUM('ok','alerta','falha') NOT NULL,
        resumo      VARCHAR(1000) NOT NULL DEFAULT '',
        checagens   JSON,
        evento      VARCHAR(20) NOT NULL DEFAULT 'saude',
        recebido_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Tokens / config ───────────────────────────── */

/** Token que o Ponto manda na URL do webhook (?token=). '' = não gerado ainda. */
function solides_webhook_token(): string
{
    return (string) wpp_cfg_get('solides_webhook_token', '');
}

/** Gera e grava um novo token de entrada (invalida o anterior). */
function solides_gerar_webhook_token(): string
{
    $token = bin2hex(random_bytes(16));
    wpp_cfg_set('solides_webhook_token', $token);
    return $token;
}

/** Token X-Token-Saude do painel do Ponto (Saúde do sistema → Monitoramento externo). */
function solides_token_saude(): string
{
    return (string) wpp_cfg_get('solides_token_saude', '');
}

function solides_base_url(): string
{
    $url = trim((string) wpp_cfg_get('solides_base_url', ''));
    return rtrim($url !== '' ? $url : SOLIDES_BASE_URL_PADRAO, '/');
}

/* ───────────────────────────── Webhook: gravação ───────────────────────────── */

/** Grava o diagnóstico recebido como o último da origem (upsert). */
function solides_registrar_payload(PDO $pdo, array $payload, string $origem = SOLIDES_ORIGEM): void
{
    $status = (string) ($payload['status'] ?? '');
    // status desconhecido/ausente -> trata como falha (mais seguro que ignorar em silêncio)
    if (!in_array($status, ['ok', 'alerta', 'falha'], true)) $status = 'falha';

    $checagens = is_array($payload['checagens'] ?? null) ? array_values($payload['checagens']) : [];
    $pdo->prepare("INSERT INTO portal_solides_saude (origem, status, resumo, checagens, evento, recebido_em)
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

/** @return array|null linha de portal_solides_saude (checagens em JSON cru) */
function solides_ultimo_diagnostico(PDO $pdo, string $origem = SOLIDES_ORIGEM): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_solides_saude WHERE origem = ?");
    $st->execute([$origem]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ───────────────────────────── Regras de alerta (puras) ───────────────────────────── */

/** Minutos inteiros desde recebido_em até $agoraTs. */
function solides_minutos_desde(string $recebidoEm, int $agoraTs): int
{
    return max(0, (int) floor(($agoraTs - strtotime($recebidoEm)) / 60));
}

/**
 * 1 ocorrência por checagem do Ponto que não está ok. Diagnóstico mais velho
 * que o limite de silêncio não gera nada: aí quem avisa é o sem_contato, sem
 * repetir um problema que pode já ter passado.
 *
 * @return array ocorrências: cada uma ['chave','titulo','loja','detalhe']
 */
function solides_ocorrencias_checagem(?array $linha, int $minSemContato, int $agoraTs): array
{
    if ($linha === null) return [];
    if (solides_minutos_desde((string) $linha['recebido_em'], $agoraTs) > $minSemContato) return [];

    $checagens = json_decode((string) $linha['checagens'], true);
    if (!is_array($checagens)) return [];

    $out = [];
    foreach ($checagens as $c) {
        if (!is_array($c) || ($c['status'] ?? 'ok') === 'ok') continue;
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $out[] = [
            'chave'   => 'solides:' . $id,
            'titulo'  => (string) ($c['nome'] ?? $id),
            'loja'    => '',
            'detalhe' => '[' . $c['status'] . '] ' . (string) ($c['detalhe'] ?? ''),
        ];
    }
    return $out;
}

/** @return array 0 ou 1 ocorrência: o Ponto parou de mandar o POST de 5 em 5 min. */
function solides_ocorrencias_sem_contato(?array $linha, int $minSemContato, int $agoraTs): array
{
    if ($linha === null) {
        $detalhe = 'nunca recebeu o diagnóstico do Ponto (webhook ligado no painel do Ponto?)';
    } else {
        $min = solides_minutos_desde((string) $linha['recebido_em'], $agoraTs);
        if ($min <= $minSemContato) return [];
        $detalhe = 'sem diagnóstico do Ponto há ' . ($min < 60 ? $min . ' min' : floor($min / 60) . 'h' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT))
                 . ' (esperado a cada 5 min)';
    }
    return [[
        'chave'   => 'solides_sem_contato',
        'titulo'  => 'Ponto (API Sólides)',
        'loja'    => '',
        'detalhe' => $detalhe,
    ]];
}

/* ───────────────────────────── Catálogo da Central de Alertas ───────────────────────────── */

function alerta_check_solides_checagem(PDO $pdo, array $p): array
{
    return solides_ocorrencias_checagem(solides_ultimo_diagnostico($pdo), (int) ($p['minutos'] ?? 15), time());
}

function alerta_check_solides_sem_contato(PDO $pdo, array $p): array
{
    return solides_ocorrencias_sem_contato(solides_ultimo_diagnostico($pdo), (int) ($p['minutos'] ?? 15), time());
}

/** innerHTML do corpo da seção — tabela simples, mesmo visual dos outros tipos. */
function alerta_render_solides(array $ocorr, string $tipo = ''): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Ponto (API Sólides) sem problemas.</div>';
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

/* ───────────────────────────── Relatório do chamado diário (pull) ───────────────────────────── */

/**
 * GET /api/saude/relatorio no Ponto.
 * @return array ['ok'=>bool, 'relatorio'=>array|null, 'erro'=>string|null]
 */
function solides_buscar_relatorio(): array
{
    $token = solides_token_saude();
    if ($token === '') return ['ok' => false, 'relatorio' => null, 'erro' => 'token do Ponto não configurado (Alertas → Ponto / API Sólides)'];

    $ch = curl_init(solides_base_url() . '/api/saude/relatorio');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['X-Token-Saude: ' . $token, 'Accept: application/json'],
    ]);
    $corpo = curl_exec($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro  = curl_error($ch);
    curl_close($ch);

    if ($corpo === false) return ['ok' => false, 'relatorio' => null, 'erro' => 'sem resposta do Ponto: ' . $erro];
    if ($http === 401)    return ['ok' => false, 'relatorio' => null, 'erro' => 'token do Ponto recusado (401) — copie de novo no painel do Ponto'];
    if ($http !== 200)    return ['ok' => false, 'relatorio' => null, 'erro' => 'Ponto respondeu HTTP ' . $http];

    $rel = json_decode((string) $corpo, true);
    if (!is_array($rel)) return ['ok' => false, 'relatorio' => null, 'erro' => 'resposta do Ponto não é JSON'];
    return ['ok' => true, 'relatorio' => $rel, 'erro' => null];
}

/**
 * Texto pronto pra resposta do chamado diário — mesmo formato do resumo de
 * backup: cabeçalho, 1 linha de resumo por dia, anormalidades com ✅/❌.
 */
function solides_relatorio_texto(array $rel): string
{
    $dias = $rel['dias'] ?? null;
    if (!is_array($dias) || !$dias) {
        return 'Ponto (API Sólides): relatório veio vazio ou em formato inesperado.';
    }

    $out = "Ponto (API Sólides) — anormalidades\n";
    foreach ($dias as $d) {
        if (!is_array($d)) continue;
        $out .= "\n" . (string) ($d['resumo'] ?? '') . "\n";
        foreach (($d['anormalidades'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $out .= '  ' . (!empty($a['resolvida']) ? '✅' : '❌') . ' ' . (string) ($a['texto'] ?? $a['titulo'] ?? '') . "\n";
        }
    }
    return rtrim($out);
}
