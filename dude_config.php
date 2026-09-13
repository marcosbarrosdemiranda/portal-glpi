<?php
/**
 * dude_config.php — token/URL do webhook do The Dude, testar conexão e ver
 * a última notificação recebida. O que é monitorado (dispositivos, links,
 * limiar de latência) fica 100% no cliente do Dude — aqui só liga o "fio".
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/dude_lib.php';

$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

$H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
const DUDE_WEBHOOK_BASE = 'https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php';

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'status') {
        $token  = dude_token_atual($pdo);
        $ultima = dude_ultima_notificacao($pdo);
        echo json_encode([
            'ok' => true,
            'tem_token'   => $token !== '',
            'webhook_url' => $token !== '' ? (DUDE_WEBHOOK_BASE . '?token=' . $token) : null,
            'ultima'      => $ultima,
        ]);
        exit;
    }

    if ($action === 'gerar_token') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        try {
            $token = dude_gerar_novo_token($pdo);
            echo json_encode(['ok' => true, 'webhook_url' => DUDE_WEBHOOK_BASE . '?token=' . $token]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao gerar token']);
        }
        exit;
    }

    if ($action === 'testar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $token = dude_token_atual($pdo);
        if ($token === '') { echo json_encode(['ok' => false, 'erro' => 'gere um token primeiro']); exit; }

        $url = DUDE_WEBHOOK_BASE . '?' . http_build_query([
            'token' => $token, 'tipo' => 'device', 'chave' => '__teste_dude_config__',
            'nome' => 'Teste de conexão', 'estado' => 'down', 'detalhe' => 'disparado pelo botão Testar',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        // limpa o teste na hora (down + up), nunca deixa ocorrência pendurada
        try {
            if ($token !== '') {
                dude_registrar_estado($pdo, 'device', '__teste_dude_config__', 'Teste de conexão', '', '', '', 'up', '');
                $pdo->prepare("DELETE FROM portal_dude_estado WHERE chave = '__teste_dude_config__'")->execute();
            }
        } catch (\Throwable $e) {}

        if ($erroCurl !== '') {
            echo json_encode(['ok' => false, 'erro' => 'falha de conexão: ' . $erroCurl]);
        } elseif ($status === 200) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'erro' => "resposta HTTP $status"]);
        }
        exit;
    }

    if ($action === 'categorias_listar') {
        echo json_encode(['ok' => true, 'categorias' => dude_categoria_config_listar($pdo)]);
        exit;
    }

    if ($action === 'categorias_salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $categoria = trim((string) ($_POST['categoria'] ?? ''));
        if ($categoria === '') { echo json_encode(['ok' => false, 'erro' => 'categoria inválida']); exit; }

        $horaInicio = trim((string) ($_POST['horario_inicio'] ?? ''));
        $horaFim    = trim((string) ($_POST['horario_fim'] ?? ''));
        if (($horaInicio === '') !== ($horaFim === '')) {
            echo json_encode(['ok' => false, 'erro' => 'preencha os dois horários, ou deixe os dois em branco (sempre notifica)']);
            exit;
        }
        if ($horaInicio !== '' && (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFim))) {
            echo json_encode(['ok' => false, 'erro' => 'horário inválido (use HH:MM)']);
            exit;
        }

        $ligadoStr = trim((string) ($_POST['ligado_horas_max'] ?? ''));
        $ligado = null;
        if ($ligadoStr !== '') {
            $ligado = (int) $ligadoStr;
            if ($ligado < 1 || $ligado > 720) {
                echo json_encode(['ok' => false, 'erro' => 'horas ligado: informe de 1 a 720, ou deixe em branco']);
                exit;
            }
        }

        try {
            dude_categoria_config_salvar(
                $pdo, $categoria,
                $horaInicio !== '' ? $horaInicio . ':00' : null,
                $horaFim !== '' ? $horaFim . ':00' : null,
                $ligado
            );
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
  <title>The Dude — Rede</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:820px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    .url-box { display:flex; gap:.4rem; margin-top:.5rem; align-items:center; }
    .url-box input { font-size:.78rem; font-family:monospace; }
    .status-linha { font-size:.85rem; color:#6b7280; margin-top:.5rem; }
    .runbook { font-size:.85rem; color:#374151; }
    .runbook code { background:#f3f4f6; padding:.1rem .35rem; border-radius:4px; font-size:.8rem; }
    .runbook ol { padding-left:1.2rem; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-diagram-3"></i> The Dude — Rede</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="alertas_config.php"><i class="bi bi-arrow-left me-1"></i>Configurar Alertas</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <div class="card-box">
    <h6 class="mb-2">Webhook</h6>
    <div id="semToken" class="d-none">
      <p class="small text-muted mb-2">Nenhum token gerado ainda. Gere um pra criar a URL do webhook.</p>
    </div>
    <div id="comToken" class="d-none">
      <div class="url-box">
        <input type="text" id="webhook-url" class="form-control form-control-sm" readonly>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="copiarUrl()"><i class="bi bi-clipboard"></i></button>
      </div>
      <div class="status-linha" id="ultima-notif">última notificação: —</div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button type="button" class="btn btn-primary btn-sm" onclick="gerarToken()"><i class="bi bi-key me-1"></i>Gerar novo token</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-testar" onclick="testar()"><i class="bi bi-play-circle me-1"></i>Testar conexão</button>
    </div>
    <div id="fb" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-2">Regras por categoria (PDV, Servidor, PCs Retaguarda...)</h6>
    <p class="small text-muted mb-2">Horário em branco = notifica "sem comunicação" a qualquer hora. Horas ligado em branco = não checa "ligado há muito tempo" pra essa categoria.</p>
    <div id="cat-lista">Carregando…</div>
    <div id="fb-cat" class="small mt-2"></div>
  </div>

  <div class="card-box runbook">
    <h6 class="mb-2">Como configurar no cliente do Dude</h6>
    <p>Você tem 4 mapas (um por loja) — configure 1 Notification por mapa/categoria, com <code>&amp;loja=</code> e <code>&amp;categoria=</code> fixos (ex.: <code>Loja+05</code> / <code>PDV</code>).</p>
    <ol>
      <li>No cliente do Dude: <b>Settings → Notifications → New</b>, tipo <b>Execute on server</b> (não "Locally", a menos que o PC fique ligado 24/7).</li>
      <li>Comando (confirmado funcionando — variáveis dessa versão do Dude são só <code>[Device.Name]</code> e <code>[Device.FirstAddress]</code>; <code>[Device.Id]</code>/<code>[Device.Address]</code> não existem):
        <br><code>cmd.exe /c curl.exe -g "&lt;URL do webhook&gt;&amp;tipo=device&amp;chave=[Device.Name]&amp;nome=[Device.Name]&amp;addr=[Device.FirstAddress]&amp;loja=Loja+05&amp;categoria=PDV&amp;estado=down" &gt;&gt; %TEMP%\dude_log.txt 2&gt;&amp;1</code>
      </li>
      <li>Precisa do <code>cmd.exe /c</code> na frente (senão redirecionamento/variável não funcionam) e do <code>-g</code> no curl (senão colchetes de variável não-preenchida quebram o comando).</li>
      <li>Disparar em <b>down</b> e em <b>up</b> — sem o <code>up</code>, o alerta nunca resolve sozinho. Dá pra usar 1 notification só com <code>estado=[Device.Status]</code> (se essa variável existir na sua versão) marcando os 2 checkboxes (Ativo/Inativo) na aba Avançado.</li>
      <li>Pra latência/enlace/serviço: troca <code>tipo=device</code> por <code>latencia</code>/<code>link</code>/<code>service</code>. Essas 3 não respeitam o horário por categoria (só "device" respeita) — sempre alertam.</li>
      <li>O container do Dude pode não ter <code>curl</code> instalado (aconteceu aqui) — se o teste não gerar log nenhum, confirmar com o TI: <code>docker exec dude apt-get install -y curl</code>.</li>
    </ol>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — The Dude</footer>

<script>
function $(id) { return document.getElementById(id); }
function feedback(tipo, msg) {
  var el = $('fb');
  el.className = 'small mt-2 ' + (tipo === 'ok' ? 'text-success' : (tipo === 'err' ? 'text-danger' : 'text-muted'));
  el.textContent = msg;
}
function carregarStatus() {
  fetch('dude_config.php?action=status')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) return;
      $('semToken').classList.toggle('d-none', d.tem_token);
      $('comToken').classList.toggle('d-none', !d.tem_token);
      if (d.tem_token) {
        $('webhook-url').value = d.webhook_url;
        $('ultima-notif').textContent = d.ultima ? ('última notificação: ' + d.ultima) : 'última notificação: nunca';
      }
    });
}
function copiarUrl() {
  var input = $('webhook-url');
  input.select();
  navigator.clipboard && navigator.clipboard.writeText(input.value);
}
function gerarToken() {
  if (!confirm('Gerar um novo token invalida o atual — o Dude para de conseguir notificar até você colar a URL nova. Continuar?')) return;
  fetch('dude_config.php?action=gerar_token', { method: 'POST' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { feedback('err', d.erro || 'erro ao gerar token'); return; }
      feedback('ok', 'token gerado — copie a URL nova pra cada Notification no Dude');
      carregarStatus();
    });
}
function testar() {
  var btn = $('btn-testar');
  btn.disabled = true;
  feedback('info', 'testando…');
  fetch('dude_config.php?action=testar', { method: 'POST' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      feedback(d.ok ? 'ok' : 'err', d.ok ? 'conexão OK — o caminho até o webhook está funcionando' : (d.erro || 'falha no teste'));
      carregarStatus();
    })
    .finally(function () { btn.disabled = false; });
}
carregarStatus();

/* ─────────── Categorias ─────────── */
function feedbackCat(tipo, msg) {
  var el = $('fb-cat');
  el.className = 'small mt-2 ' + (tipo === 'ok' ? 'text-success' : (tipo === 'err' ? 'text-danger' : 'text-muted'));
  el.textContent = msg;
}
function hhmm(t) {
  // "06:00:00" -> "06:00"; null -> ""
  if (!t) return '';
  return t.substring(0, 5);
}
function linhaCategoria(c) {
  var div = document.createElement('div');
  div.className = 'd-flex gap-2 flex-wrap align-items-end mb-2 pb-2';
  div.style.borderBottom = '1px solid #f3f4f6';
  div.innerHTML =
    '<div style="min-width:110px"><b>' + c.categoria + '</b></div>' +
    '<div><label class="form-label small mb-0">Notifica das</label>' +
      '<input type="time" class="form-control form-control-sm cat-inicio" style="width:110px" value="' + hhmm(c.horario_inicio) + '"></div>' +
    '<div><label class="form-label small mb-0">até</label>' +
      '<input type="time" class="form-control form-control-sm cat-fim" style="width:110px" value="' + hhmm(c.horario_fim) + '"></div>' +
    '<div><label class="form-label small mb-0">Horas ligado p/ alertar</label>' +
      '<input type="number" min="1" max="720" class="form-control form-control-sm cat-ligado" style="width:110px" placeholder="não checa" value="' + (c.ligado_horas_max != null ? c.ligado_horas_max : '') + '"></div>' +
    '<button type="button" class="btn btn-primary btn-sm" onclick="salvarCategoria(this, \'' + c.categoria.replace(/'/g, "\\'") + '\')">Salvar</button>';
  return div;
}
function carregarCategorias() {
  fetch('dude_config.php?action=categorias_listar')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var lista = $('cat-lista');
      lista.innerHTML = '';
      if (!d.ok || !d.categorias.length) {
        lista.innerHTML = '<div class="text-muted small">Nenhuma categoria vista ainda — configure <code>&categoria=</code> nas notifications do Dude primeiro.</div>';
        return;
      }
      d.categorias.forEach(function (c) { lista.appendChild(linhaCategoria(c)); });
    });
}
function salvarCategoria(btn, categoria) {
  var linha = btn.closest('div.d-flex');
  var params = new URLSearchParams({
    categoria: categoria,
    horario_inicio: linha.querySelector('.cat-inicio').value,
    horario_fim: linha.querySelector('.cat-fim').value,
    ligado_horas_max: linha.querySelector('.cat-ligado').value,
  });
  fetch('dude_config.php?action=categorias_salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { feedbackCat('err', d.erro || 'erro ao salvar'); return; }
      feedbackCat('ok', categoria + ' salvo.');
    });
}
carregarCategorias();
</script>
</body>
</html>
