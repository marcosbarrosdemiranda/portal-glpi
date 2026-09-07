<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ─────────── Alertas que já dá pra detectar hoje (dados do GLPI) ─────────── */
const ALERTA_INV_DIAS  = 7;
const ALERTA_DISCO_PCT = 90;   // volume acima disso = alerta

// 1) Máquinas sem reportar inventário há +7 dias (ou nunca)
$semInv = $pdo->query("
    SELECT c.name, c.last_inventory_update, e.completename AS loja,
           COALESCE(pc.categoria,'pcs-retaguarda') AS cat
    FROM glpi_computers c
    LEFT JOIN glpi_entities e ON e.id = c.entities_id
    LEFT JOIN portal_inv_pc_cat pc ON pc.computer_id = c.id
    LEFT JOIN portal_inv_baixas bx ON bx.itemtype='Computer' AND bx.items_id = c.id
    WHERE c.is_deleted = 0 AND c.is_template = 0 AND bx.id IS NULL
      AND (COALESCE(pc.categoria,'') <> '__ignorado__')
      AND (c.last_inventory_update IS NULL OR c.last_inventory_update < (NOW() - INTERVAL " . ALERTA_INV_DIAS . " DAY))
    ORDER BY c.last_inventory_update IS NULL DESC, c.last_inventory_update ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2) Discos quase cheios — só volumes de dados (> 30 GB); ignora partições de
//    recuperação/sistema (sempre ~99% cheias por natureza).
$discoCheio = $pdo->query("
    SELECT c.name, e.completename AS loja, d.name AS volume,
           d.totalsize, d.freesize,
           ROUND((d.totalsize - d.freesize) / d.totalsize * 100) AS pct
    FROM glpi_items_disks d
    JOIN glpi_computers c ON c.id = d.items_id AND d.itemtype='Computer' AND c.is_deleted = 0
    LEFT JOIN glpi_entities e ON e.id = c.entities_id
    WHERE d.totalsize > 30000
      AND (d.totalsize - d.freesize) / d.totalsize * 100 >= " . ALERTA_DISCO_PCT . "
      AND d.name NOT REGEXP '(?i)(recov|image|reserv|winre|system|efi|pbr|oem)'
    ORDER BY pct DESC
")->fetchAll(PDO::FETCH_ASSOC);

// agrupa "sem inventário" por loja
$semInvPorLoja = [];
foreach ($semInv as $m) {
    $l = apelido_entidade($m['loja'] ?? '') ?: 'Sem loja';
    $semInvPorLoja[$l][] = $m;
}
ksort($semInvPorLoja, SORT_NATURAL | SORT_FLAG_CASE);

$totalAtivos = (int)$pdo->query("SELECT COUNT(*) FROM glpi_computers WHERE is_deleted=0 AND is_template=0")->fetchColumn();
$pctSemInv   = $totalAtivos ? round(count($semInv) / $totalAtivos * 100) : 0;

function gb($mb) { $n = (float)$mb; return $n >= 1024 ? round($n/1024, $n>=10240?0:1).' GB' : round($n).' MB'; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central de Alertas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --alert:#e53935; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p { opacity:.8; margin-top:.5rem; font-size:.95rem; }
    .wrap { max-width:1050px; margin:-3rem auto 3rem; padding:0 1rem; }
    .stats { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1.25rem; }
    .stat { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:.9rem 1.2rem; flex:1; min-width:170px; }
    .stat .n { font-size:1.6rem; font-weight:800; }
    .stat .l { font-size:.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .sec { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:1.25rem; overflow:hidden; }
    .sec-h { padding:.8rem 1.25rem; font-weight:700; display:flex; align-items:center; gap:.5rem; border-bottom:1px solid #eef1f5; }
    .sec-h .badge { margin-left:auto; }
    .sec-b { padding:.5rem 1.25rem 1rem; }
    table { width:100%; border-collapse:collapse; font-size:.85rem; }
    th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:.5rem .4rem; border-bottom:2px solid #eef1f5; }
    td { padding:.45rem .4rem; border-bottom:1px solid #f3f4f6; }
    .loja-h { font-weight:700; color:var(--primary); font-size:.82rem; margin:.8rem 0 .3rem; }
    .pill { font-size:.68rem; font-weight:700; padding:.1rem .5rem; border-radius:8px; }
    .pill-red { background:#ffebee; color:#b71c1c; }
    .pill-amber { background:#fff3e0; color:#b45309; }
    .bar { display:inline-block; width:80px; height:7px; background:#e5e7eb; border-radius:4px; overflow:hidden; vertical-align:middle; margin-right:.4rem; }
    .bar > span { display:block; height:100%; background:var(--alert); }
    .vazio { color:#16a34a; padding:1rem; text-align:center; font-size:.9rem; }
    .futuro { background:#fff; border:1px dashed #c5cae9; border-radius:12px; padding:1rem 1.25rem; font-size:.82rem; color:#5f6368; }
    .futuro b { color:#1f2937; }
    .futuro ul { margin:.4rem 0 0; padding-left:1.1rem; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-bell-fill"></i> Central de Alertas</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>
<div class="hero">
  <h1><i class="bi bi-bell-fill me-2"></i>Central de Alertas</h1>
  <p>O que precisa de atenção no parque — hoje a partir dos dados do inventário do GLPI</p>
</div>

<div class="wrap">
  <div class="stats">
    <div class="stat"><div class="l">Sem inventário +<?= ALERTA_INV_DIAS ?>d</div><div class="n" style="color:var(--alert)"><?= count($semInv) ?></div><div style="font-size:.78rem;color:#6b7280"><?= $pctSemInv ?>% do parque</div></div>
    <div class="stat"><div class="l">Discos quase cheios</div><div class="n" style="color:#b45309"><?= count($discoCheio) ?></div><div style="font-size:.78rem;color:#6b7280">volume ≥ <?= ALERTA_DISCO_PCT ?>%</div></div>
    <div class="stat"><div class="l">Total de máquinas</div><div class="n"><?= $totalAtivos ?></div></div>
  </div>

  <!-- Sem inventário -->
  <div class="sec">
    <div class="sec-h"><i class="bi bi-wifi-off text-danger"></i> Máquinas sem reportar inventário
      <span class="badge bg-danger"><?= count($semInv) ?></span></div>
    <div class="sec-b">
      <?php if (!$semInv): ?>
        <div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Todo o parque reportou nos últimos <?= ALERTA_INV_DIAS ?> dias.</div>
      <?php else: foreach ($semInvPorLoja as $loja => $maquinas): ?>
        <div class="loja-h"><i class="bi bi-shop"></i> <?= $H($loja) ?> <span style="color:#9ca3af;font-weight:400">(<?= count($maquinas) ?>)</span></div>
        <table><tbody>
        <?php foreach ($maquinas as $m):
          $nunca = empty($m['last_inventory_update']) || $m['last_inventory_update'][0] === '0';
          $dias  = $nunca ? null : (int)floor((time() - strtotime($m['last_inventory_update'])) / 86400); ?>
          <tr>
            <td style="font-weight:600"><?= $H($m['name'] ?: '(sem nome)') ?></td>
            <td style="color:#6b7280"><?= $H($m['cat']) ?></td>
            <td style="text-align:right">
              <?php if ($nunca): ?><span class="pill pill-red">nunca reportou</span>
              <?php else: ?><span class="pill pill-amber"><?= $dias ?> dias (<?= $H(substr($m['last_inventory_update'],0,10)) ?>)</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Disco cheio -->
  <div class="sec">
    <div class="sec-h"><i class="bi bi-hdd-fill text-warning"></i> Discos quase cheios
      <span class="badge bg-warning text-dark"><?= count($discoCheio) ?></span></div>
    <div class="sec-b">
      <?php if (!$discoCheio): ?>
        <div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nenhum volume acima de <?= ALERTA_DISCO_PCT ?>%.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Máquina</th><th>Loja</th><th>Volume</th><th>Uso</th></tr></thead>
          <tbody>
          <?php foreach ($discoCheio as $d): ?>
            <tr>
              <td style="font-weight:600"><?= $H($d['name']) ?></td>
              <td style="color:#6b7280"><?= $H(apelido_entidade($d['loja'] ?? '') ?: '—') ?></td>
              <td><?= $H($d['volume']) ?></td>
              <td><span class="bar"><span style="width:<?= (int)$d['pct'] ?>%"></span></span><?= (int)$d['pct'] ?>% · <?= gb($d['totalsize'] - $d['freesize']) ?> / <?= gb($d['totalsize']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="futuro">
    <b><i class="bi bi-cone-striped me-1"></i>Em construção</b> — esta Central vai concentrar todos os alertas:
    <ul>
      <li>Equipamento ligado/desligado, falha de hardware (pente de RAM a menos, disco sumiu)</li>
      <li>Sistemas fora do ar (Checklist G+, controle de horas extras, portal/GLPI)</li>
      <li>VPN de loja caída · loja rodando só no link de backup</li>
      <li>Busca-preço, APs UniFi, painel de LED, TV de ofertas, HD de DVR</li>
      <li>Temperatura de câmara fria / freezer (Home Assistant)</li>
      <li>Cada alerta → grupo do WhatsApp + chamado automático, com regra configurável</li>
    </ul>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI</footer>
</body>
</html>
