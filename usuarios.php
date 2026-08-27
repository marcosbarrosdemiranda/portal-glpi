<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$nome    = $_SESSION['nome']    ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';
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
    $endpoint = 'User?range=0-500&expand_dropdowns=true';
    if ($busca) $endpoint .= '&searchText[1]=' . urlencode($busca);
    // Campos adicionais
    $endpoint .= '&forcedisplay[0]=9&forcedisplay[1]=10&forcedisplay[2]=11&forcedisplay[3]=6'; // phone,mobile,phone2,email
    $res = apiGet($endpoint);
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    // Busca perfis de cada usuário via User_Profile
    $usuarios = [];
    foreach ($res['data'] as $u) {
        if (!isset($u['id']) || (int)$u['id'] < 2) continue; // ignora anonymous (id=1) e internos
        $usuario = [
            'id'         => (int)$u['id'],
            'name'       => $u['name'] ?? '',
            'realname'   => $u['realname'] ?? '',
            'firstname'  => $u['firstname'] ?? '',
            'email'      => $u['_email'] ?? $u['email'] ?? '',
            'phone'      => $u['phone'] ?? '',
            'mobile'     => $u['phone2'] ?? $u['mobile'] ?? '',
            'is_active'  => isset($u['is_active']) ? ($u['is_active'] === true || $u['is_active'] === 1 || $u['is_active'] === '1') : true,
            'entidade'   => $u['entities_id'] ?? '',
            'ultimo_login' => $u['last_login'] ?? '',
        ];
        $usuarios[] = $usuario;
    }
    echo json_encode($usuarios);
    exit;
}

if ($action === 'buscar_perfil_glpi') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);
    $res = apiGet("User_{$uid}_Profile?range=0-5");
    $perfil_id = 0;
    if ($res['ok'] && is_array($res['data']) && !empty($res['data'][0]['profiles_id'])) {
        $perfil_id = (int)$res['data'][0]['profiles_id'];
    }
    echo json_encode(['perfil_id' => $perfil_id]);
    exit;
}

if ($action === 'buscar_perfis_portal') {
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("SELECT id, nome FROM portal_perfis ORDER BY nome")->fetchAll();
        echo json_encode($rows);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

if ($action === 'buscar_perfil_usuario') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);
    try {
        $r = $pdo->prepare("SELECT perfil_id FROM portal_perfil_usuarios WHERE user_id=?");
        $r->execute([$uid]);
        echo json_encode(['perfil_id' => $r->fetchColumn() ?: 0]);
    } catch (Exception $e) { echo json_encode(['perfil_id' => 0]); }
    exit;
}

if ($action === 'buscar_perfis') {
    header('Content-Type: application/json');
    $res = apiGet('Profile?range=0-50');
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $perfis = [];
    foreach ($res['data'] as $p) {
        if (isset($p['id']) && (int)$p['id'] > 0) {
            $perfis[] = ['id'=>(int)$p['id'], 'nome'=>$p['name'] ?? ''];
        }
    }
    echo json_encode($perfis);
    exit;
}

if ($action === 'buscar_entidades') {
    header('Content-Type: application/json');
    $res = apiGet('Entity?range=0-100&expand_dropdowns=true');
    if (!$res['ok'] || !is_array($res['data'])) { echo json_encode([]); exit; }
    $entidades = [];
    foreach ($res['data'] as $e) {
        if (isset($e['id']) && (int)$e['id'] > 0) {
            $nome_e = $e['completename'] ?? $e['name'] ?? '';
            $entidades[] = ['id'=>(int)$e['id'], 'nome'=>html_entity_decode($nome_e, ENT_QUOTES|ENT_HTML5,'UTF-8')];
        }
    }
    echo json_encode($entidades);
    exit;
}

if ($action === 'salvar') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $realname = trim($_POST['realname'] ?? '');
    $firstname = trim($_POST['firstname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? '');
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $perfil_id = (int)($_POST['perfil_id'] ?? 0);
    $entidade_id = (int)($_POST['entidade_id'] ?? 0);
    $portal_perfil_id = (int)($_POST['portal_perfil_id'] ?? 0);

    if (!$name && !$realname) { echo json_encode(['ok'=>false,'msg'=>'Login ou nome é obrigatório']); exit; }

    $input = [
        'name'       => $name,
        'realname'   => $realname,
        'firstname'  => $firstname,
        '_email'     => $email,
        'phone'      => $phone,
        'phone2'     => $mobile,
        'is_active'  => $is_active,
    ];

    if ($id) {
        // Atualiza
        $res = apiPut("User/{$id}", $input);
        if ($res['ok'] && $password) {
            apiPut("User/{$id}", ['password' => $password, 'password2' => $password]);
        }
        if ($res['ok'] && ($perfil_id || $entidade_id)) {
            // Busca User_Profile existente
            $up = apiGet("User_{$id}_Profile?range=0-10");
            if ($up['ok'] && !empty($up['data'])) {
                foreach ($up['data'] as $up_item) {
                    if (isset($up_item['id'])) apiDelete("User_Profile/{$up_item['id']}");
                }
            }
            if ($perfil_id) {
                $up_input = ['users_id' => $id, 'profiles_id' => $perfil_id];
                if ($entidade_id) $up_input['entities_id'] = $entidade_id;
                apiPost('User_Profile', $up_input);
            }
        }
        // Salva perfil do portal
        try {
            if ($portal_perfil_id) {
                $pdo->prepare("INSERT INTO portal_perfil_usuarios (user_id, perfil_id) VALUES (?,?) ON DUPLICATE KEY UPDATE perfil_id=?")->execute([$id, $portal_perfil_id, $portal_perfil_id]);
            } else {
                $pdo->prepare("DELETE FROM portal_perfil_usuarios WHERE user_id=?")->execute([$id]);
            }
        } catch (Exception $e) {}
        echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Usuário atualizado!' : ($res['msg'] ?? 'Erro')]);
    } else {
        // Cria
        if (!$password) { echo json_encode(['ok'=>false,'msg'=>'Senha é obrigatória para novo usuário']); exit; }
        $input['password'] = $password;
        $input['password2'] = $password;
        $res = apiPost('User', $input);
        if ($res['ok'] && !empty($res['data']['id'])) {
            $novo_id = (int)$res['data']['id'];
            if ($perfil_id) {
                $up_input = ['users_id' => $novo_id, 'profiles_id' => $perfil_id];
                if ($entidade_id) $up_input['entities_id'] = $entidade_id;
                apiPost('User_Profile', $up_input);
            }
            // Salva perfil do portal
            try {
                if ($portal_perfil_id) {
                    $pdo->prepare("INSERT INTO portal_perfil_usuarios (user_id, perfil_id) VALUES (?,?) ON DUPLICATE KEY UPDATE perfil_id=?")->execute([$novo_id, $portal_perfil_id, $portal_perfil_id]);
                }
            } catch (Exception $e) {}
            echo json_encode(['ok'=>true, 'msg'=>"Usuário #{$novo_id} criado!"]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>$res['msg'] ?? 'Erro ao criar usuário', 'detail'=>$res['data'] ?? '']);
        }
    }
    exit;
}

if ($action === 'excluir') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 2) { echo json_encode(['ok'=>false,'msg'=>'Não é possível excluir este usuário']); exit; }
    $res = apiDelete("User/{$id}");
    echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['ok'] ? 'Usuário excluído!' : ($res['msg'] ?? 'Erro')]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Gestão de Usuários</title>
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
  <div class="brand"><i class="bi bi-people-fill"></i> Gestão de Usuários</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-people-fill me-2"></i>Gestão de Usuários GLPI</h1>
  <p>Cadastre, edite e gerencie usuários e suas permissões</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <input type="text" id="buscaInput" class="form-control form-control-sm" style="width:250px"
             placeholder="Buscar por nome ou login..." onkeyup="if(event.key==='Enter')carregarUsuarios()"/>
    </div>
    <div>
      <button class="btn btn-primary btn-sm" onclick="carregarUsuarios()"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
    <div style="margin-left:auto">
      <button class="btn btn-success btn-sm" onclick="abrirModal()"><i class="bi bi-person-plus me-1"></i>Novo Usuário</button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="tabela-card">
    <div id="tabelaContainer">
      <div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>
    </div>
  </div>

</div>

<!-- Modal Usuário -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="formUsuario" onsubmit="return salvarUsuario(event)">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Novo Usuário</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editId" value="0"/>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Login *</label>
              <input type="text" name="name" id="editName" class="form-control" required maxlength="100" placeholder="usuário"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sobrenome</label>
              <input type="text" name="realname" id="editRealname" class="form-control" placeholder="Silva"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Nome</label>
              <input type="text" name="firstname" id="editFirstname" class="form-control" placeholder="João"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">E-mail</label>
              <input type="email" name="email" id="editEmail" class="form-control" placeholder="joao@exemplo.com"/>
            </div>
            <div class="col-md-3">
              <label class="form-label">Telefone</label>
              <input type="text" name="phone" id="editPhone" class="form-control" placeholder="(67) 9999-0000"/>
            </div>
            <div class="col-md-3">
              <label class="form-label">Celular</label>
              <input type="text" name="mobile" id="editMobile" class="form-control" placeholder="(67) 99999-0000"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Senha <small class="text-muted">(deixe em branco para manter)</small></label>
              <input type="password" name="password" id="editPassword" class="form-control" placeholder="••••••••"/>
            </div>
            <div class="col-md-3">
              <label class="form-label">Perfil GLPI</label>
              <select name="perfil_id" id="editPerfil" class="form-select">
                <option value="">Selecione...</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Perfil do Portal</label>
              <select name="portal_perfil_id" id="editPerfilPortal" class="form-select">
                <option value="">Sem perfil (vê tudo)</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Loja padrão</label>
              <select name="entidade_id" id="editEntidade" class="form-select">
                <option value="">Todas</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" name="is_active" id="editActive" class="form-check-input" checked/>
                <label class="form-check-label" for="editActive">Usuário ativo</label>
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
        <p class="mt-2 mb-0 fw-bold">Excluir usuário?</p>
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

  let usuariosCache = [];
  let excluirId = null;

  const modalUsuario = new bootstrap.Modal(document.getElementById('modalUsuario'));
  const modalExcluir  = new bootstrap.Modal(document.getElementById('modalExcluir'));

  // ── Carregar lista ──
  window.carregarUsuarios = function() {
    const busca = document.getElementById('buscaInput').value;
    const params = new URLSearchParams({action:'listar'});
    if (busca) params.set('busca', busca);

    document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';

    fetch('usuarios.php?' + params.toString())
      .then(r => r.json())
      .then(dados => {
        usuariosCache = dados || [];
        renderizarTabela();
      })
      .catch(() => {
        document.getElementById('tabelaContainer').innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i>Erro ao carregar.</div>';
      });
  };

  // ── Renderizar tabela ──
  function renderizarTabela() {
    const container = document.getElementById('tabelaContainer');
    if (!usuariosCache.length) {
      container.innerHTML = '<div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum usuário encontrado.</div>';
      return;
    }

    let html = '<table class="table table-hover"><thead><tr>' +
      '<th>ID</th><th>Login</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Ativo</th><th style="width:120px">Ações</th>' +
      '</tr></thead><tbody>';

    usuariosCache.forEach(u => {
      const nomeCompleto = [u.realname, u.firstname].filter(Boolean).join(' ') || '-';
      const ativoBadge = u.is_active ? '<span class="badge-ativo">Ativo</span>' : '<span class="badge-inativo">Inativo</span>';
      html += '<tr>';
      html += `<td class="fw-bold text-muted small">${u.id}</td>`;
      html += `<td class="fw-semibold">${htmlspecialchars(u.name) || '-'}</td>`;
      html += `<td>${htmlspecialchars(nomeCompleto)}</td>`;
      html += `<td class="small">${htmlspecialchars(u.email) || '-'}</td>`;
      html += `<td class="small">${htmlspecialchars(u.phone || u.mobile) || '-'}</td>`;
      html += `<td>${ativoBadge}</td>`;
      html += '<td class="acoes">';
      html += `<button class="btn btn-sm btn-outline-primary me-1" onclick="abrirModal(${u.id})" title="Editar"><i class="bi bi-pencil"></i></button>`;
      if (u.id > 1) {
        html += `<button class="btn btn-sm btn-outline-danger" onclick="abrirModalExcluir(${u.id}, '${htmlspecialchars(u.name || '')}')" title="Excluir"><i class="bi bi-trash"></i></button>`;
      }
      html += '</td></tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Abrir modal criar/editar ──
  window.abrirModal = function(id) {
    document.getElementById('editId').value = 0;
    document.getElementById('editName').value = '';
    document.getElementById('editRealname').value = '';
    document.getElementById('editFirstname').value = '';
    document.getElementById('editEmail').value = '';
    document.getElementById('editPhone').value = '';
    document.getElementById('editMobile').value = '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editPassword').required = false;
    document.getElementById('editPerfil').value = '';
    document.getElementById('editPerfilPortal').value = '';
    document.getElementById('editEntidade').value = '';
    document.getElementById('editActive').checked = true;
    document.getElementById('modalTitle').textContent = 'Novo Usuário';

    if (id) {
      const u = usuariosCache.find(x => x.id == id);
      if (u) {
        document.getElementById('editId').value = u.id;
        document.getElementById('editName').value = u.name || '';
        document.getElementById('editRealname').value = u.realname || '';
        document.getElementById('editFirstname').value = u.firstname || '';
        document.getElementById('editEmail').value = u.email || '';
        document.getElementById('editPhone').value = u.phone || '';
        document.getElementById('editMobile').value = u.mobile || '';
        document.getElementById('editActive').checked = u.is_active;
        document.getElementById('modalTitle').textContent = 'Editar Usuário';
        // Carrega perfil GLPI e perfil do portal em paralelo
        Promise.all([
          fetch('usuarios.php?action=buscar_perfil_glpi&user_id=' + u.id).then(r => r.json()),
          fetch('usuarios.php?action=buscar_perfil_usuario&user_id=' + u.id).then(r => r.json()),
        ]).then(([glpi, portal]) => {
          if (glpi.perfil_id)   document.getElementById('editPerfil').value        = glpi.perfil_id;
          if (portal.perfil_id) document.getElementById('editPerfilPortal').value  = portal.perfil_id;
        });
      }
    } else {
      document.getElementById('editPassword').required = true;
    }

    modalUsuario.show();
  };

  // ── Salvar ──
  window.salvarUsuario = function(e) {
    e.preventDefault();
    const form = document.getElementById('formUsuario');
    const data = new FormData(form);
    data.set('action', 'salvar');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

    fetch('usuarios.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
        if (res.ok) {
          modalUsuario.hide();
          carregarUsuarios();
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
    document.getElementById('excluirInfo').textContent = `Excluir usuário "${nome}"? Esta ação não pode ser desfeita.`;
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
    fetch('usuarios.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        modalExcluir.hide();
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
        excluirId = null;
        if (res.ok) {
          carregarUsuarios();
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

  // ── Carregar selects do modal ──
  function carregarSelects() {
    // Perfis do Portal
    fetch('usuarios.php?action=buscar_perfis_portal')
      .then(r => r.json())
      .then(perfis => {
        const sel = document.getElementById('editPerfilPortal');
        perfis.forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.id;
          opt.textContent = p.nome;
          sel.appendChild(opt);
        });
      });
    // Perfis GLPI
    fetch('usuarios.php?action=buscar_perfis')
      .then(r => r.json())
      .then(perfis => {
        const sel = document.getElementById('editPerfil');
        perfis.forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.id;
          opt.textContent = p.nome;
          sel.appendChild(opt);
        });
      });
    // Entidades
    fetch('usuarios.php?action=buscar_entidades')
      .then(r => r.json())
      .then(entidades => {
        const sel = document.getElementById('editEntidade');
        entidades.forEach(e => {
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
  carregarSelects();
  carregarUsuarios();

})();
</script>
</body>
</html>
