<?php
/**
 * inventario_impressoras.php — cadastro e status de impressoras de rede
 * (SNMP). Mesmo padrão visual/estrutural de inventario_redes.php.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/impressoras_lib.php';
require_once __DIR__ . '/wpp/glpi_bot.php'; // bot_lojas() - mesma lista de lojas usada no picker do chatbot

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'super-admin', 'tecnico']);
$H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'lojas') {
        echo json_encode(['ok' => true, 'lojas' => bot_lojas()]);
        exit;
    }

    if ($action === 'listar') {
        $lista = [];
        foreach (impressora_listar($pdo) as $imp) {
            $status = impressora_status_atual($pdo, (int) $imp['id']);
            $lista[] = ['impressora' => $imp, 'status' => $status];
        }
        echo json_encode(['ok' => true, 'lista' => $lista]);
        exit;
    }

    if ($action === 'historico') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id invalido']); exit; }
        echo json_encode(['ok' => true, 'historico' => impressora_historico_paginas($pdo, $id, 90)]);
        exit;
    }

    if (!$is_admin) { echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }

    if ($action === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $id        = (int) ($_POST['id'] ?? 0);
        $ip        = trim((string) ($_POST['ip'] ?? ''));
        $apelido   = trim((string) ($_POST['apelido'] ?? ''));
        $loja      = trim((string) ($_POST['loja'] ?? ''));
        $comunidade = trim((string) ($_POST['comunidade'] ?? '')) ?: 'public';
        if ($ip === '' || $apelido === '') { echo json_encode(['ok' => false, 'erro' => 'ip e apelido sao obrigatorios']); exit; }
        try {
            if ($id > 0) {
                impressora_editar($pdo, $id, $ip, $apelido, $loja, $comunidade);
            } else {
                impressora_cadastrar($pdo, $ip, $apelido, $loja, $comunidade);
            }
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao salvar']);
        }
        exit;
    }

    if ($action === 'excluir') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id invalido']); exit; }
        impressora_excluir($pdo, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'acao desconhecida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Impressoras</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
  <style>
    :root { --primary:#e91e63; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#ad1457); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:1100px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    .imp-card { border:1px solid #e5e7eb; border-radius:10px; padding:.9rem 1rem; margin-bottom:.75rem; }
    .imp-card .status-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:.4rem; }
    .status-on  { background:#22c55e; }
    .status-off { background:#ef4444; }
    .consumivel-bar { background:#e5e7eb; border-radius:6px; height:8px; overflow:hidden; width:120px; display:inline-block; vertical-align:middle; }
    .consumivel-fill { height:100%; background:#e91e63; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-printer"></i> Inventário — Impressoras</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="inventario.php"><i class="bi bi-arrow-left me-1"></i>Inventário</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <?php if ($is_admin): ?>
  <div class="card-box">
    <h6 class="mb-2">Cadastrar impressora</h6>
    <div class="d-flex gap-2 flex-wrap align-items-end">
      <div><label class="form-label small mb-0">IP</label>
        <input type="text" id="f-ip" class="form-control form-control-sm" style="width:140px" placeholder="10.0.0.50"></div>
      <div><label class="form-label small mb-0">Apelido</label>
        <input type="text" id="f-apelido" class="form-control form-control-sm" style="width:180px"></div>
      <div><label class="form-label small mb-0">Loja</label>
        <select id="f-loja" class="form-select form-select-sm" style="width:160px"><option value="">Carregando…</option></select></div>
      <div><label class="form-label small mb-0">Comunidade SNMP</label>
        <input type="text" id="f-comunidade" class="form-control form-control-sm" style="width:130px" value="public" title="Deixe 'public' — é o padrão de quase toda impressora, só muda se o time de rede configurou outra senha">
        <div class="form-text" style="font-size:.7rem">deixe "public" se não souber</div></div>
      <input type="hidden" id="f-id" value="0">
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarImpressora()">Salvar</button>
    </div>
    <div id="fb-form" class="small mt-2"></div>
  </div>
  <?php endif; ?>

  <div class="card-box">
    <h6 class="mb-2">Impressoras</h6>
    <div id="lista">Carregando…</div>
  </div>

  <div class="card-box d-none" id="card-historico">
    <h6 class="mb-2">Histórico de páginas — <span id="hist-nome"></span></h6>
    <div id="chart-historico"></div>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Impressoras</footer>

<script>
function $(id) { return document.getElementById(id); }
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

function barraConsumivel(c) {
  if (c.nivel === null) return c.nome + ': <span class="text-muted">sem dado</span>';
  const pct = Math.max(0, Math.min(100, Math.round((c.nivel / c.max) * 100)));
  const cor = pct < 15 ? '#ef4444' : (pct < 30 ? '#f59e0b' : '#e91e63');
  return c.nome + ': <span class="consumivel-bar"><span class="consumivel-fill" style="width:' + pct + '%;background:' + cor + '"></span></span> ' + pct + '%';
}

function linhaImpressora(item) {
  const imp = item.impressora, st = item.status;
  const div = document.createElement('div');
  div.className = 'imp-card';
  const online = st && st.online == 1;
  let html = '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">';
  html += '<div><span class="status-dot ' + (online ? 'status-on' : 'status-off') + '"></span>';
  html += '<b>' + imp.apelido + '</b> <span class="text-muted small">(' + imp.ip + ' · ' + (imp.loja || 'sem loja') + ')</span><br>';
  if (st) {
    html += '<span class="small text-muted">' + (st.modelo || 'modelo desconhecido') + (st.serial ? ' · S/N ' + st.serial : '') + '</span><br>';
    html += '<span class="small">Páginas: ' + (st.paginas_total !== null ? st.paginas_total : '—') + '</span><br>';
    (st.consumiveis || []).forEach(function (c) { html += '<div class="small mt-1">' + barraConsumivel(c) + '</div>'; });
  } else {
    html += '<span class="small text-muted">ainda sem leitura</span>';
  }
  html += '</div><div class="d-flex gap-2">';
  html += '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="verHistorico(' + imp.id + ', \'' + imp.apelido.replace(/'/g, "\\'") + '\')">Histórico</button>';
  if (IS_ADMIN) {
    html += '<button type="button" class="btn btn-outline-primary btn-sm" onclick="editarImpressora(' + imp.id + ', \'' + imp.ip + '\', \'' + imp.apelido.replace(/'/g, "\\'") + '\', \'' + (imp.loja || '').replace(/'/g, "\\'") + '\', \'' + imp.comunidade + '\')">Editar</button>';
    html += '<button type="button" class="btn btn-outline-danger btn-sm" onclick="excluirImpressora(' + imp.id + ')">Excluir</button>';
  }
  html += '</div></div>';
  div.innerHTML = html;
  return div;
}

function carregarLojas() {
  fetch('inventario_impressoras.php?action=lojas').then(function (r) { return r.json(); }).then(function (d) {
    const sel = $('f-loja');
    if (!sel) return;
    sel.innerHTML = '<option value="">selecione…</option>';
    (d.lojas || []).forEach(function (l) { sel.appendChild(new Option(l.nome, l.nome)); });
  });
}

function carregarLista() {
  fetch('inventario_impressoras.php?action=listar').then(function (r) { return r.json(); }).then(function (d) {
    const lista = $('lista');
    lista.innerHTML = '';
    if (!d.ok || !d.lista.length) { lista.innerHTML = '<div class="text-muted small">Nenhuma impressora cadastrada.</div>'; return; }
    d.lista.forEach(function (item) { lista.appendChild(linhaImpressora(item)); });
  });
}

function editarImpressora(id, ip, apelido, loja, comunidade) {
  $('f-id').value = id; $('f-ip').value = ip; $('f-apelido').value = apelido;
  $('f-loja').value = loja; $('f-comunidade').value = comunidade;
}

function salvarImpressora() {
  const params = new URLSearchParams({
    id: $('f-id').value, ip: $('f-ip').value, apelido: $('f-apelido').value,
    loja: $('f-loja').value, comunidade: $('f-comunidade').value,
  });
  fetch('inventario_impressoras.php?action=salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      const fb = $('fb-form');
      if (!d.ok) { fb.className = 'small mt-2 text-danger'; fb.textContent = d.erro || 'erro ao salvar'; return; }
      fb.className = 'small mt-2 text-success'; fb.textContent = 'salvo.';
      $('f-id').value = 0; $('f-ip').value = ''; $('f-apelido').value = ''; $('f-loja').value = ''; $('f-comunidade').value = 'public';
      carregarLista();
    });
}

function excluirImpressora(id) {
  if (!confirm('Excluir esta impressora do cadastro?')) return;
  const params = new URLSearchParams({ id: id });
  fetch('inventario_impressoras.php?action=excluir', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); }).then(function () { carregarLista(); });
}

let chartHistorico = null;
function verHistorico(id, apelido) {
  fetch('inventario_impressoras.php?action=historico&id=' + id).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) return;
    $('card-historico').classList.remove('d-none');
    $('hist-nome').textContent = apelido;
    const categorias = d.historico.map(function (h) { return h.registrado_em; });
    const valores = d.historico.map(function (h) { return h.paginas_total; });
    if (chartHistorico) chartHistorico.destroy();
    chartHistorico = new ApexCharts($('chart-historico'), {
      chart: { type: 'area', height: 260 },
      series: [{ name: 'Páginas (contador total)', data: valores }],
      xaxis: { categories: categorias, labels: { rotate: -45 } },
      colors: ['#e91e63'],
    });
    chartHistorico.render();
  });
}

carregarLojas();
carregarLista();
</script>
</body>
</html>
