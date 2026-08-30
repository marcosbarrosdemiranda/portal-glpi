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
if (!$card || $card['fonte'] !== 'computer') { header('Location: inventario.php'); exit; }

$H = 'inv_h';
$MOT = ['quebrado'=>'Quebrado','vendido'=>'Vendido','descartado'=>'Descartado','outro'=>'Outro'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$por = $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';

/* ─────────── AÇÕES (JSON) ─────────── */
if ($action === 'set_cat') {
    header('Content-Type: application/json');
    inv_pc_set_categoria($pdo, (int)($_POST['id'] ?? 0), $_POST['categoria'] ?? 'pcs-retaguarda', $por);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'set_srv') {
    header('Content-Type: application/json');
    $hid = (int)($_POST['host_id'] ?? 0);
    inv_srv_set($pdo, (int)($_POST['id'] ?? 0), $_POST['papel'] ?? 'fisico', $hid ?: null);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'form') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT c.*, m.name AS fabricante_nome, md.name AS modelo_nome
                         FROM glpi_computers c
                         LEFT JOIN glpi_manufacturers m ON m.id = c.manufacturers_id
                         LEFT JOIN glpi_computermodels md ON md.id = c.computermodels_id
                         WHERE c.id = ?");
    $st->execute([$id]);
    $it = $st->fetch();
    if (!$it) { echo json_encode(['ok' => false, 'erro' => 'Não encontrado']); exit; }
    $srv = $pdo->prepare("SELECT papel, host_id FROM portal_inv_servidores WHERE computer_id = ?");
    $srv->execute([$id]);
    $sr = $srv->fetch() ?: ['papel' => 'fisico', 'host_id' => null];
    echo json_encode(['ok' => true, 'item' => [
        'id' => (int)$it['id'], 'name' => $it['name'], 'entities_id' => (int)$it['entities_id'],
        'categoria' => inv_pc_categoria($pdo, $id),
        'papel' => $sr['papel'], 'host_id' => (int)($sr['host_id'] ?? 0),
        'fabricante' => $it['fabricante_nome'] ?? '', 'modelo' => $it['modelo_nome'] ?? '',
        'serial' => $it['serial'] ?? '', 'otherserial' => $it['otherserial'] ?? '', 'comment' => $it['comment'] ?? '',
    ]]);
    exit;
}
if ($action === 'save') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['name'] ?? '');
    if ($nome === '') { echo json_encode(['ok' => false, 'erro' => 'Nome é obrigatório']); exit; }
    $token = glpi_session();
    if (!$token) { echo json_encode(['ok' => false, 'erro' => 'Falha ao autenticar no GLPI']); exit; }
    try {
        $manId = glpi_ensure_dropdown($pdo, 'glpi_manufacturers', 'Manufacturer', $_POST['fabricante'] ?? '', $token);
        $mdId  = glpi_ensure_dropdown($pdo, 'glpi_computermodels', 'ComputerModel', $_POST['modelo'] ?? '', $token);
        $input = [
            'name' => $nome, 'entities_id' => (int)($_POST['entities_id'] ?? 0),
            'manufacturers_id' => $manId ?: 0, 'computermodels_id' => $mdId ?: 0,
            'serial' => trim($_POST['serial'] ?? ''), 'otherserial' => trim($_POST['otherserial'] ?? ''),
            'comment' => trim($_POST['comment'] ?? ''),
        ];
        if ($id) { [$code] = glpi_call('PUT', "Computer/$id", ['input' => $input], $token); }
        else { [$code, $resp] = glpi_call('POST', 'Computer', ['input' => $input], $token); $id = (int)($resp['id'] ?? $resp[0]['id'] ?? 0); }
        if ($code < 200 || $code >= 300 || !$id) { echo json_encode(['ok' => false, 'erro' => 'GLPI recusou (HTTP ' . $code . ')']); exit; }
        inv_pc_set_categoria($pdo, $id, $_POST['categoria'] ?? $slug, $por);
        if (($_POST['categoria'] ?? $slug) === 'maquinas-virtuais' || isset($_POST['papel'])) {
            inv_srv_set($pdo, $id, $_POST['papel'] ?? 'fisico', (int)($_POST['host_id'] ?? 0) ?: null);
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    } finally { glpi_kill($token); }
    exit;
}
if ($action === 'delete') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $token = glpi_session();
    if (!$token) { echo json_encode(['ok' => false, 'erro' => 'Falha no GLPI']); exit; }
    [$code] = glpi_call('DELETE', "Computer/$id", null, $token);
    glpi_kill($token);
    inv_baixa_clear($pdo, 'Computer', $id);
    echo json_encode(['ok' => ($code >= 200 && $code < 300)]);
    exit;
}
if ($action === 'baixa') {
    header('Content-Type: application/json');
    inv_baixa_set($pdo, 'Computer', (int)($_POST['id'] ?? 0), $_POST['motivo'] ?? 'quebrado',
                  trim($_POST['observacao'] ?? ''), $_POST['baixado_em'] ?? null, $por);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'reativar') {
    header('Content-Type: application/json');
    inv_baixa_clear($pdo, 'Computer', (int)($_POST['id'] ?? 0));
    echo json_encode(['ok' => true]); exit;
}

/* ─────────── PÁGINA ─────────── */
$ignMode     = isset($_GET['ignorados']);
$listaSlug   = $ignMode ? '__ignorado__' : $slug;
$view        = ($_GET['view'] ?? 'ativos') === 'baixados' ? 'baixados' : 'ativos';
$loja_filtro = (int)($_GET['loja'] ?? 0);
$busca       = mb_strtolower(trim($_GET['busca'] ?? ''));

$qtdIgnorados = count(inv_computers_do_card($pdo, '__ignorado__', 'ativos'));

$todos     = inv_computers_do_card($pdo, $listaSlug, $view);
$totalOutra = count(inv_computers_do_card($pdo, $listaSlug, $view === 'ativos' ? 'baixados' : 'ativos'));

$rows = array_values(array_filter($todos, function ($r) use ($loja_filtro, $busca) {
    if ($loja_filtro && (int)$r['entities_id'] !== $loja_filtro) return false;
    if ($busca !== '' && !str_contains(mb_strtolower($r['name'] . ' ' . $r['serial'] . ' ' . $r['otherserial']), $busca)) return false;
    return true;
}));

// Card de servidores: visão em árvore (servidor físico → VMs). Sempre carrega todos os
// físicos (mesmo os filtrados fora) pra montar o dropdown de host.
$IS_SRV = ($slug === 'maquinas-virtuais' && !$ignMode && $view === 'ativos');
$srvFisicos = [];
if ($IS_SRV) {
    $rows  = inv_srv_anota($pdo, $rows);
    $todos = inv_srv_anota($pdo, $todos);
    foreach ($todos as $r) if (($r['papel'] ?? 'fisico') === 'fisico') $srvFisicos[(int)$r['id']] = $r['name'];
}

$cntPorLoja = [];
foreach ($todos as $r) { $cntPorLoja[(int)$r['entities_id']] = ($cntPorLoja[(int)$r['entities_id']] ?? 0) + 1; }
$totalGeral = count($todos);
$totalAtivos   = $view === 'ativos'   ? $totalGeral : $totalOutra;
$totalBaixados = $view === 'baixados' ? $totalGeral : $totalOutra;

$entidades = $pdo->query("SELECT id, completename FROM glpi_entities ORDER BY completename")->fetchAll();
$entMap = [];
foreach ($entidades as $e) $entMap[(int)$e['id']] = $e['completename'];
$qsView = $view === 'baixados' ? '&view=baixados' : '';
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
    .topbar { background:#1a237e; color:#fff; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.85rem; padding:.3rem .7rem; border-radius:6px; }
    .topbar a:hover { background:rgba(255,255,255,.15); color:#fff; }
    .wrap { max-width:1120px; margin:1.5rem auto 3rem; padding:0 1.5rem; }
    .head { display:flex; align-items:center; gap:1rem; margin-bottom:1rem; }
    .head .ic { width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:#fff; }
    .head h1 { font-size:1.4rem; font-weight:800; margin:0; color:#1a237e; }
    .head p { margin:0; color:#5f6368; font-size:.85rem; }
    .head .add { margin-left:auto; display:flex; gap:.5rem; }
    .btn-add { background:#1a237e; color:#fff; border:none; border-radius:9px; padding:.5rem 1rem; font-size:.85rem; display:inline-flex; align-items:center; gap:.45rem; cursor:pointer; text-decoration:none; }
    .tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem; }
    .tab { border:1px solid #d5dae2; background:#fff; border-radius:20px; padding:.3rem .85rem; font-size:.8rem; color:#3c4043; text-decoration:none; }
    .tab.active { background:#1a237e; color:#fff; border-color:#1a237e; }
    .tab .n { opacity:.6; margin-left:.35rem; }
    button.tab { font:inherit; cursor:pointer; }
    .filtros { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin-bottom:1rem; }
    .chip { border:1px solid #e0e4ea; background:#fff; border-radius:16px; padding:.25rem .7rem; font-size:.78rem; color:#5f6368; text-decoration:none; }
    .chip.active { background:#e8eaf6; color:#1a237e; border-color:#c5cae9; font-weight:600; }
    button.chip { font:inherit; cursor:pointer; }
    form.busca { margin-left:auto; }
    form.busca input { border:1px solid #d5dae2; border-radius:8px; padding:.35rem .7rem; font-size:.85rem; min-width:210px; }
    table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    th, td { padding:.55rem .8rem; text-align:left; font-size:.85rem; border-bottom:1px solid #eef1f5; }
    th { background:#f6f8fb; font-weight:700; color:#3c4043; font-size:.76rem; text-transform:uppercase; letter-spacing:.4px; }
    tr:hover td { background:#f9fbfd; }
    td .sub { color:#80868b; font-size:.76rem; }
    td select.cat { border:1px solid #d5dae2; border-radius:7px; padding:.2rem .4rem; font-size:.8rem; }
    .row-act { display:flex; gap:.3rem; }
    .row-act button, .row-act a { border:1px solid #e0e4ea; background:#fff; border-radius:7px; padding:.2rem .45rem; cursor:pointer; color:#5f6368; font-size:.8rem; text-decoration:none; }
    .row-act .del:hover { border-color:#c62828; color:#c62828; }
    .empty { background:#fff; border-radius:12px; padding:3rem 1.5rem; text-align:center; color:#5f6368; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    .empty i { font-size:2.4rem; color:#c5cbd3; display:block; margin-bottom:.6rem; }
    .grp { margin-bottom:.6rem; }
    .grp-hd { background:#1a237e; color:#fff; border-radius:10px; padding:.6rem 1.1rem; font-weight:700; font-size:.9rem; display:flex; align-items:center; justify-content:space-between; cursor:pointer; user-select:none; }
    .grp-n { font-weight:600; opacity:.9; font-size:.82rem; display:flex; align-items:center; gap:.4rem; }
    .grp-hd .chev { transition:transform .2s; }
    .grp.open .grp-hd { border-radius:10px 10px 0 0; }
    .grp.open .grp-hd .chev { transform:rotate(180deg); }
    .grp-bd { display:none; }
    .grp.open .grp-bd { display:block; }
    .grp-bd table { border-radius:0 0 10px 10px; box-shadow:none; border:1px solid #e5e7eb; border-top:none; }
    .modal-back { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
    .modal-back.show { display:flex; align-items:flex-start; justify-content:center; padding:3rem 1rem; overflow-y:auto; }
    .modal-card { background:#fff; border-radius:14px; width:520px; max-width:100%; box-shadow:0 12px 50px rgba(0,0,0,.3); }
    .modal-card header { padding:1rem 1.25rem; border-bottom:2px solid #1a237e; display:flex; justify-content:space-between; align-items:center; }
    .modal-card header h3 { margin:0; font-size:1.05rem; color:#1a237e; }
    .modal-card header button { border:none; background:none; font-size:1.4rem; color:#999; cursor:pointer; }
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
    <div class="ic" style="background:<?= $ignMode ? '#9aa0a6' : $H($card['cor']) ?>"><i class="bi <?= $ignMode ? 'bi-slash-circle' : $H($card['icone']) ?>"></i></div>
    <div>
      <h1><?= $ignMode ? 'Ignorados' : $H($card['titulo']) ?></h1>
      <?php if ($ignMode): ?>
        <p>Máquinas fora do inventário (containers Docker, serviços). Mova pra uma categoria se for um PC de verdade.</p>
      <?php else: ?>
        <p><?= $totalAtivos ?> em uso · <?= $totalBaixados ?> baixado<?= $totalBaixados == 1 ? '' : 's' ?></p>
      <?php endif; ?>
    </div>
    <div class="add">
      <?php if ($ignMode): ?>
        <a class="btn-add" style="background:#455a64" href="inventario_pc.php?cat=<?= $H($slug) ?>"><i class="bi bi-arrow-left"></i> Voltar</a>
      <?php else: ?>
        <a class="btn-add" style="background:#455a64" href="inventario_relatorio.php?cat=<?= $H($slug) ?><?= $qsView ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <?php if ($view === 'ativos'): ?><button class="btn-add" onclick="abrirModal(0)"><i class="bi bi-plus-lg"></i> Novo</button><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$ignMode): ?>
  <div class="tabs">
    <a class="tab <?= $view === 'ativos' ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>"><i class="bi bi-check-circle"></i> Em uso<span class="n"><?= $totalAtivos ?></span></a>
    <a class="tab <?= $view === 'baixados' ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>&view=baixados"><i class="bi bi-archive"></i> Baixados<span class="n"><?= $totalBaixados ?></span></a>
    <?php if ($qtdIgnorados): ?>
      <a class="tab" href="?cat=<?= $H($slug) ?>&ignorados=1" style="margin-left:auto"><i class="bi bi-slash-circle"></i> Ignorados<span class="n"><?= $qtdIgnorados ?></span></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php $qsIgn = $ignMode ? '&ignorados=1' : ''; ?>
  <div class="filtros">
    <?php if ($loja_filtro): ?>
      <a class="chip" href="?cat=<?= $H($slug) ?><?= $qsView . $qsIgn ?>">Todas as lojas</a>
    <?php else: ?>
      <button type="button" class="chip active" onclick="toggleTodosGrupos()">Todas as lojas <i class="bi bi-arrows-expand"></i></button>
    <?php endif; ?>
    <?php foreach ($cntPorLoja as $eid => $n): if (!$eid) continue; ?>
      <a class="chip <?= $loja_filtro == $eid ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>&loja=<?= (int)$eid ?><?= $qsView . $qsIgn ?>">
        <?= $H(apelido_entidade($entMap[$eid] ?? '—')) ?> <?= (int)$n ?>
      </a>
    <?php endforeach; ?>
    <form class="busca" method="get">
      <input type="hidden" name="cat" value="<?= $H($slug) ?>"/>
      <?php if ($ignMode): ?><input type="hidden" name="ignorados" value="1"/><?php endif; ?>
      <?php if ($view === 'baixados'): ?><input type="hidden" name="view" value="baixados"/><?php endif; ?>
      <?php if ($loja_filtro): ?><input type="hidden" name="loja" value="<?= $loja_filtro ?>"/><?php endif; ?>
      <input type="text" name="busca" value="<?= $H($_GET['busca'] ?? '') ?>" placeholder="Nome, série, patrimônio..."/>
    </form>
  </div>

  <?php
  $catAtual = $slug;
  $tabela = function(array $list) use ($view, $H, $MOT, $catAtual) { ?>
    <table>
      <thead><tr>
        <th>Nome</th><th>Categoria</th><th>Tipo (HW)</th><th>Fabricante / Modelo</th>
        <?php if ($view === 'baixados'): ?><th>Motivo</th><th>Data</th><?php else: ?><th>Série</th><th>Patrimônio</th><?php endif; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($list as $a): $cat = $a['cat_salva'] ?: 'pcs-retaguarda'; ?>
        <tr>
          <td><?= $H($a['name'] ?: '(sem nome)') ?></td>
          <td>
            <?php if ($view === 'baixados'): ?>
              <?= $H(INV_PC_CATS[$cat] ?? $cat) ?>
            <?php else: ?>
              <select class="cat" onchange="mudarCat(<?= (int)$a['id'] ?>, this.value)">
                <?php foreach (INV_PC_CATS as $cv => $cl): ?>
                  <option value="<?= $cv ?>" <?= $cat === $cv ? 'selected' : '' ?>><?= $H($cl) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </td>
          <td><?= $H($a['tipo_hw'] ?: '—') ?></td>
          <td><?= $H($a['fabricante'] ?: '—') ?><?php if ($a['modelo']): ?><div class="sub"><?= $H($a['modelo']) ?></div><?php endif; ?></td>
          <?php if ($view === 'baixados'): ?>
            <td><?= $H($MOT[$a['baixa_motivo']] ?? $a['baixa_motivo']) ?><?php if ($a['baixa_obs']): ?><div class="sub"><?= $H($a['baixa_obs']) ?></div><?php endif; ?></td>
            <td><?= $H($a['baixa_data'] ?: '—') ?></td>
          <?php else: ?>
            <td><?= $H($a['serial'] ?: '—') ?></td>
            <td><?= $H($a['otherserial'] ?: '—') ?></td>
          <?php endif; ?>
          <td><div class="row-act">
            <?php if ($view === 'baixados'): ?>
              <button title="Reativar" onclick="reativar(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-arrow-counterclockwise"></i></button>
            <?php else: ?>
              <button title="Editar" onclick="abrirModal(<?= (int)$a['id'] ?>)"><i class="bi bi-pencil"></i></button>
              <button title="Dar baixa" onclick="abrirBaixa(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-box-arrow-in-down"></i></button>
            <?php endif; ?>
            <button class="del" title="Excluir" onclick="excluir(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-trash3"></i></button>
            <a href="/glpi2/front/computer.form.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" title="Abrir no GLPI"><i class="bi bi-box-arrow-up-right"></i></a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php };
  ?>

  <?php
  // linha de máquina na visão de servidores (com papel + host)
  $linhaSrv = function(array $a) use ($H, $srvFisicos) {
      $papel = $a['papel'] ?? 'fisico'; ?>
    <tr>
      <td><?= $papel === 'virtual' ? '<span style="color:#9aa0a6">└─ </span>' : '<i class="bi bi-hdd-rack-fill" style="color:#5e35b1"></i> ' ?><?= $H($a['name'] ?: '(sem nome)') ?></td>
      <td>
        <select class="cat" onchange="mudarSrv(<?= (int)$a['id'] ?>, this.value, null)">
          <option value="fisico" <?= $papel === 'fisico' ? 'selected' : '' ?>>Servidor físico</option>
          <option value="virtual" <?= $papel === 'virtual' ? 'selected' : '' ?>>Máquina virtual</option>
        </select>
      </td>
      <td>
        <?php if ($papel === 'virtual'): ?>
          <select class="cat" onchange="mudarSrv(<?= (int)$a['id'] ?>, 'virtual', this.value)">
            <option value="0">— servidor host —</option>
            <?php foreach ($srvFisicos as $fid => $fnome): if ($fid === (int)$a['id']) continue; ?>
              <option value="<?= $fid ?>" <?= (int)$a['host_id'] === $fid ? 'selected' : '' ?>><?= $H($fnome) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?><span class="sub">—</span><?php endif; ?>
      </td>
      <td><?= $H($a['tipo_hw'] ?: '—') ?></td>
      <td><?= $H($a['fabricante'] ?: '—') ?><?php if ($a['modelo']): ?><div class="sub"><?= $H($a['modelo']) ?></div><?php endif; ?></td>
      <td><?= $H($a['serial'] ?: '—') ?><?php if ($a['otherserial']): ?><div class="sub">pat. <?= $H($a['otherserial']) ?></div><?php endif; ?></td>
      <td><div class="row-act">
        <button title="Editar" onclick="abrirModal(<?= (int)$a['id'] ?>)"><i class="bi bi-pencil"></i></button>
        <button title="Dar baixa" onclick="abrirBaixa(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-box-arrow-in-down"></i></button>
        <button class="del" title="Excluir" onclick="excluir(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-trash3"></i></button>
        <a href="/glpi2/front/computer.form.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" title="Abrir no GLPI"><i class="bi bi-box-arrow-up-right"></i></a>
      </div></td>
    </tr>
  <?php };

  $arvore = function(array $fisico, array $vms) use ($H, $linhaSrv) { ?>
    <div class="grp open">
      <div class="grp-hd" onclick="this.parentElement.classList.toggle('open')">
        <span><i class="bi bi-hdd-rack-fill"></i> <?= $H($fisico['name'] ?? 'Sem servidor definido') ?></span>
        <span class="grp-n"><?= count($vms) ?> VM<?= count($vms) == 1 ? '' : 's' ?> <i class="bi bi-chevron-down chev"></i></span>
      </div>
      <div class="grp-bd"><table>
        <thead><tr><th>Nome</th><th>Papel</th><th>Host</th><th>Tipo (HW)</th><th>Fabricante / Modelo</th><th>Série</th><th></th></tr></thead>
        <tbody>
          <?php if ($fisico) $linhaSrv($fisico); ?>
          <?php foreach ($vms as $v) $linhaSrv($v); ?>
        </tbody>
      </table></div>
    </div>
  <?php };
  ?>

  <?php if (!$rows): ?>
    <div class="empty"><i class="bi bi-inbox"></i>
      <?= ($busca || $loja_filtro) ? 'Nenhuma máquina com esse filtro.' : ($view === 'baixados' ? 'Nenhuma máquina baixada.' : 'Nenhuma máquina nesta categoria.') ?>
    </div>
  <?php elseif ($IS_SRV): ?>
    <?php
      $fisicos = []; $vmsPorHost = []; $orfas = [];
      foreach ($rows as $r) {
          if (($r['papel'] ?? 'fisico') === 'fisico') $fisicos[(int)$r['id']] = $r;
      }
      foreach ($rows as $r) {
          if (($r['papel'] ?? 'fisico') !== 'virtual') continue;
          $h = (int)($r['host_id'] ?? 0);
          if ($h && isset($fisicos[$h])) $vmsPorHost[$h][] = $r;
          else $orfas[] = $r;
      }
      uasort($fisicos, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
      foreach ($fisicos as $fid => $f) $arvore($f, $vmsPorHost[$fid] ?? []);
      if ($orfas) $arvore([], $orfas);
    ?>
  <?php elseif ($loja_filtro): ?>
    <?php $tabela($rows); ?>
  <?php else:
    $grupos = [];
    foreach ($rows as $a) { $grupos[apelido_entidade($entMap[(int)$a['entities_id']] ?? '') ?: 'Sem loja'][] = $a; }
    ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($grupos as $ln => $lr): ?>
    <div class="grp">
      <div class="grp-hd" onclick="this.parentElement.classList.toggle('open')">
        <span><i class="bi bi-shop"></i> <?= $H($ln) ?></span>
        <span class="grp-n"><?= count($lr) ?> <i class="bi bi-chevron-down chev"></i></span>
      </div>
      <div class="grp-bd"><?php $tabela($lr); ?></div>
    </div>
    <?php endforeach;
  endif; ?>
</div>

<div class="modal-back" id="modalBack">
  <div class="modal-card">
    <header><h3 id="modalTitulo">Nova máquina</h3><button onclick="fecharModal()">&times;</button></header>
    <div class="modal-body">
      <input type="hidden" id="f-id"/>
      <div class="fld full"><label>Nome *</label><input id="f-name"/></div>
      <div class="fld"><label>Loja</label>
        <select id="f-entidade"><option value="0">—</option>
          <?php foreach ($entidades as $e): ?><option value="<?= (int)$e['id'] ?>"><?= $H(apelido_entidade($e['completename'])) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="fld"><label>Categoria</label>
        <select id="f-categoria">
          <?php foreach (INV_PC_CATS as $cv => $cl): ?><option value="<?= $cv ?>" <?= $slug === $cv ? 'selected' : '' ?>><?= $H($cl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($slug === 'maquinas-virtuais'): ?>
      <div class="fld"><label>Papel</label>
        <select id="f-papel" onchange="document.getElementById('f-host-wrap').style.display = this.value === 'virtual' ? '' : 'none'">
          <option value="fisico">Servidor físico</option>
          <option value="virtual">Máquina virtual</option>
        </select>
      </div>
      <div class="fld" id="f-host-wrap" style="display:none"><label>Servidor host (da VM)</label>
        <select id="f-host"><option value="0">—</option>
          <?php foreach ($srvFisicos as $fid => $fnome): ?><option value="<?= $fid ?>"><?= $H($fnome) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="fld"><label>Fabricante</label><input id="f-fabricante"/></div>
      <div class="fld"><label>Modelo</label><input id="f-modelo"/></div>
      <div class="fld"><label>Nº de série</label><input id="f-serial"/></div>
      <div class="fld"><label>Patrimônio</label><input id="f-otherserial"/></div>
      <div class="fld full"><label>Observação</label><textarea id="f-comment" rows="2"></textarea></div>
    </div>
    <footer>
      <button class="btn btn-ghost" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-primary" id="btnSalvar" onclick="salvar()">Salvar</button>
    </footer>
  </div>
</div>

<div class="modal-back" id="baixaBack">
  <div class="modal-card" style="width:440px">
    <header><h3>Dar baixa na máquina</h3><button onclick="fecharBaixa()">&times;</button></header>
    <div class="modal-body" style="grid-template-columns:1fr">
      <input type="hidden" id="b-id"/>
      <p style="margin:0;color:#5f6368;font-size:.85rem">Marcar <strong id="b-nome"></strong> como fora de uso.</p>
      <div class="fld"><label>Motivo *</label>
        <select id="b-motivo"><option value="quebrado">Quebrado definitivamente</option><option value="vendido">Vendido</option><option value="descartado">Descartado</option><option value="outro">Outro</option></select>
      </div>
      <div class="fld"><label>Data</label><input type="date" id="b-data"/></div>
      <div class="fld"><label>Observação</label><textarea id="b-obs" rows="2"></textarea></div>
    </div>
    <footer><button class="btn btn-ghost" onclick="fecharBaixa()">Cancelar</button><button class="btn btn-primary" id="btnBaixa" onclick="confirmarBaixa()">Dar baixa</button></footer>
  </div>
</div>

<div id="msg"></div>

<script>
const CAT = <?= json_encode($slug) ?>;
const $ = s => document.querySelector(s);
function toast(t, ok = true) {
  const d = document.createElement('div');
  d.className = 'alert alert-' + (ok ? 'success' : 'danger') + ' py-2 px-3 mb-2 shadow-sm';
  d.textContent = t; $('#msg').appendChild(d); setTimeout(() => d.remove(), 4000);
}
function P(data) {
  const fd = new FormData();
  for (const k in data) fd.set(k, data[k]);
  return fetch(`inventario_pc.php?cat=${CAT}`, { method: 'POST', body: fd }).then(r => r.json());
}
function mudarCat(id, cat) { P({ action: 'set_cat', id, categoria: cat }).then(d => { if (d.ok) { toast('Categoria alterada'); setTimeout(() => location.reload(), 500); } }); }
function mudarSrv(id, papel, host) {
  const d = { action: 'set_srv', id, papel };
  d.host_id = (host === null || host === undefined) ? 0 : host;
  P(d).then(r => { if (r.ok) { toast('Atualizado'); setTimeout(() => location.reload(), 500); } });
}

function abrirModal(id) {
  ['f-name','f-fabricante','f-modelo','f-serial','f-otherserial','f-comment'].forEach(i => $('#'+i).value = '');
  $('#f-entidade').value = 0; $('#f-categoria').value = CAT;
  if ($('#f-papel')) { $('#f-papel').value = 'fisico'; $('#f-host-wrap').style.display = 'none'; $('#f-host').value = 0; }
  $('#modalTitulo').textContent = id ? 'Editar máquina' : 'Nova máquina';
  $('#f-id').value = id || '';
  if (id) fetch(`inventario_pc.php?cat=${CAT}&action=form&id=${id}`).then(r => r.json()).then(d => {
    if (!d.ok) { toast(d.erro, false); return; }
    const it = d.item;
    $('#f-name').value = it.name || ''; $('#f-entidade').value = it.entities_id || 0;
    $('#f-categoria').value = it.categoria || CAT;
    $('#f-fabricante').value = it.fabricante || ''; $('#f-modelo').value = it.modelo || '';
    $('#f-serial').value = it.serial || ''; $('#f-otherserial').value = it.otherserial || '';
    $('#f-comment').value = it.comment || '';
    if ($('#f-papel')) {
      $('#f-papel').value = it.papel || 'fisico';
      $('#f-host-wrap').style.display = it.papel === 'virtual' ? '' : 'none';
      $('#f-host').value = it.host_id || 0;
    }
  });
  $('#modalBack').classList.add('show');
}
function fecharModal() { $('#modalBack').classList.remove('show'); }
function salvar() {
  const d = {
    action: 'save', id: $('#f-id').value || 0, name: $('#f-name').value.trim(),
    entities_id: $('#f-entidade').value, categoria: $('#f-categoria').value,
    fabricante: $('#f-fabricante').value.trim(), modelo: $('#f-modelo').value.trim(),
    serial: $('#f-serial').value.trim(), otherserial: $('#f-otherserial').value.trim(),
    comment: $('#f-comment').value.trim(),
  };
  if ($('#f-papel')) { d.papel = $('#f-papel').value; d.host_id = $('#f-host').value; }
  if (!d.name) { toast('Nome é obrigatório', false); return; }
  $('#btnSalvar').disabled = true;
  P(d).then(r => { if (r.ok) { toast('Salvo'); setTimeout(() => location.reload(), 600); } else { toast(r.erro || 'Erro', false); $('#btnSalvar').disabled = false; } });
}
function excluir(id, nome) {
  if (!confirm(`Excluir "${nome}"?\n\nVai pra lixeira do GLPI.`)) return;
  P({ action: 'delete', id }).then(d => { if (d.ok) { toast('Excluído'); setTimeout(() => location.reload(), 600); } else toast('Erro', false); });
}
function abrirBaixa(id, nome) {
  $('#b-id').value = id; $('#b-nome').textContent = nome;
  $('#b-motivo').value = 'quebrado'; $('#b-data').value = new Date().toISOString().slice(0, 10); $('#b-obs').value = '';
  $('#baixaBack').classList.add('show');
}
function fecharBaixa() { $('#baixaBack').classList.remove('show'); }
function confirmarBaixa() {
  $('#btnBaixa').disabled = true;
  P({ action: 'baixa', id: $('#b-id').value, motivo: $('#b-motivo').value, baixado_em: $('#b-data').value, observacao: $('#b-obs').value.trim() })
    .then(d => { if (d.ok) { toast('Baixa registrada'); setTimeout(() => location.reload(), 600); } else { toast('Erro', false); $('#btnBaixa').disabled = false; } });
}
function reativar(id, nome) {
  if (!confirm(`Reativar "${nome}"?`)) return;
  P({ action: 'reativar', id }).then(d => { if (d.ok) { toast('Reativado'); setTimeout(() => location.reload(), 600); } });
}
function toggleTodosGrupos() {
  const g = document.querySelectorAll('.grp');
  const algumFechado = [...g].some(x => !x.classList.contains('open'));
  g.forEach(x => x.classList.toggle('open', algumFechado));
}
$('#modalBack').addEventListener('click', e => { if (e.target === $('#modalBack')) fecharModal(); });
$('#baixaBack').addEventListener('click', e => { if (e.target === $('#baixaBack')) fecharBaixa(); });
</script>
</body>
</html>
