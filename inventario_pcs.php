<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

function glpi_req(string $endpoint, string $token): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) && !isset($r['ERROR']) ? $r : [];
}

// Abre sessão
$auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch    = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r     = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';

// Busca computadores
$computadores_raw = glpi_req('Computer?range=0-500&expand_dropdowns=true&order=ASC', $token);

// Busca entidades
$entidades_raw = glpi_req('Entity?range=0-100&expand_dropdowns=true', $token);

// ── Busca IPs reais via NetworkPort → NetworkName → IPAddress ─
// O endpoint /Computer não retorna IP diretamente no GLPI 10
$netports_raw = glpi_req('NetworkPort?range=0-2000', $token);
$netnames_raw = glpi_req('NetworkName?range=0-2000', $token);
$ipaddrs_raw  = glpi_req('IPAddress?range=0-2000', $token);

// port_id → computer_id
$port_to_pc = [];
foreach ($netports_raw as $np) {
    if (($np['itemtype'] ?? '') === 'Computer') {
        $port_to_pc[(int)$np['id']] = (int)$np['items_id'];
    }
}
// name_id → computer_id
$name_to_pc = [];
foreach ($netnames_raw as $nn) {
    if (($nn['itemtype'] ?? '') === 'NetworkPort') {
        $pid = (int)$nn['items_id'];
        if (isset($port_to_pc[$pid])) {
            $name_to_pc[(int)$nn['id']] = $port_to_pc[$pid];
        }
    }
}
// computer_id → ip (prefere 192.168.x.x; ignora loopback e IPv6)
$ips_por_pc = [];
foreach ($ipaddrs_raw as $ip) {
    if (($ip['itemtype'] ?? '') !== 'NetworkName') continue;
    $nid  = (int)$ip['items_id'];
    if (!isset($name_to_pc[$nid])) continue;
    $cid  = $name_to_pc[$nid];
    $addr = trim($ip['name'] ?? '');
    if (!$addr || str_starts_with($addr, '127.') || str_contains($addr, ':')) continue;
    // Prefere LAN 192.168.x.x; aceita qualquer IPv4 como fallback
    if (!isset($ips_por_pc[$cid]) || str_starts_with($addr, '192.168.')) {
        $ips_por_pc[$cid] = $addr;
    }
}

// Encerra sessão
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
curl_exec($ch); curl_close($ch);

// Organiza entidades
$entidades = [];
foreach ($entidades_raw as $e) {
    if (!isset($e['id'])) continue;
    $entidades[$e['id']] = apelido_entidade($e['completename'] ?? $e['name'] ?? 'Entidade '.$e['id']);
}

// Organiza computadores por entidade
$por_entidade = [];
foreach ($computadores_raw as $c) {
    if (!isset($c['id'])) continue;
    $ent_nome = apelido_entidade($c['entities_id'] ?? 'Entidade raiz');
    if (!isset($por_entidade[$ent_nome])) $por_entidade[$ent_nome] = [];
    $por_entidade[$ent_nome][] = [
        'id'          => $c['id'],
        'nome'        => $c['name'] ?? 'PC '.$c['id'],
        'ip'          => $ips_por_pc[$c['id']] ?? ($c['ip'] ?? ''),
        'so'          => $c['operatingsystems_id'] ?? '',
        'fabricante'  => $c['manufacturers_id'] ?? '',
        'modelo'      => $c['computermodels_id'] ?? '',
        'serial'      => $c['serial'] ?? '',
        'entidade'    => $ent_nome,
        'usuario'     => $c['users_id'] ?? '',
        'atualizado'  => substr($c['date_mod'] ?? '', 0, 16),
        'ultimo_inv'  => substr($c['last_inventory_date'] ?? $c['date_mod'] ?? '', 0, 16),
    ];
}
ksort($por_entidade);

$total_pcs = count($computadores_raw);
$f_entidade = $_GET['entidade'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário de Máquinas / PCs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }

    .wrap { max-width:1200px; margin:-3rem auto 3rem; padding:0 1rem; }

    /* Filtros */
    .filtros-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
      margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end;
    }
    .filtros-card .form-label { font-size:.78rem; font-weight:600; color:#6b7280; margin-bottom:.2rem; }
    .btn-filtrar { background:var(--accent); border:none; color:white; border-radius:8px;
                   padding:.45rem 1.25rem; font-size:.85rem; font-weight:600; cursor:pointer; }

    /* Stats */
    .stats { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
    .stat-chip {
      background:white; border-radius:10px; border:1px solid #e5e7eb;
      padding:.5rem 1rem; font-size:.82rem; font-weight:600;
      display:flex; align-items:center; gap:.4rem;
      box-shadow:0 1px 4px rgba(0,0,0,.05);
    }

    /* Seção entidade */
    .entidade-section { margin-bottom:1.5rem; }
    .entidade-header {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; border-radius:10px 10px 0 0;
      padding:.65rem 1.25rem; font-weight:700; font-size:.9rem;
      display:flex; align-items:center; justify-content:space-between;
    }
    .entidade-body {
      background:white; border-radius:0 0 10px 10px;
      border:1px solid #e5e7eb; border-top:none;
      box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
    }

    /* Grid de PCs */
    .pcs-grid { padding:.75rem; }
    .pc-card { height:100%; }

    .pc-card {
      border:1px solid #e5e7eb; border-radius:10px;
      padding:.85rem; cursor:pointer; transition:all .15s;
      background:#fafafa; position:relative;
    }
    .pc-card:hover { border-color:var(--accent); background:#f0f4ff; transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.1); }

    .pc-status {
      position:absolute; top:.6rem; right:.6rem;
      width:10px; height:10px; border-radius:50%;
    }
    .pc-status.online  { background:#16a34a; box-shadow:0 0 6px #16a34a; animation:pulse-green 2s infinite; }
    .pc-status.offline { background:#dc2626; }
    .pc-status.checking { background:#f59e0b; animation:pulse-yellow 1s infinite; }

    @keyframes pulse-green  { 0%,100%{box-shadow:0 0 4px #16a34a} 50%{box-shadow:0 0 10px #16a34a} }
    @keyframes pulse-yellow { 0%,100%{opacity:1} 50%{opacity:.4} }

    .pc-icon { font-size:1.8rem; color:var(--accent); margin-bottom:.4rem; }
    .pc-nome { font-weight:700; font-size:.88rem; color:#111; margin-bottom:.15rem;
               white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .pc-info { font-size:.72rem; color:#6b7280; }
    .pc-info span { display:block; }

    .badge-online  { background:#dcfce7; color:#16a34a; border:1px solid #bbf7d0; }
    .badge-offline { background:#fee2e2; color:#dc2626; border:1px solid #fecaca; }
    .badge-check   { background:#fef3c7; color:#d97706; border:1px solid #fde68a; }

    /* Modal detalhes */
    .modal-header-pc { background:linear-gradient(135deg,var(--primary),#1565c0); color:white; }
    .modal-header-pc .btn-close { filter:invert(1); }

    .spec-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
    .spec-item { background:#f9fafb; border-radius:8px; padding:.6rem .85rem; }
    .spec-label { font-size:.7rem; font-weight:700; color:#9ca3af; text-transform:uppercase; }
    .spec-val   { font-size:.88rem; font-weight:600; color:#111; margin-top:.1rem; }

    .status-badge {
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.35rem .9rem; border-radius:20px; font-size:.8rem; font-weight:700;
    }

  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-pc-display me-1"></i> Inventário de Máquinas / PCs</div>
  <a href="inventario.php"><i class="bi bi-arrow-left me-1"></i>Categorias</a>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-pc-display-horizontal me-2"></i>Inventário de Máquinas / PCs</h1>
  <p style="opacity:.8">Monitoramento em tempo real por entidade</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <div class="form-label">Buscar</div>
      <input type="text" id="f-busca" class="form-control form-control-sm" style="width:220px" placeholder="Nome do PC ou IP..."/>
    </div>
    <div>
      <div class="form-label">Entidade</div>
      <select id="f-entidade" class="form-select form-select-sm" style="width:220px" onchange="filtrarEntidade()">
        <option value="">Todas as entidades</option>
        <?php foreach (array_keys($por_entidade) as $ent): ?>
          <option value="<?= htmlspecialchars($ent) ?>"><?= htmlspecialchars($ent) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <div class="form-label">Status</div>
      <select id="f-status" class="form-select form-select-sm" style="width:140px" onchange="filtrarStatus()">
        <option value="">Todos</option>
        <option value="online">Online</option>
        <option value="offline">Offline</option>
      </select>
    </div>
    <button class="btn-filtrar" onclick="verificarTodos()">
      <i class="bi bi-wifi me-1"></i>Verificar Status
    </button>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-chip"><i class="bi bi-pc-display text-secondary"></i> Total: <strong><?= $total_pcs ?></strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i> Online: <strong id="cnt-online">—</strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-danger" style="font-size:.6rem"></i> Offline: <strong id="cnt-offline">—</strong></div>
    <div class="stat-chip"><i class="bi bi-buildings text-primary" style="font-size:.8rem"></i> Entidades: <strong><?= count($por_entidade) ?></strong></div>
  </div>

  <!-- PCs por entidade -->
  <?php foreach ($por_entidade as $ent_nome => $pcs): ?>
  <div class="entidade-section" data-entidade="<?= htmlspecialchars($ent_nome) ?>">
    <div class="entidade-header">
      <span><i class="bi bi-building me-2"></i><?= htmlspecialchars($ent_nome) ?></span>
      <span class="badge bg-light text-dark"><?= count($pcs) ?> máquinas</span>
    </div>
    <div class="entidade-body">
      <div class="pcs-grid">
        <div class="row g-2">
        <?php foreach ($pcs as $pc): ?>
        <div class="col-6 col-md-4 col-lg-3">
        <div class="pc-card"
             data-id="<?= $pc['id'] ?>"
             data-nome="<?= htmlspecialchars($pc['nome']) ?>"
             data-ip="<?= htmlspecialchars($pc['ip']) ?>"
             data-so="<?= htmlspecialchars($pc['so']) ?>"
             data-fabricante="<?= htmlspecialchars($pc['fabricante']) ?>"
             data-modelo="<?= htmlspecialchars($pc['modelo']) ?>"
             data-serial="<?= htmlspecialchars($pc['serial']) ?>"
             data-usuario="<?= htmlspecialchars($pc['usuario']) ?>"
             data-entidade="<?= htmlspecialchars($pc['entidade']) ?>"
             data-atualizado="<?= htmlspecialchars($pc['atualizado']) ?>"
             data-ultimo-inv="<?= htmlspecialchars($pc['ultimo_inv']) ?>"
             data-status="checking"
             onclick="abrirDetalhes(this)">
          <div class="pc-status checking" id="status-<?= $pc['id'] ?>"></div>
          <div class="pc-icon"><i class="bi bi-pc-display"></i></div>
          <div class="pc-nome" title="<?= htmlspecialchars($pc['nome']) ?>"><?= htmlspecialchars($pc['nome']) ?></div>
          <div class="pc-info">
            <?php if ($pc['ip']): ?><span><i class="bi bi-ethernet me-1"></i><?= htmlspecialchars($pc['ip']) ?></span><?php endif; ?>
            <?php if ($pc['so']): ?><span><i class="bi bi-windows me-1"></i><?= htmlspecialchars($pc['so']) ?></span><?php endif; ?>
            <?php if ($pc['usuario']): ?><span><i class="bi bi-person me-1"></i><?= htmlspecialchars($pc['usuario']) ?></span><?php endif; ?>
          </div>
          <div class="mt-2 d-flex align-items-center gap-2" style="min-width:0">
            <span class="badge badge-check flex-shrink-0" id="badge-<?= $pc['id'] ?>">
              <i class="bi bi-hourglass-split me-1"></i>Verificando...
            </span>
            <?php if ($pc['ultimo_inv']): ?>
            <span style="font-size:.65rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="Última comunicação: <?= htmlspecialchars($pc['ultimo_inv']) ?>">
              <i class="bi bi-clock-history"></i> <?= htmlspecialchars(substr($pc['ultimo_inv'], 0, 16)) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

</div>

<!-- Modal Detalhes PC -->
<div class="modal fade" id="modalPC" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-pc">
        <div>
          <h5 class="modal-title mb-0" id="modal-pc-nome">PC</h5>
          <small id="modal-pc-entidade" style="opacity:.8"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Status -->
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="status-badge" id="modal-status-badge">
            <i class="bi bi-circle-fill"></i> Verificando...
          </span>
          <small class="text-muted" id="modal-ultimo-inv"></small>
        </div>

        <!-- Specs -->
        <div class="spec-grid" id="modal-specs">
          <div class="spec-item">
            <div class="spec-label">IP</div>
            <div class="spec-val" id="modal-ip">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Sistema Operacional</div>
            <div class="spec-val" id="modal-so">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Fabricante</div>
            <div class="spec-val" id="modal-fabricante">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Modelo</div>
            <div class="spec-val" id="modal-modelo">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Número de Série</div>
            <div class="spec-val" id="modal-serial">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Último Usuário</div>
            <div class="spec-val" id="modal-usuario">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Última Atualização</div>
            <div class="spec-val" id="modal-atualizado">—</div>
          </div>
          <div class="spec-item">
            <div class="spec-label">Último Inventário</div>
            <div class="spec-val" id="modal-inventario">—</div>
          </div>
        </div>

        <!-- Hardware detalhado (carregado via AJAX) -->
        <div id="modal-hardware" class="mt-3" style="display:none">
          <hr/>
          <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-cpu me-1"></i>Hardware</h6>
          <div class="spec-grid" id="modal-hw-grid"></div>
        </div>

        <!-- Programas instalados -->
        <div id="modal-software" class="mt-3" style="display:none">
          <hr/>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="fw-bold text-secondary mb-0">
              <i class="bi bi-grid-3x3-gap me-1"></i>Programas Instalados
              <span class="badge bg-secondary ms-1" id="sw-count" style="font-size:.7rem"></span>
            </h6>
            <input type="text" id="sw-busca" class="form-control form-control-sm"
                   style="width:180px;font-size:.78rem" placeholder="🔍 Filtrar programa..."
                   oninput="filtrarSoftware()"/>
          </div>
          <div id="sw-lista" style="max-height:260px;overflow-y:auto;display:grid;grid-template-columns:1fr 1fr;gap:.35rem;font-size:.78rem"></div>
          <p id="sw-vazio" class="text-muted small text-center py-2" style="display:none">Nenhum programa encontrado.</p>
        </div>

        <div id="modal-loading" class="text-center py-3">
          <div class="spinner-border text-primary spinner-border-sm me-2"></div>
          <span class="text-muted small">Carregando detalhes...</span>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-sm btn-primary" id="btn-ping-modal" onclick="pingModal()">
          <i class="bi bi-wifi me-1"></i>Verificar Status
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalPC;
let pcAtual = null;

document.addEventListener('DOMContentLoaded', () => {
  modalPC = new bootstrap.Modal(document.getElementById('modalPC'));
  verificarTodos();

  // Busca em tempo real
  document.getElementById('f-busca').addEventListener('input', filtrarBusca);
});

// ── Ping via AJAX ─────────────────────────────────────────────
function ping(ip, pcId) {
  if (!ip) {
    setStatus(pcId, 'offline');
    return;
  }
  fetch(`ping.php?ip=${encodeURIComponent(ip)}`)
    .then(r => r.json())
    .then(d => setStatus(pcId, d.online ? 'online' : 'offline'))
    .catch(() => setStatus(pcId, 'offline'));
}

function setStatus(pcId, status) {
  const dot   = document.getElementById('status-' + pcId);
  const badge = document.getElementById('badge-' + pcId);
  const card  = document.querySelector(`[data-id="${pcId}"]`);

  if (!dot) return;

  dot.className   = 'pc-status ' + status;
  card.dataset.status = status;

  if (status === 'online') {
    badge.className   = 'badge badge-online';
    badge.innerHTML   = '<i class="bi bi-circle-fill me-1"></i>Online';
  } else {
    badge.className   = 'badge badge-offline';
    badge.innerHTML   = '<i class="bi bi-circle-fill me-1"></i>Offline';
  }

  atualizarContadores();
}

function verificarTodos() {
  const cards = document.querySelectorAll('.pc-card');
  cards.forEach(c => {
    const ip   = c.dataset.ip;
    const id   = c.dataset.id;
    const dot  = document.getElementById('status-' + id);
    const badge= document.getElementById('badge-' + id);
    if (dot)   dot.className   = 'pc-status checking';
    if (badge) { badge.className='badge badge-check'; badge.innerHTML='<i class="bi bi-hourglass-split me-1"></i>Verificando...'; }
    setTimeout(() => ping(ip, id), Math.random() * 2000); // escalonado para não sobrecarregar
  });
}

function atualizarContadores() {
  const cards   = document.querySelectorAll('.pc-card');
  let online = 0, offline = 0;
  cards.forEach(c => {
    if (c.style.display === 'none') return;
    if (c.dataset.status === 'online') online++;
    else if (c.dataset.status === 'offline') offline++;
  });
  document.getElementById('cnt-online').textContent  = online;
  document.getElementById('cnt-offline').textContent = offline;
}

// ── Filtros ───────────────────────────────────────────────────
function filtrarEntidade() {
  const sel = document.getElementById('f-entidade').value;
  document.querySelectorAll('.entidade-section').forEach(s => {
    s.style.display = !sel || s.dataset.entidade === sel ? '' : 'none';
  });
}

function filtrarStatus() {
  const sel = document.getElementById('f-status').value;
  document.querySelectorAll('.pc-card').forEach(c => {
    c.style.display = !sel || c.dataset.status === sel ? '' : 'none';
  });
  atualizarContadores();
}

function filtrarBusca() {
  const q = document.getElementById('f-busca').value.toLowerCase();
  document.querySelectorAll('.pc-card').forEach(c => {
    const match = c.dataset.nome.toLowerCase().includes(q) || c.dataset.ip.includes(q);
    c.style.display = match ? '' : 'none';
  });
}

// ── Modal detalhes ────────────────────────────────────────────
function abrirDetalhes(card) {
  pcAtual = card;
  const d = card.dataset;

  document.getElementById('modal-pc-nome').textContent    = d.nome;
  document.getElementById('modal-pc-entidade').textContent= d.entidade;
  document.getElementById('modal-ip').textContent         = d.ip || '—';
  document.getElementById('modal-so').textContent         = d.so || '—';
  document.getElementById('modal-fabricante').textContent = d.fabricante || '—';
  document.getElementById('modal-modelo').textContent     = d.modelo || '—';
  document.getElementById('modal-serial').textContent     = d.serial || '—';
  document.getElementById('modal-usuario').textContent    = d.usuario || '—';
  document.getElementById('modal-atualizado').textContent = d.atualizado || '—';
  document.getElementById('modal-inventario').textContent = d.ultimoInv || '—';

  // Status atual
  const status = d.status;
  const badge  = document.getElementById('modal-status-badge');
  if (status === 'online') {
    badge.className = 'status-badge bg-success text-white';
    badge.innerHTML = '<i class="bi bi-circle-fill"></i> Online';
  } else if (status === 'offline') {
    badge.className = 'status-badge bg-danger text-white';
    badge.innerHTML = '<i class="bi bi-circle-fill"></i> Offline';
  } else {
    badge.className = 'status-badge bg-warning text-dark';
    badge.innerHTML = '<i class="bi bi-hourglass-split"></i> Verificando...';
  }

  // Carrega hardware detalhado + software
  document.getElementById('modal-hardware').style.display = 'none';
  document.getElementById('modal-software').style.display = 'none';
  document.getElementById('modal-loading').style.display  = '';
  document.getElementById('sw-busca').value = '';
  window._swLista = [];

  fetch(`inventario_detalhes.php?id=${d.id}`)
    .then(r => r.json())
    .then(resp => {
      document.getElementById('modal-loading').style.display = 'none';

      // Suporte ao novo formato {hw, software} e ao formato legado (objeto plano)
      const hw  = resp.hw       ?? resp;
      const sws = resp.software ?? [];

      // Hardware
      if (hw && Object.keys(hw).length > 0) {
        document.getElementById('modal-hw-grid').innerHTML =
          Object.entries(hw).map(([k,v]) =>
            `<div class="spec-item"><div class="spec-label">${k}</div><div class="spec-val">${v||'—'}</div></div>`
          ).join('');
        document.getElementById('modal-hardware').style.display = '';
      }

      // Software
      if (sws.length > 0) {
        window._swLista = sws;
        document.getElementById('sw-count').textContent = sws.length;
        renderSoftware(sws);
        document.getElementById('modal-software').style.display = '';
      }
    })
    .catch(() => { document.getElementById('modal-loading').style.display = 'none'; });

  modalPC.show();
}

function renderSoftware(lista) {
  const container = document.getElementById('sw-lista');
  const vazio     = document.getElementById('sw-vazio');
  if (!lista.length) {
    container.innerHTML = '';
    vazio.style.display = '';
    return;
  }
  vazio.style.display = 'none';
  container.innerHTML = lista.map(s => `
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:.35rem .6rem;overflow:hidden">
      <div style="font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escHtml(s.nome)}">${escHtml(s.nome)}</div>
      ${s.versao ? `<div style="color:#6b7280;font-size:.7rem">${escHtml(s.versao)}</div>` : ''}
    </div>`).join('');
}

function filtrarSoftware() {
  const q = document.getElementById('sw-busca').value.toLowerCase();
  const filtrado = (window._swLista || []).filter(s =>
    s.nome.toLowerCase().includes(q) || (s.versao || '').toLowerCase().includes(q)
  );
  renderSoftware(filtrado);
  document.getElementById('sw-count').textContent = filtrado.length;
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function pingModal() {
  if (!pcAtual) return;
  const ip = pcAtual.dataset.ip;
  const id = pcAtual.dataset.id;
  const badge = document.getElementById('modal-status-badge');
  badge.className = 'status-badge bg-warning text-dark';
  badge.innerHTML = '<i class="bi bi-hourglass-split"></i> Verificando...';

  fetch(`ping.php?ip=${encodeURIComponent(ip)}`)
    .then(r => r.json())
    .then(d => {
      if (d.online) {
        badge.className = 'status-badge bg-success text-white';
        badge.innerHTML = '<i class="bi bi-circle-fill"></i> Online';
        setStatus(id, 'online');
      } else {
        badge.className = 'status-badge bg-danger text-white';
        badge.innerHTML = '<i class="bi bi-circle-fill"></i> Offline';
        setStatus(id, 'offline');
      }
    });
}
</script>
</body>
</html>
