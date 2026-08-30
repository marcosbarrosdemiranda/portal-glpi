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
    echo json_encode(['ok' => ($code >= 200 && $code < 300), 'http' => $code]);
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

$typeIds = array_values(array_filter(array_map(fn($s) => (int)$s['glpi_type_id'], $subcats)));
$aplicaTipo = ($fonte === 'peripheral') || count($subcats) > 1;

$where  = ["p.is_deleted = 0", "p.is_template = 0"];
$params = [];
if ($fonte === 'peripheral') $where[] = "p.is_dynamic = 0";

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

$sql = "SELECT p.id, p.name, p.serial, p.otherserial, p.contact, p.entities_id,
               p.$colType AS type_id, e.completename AS entidade,
               m.name AS fabricante, md.name AS modelo, t.name AS subcategoria, p.date_mod
        FROM `$tbl` p
        LEFT JOIN glpi_entities e      ON e.id  = p.entities_id
        LEFT JOIN glpi_manufacturers m ON m.id  = p.manufacturers_id
        LEFT JOIN `$tblModel` md       ON md.id = p.$colModel
        LEFT JOIN `$tblType` t         ON t.id  = p.$colType
        WHERE $W
        ORDER BY e.completename, p.name";
$st = $pdo->prepare($sql);
$st->execute($params);
$ativos = $st->fetchAll();

// contagens (por subcategoria e por loja) — ignoram os filtros sub/loja/busca
function inv_count(PDO $pdo, string $tbl, string $baseW, array $baseP, string $groupCol): array {
    $st = $pdo->prepare("SELECT p.$groupCol AS k, COUNT(*) n FROM `$tbl` p WHERE $baseW GROUP BY p.$groupCol");
    $st->execute($baseP);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$baseW = implode(' AND ', array_filter($where, fn($c) => !str_contains($c, ':subf') && !str_contains($c, ':loja') && !str_contains($c, ':b')));
$baseP = array_filter($params, fn($k) => !in_array($k, [':subf', ':loja', ':b'], true), ARRAY_FILTER_USE_KEY);
$cntPorTipo = inv_count($pdo, $tbl, $baseW, $baseP, $colType);
$cntPorLoja = inv_count($pdo, $tbl, $baseW, $baseP, 'entities_id');
$totalGeral = array_sum($cntPorTipo);

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
    .head .add { margin-left:auto; }
    .btn-add { background:#1a237e; color:#fff; border:none; border-radius:9px; padding:.5rem 1rem; font-size:.85rem; display:inline-flex; align-items:center; gap:.45rem; cursor:pointer; }
    .tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.6rem; }
    .tab { border:1px solid #d5dae2; background:#fff; border-radius:20px; padding:.3rem .85rem; font-size:.8rem; color:#3c4043; text-decoration:none; }
    .tab:hover { border-color:#1a237e; color:#1a237e; }
    .tab.active { background:#1a237e; color:#fff; border-color:#1a237e; }
    .tab .n { opacity:.6; margin-left:.35rem; }
    .filtros { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin-bottom:1rem; }
    .chip { border:1px solid #e0e4ea; background:#fff; border-radius:16px; padding:.25rem .7rem; font-size:.78rem; color:#5f6368; text-decoration:none; }
    .chip.active { background:#e8eaf6; color:#1a237e; border-color:#c5cae9; font-weight:600; }
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
  <a href="dashboard.php"><i class="bi bi-house"></i> Início</a>
</div>

<div class="wrap">
  <div class="head">
    <div class="ic" style="background:<?= $H($card['cor']) ?>"><i class="bi <?= $H($card['icone']) ?>"></i></div>
    <div>
      <h1><?= $H($card['titulo']) ?></h1>
      <p><?= $totalGeral ?> ativo<?= $totalGeral == 1 ? '' : 's' ?> no GLPI</p>
    </div>
    <div class="add"><button class="btn-add" onclick="abrirModal(0)"><i class="bi bi-plus-lg"></i> Novo</button></div>
  </div>

  <?php if (count($subcats) > 1): ?>
  <div class="tabs">
    <a class="tab <?= !$sub_filtro ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>">Todas<span class="n"><?= $totalGeral ?></span></a>
    <?php foreach ($subcats as $s): ?>
      <a class="tab <?= $sub_filtro == $s['id'] ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?>&sub=<?= (int)$s['id'] ?>">
        <?= $H($s['nome']) ?><span class="n"><?= (int)($cntPorTipo[$s['glpi_type_id']] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="filtros">
    <a class="chip <?= !$loja_filtro ? 'active' : '' ?>" href="?cat=<?= $H($slug) ?><?= $sub_filtro ? '&sub='.$sub_filtro : '' ?>">Todas as lojas</a>
    <?php foreach ($cntPorLoja as $eid => $n): if (!$eid) continue;
        $enome = ''; foreach ($entidades as $e) if ((int)$e['id'] === (int)$eid) $enome = $e['completename']; ?>
      <a class="chip <?= $loja_filtro == $eid ? 'active' : '' ?>"
         href="?cat=<?= $H($slug) ?><?= $sub_filtro ? '&sub='.$sub_filtro : '' ?>&loja=<?= (int)$eid ?>">
        <?= $H(apelido_entidade($enome)) ?> <?= (int)$n ?>
      </a>
    <?php endforeach; ?>
    <form class="busca" method="get">
      <input type="hidden" name="cat" value="<?= $H($slug) ?>"/>
      <?php if ($sub_filtro): ?><input type="hidden" name="sub" value="<?= $sub_filtro ?>"/><?php endif; ?>
      <?php if ($loja_filtro): ?><input type="hidden" name="loja" value="<?= $loja_filtro ?>"/><?php endif; ?>
      <input type="text" name="busca" value="<?= $H($busca) ?>" placeholder="Nome, série, patrimônio..."/>
    </form>
  </div>

  <?php if (!$ativos): ?>
    <div class="empty">
      <i class="bi bi-inbox"></i>
      <?= ($busca || $loja_filtro || $sub_filtro) ? 'Nenhum ativo com esse filtro.' : 'Nenhum ' . $H($card['titulo']) . ' cadastrado ainda. Clique em <strong>Novo</strong>.' ?>
    </div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>Nome</th><th>Loja</th><th>Subcategoria</th><th>Fabricante / Modelo</th>
        <th><?= $fonte === 'phone' ? 'Nº linha' : 'Série' ?></th><th>Patrimônio</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($ativos as $a): ?>
        <tr>
          <td><?= $H($a['name'] ?: '(sem nome)') ?><?php if ($a['contact']): ?><div class="sub"><?= $H($a['contact']) ?></div><?php endif; ?></td>
          <td><?= $H(apelido_entidade($a['entidade'] ?? '—')) ?></td>
          <td><?= $H($a['subcategoria'] ?: '—') ?></td>
          <td><?= $H($a['fabricante'] ?: '—') ?><?php if ($a['modelo']): ?><div class="sub"><?= $H($a['modelo']) ?></div><?php endif; ?></td>
          <td><?= $H($a['serial'] ?: '—') ?></td>
          <td><?= $H($a['otherserial'] ?: '—') ?></td>
          <td><div class="row-act">
            <button title="Editar" onclick="abrirModal(<?= (int)$a['id'] ?>)"><i class="bi bi-pencil"></i></button>
            <button class="del" title="Excluir" onclick="excluir(<?= (int)$a['id'] ?>, '<?= $H(addslashes($a['name'])) ?>')"><i class="bi bi-trash3"></i></button>
            <a class="chip" href="<?= $GLPI_BASE . $glpiForm ?>?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" title="Abrir no GLPI"><i class="bi bi-box-arrow-up-right"></i></a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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
      <div class="fld"><label><?= $fonte === 'phone' ? 'Nº da linha' : 'Nº de série' ?></label><input type="text" id="f-serial"/></div>
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
    </div>
    <footer>
      <button class="btn btn-ghost" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-primary" id="btnSalvar" onclick="salvar()">Salvar</button>
    </footer>
  </div>
</div>
<datalist id="dl-fab"></datalist>
<div id="msg"></div>

<script>
const CAT = <?= json_encode($slug) ?>;
const $ = s => document.querySelector(s);

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
        $('#f-serial').value = it.serial || '';
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
  ['f-name','f-fabricante','f-modelo','f-serial','f-otherserial','f-comment'].forEach(i => $('#'+i).value = '');
  $('#f-entidade').value = 0; $('#f-subcat').value = 0;
  document.querySelectorAll('[data-campo]').forEach(el => el.value = '');
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

$('#modalBack').addEventListener('click', e => { if (e.target === $('#modalBack')) fecharModal(); });
</script>
</body>
</html>
