<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';

/*
 * Visualizador genérico de inventário lido direto das tabelas do GLPI
 * (glpi_phones / glpi_peripherals). Somente leitura — o cadastro/edição
 * acontece no próprio GLPI. Cada card do inventario.php aponta pra cá com ?cat=.
 */

$CATS = [
    'celulares'  => ['src'=>'phone',      'tipo'=>null,                 'titulo'=>'Celulares',            'icone'=>'bi-phone',              'cor'=>'#1565c0'],
    'tablets'    => ['src'=>'peripheral', 'tipo'=>'Tablet',             'titulo'=>'Tablets',              'icone'=>'bi-tablet',            'cor'=>'#7b1fa2'],
    'coletores'  => ['src'=>'peripheral', 'tipo'=>'Coletor',            'titulo'=>'Coletores',            'icone'=>'bi-upc-scan',          'cor'=>'#2e7d32'],
    'pdvmobile'  => ['src'=>'peripheral', 'tipo'=>'PDV Mobile',         'titulo'=>'PDV Mobile',           'icone'=>'bi-credit-card-2-back','cor'=>'#e65100'],
    'pinpads'    => ['src'=>'peripheral', 'tipo'=>'Pinpad',             'titulo'=>'Pinpads',              'icone'=>'bi-credit-card',       'cor'=>'#3949ab'],
    'pos'        => ['src'=>'peripheral', 'tipo'=>'POS',                'titulo'=>'POS',                  'icone'=>'bi-shop-window',       'cor'=>'#00796b'],
    'termometros'=> ['src'=>'peripheral', 'tipo'=>'Termômetro',         'titulo'=>'Termômetros',          'icone'=>'bi-thermometer-half',  'cor'=>'#c62828'],
    'radios'     => ['src'=>'peripheral', 'tipo'=>'Rádio Comunicador',  'titulo'=>'Rádios Comunicação',   'icone'=>'bi-walkie-talkie',     'cor'=>'#0277bd'],
    'som'        => ['src'=>'peripheral', 'tipo'=>'Equipamento de Som', 'titulo'=>'Equipamentos de Som',  'icone'=>'bi-speaker-fill',      'cor'=>'#8e24aa'],
    'acessorios' => ['src'=>'peripheral', 'tipo'=>'Acessório Celular',  'titulo'=>'Acessórios Celulares', 'icone'=>'bi-headphones',        'cor'=>'#f9a825'],
    'triturador' => ['src'=>'peripheral', 'tipo'=>'Triturador de Papel','titulo'=>'Triturador de Papel',  'icone'=>'bi-scissors',          'cor'=>'#546e7a'],
    'videoconf'  => ['src'=>'peripheral', 'tipo'=>'Videoconferência',   'titulo'=>'Videoconferência',     'icone'=>'bi-camera-video-fill', 'cor'=>'#1a73e8'],
];

$cat = $_GET['cat'] ?? '';
if (!isset($CATS[$cat])) { header('Location: inventario.php'); exit; }
$C = $CATS[$cat];

$loja_filtro = (int)($_GET['loja'] ?? 0);
$busca       = trim($_GET['busca'] ?? '');

// ── Resolve o tipo (peripheral) para id ────────────────────────
$tipo_id      = null;
$tipo_faltando = false;
if ($C['src'] === 'peripheral') {
    $st = $pdo->prepare("SELECT id FROM glpi_peripheraltypes WHERE name = ?");
    $st->execute([$C['tipo']]);
    $tipo_id = $st->fetchColumn();
    if ($tipo_id === false) { $tipo_id = -1; $tipo_faltando = true; }
}

// ── Monta a query ─────────────────────────────────────────────
if ($C['src'] === 'phone') {
    $sql = "SELECT p.id, p.name, p.serial, p.otherserial, p.contact, p.number_line AS linha,
                   p.comment, p.entities_id, e.completename AS entidade,
                   m.name AS fabricante, pm.name AS modelo, p.date_mod
            FROM glpi_phones p
            LEFT JOIN glpi_entities e     ON e.id = p.entities_id
            LEFT JOIN glpi_manufacturers m ON m.id = p.manufacturers_id
            LEFT JOIN glpi_phonemodels pm  ON pm.id = p.phonemodels_id
            WHERE p.is_deleted = 0 AND p.is_template = 0";
    $params = [];
    $glpi_form = 'phone.form.php';
} else {
    $sql = "SELECT p.id, p.name, p.serial, p.otherserial, p.contact, NULL AS linha,
                   p.comment, p.entities_id, e.completename AS entidade,
                   m.name AS fabricante, pm.name AS modelo, p.date_mod
            FROM glpi_peripherals p
            LEFT JOIN glpi_entities e      ON e.id = p.entities_id
            LEFT JOIN glpi_manufacturers m ON m.id = p.manufacturers_id
            LEFT JOIN glpi_peripheralmodels pm ON pm.id = p.peripheralmodels_id
            WHERE p.is_deleted = 0 AND p.is_template = 0 AND p.is_dynamic = 0
              AND p.peripheraltypes_id = :tid";
    $params = [':tid' => $tipo_id];
    $glpi_form = 'peripheral.form.php';
}
if ($loja_filtro) { $sql .= " AND p.entities_id = :loja"; $params[':loja'] = $loja_filtro; }
if ($busca !== '') {
    $sql .= " AND (p.name LIKE :b OR p.serial LIKE :b OR p.otherserial LIKE :b OR p.contact LIKE :b)";
    $params[':b'] = '%' . $busca . '%';
}
$sql .= " ORDER BY e.completename, p.name";

$st = $pdo->prepare($sql);
$st->execute($params);
$ativos = $st->fetchAll();

// ── Contagem por loja (ignora o filtro de loja) ───────────────
if ($C['src'] === 'phone') {
    $sqlL = "SELECT p.entities_id, e.completename, COUNT(*) n
             FROM glpi_phones p LEFT JOIN glpi_entities e ON e.id = p.entities_id
             WHERE p.is_deleted=0 AND p.is_template=0
             GROUP BY p.entities_id, e.completename ORDER BY n DESC";
    $stL = $pdo->query($sqlL);
} else {
    $stL = $pdo->prepare("SELECT p.entities_id, e.completename, COUNT(*) n
             FROM glpi_peripherals p LEFT JOIN glpi_entities e ON e.id = p.entities_id
             WHERE p.is_deleted=0 AND p.is_template=0 AND p.is_dynamic=0 AND p.peripheraltypes_id=:tid
             GROUP BY p.entities_id, e.completename ORDER BY n DESC");
    $stL->execute([':tid' => $tipo_id]);
}
$por_loja = $stL->fetchAll();
$total_geral = array_sum(array_column($por_loja, 'n'));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$GLPI_BASE = '/glpi2/front/';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= h($C['titulo']) ?> — Inventário</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:#1a237e; color:#fff; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; font-size:1.05rem; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.85rem; display:flex; align-items:center; gap:.35rem; padding:.3rem .7rem; border-radius:6px; transition:.15s; }
    .topbar a:hover { background:rgba(255,255,255,.15); color:#fff; }
    .wrap { max-width:1100px; margin:1.5rem auto 3rem; padding:0 1.5rem; }
    .head { display:flex; align-items:center; gap:1rem; margin-bottom:1rem; }
    .head .ic { width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:#fff; flex-shrink:0; }
    .head h1 { font-size:1.4rem; font-weight:800; margin:0; color:#1a237e; }
    .head p  { margin:0; color:#5f6368; font-size:.85rem; }
    .toolbar { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:1rem; }
    .chip { border:1px solid #d5dae2; background:#fff; border-radius:20px; padding:.3rem .8rem; font-size:.8rem; color:#3c4043; text-decoration:none; white-space:nowrap; }
    .chip:hover { border-color:#1a237e; color:#1a237e; }
    .chip.active { background:#1a237e; color:#fff; border-color:#1a237e; }
    .chip .n { opacity:.65; margin-left:.3rem; }
    form.busca { margin-left:auto; display:flex; gap:.4rem; }
    form.busca input { border:1px solid #d5dae2; border-radius:8px; padding:.35rem .7rem; font-size:.85rem; min-width:200px; }
    .btn-glpi { background:#1a237e; color:#fff; border:none; border-radius:8px; padding:.4rem .9rem; font-size:.82rem; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem; }
    table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    th, td { padding:.6rem .85rem; text-align:left; font-size:.85rem; border-bottom:1px solid #eef1f5; }
    th { background:#f6f8fb; font-weight:700; color:#3c4043; font-size:.78rem; text-transform:uppercase; letter-spacing:.4px; }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:#f9fbfd; }
    td .sub { color:#80868b; font-size:.76rem; }
    .empty { background:#fff; border-radius:12px; padding:3rem 1.5rem; text-align:center; color:#5f6368; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    .empty i { font-size:2.5rem; color:#c5cbd3; display:block; margin-bottom:.75rem; }
    .alert-tipo { background:#fff8e1; border:1px solid #ffe082; border-radius:10px; padding:.75rem 1rem; font-size:.85rem; color:#7a5b00; margin-bottom:1rem; }
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
    <div class="ic" style="background:<?= h($C['cor']) ?>"><i class="bi <?= h($C['icone']) ?>"></i></div>
    <div>
      <h1><?= h($C['titulo']) ?></h1>
      <p><?= $total_geral ?> ativo<?= $total_geral == 1 ? '' : 's' ?> cadastrado<?= $total_geral == 1 ? '' : 's' ?> no GLPI</p>
    </div>
  </div>

  <?php if ($tipo_faltando): ?>
  <div class="alert-tipo">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    O tipo de periférico <strong>"<?= h($C['tipo']) ?>"</strong> ainda não existe no GLPI.
    Crie em <em>Configurar → Intitulados → Tipos de periférico</em> e classifique os ativos com esse tipo para eles aparecerem aqui.
  </div>
  <?php endif; ?>

  <div class="toolbar">
    <a class="chip <?= !$loja_filtro ? 'active' : '' ?>" href="?cat=<?= h($cat) ?>">Todas<span class="n"><?= $total_geral ?></span></a>
    <?php foreach ($por_loja as $l): if ($l['entities_id'] === null) continue; ?>
      <a class="chip <?= $loja_filtro == $l['entities_id'] ? 'active' : '' ?>"
         href="?cat=<?= h($cat) ?>&loja=<?= (int)$l['entities_id'] ?>">
        <?= h(apelido_entidade($l['completename'] ?? '—')) ?><span class="n"><?= (int)$l['n'] ?></span>
      </a>
    <?php endforeach; ?>
    <form class="busca" method="get">
      <input type="hidden" name="cat" value="<?= h($cat) ?>"/>
      <?php if ($loja_filtro): ?><input type="hidden" name="loja" value="<?= $loja_filtro ?>"/><?php endif; ?>
      <input type="text" name="busca" value="<?= h($busca) ?>" placeholder="Nome, série, patrimônio..."/>
      <a class="btn-glpi" href="<?= $GLPI_BASE . $glpi_form ?>" target="_blank" rel="noopener">
        <i class="bi bi-plus-lg"></i> Cadastrar no GLPI
      </a>
    </form>
  </div>

  <?php if (!$ativos): ?>
    <div class="empty">
      <i class="bi bi-inbox"></i>
      <?php if ($busca || $loja_filtro): ?>
        Nenhum ativo encontrado com esse filtro.
      <?php else: ?>
        Nenhum <?= h($C['titulo']) ?> cadastrado ainda.<br/>
        Use o botão <strong>Cadastrar no GLPI</strong> para adicionar o primeiro.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Nome</th>
          <th>Loja</th>
          <th>Fabricante / Modelo</th>
          <th><?= $C['src'] === 'phone' ? 'Linha' : 'Série' ?></th>
          <th>Patrimônio</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ativos as $a): ?>
        <tr>
          <td>
            <?= h($a['name'] ?: '(sem nome)') ?>
            <?php if ($a['contact']): ?><div class="sub"><?= h($a['contact']) ?></div><?php endif; ?>
          </td>
          <td><?= h(apelido_entidade($a['entidade'] ?? '—')) ?></td>
          <td>
            <?= h($a['fabricante'] ?: '—') ?>
            <?php if ($a['modelo']): ?><div class="sub"><?= h($a['modelo']) ?></div><?php endif; ?>
          </td>
          <td><?= h(($C['src'] === 'phone' ? $a['linha'] : $a['serial']) ?: '—') ?></td>
          <td><?= h($a['otherserial'] ?: '—') ?></td>
          <td>
            <a class="chip" href="<?= $GLPI_BASE . $glpi_form ?>?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>
</body>
</html>
