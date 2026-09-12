<?php
/**
 * backup_maquinas.php — cadastro das máquinas de backup (back-gmais) que a
 * Central de Alertas monitora. Cada máquina cadastrada aqui vira uma URL de
 * webhook própria (com token) pra colar no config.yaml do back-gmais naquele
 * servidor — é assim que o portal sabe de qual máquina veio cada resultado
 * de job, já que o webhook do back-gmais não manda hostname.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/backup_lib.php';

$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

$H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
const BACKUP_WEBHOOK_BASE = 'http://192.168.1.198:7412/glpi2/portal-glpi/webhook_backup.php';

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'listar') {
        $maquinas = array_map(function ($m) {
            $m['webhook_url'] = BACKUP_WEBHOOK_BASE . '?m=' . $m['token'];
            return $m;
        }, backup_maquinas_listar($pdo));
        echo json_encode(['ok' => true, 'maquinas' => $maquinas]);
        exit;
    }

    if ($action === 'criar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if ($nome === '') { echo json_encode(['ok' => false, 'erro' => 'nome é obrigatório']); exit; }
        $horas = ($_POST['silencio_horas'] ?? '') !== '' ? (int) $_POST['silencio_horas'] : null;
        if ($horas !== null && ($horas < 2 || $horas > 168)) {
            echo json_encode(['ok' => false, 'erro' => 'horas de silêncio: informe de 2 a 168, ou deixe em branco']);
            exit;
        }
        try {
            $m = backup_maquina_criar($pdo, $nome, $horas);
            $m['webhook_url'] = BACKUP_WEBHOOK_BASE . '?m=' . $m['token'];
            echo json_encode(['ok' => true, 'maquina' => $m]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao criar']);
        }
        exit;
    }

    if ($action === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $id   = (int) ($_POST['id'] ?? 0);
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if ($id <= 0 || $nome === '') { echo json_encode(['ok' => false, 'erro' => 'dados inválidos']); exit; }
        $ativo = (($_POST['ativo'] ?? '') === '1');
        $horas = ($_POST['silencio_horas'] ?? '') !== '' ? (int) $_POST['silencio_horas'] : null;
        if ($horas !== null && ($horas < 2 || $horas > 168)) {
            echo json_encode(['ok' => false, 'erro' => 'horas de silêncio: informe de 2 a 168, ou deixe em branco']);
            exit;
        }
        try {
            backup_maquina_atualizar($pdo, $id, $nome, $ativo, $horas);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao salvar']);
        }
        exit;
    }

    if ($action === 'excluir') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id inválido']); exit; }
        try {
            backup_maquina_excluir($pdo, $id);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao excluir']);
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
  <title>Máquinas de Backup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:900px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    .maquina { border-bottom:1px solid #f3f4f6; padding:.9rem 0; }
    .maquina:last-child { border-bottom:none; }
    .maquina .nome { font-weight:700; }
    .maquina .status { font-size:.8rem; color:#6b7280; }
    .maquina .status.error { color:#b71c1c; font-weight:600; }
    .maquina .status.sem-contato { color:#b45309; font-weight:600; }
    .url-box { display:flex; gap:.4rem; margin-top:.5rem; align-items:center; }
    .url-box input { font-size:.78rem; font-family:monospace; }
    .vazio { color:#6b7280; text-align:center; padding:1.5rem; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-hdd-network"></i> Máquinas de Backup</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="alertas.php"><i class="bi bi-bell me-1"></i>Central de Alertas</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <div class="card-box">
    <h6 class="mb-2">Cadastrar nova máquina</h6>
    <div class="d-flex gap-2 flex-wrap align-items-end">
      <div>
        <label class="form-label small mb-0">Nome</label>
        <input type="text" id="novo-nome" class="form-control form-control-sm" style="width:220px" placeholder="ex: Servidor Backup 01">
      </div>
      <div>
        <label class="form-label small mb-0">Horas sem contato p/ alertar</label>
        <input type="number" id="novo-horas" class="form-control form-control-sm" style="width:150px" placeholder="padrão: 26" min="2" max="168">
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="criarMaquina()"><i class="bi bi-plus-lg me-1"></i>Cadastrar</button>
    </div>
    <div id="fb-criar" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-2">Máquinas cadastradas</h6>
    <div id="lista">Carregando…</div>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Máquinas de Backup</footer>

<script>
function $(id) { return document.getElementById(id); }
function feedback(el, tipo, msg) {
  el.className = 'small mt-2 ' + (tipo === 'ok' ? 'text-success' : (tipo === 'err' ? 'text-danger' : 'text-muted'));
  el.textContent = msg;
}
function statusClasse(m) {
  if (!m.ultimo_contato) return 'sem-contato';
  if (m.ultimo_status === 'error') return 'error';
  return '';
}
function statusTexto(m) {
  if (!m.ultimo_contato) return 'nunca contatou';
  return 'último contato: ' + m.ultimo_contato + (m.ultima_politica ? ' · ' + m.ultima_politica : '') + (m.ultimo_status ? ' (' + m.ultimo_status + ')' : '');
}
function linhaMaquina(m) {
  var div = document.createElement('div');
  div.className = 'maquina';
  div.innerHTML =
    '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">' +
      '<div>' +
        '<div class="nome">' + m.nome + (m.ativo == 0 ? ' <span class="badge bg-secondary">inativa</span>' : '') + '</div>' +
        '<div class="status ' + statusClasse(m) + '">' + statusTexto(m) + '</div>' +
      '</div>' +
      '<div class="d-flex gap-1">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAtiva(' + m.id + ',' + (m.ativo == 1 ? 0 : 1) + ',\'' + m.nome.replace(/'/g, "\\'") + '\')">' + (m.ativo == 1 ? 'Desativar' : 'Ativar') + '</button>' +
        '<button type="button" class="btn btn-sm btn-outline-danger" onclick="excluirMaquina(' + m.id + ',\'' + m.nome.replace(/'/g, "\\'") + '\')"><i class="bi bi-trash"></i></button>' +
      '</div>' +
    '</div>' +
    '<div class="url-box">' +
      '<input type="text" class="form-control form-control-sm" readonly value="' + m.webhook_url + '">' +
      '<button type="button" class="btn btn-sm btn-outline-primary" onclick="copiarUrl(this)"><i class="bi bi-clipboard"></i></button>' +
    '</div>';
  return div;
}
function copiarUrl(btn) {
  var input = btn.previousElementSibling;
  input.select();
  navigator.clipboard && navigator.clipboard.writeText(input.value);
  btn.innerHTML = '<i class="bi bi-check-lg"></i>';
  setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
}
function carregarLista() {
  fetch('backup_maquinas.php?action=listar')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var lista = $('lista');
      lista.innerHTML = '';
      if (!d.ok || !d.maquinas.length) {
        lista.innerHTML = '<div class="vazio">Nenhuma máquina cadastrada ainda.</div>';
        return;
      }
      d.maquinas.forEach(function (m) { lista.appendChild(linhaMaquina(m)); });
    })
    .catch(function () { $('lista').innerHTML = '<div class="vazio text-danger">erro ao carregar</div>'; });
}
function criarMaquina() {
  var nome = $('novo-nome').value.trim();
  var horas = $('novo-horas').value.trim();
  if (!nome) { feedback($('fb-criar'), 'err', 'informe um nome'); return; }
  var params = new URLSearchParams({ nome: nome, silencio_horas: horas });
  fetch('backup_maquinas.php?action=criar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { feedback($('fb-criar'), 'err', d.erro || 'erro ao criar'); return; }
      feedback($('fb-criar'), 'ok', 'máquina criada — copie a URL abaixo pro config.yaml');
      $('novo-nome').value = ''; $('novo-horas').value = '';
      carregarLista();
    });
}
function toggleAtiva(id, ativo, nome) {
  var params = new URLSearchParams({ id: id, nome: nome, ativo: ativo });
  fetch('backup_maquinas.php?action=salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function () { carregarLista(); });
}
function excluirMaquina(id, nome) {
  if (!confirm('Excluir "' + nome + '"? O histórico de execuções dessa máquina também é apagado.')) return;
  var params = new URLSearchParams({ id: id });
  fetch('backup_maquinas.php?action=excluir', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function () { carregarLista(); });
}
carregarLista();
</script>
</body>
</html>
