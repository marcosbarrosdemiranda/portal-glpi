<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/alertas_tipos.php';   // já puxa alertas_lib.php + entidade_alias.php

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gb($mb) { $n = (float)$mb; return $n >= 1024 ? round($n/1024, $n>=10240?0:1).' GB' : round($n).' MB'; }

/**
 * Roda os tipos ATIVOS do catálogo e devolve, por tipo, o count e o innerHTML
 * da seção — pra carga inicial e pro ?action=dados ficarem idênticos.
 */
function alertas_carregar(PDO $pdo): array
{
    $secoes = [];
    foreach (alertas_catalogo() as $slug => $def) {
        $cfg = alertas_config_do_tipo($pdo, $slug);
        if (!$cfg['ativo']) continue;
        try {
            $ocorr = call_user_func($def['check'], $pdo, $cfg['params']);
        } catch (\Throwable $e) {
            $ocorr = [];
        }
        $secoes[] = [
            'slug'  => $slug,
            'nome'  => $def['nome'],
            'icone' => $def['icone'],
            'cor'   => $def['cor'],
            'n'     => count($ocorr),
            'html'  => call_user_func($def['render'], $ocorr),
        ];
    }
    $total = (int) $pdo->query("SELECT COUNT(*) FROM glpi_computers WHERE is_deleted=0 AND is_template=0")->fetchColumn();
    return ['secoes' => $secoes, 'total' => $total];
}

$dados = alertas_carregar($pdo);

// ── Endpoint AJAX do auto-refresh / botão Atualizar ──
if (($_GET['action'] ?? '') === 'dados') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'     => true,
        'hora'   => date('H:i:s'),
        'total'  => $dados['total'],
        'secoes' => array_map(fn($s) => [
            'slug' => $s['slug'], 'nome' => $s['nome'], 'icone' => $s['icone'],
            'cor' => $s['cor'], 'n' => $s['n'], 'html' => $s['html'],
        ], $dados['secoes']),
    ]);
    exit;
}

// perfil restrito sem 'notificacoes_config' não vê o link de configuração
$podeConfig = !isset($_SESSION['portal_perfil_cards']) || $_SESSION['portal_perfil_cards'] === null
              || isset($_SESSION['portal_perfil_cards']['notificacoes_config']);
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
    <?php if ($podeConfig): ?>
      <a href="alertas_config.php"><i class="bi bi-gear me-1"></i>Configurar alertas</a>
    <?php endif; ?>
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
    <?php foreach ($dados['secoes'] as $s): ?>
    <div class="stat"><div class="l"><?= h($s['nome']) ?></div><div class="n" id="n-<?= h($s['slug']) ?>"><?= $s['n'] ?></div></div>
    <?php endforeach; ?>
    <div class="stat"><div class="l">Total de máquinas</div><div class="n" id="n-total"><?= $dados['total'] ?></div></div>
  </div>

  <div class="att-linha">
    <i class="bi bi-clock-history"></i>
    <span id="attTxt">atualizado às <?= date('H:i:s') ?></span>
  </div>

  <?php foreach ($dados['secoes'] as $s): ?>
  <div class="sec" id="sec-<?= h($s['slug']) ?>">
    <div class="sec-h"><i class="bi <?= h($s['icone']) ?> text-<?= h($s['cor']) ?>"></i> <?= h($s['nome']) ?>
      <span class="badge bg-<?= h($s['cor'] === 'warning' ? 'warning text-dark' : $s['cor']) ?>" id="badge-<?= h($s['slug']) ?>"><?= $s['n'] ?></span></div>
    <div class="sec-b" id="body-<?= h($s['slug']) ?>"><?= $s['html'] ?></div>
  </div>
  <?php endforeach; ?>

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

      (d.secoes || []).forEach(function (s) {
        var body = document.getElementById('body-' + s.slug);
        if (body) body.innerHTML = s.html;
        setNum('badge-' + s.slug, s.n);
        setNum('n-' + s.slug, s.n);
      });
      setNum('n-total', d.total);
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
