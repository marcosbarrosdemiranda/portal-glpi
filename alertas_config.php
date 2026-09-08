<?php
/**
 * alertas_config.php — tela de configuração dos tipos de alerta.
 *
 * Etapa 1 da Central de Alertas configurável. Para cada tipo do catálogo
 * (alertas_tipos.php) permite ligar/desligar, ajustar os parâmetros, escolher
 * se notifica no grupo Alertas do WhatsApp e definir o lembrete em minutos.
 *
 * Só configuração: nada de envio de WhatsApp / abertura de chamado aqui — isso
 * é a Etapa 2. A guarda de perfil roda ANTES do dispatch de ?action= para que
 * as chamadas AJAX (ler/salvar) também fiquem barradas.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/alertas_tipos.php';

// checagem defensiva: perfil restrito sem 'notificacoes_config' -> volta pro dashboard
$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ─────────── Handlers AJAX (antes de qualquer HTML) ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'ler') {
        $tipos = [];
        foreach (alertas_catalogo() as $slug => $def) {
            $cfg = alertas_config_do_tipo($pdo, $slug);
            $schema = [];
            foreach ($def['params'] as $k => $meta) {
                $schema[$k] = ['label' => $meta['label'], 'min' => (int) $meta['min'], 'max' => (int) $meta['max']];
            }
            $tipos[] = [
                'slug'           => $slug,
                'nome'           => $def['nome'],
                'descricao'      => $def['descricao'],
                'icone'          => $def['icone'],
                'cor'            => $def['cor'],
                'params_schema'  => $schema,
                'ativo'          => $cfg['ativo'] ? 1 : 0,
                'params'         => $cfg['params'],
                'notif_whatsapp' => $cfg['notif_whatsapp'] ? 1 : 0,
                'lembrete_min'   => $cfg['lembrete_min'],
            ];
        }
        echo json_encode(['ok' => true, 'tipos' => $tipos]);
        exit;
    }

    if ($action === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }

        $slug = $_POST['tipo'] ?? '';
        $def  = alertas_catalogo()[$slug] ?? null;
        if (!$def) { echo json_encode(['ok' => false, 'erro' => 'tipo desconhecido']); exit; }

        $lembrete = (int) ($_POST['lembrete_min'] ?? 0);
        if ($lembrete < 0 || $lembrete > 10080) {
            echo json_encode(['ok' => false, 'erro' => 'lembrete deve ser de 0 a 10080 min']);
            exit;
        }

        // valida cada parâmetro contra o min/max do catálogo antes de gravar
        $params = [];
        foreach ($def['params'] as $k => $meta) {
            $v = (int) ($_POST['p_' . $k] ?? $meta['default']);
            if ($v < $meta['min'] || $v > $meta['max']) {
                echo json_encode(['ok' => false, 'erro' => "{$meta['label']}: informe de {$meta['min']} a {$meta['max']}"]);
                exit;
            }
            $params[$k] = $v;
        }

        $ativo = (($_POST['ativo'] ?? '') === '1') ? 1 : 0;
        $notif = (($_POST['notif_whatsapp'] ?? '') === '1') ? 1 : 0;

        try {
            $st = $pdo->prepare(
                "INSERT INTO portal_alertas_config (tipo, ativo, params, notif_whatsapp, lembrete_min)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ativo=VALUES(ativo), params=VALUES(params),
                     notif_whatsapp=VALUES(notif_whatsapp), lembrete_min=VALUES(lembrete_min)"
            );
            $st->execute([$slug, $ativo, json_encode($params), $notif, $lembrete]);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao salvar']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Configurar Alertas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
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

    .bloco { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06);
             padding:1.1rem 1.25rem; margin-bottom:1.1rem; }
    .bloco-h { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .bloco-h .bi { font-size:1.15rem; }
    .bloco-desc { margin:.15rem 0 .8rem; }
    .bloco .form-switch { margin:.35rem 0; }
    .bloco .form-check-label { cursor:pointer; }

    /* área que apaga/desabilita quando "Ativo" está desmarcado */
    .sub { transition:opacity .15s; margin-top:.5rem; }
    .sub.off { opacity:.4; }

    /* input na MESMA linha do label (padrão da aba Gatilhos do WhatsApp) */
    .campo { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin:.5rem 0; }
    .campo .form-label { margin:0; font-weight:600; font-size:.85rem; color:#374151; }
    .campo input { width:90px; }
    .campo .un { font-size:.8rem; color:#6b7280; }

    .bloco .btn-salvar { margin-top:.9rem; }
    .feedback { border-radius:8px; padding:.5rem .8rem; font-size:.83rem; margin-top:.7rem; display:none; }
    .feedback.ok  { background:#f0fdf4; border:1px solid #22c55e; color:#166534; display:block; }
    .feedback.err { background:#fef2f2; border:1px solid #ef4444; color:#991b1b; display:block; }
    .feedback.info{ background:#eff6ff; border:1px solid #3b82f6; color:#1e40af; display:block; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-sliders"></i> Configurar Alertas</div>
  <div>
    <a href="alertas.php"><i class="bi bi-arrow-left me-1"></i>Central de Alertas</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-sliders me-2"></i>Configurar Alertas</h1>
  <p>Ligue/desligue cada tipo de alerta e ajuste os parâmetros, o lembrete e a notificação no WhatsApp</p>
</div>

<div class="wrap">
  <div id="lista">
    <div class="text-muted small">Carregando…</div>
  </div>
</div>

<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Configuração de Alertas (Etapa 1)</footer>

<script>
(function () {
  'use strict';

  var PAGE = 'alertas_config.php';

  function feedback(el, tipo, msg) {
    el.className = 'feedback ' + tipo;
    el.textContent = msg;
  }

  // desabilita/apaga os campos de um bloco quando "Ativo" está desmarcado
  function sincronizarBloco(bloco) {
    var on = bloco.querySelector('.chk-ativo').checked;
    var sub = bloco.querySelector('.sub');
    sub.classList.toggle('off', !on);
    sub.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
  }

  function montarBloco(t) {
    var bloco = document.createElement('div');
    bloco.className = 'bloco';
    bloco.setAttribute('data-slug', t.slug);

    // título + descrição
    var h = document.createElement('div');
    h.className = 'bloco-h';
    var ico = document.createElement('i');
    ico.className = 'bi ' + t.icone;
    h.appendChild(ico);
    var nome = document.createElement('span');
    nome.textContent = t.nome;
    h.appendChild(nome);
    bloco.appendChild(h);

    var desc = document.createElement('div');
    desc.className = 'bloco-desc small text-muted';
    desc.textContent = t.descricao;
    bloco.appendChild(desc);

    // switch Ativo
    var swAtivo = document.createElement('div');
    swAtivo.className = 'form-check form-switch';
    var inAtivo = document.createElement('input');
    inAtivo.type = 'checkbox';
    inAtivo.className = 'form-check-input chk-ativo';
    inAtivo.id = 'ativo-' + t.slug;
    inAtivo.checked = (t.ativo === 1 || t.ativo === true);
    var lbAtivo = document.createElement('label');
    lbAtivo.className = 'form-check-label fw-semibold';
    lbAtivo.setAttribute('for', inAtivo.id);
    lbAtivo.textContent = 'Ativo';
    swAtivo.appendChild(inAtivo);
    swAtivo.appendChild(lbAtivo);
    bloco.appendChild(swAtivo);

    // sub-área (some/desabilita quando Ativo off)
    var sub = document.createElement('div');
    sub.className = 'sub';

    // um input numérico por parâmetro, label na mesma linha
    Object.keys(t.params_schema || {}).forEach(function (k) {
      var meta = t.params_schema[k];
      var campo = document.createElement('div');
      campo.className = 'campo';
      var lb = document.createElement('label');
      lb.className = 'form-label';
      lb.setAttribute('for', 'p-' + t.slug + '-' + k);
      lb.textContent = meta.label;
      var inp = document.createElement('input');
      inp.type = 'number';
      inp.className = 'form-control form-control-sm p-param';
      inp.id = 'p-' + t.slug + '-' + k;
      inp.setAttribute('data-k', k);
      inp.min = meta.min;
      inp.max = meta.max;
      inp.step = 1;
      var v = (t.params && t.params[k] != null) ? t.params[k] : meta.min;
      inp.value = v;
      var un = document.createElement('span');
      un.className = 'un';
      un.textContent = '(' + meta.min + '–' + meta.max + ')';
      campo.appendChild(lb);
      campo.appendChild(inp);
      campo.appendChild(un);
      sub.appendChild(campo);
    });

    // switch Notificar no grupo Alertas do WhatsApp
    var swNotif = document.createElement('div');
    swNotif.className = 'form-check form-switch';
    var inNotif = document.createElement('input');
    inNotif.type = 'checkbox';
    inNotif.className = 'form-check-input chk-notif';
    inNotif.id = 'notif-' + t.slug;
    inNotif.checked = (t.notif_whatsapp === 1 || t.notif_whatsapp === true);
    var lbNotif = document.createElement('label');
    lbNotif.className = 'form-check-label';
    lbNotif.setAttribute('for', inNotif.id);
    lbNotif.textContent = 'Notificar no grupo Alertas do WhatsApp';
    swNotif.appendChild(inNotif);
    swNotif.appendChild(lbNotif);
    sub.appendChild(swNotif);

    // aviso: na Etapa 1 ainda não há worker que dispare as notificações
    var notaNotif = document.createElement('small');
    notaNotif.className = 'text-muted d-block';
    notaNotif.style.margin = '-.15rem 0 .35rem 2.5rem';
    notaNotif.textContent = 'Passa a valer quando o worker de alertas entrar no ar (Etapa 2).';
    sub.appendChild(notaNotif);

    // lembrete em minutos
    var campoLem = document.createElement('div');
    campoLem.className = 'campo';
    var lbLem = document.createElement('label');
    lbLem.className = 'form-label';
    lbLem.setAttribute('for', 'lembrete-' + t.slug);
    lbLem.textContent = 'Lembrete (min · 0 = sem lembrete)';
    var inLem = document.createElement('input');
    inLem.type = 'number';
    inLem.className = 'form-control form-control-sm inp-lembrete';
    inLem.id = 'lembrete-' + t.slug;
    inLem.min = 0;
    inLem.max = 10080;
    inLem.step = 1;
    inLem.value = (t.lembrete_min != null) ? t.lembrete_min : 0;
    campoLem.appendChild(lbLem);
    campoLem.appendChild(inLem);
    sub.appendChild(campoLem);

    bloco.appendChild(sub);

    // botão Salvar + feedback
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-primary btn-sm btn-salvar';
    btn.innerHTML = '<i class="bi bi-save me-1"></i>Salvar';
    btn.addEventListener('click', function () { salvar(t.slug, btn); });
    bloco.appendChild(btn);

    var fb = document.createElement('div');
    fb.className = 'feedback';
    bloco.appendChild(fb);

    inAtivo.addEventListener('change', function () { sincronizarBloco(bloco); });
    sincronizarBloco(bloco);
    return bloco;
  }

  function carregar() {
    var lista = document.getElementById('lista');
    fetch(PAGE + '?action=ler')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { throw new Error((d && d.erro) || 'resposta inválida'); }
        lista.innerHTML = '';
        (d.tipos || []).forEach(function (t) { lista.appendChild(montarBloco(t)); });
        if (!(d.tipos || []).length) {
          lista.innerHTML = '<div class="text-muted small">Nenhum tipo de alerta no catálogo.</div>';
        }
      })
      .catch(function (err) {
        lista.innerHTML = '<div class="feedback err">Erro ao carregar: ' + (err.message || err) + '</div>';
      });
  }

  function salvar(slug, btn) {
    var bloco = document.querySelector('.bloco[data-slug="' + slug + '"]');
    if (!bloco) { return; }
    var fb = bloco.querySelector('.feedback');

    var params = new URLSearchParams();
    params.set('tipo', slug);
    params.set('ativo', bloco.querySelector('.chk-ativo').checked ? '1' : '0');
    params.set('notif_whatsapp', bloco.querySelector('.chk-notif').checked ? '1' : '0');

    var lem = parseInt(bloco.querySelector('.inp-lembrete').value, 10);
    if (isNaN(lem)) { lem = 0; }
    if (lem < 0 || lem > 10080) { feedback(fb, 'err', 'Lembrete: informe de 0 a 10080 min.'); return; }
    params.set('lembrete_min', lem);

    var erroLocal = null;
    bloco.querySelectorAll('.p-param').forEach(function (inp) {
      var k = inp.getAttribute('data-k');
      var min = parseInt(inp.min, 10), max = parseInt(inp.max, 10);
      var v = parseInt(inp.value, 10);
      if (isNaN(v) || v < min || v > max) {
        erroLocal = erroLocal || (k + ': informe de ' + min + ' a ' + max + '.');
      }
      params.set('p_' + k, isNaN(v) ? min : v);
    });
    if (erroLocal) { feedback(fb, 'err', erroLocal); return; }

    btn.disabled = true;
    feedback(fb, 'info', 'Salvando…');
    fetch(PAGE + '?action=salvar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        feedback(fb, d.ok ? 'ok' : 'err', d.ok ? 'Configuração salva.' : ('Erro: ' + (d.erro || 'falha ao salvar')));
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback(fb, 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  carregar();
})();
</script>
</body>
</html>
