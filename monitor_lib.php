<?php
/**
 * monitor_lib.php — Monitor de rede próprio do portal (substitui o The Dude).
 *
 * Etapa 1: QUAIS equipamentos monitorar — vêm do inventário (computadores do
 * GLPI por categoria do portal, balanças, pfSense, servidores MGV) + cadastro
 * manual pra exceção. Cada equipamento tem a chave "Monitorar"; cada grupo
 * (categoria) tem intervalo, tolerância e aviso de queda curta próprios.
 * Ainda não pinga nada (etapa 2) nem alerta (etapa 3).
 *
 * Roteiro: Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria as tabelas ao ser incluído (padrão do portal).
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php'; // apelido_entidade()
require_once __DIR__ . '/inventario_lib.php'; // inv_pc_cats()

const MONITOR_REDE_SERVIDOR = '192.168.1.'; // LAN do servidor do portal — mesma preferência do inventario_pc.php

/** Padrões sugeridos por grupo (roteiro, seção 1e). Editáveis na tela depois. */
const MONITOR_GRUPOS_PADRAO = [
    // grupo            => [nome, intervalo_seg, falhas_para_cair, sucessos_para_voltar, queda_curta, monitorar_novos]
    'pdvs'              => ['PDVs',                30, 8, 2, 'na_hora',  1],
    'firewalls'         => ['Firewalls (pfSense)', 30, 2, 2, 'na_hora',  1],
    'maquinas-virtuais' => ['Servidores / VMs',    30, 2, 2, 'na_hora',  0],
    'balancas'          => ['Balanças',           120, 3, 2, 'registro', 1],
    'servidores-mgv'    => ['Servidores MGV',      60, 3, 2, 'registro', 0],
    'pcs-retaguarda'    => ['PCs Retaguarda',      60, 3, 2, 'registro', 0],
    'notebooks'         => ['Notebooks',           60, 3, 2, 'registro', 0],
    'tvs'               => ['TVs',                 60, 3, 2, 'registro', 0],
    'radios-pc'         => ['Rádios',              60, 3, 2, 'registro', 0],
];

// cria as tabelas ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_monitor_grupos (
        grupo                VARCHAR(40) PRIMARY KEY,
        nome                 VARCHAR(80) NOT NULL,
        monitorar_novos      TINYINT(1) NOT NULL DEFAULT 0,
        intervalo_seg        INT NOT NULL DEFAULT 60,
        falhas_para_cair     INT NOT NULL DEFAULT 3,
        sucessos_para_voltar INT NOT NULL DEFAULT 2,
        queda_curta          ENUM('registro','resumo_diario','na_hora') NOT NULL DEFAULT 'registro',
        atualizado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // nome/ip/loja/grupo são cópia do inventário, atualizada a cada sincronização
    // (manuais: preenchidos na tela). A chave "monitorar" e o ip_fixo são do portal
    // e nunca são sobrescritos pela sincronização. status..latencia_ms = etapa 2.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_monitor_dispositivos (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        origem            ENUM('glpi','balanca','pfsense','mgv','manual') NOT NULL,
        origem_id         INT NULL,
        nome              VARCHAR(120) NOT NULL,
        ip                VARCHAR(45) NULL,
        ips               VARCHAR(255) NULL,
        loja              VARCHAR(60) NOT NULL DEFAULT '',
        grupo             VARCHAR(40) NOT NULL,
        duplicado_de      VARCHAR(120) NULL,
        removido_em       DATETIME NULL,
        monitorar         TINYINT(1) NOT NULL DEFAULT 0,
        ip_fixo           VARCHAR(45) NULL,
        porta_tcp         INT NULL,
        status            ENUM('up','down','desconhecido') NOT NULL DEFAULT 'desconhecido',
        status_desde      DATETIME NULL,
        falhas_seguidas   INT NOT NULL DEFAULT 0,
        sucessos_seguidos INT NOT NULL DEFAULT 0,
        ultimo_ping       DATETIME NULL,
        latencia_ms       DECIMAL(8,2) NULL,
        criado_em         DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_origem (origem, origem_id),
        INDEX idx_grupo (grupo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // migração leve — lista de IPs candidatos (acrescentada depois do 1º deploy)
    $cols = array_column($pdo->query("SHOW COLUMNS FROM portal_monitor_dispositivos")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('ips', $cols, true)) {
        $pdo->exec("ALTER TABLE portal_monitor_dispositivos ADD COLUMN ips VARCHAR(255) NULL AFTER ip");
    }

    $ins = $pdo->prepare("INSERT IGNORE INTO portal_monitor_grupos
        (grupo, nome, intervalo_seg, falhas_para_cair, sucessos_para_voltar, queda_curta, monitorar_novos)
        VALUES (?,?,?,?,?,?,?)");
    foreach (MONITOR_GRUPOS_PADRAO as $g => [$nome, $int, $f, $s, $q, $novos]) {
        $ins->execute([$g, $nome, $int, $f, $s, $q, $novos]);
    }
})();

/* ───────────────────────────── Regras puras ───────────────────────────── */

/** IP de rede local utilizável pra ping (mesmos descartes do inventario_pc.php). */
function monitor_ip_descartar(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return true;
    [$a, $b, $c] = array_map('intval', explode('.', $ip));
    if ($a === 127 || $a === 0) return true;
    if ($a === 169 && $b === 254) return true;              // APIPA
    if ($a === 100 && $b >= 64 && $b <= 127) return true;   // Tailscale / CGNAT
    if ($a === 172 && $b >= 16 && $b <= 31) return true;    // Docker
    if ($a === 192 && $b === 168 && $c === 56) return true; // VirtualBox
    return false;
}

/**
 * Escolhe 1 IP entre as placas do computador: Ethernet antes de WiFi; no
 * mesmo tipo, a rede do servidor do portal primeiro.
 * @param array $candidatos lista de [ip, instantiation_type]
 */
function monitor_escolher_ip(array $candidatos): ?string
{
    return monitor_escolher_ips($candidatos)[0] ?? null;
}

/**
 * Até 4 IPs candidatos, na ordem de preferência, sem repetir. O GLPI guarda
 * IPs antigos da mesma placa (DHCP mudou) — o monitor pinga todos e considera
 * ligado se qualquer um responder, igual o inventario_pc.php já faz.
 * @return string[]
 */
function monitor_escolher_ips(array $candidatos): array
{
    $rank = ['NetworkPortEthernet' => 0, 'NetworkPortWifi' => 1];
    $ok = [];
    foreach ($candidatos as $i => [$ip, $tipo]) {
        $ip = trim((string) $ip);
        if (monitor_ip_descartar($ip)) continue;
        $ok[] = [$ip, $rank[$tipo] ?? 2, str_starts_with($ip, MONITOR_REDE_SERVIDOR) ? 0 : 1, $i];
    }
    usort($ok, fn($x, $y) => [$x[1], $x[2], $x[3]] <=> [$y[1], $y[2], $y[3]]);
    return array_slice(array_values(array_unique(array_column($ok, 0))), 0, 4);
}

/** "Loja 001" / "MGV Loja 003" / "loja 10" -> "Lj 001" (formato da Central). Sem número: como veio. */
function monitor_loja_curta(string $loja): string
{
    $loja = trim($loja);
    if (preg_match('/\b(?:lj|loja)\s*0*(\d{1,3})\b/i', $loja, $m)) {
        return 'Lj ' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
    }
    return $loja;
}

/**
 * Mesmo IP em vários itens (registros antigos no GLPI): vale o de inventário
 * mais recente; os outros recebem duplicado_de = nome de quem vale.
 * Itens sem IP não entram na comparação.
 */
function monitor_resolver_ip_duplicado(array $itens): array
{
    $vencedor = []; // ip => índice
    foreach ($itens as $i => $it) {
        $itens[$i]['duplicado_de'] = null;
        $ip = $it['ip'] ?? null;
        if ($ip === null || $ip === '') continue;
        if (!isset($vencedor[$ip])) { $vencedor[$ip] = $i; continue; }
        $atual = $itens[$vencedor[$ip]];
        if ((string) ($it['ultimo_inv'] ?? '') > (string) ($atual['ultimo_inv'] ?? '')) $vencedor[$ip] = $i;
    }
    foreach ($itens as $i => $it) {
        $ip = $it['ip'] ?? null;
        if ($ip === null || $ip === '' || $vencedor[$ip] === $i) continue;
        $itens[$i]['duplicado_de'] = $itens[$vencedor[$ip]]['nome'];
    }
    return $itens;
}

function monitor_ip_valido(string $ip): bool
{
    return (bool) filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

/** Aplica limites aos parâmetros editados na tela (campos ausentes ficam com o padrão). */
function monitor_grupo_normalizar(array $p): array
{
    $int = fn($k, $def, $min, $max) => max($min, min($max, (int) ($p[$k] ?? $def)));
    $q = (string) ($p['queda_curta'] ?? 'registro');
    return [
        'intervalo_seg'        => $int('intervalo_seg', 60, 30, 3600),
        'falhas_para_cair'     => $int('falhas_para_cair', 3, 1, 60),
        'sucessos_para_voltar' => $int('sucessos_para_voltar', 2, 1, 10),
        'queda_curta'          => in_array($q, ['registro', 'resumo_diario', 'na_hora'], true) ? $q : 'registro',
        'monitorar_novos'      => !empty($p['monitorar_novos']) ? 1 : 0,
    ];
}

/* ───────────────────────────── Grupos ───────────────────────────── */

function monitor_grupos_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_monitor_grupos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

function monitor_grupo(PDO $pdo, string $grupo): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_monitor_grupos WHERE grupo = ?");
    $st->execute([$grupo]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Cria ou atualiza; campos não enviados mantêm o valor atual (ou o padrão, se o grupo é novo). */
function monitor_grupo_salvar(PDO $pdo, string $grupo, string $nome, array $params): void
{
    $atual = monitor_grupo($pdo, $grupo) ?? [];
    $n = monitor_grupo_normalizar(array_merge($atual, $params));
    $pdo->prepare("INSERT INTO portal_monitor_grupos
            (grupo, nome, monitorar_novos, intervalo_seg, falhas_para_cair, sucessos_para_voltar, queda_curta)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nome = VALUES(nome), monitorar_novos = VALUES(monitorar_novos),
            intervalo_seg = VALUES(intervalo_seg), falhas_para_cair = VALUES(falhas_para_cair),
            sucessos_para_voltar = VALUES(sucessos_para_voltar), queda_curta = VALUES(queda_curta)")
        ->execute([$grupo, $nome !== '' ? $nome : $grupo, $n['monitorar_novos'], $n['intervalo_seg'],
                   $n['falhas_para_cair'], $n['sucessos_para_voltar'], $n['queda_curta']]);
}

/** Grupo que apareceu no inventário (categoria nova criada no admin) e ainda não tem config. */
function monitor_grupo_garantir(PDO $pdo, string $grupo, string $nome): void
{
    if (monitor_grupo($pdo, $grupo) === null) monitor_grupo_salvar($pdo, $grupo, $nome, []);
}

function monitor_grupo_set_monitorar_todos(PDO $pdo, string $grupo, bool $ligar): void
{
    // IP duplicado nunca liga em massa — continua desligado até alguém resolver
    $pdo->prepare("UPDATE portal_monitor_dispositivos SET monitorar = ?
                   WHERE grupo = ? AND (duplicado_de IS NULL OR ? = 0)")
        ->execute([$ligar ? 1 : 0, $grupo, $ligar ? 1 : 0]);
}

/* ───────────────────────────── Fontes do inventário ───────────────────────────── */

/**
 * Lista única de equipamentos vindos do inventário.
 * @return array cada item: origem, origem_id, nome, ip, ips (candidatos, separados por vírgula), loja, grupo, grupo_nome, ultimo_inv, duplicado_de
 */
function monitor_fontes_inventario(PDO $pdo): array
{
    $itens = [];
    $cats = inv_pc_cats();

    // 1) Computadores do GLPI (fora baixados e "ignorados" = containers Docker do agente)
    $pcs = $pdo->query("
        SELECT c.id, c.name, c.last_inventory_update, e.completename AS entidade,
               COALESCE(pc.categoria, 'pcs-retaguarda') AS categoria
        FROM glpi_computers c
        LEFT JOIN glpi_entities e ON e.id = c.entities_id
        LEFT JOIN portal_inv_pc_cat pc ON pc.computer_id = c.id
        LEFT JOIN portal_inv_baixas bx ON bx.itemtype = 'Computer' AND bx.items_id = c.id
        WHERE c.is_deleted = 0 AND c.is_template = 0 AND bx.id IS NULL
          AND COALESCE(pc.categoria, '') <> '__ignorado__'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ipsPorPc = [];
    foreach ($pdo->query("
        SELECT np.items_id AS cid, np.instantiation_type AS tipo, ia.name AS ip
        FROM glpi_networkports np
        JOIN glpi_networknames nn ON nn.itemtype = 'NetworkPort' AND nn.items_id = np.id
        JOIN glpi_ipaddresses ia  ON ia.itemtype = 'NetworkName' AND ia.items_id = nn.id
        WHERE np.itemtype = 'Computer' AND ia.name LIKE '%.%'
          AND np.instantiation_type IN ('NetworkPortEthernet','NetworkPortWifi')
    ", PDO::FETCH_ASSOC) as $r) {
        $ipsPorPc[(int) $r['cid']][] = [$r['ip'], $r['tipo']];
    }

    foreach ($pcs as $c) {
        $ips = monitor_escolher_ips($ipsPorPc[(int) $c['id']] ?? []);
        $itens[] = [
            'origem'     => 'glpi',
            'origem_id'  => (int) $c['id'],
            'nome'       => (string) $c['name'],
            'ip'         => $ips[0] ?? null,
            'ips'        => $ips ? implode(',', $ips) : null,
            'loja'       => monitor_loja_curta(apelido_entidade((string) $c['entidade'])),
            'grupo'      => (string) $c['categoria'],
            'grupo_nome' => $cats[$c['categoria']] ?? (string) $c['categoria'],
            'ultimo_inv' => $c['last_inventory_update'],
        ];
    }

    // 2) Balanças — loja: campo próprio ou, se vazio, a do servidor MGV da balança
    foreach ($pdo->query("
        SELECT b.id, b.identificacao, b.departamento, b.ip, b.loja, s.nome AS servidor
        FROM portal_balancas b LEFT JOIN portal_servidores_mgv s ON s.id = b.servidor_id
    ", PDO::FETCH_ASSOC) as $b) {
        $nome = trim('Balança ' . $b['identificacao'] . ' ' . ($b['departamento'] ?? ''));
        $itens[] = [
            'origem' => 'balanca', 'origem_id' => (int) $b['id'], 'nome' => $nome,
            'ip' => monitor_ip_valido((string) $b['ip']) ? trim((string) $b['ip']) : null,
            'loja' => monitor_loja_curta(trim((string) $b['loja']) !== '' ? (string) $b['loja'] : (string) $b['servidor']),
            'grupo' => 'balancas', 'grupo_nome' => 'Balanças', 'ultimo_inv' => null,
        ];
    }

    // 3) pfSense das lojas (só os ativos)
    foreach ($pdo->query("SELECT id, loja, ip FROM portal_pfsense_lojas WHERE ativo = 1", PDO::FETCH_ASSOC) as $f) {
        $itens[] = [
            'origem' => 'pfsense', 'origem_id' => (int) $f['id'], 'nome' => 'pfSense ' . monitor_loja_curta((string) $f['loja']),
            'ip' => monitor_ip_valido((string) $f['ip']) ? trim((string) $f['ip']) : null,
            'loja' => monitor_loja_curta((string) $f['loja']),
            'grupo' => 'firewalls', 'grupo_nome' => 'Firewalls (pfSense)', 'ultimo_inv' => null,
        ];
    }

    // 4) Servidores MGV
    foreach ($pdo->query("SELECT id, nome, ip FROM portal_servidores_mgv", PDO::FETCH_ASSOC) as $s) {
        $itens[] = [
            'origem' => 'mgv', 'origem_id' => (int) $s['id'], 'nome' => (string) $s['nome'],
            'ip' => monitor_ip_valido((string) $s['ip']) ? trim((string) $s['ip']) : null,
            'loja' => monitor_loja_curta((string) $s['nome']),
            'grupo' => 'servidores-mgv', 'grupo_nome' => 'Servidores MGV', 'ultimo_inv' => null,
        ];
    }

    // fontes com 1 IP só: a lista de candidatos é o próprio IP
    foreach ($itens as &$it) $it['ips'] ??= $it['ip'];
    unset($it);

    return monitor_resolver_ip_duplicado($itens);
}

/**
 * Grava/atualiza a cópia do inventário. Item novo entra com o padrão do
 * grupo (monitorar_novos); IP duplicado entra desligado. A chave "monitorar"
 * e o ip_fixo de quem já existe nunca são sobrescritos. Quem sumiu do
 * inventário ganha removido_em (e volta a NULL se reaparecer).
 *
 * @param array|null $itens null = lê de monitor_fontes_inventario()
 * @return array ['novos' => int, 'total' => int]
 */
function monitor_sincronizar(PDO $pdo, ?array $itens = null): array
{
    $itens ??= monitor_fontes_inventario($pdo);

    $novosPorGrupo = [];
    foreach ($itens as $it) {
        if (!isset($novosPorGrupo[$it['grupo']])) {
            monitor_grupo_garantir($pdo, $it['grupo'], (string) ($it['grupo_nome'] ?? $it['grupo']));
            $novosPorGrupo[$it['grupo']] = (int) (monitor_grupo($pdo, $it['grupo'])['monitorar_novos'] ?? 0);
        }
    }

    $ins = $pdo->prepare("INSERT INTO portal_monitor_dispositivos
            (origem, origem_id, nome, ip, ips, loja, grupo, duplicado_de, monitorar)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nome = VALUES(nome), ip = VALUES(ip), ips = VALUES(ips), loja = VALUES(loja),
            grupo = VALUES(grupo), duplicado_de = VALUES(duplicado_de), removido_em = NULL");
    $novos = 0;
    $vistos = [];
    foreach ($itens as $it) {
        $monitorar = $it['duplicado_de'] === null ? $novosPorGrupo[$it['grupo']] : 0;
        $ins->execute([$it['origem'], $it['origem_id'], $it['nome'], $it['ip'], $it['ips'] ?? $it['ip'], $it['loja'],
                       $it['grupo'], $it['duplicado_de'], $monitorar]);
        if ($ins->rowCount() === 1) $novos++; // 1 = inseriu, 2 = atualizou, 0 = igual
        $vistos[$it['origem'] . ':' . $it['origem_id']] = true;
    }

    // marca quem saiu do inventário — só quando a lista veio completa do banco
    // (chamada com lista parcial, como nos testes, não pode "remover" o resto)
    if (func_num_args() < 2) {
        foreach ($pdo->query("SELECT id, origem, origem_id FROM portal_monitor_dispositivos
                              WHERE origem <> 'manual' AND removido_em IS NULL", PDO::FETCH_ASSOC) as $r) {
            if (!isset($vistos[$r['origem'] . ':' . $r['origem_id']])) {
                $pdo->prepare("UPDATE portal_monitor_dispositivos SET removido_em = NOW() WHERE id = ?")->execute([$r['id']]);
            }
        }
    }
    return ['novos' => $novos, 'total' => count($itens)];
}

/* ───────────────────────────── Dispositivos ───────────────────────────── */

function monitor_dispositivo(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_monitor_dispositivos WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Pela origem do inventário (ex.: 'glpi' + id do computador) — usado pela chave na tela do inventário. */
function monitor_dispositivo_por_origem(PDO $pdo, string $origem, int $origemId): ?array
{
    $st = $pdo->prepare("SELECT d.*, COALESCE(d.ip_fixo, d.ip) AS ip_efetivo, g.nome AS grupo_nome
                         FROM portal_monitor_dispositivos d
                         LEFT JOIN portal_monitor_grupos g ON g.grupo = d.grupo
                         WHERE d.origem = ? AND d.origem_id = ?");
    $st->execute([$origem, $origemId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Tudo (menos removidos do inventário), com o IP efetivo (ip_fixo vence). */
function monitor_listar(PDO $pdo): array
{
    return $pdo->query("
        SELECT d.*, COALESCE(d.ip_fixo, d.ip) AS ip_efetivo, g.nome AS grupo_nome
        FROM portal_monitor_dispositivos d
        LEFT JOIN portal_monitor_grupos g ON g.grupo = d.grupo
        WHERE d.removido_em IS NULL
        ORDER BY g.nome, d.loja, d.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function monitor_set_monitorar(PDO $pdo, int $id, bool $ligar): void
{
    $pdo->prepare("UPDATE portal_monitor_dispositivos SET monitorar = ? WHERE id = ?")->execute([$ligar ? 1 : 0, $id]);
}

/** '' limpa (volta a usar o IP do inventário). */
function monitor_set_ip_fixo(PDO $pdo, int $id, string $ip): void
{
    $ip = trim($ip);
    if ($ip !== '' && !monitor_ip_valido($ip)) throw new \InvalidArgumentException('IP inválido');
    $pdo->prepare("UPDATE portal_monitor_dispositivos SET ip_fixo = ? WHERE id = ?")->execute([$ip !== '' ? $ip : null, $id]);
}

/** Equipamento fora do inventário (switch, link, NAS...). Entra com o padrão do grupo. */
function monitor_manual_criar(PDO $pdo, string $nome, string $ip, string $loja, string $grupo): int
{
    $nome = trim($nome);
    if ($nome === '') throw new \InvalidArgumentException('nome obrigatório');
    if (!monitor_ip_valido($ip)) throw new \InvalidArgumentException('IP inválido');
    $g = monitor_grupo($pdo, $grupo);
    if ($g === null) throw new \InvalidArgumentException('grupo inexistente');

    $pdo->prepare("INSERT INTO portal_monitor_dispositivos (origem, origem_id, nome, ip, ips, loja, grupo, monitorar)
                   VALUES ('manual', NULL, ?, ?, ?, ?, ?, ?)")
        ->execute([$nome, trim($ip), trim($ip), monitor_loja_curta($loja), $grupo, (int) $g['monitorar_novos']]);
    return (int) $pdo->lastInsertId();
}

function monitor_manual_atualizar(PDO $pdo, int $id, string $nome, string $ip, string $loja, string $grupo): void
{
    if (trim($nome) === '') throw new \InvalidArgumentException('nome obrigatório');
    if (!monitor_ip_valido($ip)) throw new \InvalidArgumentException('IP inválido');
    if (monitor_grupo($pdo, $grupo) === null) throw new \InvalidArgumentException('grupo inexistente');
    $pdo->prepare("UPDATE portal_monitor_dispositivos SET nome = ?, ip = ?, ips = ?, loja = ?, grupo = ?
                   WHERE id = ? AND origem = 'manual'")
        ->execute([trim($nome), trim($ip), trim($ip), monitor_loja_curta($loja), $grupo, $id]);
}

/** Só manuais — os do inventário se desligam com a chave Monitorar. */
function monitor_manual_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_monitor_dispositivos WHERE id = ? AND origem = 'manual'")->execute([$id]);
}

/* ───────────────────────────── Semente a partir do Dude (1 vez) ───────────────────────────── */

/**
 * Liga "Monitorar" em tudo que o Dude monitora hoje (casando por IP com o
 * inventário) e cria como manual o que não está no inventário. Roda uma vez
 * só (flag monitor_semente_dude em wpp_cfg).
 * @return array ['ligados' => int, 'manuais' => int] ou [] se já rodou
 */
function monitor_semear_do_dude(PDO $pdo): array
{
    require_once __DIR__ . '/wpp/db.php';
    if (wpp_cfg_get('monitor_semente_dude', '') !== '') return [];

    $mapaGrupo = ['PDVs' => 'pdvs', 'Balanca' => 'balancas', 'Servidor' => 'maquinas-virtuais'];
    $ligados = 0;
    $manuais = 0;
    $dude = $pdo->query("SELECT nome, endereco, loja, categoria FROM portal_dude_estado
                         WHERE tipo = 'device' AND endereco <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE portal_monitor_dispositivos SET monitorar = 1
                          WHERE (ip_fixo = ? OR FIND_IN_SET(?, ips)) AND duplicado_de IS NULL AND removido_em IS NULL");
    foreach ($dude as $d) {
        $upd->execute([$d['endereco'], $d['endereco']]);
        if ($upd->rowCount() > 0) { $ligados += $upd->rowCount(); continue; }

        // já existe (qualquer origem) com esse IP, mesmo que já ligado? não duplica
        $st = $pdo->prepare("SELECT COUNT(*) FROM portal_monitor_dispositivos WHERE ip_fixo = ? OR FIND_IN_SET(?, ips)");
        $st->execute([$d['endereco'], $d['endereco']]);
        if ((int) $st->fetchColumn() > 0) continue;

        $id = monitor_manual_criar($pdo, (string) $d['nome'], (string) $d['endereco'], (string) $d['loja'],
                                   $mapaGrupo[$d['categoria']] ?? 'pcs-retaguarda');
        monitor_set_monitorar($pdo, $id, true);
        $manuais++;
    }
    wpp_cfg_set('monitor_semente_dude', date('Y-m-d H:i:s'));
    return ['ligados' => $ligados, 'manuais' => $manuais];
}
