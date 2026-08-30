<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';
require_once __DIR__ . '/inventario_lib.php';
inv_bootstrap($pdo);

$H = 'inv_h';
$MOT = ['quebrado'=>'Quebrado','vendido'=>'Vendido','descartado'=>'Descartado','outro'=>'Outro'];

// filtro opcional: ?cat=slug limita a 1 card; sem cat = todos
$soCat   = $_GET['cat'] ?? '';
$view    = ($_GET['view'] ?? 'ativos') === 'baixados' ? 'baixados' : 'ativos';
$autoImp = isset($_GET['print']);

$cards = $soCat ? array_filter(inv_cards($pdo), fn($c) => $c['slug'] === $soCat) : inv_cards($pdo);

$blocos = [];
$totalItens = 0;
foreach ($cards as $c) {
    $subs   = inv_subcats($pdo, (int)$c['id']);
    $rows   = inv_ativos_do_card($pdo, $c, $subs, $view);
    $flds   = array_values(array_filter(inv_fields($pdo, (int)$c['id']), fn($f) => !empty($f['na_lista']) && $f['tipo'] !== 'textarea'));
    $vals   = $flds ? inv_values_bulk($pdo, $c['fonte'] === 'phone' ? 'Phone' : 'Peripheral', array_column($rows, 'id')) : [];
    $porLoja = [];
    foreach ($rows as $r) {
        $ln = apelido_entidade($r['entidade'] ?? '') ?: 'Sem loja';
        $porLoja[$ln][] = $r;
    }
    ksort($porLoja, SORT_NATURAL | SORT_FLAG_CASE);
    $blocos[] = ['card' => $c, 'porLoja' => $porLoja, 'total' => count($rows), 'fields' => $flds, 'vals' => $vals];
    $totalItens += count($rows);
}
$hoje = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Relatório<?= $soCat ? ' ' . $H($cards[array_key_first($cards)]['titulo'] ?? '') : '' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f0f4f9; font-family:'Segoe UI',Arial,sans-serif; margin:0; color:#202124; }
    .bar { background:#1a237e; color:#fff; padding:.7rem 1.5rem; display:flex; align-items:center; gap:1rem; }
    .bar .sp { flex:1; }
    .bar button, .bar a { background:rgba(255,255,255,.15); color:#fff; border:none; border-radius:8px; padding:.4rem .9rem; font-size:.85rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem; }
    .bar button:hover, .bar a:hover { background:rgba(255,255,255,.28); }
    .doc { max-width:1000px; margin:1.5rem auto 3rem; padding:2rem 2.25rem; background:#fff; box-shadow:0 1px 6px rgba(0,0,0,.1); }
    .doc h1 { font-size:1.4rem; margin:0 0 .2rem; color:#1a237e; }
    .doc .meta { color:#5f6368; font-size:.82rem; margin-bottom:1.5rem; }
    .bloco { margin-bottom:1.8rem; break-inside:avoid; }
    .bloco h2 { font-size:1.05rem; margin:0 0 .1rem; color:#1a237e; border-bottom:2px solid #1a237e; padding-bottom:.25rem; }
    .bloco .btot { font-size:.8rem; color:#5f6368; margin:.15rem 0 .6rem; }
    .loja { font-weight:700; font-size:.9rem; margin:.7rem 0 .3rem; color:#333; }
    table { width:100%; border-collapse:collapse; margin-bottom:.5rem; }
    th, td { border:1px solid #d5dae2; padding:.35rem .55rem; font-size:.8rem; text-align:left; }
    th { background:#f0f2f7; font-size:.72rem; text-transform:uppercase; letter-spacing:.3px; }
    .vazio { color:#9aa0a6; font-size:.82rem; font-style:italic; }
    @media print {
      body { background:#fff; }
      .bar { display:none; }
      .doc { max-width:100%; margin:0; padding:0; box-shadow:none; }
      .bloco { break-inside:avoid; }
      th { background:#eee !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
  </style>
</head>
<body>

<div class="bar">
  <span><i class="bi bi-file-earmark-text"></i> Relatório de Inventário</span>
  <span class="sp"></span>
  <a href="<?= $soCat ? 'inventario_glpi.php?cat=' . $H($soCat) : 'inventario.php' ?>"><i class="bi bi-arrow-left"></i> Voltar</a>
  <button onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / PDF</button>
</div>

<div class="doc">
  <h1>Inventário<?= $view === 'baixados' ? ' — Equipamentos baixados' : '' ?></h1>
  <div class="meta">
    Gerado em <?= $hoje ?> · <?= $totalItens ?> item<?= $totalItens == 1 ? '' : 's' ?>
    <?= count($blocos) === 1 ? '' : ' em ' . count($blocos) . ' categorias' ?>
  </div>

  <?php foreach ($blocos as $b): $c = $b['card']; ?>
  <div class="bloco">
    <h2><i class="bi <?= $H($c['icone']) ?>"></i> <?= $H($c['titulo']) ?></h2>
    <div class="btot"><?= $b['total'] ?> item<?= $b['total'] == 1 ? '' : 's' ?></div>

    <?php if (!$b['total']): ?>
      <div class="vazio">Nenhum item.</div>
    <?php else: foreach ($b['porLoja'] as $loja => $rows): ?>
      <div class="loja"><?= $H($loja) ?> — <?= count($rows) ?></div>
      <table>
        <thead><tr>
          <th>Nome</th><th>Subcategoria</th><th>Fabricante</th><th>Modelo</th>
          <?php foreach ($b['fields'] as $f): ?><th><?= $H($f['label']) ?></th><?php endforeach; ?>
          <th><?= $c['fonte'] === 'phone' ? 'Nº linha' : 'Série' ?></th><th>Patrimônio</th>
          <?php if ($view === 'baixados'): ?><th>Motivo</th><th>Data</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= $H($r['name'] ?: '(sem nome)') ?></td>
            <td><?= $H($r['subcategoria'] ?: '—') ?></td>
            <td><?= $H($r['fabricante'] ?: '—') ?></td>
            <td><?= $H($r['modelo'] ?: '—') ?></td>
            <?php foreach ($b['fields'] as $f):
                $v = $b['vals'][(int)$r['id']][(int)$f['id']] ?? '';
                if ($f['tipo'] === 'checkbox') $v = ($v === '1') ? 'Sim' : ($v === '' ? '' : 'Não'); ?>
              <td><?= $H($v !== '' ? $v : '—') ?></td>
            <?php endforeach; ?>
            <td><?= $H($r['serial'] ?: '—') ?></td>
            <td><?= $H($r['otherserial'] ?: '—') ?></td>
            <?php if ($view === 'baixados'): ?>
              <td><?= $H($MOT[$r['baixa_motivo']] ?? $r['baixa_motivo']) ?></td>
              <td><?= $H($r['baixa_data'] ?: '—') ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($autoImp): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script><?php endif; ?>
</body>
</html>
