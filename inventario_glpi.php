<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';
require_once __DIR__ . '/inventario_lib.php';

inv_bootstrap($pdo);

$slug = $_GET['cat'] ?? '';
$card = inv_card($pdo, $slug);
if (!$card) { header('Location: inventario.php'); exit; }

$fonte     = $card['fonte'];                       // peripheral | phone
$itemtype  = $fonte === 'phone' ? 'Phone' : 'Peripheral';
$tbl       = $fonte === 'phone' ? 'glpi_phones' : 'glpi_peripherals';
$tblType   = $fonte === 'phone' ? 'glpi_phonetypes' : 'glpi_peripheraltypes';
$tblModel  = $fonte === 'phone' ? 'glpi_phonemodels' : 'glpi_peripheralmodels';
$colType   = $fonte === 'phone' ? 'phonetypes_id' : 'peripheraltypes_id';
$colModel  = $fonte === 'phone' ? 'phonemodels_id' : 'peripheralmodels_id';
$glpiForm  = $fonte === 'phone' ? 'phone.form.php' : 'peripheral.form.php';

$subcats = inv_subcats($pdo, (int)$card['id']);
$fields  = inv_fields($pdo, (int)$card['id']);
$fieldsLista = array_values(array_filter($fields, fn($f) => !empty($f['na_lista']) && $f['tipo'] !== 'textarea'));

/* ─────────────────────── AÇÕES (JSON) ─────────────────────── */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'form') {                       // carrega 1 ativo pra edição
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT p.*, m.name AS fabricante_nome, md.name AS modelo_nome
                         FROM `$tbl` p
                         LEFT JOIN glpi_manufacturers m ON m.id = p.manufacturers_id
                         LEFT JOIN `$tblModel` md ON md.id = p.$colModel
                         WHERE p.id = ?");
    $st->execute([$id]);
    $it = $st->fetch();
    if (!$it) { echo json_encode(['ok' => false, 'erro' => 'Ativo não encontrado']); exit; }
    // descobre a subcategoria pela associação tipo → subcat
    $subId = 0;
    foreach ($subcats as $s) { if ((int)$s['glpi_type_id'] === (int)$it[$colType]) { $subId = (int)$s['id']; break; } }
    echo json_encode([
        'ok'   => true,
        'item' => [
            'id'          => (int)$it['id'],
            'name'        => $it['name'],
            'entities_id' => (int)$it['entities_id'],
            'subcat_id'   => $subId,
            'fabricante'  => $it['fabricante_nome'] ?? '',
            'modelo'      => $it['modelo_nome'] ?? '',
            'serial'      => $it['serial'] ?? '',
            'otherserial' => $it['otherserial'] ?? '',
            'comment'     => $it['comment'] ?? '',
            'valores'     => inv_values($pdo, $itemtype, $id),
        ],
    ]);
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $id   = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['name'] ?? '');
    if ($nome === '') { echo json_encode(['ok' => false, 'erro' => 'Nome é obrigatório']); exit; }

    $token = glpi_session();
    if (!$token) { echo json_encode(['ok' => false, 'erro' => 'Falha ao autenticar no GLPI']); exit; }

    try {
        // subcategoria → tipo no GLPI
        $typeId = null;
        $subId  = (int)($_POST['subcat_id'] ?? 0);
        foreach ($subcats as $s) {
            if ((int)$s['id'] !== $subId) continue;
            $typeId = $s['glpi_type_id'] ? (int)$s['glpi_type_id'] : glpi_ensure_type($pdo, $fonte, $s['nome'], $token);
            if ($typeId && !$s['glpi_type_id']) {
                $pdo->prepare("UPDATE portal_inv_subcats SET glpi_type_id = ? WHERE id = ?")->execute([$typeId, $subId]);
            }
        }

        $manId = glpi_ensure_dropdown($pdo, 'glpi_manufacturers', 'Manufacturer', $_POST['fabricante'] ?? '', $token);
        $mdEndpoint = $fonte === 'phone' ? 'PhoneModel' : 'PeripheralModel';
        $mdId  = glpi_ensure_dropdown($pdo, $tblModel, $mdEndpoint, $_POST['modelo'] ?? '', $token);

        $input = [
            'name'        => $nome,
            'entities_id' => (int)($_POST['entities_id'] ?? 0),
            $colType      => $typeId ?: 0,
            'manufacturers_id' => $manId ?: 0,
            $colModel     => $mdId ?: 0,
            'serial'      => trim($_POST['serial'] ?? ''),
            'otherserial' => trim($_POST['otherserial'] ?? ''),
            'comment'     => trim($_POST['comment'] ?? ''),
        ];

        if ($id) {
            [$code, $resp] = glpi_call('PUT', "$itemtype/$id", ['input' => $input], $token);
        } else {
            [$code, $resp] = glpi_call('POST', $itemtype, ['input' => $input], $token);
            $id = (int)($resp['id'] ?? $resp[0]['id'] ?? 0);
        }
        if ($code < 200 || $code >= 300 || !$id) {
            echo json_encode(['ok' => false, 'erro' => 'GLPI recusou (HTTP ' . $code . ')']);
            exit;
        }

        // campos personalizados → portal
        $vals = [];
        foreach ($fields as $f) {
            $k = 'campo_' . $f['id'];
            if (array_key_exists($k, $_POST)) $vals[(int)$f['id']] = trim((string)$_POST[$k]);
        }
        if ($vals) inv_save_values($pdo, $itemtype, $id, $vals);

        echo json_encode(['ok' => true, 'id' => $id]);
    } finally {
        glpi_kill($token);
    }
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    $token = glpi_session();
    if (!$token) { echo json_encode(['ok' => false, 'erro' => 'Falha ao autenticar no GLPI']); exit; }
    [$code, $resp] = glpi_call('DELETE', "$itemtype/$id", null, $token);  // soft delete → lixeira do GLPI
    glpi_kill($token);
    inv_baixa_clear($pdo, $itemtype, $id);
    echo json_encode(['ok' => ($code >= 200 && $code < 300), 'http' => $code]);
    exit;
}

if ($action === 'baixa') {
    header('Content-Type: application/json; charset=utf-8');
    $id  = (int)($_POST['id'] ?? 0);
    $por = $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    inv_baixa_set($pdo, $itemtype, $id, $_POST['motivo'] ?? 'quebrado',
                  trim($_POST['observacao'] ?? ''), $_POST['baixado_em'] ?? null, $por);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reativar') {
    header('Content-Type: application/json; charset=utf-8');
    inv_baixa_clear($pdo, $itemtype, (int)($_POST['id'] ?? 0));
    echo json_encode(['ok' => true]);
    exit;
}

/* ── Manutenção: chamados vinculados ao equipamento (glpi_items_tickets) ── */
if ($action === 'chamados') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("
        SELECT t.id, t.name, t.status, t.date, t.closedate,
               u.name AS tecnico_user, u.realname AS tecnico_realname, u.firstname AS tecnico_firstname
        FROM glpi_items_tickets it
        JOIN glpi_tickets t ON t.id = it.tickets_id
        LEFT JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2
        LEFT JOIN glpi_users u ON u.id = tu.users_id
        WHERE it.itemtype = ? AND it.items_id = ? AND t.is_deleted = 0
        GROUP BY t.id
        ORDER BY t.date DESC");
    $st->execute([$itemtype, $id]);
    $rows = [];
    foreach ($st as $r) {
        $tec = trim(($r['tecnico_firstname'] ?? '') . ' ' . ($r['tecnico_realname'] ?? '')) ?: ($r['tecnico_user'] ?? '');
        $rows[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'status'    => (int)$r['status'],
            'date'      => $r['date'] ? substr($r['date'], 0, 10) : '',
            'closedate' => $r['closedate'] ? substr($r['closedate'], 0, 10) : '',
            'tecnico'   => $tec,
        ];
    }
    echo json_encode(['ok' => true, 'chamados' => $rows]);
    exit;
}

if ($action === 'link_chamado') {
    header('Content-Type: application/json; charset=utf-8');
    $id  = (int)($_POST['id'] ?? 0);
    $tid = (int)($_POST['ticket_id'] ?? 0);
    if (!$id || !$tid) { echo json_encode(['ok' => false, 'erro' => 'Informe o nº do chamado']); exit; }
    $tk = $pdo->prepare("SELECT id FROM glpi_tickets WHERE id = ? AND is_deleted = 0");
    $tk->execute([$tid]);
    if (!$tk->fetchColumn()) { echo json_encode(['ok' => false, 'erro' => "Chamado #$tid não existe"]); exit; }
    $ex = $pdo->prepare("SELECT id FROM glpi_items_tickets WHERE itemtype = ? AND items_id = ? AND tickets_id = ?");
    $ex->execute([$itemtype, $id, $tid]);
    if (!$ex->fetchColumn()) {
        $pdo->prepare("INSERT INTO glpi_items_tickets (itemtype, items_id, tickets_id) VALUES (?,?,?)")
            ->execute([$itemtype, $id, $tid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unlink_chamado') {
    header('Content-Type: application/json; charset=utf-8');
    $id  = (int)($_POST['id'] ?? 0);
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $pdo->prepare("DELETE FROM glpi_items_tickets WHERE itemtype = ? AND items_id = ? AND tickets_id = ?")
        ->execute([$itemtype, $id, $tid]);
    echo json_encode(['ok' => true]);
    exit;
}

/* ─────────────────────── PÁGINA ─────────────────────── */

// resolve tipos que ainda não têm id no GLPI (uma vez; tolera falha)
if (array_filter($subcats, fn($s) => $s['glpi_type_id'] === null)) {
    try {
        $tk = glpi_session();
        if ($tk) { inv_sync_types($pdo, $card, $tk); glpi_kill($tk); $subcats = inv_subcats($pdo, (int)$card['id']); }
    } catch (Throwable $e) { /* segue sem os tipos */ }
}

$sub_filtro  = (int)($_GET['sub'] ?? 0);
$loja_filtro = (int)($_GET['loja'] ?? 0);
$busca       = trim($_GET['busca'] ?? '');
$view        = ($_GET['view'] ?? 'ativos') === 'baixados' ? 'baixados' : 'ativos';

$typeIds = array_values(array_filter(array_map(fn($s) => (int)$s['glpi_type_id'], $subcats)));
$aplicaTipo = ($fonte === 'peripheral') || count($subcats) > 1;

$where  = ["p.is_deleted = 0", "p.is_template = 0"];
$params = [];
if ($fonte === 'peripheral') $where[] = "p.is_dynamic = 0";
$where[] = $view === 'baixados' ? "bx.id IS NOT NULL" : "bx.id IS NULL";

if ($aplicaTipo) {
    if (!$typeIds) $where[] = "1 = 0";
    else {
        $ph = [];
        foreach ($typeIds as $i => $tid) { $ph[] = ":t$i"; $params[":t$i"] = $tid; }
        $where[] = "p.$colType IN (" . implode(',', $ph) . ")";
    }
}
if ($sub_filtro) {
    foreach ($subcats as $s) if ((int)$s['id'] === $sub_filtro && $s['glpi_type_id']) {
        $where[] = "p.$colType = :subf"; $params[':subf'] = (int)$s['glpi_type_id'];
    }
}
if ($loja_filtro) { $where[] = "p.entities_id = :loja"; $params[':loja'] = $loja_filtro; }
if ($busca !== '') {
    $where[] = "(p.name LIKE :b OR p.serial LIKE :b OR p.otherserial LIKE :b OR p.contact LIKE :b)";
    $params[':b'] = '%' . $busca . '%';
}
$W = implode(' AND ', $where);
$JOIN = "FROM `$tbl` p
         LEFT JOIN portal_inv_baixas bx ON bx.itemtype = " . $pdo->quote($itemtype) . " AND bx.items_id = p.id
         LEFT JOIN glpi_entities e      ON e.id  = p.entities_id
         LEFT JOIN glpi_manufacturers m ON m.id  = p.manufacturers_id
         LEFT JOIN `$tblModel` md       ON md.id = p.$colModel
         LEFT JOIN `$tblType` t         ON t.id  = p.$colType";

$sql = "SELECT p.id, p.name, p.serial, p.otherserial, p.contact, p.entities_id,
               p.$colType AS type_id, e.completename AS entidade,
               m.name AS fabricante, md.name AS modelo, t.name AS subcategoria, p.date_mod,
               bx.motivo AS baixa_motivo, bx.observacao AS baixa_obs,
               bx.baixado_em AS baixa_data, bx.baixado_por AS baixa_por
        $JOIN
        WHERE $W
        ORDER BY e.completename, p.name";
$st = $pdo->prepare($sql);
$st->execute($params);
$ativos = $st->fetchAll();

$valsAll = $fieldsLista ? inv_values_bulk($pdo, $itemtype, array_column($ativos, 'id')) : [];

// contagens por subcategoria e por loja — dentro da mesma view (ativos/baixados), sem os filtros sub/loja/busca
$baseWhere = array_filter($where, fn($c) => !str_contains($c, ':subf') && !str_contains($c, ':loja') && !str_contains($c, ':b'));
$baseW = implode(' AND ', $baseWhere);
$baseP = array_filter($params, fn($k) => !in_array($k, [':subf', ':loja', ':b'], true), ARRAY_FILTER_USE_KEY);

function inv_count(PDO $pdo, string $join, string $baseW, array $baseP, string $groupCol): array {
    $st = $pdo->prepare("SELECT p.$groupCol AS k, COUNT(*) n $join WHERE $baseW GROUP BY p.$groupCol");
    $st->execute($baseP);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$cntPorTipo = inv_count($pdo, $JOIN, $baseW, $baseP, $colType);
$cntPorLoja = inv_count($pdo, $JOIN, $baseW, $baseP, 'entities_id');
$totalGeral = array_sum($cntPorTipo);

// total da outra view (pro badge do toggle)
$wOutra = array_map(fn($c) => str_contains($c, 'bx.id IS') ? ($view === 'baixados' ? 'bx.id IS NULL' : 'bx.id IS NOT NULL') : $c, $baseWhere);
$stO = $pdo->prepare("SELECT COUNT(*) $JOIN WHERE " . implode(' AND ', $wOutra));
$stO->execute($baseP);
$totalOutra = (int)$stO->fetchColumn();
$totalAtivos   = $view === 'ativos'   ? $totalGeral : $totalOutra;
$totalBaixados = $view === 'baixados' ? $totalGeral : $totalOutra;

$entidades = $pdo->query("SELECT id, completename FROM glpi_entities ORDER BY completename")->fetchAll();
$GLPI_BASE = '/glpi2/front/';
$H = 'inv_h';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= $H($card['titulo']) ?> — Inventário</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:#1a237e; color:#fff; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; font-size:1.05rem; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.85rem; display:flex; align-items:center; gap:.35rem; padding:.3rem .7rem; border-radius:6px; }
    .topbar a:hover { background:rgba(255,255,255,.15); color:#fff; }
    .wrap { max-width:1120px; margin:1.5rem auto 3rem; padding:0 1.5rem; }
    .head { display:flex; align-items:center; gap:1rem; margin-bottom:1rem; }
    .head .ic { width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:#fff; flex-shrink:0; }
    .head h1 { font-size:1.4rem; font-weight:800; margin:0; color:#1a237e; }
    .head p  { margin:0; color:#5f6368; font-size:.85rem; }
    .head .add { margin-left:auto; display:flex; gap:.5rem; }
    .btn-add { background:#1a237e; color:#fff; border:none; border-radius:9px; padding:.5rem 1rem; font-size:.85rem; display:inline-flex; align-items:center; gap:.45rem; cursor:pointer; text-decoration:none; }
    .tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.6rem; }
    .tab { border:1px solid #d5dae2; background:#fff; border-radius:20px; padding:.3rem .85rem; font-size:.8rem; color:#3c4043; text-decoration:none; }
    .tab:hover { border-color:#1a237e; color:#1a237e; }
    .tab.active { background:#1a237e; color:#fff; border-color:#1a237e; }
    .tab .n { opacity:.6; margin-left:.35rem; }
    .filtros { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin-bottom:1rem; }
    .chip { border:1px solid #e0e4ea; background:#fff; border-radius:16px; padding:.25rem .7rem; font-size:.78rem; color:#5f6368; text-decoration:none; }
    .chip.active { background:#e8eaf6; color:#1a237e; border-color:#c5cae9; font-weight:600; }
    button.chip { font:inherit; cursor:pointer; }
    form.busca { margin-left:auto; }
    form.busca input { border:1px solid #d5dae2; border-radius:8px; padding:.35rem .7rem; font-size:.85rem; min-width:210px; }
    table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    th, td { padding:.6rem .85rem; text-align:left; font-size:.85rem; border-bottom:1px solid #eef1f5; }
    th { background:#f6f8fb; font-weight:700; color:#3c4043; font-size:.76rem; text-transform:uppercase; letter-spacing:.4px; }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:#f9fbfd; }
    td .sub { color:#80868b; font-size:.76rem; }
    .row-act { display:flex; gap:.3rem; }
    .row-act button { border:1px solid #e0e4ea; background:#fff; border-radius:7px; padding:.2rem .45rem; cursor:pointer; color:#5f6368; font-size:.8rem; }
    .row-act button:hover { border-color:#1a237e; color:#1a237e; }
    .row-act .del:hover { border-color:#c62828; color:#c62828; }
    .empty { background:#fff; border-radius:12px; padding:3rem 1.5rem; text-align:center; color:#5f6368; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    .empty i { font-size:2.4rem; color:#c5cbd3; display:block; margin-bottom:.6rem; }
    .grp { margin-bottom:.6rem; }
    .grp-hd { background:#1a237e; color:#fff; border-radius:10px; padding:.6rem 1.1rem; font-weight:700; font-size:.9rem; display:flex; align-items:center; justify-content:space-between; cursor:pointer; user-select:none; }
    .grp-hd:hover { filter:brightness(1.12); }
    .grp-n { font-weight:600; opacity:.9; font-size:.82rem; display:flex; align-items:center; gap:.4rem; }
    .grp-hd .chev { transition:transform .2s; }
    .grp.open .grp-hd { border-radius:10px 10px 0 0; }
    .grp.open .grp-hd .chev { transform:rotate(180deg); }
    .grp-bd { display:none; }
    .grp.open .grp-bd { display:block; }
    .grp-bd table { border-radius:0 0 10px 10px; box-shadow:none; border:1px solid #e5e7eb; border-top:none; }
    .modal-back { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
    .modal-back.show { display:flex; align-items:flex-start; justify-content:center; padding:3rem 1rem; overflow-y:auto; }
    .modal-card { background:#fff; border-radius:14px; width:560px; max-width:100%; box-shadow:0 12px 50px rgba(0,0,0,.3); }
    .modal-card header { padding:1rem 1.25rem; border-bottom:2px solid #1a237e; display:flex; align-items:center; justify-content:space-between; }
    .modal-card header h3 { margin:0; font-size:1.05rem; color:#1a237e; }
    .modal-card header button { border:none; background:none; font-size:1.4rem; color:#999; cursor:pointer; line-height:1; }
    .modal-body { padding:1.1rem 1.25rem; display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
    .modal-body .full { grid-column:1/-1; }
    .fld label { display:block; font-size:.78rem; font-weight:600; color:#5f6368; margin-bottom:.25rem; }
    .fld input, .fld select, .fld textarea { width:100%; border:1px solid #d5dae2; border-radius:8px; padding:.4rem .6rem; font-size:.85rem; }
    .modal-card footer { padding:.9rem 1.25rem; border-top:1px solid #eef1f5; display:flex; gap:.5rem; justify-content:flex-end; }
    .btn { border:none; border-radius:8px; padding:.5rem 1rem; font-size:.85rem; cursor:pointer; }
    .btn-primary { background:#1a237e; color:#fff; }
    .btn-ghost { background:#f1f3f4; color:#3c4043; }
    #msg { position:fixed; bottom:1.2rem; right:1.2rem; z-index:1100; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-box-seam"></i> Inventário</div>
  <span class="spacer"></span>
  <a href="inventario.php"><i class="bi bi-grid-3x3-gap"></i> Categorias</a>
  <a href="inventario_admin.php"><i class="bi bi-gear"></i> Configurar</a>
  <a href="dashboard.php"><i class="bi bi-house"></i> Início</a>
</div>

<div class="wrap">
  <div class="head">
    <div class="ic" style="background:<?= $H($card['cor']) ?>"><i class="bi <?= $H($card['icone']) ?>"></i></div>
    <div>
      <h1><?= $H($card['titulo']) ?></h1>
      <p><?= $totalAtivos ?> em uso · <?= $totalBaixados ?> baixado<?= $totalBaixados == 1 ? '' : 's' ?></p>
    </div>
    <div class="add">
      <a class="btn-add" style="background:#455a64" href="inventario_relatorio.php?cat=<?= $H($slug) ?><?= $view === 'baixados' ? '&view=baixados' : '' ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
      <?php if ($view === 'ativos'): ?><button class="btn-add" onclick="abrirModal(0)"><i class="bi bi-plus-lg"></i> Novo</button><?php endif; ?>
    </div>
  </div>

  <div class="tabs" style="margin-bottom:1rem">
    <a class="tab <?= $view === 'ativos' ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>">
      <i class="bi bi-check-circle"></i> Em uso<span class="n"><?= $totalAtivos ?></span>
    </a>
    <a class="tab <?= $view === 'baixados' ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>&view=baixados">
      <i class="bi bi-archive"></i> Baixados<span class="n"><?= $totalBaixados ?></span>
    </a>
  </div>

  <?php $qsView = $view === 'baixados' ? '&view=baixados' : ''; ?>

  <?php if (count($subcats) > 1): ?>
  <div class="tabs">
    <a class="tab <?= !$sub_filtro ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?><?= $qsView ?>">Todas<span class="n"><?= $totalGeral ?></span></a>
    <?php foreach ($subcats as $s): ?>
      <a class="tab <?= $sub_filtro == $s['id'] ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>&sub=<?= (int)$s['id'] ?><?= $qsView ?>">
        <?= $H($s['nome']) ?><span class="n"><?= (int)($cntPorTipo[$s['glpi_type_id']] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="filtros">
    <?php if ($loja_filtro): ?>
      <a class="chip" href="?cat=<?= $H($slug) ?><?= $sub_filtro ? '&sub='.$sub_filtro : '' ?><?= $qsView ?>">Todas as lojas</a>
    <?php else: ?>
      <button type="button" class="chip active" onclick="toggleTodosGrupos()" title="Expandir / recolher todas as lojas">Todas as lojas <i class="bi bi-arrows-expand"></i></button>
    <?php endif; ?>
    <?php foreach ($cntPorLoja as $eid => $n): if (!$eid) continue;
        $enome = ''; foreach ($entidades as $e) if ((int)$e['id'] === (int)$eid) $enome = $e['completename']; ?>
      <a class="chip <?= $loja_filtro == $eid ? 'active' : '' ?>"
         href="?cat=<?= $H($slug) ?><?= $sub_filtro ? '&sub='.$sub_filtro : '' ?>&loja=<?= (int)$eid ?><?= $qsView ?>">
        <?= $H(apelido_entidade($enome)) ?> <?= (int)$n ?>
      </a>
    <?php endforeach; ?>
    <form class="busca" method="get">
      <input type="hidden" name="cat" value="<?= $H($slug) ?>"/>
      <?php if ($view === 'baixados'): ?><input type="hidden" name="view" value="baixados"/><?php endif; ?>
      <?php if ($sub_filtro): ?><input type="hidden" name="sub" value="<?= $sub_filtro ?>"/><?php endif; ?>
      <?php if ($loja_filtro): ?><input type="hidden" name="loja" value="<?= $loja_filtro ?>"/><?php endif; ?>
      <input type="text" name="busca" value="<?= $H($busca) ?>" placeholder="Nome, série, patrimônio..."/>
    </form>
  </div>

  <?php
  $MOT = ['quebrado'=>'Quebrado','vendido'=>'Vendido','descartado'=>'Descartado','outro'=>'Outro'];

  $tabela = function(array $rows, bool $showLoja) use ($view, $fonte, $MOT, $H, $GLPI_BASE, $glpiForm, $fieldsLista, $valsAll) {
    // Celulares: layout próprio — sem Subcategoria/Patrimônio, Fabricante e Modelo
    // em colunas separadas, campos personalizados (Cargo, Departamento) logo após o Nome.
    $isPhone = ($fonte === 'phone');
    ?>
    <table>
      <thead><tr>
        <th>Nome</th>
        <?php if ($showLoja): ?><th>Loja</th><?php endif; ?>
        <?php if (!$isPhone): ?><th>Subcategoria</th><?php endif; ?>
        <?php if ($isPhone): ?>
          <?php foreach ($fieldsLista as $f): ?><th><?= $H($f['label']) ?></th><?php endforeach; ?>
          <th>Fabricante</th><th>Modelo</th>
        <?php else: ?>
          <th>Fabricante / Modelo</th>
          <?php foreach ($fieldsLista as $f): ?><th><?= $H($f['label']) ?></th><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($view === 'baixados'): ?>
          <th>Motivo</th><th>Baixa</th>
        <?php else: ?>
          <th><?= $isPhone ? 'Nº linha' : 'Série' ?></th>
          <?php if (!$isPhone): ?><th>Patrimônio</th><?php endif; ?>
        <?php endif; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $a):
        $campoTds = '';
        foreach ($fieldsLista as $f) {
            $v = $valsAll[(int)$a['id']][(int)$f['id']] ?? '';
            if ($f['tipo'] === 'checkbox') $v = ($v === '1' || $v === 1) ? 'Sim' : ($v === '' ? '' : 'Não');
            $campoTds .= '<td>' . $H($v !== '' ? $v : '—') . '</td>';
        }
      ?>
        <tr>
          <td><?= $H($a['name'] ?: '(sem nome)') ?><?php if ($a['contact']): ?><div class="sub"><?= $H($a['contact']) ?></div><?php endif; ?></td>
          <?php if ($showLoja): ?><td><?= $H(apelido_entidade($a['entidade'] ?? '—')) ?></td><?php endif; ?>
          <?php if (!$isPhone): ?><td><?= $H($a['subcategoria'] ?: '—') ?></td><?php endif; ?>
          <?php if ($isPhone): ?>
            <?= $campoTds ?>
            <td><?= $H($a['fabricante'] ?: '—') ?></td>
            <td><?= $H($a['modelo'] ?: '—') ?></td>
          <?php else: ?>
            <td><?= $H($a['fabricante'] ?: '—') ?><?php if ($a['modelo']): ?><div class="sub"><?= $H($a['modelo']) ?></div><?php endif; ?></td>
            <?= $campoTds ?>
          <?php endif; ?>
          <?php if ($view === 'baixados'): ?>
            <td><?= $H($MOT[$a['baixa_motivo']] ?? $a['baixa_motivo']) ?><?php if ($a['baixa_obs']): ?><div class="sub"><?= $H($a['baixa_obs']) ?></div><?php endif; ?></td>
            <td><?= $H($a['baixa_data'] ?: '—') ?><?php if ($a['baixa_por']): ?><div class="sub"><?= $H($a['baixa_por']) ?></div><?php endif; ?></td>
          <?php else: ?>
            <td><?= $H($a['serial'] ?: '—') ?></td>
            <?php if (!$isPhone): ?><td><?= $H($a['otherserial'] ?: '—') ?></td><?php endif; ?>
          <?php endif; ?>
          <td><div class="row-act">
            <?php if ($view === 'baixados'): ?>
              <button title="Reativar" onclick="reativar(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-arrow-counterclockwise"></i></button>
            <?php else: ?>
              <button title="Editar" onclick="abrirModal(<?= (int)$a['id'] ?>)"><i class="bi bi-pencil"></i></button>
              <button title="Dar baixa" onclick="abrirBaixa(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-box-arrow-in-down"></i></button>
            <?php endif; ?>
            <button class="del" title="Excluir" onclick="excluir(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-trash3"></i></button>
            <a class="chip" href="<?= $GLPI_BASE . $glpiForm ?>?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" title="Abrir no GLPI"><i class="bi bi-box-arrow-up-right"></i></a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php };
  ?>

  <?php if (!$ativos): ?>
    <div class="empty">
      <i class="bi bi-inbox"></i>
      <?php if ($busca || $loja_filtro || $sub_filtro): ?>
        Nenhum item com esse filtro.
      <?php elseif ($view === 'baixados'): ?>
        Nenhum item baixado. Itens quebrados, vendidos ou descartados aparecem aqui.
      <?php else: ?>
        Nenhum <?= $H($card['titulo']) ?> cadastrado ainda. Clique em <strong>Novo</strong>.
      <?php endif; ?>
    </div>
  <?php elseif ($loja_filtro): ?>
    <?php $tabela($ativos, false); ?>
  <?php else: ?>
    <?php
      $grupos = [];
      foreach ($ativos as $a) {
          $nome = apelido_entidade($a['entidade'] ?? '') ?: 'Sem loja';
          $grupos[$nome][] = $a;
      }
      ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <?php foreach ($grupos as $lojaNome => $rows): ?>
      <div class="grp">
        <div class="grp-hd" onclick="this.parentElement.classList.toggle('open')">
          <span><i class="bi bi-shop"></i> <?= $H($lojaNome) ?></span>
          <span class="grp-n"><?= count($rows) ?> <i class="bi bi-chevron-down chev"></i></span>
        </div>
        <div class="grp-bd"><?php $tabela($rows, false); ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal-back" id="modalBack">
  <div class="modal-card">
    <header>
      <h3 id="modalTitulo">Novo ativo</h3>
      <button onclick="fecharModal()">&times;</button>
    </header>
    <div class="modal-body">
      <input type="hidden" id="f-id"/>
      <div class="fld full"><label>Nome *</label><input type="text" id="f-name"/></div>
      <div class="fld"><label>Loja</label>
        <select id="f-entidade">
          <option value="0">—</option>
          <?php foreach ($entidades as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= $H(apelido_entidade($e['completename'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld"><label>Subcategoria</label>
        <select id="f-subcat">
          <option value="0">—</option>
          <?php foreach ($subcats as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= $H($s['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld"><label>Fabricante</label><input type="text" id="f-fabricante" list="dl-fab"/></div>
      <div class="fld"><label>Modelo</label><input type="text" id="f-modelo"/></div>
      <div class="fld"><label><?= $fonte === 'phone' ? 'Nº da linha' : 'Nº de série' ?></label><input type="text" id="f-serial"<?= $fonte === 'phone' ? ' inputmode="numeric" maxlength="16" placeholder="(67) 99802-3041"' : '' ?>/></div>
      <div class="fld"><label>Patrimônio</label><input type="text" id="f-otherserial"/></div>
      <div class="fld full"><label>Observação</label><textarea id="f-comment" rows="2"></textarea></div>
      <?php foreach ($fields as $f): ?>
        <div class="fld <?= in_array($f['tipo'], ['textarea']) ? 'full' : '' ?>">
          <label><?= $H($f['label']) ?><?= $f['obrigatorio'] ? ' *' : '' ?></label>
          <?php if ($f['tipo'] === 'select'): ?>
            <select data-campo="<?= (int)$f['id'] ?>">
              <option value=""></option>
              <?php foreach (preg_split('/\r?\n/', (string)$f['opcoes'], -1, PREG_SPLIT_NO_EMPTY) as $op): ?>
                <option value="<?= $H(trim($op)) ?>"><?= $H(trim($op)) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($f['tipo'] === 'textarea'): ?>
            <textarea data-campo="<?= (int)$f['id'] ?>" rows="2"></textarea>
          <?php else: ?>
            <input type="<?= $H(in_array($f['tipo'], ['number','date']) ? $f['tipo'] : 'text') ?>" data-campo="<?= (int)$f['id'] ?>"/>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="fld full" id="box-chamados" style="display:none">
        <label><i class="bi bi-wrench-adjustable"></i> Manutenção / Chamados vinculados</label>
        <div id="chamados-lista" style="border:1px solid #e0e4ea;border-radius:8px;padding:.5rem .6rem;font-size:.82rem;max-height:180px;overflow-y:auto"></div>
        <div style="display:flex;gap:.4rem;margin-top:.4rem">
          <input type="number" id="f-novo-chamado" placeholder="Nº do chamado" style="flex:1"/>
          <button type="button" class="btn btn-ghost" onclick="vincularChamado()"><i class="bi bi-link-45deg"></i> Vincular</button>
        </div>
      </div>
    </div>
    <footer>
      <button class="btn btn-ghost" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-primary" id="btnSalvar" onclick="salvar()">Salvar</button>
    </footer>
  </div>
</div>
<!-- Modal de baixa -->
<div class="modal-back" id="baixaBack">
  <div class="modal-card" style="width:440px">
    <header>
      <h3>Dar baixa no equipamento</h3>
      <button onclick="fecharBaixa()">&times;</button>
    </header>
    <div class="modal-body" style="grid-template-columns:1fr">
      <input type="hidden" id="b-id"/>
      <p style="margin:0;color:#5f6368;font-size:.85rem">Marcar <strong id="b-nome"></strong> como fora de uso. Sai da lista "Em uso" e vai pra "Baixados" (dá pra reativar depois).</p>
      <div class="fld"><label>Motivo *</label>
        <select id="b-motivo">
          <option value="quebrado">Quebrado definitivamente</option>
          <option value="vendido">Vendido</option>
          <option value="descartado">Descartado</option>
          <option value="outro">Outro</option>
        </select>
      </div>
      <div class="fld"><label>Data da baixa</label><input type="date" id="b-data"/></div>
      <div class="fld"><label>Observação</label><textarea id="b-obs" rows="2" placeholder="Ex: tela trincada, sem conserto"></textarea></div>
    </div>
    <footer>
      <button class="btn btn-ghost" onclick="fecharBaixa()">Cancelar</button>
      <button class="btn btn-primary" id="btnBaixa" onclick="confirmarBaixa()">Dar baixa</button>
    </footer>
  </div>
</div>

<datalist id="dl-fab"></datalist>
<div id="msg"></div>

<script>
const CAT = <?= json_encode($slug) ?>;
const FONTE = <?= json_encode($fonte) ?>;
const $ = s => document.querySelector(s);

function formatFoneBR(v) {
  const d = (v || '').replace(/\D/g, '').slice(0, 11);
  if (!d) return '';
  if (d.length <= 2)  return '(' + d;
  if (d.length <= 6)  return '(' + d.slice(0,2) + ') ' + d.slice(2);
  if (d.length <= 10) return '(' + d.slice(0,2) + ') ' + d.slice(2,6) + '-' + d.slice(6);
  return '(' + d.slice(0,2) + ') ' + d.slice(2,7) + '-' + d.slice(7);
}
if (FONTE === 'phone') {
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('f-serial');
    if (el) el.addEventListener('input', () => { el.value = formatFoneBR(el.value); });
  });
}

function toast(txt, ok = true) {
  const d = document.createElement('div');
  d.className = 'alert alert-' + (ok ? 'success' : 'danger') + ' py-2 px-3 mb-2 shadow-sm';
  d.textContent = txt;
  $('#msg').appendChild(d);
  setTimeout(() => d.remove(), 4000);
}

function abrirModal(id) {
  limparForm();
  $('#modalTitulo').textContent = id ? 'Editar ativo' : 'Novo ativo';
  $('#f-id').value = id || '';
  if (id) { $('#box-chamados').style.display = ''; carregarChamados(id); }
  else    { $('#box-chamados').style.display = 'none'; $('#chamados-lista').innerHTML = ''; }
  if (id) {
    fetch(`inventario_glpi.php?cat=${CAT}&action=form&id=${id}`)
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { toast(d.erro || 'Erro ao carregar', false); return; }
        const it = d.item;
        $('#f-name').value = it.name || '';
        $('#f-entidade').value = it.entities_id || 0;
        $('#f-subcat').value = it.subcat_id || 0;
        $('#f-fabricante').value = it.fabricante || '';
        $('#f-modelo').value = it.modelo || '';
        $('#f-serial').value = FONTE === 'phone' ? formatFoneBR(it.serial || '') : (it.serial || '');
        $('#f-otherserial').value = it.otherserial || '';
        $('#f-comment').value = it.comment || '';
        document.querySelectorAll('[data-campo]').forEach(el => {
          el.value = (it.valores && it.valores[el.dataset.campo]) || '';
        });
      });
  }
  $('#modalBack').classList.add('show');
}
function fecharModal() { $('#modalBack').classList.remove('show'); }
function limparForm() {
  ['f-name','f-fabricante','f-modelo','f-serial','f-otherserial','f-comment','f-novo-chamado'].forEach(i => $('#'+i).value = '');
  $('#f-entidade').value = 0; $('#f-subcat').value = 0;
  document.querySelectorAll('[data-campo]').forEach(el => el.value = '');
}

function escHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

const TK_STATUS = {1:['Novo','#1a73e8'],2:['Em atendimento','#e8710a'],3:['Planejado','#8e24aa'],4:['Pendente','#c62828'],5:['Solucionado','#1e8e3e'],6:['Fechado','#5f6368']};

function carregarChamados(id) {
  const box = $('#chamados-lista');
  box.innerHTML = '<span style="color:#9aa0a6">Carregando…</span>';
  fetch(`inventario_glpi.php?cat=${CAT}&action=chamados&id=${id}`)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { box.innerHTML = '<span style="color:#c62828">Erro ao carregar</span>'; return; }
      if (!d.chamados.length) { box.innerHTML = '<span style="color:#9aa0a6">Nenhum chamado vinculado a este equipamento.</span>'; return; }
      box.innerHTML = d.chamados.map(c => {
        const st = TK_STATUS[c.status] || ['?', '#9aa0a6'];
        const tec = c.tecnico ? `<span style="font-size:.72rem;color:#9aa0a6;white-space:nowrap">${escHtml(c.tecnico)}</span>` : '';
        return `<div style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0;border-bottom:1px dashed #eef1f5">
          <a href="chamado.php?id=${c.id}" target="_blank" style="font-weight:700;color:#1a237e;text-decoration:none">#${c.id}</a>
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(c.name)}</span>
          <span style="font-size:.7rem;background:${st[1]}22;color:${st[1]};border-radius:10px;padding:.1rem .5rem;white-space:nowrap">${st[0]}</span>
          <span style="font-size:.72rem;color:#9aa0a6;white-space:nowrap">${c.date || ''}</span>
          ${tec}
          <button type="button" title="Desvincular" onclick="desvincularChamado(${c.id})" style="border:none;background:none;color:#c62828;cursor:pointer;font-size:1.1rem;line-height:1">&times;</button>
        </div>`;
      }).join('');
    })
    .catch(() => { box.innerHTML = '<span style="color:#c62828">Erro de rede</span>'; });
}

function vincularChamado() {
  const id = $('#f-id').value;
  const tid = parseInt($('#f-novo-chamado').value || 0);
  if (!id) { toast('Salve o equipamento primeiro', false); return; }
  if (!tid) { toast('Informe o nº do chamado', false); return; }
  const fd = new FormData();
  fd.set('action', 'link_chamado'); fd.set('id', id); fd.set('ticket_id', tid);
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { toast(d.erro || 'Erro ao vincular', false); return; }
      $('#f-novo-chamado').value = '';
      carregarChamados(id);
      toast('Chamado vinculado');
    })
    .catch(e => toast('Erro de rede: ' + e.message, false));
}

function desvincularChamado(tid) {
  const id = $('#f-id').value;
  if (!confirm(`Desvincular o chamado #${tid} deste equipamento?\n\nO chamado no GLPI continua existindo — só o vínculo é removido.`)) return;
  const fd = new FormData();
  fd.set('action', 'unlink_chamado'); fd.set('id', id); fd.set('ticket_id', tid);
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.ok) carregarChamados(id); else toast('Erro ao desvincular', false); });
}

function salvar() {
  const fd = new FormData();
  fd.set('action', 'save');
  fd.set('id', $('#f-id').value || 0);
  fd.set('name', $('#f-name').value.trim());
  fd.set('entities_id', $('#f-entidade').value);
  fd.set('subcat_id', $('#f-subcat').value);
  fd.set('fabricante', $('#f-fabricante').value.trim());
  fd.set('modelo', $('#f-modelo').value.trim());
  fd.set('serial', $('#f-serial').value.trim());
  fd.set('otherserial', $('#f-otherserial').value.trim());
  fd.set('comment', $('#f-comment').value.trim());
  document.querySelectorAll('[data-campo]').forEach(el => fd.set('campo_' + el.dataset.campo, el.value));

  if (!fd.get('name')) { toast('Nome é obrigatório', false); return; }
  $('#btnSalvar').disabled = true;
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) { toast('Salvo'); setTimeout(() => location.reload(), 600); }
      else { toast(d.erro || 'Erro ao salvar', false); $('#btnSalvar').disabled = false; }
    })
    .catch(e => { toast('Erro de rede: ' + e.message, false); $('#btnSalvar').disabled = false; });
}

function excluir(id, nome) {
  if (!confirm(`Excluir "${nome}"?\n\nVai para a lixeira do GLPI (dá pra restaurar).`)) return;
  const fd = new FormData();
  fd.set('action', 'delete');
  fd.set('id', id);
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.ok) { toast('Excluído'); setTimeout(() => location.reload(), 600); } else toast('Erro ao excluir', false); });
}

function abrirBaixa(id, nome) {
  $('#b-id').value = id;
  $('#b-nome').textContent = nome;
  $('#b-motivo').value = 'quebrado';
  $('#b-data').value = new Date().toISOString().slice(0, 10);
  $('#b-obs').value = '';
  $('#baixaBack').classList.add('show');
}
function fecharBaixa() { $('#baixaBack').classList.remove('show'); }
function confirmarBaixa() {
  const fd = new FormData();
  fd.set('action', 'baixa');
  fd.set('id', $('#b-id').value);
  fd.set('motivo', $('#b-motivo').value);
  fd.set('baixado_em', $('#b-data').value);
  fd.set('observacao', $('#b-obs').value.trim());
  $('#btnBaixa').disabled = true;
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.ok) { toast('Baixa registrada'); setTimeout(() => location.reload(), 600); } else { toast('Erro', false); $('#btnBaixa').disabled = false; } });
}
function reativar(id, nome) {
  if (!confirm(`Reativar "${nome}"?\n\nVolta pra lista "Em uso".`)) return;
  const fd = new FormData();
  fd.set('action', 'reativar');
  fd.set('id', id);
  fetch(`inventario_glpi.php?cat=${CAT}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.ok) { toast('Reativado'); setTimeout(() => location.reload(), 600); } });
}

function toggleTodosGrupos() {
  const grps = document.querySelectorAll('.grp');
  if (!grps.length) return;
  const algumFechado = [...grps].some(g => !g.classList.contains('open'));
  grps.forEach(g => g.classList.toggle('open', algumFechado));
}

$('#modalBack').addEventListener('click', e => { if (e.target === $('#modalBack')) fecharModal(); });
$('#baixaBack').addEventListener('click', e => { if (e.target === $('#baixaBack')) fecharBaixa(); });
</script>
</body>
</html>
