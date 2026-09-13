<?php
/**
 * sefaz_lib.php — disponibilidade do SEFAZ (autorização de CT-e/NFe) pra
 * Central de Alertas. Diferente do backup-gmais/Dude (push via webhook),
 * aqui é PULL: o portal consulta a página pública de disponibilidade
 * quando o check() roda, com um cache curto pra não bater no site do
 * governo a cada refresh da tela (alertas.php recarrega a cada 45s).
 *
 * Fonte hoje: página de disponibilidade do CT-e (fazenda.gov.br), linha
 * do estado MS — **por serviço** (Recepção Sinc, Status Serviço, etc.),
 * não um resumo único do estado. Cada serviço degradado vira sua própria
 * ocorrência, resolve independente das outras.
 *
 * Página do SEFAZ-MS direto (disponibilidade.sefaz.ms.gov.br) foi tentada
 * primeiro mas carrega o status via JS/checagem ao vivo que não terminou
 * em teste (ficou "Carregando..." por +1min) — usar essa se um dia for
 * confirmada funcionando.
 */

require_once __DIR__ . '/agenda/db.php';

const SEFAZ_CTE_URL = 'https://hom.cte.fazenda.gov.br/portal/disponibilidade.aspx?versao=4.00&tipoConteudo=XbSeqxE8pl8=';
const SEFAZ_CTE_TABELA_ID = 'ctl00_ContentPlaceHolder1_gdvDisponibilidade';
const SEFAZ_CTE_SERVICOS = ['Recepção Sinc', 'Status Serviço', 'Recepção CT-e OS', 'Recepção GTVE', 'Recepção Evento', 'Consulta Cadastro'];
const SEFAZ_CACHE_MINUTOS = 5;

// cria as tabelas ao incluir (padrão do portal)
(function () {
    global $pdo;
    // horário em que "amarelo"/"vermelho" vira alerta, por tipo de alerta
    // (não só SEFAZ — generalizável pra outro tipo que precise disso no
    // futuro). Sem linha pra um tipo = sempre notifica, 24h.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_horario (
        tipo           VARCHAR(40) NOT NULL PRIMARY KEY,
        horario_inicio TIME NOT NULL,
        horario_fim    TIME NOT NULL,
        atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_sefaz_status (
        fonte         VARCHAR(20) NOT NULL,
        servico       VARCHAR(60) NOT NULL,
        status        ENUM('verde','amarelo','vermelho') NOT NULL,
        atualizado_em DATETIME NOT NULL,
        PRIMARY KEY (fonte, servico)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/**
 * Parseia o HTML da página de disponibilidade do CT-e e devolve o status
 * de CADA serviço da linha MS. Função pura (recebe o HTML já baixado) —
 * nunca lança, retorna null se não conseguir achar a tabela/linha MS
 * (estrutura da página mudou, ou HTML vazio/inesperado).
 *
 * @return array<string,string>|null nome do serviço => 'verde'|'amarelo'|'vermelho'
 */
function sefaz_parsear_html_ms(string $html): ?array
{
    if (trim($html) === '') return null;

    $doc = new \DOMDocument();
    $anteriorLibxml = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($anteriorLibxml);
    if (!$ok) return null;

    $xpath = new \DOMXPath($doc);
    $tabela = $xpath->query("//table[@id='" . SEFAZ_CTE_TABELA_ID . "']")->item(0);
    if ($tabela === null) return null;

    foreach ($xpath->query('.//tr', $tabela) as $tr) {
        $tds = $xpath->query('./td', $tr);
        if ($tds->length < 7) continue; // linha de cabeçalho usa <th>, não <td> — pula

        $estado = trim($tds->item(0)->textContent);
        if ($estado !== 'MS') continue;

        $porServico = [];
        for ($i = 0; $i < 6; $i++) {
            $img = $xpath->query('.//img', $tds->item($i + 1))->item(0);
            $src = $img ? $img->getAttribute('src') : '';
            $cor = str_contains($src, 'vermelh') ? 'vermelho' : (str_contains($src, 'amarel') ? 'amarelo' : 'verde');
            $porServico[SEFAZ_CTE_SERVICOS[$i]] = $cor;
        }
        return $porServico;
    }
    return null; // MS não encontrado na tabela
}

/** Busca a página real via HTTP e parseia. Null em qualquer falha de rede. */
function sefaz_cte_buscar_status_ms(): ?array
{
    $ch = curl_init(SEFAZ_CTE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PortalGmaisMonitor/1.0)',
    ]);
    $html = curl_exec($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($html === false || $erro !== '') return null;

    return sefaz_parsear_html_ms($html);
}

/**
 * Garante que o cache de $fonte está fresco (rebusca se passou de $minutos
 * desde a última atualização, ou se nunca foi buscado). Se o fetch falhar,
 * mantém o cache antigo (não apaga nada) — assim uma falha de rede nossa
 * não derruba os alertas em aberto nem falsifica um "resolvido".
 *
 * @param callable $buscar (): array<string,string>|null — mesmo formato de sefaz_parsear_html_ms
 */
function sefaz_garantir_cache_fresco(PDO $pdo, string $fonte, int $minutos, callable $buscar): void
{
    $ultima = $pdo->prepare("SELECT MAX(atualizado_em) FROM portal_sefaz_status WHERE fonte = ?");
    $ultima->execute([$fonte]);
    $ultimaData = $ultima->fetchColumn();

    if ($ultimaData !== null && strtotime((string) $ultimaData) > time() - $minutos * 60) {
        return; // ainda fresco
    }

    $novo = $buscar();
    if ($novo === null) return; // fetch falhou: mantém o que já tinha

    $up = $pdo->prepare(
        "INSERT INTO portal_sefaz_status (fonte, servico, status, atualizado_em) VALUES (?,?,?,NOW())
         ON DUPLICATE KEY UPDATE status=VALUES(status), atualizado_em=NOW()"
    );
    foreach ($novo as $servico => $status) {
        $up->execute([$fonte, $servico, $status]);
    }
}

/**
 * true = pode virar alerta agora (tipo sem restrição configurada, OU
 * dentro do horário permitido). false = fora do horário. Suporta janela
 * que cruza meia-noite. Mesma lógica de dude_categoria_no_horario, mas
 * chaveada por TIPO de alerta em vez de categoria de dispositivo.
 */
function alertas_horario_permitido(PDO $pdo, string $tipo): bool
{
    $st = $pdo->prepare("SELECT horario_inicio, horario_fim FROM portal_alertas_horario WHERE tipo = ?");
    $st->execute([$tipo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return true; // sem config = sempre notifica

    $agora = date('H:i:s');
    $ini = (string) $row['horario_inicio'];
    $fim = (string) $row['horario_fim'];
    if ($ini <= $fim) return $agora >= $ini && $agora <= $fim;
    return $agora >= $ini || $agora <= $fim; // janela cruza meia-noite
}

/** Upsert do horário permitido de 1 tipo de alerta. */
function alertas_horario_salvar(PDO $pdo, string $tipo, string $horarioInicio, string $horarioFim): void
{
    $pdo->prepare(
        "INSERT INTO portal_alertas_horario (tipo, horario_inicio, horario_fim) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE horario_inicio=VALUES(horario_inicio), horario_fim=VALUES(horario_fim)"
    )->execute([$tipo, $horarioInicio, $horarioFim]);
}

function alertas_horario_remover(PDO $pdo, string $tipo): void
{
    $pdo->prepare("DELETE FROM portal_alertas_horario WHERE tipo = ?")->execute([$tipo]);
}

function alertas_horario_atual(PDO $pdo, string $tipo): ?array
{
    $st = $pdo->prepare("SELECT horario_inicio, horario_fim FROM portal_alertas_horario WHERE tipo = ?");
    $st->execute([$tipo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return array ocorrências: 1 por serviço não-verde (cada um resolve independente) */
function alerta_check_sefaz_ms(PDO $pdo, array $p): array
{
    sefaz_garantir_cache_fresco($pdo, 'cte_ms', SEFAZ_CACHE_MINUTOS, 'sefaz_cte_buscar_status_ms');
    if (!alertas_horario_permitido($pdo, 'sefaz_ms')) return [];

    $st = $pdo->prepare("SELECT servico, status FROM portal_sefaz_status WHERE fonte = ? AND status != 'verde' ORDER BY servico");
    $st->execute(['cte_ms']);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rotulo = $r['status'] === 'vermelho' ? 'Indisponível' : 'Instável';
        $out[] = [
            'chave'   => 'sefaz:cte_ms:' . $r['servico'],
            'titulo'  => 'CT-e MS — ' . $r['servico'],
            'loja'    => '',
            'detalhe' => $rotulo,
        ];
    }
    return $out;
}

/** innerHTML do corpo da seção — tabela simples, 1 linha por serviço degradado. */
function alerta_render_sefaz(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>SEFAZ normal.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<table><thead><tr><th>Serviço</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($ocorr as $o) {
        $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
