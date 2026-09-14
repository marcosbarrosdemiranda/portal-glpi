<?php
/**
 * impressoras_lib.php — cadastro e consulta de impressoras de rede via SNMP
 * pra Central de Alertas / Inventário.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria as tabelas ao ser incluído (padrão do portal, igual dude_lib.php).
 */

require_once __DIR__ . '/agenda/db.php';

(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45)  NOT NULL,
        apelido      VARCHAR(120) NOT NULL,
        loja         VARCHAR(120) NOT NULL DEFAULT '',
        comunidade   VARCHAR(60)  NOT NULL DEFAULT 'public',
        ativo        TINYINT(1)   NOT NULL DEFAULT 1,
        criado_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_status (
        impressora_id    INT PRIMARY KEY,
        online           TINYINT(1) NOT NULL,
        modelo           VARCHAR(255) NULL,
        serial           VARCHAR(120) NULL,
        firmware         VARCHAR(120) NULL,
        paginas_total    INT NULL,
        consumiveis_json MEDIUMTEXT NULL,
        atualizado_em    DATETIME NOT NULL,
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // migração leve — coluna acrescentada depois do primeiro deploy (padrão do portal)
    $colsStatus = [];
    foreach ($pdo->query("SHOW COLUMNS FROM portal_impressoras_status") as $r) $colsStatus[] = $r['Field'];
    if (!in_array('alertas_json', $colsStatus, true)) {
        $pdo->exec("ALTER TABLE portal_impressoras_status ADD COLUMN alertas_json MEDIUMTEXT NULL AFTER consumiveis_json");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_historico (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        impressora_id INT NOT NULL,
        paginas_total INT NOT NULL,
        registrado_em DATETIME NOT NULL,
        KEY (impressora_id, registrado_em),
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── CRUD ───────────────────────────── */

function impressora_cadastrar(PDO $pdo, string $ip, string $apelido, string $loja, string $comunidade = 'public'): int
{
    $pdo->prepare(
        "INSERT INTO portal_impressoras (ip, apelido, loja, comunidade) VALUES (?, ?, ?, ?)"
    )->execute([$ip, $apelido, $loja, $comunidade]);
    return (int) $pdo->lastInsertId();
}

/** Só as ativas — mesmo padrão de dude_categorias_vistas (config só existe pra quem está em uso). */
function impressora_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_impressoras WHERE ativo = 1 ORDER BY loja, apelido")->fetchAll(PDO::FETCH_ASSOC);
}

function impressora_buscar(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_impressoras WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function impressora_editar(PDO $pdo, int $id, string $ip, string $apelido, string $loja, string $comunidade): void
{
    $pdo->prepare(
        "UPDATE portal_impressoras SET ip=?, apelido=?, loja=?, comunidade=? WHERE id=?"
    )->execute([$ip, $apelido, $loja, $comunidade, $id]);
}

function impressora_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_impressoras WHERE id = ?")->execute([$id]);
}

/* ───────────────────────────── SNMP ───────────────────────────── */

const IMPRESSORA_OID_SYSDESCR   = '1.3.6.1.2.1.1.1.0';
const IMPRESSORA_OID_SERIAL     = '1.3.6.1.2.1.43.5.1.1.17.1';
const IMPRESSORA_OID_PAGINAS    = '1.3.6.1.2.1.43.10.2.1.4.1.1';
const IMPRESSORA_OID_SUP_DESC   = '1.3.6.1.2.1.43.11.1.1.6.1';
const IMPRESSORA_OID_SUP_NIVEL  = '1.3.6.1.2.1.43.11.1.1.9.1';
const IMPRESSORA_OID_SUP_MAX    = '1.3.6.1.2.1.43.11.1.1.8.1';
// prtAlertTable (RFC 3805) - tabela de alertas ativos (atolamento, sem
// papel, tampa aberta etc). Descricao ja vem em texto legivel do proprio
// firmware da impressora - mais simples e universal que decodificar os
// ~250 codigos numericos possiveis de prtAlertCode.
const IMPRESSORA_OID_ALERT_SEVERIDADE = '1.3.6.1.2.1.43.18.1.1.2';
const IMPRESSORA_OID_ALERT_DESCRICAO  = '1.3.6.1.2.1.43.18.1.1.8';

/**
 * Consulta SNMP de verdade (I/O de rede) — separada de impressora_snmp_parsear()
 * só pra essa poder ser testada sem rede/hardware físico.
 * Nunca lança: timeout/erro de rede vira ['online' => false, ...].
 */
function impressora_snmp_consultar(string $ip, string $comunidade, int $timeoutMs = 2500): array
{
    $timeoutUs = $timeoutMs * 1000;
    snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    snmp_set_quick_print(true);

    $bruto = [
        'sysDescr'      => @snmpget($ip, $comunidade, IMPRESSORA_OID_SYSDESCR, $timeoutUs, 1),
        'serial'        => @snmpget($ip, $comunidade, IMPRESSORA_OID_SERIAL, $timeoutUs, 1),
        'paginas_total' => @snmpget($ip, $comunidade, IMPRESSORA_OID_PAGINAS, $timeoutUs, 1),
        'consumiveis_descricoes' => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_DESC, $timeoutUs, 1) ?: [],
        'consumiveis_niveis'     => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_NIVEL, $timeoutUs, 1) ?: [],
        'consumiveis_maximos'    => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_MAX, $timeoutUs, 1) ?: [],
        'alertas_severidade' => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_ALERT_SEVERIDADE, $timeoutUs, 1) ?: [],
        'alertas_descricao'  => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_ALERT_DESCRICAO, $timeoutUs, 1) ?: [],
    ];

    return impressora_snmp_parsear($bruto);
}

/**
 * Transforma a resposta bruta do SNMP (ou um array simulado, nos testes) no
 * formato usado pelo resto do sistema. Nunca lança.
 *
 * $bruto: ['sysDescr'=>string|false, 'serial'=>string|false|null,
 *          'paginas_total'=>string|int|null|false,
 *          'consumiveis_descricoes'=>array, 'consumiveis_niveis'=>array, 'consumiveis_maximos'=>array]
 */
function impressora_snmp_parsear(array $bruto): array
{
    $sysDescr = $bruto['sysDescr'] ?? false;
    if ($sysDescr === false || $sysDescr === null || $sysDescr === '') {
        return ['online' => false, 'modelo' => null, 'serial' => null, 'firmware' => null, 'paginas_total' => null, 'consumiveis' => [], 'alertas' => []];
    }

    $serial = $bruto['serial'] ?? null;
    $serial = (is_string($serial) && $serial !== '') ? trim($serial) : null;

    $paginasRaw = $bruto['paginas_total'] ?? null;
    $paginas    = (is_numeric($paginasRaw)) ? (int) $paginasRaw : null;

    $consumiveis = [];
    $descricoes = $bruto['consumiveis_descricoes'] ?? [];
    $niveis     = array_values($bruto['consumiveis_niveis'] ?? []);
    $maximos    = array_values($bruto['consumiveis_maximos'] ?? []);
    $i = 0;
    foreach (array_values($descricoes) as $nome) {
        $nivelRaw = $niveis[$i] ?? null;
        $maxRaw   = $maximos[$i] ?? null;
        $nivel = is_numeric($nivelRaw) ? (int) $nivelRaw : null;
        $max   = is_numeric($maxRaw) ? (int) $maxRaw : null;
        // -2 = "nao reporta percentual" (RFC 3805 prtMarkerSuppliesLevel) - trata como sem dado, nao erro.
        if ($nivel === -2 || $max === null || $max <= 0) {
            $nivel = null;
            $max   = null;
        }
        $consumiveis[] = ['nome' => (string) $nome, 'nivel' => $nivel, 'max' => $max];
        $i++;
    }

    // Alertas ativos (prtAlertTable). Severidade 1 = "other"/informativo,
    // ignorada — só 3 (critical) e 4 (warning) são alertas de verdade
    // (valores padronizados pelo RFC 3805, PrtAlertSeverityLevelTC).
    $alertas = [];
    $severidades = array_values($bruto['alertas_severidade'] ?? []);
    $descricoesAlerta = array_values($bruto['alertas_descricao'] ?? []);
    foreach ($descricoesAlerta as $idx => $desc) {
        $sev = isset($severidades[$idx]) && is_numeric($severidades[$idx]) ? (int) $severidades[$idx] : null;
        if ($sev !== 3 && $sev !== 4) continue;
        $alertas[] = ['severidade' => $sev, 'descricao' => (string) $desc];
    }

    return [
        'online'        => true,
        'modelo'        => trim((string) $sysDescr),
        'serial'        => $serial,
        'firmware'      => null, // sysDescr costuma trazer versao junto do modelo, sem OID separado universal
        'paginas_total' => $paginas,
        'consumiveis'   => $consumiveis,
        'alertas'       => $alertas,
    ];
}

/* ───────────────────────────── Status + histórico ───────────────────────────── */

function impressora_status_salvar(PDO $pdo, int $impressoraId, array $consulta): void
{
    $pdo->prepare(
        "INSERT INTO portal_impressoras_status
            (impressora_id, online, modelo, serial, firmware, paginas_total, consumiveis_json, alertas_json, atualizado_em)
         VALUES (?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE online=VALUES(online), modelo=VALUES(modelo), serial=VALUES(serial),
             firmware=VALUES(firmware), paginas_total=VALUES(paginas_total),
             consumiveis_json=VALUES(consumiveis_json), alertas_json=VALUES(alertas_json), atualizado_em=NOW()"
    )->execute([
        $impressoraId,
        $consulta['online'] ? 1 : 0,
        $consulta['modelo'] ?? null,
        $consulta['serial'] ?? null,
        $consulta['firmware'] ?? null,
        $consulta['paginas_total'] ?? null,
        !empty($consulta['consumiveis']) ? json_encode($consulta['consumiveis']) : null,
        !empty($consulta['alertas']) ? json_encode($consulta['alertas']) : null,
    ]);

    $paginas = $consulta['paginas_total'] ?? null;
    if ($paginas === null) {
        return; // offline ou modelo nao reporta contador - nao ha o que gravar no historico
    }

    $ultimo = $pdo->prepare(
        "SELECT paginas_total FROM portal_impressoras_historico WHERE impressora_id = ? ORDER BY registrado_em DESC, id DESC LIMIT 1"
    );
    $ultimo->execute([$impressoraId]);
    $anterior = $ultimo->fetchColumn();

    if ($anterior === false || (int) $anterior !== (int) $paginas) {
        $pdo->prepare(
            "INSERT INTO portal_impressoras_historico (impressora_id, paginas_total, registrado_em) VALUES (?, ?, NOW())"
        )->execute([$impressoraId, $paginas]);
    }
}

function impressora_status_atual(PDO $pdo, int $impressoraId): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_impressoras_status WHERE impressora_id = ?");
    $st->execute([$impressoraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    $row['consumiveis'] = $row['consumiveis_json'] ? json_decode($row['consumiveis_json'], true) : [];
    $row['alertas']     = $row['alertas_json'] ? json_decode($row['alertas_json'], true) : [];
    return $row;
}

/** @return array linhas ['paginas_total'=>int,'registrado_em'=>string], mais antiga primeiro. */
function impressora_historico_paginas(PDO $pdo, int $impressoraId, int $dias = 90): array
{
    $st = $pdo->prepare(
        "SELECT paginas_total, registrado_em FROM portal_impressoras_historico
         WHERE impressora_id = ? AND registrado_em >= NOW() - INTERVAL ? DAY
         ORDER BY registrado_em, id"
    );
    $st->execute([$impressoraId, $dias]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Páginas impressas POR MÊS (delta do contador, não o total acumulado).
 * O 1o mês rastreado usa a leitura mais antiga do próprio mês como base
 * (não dá pra saber o contador antes de começar a rastrear); os meses
 * seguintes usam o fechamento do mês anterior, o que é preciso mesmo
 * quando o mês vira sem nenhuma leitura exatamente no dia 1.
 *
 * @return array [['mes'=>'YYYY-MM','paginas'=>int], ...] mais antigo primeiro.
 */
function impressora_paginas_por_mes(PDO $pdo, int $impressoraId, int $meses = 12): array
{
    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(registrado_em, '%Y-%m') AS mes,
                MIN(paginas_total) AS abertura, MAX(paginas_total) AS fechamento
         FROM portal_impressoras_historico
         WHERE impressora_id = ? AND registrado_em >= DATE_SUB(NOW(), INTERVAL ? MONTH)
         GROUP BY mes ORDER BY mes"
    );
    $st->execute([$impressoraId, $meses]);

    $out = [];
    $fechamentoAnterior = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $base = $fechamentoAnterior ?? (int) $linha['abertura'];
        $out[] = ['mes' => $linha['mes'], 'paginas' => max(0, (int) $linha['fechamento'] - $base)];
        $fechamentoAnterior = (int) $linha['fechamento'];
    }
    return $out;
}
