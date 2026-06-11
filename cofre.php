<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';    // PDO $pdo
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php'; // VAULT_KEY + vault_encrypt/decrypt

// ── Cria tabelas se não existirem ──────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vault (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        titulo       VARCHAR(255)  NOT NULL,
        categoria    VARCHAR(50)   NOT NULL DEFAULT 'senha',
        usuario      VARCHAR(255)  DEFAULT NULL,
        conteudo     TEXT          DEFAULT NULL,
        url          VARCHAR(500)  DEFAULT NULL,
        tags         VARCHAR(255)  DEFAULT NULL,
        notas        TEXT          DEFAULT NULL,
        criado_em    DATETIME      DEFAULT CURRENT_TIMESTAMP,
        modificado_em DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vault_pin (
        user_id      INT PRIMARY KEY,
        pin_hash     VARCHAR(255) NOT NULL,
        criado_em    DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vault_audit (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT          DEFAULT NULL,
        user_nome    VARCHAR(255) DEFAULT NULL,
        acao         VARCHAR(30)  NOT NULL,
        item_id      INT          DEFAULT NULL,
        item_titulo  VARCHAR(255) DEFAULT NULL,
        ip           VARCHAR(45)  DEFAULT NULL,
        criado_em    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vault_share (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        token        VARCHAR(64)  NOT NULL UNIQUE,
        item_id      INT          NOT NULL,
        criado_por   INT          DEFAULT NULL,
        criado_nome  VARCHAR(255) DEFAULT NULL,
        expira_em    DATETIME     NOT NULL,
        max_views    INT          NOT NULL DEFAULT 1,
        views        INT          NOT NULL DEFAULT 0,
        criado_em    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Auditoria ──────────────────────────────────────────────────
function vault_audit(PDO $pdo, string $acao, ?int $itemId = null, ?string $itemTitulo = null): void {
    $st = $pdo->prepare(
        "INSERT INTO portal_vault_audit (user_id,user_nome,acao,item_id,item_titulo,ip)
         VALUES (?,?,?,?,?,?)"
    );
    $st->execute([
        $_SESSION['user_id'] ?? null,
        $_SESSION['nome']    ?? ($_SESSION['usuario'] ?? null),
        $acao,
        $itemId,
        $itemTitulo,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function vault_titulo(PDO $pdo, int $id): ?string {
    $st = $pdo->prepare("SELECT titulo FROM portal_vault WHERE id = ?");
    $st->execute([$id]);
    return $st->fetchColumn() ?: null;
}

// ── API AJAX ───────────────────────────────────────────────────
$action  = $_GET['action'] ?? '';
$uid     = (int)($_SESSION['user_id'] ?? 0);

// Ações de PIN não exigem cofre desbloqueado (são o próprio portão de entrada)
$ACOES_PIN  = ['pin_status','pin_set','pin_unlock','pin_reset','pin_lock'];
$COFRE_IDLE = 300; // 5 min — re-trava o cofre por inatividade

if ($action) {
    header('Content-Type: application/json');

    // ── PIN por usuário ────────────────────────────────────────
    if (in_array($action, $ACOES_PIN, true)) {

        // Travar manualmente (chamado ao sair/fechar a aba ou por inatividade)
        if ($action === 'pin_lock') {
            unset($_SESSION['vault_unlocked'], $_SESSION['vault_last']);
            echo json_encode(['ok'=>true]);
            exit;
        }

        $st = $pdo->prepare("SELECT pin_hash FROM portal_vault_pin WHERE user_id = ?");
        $st->execute([$uid]);
        $pinHash = $st->fetchColumn();

        if ($action === 'pin_status') {
            // Aplica inatividade do cofre também aqui (cobre volta de aba em segundo plano)
            $unlocked = !empty($_SESSION['vault_unlocked']);
            if ($unlocked && !empty($_SESSION['vault_last'])
                && (time() - $_SESSION['vault_last']) > $COFRE_IDLE) {
                unset($_SESSION['vault_unlocked']);
                $unlocked = false;
            }
            echo json_encode(['has_pin' => (bool)$pinHash, 'unlocked' => $unlocked]);
            exit;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $pin  = trim((string)($body['pin'] ?? ''));

        if ($action === 'pin_set') {
            // Cadastro inicial (só permitido se ainda não houver PIN)
            if ($pinHash) { echo json_encode(['ok'=>false,'msg'=>'PIN já cadastrado']); exit; }
            if (strlen($pin) < 4) { echo json_encode(['ok'=>false,'msg'=>'PIN deve ter ao menos 4 dígitos']); exit; }
            $h = password_hash($pin, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO portal_vault_pin (user_id,pin_hash) VALUES (?,?)")->execute([$uid,$h]);
            $_SESSION['vault_unlocked'] = true;
            $_SESSION['vault_last']     = time();
            vault_audit($pdo, 'pin_set');
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($action === 'pin_unlock') {
            if (!$pinHash || !password_verify($pin, $pinHash)) {
                echo json_encode(['ok'=>false,'msg'=>'PIN incorreto']);
                exit;
            }
            $_SESSION['vault_unlocked'] = true;
            $_SESSION['vault_last']     = time();
            vault_audit($pdo, 'pin_unlock');
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($action === 'pin_reset') {
            // Esqueci o PIN: remove para permitir novo cadastro.
            // O login do portal (GLPI) já autentica o usuário.
            $pdo->prepare("DELETE FROM portal_vault_pin WHERE user_id = ?")->execute([$uid]);
            unset($_SESSION['vault_unlocked']);
            vault_audit($pdo, 'pin_reset');
            echo json_encode(['ok'=>true]);
            exit;
        }
    }

    // ── Daqui pra baixo exige cofre desbloqueado ──────────────
    if (empty($_SESSION['vault_unlocked'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'locked'=>true,'msg'=>'Cofre bloqueado']);
        exit;
    }
    // Re-trava por inatividade (5 min)
    if (!empty($_SESSION['vault_last']) && (time() - $_SESSION['vault_last']) > $COFRE_IDLE) {
        unset($_SESSION['vault_unlocked']);
        http_response_code(403);
        echo json_encode(['ok'=>false,'locked'=>true,'timeout'=>true,'msg'=>'Cofre travado por inatividade']);
        exit;
    }
    $_SESSION['vault_last'] = time();

    // Listar (conteúdo mascarado)
    if ($action === 'list') {
        $q    = '%' . trim($_GET['q'] ?? '') . '%';
        $cat  = $_GET['cat'] ?? '';
        $sql  = "SELECT id, titulo, categoria, usuario, url, tags, notas,
                        DATE_FORMAT(modificado_em,'%d/%m/%Y %H:%i') AS modificado_em
                 FROM portal_vault WHERE (titulo LIKE ? OR tags LIKE ? OR notas LIKE ?)";
        $params = [$q, $q, $q];
        if ($cat) { $sql .= " AND categoria = ?"; $params[] = $cat; }
        $sql .= " ORDER BY modificado_em DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Revelar conteúdo (descriptografa só quando solicitado)
    if ($action === 'reveal' && isset($_GET['id'])) {
        $id  = (int)$_GET['id'];
        $st = $pdo->prepare("SELECT conteudo FROM portal_vault WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        // 'edit' = leitura interna para preencher o modal (não audita como acesso)
        if (($_GET['ctx'] ?? '') !== 'edit') {
            vault_audit($pdo, 'reveal', $id, vault_titulo($pdo, $id));
        }
        echo json_encode(['conteudo' => $row ? vault_decrypt($row['conteudo']) : '']);
        exit;
    }

    // Salvar (criar ou editar)
    if ($action === 'save') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id        = (int)($body['id'] ?? 0);
        $titulo    = trim($body['titulo']   ?? '');
        $categoria = trim($body['categoria'] ?? 'senha');
        $usuario   = trim($body['usuario']  ?? '');
        $conteudo  = trim($body['conteudo'] ?? '');
        $url       = trim($body['url']      ?? '');
        $tags      = trim($body['tags']     ?? '');
        $notas     = trim($body['notas']    ?? '');
        if (!$titulo) { echo json_encode(['ok'=>false,'msg'=>'Título obrigatório']); exit; }
        $conteudo_enc = $conteudo ? vault_encrypt($conteudo) : '';
        if ($id) {
            $st = $pdo->prepare("UPDATE portal_vault SET titulo=?,categoria=?,usuario=?,conteudo=?,url=?,tags=?,notas=? WHERE id=?");
            $st->execute([$titulo,$categoria,$usuario,$conteudo_enc,$url,$tags,$notas,$id]);
            vault_audit($pdo, 'update', $id, $titulo);
        } else {
            $st = $pdo->prepare("INSERT INTO portal_vault (titulo,categoria,usuario,conteudo,url,tags,notas) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$titulo,$categoria,$usuario,$conteudo_enc,$url,$tags,$notas]);
            $id = $pdo->lastInsertId();
            vault_audit($pdo, 'create', (int)$id, $titulo);
        }
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }

    // Excluir
    if ($action === 'delete' && isset($_GET['id'])) {
        $id    = (int)$_GET['id'];
        $titulo = vault_titulo($pdo, $id);
        $pdo->prepare("DELETE FROM portal_vault WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM portal_vault_share WHERE item_id = ?")->execute([$id]);
        vault_audit($pdo, 'delete', $id, $titulo);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Registrar cópia (auditoria)
    if ($action === 'copy' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        vault_audit($pdo, 'copy', $id, vault_titulo($pdo, $id));
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Criar link de compartilhamento temporário
    if ($action === 'share_create') {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id    = (int)($body['id'] ?? 0);
        $ttl   = max(5, min(1440, (int)($body['ttl'] ?? 60)));   // 5min .. 24h
        $views = max(1, min(20,  (int)($body['views'] ?? 1)));
        $titulo = vault_titulo($pdo, $id);
        if (!$titulo) { echo json_encode(['ok'=>false,'msg'=>'Item não encontrado']); exit; }
        $token = bin2hex(random_bytes(24));
        $exp   = (new DateTime("+{$ttl} minutes"))->format('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO portal_vault_share (token,item_id,criado_por,criado_nome,expira_em,max_views)
             VALUES (?,?,?,?,?,?)"
        )->execute([$token,$id,$uid,$_SESSION['nome'] ?? null,$exp,$views]);
        vault_audit($pdo, 'share', $id, $titulo);

        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        echo json_encode(['ok'=>true,'url'=>$base . '/compartilhar.php?token=' . $token,
                          'expira_em'=>(new DateTime($exp))->format('d/m/Y H:i'), 'views'=>$views]);
        exit;
    }

    // Listar auditoria (últimos 200 eventos)
    if ($action === 'audit_list') {
        $rows = $pdo->query(
            "SELECT acao, user_nome, item_titulo, ip,
                    DATE_FORMAT(criado_em,'%d/%m/%Y %H:%i') AS quando
             FROM portal_vault_audit ORDER BY id DESC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Cofre TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,#263238,#37474f);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.3);
    }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,#263238,#37474f); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    /* Barra de ferramentas */
    .toolbar {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06);
      padding:1rem 1.25rem; margin-bottom:1rem;
      display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;
    }

    /* Filtros de categoria */
    .cat-btn {
      border:2px solid #e5e7eb; border-radius:20px; padding:.3rem .85rem;
      font-size:.8rem; font-weight:600; cursor:pointer; background:white;
      transition:all .15s; display:flex; align-items:center; gap:.35rem;
    }
    .cat-btn:hover  { border-color:#37474f; background:#f5f5f5; }
    .cat-btn.active { border-color:#37474f; background:#263238; color:white; }

    /* Cards do cofre */
    .vault-grid {
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap:1rem;
    }
    .vault-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.05);
      padding:1rem 1.1rem; transition:box-shadow .15s;
      position:relative;
    }
    .vault-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }

    .cat-badge {
      font-size:.68rem; font-weight:700; padding:.2rem .6rem; border-radius:10px;
      display:inline-flex; align-items:center; gap:.3rem;
    }
    .cat-senha    { background:#e3f2fd; color:#1565c0; }
    .cat-comando  { background:#e8f5e9; color:#2e7d32; }
    .cat-doc      { background:#fff8e1; color:#e65100; }
    .cat-link     { background:#f3e5f5; color:#6a1b9a; }
    .cat-contato  { background:#e0f2f1; color:#00695c; }
    .cat-outro    { background:#f5f5f5; color:#616161; }

    .vault-titulo { font-weight:700; font-size:.95rem; margin:.4rem 0 .2rem; color:#111;
                    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .vault-usuario { font-size:.78rem; color:#6b7280;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .conteudo-wrap {
      margin:.6rem 0 .4rem;
      background:#f8f9fa; border-radius:8px; border:1px solid #e5e7eb;
      padding:.5rem .75rem; font-family:monospace; font-size:.82rem;
      display:flex; align-items:center; gap:.5rem; min-height:36px;
    }
    .conteudo-mask { flex:1; color:#9ca3af; letter-spacing:.1em; }
    .conteudo-text { flex:1; color:#111; word-break:break-all; white-space:pre-wrap; }

    .btn-icon {
      background:none; border:none; padding:.25rem .4rem;
      border-radius:6px; cursor:pointer; font-size:.85rem; color:#6b7280;
      transition:all .15s;
    }
    .btn-icon:hover { background:#f0f4ff; color:#1a237e; }
    .btn-icon.copied { color:#16a34a; }

    .vault-notas { font-size:.75rem; color:#9ca3af; margin-top:.35rem;
                   white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .vault-tags  { display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.4rem; }
    .tag-chip { background:#f1f5f9; border-radius:10px; padding:.1rem .5rem;
                font-size:.68rem; color:#64748b; }

    .vault-footer {
      display:flex; align-items:center; justify-content:space-between;
      margin-top:.6rem; padding-top:.5rem; border-top:1px solid #f3f4f6;
    }
    .vault-data { font-size:.68rem; color:#d1d5db; }

    /* Modal */
    .modal-header-cofre { background:linear-gradient(135deg,#263238,#37474f); color:white; }
    .modal-header-cofre .btn-close { filter:invert(1); }

    .form-label { font-size:.84rem; font-weight:600; }

    /* Input de senha com toggle */
    .pass-wrap { position:relative; }
    .pass-wrap .form-control { padding-right:2.5rem; }
    .pass-toggle {
      position:absolute; right:.6rem; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer; color:#9ca3af; font-size:.9rem;
      padding:.2rem;
    }
    .pass-toggle:hover { color:#37474f; }

    .btn-salvar { background:#263238; border-color:#263238; }
    .btn-salvar:hover { background:#37474f; border-color:#37474f; }
  </style>
</head>
<body>

<div class="topbar">
  <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem">
    <i class="bi bi-safe2-fill"></i> Cofre TI
  </div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0"><i class="bi bi-safe2-fill me-2"></i>Cofre TI</h1>
  <p style="opacity:.8;margin-top:.5rem">Senhas, comandos e documentação interna — seguro e centralizado</p>
</div>

<div class="wrap">

  <!-- Toolbar -->
  <div class="toolbar">
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:240px"
           placeholder="🔍 Buscar por título, tag ou nota..." oninput="carregar()"/>

    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      <button class="cat-btn active" data-cat="" onclick="setCategoria(this)">
        <i class="bi bi-grid-3x3-gap"></i> Todos
      </button>
      <button class="cat-btn" data-cat="senha" onclick="setCategoria(this)">
        <i class="bi bi-key-fill"></i> Senhas
      </button>
      <button class="cat-btn" data-cat="comando" onclick="setCategoria(this)">
        <i class="bi bi-terminal-fill"></i> Comandos
      </button>
      <button class="cat-btn" data-cat="doc" onclick="setCategoria(this)">
        <i class="bi bi-file-text-fill"></i> Docs
      </button>
      <button class="cat-btn" data-cat="link" onclick="setCategoria(this)">
        <i class="bi bi-link-45deg"></i> Links
      </button>
      <button class="cat-btn" data-cat="contato" onclick="setCategoria(this)">
        <i class="bi bi-telephone-fill"></i> Contatos
      </button>
      <button class="cat-btn" data-cat="outro" onclick="setCategoria(this)">
        <i class="bi bi-three-dots"></i> Outros
      </button>
    </div>

    <div style="flex:1"></div>
    <span id="cnt-itens" style="font-size:.78rem;color:#9ca3af"></span>
    <button class="btn btn-sm btn-outline-secondary" onclick="abrirAuditoria()" title="Histórico de acessos">
      <i class="bi bi-clock-history me-1"></i>Auditoria
    </button>
    <button class="btn btn-sm btn-salvar text-white" onclick="abrirModal()">
      <i class="bi bi-plus-lg me-1"></i>Novo Item
    </button>
  </div>

  <!-- Grid de cards -->
  <div id="vault-grid" class="vault-grid"></div>
  <p id="sem-itens" class="text-center text-muted py-5" style="display:none">
    <i class="bi bi-safe2 d-block" style="font-size:2.5rem;margin-bottom:.5rem"></i>
    Nenhum item encontrado. Clique em "Novo Item" para começar.
  </p>

</div>

<!-- Modal criar/editar -->
<div class="modal fade" id="modalCofre" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-cofre">
        <h5 class="modal-title fw-bold" id="modal-titulo">
          <i class="bi bi-safe2-fill me-2"></i>Novo Item
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="item-id"/>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Título <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="item-titulo"
                   placeholder="Ex: Servidor de Produção, Firewall LJ031..."/>
          </div>
          <div class="col-md-4">
            <label class="form-label">Categoria</label>
            <select class="form-select" id="item-categoria" onchange="ajustarCampos()">
              <option value="senha">🔑 Senha</option>
              <option value="comando">💻 Comando / Script</option>
              <option value="doc">📋 Documentação</option>
              <option value="link">🔗 Link útil</option>
              <option value="contato">📞 Contato útil</option>
              <option value="outro">📦 Outro</option>
            </select>
          </div>

          <!-- Campos de senha -->
          <div class="col-md-6" id="campo-usuario">
            <label class="form-label">Usuário / Login</label>
            <input type="text" class="form-control" id="item-usuario"
                   placeholder="admin, root, ti@empresa..." autocomplete="off"/>
          </div>
          <div class="col-md-6" id="campo-url">
            <label class="form-label" id="label-url">URL / Servidor / IP</label>
            <input type="text" class="form-control" id="item-url"
                   placeholder="192.168.1.1, https://..." autocomplete="off"/>
          </div>

          <div class="col-12" id="campo-conteudo">
            <label class="form-label" id="label-conteudo">
              Senha / Conteúdo <span class="text-muted small">(criptografado)</span>
            </label>
            <div class="pass-wrap">
              <textarea class="form-control" id="item-conteudo" rows="3"
                        placeholder="Senha, comando ou conteúdo a guardar..."
                        style="resize:vertical;font-family:monospace"></textarea>
            </div>
          </div>

          <div class="col-md-8" id="campo-tags">
            <label class="form-label">Tags <span class="text-muted small">(separadas por vírgula)</span></label>
            <input type="text" class="form-control" id="item-tags"
                   placeholder="servidor, produção, vpn..."/>
          </div>

          <div class="col-12" id="campo-notas">
            <label class="form-label">Notas adicionais</label>
            <textarea class="form-control" id="item-notas" rows="2"
                      placeholder="Observações, instruções de uso, contexto..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btn-excluir" style="display:none" onclick="excluir()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-salvar text-white" onclick="salvar()">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal compartilhar -->
<div class="modal fade" id="modalShare" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-cofre">
        <h5 class="modal-title fw-bold"><i class="bi bi-share-fill me-2"></i>Compartilhar com link temporário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="share-form">
          <p class="text-muted small mb-3">
            Gera um link público (sem login) que revela o conteúdo deste item.
            O link expira no prazo e após o número de visualizações escolhidos.
          </p>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Expira em</label>
              <select class="form-select" id="share-ttl">
                <option value="15">15 minutos</option>
                <option value="60" selected>1 hora</option>
                <option value="480">8 horas</option>
                <option value="1440">24 horas</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Visualizações</label>
              <select class="form-select" id="share-views">
                <option value="1" selected>1 (única)</option>
                <option value="3">3</option>
                <option value="5">5</option>
              </select>
            </div>
          </div>
        </div>
        <div id="share-result" style="display:none">
          <label class="form-label">Link gerado</label>
          <div class="input-group">
            <input type="text" class="form-control" id="share-url" readonly/>
            <button class="btn btn-salvar text-white" onclick="copiarLink()"><i class="bi bi-clipboard"></i></button>
          </div>
          <div class="text-muted small mt-2"><i class="bi bi-clock me-1"></i><span id="share-info"></span></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-salvar text-white" onclick="gerarLink()"><i class="bi bi-link-45deg me-1"></i>Gerar link</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal auditoria -->
<div class="modal fade" id="modalAudit" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-cofre">
        <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2"></i>Auditoria de acessos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:60vh;overflow:auto">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="text-muted small">
            <th>Quando</th><th>Ação</th><th>Usuário</th><th>Item</th><th>IP</th>
          </tr></thead>
          <tbody id="audit-tbody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <span class="text-muted small me-auto">Últimos 200 eventos</span>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Overlay de PIN -->
<div id="pin-overlay" style="display:none;position:fixed;inset:0;z-index:2000;
     background:rgba(38,50,56,.97);align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:2rem;max-width:360px;width:90%;text-align:center;
              box-shadow:0 8px 40px rgba(0,0,0,.4)">
    <i class="bi bi-shield-lock-fill" style="font-size:2.5rem;color:#263238"></i>
    <h5 id="pin-title" class="fw-bold mt-2 mb-1">Cofre bloqueado</h5>
    <p id="pin-sub" class="text-muted small">Digite seu PIN para desbloquear.</p>
    <input type="password" id="pin-input" class="form-control text-center mb-2" inputmode="numeric"
           maxlength="20" placeholder="PIN" style="letter-spacing:.3em;font-size:1.1rem"/>
    <input type="password" id="pin-input2" class="form-control text-center mb-2" inputmode="numeric"
           maxlength="20" placeholder="Confirme o PIN" style="letter-spacing:.3em;font-size:1.1rem;display:none"/>
    <div id="pin-erro" class="text-danger small mb-2" style="min-height:1.1rem"></div>
    <button id="pin-btn" class="btn btn-salvar text-white w-100" onclick="enviarPin()">Desbloquear</button>
    <a id="pin-forgot" href="javascript:void(0)" onclick="esqueciPin()"
       class="d-block mt-3 small text-muted text-decoration-none">Esqueci meu PIN</a>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modal, shareModal, auditModal;
let categoriaAtiva = '';
let revelados = {}; // id → conteudo decriptado em memória (limpo ao fechar aba)
let pinMode = 'desbloquear';
let shareItemId = null;
let idleTimer = null;
const COFRE_IDLE_MS = 5 * 60 * 1000; // 5 min — re-trava o cofre por inatividade

document.addEventListener('DOMContentLoaded', () => {
  modal      = new bootstrap.Modal(document.getElementById('modalCofre'));
  shareModal = new bootstrap.Modal(document.getElementById('modalShare'));
  auditModal = new bootstrap.Modal(document.getElementById('modalAudit'));
  document.getElementById('f-busca').addEventListener('input', carregar);
  document.getElementById('pin-input').addEventListener('keydown', e => { if (e.key==='Enter') enviarPin(); });

  // Atividade do usuário reinicia o relógio de inatividade (só quando desbloqueado)
  ['mousemove','keydown','click','scroll','touchstart'].forEach(ev =>
    document.addEventListener(ev, () => {
      if (document.getElementById('pin-overlay').style.display === 'none') resetIdle();
    }, {passive:true}));
  // Trava ao fechar/sair da aba
  window.addEventListener('pagehide', () => navigator.sendBeacon('cofre.php?action=pin_lock'));
  // Ao voltar para a aba, revalida (pode ter expirado em segundo plano)
  document.addEventListener('visibilitychange', () => { if (!document.hidden) verificarPin(); });

  verificarPin();
});

// ── Inatividade do cofre (5 min) ───────────────────────────────
function resetIdle() {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(travarCofre, COFRE_IDLE_MS);
}
async function travarCofre() {
  clearTimeout(idleTimer);
  try { await fetch('cofre.php?action=pin_lock', {method:'POST'}); } catch {}
  revelados = {};
  mostrarPinOverlay('desbloquear');
}

// ── Gate de PIN ────────────────────────────────────────────────
async function verificarPin() {
  try {
    const d = await (await fetch('cofre.php?action=pin_status')).json();
    if (!d.has_pin)  return mostrarPinOverlay('criar');
    if (!d.unlocked) return mostrarPinOverlay('desbloquear');
    esconderPinOverlay();
    carregar();
    resetIdle();
  } catch { toast('Erro ao verificar o cofre', 'danger'); }
}

function mostrarPinOverlay(mode) {
  pinMode = mode;
  const crit = mode === 'criar';
  document.getElementById('pin-title').textContent = crit ? 'Criar PIN do Cofre' : 'Cofre bloqueado';
  document.getElementById('pin-sub').textContent   = crit
    ? 'Defina um PIN (mín. 4 dígitos) para proteger o cofre.'
    : 'Digite seu PIN para desbloquear.';
  document.getElementById('pin-input2').style.display = crit ? '' : 'none';
  document.getElementById('pin-forgot').style.display = crit ? 'none' : '';
  document.getElementById('pin-btn').textContent = crit ? 'Criar PIN' : 'Desbloquear';
  document.getElementById('pin-input').value  = '';
  document.getElementById('pin-input2').value = '';
  document.getElementById('pin-erro').textContent = '';
  document.getElementById('pin-overlay').style.display = 'flex';
  setTimeout(() => document.getElementById('pin-input').focus(), 100);
}
function esconderPinOverlay() {
  document.getElementById('pin-overlay').style.display = 'none';
}

async function enviarPin() {
  const pin  = document.getElementById('pin-input').value.trim();
  const erro = document.getElementById('pin-erro');
  if (pinMode === 'criar') {
    const pin2 = document.getElementById('pin-input2').value.trim();
    if (pin.length < 4) { erro.textContent = 'O PIN deve ter ao menos 4 dígitos.'; return; }
    if (pin !== pin2)   { erro.textContent = 'Os PINs não conferem.'; return; }
    const d = await (await fetch('cofre.php?action=pin_set', postJson({pin}))).json();
    if (d.ok) { esconderPinOverlay(); carregar(); resetIdle(); toast('🔑 PIN criado! Cofre desbloqueado.'); }
    else      { erro.textContent = d.msg || 'Erro ao criar PIN'; }
  } else {
    const d = await (await fetch('cofre.php?action=pin_unlock', postJson({pin}))).json();
    if (d.ok) { esconderPinOverlay(); carregar(); resetIdle(); }
    else      { erro.textContent = d.msg || 'PIN incorreto'; document.getElementById('pin-input').value=''; }
  }
}

async function esqueciPin() {
  if (!confirm('Resetar seu PIN? Você definirá um novo agora (seu login do portal já garante sua identidade).')) return;
  await fetch('cofre.php?action=pin_reset', {method:'POST'});
  mostrarPinOverlay('criar');
}

function postJson(obj) {
  return { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(obj) };
}

// ── Categorias ─────────────────────────────────────────────────
const CAT_INFO = {
  senha:   { icon:'bi-key-fill',        label:'Senha',        cls:'cat-senha'   },
  comando: { icon:'bi-terminal-fill',   label:'Comando',      cls:'cat-comando' },
  doc:     { icon:'bi-file-text-fill',  label:'Documentação', cls:'cat-doc'     },
  link:    { icon:'bi-link-45deg',      label:'Link',         cls:'cat-link'    },
  contato: { icon:'bi-telephone-fill',  label:'Contato',      cls:'cat-contato' },
  outro:   { icon:'bi-three-dots',      label:'Outro',        cls:'cat-outro'   },
};

function setCategoria(btn) {
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  categoriaAtiva = btn.dataset.cat;
  carregar();
}

// ── Carregar lista ──────────────────────────────────────────────
function carregar() {
  const q   = document.getElementById('f-busca').value;
  const url = `cofre.php?action=list&q=${encodeURIComponent(q)}&cat=${encodeURIComponent(categoriaAtiva)}`;
  fetch(url)
    .then(r => { if (r.status === 403) { mostrarPinOverlay('desbloquear'); throw 'locked'; } return r.json(); })
    .then(items => renderizar(items))
    .catch(e => { if (e !== 'locked') toast('Erro ao carregar itens', 'danger'); });
}

function renderizar(items) {
  const grid = document.getElementById('vault-grid');
  const sem  = document.getElementById('sem-itens');
  document.getElementById('cnt-itens').textContent = items.length
    ? `${items.length} item${items.length > 1 ? 's' : ''}`
    : '';

  if (!items.length) {
    grid.innerHTML = '';
    sem.style.display = '';
    return;
  }
  sem.style.display = 'none';
  grid.innerHTML = items.map(item => buildCard(item)).join('');
}

function buildCard(item) {
  const cat  = CAT_INFO[item.categoria] || CAT_INFO.outro;
  const tags = (item.tags || '').split(',').map(t=>t.trim()).filter(Boolean);
  const conteudoHtml = revelados[item.id]
    ? `<span class="conteudo-text">${esc(revelados[item.id])}</span>`
    : `<span class="conteudo-mask">● ● ● ● ● ● ● ●</span>`;

  return `
  <div class="vault-card" id="card-${item.id}">
    <div class="d-flex align-items-center justify-content-between">
      <span class="cat-badge ${cat.cls}">
        <i class="bi ${cat.icon}"></i> ${cat.label}
      </span>
      <div>
        <button class="btn-icon" onclick="shareItem(${item.id})" title="Compartilhar com link temporário">
          <i class="bi bi-share-fill"></i>
        </button>
        <button class="btn-icon" onclick="editarItem(${item.id})" title="Editar">
          <i class="bi bi-pencil-fill"></i>
        </button>
      </div>
    </div>
    <div class="vault-titulo" title="${esc(item.titulo)}">${esc(item.titulo)}</div>
    ${item.usuario ? `<div class="vault-usuario"><i class="bi bi-person me-1"></i>${esc(item.usuario)}</div>` : ''}
    ${item.url ? `<div class="vault-usuario" title="${esc(item.url)}"><i class="bi bi-hdd-network me-1"></i>${esc(item.url)}</div>` : ''}
    <div class="conteudo-wrap" id="conteudo-wrap-${item.id}">
      <div id="conteudo-${item.id}" style="flex:1">${conteudoHtml}</div>
      <button class="btn-icon" id="btn-reveal-${item.id}"
              onclick="toggleRevelar(${item.id})"
              title="${revelados[item.id] ? 'Ocultar' : 'Revelar'}">
        <i class="bi ${revelados[item.id] ? 'bi-eye-slash-fill' : 'bi-eye-fill'}"></i>
      </button>
      <button class="btn-icon" id="btn-copy-${item.id}"
              onclick="copiar(${item.id})" title="Copiar">
        <i class="bi bi-clipboard"></i>
      </button>
    </div>
    ${tags.length ? `<div class="vault-tags">${tags.map(t=>`<span class="tag-chip">#${esc(t)}</span>`).join('')}</div>` : ''}
    ${item.notas ? `<div class="vault-notas" title="${esc(item.notas)}"><i class="bi bi-sticky me-1"></i>${esc(item.notas)}</div>` : ''}
    <div class="vault-footer">
      <span class="vault-data"><i class="bi bi-clock me-1"></i>${item.modificado_em}</span>
    </div>
  </div>`;
}

// ── Revelar / Ocultar ───────────────────────────────────────────
async function toggleRevelar(id) {
  if (revelados[id]) {
    delete revelados[id];
    atualizarCard(id);
    return;
  }
  const btn = document.getElementById('btn-reveal-' + id);
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  try {
    const r = await fetch(`cofre.php?action=reveal&id=${id}`);
    if (r.status === 403) { mostrarPinOverlay('desbloquear'); return; }
    const d = await r.json();
    revelados[id] = d.conteudo || '';
    atualizarCard(id);
  } catch {
    toast('Erro ao revelar conteúdo', 'danger');
  }
}

function atualizarCard(id) {
  const wrap = document.getElementById('conteudo-' + id);
  const btn  = document.getElementById('btn-reveal-' + id);
  if (!wrap) return;
  if (revelados[id] !== undefined) {
    wrap.innerHTML  = `<span class="conteudo-text">${esc(revelados[id])}</span>`;
    btn.innerHTML   = '<i class="bi bi-eye-slash-fill"></i>';
    btn.title       = 'Ocultar';
  } else {
    wrap.innerHTML  = `<span class="conteudo-mask">● ● ● ● ● ● ● ●</span>`;
    btn.innerHTML   = '<i class="bi bi-eye-fill"></i>';
    btn.title       = 'Revelar';
  }
}

// ── Copiar para clipboard ───────────────────────────────────────
async function copiar(id) {
  let texto = revelados[id];
  if (texto === undefined) {
    try {
      const r = await fetch(`cofre.php?action=reveal&id=${id}&ctx=edit`);
      const d = await r.json();
      texto = d.conteudo || '';
    } catch { toast('Erro ao copiar', 'danger'); return; }
  }
  if (!texto) { toast('Nada para copiar', 'warning'); return; }
  fetch(`cofre.php?action=copy&id=${id}`);   // auditoria
  navigator.clipboard.writeText(texto).then(() => {
    const btn = document.getElementById('btn-copy-' + id);
    if (btn) {
      btn.classList.add('copied');
      btn.innerHTML = '<i class="bi bi-clipboard-check-fill"></i>';
      setTimeout(() => {
        btn.classList.remove('copied');
        btn.innerHTML = '<i class="bi bi-clipboard"></i>';
      }, 2000);
    }
    toast('✅ Copiado para a área de transferência!');
  });
}

// ── Modal ───────────────────────────────────────────────────────
// Campos exibidos por categoria. show=false esconde e limpa o campo
// para não persistir dado que não pertence ao tipo escolhido.
const CAMPOS_POR_CAT = {
  senha: {
    usuario:  { show:true,  label:'Usuário / Login',        ph:'admin, root, ti@empresa...' },
    url:      { show:true,  label:'URL / Servidor / IP',    ph:'192.168.1.1, https://...' },
    conteudo: { show:true,  label:'Senha', cripto:true,     ph:'Senha a guardar...',        rows:2 },
    tags:     { show:true },
    notas:    { show:true },
  },
  comando: {
    usuario:  { show:false },
    url:      { show:false },
    conteudo: { show:true,  label:'Comando / Script', cripto:true, ph:'sudo systemctl restart nginx...', rows:4 },
    tags:     { show:true },
    notas:    { show:true },
  },
  doc: {
    usuario:  { show:false },
    url:      { show:false },
    conteudo: { show:true,  label:'Conteúdo / Documentação', cripto:true, ph:'Passo a passo, procedimento...', rows:6 },
    tags:     { show:true },
    notas:    { show:true },
  },
  link: {
    usuario:  { show:false },
    url:      { show:true,  label:'Link / URL', ph:'https://...' },
    conteudo: { show:false },
    tags:     { show:false },
    notas:    { show:true },
  },
  contato: {
    usuario:  { show:true,  label:'Pessoa de referência', ph:'João Silva, Suporte N2...' },
    url:      { show:true,  label:'Telefone / Ramal',     ph:'(11) 91234-5678, ramal 2803' },
    conteudo: { show:false },
    tags:     { show:true },
    notas:    { show:true },
  },
  outro: {
    usuario:  { show:false },
    url:      { show:false },
    conteudo: { show:true,  label:'Conteúdo', cripto:true, ph:'Conteúdo a guardar...', rows:3 },
    tags:     { show:true },
    notas:    { show:true },
  },
};

function setCampo(wrapId, inputId, cfg, labelId) {
  const wrap  = document.getElementById(wrapId);
  const input = document.getElementById(inputId);
  if (!cfg || !cfg.show) {
    wrap.style.display = 'none';
    if (input) input.value = '';   // não salva dado de outra categoria
    return;
  }
  wrap.style.display = '';
  if (labelId && cfg.label) {
    const note = cfg.cripto ? ' <span class="text-muted small">(criptografado)</span>' : '';
    document.getElementById(labelId).innerHTML = cfg.label + note;
  }
  if (input && cfg.ph   !== undefined) input.placeholder = cfg.ph;
  if (input && cfg.rows !== undefined && input.tagName === 'TEXTAREA') input.rows = cfg.rows;
}

function ajustarCampos() {
  const cat = document.getElementById('item-categoria').value;
  const cfg = CAMPOS_POR_CAT[cat] || CAMPOS_POR_CAT.outro;
  setCampo('campo-usuario',  'item-usuario',  cfg.usuario);
  setCampo('campo-url',      'item-url',      cfg.url,      'label-url');
  setCampo('campo-conteudo', 'item-conteudo', cfg.conteudo, 'label-conteudo');
  setCampo('campo-tags',     'item-tags',     cfg.tags);
  setCampo('campo-notas',    'item-notas',    cfg.notas);
}

function abrirModal() {
  document.getElementById('item-id').value       = '';
  document.getElementById('item-titulo').value   = '';
  document.getElementById('item-categoria').value= 'senha';
  document.getElementById('item-usuario').value  = '';
  document.getElementById('item-conteudo').value = '';
  document.getElementById('item-url').value      = '';
  document.getElementById('item-tags').value     = '';
  document.getElementById('item-notas').value    = '';
  document.getElementById('btn-excluir').style.display = 'none';
  document.getElementById('modal-titulo').innerHTML =
    '<i class="bi bi-safe2-fill me-2"></i>Novo Item';
  ajustarCampos();
  modal.show();
}

async function editarItem(id) {
  // Abre modal e preenche campos básicos (sem conteúdo sensível)
  const r = await fetch(`cofre.php?action=list&q=`);
  const items = await r.json();
  const item  = items.find(x => x.id == id);
  if (!item) return;

  document.getElementById('item-id').value        = item.id;
  document.getElementById('item-titulo').value    = item.titulo;
  document.getElementById('item-categoria').value = item.categoria;
  document.getElementById('item-usuario').value   = item.usuario || '';
  document.getElementById('item-url').value       = item.url     || '';
  document.getElementById('item-tags').value      = item.tags    || '';
  document.getElementById('item-notas').value     = item.notas   || '';

  // Carrega conteúdo descriptografado (ctx=edit → não conta como acesso na auditoria)
  const rc = await fetch(`cofre.php?action=reveal&id=${id}&ctx=edit`);
  const dc = await rc.json();
  document.getElementById('item-conteudo').value = dc.conteudo || '';

  document.getElementById('btn-excluir').style.display = '';
  document.getElementById('modal-titulo').innerHTML =
    '<i class="bi bi-pencil-fill me-2"></i>Editar Item';
  ajustarCampos();
  modal.show();
}

async function salvar() {
  const titulo = document.getElementById('item-titulo').value.trim();
  if (!titulo) { alert('Informe o título.'); return; }
  const body = {
    id:        document.getElementById('item-id').value  || null,
    titulo,
    categoria: document.getElementById('item-categoria').value,
    usuario:   document.getElementById('item-usuario').value.trim(),
    conteudo:  document.getElementById('item-conteudo').value,
    url:       document.getElementById('item-url').value.trim(),
    tags:      document.getElementById('item-tags').value.trim(),
    notas:     document.getElementById('item-notas').value.trim(),
  };
  const r = await fetch('cofre.php?action=save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body),
  });
  const d = await r.json();
  if (d.ok) {
    modal.hide();
    if (body.id) delete revelados[body.id];  // limpa cache ao editar
    carregar();
    toast('✅ Item salvo com sucesso!');
  } else {
    alert(d.msg || 'Erro ao salvar');
  }
}

async function excluir() {
  const id = document.getElementById('item-id').value;
  if (!confirm('Excluir este item do cofre? Esta ação não pode ser desfeita.')) return;
  await fetch(`cofre.php?action=delete&id=${id}`);
  modal.hide();
  delete revelados[id];
  carregar();
  toast('🗑️ Item removido.');
}

// ── Compartilhamento por link temporário ────────────────────────
function shareItem(id) {
  shareItemId = id;
  document.getElementById('share-ttl').value   = '60';
  document.getElementById('share-views').value = '1';
  document.getElementById('share-result').style.display = 'none';
  document.getElementById('share-form').style.display    = '';
  shareModal.show();
}

async function gerarLink() {
  const ttl   = parseInt(document.getElementById('share-ttl').value, 10);
  const views = parseInt(document.getElementById('share-views').value, 10);
  const d = await (await fetch('cofre.php?action=share_create',
              postJson({id: shareItemId, ttl, views}))).json();
  if (!d.ok) { toast(d.msg || 'Erro ao gerar link', 'danger'); return; }
  document.getElementById('share-url').value = d.url;
  document.getElementById('share-info').textContent =
    `Expira em ${d.expira_em} · ${d.views} visualização${d.views>1?'ões':''}`;
  document.getElementById('share-form').style.display    = 'none';
  document.getElementById('share-result').style.display  = '';
}

function copiarLink() {
  const inp = document.getElementById('share-url');
  navigator.clipboard.writeText(inp.value).then(() => toast('🔗 Link copiado!'));
}

// ── Auditoria ───────────────────────────────────────────────────
const AUDIT_LABEL = {
  reveal:'👁️ Revelou', copy:'📋 Copiou', create:'➕ Criou', update:'✏️ Editou',
  delete:'🗑️ Excluiu', share:'🔗 Compartilhou', share_view:'🌐 Abriu link público',
  pin_unlock:'🔓 Desbloqueou', pin_set:'🔑 Criou PIN', pin_reset:'♻️ Resetou PIN',
};

async function abrirAuditoria() {
  auditModal.show();
  const tbody = document.getElementById('audit-tbody');
  tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';
  try {
    const rows = await (await fetch('cofre.php?action=audit_list')).json();
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sem registros ainda.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td style="white-space:nowrap">${r.quando}</td>
        <td>${AUDIT_LABEL[r.acao] || esc(r.acao)}</td>
        <td>${esc(r.user_nome || '—')}</td>
        <td>${esc(r.item_titulo || '—')}</td>
        <td class="text-muted small">${esc(r.ip || '')}</td>
      </tr>`).join('');
  } catch {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Erro ao carregar auditoria.</td></tr>';
  }
}

// ── Utilitários ─────────────────────────────────────────────────
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toast(msg, type = 'success') {
  const id = 'toast-' + Date.now();
  const bg = type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : 'bg-warning';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2">
      <div class="d-flex">
        <div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto"
                onclick="document.getElementById('${id}').remove()"></button>
      </div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
