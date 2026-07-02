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
    $endpoint = 'ITILCategory?range=0-500&expand_dropdowns=true';
    $res = apiGet($endpoint);
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $categorias = [];
    foreach ($res['data'] as $c) {
        if (!isset($c['id']) || (int)$c['id'] < 1) continue;
        $nome_pai = '';
        if (!empty($c['itilcategories_id']) && is_array($c['itilcategories_id'])) {
            $nome_pai = $c['itilcategories_id']['name'] ?? $c['itilcategories_id'][0] ?? '';
        } elseif (!empty($c['itilcategories_id'])) {
            $nome_pai = $c['itilcategories_id'];
        }
        $categorias[] = [
            'id'              => (int)$c['id'],
            'name'            => $c['name'] ?? '',
            'completename'    => $c['completename'] ?? $c['name'] ?? '',
            'itilcategories_id' => is_array($c['itilcategories_id'] ?? null) ? ($c['itilcategories_id']['id'] ?? 0) : (int)($c['itilcategories_id'] ?? 0),
            'categoria_pai_nome' => $nome_pai,
            'comment'         => $c['comment'] ?? '',
            'is_active'       => isset($c['is_active']) ? ($c['is_active'] === true || $c['is_active'] === 1 || $c['is_active'] === '1') : true,
            'level'           => (int)($c['level'] ?? 0),
        ];
    }
    echo json_encode($categorias);
    exit;
}

if ($action === 'buscar_pais') {
    header('Content-Type: application/json');
    $res = apiGet('ITILCategory?range=0-500&expand_dropdowns=true');
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $pais = [];
    foreach ($res['data'] as $c) {
        if (isset($c['id']) && (int)$c['id'] > 0) {
            $nome = $c['completename'] ?? $c['name'] ?? '';
            $pais[] = ['id' => (int)$c['id'], 'nome' => html_entity_decode($nome, ENT_QUOTES|ENT_HTML5, 'UTF-8')];
        }
    }
    echo json_encode($pais);
    exit;
}

if ($action === 'salvar') {
    header('Content-Type: application/json');
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $categoria_pai = (int)($_POST['itilcategories_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    if (!$name) { echo json_encode(['ok'=>false, 'msg'=>'Nome é obrigatório']); exit; }

    $input = [
        'name'              => $name,
        'itilcategories_id' => $categoria_pai,
        'comment'           => $comment,
        'is_active'         => $is_active,
    ];

    if ($id) {
        $res = apiPut("ITILCategory/{$id}", $input);
        echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Categoria atualizada!' : ($res['msg'] ?? 'Erro')]);
    } else {
        $res = apiPost('ITILCategory', $input);
        if ($res['ok'] && !empty($res['data']['id'])) {
            $novo_id = (int)$res['data']['id'];
            echo json_encode(['ok'=>true, 'msg'=>"Categoria #{$novo_id} criada!"]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>$res['msg'] ?? 'Erro ao criar categoria', 'detail'=>$res['data'] ?? '']);
        }
    }
    exit;
}

if ($action === 'excluir') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'Categoria inválida']); exit; }
    $res = apiDelete("ITILCategory/{$id}");
    echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Categoria excluída!' : ($res['msg'] ?? 'Erro')]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Gestão de Categorias</title>
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
    .badge-nivel { background:#e8eaf6; color:#283593; font-size:.7rem; border-radius:20px; padding:.15rem .5rem; }

    .empty { text-align:center; color:#9ca3af; padding:4rem 1rem; }

    @media(max-width:768px) { .tabela-card { overflow-x:auto; } }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-tags-fill"></i> Gestão de Categorias</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-tags-fill me-2"></i>Gestão de Categorias GLPI</h1>
  <p>Cadastre, edite e gerencie as categorias de chamados (ITILCategory)</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <input type="text" id="buscaInput" class="form-control form-control-sm" style="width:250px"
             placeholder="Filtrar por nome..." onkeyup="if(event.key==='Enter')carregarCategorias()"/>
    </div>
    <div>
      <button class="btn btn-primary btn-sm" onclick="carregarCategorias()"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
    <div style="margin-left:auto">
      <button class="btn btn-success btn-sm" onclick="abrirModal()"><i class="bi bi-plus-circle me-1"></i>Nova Categoria</button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="tabela-card">
    <div id="tabelaContainer">
      <div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>
    </div>
  </div>

</div>

<!-- Modal Categoria -->
<div class="modal fade" id="modalCategoria" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="formCategoria" onsubmit="return salvarCategoria(event)">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Nova Categoria</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editId" value="0"/>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome *</label>
              <input type="text" name="name" id="editName" class="form-control" required maxlength="255" placeholder="Nome da categoria"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Categoria Pai</label>
              <select name="itilcategories_id" id="editCategoriaPai" class="form-select">
                <option value="0">Nenhuma (raiz)</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descrição / Completude</label>
              <textarea name="comment" id="editComment" class="form-control" rows="3" placeholder="Descrição da categoria..."></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" name="is_active" id="editActive" class="form-check-input" checked/>
                <label class="form-check-label" for="editActive">Categoria ativa</label>
              </div>
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
        <p class="mt-2 mb-0 fw-bold">Excluir categoria?</p>
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

  let categoriasCache = [];
  let categoriasPaisCache = [];
  let excluirId = null;

  const modalCategoria = new bootstrap.Modal(document.getElementById('modalCategoria'));
  const modalExcluir   = new bootstrap.Modal(document.getElementById('modalExcluir'));

  // ── Carregar lista ──
  window.carregarCategorias = function() {
    const busca = document.getElementById('buscaInput').value;

    document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';

    fetch('categorias.php?action=listar')
      .then(r => r.json())
      .then(dados => {
        categoriasCache = dados || [];
        if (busca) {
          const termo = busca.toLowerCase();
          categoriasCache = categoriasCache.filter(c =>
            (c.name && c.name.toLowerCase().includes(termo)) ||
            (c.categoria_pai_nome && c.categoria_pai_nome.toLowerCase().includes(termo))
          );
        }
        renderizarTabela();
      })
      .catch(() => {
        document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i>Erro ao carregar.</div>';
      });
  };

  // ── Renderizar tabela ──
  function renderizarTabela() {
    const container = document.getElementById('tabelaContainer');
    if (!categoriasCache.length) {
      container.innerHTML = '<div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhuma categoria encontrada.</div>';
      return;
    }

    let html = '<table class="table table-hover"><thead><tr>' +
      '<th>ID</th><th>Nome</th><th>Categoria Pai</th><th>Nível</th><th>Ativo</th><th style="width:120px">Ações</th>' +
      '</tr></thead><tbody>';

    categoriasCache.forEach(c => {
      const ativoBadge = c.is_active ? '<span class="badge-ativo">Ativo</span>' : '<span class="badge-inativo">Inativo</span>';
      const nivelBadge = c.level > 0 ? `<span class="badge-nivel">Nv ${c.level}</span>` : '<span class="badge-nivel">Raiz</span>';
      html += '<tr>';
      html += `<td class="fw-bold text-muted small">${c.id}</td>`;
      html += `<td class="fw-semibold">${htmlspecialchars(c.completename || c.name) || '-'}</td>`;
      html += `<td class="small">${htmlspecialchars(c.categoria_pai_nome) || '-'}</td>`;
      html += `<td>${nivelBadge}</td>`;
      html += `<td>${ativoBadge}</td>`;
      html += '<td class="acoes">';
      html += `<button class="btn btn-sm btn-outline-primary me-1" onclick="abrirModal(${c.id})" title="Editar"><i class="bi bi-pencil"></i></button>`;
      html += `<button class="btn btn-sm btn-outline-danger" onclick="abrirModalExcluir(${c.id}, '${htmlspecialchars(c.name || '')}')" title="Excluir"><i class="bi bi-trash"></i></button>`;
      html += '</td></tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Abrir modal criar/editar ──
  window.abrirModal = function(id) {
    document.getElementById('editId').value = 0;
    document.getElementById('editName').value = '';
    document.getElementById('editCategoriaPai').value = '0';
    document.getElementById('editComment').value = '';
    document.getElementById('editActive').checked = true;
    document.getElementById('modalTitle').textContent = 'Nova Categoria';

    if (id) {
      const c = categoriasCache.find(x => x.id == id);
      if (c) {
        document.getElementById('editId').value = c.id;
        document.getElementById('editName').value = c.name || '';
        document.getElementById('editCategoriaPai').value = c.itilcategories_id || 0;
        document.getElementById('editComment').value = c.comment || '';
        document.getElementById('editActive').checked = c.is_active;
        document.getElementById('modalTitle').textContent = 'Editar Categoria';
      }
    }

    modalCategoria.show();
  };

  // ── Salvar ──
  window.salvarCategoria = function(e) {
    e.preventDefault();
    const form = document.getElementById('formCategoria');
    const data = new FormData(form);
    data.set('action', 'salvar');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

    fetch('categorias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
        if (res.ok) {
          modalCategoria.hide();
          carregarCategorias();
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
    document.getElementById('excluirInfo').textContent = `Excluir categoria "${nome}"? Esta ação não pode ser desfeita.`;
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
    fetch('categorias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        modalExcluir.hide();
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
        excluirId = null;
        if (res.ok) {
          carregarCategorias();
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

  // ── Carregar categorias pai no select do modal ──
  function carregarCategoriasPai() {
    fetch('categorias.php?action=buscar_pais')
      .then(r => r.json())
      .then(pais => {
        categoriasPaisCache = pais || [];
        const sel = document.getElementById('editCategoriaPai');
        // Mantém a option "Nenhuma (raiz)"
        sel.innerHTML = '<option value="0">Nenhuma (raiz)</option>';
        pais.forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.id;
          opt.textContent = p.nome;
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
  carregarCategoriasPai();
  carregarCategorias();

})();
</script>
</body>
</html>
