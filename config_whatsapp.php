<?php
/**
 * Configuração do WhatsApp (Fase 1)
 * Duas abas:
 *   - Conexão: parear a linha do TI via QR code (Evolution API) e desconectar.
 *   - Grupos:  escolher qual grupo do WhatsApp recebe Alertas e qual recebe Chamados.
 * Sem envio automático de nada nesta fase — a tela só lê status / mostra QR / lista e salva grupos.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/wpp/db.php';
require_once __DIR__ . '/wpp/evo_api.php';

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ─────────── Handlers AJAX (antes de qualquer HTML) ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    switch ($action) {
        case 'status':
            echo json_encode(evo_status());
            break;
        case 'ensure':
            echo json_encode(evo_ensure_instance());
            break;
        case 'qr':
            echo json_encode(evo_qr());
            break;
        case 'logout':
            echo json_encode(evo_logout());
            break;
        case 'groups':
            echo json_encode(evo_groups());
            break;
        case 'save_groups':
            $a = trim($_POST['alertas_jid'] ?? '');
            $c = trim($_POST['chamados_jid'] ?? '');
            // aceita só JID de grupo (@g.us) ou string vazia
            foreach (['alertas' => $a, 'chamados' => $c] as $k => $v) {
                if ($v !== '' && !str_ends_with($v, '@g.us')) {
                    echo json_encode(['ok' => false, 'erro' => "JID de $k inválido"]);
                    exit;
                }
            }
            wpp_cfg_set('grupo_alertas_jid', $a);
            wpp_cfg_set('grupo_chamados_jid', $c);
            echo json_encode(['ok' => true]);
            break;
        default:
            echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    }
    exit;
}

$alertas_jid  = wpp_cfg_get('grupo_alertas_jid', '');
$chamados_jid = wpp_cfg_get('grupo_chamados_jid', '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Configuração do WhatsApp</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --wpp:#25d366; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15);
                border-radius:6px; padding:.3rem .75rem; margin-left:.4rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p { opacity:.8; margin-top:.5rem; font-size:.95rem; }
    .wrap { max-width:760px; margin:-3rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06);
                overflow:hidden; }
    .nav-tabs { padding:0 1rem; border-bottom:1px solid #eef1f5; }
    .nav-tabs .nav-link { border:0; color:#6b7280; font-weight:600; font-size:.9rem; padding:.9rem 1rem; cursor:pointer; }
    .nav-tabs .nav-link.active { color:var(--primary); border-bottom:3px solid var(--primary); background:transparent; }
    .tab-body { padding:1.5rem; }
    .state-line { display:flex; align-items:center; gap:.5rem; font-weight:700; font-size:.95rem; margin-bottom:1rem; }
    .dot { width:12px; height:12px; border-radius:50%; display:inline-block; background:#9ca3af; }
    .dot.open { background:#16a34a; } .dot.connecting { background:#f59e0b; } .dot.close { background:#e53935; }
    #qr-wrap { text-align:center; margin-top:1rem; }
    #qr-wrap img { width:260px; height:260px; border:1px solid #e5e7eb; border-radius:8px; padding:.4rem; background:#fff; }
    .feedback { border-radius:8px; padding:.6rem .9rem; font-size:.85rem; margin-top:1rem; display:none; }
    .feedback.ok { background:#f0fdf4; border:1px solid #22c55e; color:#166534; display:block; }
    .feedback.err { background:#fef2f2; border:1px solid #ef4444; color:#991b1b; display:block; }
    .feedback.info { background:#eff6ff; border:1px solid #3b82f6; color:#1e40af; display:block; }
    .aviso { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:8px; padding:.6rem .9rem;
             font-size:.85rem; margin-bottom:1rem; }
    label.form-label { font-weight:600; font-size:.85rem; color:#374151; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-whatsapp"></i> Configuração do WhatsApp</div>
  <div>
    <a href="config_notificacoes.php"><i class="bi bi-arrow-left me-1"></i>Configuração</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-whatsapp me-2"></i>Configuração do WhatsApp</h1>
  <p>Pareie a linha do TI e escolha os grupos de Alertas e Chamados</p>
</div>

<div class="wrap">
  <div class="card-box">
    <ul class="nav nav-tabs" id="wpp-tabs">
      <li class="nav-item"><span class="nav-link active" data-tab="conexao"><i class="bi bi-qr-code me-1"></i>Conexão</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="grupos"><i class="bi bi-people me-1"></i>Grupos</span></li>
    </ul>

    <!-- ─────────── Aba Conexão ─────────── -->
    <div class="tab-body" id="tab-conexao">
      <div class="state-line">
        <span class="dot" id="state-dot"></span>
        <span id="state-text">Verificando…</span>
      </div>

      <button class="btn btn-success btn-sm" id="btn-conectar" style="background:var(--wpp);border-color:var(--wpp)">
        <i class="bi bi-qr-code me-1"></i>Conectar / mostrar QR
      </button>
      <button class="btn btn-outline-danger btn-sm" id="btn-desconectar" style="display:none">
        <i class="bi bi-box-arrow-right me-1"></i>Desconectar
      </button>

      <div id="qr-wrap" style="display:none">
        <p class="small text-muted mb-2">Abra o WhatsApp no celular da linha do TI → Aparelhos conectados → Conectar um aparelho e aponte para o código.</p>
        <img id="qr-img" alt="QR code"/>
      </div>

      <div class="feedback" id="fb-conexao"></div>
    </div>

    <!-- ─────────── Aba Grupos ─────────── -->
    <div class="tab-body" id="tab-grupos" style="display:none">
      <div class="aviso" id="aviso-conecte" style="display:none">
        <i class="bi bi-exclamation-triangle me-1"></i>Conecte primeiro na aba Conexão. Você pode tentar carregar a lista mesmo assim.
      </div>

      <button class="btn btn-primary btn-sm mb-3" id="btn-recarregar">
        <i class="bi bi-arrow-repeat me-1"></i>Recarregar lista de grupos
      </button>

      <div class="mb-3">
        <label class="form-label" for="sel-alertas">Grupo de Alertas</label>
        <select class="form-select form-select-sm" id="sel-alertas">
          <option value="">— nenhum —</option>
          <?php if ($alertas_jid !== ''): ?>
            <option value="<?= $H($alertas_jid) ?>" selected><?= $H($alertas_jid) ?> (salvo)</option>
          <?php endif; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label" for="sel-chamados">Grupo de Chamados</label>
        <select class="form-select form-select-sm" id="sel-chamados">
          <option value="">— nenhum —</option>
          <?php if ($chamados_jid !== ''): ?>
            <option value="<?= $H($chamados_jid) ?>" selected><?= $H($chamados_jid) ?> (salvo)</option>
          <?php endif; ?>
        </select>
      </div>

      <button class="btn btn-success btn-sm" id="btn-salvar" style="background:var(--wpp);border-color:var(--wpp)">
        <i class="bi bi-save me-1"></i>Salvar
      </button>

      <div class="feedback" id="fb-grupos"></div>
    </div>
  </div>
</div>

<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integração WhatsApp (Fase 1)</footer>

<script>
(function () {
  'use strict';

  var PAGE = 'config_whatsapp.php';
  // JIDs salvos no banco (pré-seleção dos selects)
  var SALVO = {
    alertas:  <?= json_encode($alertas_jid) ?>,
    chamados: <?= json_encode($chamados_jid) ?>
  };
  var estadoAtual = 'desconhecido';
  var pollTimer = null;

  function $(id) { return document.getElementById(id); }

  function feedback(el, tipo, msg) {
    el.className = 'feedback ' + tipo;
    el.textContent = msg;
  }

  /* ─────────── Abas ─────────── */
  document.querySelectorAll('#wpp-tabs .nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      document.querySelectorAll('#wpp-tabs .nav-link').forEach(function (l) { l.classList.remove('active'); });
      link.classList.add('active');
      var alvo = link.getAttribute('data-tab');
      $('tab-conexao').style.display = (alvo === 'conexao') ? '' : 'none';
      $('tab-grupos').style.display  = (alvo === 'grupos')  ? '' : 'none';
      if (alvo === 'grupos') atualizarAvisoGrupos();
    });
  });

  /* ─────────── Conexão ─────────── */
  function pintarEstado(estado) {
    estadoAtual = estado || 'desconhecido';
    var dot = $('state-dot'), txt = $('state-text');
    dot.className = 'dot';
    if (estado === 'open') {
      dot.classList.add('open');
      txt.textContent = 'Conectado';
      $('btn-desconectar').style.display = '';
      $('btn-conectar').style.display = 'none';
      $('qr-wrap').style.display = 'none';
      pararPoll();
    } else if (estado === 'connecting') {
      dot.classList.add('connecting');
      txt.textContent = 'Conectando…';
      $('btn-desconectar').style.display = 'none';
      $('btn-conectar').style.display = '';
    } else {
      dot.classList.add('close');
      txt.textContent = 'Desconectado';
      $('btn-desconectar').style.display = 'none';
      $('btn-conectar').style.display = '';
    }
    atualizarAvisoGrupos();
  }

  function carregarStatus() {
    fetch(PAGE + '?action=status')
      .then(function (r) { return r.json(); })
      .then(function (d) { pintarEstado(d.estado); })
      .catch(function () { pintarEstado('desconhecido'); });
  }

  function conectar() {
    var btn = $('btn-conectar');
    btn.disabled = true;
    feedback($('fb-conexao'), 'info', 'Preparando instância…');
    fetch(PAGE + '?action=ensure')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao preparar instância'); }
        feedback($('fb-conexao'), 'info', 'Gerando QR code…');
        return fetch(PAGE + '?action=qr').then(function (r) { return r.json(); });
      })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok || !d.base64) { throw new Error(d.erro || 'QR indisponível — talvez já esteja conectado'); }
        var src = d.base64.indexOf('data:') === 0 ? d.base64 : 'data:image/png;base64,' + d.base64;
        $('qr-img').src = src;
        $('qr-wrap').style.display = '';
        feedback($('fb-conexao'), 'info', 'Escaneie o QR com a linha do TI. A tela atualiza sozinha ao conectar.');
        iniciarPoll();
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  function iniciarPoll() {
    pararPoll();
    pollTimer = setInterval(pollStatus, 3000);
  }
  function pararPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }
  function pollStatus() {
    fetch(PAGE + '?action=status')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.estado === 'open') {
          feedback($('fb-conexao'), 'ok', 'Conectado com sucesso.');
          pintarEstado('open');
        } else {
          pintarEstado(d.estado);
        }
      })
      .catch(function () {});
  }

  function desconectar() {
    if (!confirm('Desconectar a linha do WhatsApp? Será preciso escanear o QR de novo para reconectar.')) { return; }
    var btn = $('btn-desconectar');
    btn.disabled = true;
    fetch(PAGE + '?action=logout')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.ok) {
          feedback($('fb-conexao'), 'ok', 'Desconectado.');
          pintarEstado('close');
        } else {
          feedback($('fb-conexao'), 'err', 'Erro: ' + (d.erro || 'falha ao desconectar'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  /* ─────────── Grupos ─────────── */
  function atualizarAvisoGrupos() {
    var aviso = $('aviso-conecte');
    if (aviso) { aviso.style.display = (estadoAtual === 'open') ? 'none' : ''; }
  }

  function preencherSelect(sel, grupos, salvo) {
    var atual = sel.value || salvo || '';
    sel.innerHTML = '<option value="">— nenhum —</option>';
    var achouSalvo = false;
    grupos.forEach(function (g) {
      var opt = document.createElement('option');
      opt.value = g.jid;
      opt.textContent = g.nome + (g.tamanho ? ' (' + g.tamanho + ')' : '');
      if (g.jid === atual) { opt.selected = true; achouSalvo = true; }
      sel.appendChild(opt);
    });
    // Mantém o JID salvo como opção mesmo se não veio na lista (linha desconectada, etc.)
    if (atual && !achouSalvo) {
      var opt2 = document.createElement('option');
      opt2.value = atual;
      opt2.textContent = atual + ' (salvo)';
      opt2.selected = true;
      sel.appendChild(opt2);
    }
  }

  function carregarGrupos() {
    var btn = $('btn-recarregar');
    btn.disabled = true;
    feedback($('fb-grupos'), 'info', 'Carregando grupos…');
    fetch(PAGE + '?action=groups')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { throw new Error(d.erro || 'falha ao listar grupos'); }
        var grupos = d.grupos || [];
        preencherSelect($('sel-alertas'), grupos, SALVO.alertas);
        preencherSelect($('sel-chamados'), grupos, SALVO.chamados);
        feedback($('fb-grupos'), 'ok', grupos.length + ' grupo(s) carregado(s).');
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-grupos'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  function salvarGrupos() {
    var btn = $('btn-salvar');
    btn.disabled = true;
    feedback($('fb-grupos'), 'info', 'Salvando…');
    var body = new URLSearchParams({
      alertas_jid:  $('sel-alertas').value,
      chamados_jid: $('sel-chamados').value
    });
    fetch(PAGE + '?action=save_groups', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.ok) {
          SALVO.alertas  = $('sel-alertas').value;
          SALVO.chamados = $('sel-chamados').value;
          feedback($('fb-grupos'), 'ok', 'Grupos salvos.');
        } else {
          feedback($('fb-grupos'), 'err', 'Erro: ' + (d.erro || 'falha ao salvar'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-grupos'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  /* ─────────── Ligações ─────────── */
  $('btn-conectar').addEventListener('click', conectar);
  $('btn-desconectar').addEventListener('click', desconectar);
  $('btn-recarregar').addEventListener('click', carregarGrupos);
  $('btn-salvar').addEventListener('click', salvarGrupos);

  carregarStatus();
})();
</script>
</body>
</html>
