<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$nome    = $_SESSION['nome']    ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

// ── Conexão PDO ──
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'glpi2';
$pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ── Cria tabela se não existir ──
$pdo->exec("CREATE TABLE IF NOT EXISTS anotacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entidade_id INT NOT NULL DEFAULT 0,
  titulo VARCHAR(255) NOT NULL,
  descricao TEXT,
  prioridade ENUM('baixa','media','alta') NOT NULL DEFAULT 'media',
  status ENUM('pendente','concluido','convertido') NOT NULL DEFAULT 'pendente',
  user_id INT NOT NULL DEFAULT 0,
  user_nome VARCHAR(100) NOT NULL DEFAULT '',
  ticket_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Carregar entidades do GLPI ──
function carregarEntidades(): array {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return [];
    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];
    $ch2 = curl_init(GLPI_URL . '/apirest.php/Entity?range=0-100&expand_dropdowns=true');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $entidades = json_decode(curl_exec($ch2), true) ?? [];
    curl_close($ch2);
    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch3); curl_close($ch3);
    $result = [];
    foreach ($entidades as $e) {
        if (!isset($e['id']) || (int)$e['id'] === 0) continue;
        $nome_e = $e['completename'] ?? $e['name'] ?? '';
        $nome_e = html_entity_decode($nome_e, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strtolower($nome_e) === 'entidade raiz' || !$nome_e) continue;
        $result[] = ['id' => (int)$e['id'], 'nome' => $nome_e];
    }
    usort($result, fn($a,$b) => strcmp($a['nome'], $b['nome']));
    return $result;
}

// ── Handlers AJAX ──
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'listar') {
    header('Content-Type: application/json');
    $filtro_entidade = (int)($_GET['entidade_id'] ?? 0);
    $filtro_status   = $_GET['status'] ?? '';
    $sql = "SELECT * FROM anotacoes WHERE 1=1";
    $params = [];
    if ($filtro_entidade) {
        $sql .= " AND entidade_id = ?";
        $params[] = $filtro_entidade;
    }
    if ($filtro_status && in_array($filtro_status, ['pendente','concluido','convertido'])) {
        $sql .= " AND status = ?";
        $params[] = $filtro_status;
    }
    $sql .= " ORDER BY FIELD(status,'pendente','convertido','concluido'), updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Mapeia entidade_id para nome via api
    $entidades = carregarEntidades();
    $ent_map = [];
    foreach ($entidades as $e) $ent_map[$e['id']] = $e['nome'];
    foreach ($rows as &$r) {
        $nome_ent = $ent_map[(int)$r['entidade_id']] ?? '';
        $r['entidade_nome'] = $nome_ent;
        $r['entidade_apelido'] = apelido_entidade($nome_ent);
    }
    echo json_encode($rows);
    exit;
}

if ($action === 'salvar') {
    header('Content-Type: application/json');
    $id          = (int)($_POST['id'] ?? 0);
    $entidade_id = (int)($_POST['entidade_id'] ?? 0);
    $titulo      = trim($_POST['titulo'] ?? '');
    $descricao   = trim($_POST['descricao'] ?? '');
    $prioridade  = $_POST['prioridade'] ?? 'media';
    if (!in_array($prioridade, ['baixa','media','alta'])) $prioridade = 'media';
    if (!$titulo) { echo json_encode(['ok'=>false,'msg'=>'Título é obrigatório']); exit; }
    if ($id) {
        $stmt = $pdo->prepare("UPDATE anotacoes SET entidade_id=?, titulo=?, descricao=?, prioridade=? WHERE id=?");
        $stmt->execute([$entidade_id, $titulo, $descricao, $prioridade, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO anotacoes (entidade_id, titulo, descricao, prioridade, user_id, user_nome) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$entidade_id, $titulo, $descricao, $prioridade, $user_id, $nome]);
    }
    echo json_encode(['ok'=>true, 'msg'=>'Salvo com sucesso!']);
    exit;
}

if ($action === 'excluir') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM anotacoes WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'concluir') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE anotacoes SET status='concluido' WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'reativar') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE anotacoes SET status='pendente' WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'converter') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM anotacoes WHERE id=?");
    $stmt->execute([$id]);
    $anot = $stmt->fetch();
    if (!$anot) { echo json_encode(['ok'=>false,'msg'=>'Anotação não encontrada']); exit; }

    // Cria chamado no GLPI via API
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) { echo json_encode(['ok'=>false,'msg'=>'Falha ao autenticar no GLPI']); exit; }
    $headers = ['Content-Type: application/json','Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN];

    // Prioridade da anotação → urgência GLPI
    $urg_map = ['baixa'=>2, 'media'=>3, 'alta'=>4];
    $urgencia = $urg_map[$anot['prioridade']] ?? 3;

    $input = [
        'name'    => $anot['titulo'],
        'content' => $anot['descricao'] ?: $anot['titulo'],
        'type'    => 1, // Incidente
        'urgency' => $urgencia,
        'priority'=> $urgencia,
        'status'  => 1, // Novo (não atribuir pra não impactar SLA)
    ];
    if (!empty($anot['entidade_id'])) $input['entities_id'] = (int)$anot['entidade_id'];

    $ch = curl_init(GLPI_URL . '/apirest.php/Ticket');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['input'=>$input]), CURLOPT_HTTPHEADER=>$headers]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);

    $ch = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers]);
    curl_exec($ch); curl_close($ch);

    if (!empty($res['id'])) {
        $ticket_id = (int)$res['id'];
        $pdo->prepare("UPDATE anotacoes SET status='convertido', ticket_id=? WHERE id=?")->execute([$ticket_id, $id]);
        echo json_encode(['ok'=>true, 'ticket_id'=>$ticket_id, 'msg'=>"Chamado #{$ticket_id} criado a partir da anotação!"]);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'Erro ao criar chamado no GLPI', 'detail'=>$res]);
    }
    exit;
}

// ── Lista entidades pro filtro ──
$entidades = carregarEntidades();
$filtro_entidade = (int)($_GET['entidade_id'] ?? 0);
$filtro_status   = $_GET['status'] ?? 'pendente';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Pendências e Anotações</title>
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
    .wrap { max-width:900px; margin:-3rem auto 3rem; padding:0 1rem; }

    /* Filtros */
    .filtros-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
      margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end;
    }
    .filtros-card .form-label { font-size:.78rem; font-weight:600; color:#6b7280; margin-bottom:.2rem; }

    /* Cards de anotações */
    .grupo-loja { margin-bottom:1.5rem; }
    .grupo-header {
      background:white; border-radius:12px 12px 0 0;
      padding:.7rem 1.25rem; border:1px solid #e5e7eb; border-bottom:none;
      font-weight:700; font-size:.9rem; color:var(--primary);
      display:flex; align-items:center; gap:.5rem;
    }
    .grupo-header .badge-count {
      background:var(--primary); color:white; font-size:.7rem;
      border-radius:20px; padding:.15rem .5rem; font-weight:600;
    }
    .anotacao-card {
      background:white; border:1px solid #e5e7eb; border-top:none;
      padding:.9rem 1.25rem; transition:background .15s;
    }
    .anotacao-card:last-child {
      border-radius:0 0 12px 12px; margin-bottom:0;
    }
    .anotacao-card:hover { background:#f8fafc; }

    .anotacao-card .titulo { font-weight:600; color:#111; font-size:.9rem; }
    .anotacao-card .descricao {
      font-size:.8rem; color:#6b7280; margin-top:.3rem;
      overflow:hidden; text-overflow:ellipsis; display:-webkit-box;
      -webkit-line-clamp:2; -webkit-box-orient:vertical;
    }
    .anotacao-card .meta {
      display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
      margin-top:.45rem; font-size:.75rem;
    }
    .anotacao-card .acoes {
      display:flex; align-items:center; gap:.35rem;
    }
    .anotacao-card .acoes button, .anotacao-card .acoes a {
      border:none; background:none; padding:.25rem .4rem;
      border-radius:6px; font-size:.8rem; transition:.2s;
      text-decoration:none; display:inline-flex; align-items:center; gap:.3rem;
    }
    .anotacao-card .acoes button:hover { background:#e5e7eb; }

    .prioridade-alta { border-left:4px solid #dc2626; }
    .prioridade-media { border-left:4px solid #d97706; }
    .prioridade-baixa { border-left:4px solid #16a34a; }
    .prioridade-concluido { border-left:4px solid #9ca3af; opacity:.65; }

    .badge-prioridade { font-size:.68rem; padding:.2rem .5rem; border-radius:20px; font-weight:600; }
    .badge-status { font-size:.68rem; padding:.2rem .5rem; border-radius:20px; font-weight:600; }

    .empty { text-align:center; color:#9ca3af; padding:4rem 1rem; }

    /* Modal visualização */
    .modal-view-header { padding:.9rem 1.25rem; border-bottom:1px solid #e5e7eb; display:flex; align-items:flex-start; gap:.6rem; }
    .modal-view-icon { font-size:1.4rem; line-height:1; flex-shrink:0; margin-top:.1rem; }
    .modal-view-titulo { font-size:.95rem; font-weight:700; color:#111; margin:0; }
    .modal-view-loja { font-size:.78rem; color:#6b7280; margin:.15rem 0 0; }
    .modal-view-badges { display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.4rem; }
    .modal-view-body { padding:.9rem 1.25rem; }
    .modal-view-section { margin-bottom:.85rem; }
    .modal-view-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin-bottom:.25rem; }
    .modal-view-value { font-size:.82rem; color:#374151; white-space:pre-wrap; line-height:1.55; }
    .modal-view-meta { display:flex; gap:.75rem; flex-wrap:wrap; background:#f8fafc; border-radius:8px; padding:.6rem .9rem; font-size:.75rem; color:#6b7280; }
    .modal-view-meta span { display:flex; align-items:center; gap:.25rem; }
    .anotacao-card { cursor:pointer; }
    .anotacao-card .acoes button, .anotacao-card .acoes a { position:relative; z-index:2; }

    @media(max-width:640px) {
      .filtros-card { flex-direction:column; }
      .anotacao-card .acoes { flex-wrap:wrap; }
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-sticky-fill"></i> Pendências e Anotações</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-sticky-fill me-2"></i>Pendências e Anotações</h1>
  <p>Registre observações, lembretes de visita e itens pendentes — converta em chamado quando necessário</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <div class="filtros-card">
    <div>
      <div class="form-label">Loja</div>
      <select id="filtroEntidade" class="form-select form-select-sm" style="width:200px">
        <option value="">Todas as lojas</option>
        <?php foreach ($entidades as $e): ?>
          <option value="<?= $e['id'] ?>" <?= $filtro_entidade===$e['id']?'selected':'' ?>><?= htmlspecialchars(apelido_entidade($e['nome'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <div class="form-label">Status</div>
      <select id="filtroStatus" class="form-select form-select-sm" style="width:150px">
        <option value="">Todas</option>
        <option value="pendente" <?= $filtro_status==='pendente'?'selected':'' ?>>Pendentes</option>
        <option value="concluido" <?= $filtro_status==='concluido'?'selected':'' ?>>Concluídas</option>
        <option value="convertido" <?= $filtro_status==='convertido'?'selected':'' ?>>Convertidas</option>
      </select>
    </div>
    <div>
      <button class="btn btn-primary btn-sm" onclick="filtrar()"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </div>
    <div style="margin-left:auto">
      <button class="btn btn-success btn-sm" onclick="abrirModal()"><i class="bi bi-plus-lg me-1"></i>Nova Pendência</button>
    </div>
  </div>

  <!-- Lista -->
  <div id="listaAnotacoes">
    <div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Carregando...</div>
  </div>

</div>

<!-- Modal Nova/Editar -->
<div class="modal fade" id="modalAnotacao" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="formAnotacao" onsubmit="return salvarAnotacao(event)">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Nova Pendência</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editId" value="0"/>
          <div class="mb-3">
            <label class="form-label">Loja</label>
            <select name="entidade_id" id="editEntidade" class="form-select" required>
              <option value="">Selecione...</option>
              <?php foreach ($entidades as $e): ?>
                <option value="<?= $e['id'] ?>"><?= htmlspecialchars(apelido_entidade($e['nome'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" id="editTitulo" class="form-control" required maxlength="255" placeholder="Ex: Verificar nobreak"/>
          </div>
          <div class="mb-3">
            <label class="form-label">Descrição</label>
            <textarea name="descricao" id="editDescricao" class="form-control" rows="4" placeholder="Detalhes da pendência..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Prioridade</label>
            <select name="prioridade" id="editPrioridade" class="form-select">
              <option value="baixa">🟢 Baixa</option>
              <option value="media" selected>🟡 Média</option>
              <option value="alta">🔴 Alta</option>
            </select>
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

<!-- Modal Confirmar Excluir -->
<div class="modal fade" id="modalExcluir" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:2.5rem"></i>
        <p class="mt-2 mb-0 fw-bold">Excluir anotação?</p>
        <p class="text-muted small">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnConfirmarExcluir"><i class="bi bi-trash me-1"></i>Excluir</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Converter -->
<div class="modal fade" id="modalConverter" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-arrow-right-circle-fill text-success" style="font-size:2.5rem"></i>
        <p class="mt-2 mb-0 fw-bold">Converter em chamado?</p>
        <p class="text-muted small">Um chamado será aberto no GLPI com os dados desta anotação.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success" id="btnConfirmarConverter"><i class="bi bi-ticket me-1"></i>Converter</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Visualizar -->
<div class="modal fade" id="modalVisualizar" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height:90vh">
    <div class="modal-content">
      <div class="modal-view-header">
        <div class="modal-view-icon" id="vIcon">📋</div>
        <div style="flex:1">
          <div class="modal-view-titulo" id="vTitulo">—</div>
          <div class="modal-view-loja" id="vLoja">—</div>
          <div class="modal-view-badges" id="vBadges"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-view-body">
        <div class="modal-view-section" id="vDescricaoWrap">
          <div class="modal-view-label">Descrição</div>
          <div class="modal-view-value" id="vDescricao">—</div>
        </div>
        <div class="modal-view-meta" id="vMeta"></div>
      </div>
      <div class="modal-footer" id="vFooter" style="gap:.5rem;flex-wrap:wrap"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/notificacoes.js"></script>
<script>
(function() {
  'use strict';

  let anotacoesCache = [];
  let excluirId = null;
  let converterId = null;

  const modalAnotacao   = new bootstrap.Modal(document.getElementById('modalAnotacao'));
  const modalExcluir    = new bootstrap.Modal(document.getElementById('modalExcluir'));
  const modalConverter  = new bootstrap.Modal(document.getElementById('modalConverter'));
  const modalVisualizar = new bootstrap.Modal(document.getElementById('modalVisualizar'));
  // Exposto no window: os botões do rodapé (onclick inline) rodam em escopo global,
  // e sem isso "modalVisualizar" resolveria para a <div id="modalVisualizar"> (auto-global do DOM), não o Modal.
  window.modalVisualizar = modalVisualizar;

  // ── Carregar lista ──
  function carregarLista() {
    const entidadeId = document.getElementById('filtroEntidade').value;
    const status     = document.getElementById('filtroStatus').value;
    const params = new URLSearchParams({action:'listar'});
    if (entidadeId) params.set('entidade_id', entidadeId);
    if (status) params.set('status', status);

    document.getElementById('listaAnotacoes').innerHTML = '<div class="empty"><i class="bi bi-arrow-repeat fs-1 d-block mb-2 spin"></i>Carregando...</div>';

    fetch('pendencias.php?' + params.toString())
      .then(r => r.json())
      .then(dados => {
        anotacoesCache = dados || [];
        renderizarLista();
      })
      .catch(() => {
        document.getElementById('listaAnotacoes').innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i>Erro ao carregar.</div>';
      });
  }

  // ── Renderizar ──
  function renderizarLista() {
    const container = document.getElementById('listaAnotacoes');
    if (!anotacoesCache.length) {
      container.innerHTML = '<div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhuma pendência encontrada.<br><span class="small">Clique em "Nova Pendência" para começar.</span></div>';
      return;
    }

    // Agrupa por entidade
    const grupos = {};
    anotacoesCache.forEach(a => {
      const chave = a.entidade_apelido || 'Sem loja';
      if (!grupos[chave]) grupos[chave] = [];
      grupos[chave].push(a);
    });

    let html = '';
    for (const [loja, itens] of Object.entries(grupos)) {
      const pendentes = itens.filter(i => i.status === 'pendente').length;
      html += '<div class="grupo-loja">';
      html += `<div class="grupo-header"><i class="bi bi-shop"></i> ${htmlspecialchars(loja)} <span class="badge-count">${pendentes} pendente${pendentes!==1?'s':''}</span></div>`;
      itens.forEach(a => {
        const prioridade = a.prioridade || 'media';
        const classeBorda = a.status === 'concluido' || a.status === 'convertido' ? 'prioridade-concluido' : 'prioridade-' + prioridade;
        html += `<div class="anotacao-card ${classeBorda}" onclick="abrirVisualizar(${a.id})">`;

        // Título
        html += `<div class="titulo">${htmlspecialchars(a.titulo)}`;
        if (a.ticket_id) html += ` <a href="chamado.php?id=${a.ticket_id}" class="text-decoration-none small" style="color:var(--accent)" target="_blank">#${a.ticket_id}</a>`;
        html += '</div>';

        // Descrição
        if (a.descricao) html += `<div class="descricao">${htmlspecialchars(a.descricao)}</div>`;

        // Meta
        html += '<div class="meta">';
        const prioridadeLabels = {baixa:'🟢 Baixa', media:'🟡 Média', alta:'🔴 Alta'};
        const statusLabels = {pendente:'<span class="badge bg-warning text-dark">Pendente</span>', concluido:'<span class="badge bg-secondary">Concluído</span>', convertido:'<span class="badge bg-info text-dark">Convertido</span>'};
        html += `<span>${prioridadeLabels[prioridade] || '🟡 Média'}</span>`;
        html += ` <span>${statusLabels[a.status] || a.status}</span>`;
        if (a.user_nome) html += ` <span class="text-muted"><i class="bi bi-person"></i> ${htmlspecialchars(a.user_nome)}</span>`;
        const criado = (a.created_at || '').substring(0, 16).replace('T', ' ');
        if (criado) html += ` <span class="text-muted"><i class="bi bi-calendar3"></i> ${criado}</span>`;

        // Ações
        html += '<span class="acoes ms-auto">';
        if (a.status === 'pendente') {
          html += `<button class="text-success" onclick="concluirAnotacao(${a.id})" title="Concluir"><i class="bi bi-check-circle"></i></button>`;
          html += `<button class="text-primary" onclick="abrirModal(${a.id})" title="Editar"><i class="bi bi-pencil"></i></button>`;
          html += `<button class="text-success" onclick="abrirModalConverter(${a.id})" title="Converter em chamado"><i class="bi bi-ticket"></i></button>`;
          html += `<button class="text-danger" onclick="abrirModalExcluir(${a.id})" title="Excluir"><i class="bi bi-trash"></i></button>`;
        } else if (a.status === 'convertido') {
          html += `<a href="chamado.php?id=${a.ticket_id}" class="text-info" title="Ver chamado" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>`;
          html += `<button class="text-secondary" onclick="reativarAnotacao(${a.id})" title="Reativar"><i class="bi bi-arrow-counterclockwise"></i></button>`;
          html += `<button class="text-danger" onclick="abrirModalExcluir(${a.id})" title="Excluir"><i class="bi bi-trash"></i></button>`;
        } else {
          html += `<button class="text-secondary" onclick="reativarAnotacao(${a.id})" title="Reativar"><i class="bi bi-arrow-counterclockwise"></i></button>`;
          html += `<button class="text-danger" onclick="abrirModalExcluir(${a.id})" title="Excluir"><i class="bi bi-trash"></i></button>`;
        }
        html += '</span>';

        html += '</div>'; // meta
        html += '</div>'; // anotacao-card
      });
      html += '</div>'; // grupo-loja
    }
    container.innerHTML = html;
  }

  // ── Visualizar pendência completa ──
  window.abrirVisualizar = function(id) {
    const a = anotacoesCache.find(x => x.id == id);
    if (!a) return;

    const prioridadeLabels = { baixa:'🟢 Baixa', media:'🟡 Média', alta:'🔴 Alta' };
    const statusIcons = { pendente:'📋', concluido:'✅', convertido:'🎫' };
    const statusBadges = {
      pendente: '<span class="badge bg-warning text-dark">Pendente</span>',
      concluido: '<span class="badge bg-secondary">Concluído</span>',
      convertido: '<span class="badge bg-info text-dark">Convertido em Chamado</span>'
    };
    const prioBadges = {
      baixa: '<span class="badge" style="background:#dcfce7;color:#166534">🟢 Baixa</span>',
      media: '<span class="badge" style="background:#fef9c3;color:#854d0e">🟡 Média</span>',
      alta:  '<span class="badge" style="background:#fee2e2;color:#991b1b">🔴 Alta</span>'
    };

    document.getElementById('vIcon').textContent = statusIcons[a.status] || '📋';
    document.getElementById('vTitulo').textContent = a.titulo;
    document.getElementById('vLoja').textContent = a.entidade_apelido || 'Sem loja';
    document.getElementById('vBadges').innerHTML = (statusBadges[a.status] || '') + ' ' + (prioBadges[a.prioridade] || '');

    const descEl = document.getElementById('vDescricao');
    const descWrap = document.getElementById('vDescricaoWrap');
    if (a.descricao && a.descricao.trim()) {
      descEl.textContent = a.descricao;
      descWrap.style.display = '';
    } else {
      descWrap.style.display = 'none';
    }

    const criado = (a.created_at || '').substring(0, 16).replace('T', ' ');
    const atualizado = (a.updated_at || '').substring(0, 16).replace('T', ' ');
    let meta = '';
    if (a.user_nome) meta += `<span><i class="bi bi-person-fill"></i> ${htmlspecialchars(a.user_nome)}</span>`;
    if (criado) meta += `<span><i class="bi bi-calendar3"></i> Criado: ${criado}</span>`;
    if (atualizado && atualizado !== criado) meta += `<span><i class="bi bi-clock-history"></i> Atualizado: ${atualizado}</span>`;
    if (a.ticket_id) meta += `<span><i class="bi bi-ticket-fill"></i> Chamado: <a href="chamado.php?id=${a.ticket_id}" target="_blank">#${a.ticket_id}</a></span>`;
    document.getElementById('vMeta').innerHTML = meta;

    // Footer com ações conforme status
    let footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';
    if (a.status === 'pendente') {
      footer += `<button class="btn btn-outline-success btn-sm" onclick="modalVisualizar.hide();concluirAnotacao(${a.id})"><i class="bi bi-check-circle me-1"></i>Concluir</button>`;
      footer += `<button class="btn btn-outline-primary btn-sm" onclick="modalVisualizar.hide();abrirModal(${a.id})"><i class="bi bi-pencil me-1"></i>Editar</button>`;
      footer += `<button class="btn btn-outline-success btn-sm" onclick="modalVisualizar.hide();abrirModalConverter(${a.id})"><i class="bi bi-ticket me-1"></i>Converter em Chamado</button>`;
      footer += `<button class="btn btn-outline-danger btn-sm" onclick="modalVisualizar.hide();abrirModalExcluir(${a.id})"><i class="bi bi-trash me-1"></i>Excluir</button>`;
    } else if (a.status === 'convertido') {
      if (a.ticket_id) footer += `<a href="chamado.php?id=${a.ticket_id}" class="btn btn-outline-info btn-sm" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver Chamado #${a.ticket_id}</a>`;
      footer += `<button class="btn btn-outline-secondary btn-sm" onclick="modalVisualizar.hide();reativarAnotacao(${a.id})"><i class="bi bi-arrow-counterclockwise me-1"></i>Reativar</button>`;
      footer += `<button class="btn btn-outline-danger btn-sm" onclick="modalVisualizar.hide();abrirModalExcluir(${a.id})"><i class="bi bi-trash me-1"></i>Excluir</button>`;
    } else {
      footer += `<button class="btn btn-outline-secondary btn-sm" onclick="modalVisualizar.hide();reativarAnotacao(${a.id})"><i class="bi bi-arrow-counterclockwise me-1"></i>Reativar</button>`;
      footer += `<button class="btn btn-outline-danger btn-sm" onclick="modalVisualizar.hide();abrirModalExcluir(${a.id})"><i class="bi bi-trash me-1"></i>Excluir</button>`;
    }
    document.getElementById('vFooter').innerHTML = footer;

    modalVisualizar.show();
  };

  // ── Abrir modal criar/editar ──
  window.abrirModal = function(id) {
    document.getElementById('editId').value = 0;
    document.getElementById('editEntidade').value = '';
    document.getElementById('editTitulo').value = '';
    document.getElementById('editDescricao').value = '';
    document.getElementById('editPrioridade').value = 'media';
    document.getElementById('modalTitle').textContent = 'Nova Pendência';

    if (id) {
      const a = anotacoesCache.find(x => x.id == id);
      if (a) {
        document.getElementById('editId').value = a.id;
        document.getElementById('editEntidade').value = a.entidade_id;
        document.getElementById('editTitulo').value = a.titulo;
        document.getElementById('editDescricao').value = a.descricao || '';
        document.getElementById('editPrioridade').value = a.prioridade || 'media';
        document.getElementById('modalTitle').textContent = 'Editar Pendência';
      }
    }
    modalAnotacao.show();
  };

  // ── Salvar ──
  window.salvarAnotacao = function(e) {
    e.preventDefault();
    const form = document.getElementById('formAnotacao');
    const data = new FormData(form);
    data.set('action', 'salvar');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

    fetch('pendencias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar';
        if (res.ok) {
          modalAnotacao.hide();
          carregarLista();
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

  // ── Concluir ──
  window.concluirAnotacao = function(id) {
    const data = new FormData();
    data.set('action', 'concluir');
    data.set('id', id);
    fetch('pendencias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(() => carregarLista());
  };

  // ── Reativar ──
  window.reativarAnotacao = function(id) {
    const data = new FormData();
    data.set('action', 'reativar');
    data.set('id', id);
    fetch('pendencias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(() => carregarLista());
  };

  // ── Modal excluir ──
  window.abrirModalExcluir = function(id) {
    excluirId = id;
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
    fetch('pendencias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(() => {
        modalExcluir.hide();
        excluirId = null;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
        carregarLista();
      })
      .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash me-1"></i>Excluir';
      });
  });

  // ── Modal converter ──
  window.abrirModalConverter = function(id) {
    converterId = id;
    modalConverter.show();
  };

  document.getElementById('btnConfirmarConverter').addEventListener('click', function() {
    if (!converterId) return;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Convertendo...';
    const data = new FormData();
    data.set('action', 'converter');
    data.set('id', converterId);
    fetch('pendencias.php', {method:'POST', body:data})
      .then(r => r.json())
      .then(res => {
        modalConverter.hide();
        converterId = null;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-ticket me-1"></i>Converter';
        if (res.ok) {
          carregarLista();
        } else {
          alert(res.msg || 'Erro ao converter');
        }
      })
      .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-ticket me-1"></i>Converter';
        alert('Erro de conexão');
      });
  });

  // ── Filtrar ──
  window.filtrar = function() {
    const params = new URLSearchParams();
    const entId = document.getElementById('filtroEntidade').value;
    const status = document.getElementById('filtroStatus').value;
    if (entId) params.set('entidade_id', entId);
    if (status) params.set('status', status);
    const qs = params.toString();
    const novaUrl = 'pendencias.php' + (qs ? '?' + qs : '');
    window.history.replaceState({}, '', novaUrl);
    carregarLista();
  };

  function htmlspecialchars(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  // ── Init ──
  carregarLista();

})();
</script>
</body>
</html>
