<?php
/**
 * API de dados — Relatório "Histórico de chamados por equipamento" (geral).
 * Lê glpi_items_tickets (vínculo equipamento ↔ chamado) e agrupa por equipamento.
 *
 * GET ?dt_ini=YYYY-MM-DD&dt_fim=YYYY-MM-DD&entidade_id=N
 * Filtro de categoria e busca são client-side (dataset pequeno).
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { echo json_encode([]); exit; }

header('Content-Type: application/json');
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';
require_once __DIR__ . '/inventario_lib.php';   // INV_PC_CATS

$dt_ini      = ($_GET['dt_ini'] ?? date('Y-m-01')) . ' 00:00:00';
$dt_fim      = ($_GET['dt_fim'] ?? date('Y-m-d'))  . ' 23:59:59';
$entidade_id = (int)($_GET['entidade_id'] ?? 0);

$STATUS = [1 => 'Novo', 2 => 'Em atendimento', 3 => 'Planejado', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];

$entCond = $entidade_id ? " AND p.entities_id = :ent " : "";
$bind = [':ini' => $dt_ini, ':fim' => $dt_fim];
if ($entidade_id) $bind[':ent'] = $entidade_id;

$equip = [];   // "itemtype:id" => registro agregado

function inv_rel_acumula(array &$equip, array $r, array $STATUS): void {
    $k = $r['itemtype'] . ':' . $r['item_id'];
    if (!isset($equip[$k])) {
        $equip[$k] = [
            'itemtype'  => $r['itemtype'],
            'item_id'   => (int)$r['item_id'],
            'nome'      => $r['nome'] !== '' ? $r['nome'] : '(sem nome)',
            'categoria' => $r['categoria'],
            'loja_id'   => (int)$r['loja_id'],
            'loja'      => apelido_entidade($r['loja'] ?? '') ?: '—',
            'chamados'  => [],
        ];
    }
    $equip[$k]['chamados'][] = [
        'id'          => (int)$r['tid'],
        'titulo'      => $r['titulo'],
        'status'      => (int)$r['status'],
        'status_nome' => $STATUS[(int)$r['status']] ?? '?',
        'data'        => substr((string)$r['date'], 0, 10),
        'fechado'     => $r['closedate'] ? substr((string)$r['closedate'], 0, 10) : null,
    ];
}

try {
    $baseJoinTicket = "JOIN glpi_tickets t ON t.id = it.tickets_id AND t.is_deleted = 0
                       AND t.date BETWEEN :ini AND :fim";

    // ── Computadores (categoria vem de portal_inv_pc_cat) ──
    $sql = "SELECT 'Computer' itemtype, p.id item_id, p.name nome, p.entities_id loja_id,
                   e.completename loja, COALESCE(pc.categoria,'pcs-retaguarda') cat_slug,
                   t.id tid, t.name titulo, t.status, t.date, t.closedate
            FROM glpi_items_tickets it
            JOIN glpi_computers p ON p.id = it.items_id AND it.itemtype = 'Computer' AND p.is_deleted = 0
            LEFT JOIN portal_inv_pc_cat pc ON pc.computer_id = p.id
            LEFT JOIN glpi_entities e ON e.id = p.entities_id
            $baseJoinTicket
            WHERE 1=1 $entCond";
    $st = $pdo->prepare($sql); $st->execute($bind);
    foreach ($st as $r) {
        $r['categoria'] = INV_PC_CATS[$r['cat_slug']] ?? 'PC Retaguarda';
        inv_rel_acumula($equip, $r, $STATUS);
    }

    // ── Impressoras ──
    $sql = "SELECT 'Printer' itemtype, p.id item_id, p.name nome, p.entities_id loja_id, e.completename loja,
                   t.id tid, t.name titulo, t.status, t.date, t.closedate
            FROM glpi_items_tickets it
            JOIN glpi_printers p ON p.id = it.items_id AND it.itemtype = 'Printer' AND p.is_deleted = 0
            LEFT JOIN glpi_entities e ON e.id = p.entities_id
            $baseJoinTicket
            WHERE 1=1 $entCond";
    $st = $pdo->prepare($sql); $st->execute($bind);
    foreach ($st as $r) { $r['categoria'] = 'Impressora'; inv_rel_acumula($equip, $r, $STATUS); }

    // ── Celulares ──
    $sql = "SELECT 'Phone' itemtype, p.id item_id, p.name nome, p.entities_id loja_id, e.completename loja,
                   t.id tid, t.name titulo, t.status, t.date, t.closedate
            FROM glpi_items_tickets it
            JOIN glpi_phones p ON p.id = it.items_id AND it.itemtype = 'Phone' AND p.is_deleted = 0
            LEFT JOIN glpi_entities e ON e.id = p.entities_id
            $baseJoinTicket
            WHERE 1=1 $entCond";
    $st = $pdo->prepare($sql); $st->execute($bind);
    foreach ($st as $r) { $r['categoria'] = 'Celular'; inv_rel_acumula($equip, $r, $STATUS); }

    // ── Periféricos (categoria = nome do tipo no GLPI) ──
    $sql = "SELECT 'Peripheral' itemtype, p.id item_id, p.name nome, p.entities_id loja_id, e.completename loja,
                   COALESCE(pt.name, 'Periférico') cat_nome,
                   t.id tid, t.name titulo, t.status, t.date, t.closedate
            FROM glpi_items_tickets it
            JOIN glpi_peripherals p ON p.id = it.items_id AND it.itemtype = 'Peripheral' AND p.is_deleted = 0
            LEFT JOIN glpi_peripheraltypes pt ON pt.id = p.peripheraltypes_id
            LEFT JOIN glpi_entities e ON e.id = p.entities_id
            $baseJoinTicket
            WHERE 1=1 $entCond";
    $st = $pdo->prepare($sql); $st->execute($bind);
    foreach ($st as $r) { $r['categoria'] = $r['cat_nome']; inv_rel_acumula($equip, $r, $STATUS); }

    // ── Consolida ──
    $lista = array_values($equip);
    foreach ($lista as &$e) {
        usort($e['chamados'], fn($a, $b) => strcmp($b['data'], $a['data']) ?: ($b['id'] - $a['id']));
        $e['num_chamados'] = count($e['chamados']);
        $e['ultimo']       = $e['chamados'][0]['data'] ?? null;
        $e['abertos']      = count(array_filter($e['chamados'], fn($c) => $c['status'] < 5));
    }
    unset($e);
    usort($lista, fn($a, $b) => $b['num_chamados'] - $a['num_chamados']
        ?: strcmp($a['categoria'], $b['categoria'])
        ?: strcmp($a['nome'], $b['nome']));

    $cats = array_values(array_unique(array_map(fn($e) => $e['categoria'], $lista)));
    sort($cats, SORT_NATURAL | SORT_FLAG_CASE);

    $porCategoria = [];
    foreach ($lista as $e) {
        $porCategoria[$e['categoria']] ??= ['categoria' => $e['categoria'], 'equipamentos' => 0, 'chamados' => 0];
        $porCategoria[$e['categoria']]['equipamentos']++;
        $porCategoria[$e['categoria']]['chamados'] += $e['num_chamados'];
    }
    usort($porCategoria, fn($a, $b) => $b['chamados'] - $a['chamados']);

    echo json_encode([
        'ok'           => true,
        'equipamentos' => $lista,
        'categorias'   => $cats,
        'por_categoria'=> array_values($porCategoria),
        'kpis'         => [
            'equip_com_chamado' => count($lista),
            'total_vinculos'    => array_sum(array_map(fn($e) => $e['num_chamados'], $lista)),
            'top_nome'          => $lista[0]['nome'] ?? '—',
            'top_qtd'           => $lista[0]['num_chamados'] ?? 0,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
