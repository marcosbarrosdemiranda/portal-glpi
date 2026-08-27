<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$nome    = $_SESSION['nome']    ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

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
function apiPost(string $endpoint, array $data): array { return apiGLPI('POST', $endpoint, ['input' => $data]); }
function apiPut(string $endpoint, array $data): array { return apiGLPI('PUT', $endpoint, ['input' => $data]); }
function apiDelete(string $endpoint): array { return apiGLPI('DELETE', $endpoint); }

// ── Handlers ──
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'listar') {
    header('Content-Type: application/json');
    $busca = trim($_GET['busca'] ?? '');
    $endpoint = 'Entity?range=0-500&expand_dropdowns=true';
    if ($busca) $endpoint .= '&searchText[1]=' . urlencode($busca);
    $res = apiGet($endpoint);
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $entidades = [];
    foreach ($res['data'] as $e) {
        if (!isset($e['id']) || (int)$e['id'] < 1) continue;
        $entidades[] = [
            'id'           => (int)$e['id'],
            'name'         => $e['name'] ?? '',
            'completename' => $e['completename'] ?? $e['name'] ?? '',
            'entities_id'  => $e['entities_id'] ?? '',
            'code'         => $e['code'] ?? '',
            'address'      => $e['address'] ?? '',
            'postcode'     => $e['postcode'] ?? '',
            'town'         => $e['town'] ?? '',
            'country'      => $e['country'] ?? '',
            'phonenumber'  => $e['phonenumber'] ?? '',
            'email'        => $e['email'] ?? '',
            'is_active'    => isset($e['is_active']) ? ($e['is_active'] === true || $e['is_active'] === 1 || $e['is_active'] === '1') : true,
            'comment'      => $e['comment'] ?? '',
        ];
    }
    echo json_encode($entidades);
    exit;
}

if ($action === 'buscar_pai') {
    header('Content-Type: application/json');
    $res = apiGet('Entity?range=0-500&expand_dropdowns=true');
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $arvore = [];
    foreach ($res['data'] as $e) {
        if (isset($e['id']) && (int)$e['id'] > 0) {
            $nome_e = $e['completename'] ?? $e['name'] ?? '';
            $arvore[] = ['id'=>(int)$e['id'], 'nome'=>html_entity_decode($nome_e, ENT_QUOTES|ENT_HTML5,'UTF-8')];
        }
    }
    usort($arvore, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    echo json_encode($arvore);
    exit;
}

if ($action === 'salvar') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $entities_id = (int)($_POST['entities_id'] ?? 0);
    $code        = trim($_POST['code'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $postcode    = trim($_POST['postcode'] ?? '');
    $town        = trim($_POST['town'] ?? '');
    $country     = trim($_POST['country'] ?? '');
    $phonenumber = trim($_POST['phonenumber'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $is_active   = !empty($_POST['is_active']) ? 1 : 0;
    $comment     = trim($_POST['comment'] ?? '');

    if (!$name) { echo json_encode(['ok'=>false,'msg'=>'O nome da entidade é obrigatório']); exit; }

    $input = [
        'name'        => $name,
        'code'        => $code,
        'address'     => $address,
        'postcode'    => $postcode,
        'town'        => $town,
        'country'     => $country,
        'phonenumber' => $phonenumber,
        'email'       => $email,
        'is_active'   => $is_active,
        'comment'     => $comment,
    ];

    if ($entities_id) $input['entities_id'] = $entities_id;

    if ($id) {
        $res = apiPut("Entity/{$id}", $input);
        echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Entidade atualizada!' : ($res['msg'] ?? 'Erro')]);
    } else {
        $res = apiPost('Entity', $input);
        if ($res['ok'] && !empty($res['data']['id'])) {
            echo json_encode(['ok'=>true, 'msg'=>"Entidade #{$res['data']['id']} criada!"]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>$res['msg'] ?? 'Erro ao criar entidade', 'detail'=>$res['data'] ?? '']);
        }
    }
    exit;
}

if ($action === 'excluir') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
    $res = apiDelete("Entity/{$id}");
    echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Entidade excluída!' : ($res['msg'] ?? 'Erro')]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Gestão de Entidades</title>
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
    .tabela-card tbody tr:hover { background:#e8f0fe; }

    .badge-ativo { background:#16a34a; color:white; font-size:.7rem; border-radius:20px; padding:.15rem .5rem; }
    .badge-inativo { background:#9ca3af; color:white; font-size:.7rem; border-radius:20px; padding:.15rem .5rem; }

    .empty { text-align:center; color:#9ca3af; padding:4rem 1rem; }

    @media(max-width:768px) { .tabela-card { overflow-x:auto; } }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-building me-1"></i> Gestão de Entidades</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-building me-2"></i>Gestão de Lojas / Entidades GLPI</h1>
  <p>Cadastre, edite e gerencie as entidades (lojas, filiais, departamentos)</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <input type="text" id="buscaInput" class="form-control form-control-sm" style="width:250px"
             placeholder="Buscar por nome..." onkeyup="if(event.key==='Enter')carregarEntidades()"/>
    </div>
    <div>
      <button class="btn btn-primary btn-sm" onclick="carregarEntidades()"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
    <div style="margin-left:auto">
      <button class="btn btn-success btn-sm" onclick="abrirModal()"><i class="bi bi-building-add me-1"></i>Nova Entidade</button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="tabela-card">
    <div id="tabelaContainer">
      <div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>
    </div>
  </div>

</div>

<!-- Modal Entidade -->
<div class="modal fade" id="modalEntidade" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="formEntidade" onsubmit="return salvarEntidade(event)">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Nova Entidade</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editId" value="0"/>
          <div class="row g-3">
            <!-- Nome -->
            <div class="col-md-6">
              <label class="form-label">Nome *</label>
              <input type="text" name="name" id="editName" class="form-control" required maxlength="255" placeholder="Nome da entidade"/>
            </div>
            <!-- Entidade pai -->
            <div class="col-md-6">
              <label class="form-label">Entidade pai</label>
              <select name="entities_id" id="editPai" class="form-select">
                <option value="">Nenhum (raiz)</option>
              </select>
            </div>
            <!-- Código -->
            <div class="col-md-6">
              <label class="form-label">Código</label>
              <input type="text" name="code" id="editCode" class="form-control" maxlength="255" placeholder="Ex: LJ-001"/>
            </div>
            <!-- E-mail -->
            <div class="col-md-6">
              <label class="form-label">E-mail</label>
              <input type="email" name="email" id="editEmail" class="form-control" placeholder="loja@exemplo.com"/>
            </div>
            <!-- Endereço -->
            <div class="col-12">
              <label class="form-label">Endereço</label>
              <input type="text" name="address" id="editAddress" class="form-control" placeholder="Rua, número, bairro"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">CEP</label>
              <input type="text" name="postcode" id="editPostcode" class="form-control" placeholder="00000-000"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Cidade</label>
              <input type="text" name="town" id="editTown" class="form-control" placeholder="Campo Grande"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">País</label>
              <input type="text" name="country" id="editCountry" class="form-control" placeholder="Brasil"/>
            </div>
            <!-- Telefone -->
            <div class="col-md-6">
              <label class="form-label">Telefone</label>
              <input type="text" name="phonenumber" id="editPhone" class="form-control" placeholder="(67) 9999-0000"/>
            </div>
            <!-- Ativo -->
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check mb-2">
                <input type="checkbox" name="is_active" id="editActive" class="form-check-input" checked/>
                <label class="form-check-label" for="editActive">Entidade ativa</label>
              </div>
            </div>
            <!-- Observação -->
            <div class="col-12">
              <label class="form-label">Observação</label>
              <textarea name="comment" id="editComment" class="form-control" rows="3" placeholder="Observações..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Excluir -->
<div class="modal fade" id="modalExcluir" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:2.5rem"></i>
        <p class="mt-2 mb-0 fw-bold">Excluir entidade?</p>
        <p class="text-muted small" id="excluirInfo">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnConfirmarExcluir"><i class="bi bi-trash me-1"></i>Excluir</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/notificacoes.js"></script>
<script>
(function() {
  'use strict';

  let entidadesCache = [];
  let excluirId = null;

  const modalEntidade = new bootstrap.Modal(document.getElementById('modalEntidade'));
  const modalExcluir  = new bootstrap.Modal(document.getElementById('modalExcluir'));

  // ── Carregar lista ──
  window.carregarEntidades = function() {
    const busca = document.getElementById('buscaInput').value;
    const params = new URLSearchParams({action:'listar'});
    if (busca) params.set('busca', busca);

    document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';

    fetch('gerenciar_entidades.php?' + params.toString())
      .then(r => r.json())
      .then(dados => {
        entidadesCache = dados || [];
        renderizarTabela();
      })
      .catch(() => {
        document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i>Erro ao carregar.</div>';
      });
  };

  // ── Renderizar tabela ──
  function renderizarTabela() {
    const container = document.getElementById('tabelaContainer');
    if (!entidadesCache.length) {
      container.innerHTML = '<div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhuma entidade encontrada.</div>';
      return;
    }

    let html = '<table class="table table-hover"><thead><tr>' +
      '<th>ID</th><th>Nome</th><th>Código</th><th>Cidade</th><th>Telefone</th><th>Ativo</th><th style="width:120px">Ações</th>' +
      '</tr></thead><tbody>';

    entidadesCache.forEach(e => {
      const ativoBadge = e.is_active ? '<span class="badge-ativo">Ativo</span>' : '<span class="badge-inativo">Inativo</span>';
      html += '<tr>';
      html += `<td class="fw-bold text-muted small">${e.id}</td>`;
      html += `<td class="fw-semibold">${htmlspecialchars(e.completename || e.name || '-')}</td>`;
      html += `<td class="small">${htmlspecialchars(e.code) || '-'}</td>`;
      html += `<td class="small">${htmlspecialchars(e.town) || '-'}</td>`;
      html += `<td class="small">${htmlspecialchars(e.phonenumber) || '-'}</td>`;
      html += `<td>${ativoBadge}</td>`;
      html += '<td class="acoes">';
      html += `<button class="btn btn-sm btn-outline-primary me-1" onclick="abrirModal(${e.id})" title="Editar"><i class="bi bi-pencil"></i></button>`;
      html += `<button class="btn btn-sm btn-outline-danger" onclick="abrirModalExcluir(${e.id}, '${htmlspecialchars(e.completename || e.name || '')}')" title="Excluir"><i class="bi bi-trash"></i></button>`;
      html += '</td></tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Abrir modal criar/editar ──
  window.abrirModal = function(id) {
    document.getElementById('editId').value = 0;
    document.getElementById('editName').value = '';
    document.getElementById('editPai').value = '';
    document.getElementById('editCode').value = '';
    document.getElementById('editAddress').value = '';
    document.getElementById('editPostcode').value = '';
    document.getElementById('editTown').value = '';
    document.getElementById('editCountry').value = '';
    document.getElementById('editPhone').value = '';
    document.getElementById('editEmail').value = '';
    document.getElementById('editComment').value = '';
    document.getElementById('editActive').checked = true;
    document.getElementById('modalTitle').textContent = 'Nova Entidade';

    if (id) {
      const e = entidadesCache.find(x => x.id == id);
      if (e) {
        document.getElementById('editId').value = e.id;
        document.getElementById('editName').value = e.name || '';
        document.getElementById('editPai').value = e.entities_id || '';
        document.getElementById('editCode').value = e.code || '';
        document.getElementById('editAddress').value = e.address || '';
        document.getElementById('editPostcode').value = e.postcode || '';
        document.getElementById('editTown').value = e.town || '';
        document.getElementById('editCountry').value = e.country || '';
        document.getElementById('editPhone').value = e.phonenumber || '';
        document.getElementById('editEmail').value = e.email || '';
        document.getElementById('editComment').value = e.comment || '';
        document.getElementById('editActive').checked = e.is_active;
        document.getElementById('modalTitle').textContent = 'Editar Entidade';
      }
    }

    modalEntidade.show();
  };

  // ── Salvar ──
  window.salvarEntidade = function(e) {
    e.preventDefault();
    const form = document.getElementById('formEntidade');
    const data = new FormData(form);
    data.set('action', 'salvar');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

    fetch('gerenciar_entidades.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
        if (res.ok) {
          modalEntidade.hide();
          carregarEntidades();
        } else {
          alert(res.msg || 'Erro ao salvar');
        }
      })
      .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
        alert('Erro de conexão');
      });
    return false;
  };

  // ── Excluir ──
  window.abrirModalExcluir = function(id, nome) {
    excluirId = id;
    document.getElementById('excluirInfo').textContent = `Excluir entidade "${nome}"? Esta ação não pode ser desfeita.`;
    modalExcluir.show();
  };

  document.getElementById('btnConfirmarExcluir').addEventListener('click', function() {
    if (!excluirId) return;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Excluindo...';
    const data = new FormData();
    data.set('action', 'excluir');
    data.set('id', excluirId);
    fetch('gerenciar_entidades.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        modalExcluir.hide();
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
        excluirId = null;
        if (res.ok) {
          carregarEntidades();
        } else {
          alert(res.msg || 'Erro ao excluir');
        }
      })
      .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
        alert('Erro de conexão');
      });
  });

  // ── Carregar select de entidade pai ──
  function carregarSelectPai() {
    fetch('gerenciar_entidades.php?action=buscar_pai')
      .then(r => r.json())
      .then(entidades => {
        const sel = document.getElementById('editPai');
        // Preserva option "Nenhum (raiz)"
        sel.innerHTML = '<option value="">Nenhum (raiz)</option>';
        entidades.forEach(e => {
          // Não permite auto-referência (para edição será tratado pelo backend)
          const opt = document.createElement('option');
          opt.value = e.id;
          opt.textContent = e.nome;
          sel.appendChild(opt);
        });
      });
  }

  function htmlspecialchars(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  // ── Init ──
  carregarSelectPai();
  carregarEntidades();

})();
</script>
</body>
</html>
