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

    // [SPEC: reconhecimento-resumo-rotina-diaria] última face detectada — bloco
    // opcional mandado pelo coletor na carona do mesmo webhook de saúde. Só a
    // última (sem histórico de imagens): upsert junto com a linha de saúde.
    $pdo->exec("ALTER TABLE portal_reconhecimento_saude
        ADD COLUMN IF NOT EXISTS ultima_face_quando DATETIME NULL,
        ADD COLUMN IF NOT EXISTS ultima_face_nome VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS ultima_face_canal VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS ultima_face_thumb MEDIUMTEXT NULL");
})();

// [SPEC: reconhecimento-resumo-rotina-diaria] limite de tamanho do thumb_b64
// recebido do coletor — acima disso descarta a imagem (mantém nome/quando/canal)
// pra não inchar o payload/linha.
const RECONHECIMENTO_FACE_THUMB_MAX_BYTES = 400 * 1024;

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

/**
 * Grava o diagnóstico recebido como o último da origem (upsert). Bloco
 * `ultima_face` é opcional — [SPEC: reconhecimento-resumo-rotina-diaria]:
 * se ausente, as colunas ultima_face_* ficam intocadas (upsert não zera o
 * que já estava lá, já que são colunas separadas da linha de saúde).
 */
function reconhecimento_registrar_payload(PDO $pdo, array $payload, string $origem = RECONHECIMENTO_ORIGEM): void
{
    $status = (string) ($payload['status'] ?? '');
    if (!in_array($status, ['ok', 'alerta', 'falha'], true)) $status = 'falha';

    $checagens = is_array($payload['checagens'] ?? null) ? array_values($payload['checagens']) : [];

    $face = is_array($payload['ultima_face'] ?? null) ? $payload['ultima_face'] : null;
    $faceQuando = null;
    $faceNome = null;
    $faceCanal = null;
    $faceThumb = null;
    if ($face !== null) {
        $quandoRaw = (string) ($face['quando'] ?? '');
        $faceQuando = $quandoRaw !== '' && strtotime($quandoRaw) !== false ? date('Y-m-d H:i:s', strtotime($quandoRaw)) : null;
        $faceNome = $faceQuando !== null ? mb_substr((string) ($face['nome'] ?? ''), 0, 255) : null;
        $faceCanal = $faceQuando !== null ? mb_substr((string) ($face['canal'] ?? ''), 0, 255) : null;
        $thumbRaw = (string) ($face['thumb_b64'] ?? '');
        // descarta a imagem (mantém nome/quando/canal) se vier maior que o limite —
        // não quebra o resto do payload, só não guarda a miniatura dessa vez.
        if ($faceQuando !== null && $thumbRaw !== '' && strlen($thumbRaw) <= RECONHECIMENTO_FACE_THUMB_MAX_BYTES) {
            $faceThumb = $thumbRaw;
        } elseif ($faceQuando !== null && $thumbRaw !== '') {
            error_log('reconhecimento_registrar_payload: ultima_face.thumb_b64 descartado (' . strlen($thumbRaw) . ' bytes > limite)');
        }
    }

    $sql = "INSERT INTO portal_reconhecimento_saude (origem, status, resumo, checagens, evento, recebido_em)
            VALUES (?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), resumo = VALUES(resumo),
                checagens = VALUES(checagens), evento = VALUES(evento), recebido_em = VALUES(recebido_em)";
    $params = [
        $origem,
        $status,
        mb_substr((string) ($payload['resumo'] ?? ''), 0, 1000),
        json_encode($checagens, JSON_UNESCAPED_UNICODE),
        mb_substr((string) ($payload['evento'] ?? 'saude'), 0, 20),
    ];

    if ($faceQuando !== null) {
        $sql = "INSERT INTO portal_reconhecimento_saude
                    (origem, status, resumo, checagens, evento, recebido_em,
                     ultima_face_quando, ultima_face_nome, ultima_face_canal, ultima_face_thumb)
                VALUES (?,?,?,?,?,NOW(),?,?,?,?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), resumo = VALUES(resumo),
                    checagens = VALUES(checagens), evento = VALUES(evento), recebido_em = VALUES(recebido_em),
                    ultima_face_quando = VALUES(ultima_face_quando), ultima_face_nome = VALUES(ultima_face_nome),
                    ultima_face_canal = VALUES(ultima_face_canal),
                    -- [review] thumb descartado por tamanho (faceThumb=null) não deve apagar
                    -- a última miniatura boa que já estava gravada — só sobrescreve se vier uma nova.
                    ultima_face_thumb = COALESCE(VALUES(ultima_face_thumb), ultima_face_thumb)";
        $params = array_merge($params, [$faceQuando, $faceNome, $faceCanal, $faceThumb]);
    }

    $pdo->prepare($sql)->execute($params);
}

/** @return array|null linha de portal_reconhecimento_saude */
function reconhecimento_ultimo_diagnostico(PDO $pdo, string $origem = RECONHECIMENTO_ORIGEM): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_reconhecimento_saude WHERE origem = ?");
    $st->execute([$origem]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ───────────────────────────── Resumo ontem/hoje (Rotina Diária) ───────────────────────────── */
// [SPEC: reconhecimento-resumo-rotina-diaria] opção B aprovada: histórico de
// transições nova/resolvida (portal_alertas_historico) agrupado em ontem/hoje,
// pareando pela mesma `chave` — mesma fonte de dados da Central de Alertas,
// sem tabela nova.

/**
 * Agrupa as transições de reconhecimento_checagem/reconhecimento_sem_contato
 * em "ontem" e "hoje" (relativo a $agoraTs), pareando nova→resolvida por chave.
 * @return array{ontem: array, hoje: array} cada dia: array de
 *   ['chave','titulo','aberta_em','resolvida_em'] (resolvida_em null = ainda aberta)
 */
function reconhecimento_relatorio_dias(PDO $pdo, int $agoraTs): array
{
    $hojeInicio = strtotime('today', $agoraTs);
    $ontemInicio = $hojeInicio - 86400;

    $st = $pdo->prepare(
        "SELECT chave, evento, titulo, criado_em FROM portal_alertas_historico
         WHERE tipo IN ('reconhecimento_checagem', 'reconhecimento_sem_contato')
           AND criado_em >= ?
         ORDER BY chave, criado_em ASC"
    );
    $st->execute([date('Y-m-d H:i:s', $ontemInicio)]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    $abertas = []; // chave => linha 'nova' ainda sem par 'resolvida'
    $dias = ['ontem' => [], 'hoje' => []];
    foreach ($linhas as $l) {
        $ts = strtotime((string) $l['criado_em']);
        $dia = $ts >= $hojeInicio ? 'hoje' : 'ontem';
        if ($l['evento'] === 'nova') {
            $abertas[$l['chave']] = ['chave' => $l['chave'], 'titulo' => (string) $l['titulo'], 'aberta_em' => $l['criado_em'], 'resolvida_em' => null, 'dia' => $dia];
        } elseif ($l['evento'] === 'resolvida' && isset($abertas[$l['chave']])) {
            $abertas[$l['chave']]['resolvida_em'] = $l['criado_em'];
            $dias[$abertas[$l['chave']]['dia']][] = $abertas[$l['chave']];
            unset($abertas[$l['chave']]);
        }
    }
    // o que ficou em $abertas nunca resolveu dentro da janela — ainda em aberto.
    foreach ($abertas as $a) {
        $dias[$a['dia']][] = $a;
    }

    return $dias;
}

/**
 * Formata o resultado de reconhecimento_relatorio_dias() no mesmo padrão visual
 * do Sólides. $agoraTs deve ser o mesmo valor passado a reconhecimento_relatorio_dias()
 * — [review] evita rótulos de data calculados do relógio real quando o chamador
 * usar um $agoraTs diferente de "agora" (ex.: teste, ou tela de um dia específico).
 */
function reconhecimento_relatorio_texto(array $dias, int $agoraTs): string
{
    $fmtDia = function (array $ocorr): string {
        if (!$ocorr) return 'sem anormalidades';
        $abertos = count(array_filter($ocorr, fn($o) => $o['resolvida_em'] === null));
        $linhas = [count($ocorr) . ' anormalidade(s)' . ($abertos > 0 ? ", {$abertos} ainda em aberto" : '')];
        foreach ($ocorr as $o) {
            $horaAbriu = date('H:i', strtotime((string) $o['aberta_em']));
            if ($o['resolvida_em'] !== null) {
                $linhas[] = '  ✅ ' . $o['titulo'] . ' às ' . $horaAbriu . ' — resolvido às ' . date('H:i', strtotime((string) $o['resolvida_em']));
            } else {
                $linhas[] = '  ❌ ' . $o['titulo'] . ' às ' . $horaAbriu . ' — AINDA EM ABERTO';
            }
        }
        return implode("\n", $linhas);
    };

    $todasOcorr = array_merge($dias['ontem'], $dias['hoje']);
    $totalAnormal = count($todasOcorr);
    // [review] cabeçalho inclui o total em aberto, igual ao exemplo da spec aprovada.
    $totalAbertos = count(array_filter($todasOcorr, fn($o) => $o['resolvida_em'] === null));
    $cabecalho = 'Reconhecimento Facial: ' . ($totalAnormal === 0
        ? '✅ sem anormalidades'
        : "❌ {$totalAnormal} anormalidade(s)" . ($totalAbertos > 0 ? ", {$totalAbertos} em aberto" : ''));

    $ontemLbl = date('d/m', $agoraTs - 86400);
    $hojeLbl = date('d/m', $agoraTs);

    return $cabecalho
        . "\nOntem ({$ontemLbl}): " . $fmtDia($dias['ontem'])
        . "\nHoje ({$hojeLbl}): " . $fmtDia($dias['hoje']);
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
