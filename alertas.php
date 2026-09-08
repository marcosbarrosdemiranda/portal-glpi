<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';
require_once __DIR__ . '/alertas_lib.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gb($mb) { $n = (float)$mb; return $n >= 1024 ? round($n/1024, $n>=10240?0:1).' GB' : round($n).' MB'; }

/* ─────────── Alertas que já dá pra detectar hoje (dados do GLPI) ─────────── */
const ALERTA_INV_DIAS  = 7;
const ALERTA_DISCO_PCT = 90;   // volume acima disso = alerta

/**
 * Lê os alertas ao vivo do GLPI. Usado tanto na carga da página quanto no
 * endpoint ?action=dados (auto-refresh). Retorna dados + fragmentos HTML já
 * renderizados, pra tela e AJAX ficarem sempre idênticos.
 */
function alertas_carregar(PDO $pdo): array
{
    $semInv     = alertas_sem_inventario($pdo, ALERTA_INV_DIAS);
    $discoCheio = alertas_disco_cheio($pdo, ALERTA_DISCO_PCT);

    // agrupa "sem inventário" por loja
    $semInvPorLoja = [];
    foreach ($semInv as $m) {
        $l = apelido_entidade($m['loja'] ?? '') ?: 'Sem loja';
        $semInvPorLoja[$l][] = $m;
    }
    ksort($semInvPorLoja, SORT_NATURAL | SORT_FLAG_CASE);

    $totalAtivos = (int)$pdo->query("SELECT COUNT(*) FROM glpi_computers WHERE is_deleted=0 AND is_template=0")->fetchColumn();
    $pctSemInv   = $totalAtivos ? round(count($semInv) / $totalAtivos * 100) : 0;

    return [
        'sem_inv'       => $semInv,
        'disco'         => $discoCheio,
        'sem_inv_loja'  => $semInvPorLoja,
        'total'         => $totalAtivos,
        'pct_sem_inv'   => $pctSemInv,
        'sem_inv_html'  => render_sem_inv_body($semInvPorLoja),
        'disco_html'    => render_disco_body($discoCheio),
    ];
}

/** innerHTML do corpo da seção "sem inventário" (agrupado por loja). */
function render_sem_inv_body(array $semInvPorLoja): string
{
    if (!$semInvPorLoja) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Todo o parque reportou nos últimos ' . ALERTA_INV_DIAS . ' dias.</div>';
    }
    $out = '';
    foreach ($semInvPorLoja as $loja => $maquinas) {
        $out .= '<div class="loja-h"><i class="bi bi-shop"></i> ' . h($loja)
              . ' <span style="color:#9ca3af;font-weight:400">(' . count($maquinas) . ')</span></div><table><tbody>';
        foreach ($maquinas as $m) {
            $nunca = empty($m['last_inventory_update']) || $m['last_inventory_update'][0] === '0';
            $dias  = $nunca ? null : (int)floor((time() - strtotime($m['last_inventory_update'])) / 86400);
            $pill  = $nunca
                ? '<span class="pill pill-red">nunca reportou</span>'
                : '<span class="pill pill-amber">' . $dias . ' dias (' . h(substr($m['last_inventory_update'], 0, 10)) . ')</span>';
            $out .= '<tr><td style="font-weight:600">' . h($m['name'] ?: '(sem nome)') . '</td>'
                  . '<td style="color:#6b7280">' . h($m['cat']) . '</td>'
                  . '<td style="text-align:right">' . $pill . '</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out;
}

/** innerHTML do corpo da seção "discos quase cheios". */
function render_disco_body(array $discoCheio): string
{
    if (!$discoCheio) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nenhum volume acima de ' . ALERTA_DISCO_PCT . '%.</div>';
    }
    $out = '<table><thead><tr><th>Máquina</th><th>Loja</th><th>Volume</th><th>Uso</th></tr></thead><tbody>';
    foreach ($discoCheio as $d) {
        $pct = (int)$d['pct'];
        $out .= '<tr><td style="font-weight:600">' . h($d['name']) . '</td>'
              . '<td style="color:#6b7280">' . h(apelido_entidade($d['loja'] ?? '') ?: '—') . '</td>'
              . '<td>' . h($d['volume']) . '</td>'
              . '<td><span class="bar"><span style="width:' . $pct . '%"></span></span>'
              . $pct . '% · ' . gb($d['totalsize'] - $d['freesize']) . ' / ' . gb($d['totalsize']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}

$dados = alertas_carregar($pdo);
$semInv        = $dados['sem_inv'];
$discoCheio    = $dados['disco'];
$semInvPorLoja = $dados['sem_inv_loja'];
$totalAtivos   = $dados['total'];
$pctSemInv     = $dados['pct_sem_inv'];

// ── Endpoint AJAX do auto-refresh / botão Atualizar ──
if (($_GET['action'] ?? '') === 'dados') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'           => true,
        'hora'         => date('H:i:s'),
        'stats'        => [
            'sem_inv' => count($semInv),
            'disco'   => count($discoCheio),
            'total'   => $totalAtivos,
            'pct'     => $pctSemInv,
        ],
        'sem_inv_html' => $dados['sem_inv_html'],
        'disco_html'   => $dados['disco_html'],
    ]);
    exit;
}
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
    .btn-refresh { color:#fff; background:rgba(255,255,255,.15); border:none; border-radius:6px;
                   font-size:.82rem; padding:.3rem .75rem; cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; }
    .btn-refresh:disabled { opacity:.55; cursor:default; }
    .btn-refresh .bi { transition:transform .3s; }
    .btn-refresh.loading .bi { animation:girar .8s linear infinite; }
    @keyframes girar { to { transform:rotate(360deg); } }
    .att-linha { font-size:.75rem; color:#6b7280; margin:-.6rem 0 1rem; display:flex; align-items:center; gap:.4rem; }
    .flash { animation:flash 1s ease; }
    @keyframes flash { 0%{ background:#fff9c4; } 100%{ background:transparent; } }
    .stat .n { border-radius:6px; padding:0 .2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-bell-fill"></i> Central de Alertas</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <button type="button" id="btnAtualizar" class="btn-refresh" onclick="atualizarAlertas(true)">
      <i class="bi bi-arrow-clockwise"></i> Atualizar
    </button>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-bell-fill me-2"></i>Central de Alertas</h1>
  <p>O que precisa de atenção no parque — hoje a partir dos dados do inventário do GLPI</p>
</div>

<div class="wrap">
  <div class="stats">
    <div class="stat"><div class="l">Sem inventário +<?= ALERTA_INV_DIAS ?>d</div><div class="n" id="n-sem-inv" style="color:var(--alert)"><?= count($semInv) ?></div><div style="font-size:.78rem;color:#6b7280"><span id="pct-sem-inv"><?= $pctSemInv ?></span>% do parque</div></div>
    <div class="stat"><div class="l">Discos quase cheios</div><div class="n" id="n-disco" style="color:#b45309"><?= count($discoCheio) ?></div><div style="font-size:.78rem;color:#6b7280">volume ≥ <?= ALERTA_DISCO_PCT ?>%</div></div>
    <div class="stat"><div class="l">Total de máquinas</div><div class="n" id="n-total"><?= $totalAtivos ?></div></div>
  </div>

  <div class="att-linha">
    <i class="bi bi-clock-history"></i>
    <span id="attTxt">atualizado às <?= date('H:i:s') ?></span>
  </div>

  <!-- Sem inventário -->
  <div class="sec">
    <div class="sec-h"><i class="bi bi-wifi-off text-danger"></i> Máquinas sem reportar inventário
      <span class="badge bg-danger" id="badge-sem-inv"><?= count($semInv) ?></span></div>
    <div class="sec-b" id="body-sem-inv"><?= $dados['sem_inv_html'] ?></div>
  </div>

  <!-- Disco cheio -->
  <div class="sec">
    <div class="sec-h"><i class="bi bi-hdd-fill text-warning"></i> Discos quase cheios
      <span class="badge bg-warning text-dark" id="badge-disco"><?= count($discoCheio) ?></span></div>
    <div class="sec-b" id="body-disco"><?= $dados['disco_html'] ?></div>
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

<script>
(function () {
  const REFRESH_MS = 45000;
  const btn   = document.getElementById('btnAtualizar');
  const attTxt = document.getElementById('attTxt');
  let auto = null;

  // pisca o valor se mudou
  function setNum(id, valor) {
    const el = document.getElementById(id);
    if (!el) return;
    const novo = String(valor);
    if (el.textContent.trim() !== novo) {
      el.textContent = novo;
      el.classList.remove('flash');
      void el.offsetWidth;         // reinicia a animação
      el.classList.add('flash');
    }
  }

  async function atualizarAlertas(manual) {
    if (btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('loading');
    try {
      // auto-refresh nao renova a sessao de inatividade (convencao do auth_guard)
      const url = 'alertas.php?action=dados' + (manual ? '' : '&bg=1');
      const r = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
      if (r.status === 440 || r.status === 401) { pararAuto('Sessão expirada — recarregue a página (F5).'); return; }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const d = await r.json();
      if (!d || !d.ok) throw new Error('resposta inválida');

      document.getElementById('body-sem-inv').innerHTML = d.sem_inv_html;
      document.getElementById('body-disco').innerHTML   = d.disco_html;
      setNum('n-sem-inv',  d.stats.sem_inv);
      setNum('badge-sem-inv', d.stats.sem_inv);
      setNum('pct-sem-inv', d.stats.pct);
      setNum('n-disco',    d.stats.disco);
      setNum('badge-disco', d.stats.disco);
      setNum('n-total',    d.stats.total);
      attTxt.textContent = 'atualizado às ' + d.hora;
    } catch (e) {
      attTxt.textContent = 'falha ao atualizar (' + e.message + ') — tentando de novo';
    } finally {
      btn.disabled = false;
      btn.classList.remove('loading');
    }
  }
  window.atualizarAlertas = atualizarAlertas;

  function pararAuto(msg) {
    if (auto) { clearInterval(auto); auto = null; }
    btn.disabled = true;
    if (msg) attTxt.textContent = msg;
  }

  // auto-refresh 45s; pausa quando a aba nao esta visivel
  function iniciarAuto() {
    if (auto) return;
    auto = setInterval(() => { if (!document.hidden) atualizarAlertas(false); }, REFRESH_MS);
  }
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) atualizarAlertas(false);   // volta pra aba -> atualiza na hora
  });
  iniciarAuto();
})();
</script>
</body>
</html>
