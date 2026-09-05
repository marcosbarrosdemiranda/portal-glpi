<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';

// ── Auto-cria tabelas ──────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_perfis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) DEFAULT '',
    cards JSON,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_perfil_usuarios (
    user_id INT NOT NULL PRIMARY KEY,
    perfil_id INT NOT NULL,
    FOREIGN KEY (perfil_id) REFERENCES portal_perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Catálogo de cards disponíveis ─────────────────────────────────────────
$SECOES = [
    'Atendimento' => [
        'agenda'        => ['label' => 'Agenda de Atendimentos', 'icon' => 'bi-calendar3',           'css' => 'card-agenda'],
        'abrir_chamado' => ['label' => 'Abrir Chamado',          'icon' => 'bi-headset',             'css' => 'card-atendente'],
        'historico'     => ['label' => 'Histórico de Chamados',  'icon' => 'bi-clock-history',       'css' => 'card-historico'],
        'pendencias'    => ['label' => 'Pendências e Anotações', 'icon' => 'bi-sticky-fill',         'css' => 'card-pendencias'],
    ],
    'KPIs' => [
        'relatorios'    => ['label' => 'Painel de Relatórios',   'icon' => 'bi-bar-chart-fill',      'css' => 'card-relatorio'],
        'inventario'    => ['label' => 'Inventário',             'icon' => 'bi-pc-display',          'css' => 'card-inventario'],
    ],
    'Recursos' => [
        'conhecimento'  => ['label' => 'Área do Conhecimento',   'icon' => 'bi-book-fill',           'css' => 'card-conhecimento'],
        'cofre'         => ['label' => 'Cofre TI',               'icon' => 'bi-safe2-fill',          'css' => 'card-cofre'],
        'ferramentas_gmais' => ['label' => 'Ferramentas Gmais',  'icon' => 'bi-diagram-3-fill',      'css' => 'card-ferramentas-gmais'],
    ],
    'Acessos' => [
        'acesso_remoto' => ['label' => 'Acesso Remoto',          'icon' => 'bi-display-fill',        'css' => 'card-acesso-remoto'],
        'infra'         => ['label' => 'Infraestrutura',         'icon' => 'bi-hdd-network-fill',    'css' => 'card-infra'],
        'erp'           => ['label' => 'Ferramentas ERP',        'icon' => 'bi-building-fill-gear',  'css' => 'card-erp'],
    ],
    'Gestão de TI' => [
        'projetos'      => ['label' => 'Projetos',               'icon' => 'bi-kanban-fill',         'css' => 'card-projetos'],
        'equipe'        => ['label' => 'Equipe',                 'icon' => 'bi-people-fill',         'css' => 'card-equipe'],
        'orcamento'     => ['label' => 'Orçamento',              'icon' => 'bi-cash-coin',           'css' => 'card-orcamento'],
        'contratos'     => ['label' => 'Contratos',              'icon' => 'bi-file-earmark-text-fill', 'css' => 'card-contratos'],
        'licencas'      => ['label' => 'Licenças de Software',   'icon' => 'bi-key-fill',            'css' => 'card-licencas'],
        'reunioes_rp'   => ['label' => 'Reuniões RP',            'icon' => 'bi-people-fill',         'css' => 'card-reunioes-rp'],
    ],
    'Permissões da Agenda' => [
        'agenda_data_passada' => ['label' => 'Agendar / editar em datas passadas', 'icon' => 'bi-calendar-x', 'css' => 'card-agenda'],
    ],
    'Configuração' => [
        'usuarios'      => ['label' => 'Gestão de Usuários',     'icon' => 'bi-people-fill',         'css' => 'card-usuarios'],
        'categorias'    => ['label' => 'Categorias',             'icon' => 'bi-tags-fill',           'css' => 'card-categorias'],
        'entidades'     => ['label' => 'Lojas',                  'icon' => 'bi-shop',                'css' => 'card-gerenciar-entidades'],
        'sla'           => ['label' => 'SLAs',                   'icon' => 'bi-clock-fill',          'css' => 'card-sla-config'],
        'logs'          => ['label' => 'Logs do Portal',         'icon' => 'bi-journal-text',        'css' => 'card-logs'],
        'manutencao'    => ['label' => 'Manutenção',             'icon' => 'bi-tools',               'css' => 'card-manutencao'],
        'perfis'        => ['label' => 'Perfis de Usuário',      'icon' => 'bi-shield-person-fill',  'css' => 'card-usuarios'],
    ],
];

// ── Carrega usuários do GLPI ───────────────────────────────────────────────
function glpiUsuarios(): array {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return [];
    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];
    $ch2 = curl_init(GLPI_URL . '/apirest.php/User?range=0-500&is_active=1');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $lista = json_decode(curl_exec($ch2), true) ?: [];
    curl_close($ch2);
    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch3); curl_close($ch3);
    return array_filter($lista, fn($u) => isset($u['id']));
}

// ── Handlers POST ──────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg = ''; $msg_tipo = 'success';

if ($action === 'salvar_perfil') {
    $id    = (int)($_POST['perfil_id'] ?? 0);
    $nome  = trim($_POST['nome'] ?? '');
    $desc  = trim($_POST['descricao'] ?? '');
    $cards = [];
    foreach ($_POST['cards'] ?? [] as $key => $modo) {
        if (in_array($modo, ['ouvinte','interagir'])) $cards[$key] = $modo;
    }
    if (!$nome) { $msg = 'Nome obrigatório.'; $msg_tipo = 'danger'; }
    else {
        $cardsJson = json_encode($cards);
        if ($id) {
            $pdo->prepare("UPDATE portal_perfis SET nome=?, descricao=?, cards=? WHERE id=?")->execute([$nome, $desc, $cardsJson, $id]);
            $msg = "Perfil \"$nome\" atualizado.";
        } else {
            $pdo->prepare("INSERT INTO portal_perfis (nome, descricao, cards) VALUES (?,?,?)")->execute([$nome, $desc, $cardsJson]);
            $id = (int)$pdo->lastInsertId();
            $msg = "Perfil \"$nome\" criado.";
        }
        // Salva usuários atribuídos
        $pdo->prepare("DELETE FROM portal_perfil_usuarios WHERE perfil_id=?")->execute([$id]);
        $uids = array_filter(array_map('intval', $_POST['usuarios'] ?? []), fn($v) => $v > 0);
        $st = $pdo->prepare("INSERT IGNORE INTO portal_perfil_usuarios (user_id, perfil_id) VALUES (?,?)");
        foreach ($uids as $uid) $st->execute([$uid, $id]);
    }
} elseif ($action === 'excluir_perfil') {
    $id = (int)($_POST['perfil_id'] ?? 0);
    if ($id) {
        $nome = $pdo->query("SELECT nome FROM portal_perfis WHERE id=$id")->fetchColumn();
        $pdo->prepare("DELETE FROM portal_perfis WHERE id=?")->execute([$id]);
        $msg = "Perfil \"$nome\" excluído.";
    }
}

// ── Carrega dados ──────────────────────────────────────────────────────────
$perfis = $pdo->query("SELECT * FROM portal_perfis ORDER BY nome")->fetchAll();
$atribuicoes = $pdo->query("SELECT user_id, perfil_id FROM portal_perfil_usuarios")->fetchAll(PDO::FETCH_KEY_PAIR);
$usuarios_glpi = glpiUsuarios();
usort($usuarios_glpi, fn($a,$b) => strcmp(($a['realname']??'').($a['firstname']??''), ($b['realname']??'').($b['firstname']??'')));

// Perfil em edição (via GET)
$editId = (int)($_GET['editar'] ?? 0);
$editPerfil = null; $editCards = []; $editUsuarios = [];
if ($editId) {
    $editPerfil  = $pdo->query("SELECT * FROM portal_perfis WHERE id=$editId")->fetch();
    $editCards   = json_decode($editPerfil['cards'] ?? '{}', true) ?: [];
    $editUsuarios= array_keys(array_filter($atribuicoes, fn($pid) => $pid == $editId));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Perfis de Usuário — Central TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --accent: #1a73e8; }
    body { background: #f0f4f9; font-family: 'Segoe UI', sans-serif; }
    .topbar {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: .9rem 2rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 12px rgba(0,0,0,.25);
    }
    .topbar .brand { font-size: 1.1rem; font-weight: 700; display:flex; align-items:center; gap:.6rem; }
    .btn-back {
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3);
      color: white; border-radius: 8px; padding: .3rem .8rem;
      font-size: .82rem; cursor: pointer; text-decoration: none;
      transition: background .2s;
    }
    .btn-back:hover { background: rgba(255,255,255,.25); color: white; }
    .container-main { max-width: 1000px; margin: 2rem auto; padding: 0 1rem; }

    /* Perfil card */
    .perfil-card {
      background: white; border-radius: 14px;
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
      padding: 1.25rem 1.5rem; margin-bottom: 1rem;
      display: flex; align-items: center; gap: 1rem;
    }
    .perfil-card .perfil-nome { font-weight: 700; font-size: 1rem; }
    .perfil-card .perfil-desc { font-size: .82rem; color: #666; }
    .perfil-card .perfil-info { flex: 1; }
    .badge-card { background: #e8f0fe; color: #1a73e8; border-radius: 20px; padding: .15rem .55rem; font-size: .72rem; font-weight: 600; }
    .badge-user { background: #e6f4ea; color: #1e8e3e; border-radius: 20px; padding: .15rem .55rem; font-size: .72rem; font-weight: 600; }

    /* Form de edição */
    .edit-card {
      background: white; border-radius: 14px;
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
      padding: 1.75rem 2rem; margin-bottom: 2rem;
    }
    .secao-titulo {
      font-size: .7rem; font-weight: 700; letter-spacing: .08em;
      text-transform: uppercase; color: #888; margin: 1.25rem 0 .5rem;
      display: flex; align-items: center; gap: .4rem;
    }
    .secao-titulo::after { content:''; flex:1; height:1px; background:#eee; }

    /* Grid de cards */
    .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .6rem; }
    .card-item {
      border: 2px solid #e8e8e8; border-radius: 10px;
      padding: .65rem .75rem; cursor: pointer;
      transition: border-color .15s, background .15s;
    }
    .card-item.ativo { border-color: var(--accent); background: #f0f6ff; }
    .card-item .ci-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .35rem; }
    .card-item .ci-icon { font-size: 1rem; color: var(--accent); }
    .card-item .ci-label { font-size: .82rem; font-weight: 600; color: #333; }
    .card-item .ci-modo { display: none; gap: .3rem; }
    .card-item.ativo .ci-modo { display: flex; }
    .modo-btn {
      flex: 1; border: 1px solid #ccc; background: white; border-radius: 6px;
      font-size: .72rem; padding: .2rem .3rem; cursor: pointer; text-align: center;
      transition: all .15s;
    }
    .modo-btn.sel-ouvinte  { border-color: #f9a825; background: #fff8e1; color: #b06000; font-weight: 700; }
    .modo-btn.sel-interagir{ border-color: #1e8e3e; background: #e6f4ea; color: #1e8e3e; font-weight: 700; }

    /* Lista de usuários */
    .users-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .4rem; max-height: 280px; overflow-y: auto; padding: .25rem; }
    .user-check { display: flex; align-items: center; gap: .5rem; padding: .35rem .5rem; border-radius: 7px; cursor: pointer; }
    .user-check:hover { background: #f5f5f5; }
    .user-check input { accent-color: var(--accent); width: 15px; height: 15px; }
    .user-check .u-nome { font-size: .82rem; color: #333; }
    .user-check .u-login { font-size: .7rem; color: #888; }

    .btn-novo { background: var(--accent); color: white; border: none; border-radius: 10px; padding: .55rem 1.25rem; font-weight: 600; cursor: pointer; }
    .btn-novo:hover { opacity: .9; }
    .empty-state { text-align: center; padding: 3rem 1rem; color: #aaa; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-shield-person-fill"></i> Perfis de Usuário</div>
  <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
</div>

<div class="container-main">

<?php if ($msg): ?>
  <div class="alert alert-<?= $msg_tipo ?> alert-dismissible" role="alert">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($editPerfil !== null || isset($_GET['novo'])): ?>
<!-- ── Formulário de edição / criação ── -->
<div class="edit-card">
  <h5 class="fw-bold mb-3">
    <i class="bi bi-<?= $editPerfil ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
    <?= $editPerfil ? 'Editar Perfil' : 'Novo Perfil' ?>
  </h5>
  <form method="POST">
    <input type="hidden" name="action" value="salvar_perfil"/>
    <input type="hidden" name="perfil_id" value="<?= $editPerfil['id'] ?? 0 ?>"/>

    <div class="row g-3 mb-3">
      <div class="col-md-5">
        <label class="form-label fw-semibold">Nome do Perfil <span class="text-danger">*</span></label>
        <input type="text" name="nome" class="form-control" required
               value="<?= htmlspecialchars($editPerfil['nome'] ?? '') ?>"
               placeholder="Ex: Gerente de Loja"/>
      </div>
      <div class="col-md-7">
        <label class="form-label fw-semibold">Descrição</label>
        <input type="text" name="descricao" class="form-control"
               value="<?= htmlspecialchars($editPerfil['descricao'] ?? '') ?>"
               placeholder="Opcional"/>
      </div>
    </div>

    <!-- Cards por seção -->
    <label class="form-label fw-semibold">Cards do Dashboard</label>
    <p class="text-muted small mb-2">Marque os cards que este perfil pode ver. Para cada um, defina se o usuário é <strong>Ouvinte</strong> (só visualiza) ou <strong>Interagir</strong> (acesso completo).</p>

    <?php foreach ($SECOES as $secNome => $secCards): ?>
    <div class="secao-titulo"><i class="bi bi-dot"></i><?= $secNome ?></div>
    <div class="cards-grid mb-2">
      <?php foreach ($secCards as $key => $info):
        $modo = $editCards[$key] ?? '';
        $ativo = !empty($modo);
      ?>
      <div class="card-item <?= $ativo ? 'ativo' : '' ?>" onclick="toggleCard(this,'<?= $key ?>')">
        <div class="ci-head">
          <i class="bi <?= $info['icon'] ?> ci-icon"></i>
          <span class="ci-label"><?= htmlspecialchars($info['label']) ?></span>
        </div>
        <div class="ci-modo">
          <button type="button" class="modo-btn <?= $modo==='ouvinte' ? 'sel-ouvinte' : '' ?>"
                  onclick="setModo(event,this,'ouvinte')">👁 Ouvinte</button>
          <button type="button" class="modo-btn <?= $modo==='interagir' ? 'sel-interagir' : '' ?>"
                  onclick="setModo(event,this,'interagir')">✏️ Interagir</button>
        </div>
        <input type="hidden" name="cards[<?= $key ?>]" value="<?= $modo ?>" class="card-input"/>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Usuários -->
    <div class="secao-titulo mt-3"><i class="bi bi-dot"></i>Usuários atribuídos a este perfil</div>
    <div class="users-grid mb-3">
      <?php foreach ($usuarios_glpi as $u):
        $uid  = (int)$u['id'];
        $nome = trim(($u['realname']??'').' '.($u['firstname']??'')) ?: ($u['name']??'');
        $login= $u['name'] ?? '';
        $check= in_array($uid, $editUsuarios) ? 'checked' : '';
      ?>
      <label class="user-check">
        <input type="checkbox" name="usuarios[]" value="<?= $uid ?>" <?= $check ?>>
        <span>
          <div class="u-nome"><?= htmlspecialchars($nome) ?></div>
          <div class="u-login"><?= htmlspecialchars($login) ?></div>
        </span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      <a href="perfis.php" class="btn btn-outline-secondary">Cancelar</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── Lista de perfis ── -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0"><i class="bi bi-shield-person-fill me-2 text-primary"></i>Perfis cadastrados</h5>
  <a href="perfis.php?novo=1" class="btn-novo"><i class="bi bi-plus-lg me-1"></i>Novo Perfil</a>
</div>

<?php if (empty($perfis)): ?>
  <div class="edit-card empty-state">
    <i class="bi bi-shield-slash" style="font-size:2.5rem;"></i>
    <p class="mt-2 mb-0">Nenhum perfil cadastrado ainda.</p>
    <p class="small">Crie o primeiro perfil para controlar o acesso dos usuários.</p>
    <a href="perfis.php?novo=1" class="btn btn-primary mt-1">Criar primeiro perfil</a>
  </div>
<?php else: ?>
  <?php foreach ($perfis as $p):
    $pCards = json_decode($p['cards'] ?? '{}', true) ?: [];
    $usPerfil = array_keys(array_filter($atribuicoes, fn($pid) => $pid == $p['id']));
  ?>
  <div class="perfil-card">
    <div class="perfil-info">
      <div class="perfil-nome"><?= htmlspecialchars($p['nome']) ?></div>
      <?php if ($p['descricao']): ?><div class="perfil-desc"><?= htmlspecialchars($p['descricao']) ?></div><?php endif; ?>
      <div class="mt-1 d-flex flex-wrap gap-1">
        <span class="badge-card"><i class="bi bi-grid me-1"></i><?= count($pCards) ?> card<?= count($pCards) != 1 ? 's' : '' ?></span>
        <span class="badge-user"><i class="bi bi-person me-1"></i><?= count($usPerfil) ?> usuário<?= count($usPerfil) != 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="perfis.php?editar=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
      <form method="POST" onsubmit="return confirm('Excluir perfil &quot;<?= htmlspecialchars($p['nome']) ?>&quot;?')">
        <input type="hidden" name="action" value="excluir_perfil"/>
        <input type="hidden" name="perfil_id" value="<?= $p['id'] ?>"/>
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCard(el, key) {
  const ativo = el.classList.toggle('ativo');
  const input = el.querySelector('.card-input');
  if (!ativo) {
    input.value = '';
    el.querySelectorAll('.modo-btn').forEach(b => b.className = 'modo-btn');
  } else {
    // padrão: interagir
    setModoInput(el, 'interagir');
    el.querySelectorAll('.modo-btn').forEach(b => {
      b.className = 'modo-btn' + (b.dataset.modo === 'interagir' ? ' sel-interagir' : '');
    });
  }
}

function setModo(event, btn, modo) {
  event.stopPropagation();
  const item = btn.closest('.card-item');
  if (!item.classList.contains('ativo')) {
    item.classList.add('ativo');
  }
  setModoInput(item, modo);
  item.querySelectorAll('.modo-btn').forEach(b => {
    b.className = 'modo-btn' + (b === btn ? (modo==='ouvinte' ? ' sel-ouvinte' : ' sel-interagir') : '');
  });
}

function setModoInput(item, modo) {
  item.querySelector('.card-input').value = modo;
}

// Garante que modo-btn tenha data-modo
document.querySelectorAll('.modo-btn').forEach(b => {
  b.dataset.modo = b.textContent.includes('Ouvinte') ? 'ouvinte' : 'interagir';
});
</script>
</body>
</html>
