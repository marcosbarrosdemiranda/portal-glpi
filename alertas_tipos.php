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
require_once __DIR__ . '/backup_lib.php';
require_once __DIR__ . '/dude_lib.php';
require_once __DIR__ . '/sefaz_lib.php';
require_once __DIR__ . '/impressoras_lib.php';

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

    // estado das ocorrências de alerta (o motor de notificação da Etapa 2 usa
    // isto pra decidir 🔔 nova / ✅ resolvida / ⏰ lembrete). chave = identificador
    // estável da ocorrência dentro do tipo. VARCHAR(191): cabe no índice utf8mb4.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_ocorrencias (
        tipo VARCHAR(40) NOT NULL,
        chave VARCHAR(191) NOT NULL,
        primeiro_visto DATETIME NOT NULL,
        ultimo_lembrete DATETIME NULL,
        PRIMARY KEY (tipo, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // histórico permanente de transições (nova/resolvida) — diferente de
    // portal_alertas_ocorrencias (que só guarda o estado ATUAL e apaga a linha
    // quando resolve). Alimentado por gat_alertas_tipo() a cada passada do worker.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(40) NOT NULL,
        chave VARCHAR(191) NOT NULL,
        evento ENUM('nova','resolvida') NOT NULL,
        titulo VARCHAR(255) NULL,
        loja VARCHAR(255) NULL,
        detalhe VARCHAR(500) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tipo_criado (tipo, criado_em),
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

// Grava uma transição no histórico. Chamada só a partir de gat_alertas_tipo()
// (único ponto que muda portal_alertas_ocorrencias de verdade), então o
// histórico reflete exatamente o que o motor decidiu, notificação tendo
// disparado ou não (o mute de WhatsApp não deve mudar o que fica registrado).
function alertas_historico_registrar(
    PDO $pdo, string $tipo, string $chave, string $evento,
    ?string $titulo = null, ?string $loja = null, ?string $detalhe = null
): void {
    $st = $pdo->prepare(
        "INSERT INTO portal_alertas_historico (tipo, chave, evento, titulo, loja, detalhe, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $st->execute([$tipo, $chave, $evento, $titulo, $loja, $detalhe]);
}

/**
 * Lista o histórico com filtros opcionais, mais recente primeiro.
 * @return array{linhas: array, total: int}
 */
function alertas_historico_listar(
    PDO $pdo, string $tipo = '', string $evento = '', int $dias = 0,
    int $limite = 50, int $pagina = 1
): array {
    $where = [];
    $params = [];
    if ($tipo !== '') { $where[] = 'tipo = ?'; $params[] = $tipo; }
    if ($evento !== '') { $where[] = 'evento = ?'; $params[] = $evento; }
    if ($dias > 0) { $where[] = 'criado_em >= NOW() - INTERVAL ? DAY'; $params[] = $dias; }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stCount = $pdo->prepare("SELECT COUNT(*) FROM portal_alertas_historico $sqlWhere");
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    $limite = max(1, min(500, $limite));
    $offset = max(0, ($pagina - 1) * $limite);
    $st = $pdo->prepare(
        "SELECT id, tipo, chave, evento, titulo, loja, detalhe, criado_em
         FROM portal_alertas_historico $sqlWhere
         ORDER BY criado_em DESC, id DESC
         LIMIT $limite OFFSET $offset"
    );
    $st->execute($params);
    return ['linhas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
}

function alertas_catalogo(): array
{
    return [
        'sem_inventario' => [
            'nome'      => 'Máquinas sem reportar inventário',
            'descricao' => 'Computador do GLPI que não envia inventário há X dias (ou nunca).',
            'params'    => [
                'dias' => ['label' => 'Dias sem reportar', 'default' => 7, 'min' => 1, 'max' => 90],
            ],
            // subtexto do card em .stats — {placeholders} trocados pelos params configurados;
            // {pct_parque} é calculado em alertas.php (ocorrências / total de máquinas).
            'sub_tpl' => 'há +{dias}d · {pct_parque}% do parque',
            'check'  => 'alerta_check_sem_inventario',
            'render' => 'alerta_render_sem_inventario',
            'icone'  => 'bi-wifi-off',
            'cor'    => 'danger',
        ],
        'disco_cheio' => [
            'nome'      => 'Discos quase cheios',
            'descricao' => 'Volume de dados (> 30 GB) acima do limiar de uso. Ignora partições de recuperação/sistema.',
            'params'    => [
                'pct' => ['label' => 'Uso mínimo (%)', 'default' => 90, 'min' => 50, 'max' => 99],
            ],
            'sub_tpl' => 'volume ≥ {pct}%',
            'check'  => 'alerta_check_disco_cheio',
            'render' => 'alerta_render_disco_cheio',
            'icone'  => 'bi-hdd-fill',
            'cor'    => 'warning',
        ],
        'backup_erro' => [
            'nome'      => 'Falha de backup',
            'descricao' => 'Job de backup (back-gmais) cuja última execução reportada foi erro.',
            'params'    => [],
            'check'  => 'alerta_check_backup_erro',
            'render' => 'alerta_render_backup_erro',
            'icone'  => 'bi-hdd-network-fill',
            'cor'    => 'danger',
        ],
        'backup_silencio' => [
            'nome'      => 'Backup sem contato',
            'descricao' => 'Máquina de backup cadastrada que não reporta nenhum resultado (sucesso ou erro) há X horas.',
            'params'    => [
                'horas' => ['label' => 'Horas sem contato', 'default' => 26, 'min' => 2, 'max' => 168],
            ],
            'check'  => 'alerta_check_backup_silencio',
            'render' => 'alerta_render_backup_silencio',
            'icone'  => 'bi-wifi-off',
            'cor'    => 'warning',
        ],
        'dude_device' => [
            'nome'      => 'Sem comunicação (IPs/dispositivos)',
            'descricao' => 'Dispositivo monitorado pelo The Dude está offline (sem resposta de ping).',
            'params'    => [],
            'check'  => 'alerta_check_dude_device',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-hdd-network',
            'cor'    => 'danger',
        ],
        'dude_link' => [
            'nome'      => 'Enlace offline (VPN/Internet)',
            'descricao' => 'Link de VPN ou de internet monitorado pelo The Dude caiu.',
            'params'    => [],
            'check'  => 'alerta_check_dude_link',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-diagram-3',
            'cor'    => 'danger',
        ],
        'dude_latencia' => [
            'nome'      => 'Latência alta entre links',
            'descricao' => 'Latência acima do limiar configurado no The Dude.',
            'params'    => [],
            'check'  => 'alerta_check_dude_latencia',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-speedometer2',
            'cor'    => 'warning',
        ],
        'dude_service' => [
            'nome'      => 'Serviço offline (The Dude)',
            'descricao' => 'Serviço monitorado pelo The Dude está fora do ar.',
            'params'    => [],
            'check'  => 'alerta_check_dude_service',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-hdd-stack',
            'cor'    => 'danger',
        ],
        'dude_sem_contato' => [
            'nome'      => 'The Dude não está notificando',
            'descricao' => 'Nenhuma notificação recebida do The Dude há X horas — pode ser o Dude ou a rede até ele.',
            'params'    => [
                'horas' => ['label' => 'Horas sem notificação', 'default' => 6, 'min' => 1, 'max' => 168],
            ],
            'check'  => 'alerta_check_dude_sem_contato',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-plug',
            'cor'    => 'warning',
        ],
        'dude_ligado_muito_tempo' => [
            'nome'      => 'Equipamento ligado há muito tempo',
            'descricao' => 'Dispositivo de uma categoria com limite configurado (Configurar Alertas → The Dude) ligado continuamente além do esperado.',
            'params'    => [],
            'check'  => 'alerta_check_dude_ligado_muito_tempo',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-clock-history',
            'cor'    => 'warning',
        ],
        'sefaz_ms' => [
            'nome'      => 'SEFAZ MS instável/fora do ar',
            'descricao' => 'Disponibilidade dos serviços de CT-e pra MS, consultada na página oficial do SEFAZ (cache de 5min).',
            'params'    => [],
            'check'  => 'alerta_check_sefaz_ms',
            'render' => 'alerta_render_sefaz',
            'icone'  => 'bi-building',
            'cor'    => 'danger',
        ],
        'impressora_offline' => [
            'nome'      => 'Impressora offline',
            'descricao' => 'Impressora cadastrada sem resposta SNMP no último ciclo do worker.',
            'params'    => [],
            'check'  => 'alerta_check_impressora_offline',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-printer',
            'cor'    => 'danger',
        ],
        'impressora_toner_baixo' => [
            'nome'      => 'Toner/consumível baixo',
            'descricao' => 'Consumível (toner, drum, coletor) de uma impressora abaixo do limiar configurado.',
            'params'    => [
                'limiar' => ['label' => 'Nível mínimo (%)', 'default' => 10, 'min' => 1, 'max' => 50],
            ],
            'check'  => 'alerta_check_impressora_toner_baixo',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-droplet-half',
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

/** @return array ocorrências: cada uma ['chave','titulo','loja','cat','dias','nunca','quando','detalhe'] */
function alerta_check_sem_inventario(PDO $pdo, array $p): array
{
    $rows = alertas_sem_inventario($pdo, (int) ($p['dias'] ?? 7));
    $out = [];
    foreach ($rows as $m) {
        $nunca = empty($m['last_inventory_update']) || $m['last_inventory_update'][0] === '0';
        $out[] = [
            'chave'   => 'sem_inv:' . ($m['name'] ?? ''),
            'titulo'  => $m['name'] ?: '(sem nome)',
            'loja'    => apelido_entidade($m['loja'] ?? '') ?: 'Sem loja',
            'cat'     => (string) ($m['cat'] ?? ''),
            'nunca'   => $nunca,
            'dias'    => $nunca ? null : (int) floor((time() - strtotime($m['last_inventory_update'])) / 86400),
            'quando'  => $nunca ? '' : substr((string) $m['last_inventory_update'], 0, 10),
            'detalhe' => $nunca
                ? 'nunca reportou inventário'
                : ((int) floor((time() - strtotime($m['last_inventory_update'])) / 86400)) . ' dias sem reportar',
        ];
    }
    return $out;
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','pct','usado','total','volume','detalhe'] */
function alerta_check_disco_cheio(PDO $pdo, array $p): array
{
    $rows = alertas_disco_cheio($pdo, (int) ($p['pct'] ?? 90));
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'chave'   => 'disco:' . ($d['name'] ?? '') . '|' . ($d['volume'] ?? ''),
            'titulo'  => (string) ($d['name'] ?? ''),
            'loja'    => apelido_entidade($d['loja'] ?? '') ?: '—',
            'pct'     => (int) $d['pct'],
            'usado'   => (float) $d['totalsize'] - (float) $d['freesize'],
            'total'   => (float) $d['totalsize'],
            'volume'  => (string) ($d['volume'] ?? ''),
            'detalhe' => (string) ($d['volume'] ?? '?') . ' · ' . (int) $d['pct'] . '% cheio',
        ];
    }
    return $out;
}

/** @return array ocorrências: impressora cadastrada, sem resposta SNMP no último poll. */
function alerta_check_impressora_offline(PDO $pdo, array $p): array
{
    $st = $pdo->query("
        SELECT i.id, i.apelido, i.loja, s.atualizado_em
        FROM portal_impressoras i
        JOIN portal_impressoras_status s ON s.impressora_id = i.id
        WHERE i.ativo = 1 AND s.online = 0
        ORDER BY i.loja, i.apelido
    ");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'chave'     => 'impressora:offline:' . $r['id'],
            'titulo'    => $r['apelido'],
            'loja'      => (string) $r['loja'],
            'categoria' => 'Impressora',
            'detalhe'   => 'sem resposta SNMP (desde ' . date('d/m H:i', strtotime($r['atualizado_em'])) . ')',
        ];
    }
    return $out;
}

/**
 * @return array ocorrências: algum consumível (toner/drum/etc.) abaixo do
 * limiar configurado (default 10%). $p['limiar'] em porcentagem inteira.
 */
function alerta_check_impressora_toner_baixo(PDO $pdo, array $p): array
{
    $limiar = (int) ($p['limiar'] ?? 10);
    $st = $pdo->query("
        SELECT i.id, i.apelido, i.loja, s.consumiveis_json
        FROM portal_impressoras i
        JOIN portal_impressoras_status s ON s.impressora_id = i.id
        WHERE i.ativo = 1 AND s.online = 1 AND s.consumiveis_json IS NOT NULL
        ORDER BY i.loja, i.apelido
    ");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $consumiveis = json_decode($r['consumiveis_json'], true) ?: [];
        $baixos = [];
        foreach ($consumiveis as $c) {
            if ($c['nivel'] === null || $c['max'] === null || $c['max'] <= 0) continue;
            $pct = ($c['nivel'] / $c['max']) * 100;
            if ($pct < $limiar) $baixos[] = $c['nome'] . ' (' . round($pct) . '%)';
        }
        if (!$baixos) continue;
        $out[] = [
            'chave'     => 'impressora:toner:' . $r['id'],
            'titulo'    => $r['apelido'],
            'loja'      => (string) $r['loja'],
            'categoria' => 'Impressora',
            'detalhe'   => implode(', ', $baixos),
        ];
    }
    return $out;
}

/** innerHTML do corpo da seção — agrupado por loja. */
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

/** innerHTML do corpo da seção — tabela com barra de uso. */
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
