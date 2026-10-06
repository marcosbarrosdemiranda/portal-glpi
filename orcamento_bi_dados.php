<?php
/**
 * orcamento_bi_dados.php
 * Endpoint de agregação pro Painel de Relatórios (abas Previsto vs
 * Realizado / Orçamento / Gestão de Despesas) — endpoint dedicado, fora
 * do fetch central relatorios_dados.php, mesmo padrão já usado por
 * Projetos/Equipamentos/Impressões em relatorios.php.
 *
 * Read-only. Nenhuma ação de escrita aqui.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['ok' => false, 'error' => 'não autenticado']); exit; }

require_once __DIR__ . '/agenda/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$loja   = trim($_GET['loja'] ?? '');
$ano    = (int)($_GET['ano'] ?? date('Y'));

function orc_meses_do_ano(int $ano): array {
    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[] = sprintf('%04d-%02d', $ano, $m);
    return $out;
}

/**
 * Agregado mensal + por categoria do lado Orçamento (planejado), com
 * filtro de ativo<>0 replicando o que hoje só existe no JS do cliente
 * (orcamento.php, função ativoItem()).
 */
function orc_bi_orcamento(PDO $pdo, int $ano, string $loja): array {
    $params = [$ano . '-01', $ano . '-12'];
    $sqlLoja = '';
    if ($loja !== '') { $sqlLoja = ' AND o.loja = ?'; $params[] = $loja; }

    $sql = "SELECT o.mes_ano, o.tipo_despesa_id, t.nome AS categoria,
                   SUM(o.qty_prevista * o.unit_previsto) AS total
            FROM glpi_portal_orcamento o
            LEFT JOIN glpi_portal_despesas_tipos t ON o.tipo_despesa_id = t.id
            WHERE o.ativo <> 0 AND o.mes_ano BETWEEN ? AND ? $sqlLoja
            GROUP BY o.mes_ano, o.tipo_despesa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Agregado mensal + por categoria do lado Despesas (realizado). Sem
 * filtro de ativo — esse conceito não existe nessa tabela.
 */
function orc_bi_despesas(PDO $pdo, int $ano, string $loja): array {
    $params = [$ano];
    $sqlLoja = '';
    if ($loja !== '') { $sqlLoja = ' AND d.loja = ?'; $params[] = $loja; }

    $sql = "SELECT DATE_FORMAT(d.data_pagamento, '%Y-%m') AS mes_ano,
                   d.tipo_despesa_id, t.nome AS categoria,
                   SUM(d.valor_pago) AS total
            FROM glpi_portal_despesas d
            LEFT JOIN glpi_portal_despesas_tipos t ON d.tipo_despesa_id = t.id
            WHERE YEAR(d.data_pagamento) = ? $sqlLoja
            GROUP BY mes_ano, d.tipo_despesa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Monta a série mensal (12 meses, zero onde não há dado) a partir das linhas agregadas por mes_ano+categoria. */
function orc_serie_mensal(array $linhas, int $ano): array {
    $porMes = array_fill_keys(orc_meses_do_ano($ano), 0.0);
    foreach ($linhas as $l) {
        $porMes[$l['mes_ano']] = ($porMes[$l['mes_ano']] ?? 0) + (float)$l['total'];
    }
    $out = [];
    foreach ($porMes as $mes => $total) $out[] = ['mes' => $mes, 'total' => round($total, 2)];
    return $out;
}

/** Agrega por categoria (soma de todos os meses do ano filtrado). */
function orc_serie_categoria(array $linhas): array {
    $porCat = [];
    foreach ($linhas as $l) {
        $id = $l['tipo_despesa_id'] ?? 0;
        $nome = $l['categoria'] ?? 'Outros';
        if (!isset($porCat[$id])) $porCat[$id] = ['tipo_despesa_id' => $id, 'categoria' => $nome, 'total' => 0.0];
        $porCat[$id]['total'] += (float)$l['total'];
    }
    $out = array_values($porCat);
    foreach ($out as &$c) $c['total'] = round($c['total'], 2);
    usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
    return $out;
}

try {
    if ($action === 'orcamento') {
        $linhasAtual = orc_bi_orcamento($pdo, $ano, $loja);
        $linhasAnterior = orc_bi_orcamento($pdo, $ano - 1, $loja);
        echo json_encode([
            'ok' => true,
            'ano' => $ano,
            'mensal' => orc_serie_mensal($linhasAtual, $ano),
            'mensal_ano_anterior' => orc_serie_mensal($linhasAnterior, $ano - 1),
            'por_categoria' => orc_serie_categoria($linhasAtual),
        ]);
    } elseif ($action === 'despesas') {
        $linhasAtual = orc_bi_despesas($pdo, $ano, $loja);
        $linhasAnterior = orc_bi_despesas($pdo, $ano - 1, $loja);
        echo json_encode([
            'ok' => true,
            'ano' => $ano,
            'mensal' => orc_serie_mensal($linhasAtual, $ano),
            'mensal_ano_anterior' => orc_serie_mensal($linhasAnterior, $ano - 1),
            'por_categoria' => orc_serie_categoria($linhasAtual),
        ]);
    } elseif ($action === 'previsto_realizado') {
        $linhasOrc = orc_bi_orcamento($pdo, $ano, $loja);
        $linhasDesp = orc_bi_despesas($pdo, $ano, $loja);

        $mesesOrc = orc_serie_mensal($linhasOrc, $ano);
        $mesesDesp = orc_serie_mensal($linhasDesp, $ano);
        $mensal = [];
        foreach ($mesesOrc as $i => $m) {
            $mensal[] = ['mes' => $m['mes'], 'previsto' => $m['total'], 'realizado' => $mesesDesp[$i]['total']];
        }

        // Casa por tipo_despesa_id (chave garantida pela migração de schema
        // — categoria não é mais string livre em nenhum dos dois lados).
        $catOrc = orc_serie_categoria($linhasOrc);
        $catDesp = orc_serie_categoria($linhasDesp);
        $porCategoria = [];
        foreach ($catOrc as $c) $porCategoria[$c['tipo_despesa_id']] = ['tipo_despesa_id' => $c['tipo_despesa_id'], 'categoria' => $c['categoria'], 'previsto' => $c['total'], 'realizado' => 0.0];
        foreach ($catDesp as $c) {
            if (!isset($porCategoria[$c['tipo_despesa_id']])) {
                $porCategoria[$c['tipo_despesa_id']] = ['tipo_despesa_id' => $c['tipo_despesa_id'], 'categoria' => $c['categoria'], 'previsto' => 0.0, 'realizado' => 0.0];
            }
            $porCategoria[$c['tipo_despesa_id']]['realizado'] = $c['total'];
        }
        $porCategoria = array_values($porCategoria);
        usort($porCategoria, fn($a, $b) => $b['previsto'] <=> $a['previsto']);

        echo json_encode([
            'ok' => true,
            'ano' => $ano,
            'mensal' => $mensal,
            'por_categoria' => $porCategoria,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action inválida']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
