<?php
/**
 * alertas_tipos.php — catálogo de tipos de alerta + config por tipo.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria a tabela portal_alertas_config ao ser incluído (padrão do portal).
 *
 * Etapa 1 da Central de Alertas configurável: aqui ficam os 2 tipos que já
 * dá pra detectar hoje com dados do GLPI (máquina sem inventário / disco
 * cheio). Cada tipo tem metadados (nome, descrição, parâmetros), um check
 * (gera ocorrências) e um render (innerHTML do corpo da seção).
 */

require_once __DIR__ . '/alertas_lib.php';
require_once __DIR__ . '/entidade_alias.php';
require_once __DIR__ . '/agenda/db.php';

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_config (
        tipo VARCHAR(40) PRIMARY KEY,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        params JSON,
        notif_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
        lembrete_min INT NOT NULL DEFAULT 0,
        abre_chamado TINYINT(1) NOT NULL DEFAULT 0,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

function alertas_catalogo(): array
{
    return [
        'sem_inventario' => [
            'nome'      => 'Máquina sem reportar inventário',
            'descricao' => 'Computador do GLPI que não envia inventário há X dias (ou nunca).',
            'params'    => [
                'dias' => ['label' => 'Dias sem reportar', 'default' => 7, 'min' => 1, 'max' => 90],
            ],
            'check'  => 'alerta_check_sem_inventario',
            'render' => 'alerta_render_sem_inventario',
            'icone'  => 'bi-wifi-off',
            'cor'    => 'danger',
        ],
        'disco_cheio' => [
            'nome'      => 'Disco quase cheio',
            'descricao' => 'Volume de dados (> 30 GB) acima do limiar de uso. Ignora partições de recuperação/sistema.',
            'params'    => [
                'pct' => ['label' => 'Uso mínimo (%)', 'default' => 90, 'min' => 50, 'max' => 99],
            ],
            'check'  => 'alerta_check_disco_cheio',
            'render' => 'alerta_render_disco_cheio',
            'icone'  => 'bi-hdd-fill',
            'cor'    => 'warning',
        ],
    ];
}

/**
 * Mescla o catálogo com portal_alertas_config. Linha ausente = defaults.
 * @return array{ativo:bool, params:array<string,int>, notif_whatsapp:bool, lembrete_min:int, abre_chamado:bool}
 */
function alertas_config_do_tipo(PDO $pdo, string $tipo): array
{
    $cat = alertas_catalogo()[$tipo] ?? null;
    $defParams = [];
    foreach (($cat['params'] ?? []) as $k => $meta) $defParams[$k] = (int) $meta['default'];

    $st = $pdo->prepare("SELECT ativo, params, notif_whatsapp, lembrete_min, abre_chamado
                         FROM portal_alertas_config WHERE tipo = ?");
    $st->execute([$tipo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ativo' => true, 'params' => $defParams, 'notif_whatsapp' => true,
                'lembrete_min' => 0, 'abre_chamado' => false];
    }
    $p = json_decode((string) $row['params'], true);
    if (!is_array($p)) $p = [];
    // só aceita as chaves conhecidas do catálogo, cai no default se faltar/inválido
    $params = [];
    foreach ($defParams as $k => $def) {
        $v = isset($p[$k]) ? (int) $p[$k] : $def;
        $min = (int) ($cat['params'][$k]['min'] ?? 1);
        $max = (int) ($cat['params'][$k]['max'] ?? 100000);
        $params[$k] = max($min, min($max, $v));
    }
    return [
        'ativo'          => (bool) $row['ativo'],
        'params'         => $params,
        'notif_whatsapp' => (bool) $row['notif_whatsapp'],
        'lembrete_min'   => max(0, (int) $row['lembrete_min']),
        'abre_chamado'   => (bool) $row['abre_chamado'],
    ];
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','cat','dias','nunca'] */
function alerta_check_sem_inventario(PDO $pdo, array $p): array
{
    $rows = alertas_sem_inventario($pdo, (int) ($p['dias'] ?? 7));
    $out = [];
    foreach ($rows as $m) {
        $nunca = empty($m['last_inventory_update']) || $m['last_inventory_update'][0] === '0';
        $out[] = [
            'chave'  => 'sem_inv:' . ($m['name'] ?? ''),
            'titulo' => $m['name'] ?: '(sem nome)',
            'loja'   => apelido_entidade($m['loja'] ?? '') ?: 'Sem loja',
            'cat'    => (string) ($m['cat'] ?? ''),
            'nunca'  => $nunca,
            'dias'   => $nunca ? null : (int) floor((time() - strtotime($m['last_inventory_update'])) / 86400),
            'quando' => $nunca ? '' : substr((string) $m['last_inventory_update'], 0, 10),
        ];
    }
    return $out;
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','pct','usado','total','volume'] */
function alerta_check_disco_cheio(PDO $pdo, array $p): array
{
    $rows = alertas_disco_cheio($pdo, (int) ($p['pct'] ?? 90));
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'chave'  => 'disco:' . ($d['name'] ?? '') . '|' . ($d['volume'] ?? ''),
            'titulo' => (string) ($d['name'] ?? ''),
            'loja'   => apelido_entidade($d['loja'] ?? '') ?: '—',
            'pct'    => (int) $d['pct'],
            'usado'  => (float) $d['totalsize'] - (float) $d['freesize'],
            'total'  => (float) $d['totalsize'],
            'volume' => (string) ($d['volume'] ?? ''),
        ];
    }
    return $out;
}

/** innerHTML do corpo — agrupado por loja. Move o HTML do render_sem_inv_body de hoje. */
function alerta_render_sem_inventario(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Todo o parque reportou.</div>';
    }
    $porLoja = [];
    foreach ($ocorr as $o) $porLoja[$o['loja']][] = $o;
    ksort($porLoja, SORT_NATURAL | SORT_FLAG_CASE);

    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '';
    foreach ($porLoja as $loja => $maquinas) {
        $out .= '<div class="loja-h"><i class="bi bi-shop"></i> ' . $H($loja)
              . ' <span style="color:#9ca3af;font-weight:400">(' . count($maquinas) . ')</span></div><table><tbody>';
        foreach ($maquinas as $m) {
            $pill = $m['nunca']
                ? '<span class="pill pill-red">nunca reportou</span>'
                : '<span class="pill pill-amber">' . (int) $m['dias'] . ' dias (' . $H($m['quando']) . ')</span>';
            $out .= '<tr><td style="font-weight:600">' . $H($m['titulo']) . '</td>'
                  . '<td style="color:#6b7280">' . $H($m['cat']) . '</td>'
                  . '<td style="text-align:right">' . $pill . '</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out;
}

/** innerHTML do corpo — tabela com barra. Move o HTML do render_disco_body de hoje. */
function alerta_render_disco_cheio(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nenhum volume acima do limiar.</div>';
    }
    $H  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $gb = fn($mb) => ($n = (float) $mb) >= 1024 ? round($n / 1024, $n >= 10240 ? 0 : 1) . ' GB' : round($n) . ' MB';
    $out = '<table><thead><tr><th>Máquina</th><th>Loja</th><th>Volume</th><th>Uso</th></tr></thead><tbody>';
    foreach ($ocorr as $d) {
        $out .= '<tr><td style="font-weight:600">' . $H($d['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($d['loja']) . '</td>'
              . '<td>' . $H($d['volume']) . '</td>'
              . '<td><span class="bar"><span style="width:' . (int) $d['pct'] . '%"></span></span>'
              . (int) $d['pct'] . '% · ' . $gb($d['usado']) . ' / ' . $gb($d['total']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
