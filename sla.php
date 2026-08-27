<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$nome    = $_SESSION['nome'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/agenda/config.php';

// ── Helpers de API GLPI ──
function apiGLPI(string $method, string $endpoint, array $data = []): array {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return ['ok'=>false, 'msg'=>'Falha ao autenticar no GLPI'];

    $headers = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];
    $url = GLPI_URL . '/apirest.php/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers];
    if ($method === 'GET') {
        // GET
    } elseif ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        $headers[] = 'Content-Type: application/json';
    } elseif ($method === 'PUT') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        $headers[] = 'Content-Type: application/json';
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Mata sessão
    $ch2 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers]);
    curl_exec($ch2); curl_close($ch2);

    $json = json_decode($res, true);
    return ['ok'=> $http >= 200 && $http < 300, 'http'=>$http, 'data'=>$json ?? $res, 'msg'=> $http >= 200 && $http < 300 ? 'OK' : ($json['ERROR'] ?? 'Erro HTTP '.$http)];
}

function apiGet(string $endpoint): array { return apiGLPI('GET', $endpoint); }

// ── Handlers ──
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'listar') {
    header('Content-Type: application/json');
    $busca = trim($_GET['busca'] ?? '');
    $endpoint = 'SLA?range=0-500&expand_dropdowns=true';
    if ($busca) $endpoint .= '&searchText[1]=' . urlencode($busca);
    $res = apiGet($endpoint);
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $slas = [];
    foreach ($res['data'] as $s) {
        if (!isset($s['id'])) continue;
        $type = $s['type'] ?? '';
        if (is_array($type)) $type = $type['name'] ?? $type['id'] ?? '';
        $slas[] = [
            'id'              => (int)$s['id'],
            'name'            => $s['name'] ?? '',
            'type'            => $type,
            'number_time'     => $s['number_time'] ?? '',
            'definition_time' => $s['definition_time'] ?? '',
            'internal_time'   => $s['internal_time'] ?? '',
            'internal_type'   => $s['internal_type'] ?? '',
            'comment'         => $s['comment'] ?? '',
            'is_active'       => isset($s['is_active']) ? ($s['is_active'] === true || $s['is_active'] === 1 || $s['is_active'] === '1') : true,
        ];
    }
    echo json_encode($slas);
    exit;
}

if ($action === 'detalhes') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
    $res = apiGet("SLA/{$id}?expand_dropdowns=true");
    if (!$res['ok'] || empty($res['data'])) { echo json_encode(['ok'=>false,'msg'=>'SLA não encontrada']); exit; }
    $s = $res['data'];
    $type = $s['type'] ?? '';
    if (is_array($type)) $type = $type['name'] ?? $type['id'] ?? '';
    $detail = [
        'id'              => (int)$s['id'],
        'name'            => $s['name'] ?? '',
        'type'            => $type,
        'number_time'     => $s['number_time'] ?? '',
        'definition_time' => $s['definition_time'] ?? '',
        'internal_time'   => $s['internal_time'] ?? '',
        'internal_type'   => $s['internal_type'] ?? '',
        'comment'         => $s['comment'] ?? '',
        'is_active'       => isset($s['is_active']) ? ($s['is_active'] === true || $s['is_active'] === 1 || $s['is_active'] === '1') : true,
        'fields'          => $res['data'],
    ];
    echo json_encode($detail);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>SLAs GLPI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }
    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; transition:.2s; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero {
      background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
      padding:2rem 1rem 4.5rem; text-align:center;
    }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p  { opacity:.8; margin:.3rem 0 0; }
    .wrap { max-width:1100px; margin:-3rem auto 3rem; padding:0 1rem; }

    .filtros-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
      margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;
    }

    .tabela-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
    }
    .tabela-card table { margin:0; font-size:.84rem; }
    .tabela-card thead th {
      background:#f9fafb; font-size:.75rem; font-weight:700;
      color:#6b7280; text-transform:uppercase; letter-spacing:.04em;
      border-bottom:2px solid #e5e7eb; padding:.65rem .8rem; white-space:nowrap;
    }
    .tabela-card tbody td { padding:.6rem .8rem; vertical-align:middle; border-color:#f3f4f6; }
    .tabela-card tbody tr { cursor:pointer; }
    .tabela-card tbody tr:hover { background:#e8f0fe; }

    .badge-ativo { background:#16a34a; color:white; font-size:.7rem; border-radius:20px; padding:.15rem .5rem; display:inline-block; }
    .badge-inativo { background:#9ca3af; color:white; font-size:.7rem; border-radius:20px; padding:.15rem .5rem; display:inline-block; }

    .empty { text-align:center; color:#9ca3af; padding:4rem 1rem; }
    .spin { animation:spin .8s linear infinite; }
    @keyframes spin { to{transform:rotate(360deg)} }

    .modal-detalhe dt { color:#6b7280; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-top:.75rem; }
    .modal-detalhe dd { margin-bottom:0; word-break:break-word; }

    @media(max-width:768px) { .tabela-card { overflow-x:auto; } }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-clock-fill"></i> SLAs GLPI</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-clock-fill me-2"></i>Consultar SLAs</h1>
  <p>Visualização dos Acordos de Nível de Serviço cadastrados no GLPI</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <input type="text" id="buscaInput" class="form-control form-control-sm" style="width:280px"
             placeholder="Buscar SLA pelo nome..." onkeyup="if(event.key==='Enter')carregarSLAs()"/>
    </div>
    <div>
      <button class="btn btn-primary btn-sm" onclick="carregarSLAs()"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
    <div style="margin-left:auto">
      <span class="text-muted small" id="totalCount"></span>
    </div>
  </div>

  <!-- Tabela -->
  <div class="tabela-card">
    <div id="tabelaContainer">
      <div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>
    </div>
  </div>

</div>

<!-- Modal Detalhes SLA -->
<div class="modal fade" id="modalDetalhes" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Detalhes da SLA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="detalhesConteudo">
          <div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  'use strict';

  let slaCache = [];

  const modalDetalhes = new bootstrap.Modal(document.getElementById('modalDetalhes'));

  // ── Carregar lista ──
  window.carregarSLAs = function() {
    const busca = document.getElementById('buscaInput').value;
    const params = new URLSearchParams({action:'listar'});
    if (busca) params.set('busca', busca);

    document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';

    fetch('sla.php?' + params.toString())
      .then(r => r.json())
      .then(dados => {
        slaCache = dados || [];
        renderizarTabela();
      })
      .catch(() => {
        document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i>Erro ao carregar.</div>';
      });
  };

  // ── Renderizar tabela ──
  function renderizarTabela() {
    const container = document.getElementById('tabelaContainer');
    const totalSpan = document.getElementById('totalCount');

    if (!slaCache.length) {
      container.innerHTML = '<div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhuma SLA encontrada.</div>';
      totalSpan.textContent = '';
      return;
    }

    totalSpan.textContent = slaCache.length + ' SLA(s) encontrada(s)';

    let html = '<table class="table table-hover"><thead><tr>' +
      '<th>ID</th><th>Nome</th><th>Tipo</th><th>Tempo Resolução</th><th>Ativo</th>' +
      '</tr></thead><tbody>';

    slaCache.forEach(s => {
      const ativoBadge = s.is_active ? '<span class="badge-ativo">Sim</span>' : '<span class="badge-inativo">Não</span>';
      const typeLabel = formatarTipoSLA(s.type);
      const tempo = s.number_time ? formatarTempo(s.number_time) : '-';
      html += '<tr onclick="abrirDetalhes(' + s.id + ')">';
      html += '<td class="fw-bold text-muted small">' + s.id + '</td>';
      html += '<td class="fw-semibold">' + htmlspecialchars(s.name) + '</td>';
      html += '<td>' + htmlspecialchars(typeLabel) + '</td>';
      html += '<td>' + htmlspecialchars(tempo) + '</td>';
      html += '<td>' + ativoBadge + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Abrir detalhes ──
  window.abrirDetalhes = function(id) {
    const s = slaCache.find(x => x.id == id);
    if (!s) return;

    document.getElementById('modalTitle').textContent = 'SLA: ' + htmlspecialchars(s.name || '#' + s.id);
    document.getElementById('detalhesConteudo').innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';
    modalDetalhes.show();

    // Busca detalhes completos
    fetch('sla.php?action=detalhes&id=' + id)
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          renderizarDetalhes(d);
        } else {
          renderizarDetalhes(s);
        }
      })
      .catch(() => {
        renderizarDetalhes(s);
      });
  };

  // ── Renderizar detalhes ──
  function renderizarDetalhes(d) {
    const container = document.getElementById('detalhesConteudo');
    const isActive = d.is_active ? '<span class="badge-ativo">Sim</span>' : '<span class="badge-inativo">Não</span>';

    let html = '<dl class="modal-detalhe row">';

    html += '<div class="col-sm-6"><dt>ID</dt><dd>' + d.id + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Nome</dt><dd>' + htmlspecialchars(d.name || '-') + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Tipo</dt><dd>' + htmlspecialchars(formatarTipoSLA(d.type)) + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Ativo</dt><dd>' + isActive + '</dd></div>';

    html += '<div class="col-12"><hr class="my-2"></div>';

    html += '<div class="col-sm-6"><dt>Tempo de Resolução</dt><dd>' + htmlspecialchars(formatarTempo(d.number_time)) + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Tempo de Definição</dt><dd>' + htmlspecialchars(formatarTempo(d.definition_time)) + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Tempo de Resposta Interna</dt><dd>' + htmlspecialchars(formatarTempo(d.internal_time)) + '</dd></div>';
    html += '<div class="col-sm-6"><dt>Tipo Interno</dt><dd>' + htmlspecialchars(formatarTipoInterno(d.internal_type)) + '</dd></div>';

    if (d.comment) {
      html += '<div class="col-12"><hr class="my-2"></div>';
      html += '<div class="col-12"><dt>Descrição / Comentário</dt><dd style="white-space:pre-wrap">' + htmlspecialchars(d.comment) + '</dd></div>';
    }

    // Mostrar campos adicionais (dados brutos)
    if (d.fields) {
      const extras = [];
      Object.entries(d.fields).forEach(([key, val]) => {
        if (['id','name','type','number_time','definition_time','internal_time','internal_type','comment','is_active'].includes(key)) return;
        if (val === null || val === '' || val === undefined) return;
        if (key.startsWith('_')) return;
        let label = key.replace(/_/g, ' ');
        label = label.charAt(0).toUpperCase() + label.slice(1);
        let value = val;
        if (typeof value === 'object' && value !== null) value = value.name ?? value.id ?? JSON.stringify(value);
        extras.push('<div class="col-sm-6"><dt>' + htmlspecialchars(label) + '</dt><dd>' + htmlspecialchars(String(value)) + '</dd></div>');
      });
      if (extras.length) {
        html += '<div class="col-12"><hr class="my-2"></div>';
        html += '<div class="col-12"><dt class="mb-2">Campos Adicionais</dt></div>';
        html += extras.join('');
      }
    }

    html += '</dl>';
    container.innerHTML = html;
  }

  // ── Utilitários ──
  function formatarTipoSLA(type) {
    if (!type || type === '0') return '-';
    const tipos = { '1': 'Incidente', '2': 'Requisição', '3': 'Ambos' };
    return tipos[String(type)] || type;
  }

  function formatarTempo(tempo) {
    if (!tempo || tempo === '0' || tempo === '0:00') return '-';
    return tempo;
  }

  function formatarTipoInterno(type) {
    if (!type || type === '0') return '-';
    const tipos = { '1': 'Minutos', '2': 'Horas', '3': 'Dias' };
    return tipos[String(type)] || type;
  }

  function htmlspecialchars(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
  }

  // ── Init ──
  carregarSLAs();

})();
</script>
</body>
</html>
