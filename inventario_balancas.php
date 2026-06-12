<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Cria tabelas se não existirem ──────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_servidores_mgv (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(100) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        fb_host     VARCHAR(45)  DEFAULT NULL COMMENT 'Firebird host (opcional — padrão = IP)',
        fb_database VARCHAR(255) DEFAULT NULL COMMENT 'Caminho do .FDB no servidor',
        fb_user     VARCHAR(50)  DEFAULT 'SYSDBA',
        fb_pass     VARCHAR(50)  DEFAULT 'masterkey',
        sync_token  VARCHAR(64)  DEFAULT NULL COMMENT 'Token para autenticação do agente PowerShell',
        observacao  TEXT         DEFAULT NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_balancas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        servidor_id   INT          NOT NULL,
        identificacao VARCHAR(100) NOT NULL COMMENT 'Número ou código da balança no MGV',
        modelo        VARCHAR(100) DEFAULT '',
        serie         VARCHAR(100) DEFAULT '',
        loja          VARCHAR(100) DEFAULT '',
        departamento  VARCHAR(100) DEFAULT '',
        ip            VARCHAR(45)  DEFAULT '',
        observacao    TEXT         DEFAULT NULL,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (servidor_id) REFERENCES portal_servidores_mgv(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── CRUD Servidores MGV ────────────────────────────────────────
if ($action === 'listar_servidores') {
    $stmt = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM portal_balancas WHERE servidor_id = s.id) AS total_balancas FROM portal_servidores_mgv s ORDER BY s.nome");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'salvar_servidor') {
    $id    = (int)($_POST['id'] ?? 0);
    $sync_token = $_POST['sync_token'] ?? '';
    $dados = [
        'nome'        => $_POST['nome'] ?? '',
        'ip'          => $_POST['ip'] ?? '',
        'fb_host'     => $_POST['fb_host'] ?: null,
        'fb_database' => $_POST['fb_database'] ?: null,
        'fb_user'     => $_POST['fb_user'] ?: 'SYSDBA',
        'fb_pass'     => $_POST['fb_pass'] ?: 'masterkey',
        'sync_token'  => $sync_token ?: null,
        'observacao'  => $_POST['observacao'] ?? '',
    ];
    if (!$dados['nome'] || !$dados['ip']) {
        echo json_encode(['ok' => false, 'erro' => 'Nome e IP são obrigatórios.']);
        exit;
    }
    if ($id) {
        $dados['id'] = $id;
        $stmt = $pdo->prepare("UPDATE portal_servidores_mgv SET nome=:nome, ip=:ip, fb_host=:fb_host, fb_database=:fb_database, fb_user=:fb_user, fb_pass=:fb_pass, sync_token=:sync_token, observacao=:observacao WHERE id=:id");
        $stmt->execute($dados);
    } else {
        $stmt = $pdo->prepare("INSERT INTO portal_servidores_mgv (nome, ip, fb_host, fb_database, fb_user, fb_pass, sync_token, observacao) VALUES (:nome, :ip, :fb_host, :fb_database, :fb_user, :fb_pass, :sync_token, :observacao)");
        $stmt->execute($dados);
        $id = $pdo->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'excluir_servidor') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare("DELETE FROM portal_servidores_mgv WHERE id=?")->execute([$id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── CRUD Balanças ──────────────────────────────────────────────
if ($action === 'listar_balancas') {
    $servidor_id = (int)($_GET['servidor_id'] ?? 0);
    if ($servidor_id) {
        $stmt = $pdo->prepare("SELECT b.*, s.nome AS servidor_nome, s.ip AS servidor_ip FROM portal_balancas b JOIN portal_servidores_mgv s ON s.id = b.servidor_id WHERE b.servidor_id = ? ORDER BY b.identificacao");
        $stmt->execute([$servidor_id]);
    } else {
        $stmt = $pdo->query("SELECT b.*, s.nome AS servidor_nome, s.ip AS servidor_ip FROM portal_balancas b JOIN portal_servidores_mgv s ON s.id = b.servidor_id ORDER BY s.nome, b.identificacao");
    }
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'salvar_balanca') {
    $id    = (int)($_POST['id'] ?? 0);
    $dados = [
        'servidor_id'   => (int)($_POST['servidor_id'] ?? 0),
        'identificacao' => $_POST['identificacao'] ?? '',
        'modelo'        => $_POST['modelo'] ?? '',
        'serie'         => $_POST['serie'] ?? '',
        'loja'          => $_POST['loja'] ?? '',
        'departamento'  => $_POST['departamento'] ?? '',
        'ip'            => $_POST['ip'] ?? '',
        'observacao'    => $_POST['observacao'] ?? '',
    ];
    if (!$dados['servidor_id'] || !$dados['identificacao']) {
        echo json_encode(['ok' => false, 'erro' => 'Servidor e identificação são obrigatórios.']);
        exit;
    }
    if ($id) {
        $dados['id'] = $id;
        $stmt = $pdo->prepare("UPDATE portal_balancas SET servidor_id=:servidor_id, identificacao=:identificacao, modelo=:modelo, serie=:serie, loja=:loja, departamento=:departamento, ip=:ip, observacao=:observacao WHERE id=:id");
        $stmt->execute($dados);
    } else {
        $stmt = $pdo->prepare("INSERT INTO portal_balancas (servidor_id, identificacao, modelo, serie, loja, departamento, ip, observacao) VALUES (:servidor_id, :identificacao, :modelo, :serie, :loja, :departamento, :ip, :observacao)");
        $stmt->execute($dados);
        $id = $pdo->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'excluir_balanca') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare("DELETE FROM portal_balancas WHERE id=?")->execute([$id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Sync MGV Firebird ──────────────────────────────────────────
if ($action === 'sync_mgv') {
    $servidor_id = (int)($_POST['servidor_id'] ?? 0);
    if (!$servidor_id) { echo json_encode(['ok' => false, 'erro' => 'Servidor não informado.']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM portal_servidores_mgv WHERE id=?");
    $stmt->execute([$servidor_id]);
    $sv = $stmt->fetch();
    if (!$sv) { echo json_encode(['ok' => false, 'erro' => 'Servidor não encontrado.']); exit; }

    $fb_host     = $sv['fb_host'] ?: $sv['ip'];
    $fb_db       = $sv['fb_database'] ?? '';
    $fb_user     = $sv['fb_user'] ?: 'SYSDBA';
    $fb_pass     = $sv['fb_pass'] ?: 'masterkey';

    if (!$fb_db) {
        echo json_encode(['ok' => false, 'erro' => 'Caminho do banco Firebird não configurado. Edite o servidor e informe o caminho do .FDB']);
        exit;
    }

    $dsn = "firebird:dbname={$fb_host}/3050:{$fb_db}";

    try {
        $fb = new PDO($dsn, $fb_user, $fb_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $e) {
        // Fallback: tenta ODBC
        try {
            $odbc_dsn = "DRIVER={Firebird/InterBase(r) driver};UID={$fb_user};PWD={$fb_pass};DBNAME={$fb_host}/3050:{$fb_db}";
            $fb = new PDO("odbc:{$odbc_dsn}", $fb_user, $fb_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Exception $e2) {
            echo json_encode(['ok' => false, 'erro' => 'Conexão Firebird falhou. Verifique se o driver PDO_Firebird ou ODBC Firebird está instalado.' . "\nFirebird: " . $e->getMessage() . "\nODBC: " . $e2->getMessage()]);
            exit;
        }
    }

    // ── Tenta consultar balanças ─────────────────────────────────
    // Tabelas comuns do MGV: BALANCAS, TB_BALANCAS, EQUIPAMENTOS
    $tabelas_possiveis = ['BALANCAS', 'TB_BALANCAS', 'EQUIPAMENTOS', 'BALANCA'];
    $tabela_encontrada = null;

    foreach ($tabelas_possiveis as $tbl) {
        try {
            $r = $fb->query("SELECT COUNT(*) AS c FROM {$tbl}");
            $r->execute();
            if ($r->fetch()['c'] >= 0) {
                $tabela_encontrada = $tbl;
                break;
            }
        } catch (Exception $e) { continue; }
    }

    if (!$tabela_encontrada) {
        echo json_encode(['ok' => false, 'erro' => 'Nenhuma tabela de balanças encontrada no Firebird. Tabelas procuradas: ' . implode(', ', $tabelas_possiveis)]);
        exit;
    }

    // ── Mapeia colunas comuns do MGV ─────────────────────────────
    // Tenta detectar as colunas disponíveis
    $col_info = [];
    try {
        $r = $fb->query("SELECT FIRST 1 * FROM {$tabela_encontrada}");
        $r->execute();
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) $col_info = array_keys($row);
    } catch (Exception $e) { $col_info = []; }

    $col = function($possiveis) use ($col_info) {
        foreach ((array)$possiveis as $p) {
            if (in_array(strtoupper($p), array_map('strtoupper', $col_info), true)) return $p;
            // Tenta com underscore
            $p_ = str_replace(' ', '_', $p);
            if (in_array(strtoupper($p_), array_map('strtoupper', $col_info), true)) return $p_;
        }
        return null;
    };

    $col_id          = $col(['BAL_NUMERO', 'COD_BALANCA', 'NUMERO', 'ID_BALANCA', 'CODIGO', 'ID']);
    $col_modelo      = $col(['BAL_MODELO', 'MODELO', 'DS_MODELO', 'DESCRICAO_MODELO']);
    $col_serie       = $col(['BAL_SERIE', 'NUM_SERIE', 'SERIE', 'NR_SERIE', 'NUMERO_SERIE']);
    $col_loja        = $col(['BAL_LOJA', 'COD_LOJA', 'LOJA', 'CD_LOJA']);
    $col_departamento = $col(['BAL_DEPARTAMENTO', 'COD_DEPARTAMENTO', 'DEPARTAMENTO', 'SETOR', 'CD_DEPARTAMENTO']);

    if (!$col_id) {
        echo json_encode(['ok' => false, 'erro' => "Tabela '{$tabela_encontrada}' encontrada, mas não foi possível identificar a coluna de identificação. Colunas disponíveis: " . implode(', ', $col_info)]);
        exit;
    }

    // ── Busca dados ──────────────────────────────────────────────
    $stmt = $fb->query("SELECT * FROM {$tabela_encontrada}");
    $balancas_firebird = $stmt->fetchAll();

    $importados = 0;
    $atualizados = 0;

    foreach ($balancas_firebird as $b) {
        $ident = trim((string)($b[$col_id] ?? ''));
        if (!$ident) continue;

        $modelo      = $col_modelo      ? trim((string)($b[$col_modelo] ?? '')) : '';
        $serie       = $col_serie       ? trim((string)($b[$col_serie] ?? '')) : '';
        $loja        = $col_loja        ? trim((string)($b[$col_loja] ?? '')) : '';
        $departamento = $col_departamento ? trim((string)($b[$col_departamento] ?? '')) : '';

        // Upsert (identificação única por servidor)
        $check = $pdo->prepare("SELECT id FROM portal_balancas WHERE servidor_id=? AND identificacao=?");
        $check->execute([$servidor_id, $ident]);
        $existing = $check->fetch();

        if ($existing) {
            $upd = $pdo->prepare("UPDATE portal_balancas SET modelo=?, serie=?, loja=?, departamento=? WHERE id=?");
            $upd->execute([$modelo, $serie, $loja, $departamento, $existing['id']]);
            $atualizados++;
        } else {
            $ins = $pdo->prepare("INSERT INTO portal_balancas (servidor_id, identificacao, modelo, serie, loja, departamento) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$servidor_id, $ident, $modelo, $serie, $loja, $departamento]);
            $importados++;
        }
    }

    echo json_encode([
        'ok'         => true,
        'tabela'     => $tabela_encontrada,
        'colunas'    => compact('col_id', 'col_modelo', 'col_serie', 'col_loja', 'col_departamento'),
        'total_fb'   => count($balancas_firebird),
        'importados' => $importados,
        'atualizados' => $atualizados,
    ]);
    exit;
}

// ── Sync remoto via PowerShell Agent ───────────────────────────
// O script PowerShell roda local no servidor MGV, conecta no
// Firebird dele, e POSTA os dados brutos aqui via HTTP/JSON.
// Autenticação: servidor_nome + sync_token (cadastrado no CRUD).
if ($action === 'sync_remoto') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['servidor_nome']) || !isset($input['balancas'])) {
        echo json_encode(['ok' => false, 'erro' => 'JSON inválido. Envie {servidor_nome, sync_token?, balancas: [...]}.']);
        exit;
    }

    // Busca servidor pelo nome
    $stmt = $pdo->prepare("SELECT id, sync_token FROM portal_servidores_mgv WHERE nome=?");
    $stmt->execute([$input['servidor_nome']]);
    $sv = $stmt->fetch();

    if (!$sv) {
        echo json_encode(['ok' => false, 'erro' => 'Servidor não encontrado. Cadastre-o primeiro no portal.']);
        exit;
    }

    // Valida token se configurado
    $token_enviado = $input['sync_token'] ?? '';
    if ($sv['sync_token'] && $sv['sync_token'] !== $token_enviado) {
        echo json_encode(['ok' => false, 'erro' => 'Token de sync inválido.']);
        exit;
    }

    $servidor_id = $sv['id'];
    $importados  = 0;
    $atualizados = 0;

    foreach ($input['balancas'] as $b) {
        $ident = trim((string)($b['identificacao'] ?? $b['codigo'] ?? $b['numero'] ?? ''));
        if (!$ident) continue;

        $modelo      = trim((string)($b['modelo'] ?? ''));
        $serie       = trim((string)($b['serie'] ?? $b['serial'] ?? ''));
        $loja        = trim((string)($b['loja'] ?? ''));
        $departamento = trim((string)($b['departamento'] ?? $b['setor'] ?? ''));

        $check = $pdo->prepare("SELECT id FROM portal_balancas WHERE servidor_id=? AND identificacao=?");
        $check->execute([$servidor_id, $ident]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE portal_balancas SET modelo=?, serie=?, loja=?, departamento=? WHERE id=?")
                ->execute([$modelo, $serie, $loja, $departamento, $existing['id']]);
            $atualizados++;
        } else {
            $pdo->prepare("INSERT INTO portal_balancas (servidor_id, identificacao, modelo, serie, loja, departamento) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$servidor_id, $ident, $modelo, $serie, $loja, $departamento]);
            $importados++;
        }
    }

    echo json_encode([
        'ok'          => true,
        'servidor'    => $input['servidor_nome'],
        'recebidas'   => count($input['balancas']),
        'importados'  => $importados,
        'atualizados' => $atualizados,
    ]);
    exit;
}

// ── Página HTML ─────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário de Balanças</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#558b2f; }
    * { box-sizing:border-box; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#2e7d32);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; gap:1rem;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem;
                display:flex; align-items:center; gap:.35rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#2e7d32); color:white;
            padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }

    .wrap { max-width:1100px; margin:-3rem auto 3rem; padding:0 1rem; }

    .stats { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
    .stat-chip {
      background:white; border-radius:10px; border:1px solid #e5e7eb;
      padding:.5rem 1rem; font-size:.82rem; font-weight:600;
      display:flex; align-items:center; gap:.4rem;
      box-shadow:0 1px 4px rgba(0,0,0,.05);
    }

    .servidor-section { margin-bottom:.75rem; }
    .servidor-header {
      background:linear-gradient(135deg,var(--primary),#2e7d32);
      color:white; border-radius:10px;
      padding:.65rem 1.25rem; font-weight:700; font-size:.9rem;
      display:flex; align-items:center; justify-content:space-between;
      cursor:pointer; user-select:none; transition:border-radius .15s, filter .15s;
    }
    .servidor-header:hover { filter:brightness(1.1); }
    .servidor-header .chevron { transition:transform .2s; }
    .servidor-section.expanded .servidor-header { border-radius:10px 10px 0 0; }
    .servidor-section.expanded .servidor-header .chevron { transform:rotate(180deg); }
    .servidor-body {
      background:white; border-radius:0 0 10px 10px;
      border:1px solid #e5e7eb; border-top:none;
      box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
      display:none;
    }
    .servidor-section.expanded .servidor-body { display:block; }

    .sv-status {
      display:inline-flex; align-items:center; gap:.4rem;
      font-size:.75rem; font-weight:600; padding:.2rem .65rem; border-radius:20px;
    }
    .sv-status.online  { background:rgba(255,255,255,.2); color:#c6f6d5; }
    .sv-status.offline { background:rgba(255,255,255,.15); color:#fecaca; }
    .sv-status.checking { background:rgba(255,255,255,.15); color:#fde68a; }
    .sv-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .sv-dot.online  { background:#22c55e; box-shadow:0 0 6px #22c55e; }
    .sv-dot.offline { background:#ef4444; }
    .sv-dot.checking { background:#f59e0b; animation:pulse-yellow 1s infinite; }
    @keyframes pulse-yellow { 0%,100%{opacity:1} 50%{opacity:.4} }

    .table-balancas { margin:0; font-size:.82rem; }
    .table-balancas th { background:#f9fafb; font-size:.72rem; text-transform:uppercase;
                         color:#6b7280; font-weight:700; border-top:none; padding:.5rem .75rem; }
    .table-balancas td { padding:.5rem .75rem; vertical-align:middle; }

    .badge-online  { background:#dcfce7; color:#16a34a; border:1px solid #bbf7d0; }
    .badge-offline { background:#fee2e2; color:#dc2626; border:1px solid #fecaca; }
    .badge-check   { background:#fef3c7; color:#d97706; border:1px solid #fde68a; }

    .servidor-actions { display:flex; gap:.35rem; }
    .servidor-actions button { background:rgba(255,255,255,.15); border:none; color:white;
                                width:28px; height:28px; border-radius:6px; font-size:.75rem;
                                display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .servidor-actions button:hover { background:rgba(255,255,255,.3); }
    .btn-sync { background:rgba(255,255,255,.15); border:none; color:white; border-radius:6px;
                padding:.25rem .65rem; font-size:.72rem; cursor:pointer; display:flex; align-items:center; gap:.3rem; }
    .btn-sync:hover { background:rgba(255,255,255,.3); }

    .toolbar { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; }
    .toolbar .btn-primary { background:var(--accent); border:none; border-radius:8px;
                            font-size:.85rem; font-weight:600; padding:.45rem 1.1rem; }
    .toolbar .btn-primary:hover { filter:brightness(1.1); }
    .toolbar .btn-outline { background:white; border:1px solid #d1d5db; border-radius:8px;
                            font-size:.82rem; padding:.45rem 1rem; color:#374151; cursor:pointer; }
    .toolbar .btn-outline:hover { background:#f9fafb; }

    .modal-cab {
      background:linear-gradient(135deg,var(--primary),#2e7d32); color:white;
    }
    .modal-cab .btn-close { filter:invert(1); }

    /* Empty state */
    .empty-state { text-align:center; padding:3rem 1rem; color:#9ca3af; }
    .empty-state i { font-size:3rem; margin-bottom:1rem; }

    @media (max-width:640px) {
      .hero h1 { font-size:1.2rem; }
      .wrap { margin-top:-2.5rem; }
      .table-balancas { font-size:.75rem; }
      .table-balancas td, .table-balancas th { padding:.35rem .5rem; }
      .servidor-actions { flex-wrap:wrap; }
    }

    /* Toast */
    .toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-speedometer2 me-1"></i> Inventário de Balanças</div>
  <span class="spacer"></span>
  <a href="inventario.php"><i class="bi bi-arrow-left me-1"></i>Categorias</a>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-speedometer2 me-2"></i>Inventário de Balanças</h1>
  <p style="opacity:.8">Servidores MGV 6 e balanças por loja/departamento</p>
</div>

<div class="wrap">

  <!-- Stats -->
  <div class="stats">
    <div class="stat-chip"><i class="bi bi-server text-secondary"></i> Servidores: <strong id="cnt-servidores">—</strong></div>
    <div class="stat-chip"><i class="bi bi-speedometer2 text-secondary"></i> Balanças: <strong id="cnt-balancas">—</strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i> Online: <strong id="cnt-online">—</strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-danger" style="font-size:.6rem"></i> Offline: <strong id="cnt-offline">—</strong></div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <button class="btn btn-primary" onclick="modalServidor()">
      <i class="bi bi-plus-lg me-1"></i>Servidor MGV
    </button>
    <button class="btn btn-primary" onclick="modalBalanca()">
      <i class="bi bi-plus-lg me-1"></i>Balança
    </button>
    <button class="btn btn-outline" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise me-1"></i>Recarregar
    </button>
  </div>

  <!-- Servidores list -->
  <div id="servidores-container"></div>

</div>

<!-- Modal Servidor MGV -->
<div class="modal fade" id="modalServidor" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-cab">
        <h5 class="modal-title" id="sv-titulo"><i class="bi bi-server me-1"></i> Servidor MGV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="sv-id"/>
        <div class="mb-2">
          <label class="form-label small fw-bold">Nome do servidor</label>
          <input type="text" id="sv-nome" class="form-control" placeholder="Ex: MGV Loja 01"/>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">IP</label>
          <input type="text" id="sv-ip" class="form-control" placeholder="10.0.0.50"/>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">Observação</label>
          <textarea id="sv-obs" class="form-control" rows="2" placeholder="Localização, contato..."></textarea>
        </div>
        <hr/>
        <p class="small fw-bold text-secondary mb-2">
          <i class="bi bi-database-gear me-1"></i>Conexão Firebird (sync automático)
        </p>
        <div class="mb-2">
          <label class="form-label small fw-bold">Host Firebird <small class="text-muted">(opcional — padrão = IP)</small></label>
          <input type="text" id="sv-fb-host" class="form-control" placeholder="Deixe vazio para usar o IP acima"/>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">Caminho do .FDB</label>
          <input type="text" id="sv-fb-db" class="form-control" placeholder="Ex: C:\MGV6\DADOS\MGV6.FDB"/>
        </div>
        <div class="row g-2 mb-2">
          <div class="col">
            <label class="form-label small fw-bold">Usuário</label>
            <input type="text" id="sv-fb-user" class="form-control" value="SYSDBA"/>
          </div>
          <div class="col">
            <label class="form-label small fw-bold">Senha</label>
            <input type="password" id="sv-fb-pass" class="form-control" value="masterkey"/>
          </div>
        </div>
        <hr/>
        <p class="small fw-bold text-secondary mb-2">
          <i class="bi bi-shield-lock me-1"></i>Agente PowerShell <small class="text-muted">(token de autenticação)</small>
        </p>
        <div class="mb-2">
          <label class="form-label small fw-bold">Sync Token</label>
          <div class="input-group input-group-sm">
            <input type="text" id="sv-sync-token" class="form-control" placeholder="Token para autenticar o agente remoto"/>
            <button class="btn btn-outline-secondary" type="button" onclick="gerarToken()" title="Gerar token aleatório">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
          <div class="form-text small">Deixe vazio para sync sem autenticação (apenas rede interna). Defina um token se quiser que só servidores com o token correto consigam enviar dados.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary" onclick="salvarServidor()"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Balança -->
<div class="modal fade" id="modalBalanca" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-cab">
        <h5 class="modal-title" id="bl-titulo"><i class="bi bi-speedometer2 me-1"></i> Balança</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="bl-id"/>
        <input type="hidden" id="bl-servidor-id"/>
        <div class="mb-2">
          <label class="form-label small fw-bold">Servidor MGV</label>
          <select id="bl-servidor" class="form-select"></select>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">Identificação <small class="text-muted">(nº da balança)</small></label>
          <input type="text" id="bl-identificacao" class="form-control" placeholder="Ex: BAL01, 001"/>
        </div>
        <div class="row g-2 mb-2">
          <div class="col">
            <label class="form-label small fw-bold">Modelo</label>
            <input type="text" id="bl-modelo" class="form-control" placeholder="Ex: Toledo 9201"/>
          </div>
          <div class="col">
            <label class="form-label small fw-bold">Nº Série</label>
            <input type="text" id="bl-serie" class="form-control" placeholder="Número de série"/>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col">
            <label class="form-label small fw-bold">Loja</label>
            <input type="text" id="bl-loja" class="form-control" placeholder="Loja ou filial"/>
          </div>
          <div class="col">
            <label class="form-label small fw-bold">Departamento</label>
            <input type="text" id="bl-departamento" class="form-control" placeholder="Ex: Padaria, Açougue"/>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">IP <small class="text-muted">(opcional)</small></label>
          <input type="text" id="bl-ip" class="form-control" placeholder="IP da balança se tiver rede"/>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-bold">Observação</label>
          <textarea id="bl-obs" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary" onclick="salvarBalanca()"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-container">
  <div id="toast" class="toast align-items-center border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const toast = new bootstrap.Toast(document.getElementById('toast'));
const _t = document.getElementById('toast');

function mostrarMsg(msg, tipo = 'success') {
  _t.className = 'toast align-items-center border-0 text-white bg-' + tipo;
  _t.querySelector('.toast-body').textContent = msg;
  toast.show();
}

// ── Servidores ──────────────────────────────────────────────────
function carregarServidores() {
  fetch('inventario_balancas.php?action=listar_servidores')
    .then(r => r.json())
    .then(lista => {
      const container = document.getElementById('servidores-container');
      if (!lista.length) {
        container.innerHTML = `<div class="empty-state"><i class="bi bi-server"></i><p class="mt-2">Nenhum servidor MGV cadastrado.<br/>Clique em <strong>Servidor MGV</strong> para adicionar.</p></div>`;
        document.getElementById('cnt-servidores').textContent = '0';
        document.getElementById('cnt-balancas').textContent = '0';
        return;
      }

      document.getElementById('cnt-servidores').textContent = lista.length;
      let totalBalancas = 0;

      container.innerHTML = lista.map(sv => {
        totalBalancas += parseInt(sv.total_balancas || 0);
        return `
        <div class="servidor-section" data-id="${sv.id}">
          <div class="servidor-header" onclick="toggleServidor(this)">
            <span class="d-flex align-items-center gap-2">
              <span class="sv-status checking" id="svstatus-${sv.id}">
                <span class="sv-dot checking" id="svdot-${sv.id}"></span> Verificando...
              </span>
              <i class="bi bi-server me-1"></i>${escHtml(sv.nome)}
              <small style="font-weight:400;opacity:.7">${escHtml(sv.ip)}</small>
            </span>
            <span class="d-flex align-items-center gap-2">
              <button class="btn-sync" onclick="event.stopPropagation();syncMGV(${sv.id})" title="Sincronizar com Firebird do MGV">
                <i class="bi bi-arrow-repeat"></i> Sync
              </button>
              <button class="btn-sync" onclick="event.stopPropagation();modalBalanca(${sv.id})" title="Adicionar balança neste servidor">
                <i class="bi bi-plus-lg"></i>
              </button>
              <button onclick="event.stopPropagation();modalServidor(${sv.id})" title="Editar servidor" style="background:rgba(255,255,255,.15);border:none;color:white;width:28px;height:28px;border-radius:6px;font-size:.75rem;">
                <i class="bi bi-pencil"></i>
              </button>
              <button onclick="event.stopPropagation();excluirServidor(${sv.id})" title="Excluir servidor" style="background:rgba(255,255,255,.15);border:none;color:#fca5a5;width:28px;height:28px;border-radius:6px;font-size:.75rem;">
                <i class="bi bi-trash"></i>
              </button>
              <span class="badge bg-light text-dark">${sv.total_balancas} balança(s)</span>
              <i class="bi bi-chevron-down chevron"></i>
            </span>
          </div>
          <div class="servidor-body">
            <div class="p-2">
              <div id="bl-list-${sv.id}">
                <div class="text-center py-3 text-muted small"><i class="bi bi-arrow-repeat me-1"></i>Carregando balanças...</div>
              </div>
            </div>
          </div>
        </div>`;
      }).join('');

      document.getElementById('cnt-balancas').textContent = totalBalancas;

      // Carrega balanças e verifica status de cada servidor
      lista.forEach(sv => {
        carregarBalancas(sv.id);
        pingServidor(sv.id, sv.ip);
      });
    });
}

function toggleServidor(el) {
  el.closest('.servidor-section').classList.toggle('expanded');
}

function pingServidor(id, ip) {
  const dot = document.getElementById('svdot-' + id);
  const st  = document.getElementById('svstatus-' + id);
  if (!dot || !st) return;

  fetch('ping.php?ip=' + encodeURIComponent(ip))
    .then(r => r.json())
    .then(d => {
      dot.className = 'sv-dot ' + (d.online ? 'online' : 'offline');
      st.className  = 'sv-status ' + (d.online ? 'online' : 'offline');
      st.innerHTML  = `<span class="sv-dot ${d.online ? 'online' : 'offline'}"></span> ${d.online ? 'Online' : 'Offline'}`;
      atualizarStats();
    })
    .catch(() => {
      dot.className = 'sv-dot offline';
      st.className  = 'sv-status offline';
      st.innerHTML  = '<span class="sv-dot offline"></span> Offline';
      atualizarStats();
    });
}

function atualizarStats() {
  const dots = document.querySelectorAll('.sv-dot.online, .sv-dot.offline');
  let online = 0, offline = 0;
  dots.forEach(d => {
    if (d.classList.contains('online')) online++;
    else if (d.classList.contains('offline')) offline++;
  });
  document.getElementById('cnt-online').textContent  = online;
  document.getElementById('cnt-offline').textContent = offline;
}

// ── Balanças ────────────────────────────────────────────────────
function carregarBalancas(servidorId) {
  fetch('inventario_balancas.php?action=listar_balancas&servidor_id=' + servidorId)
    .then(r => r.json())
    .then(lista => {
      const container = document.getElementById('bl-list-' + servidorId);
      if (!lista.length) {
        container.innerHTML = '<div class="text-center py-3 text-muted small"><i class="bi bi-speedometer2 me-1"></i>Nenhuma balança cadastrada neste servidor.</div>';
        return;
      }

      container.innerHTML = `
      <table class="table table-balancas">
        <thead><tr>
          <th>Identificação</th>
          <th>Modelo</th>
          <th>Série</th>
          <th>Loja</th>
          <th>Departamento</th>
          <th style="width:90px">Ações</th>
        </tr></thead>
        <tbody>
        ${lista.map(b => `
          <tr>
            <td><strong>${escHtml(b.identificacao)}</strong></td>
            <td>${escHtml(b.modelo) || '<span class="text-muted">—</span>'}</td>
            <td>${escHtml(b.serie) || '<span class="text-muted">—</span>'}</td>
            <td>${escHtml(b.loja) || '<span class="text-muted">—</span>'}</td>
            <td>${escHtml(b.departamento) || '<span class="text-muted">—</span>'}</td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary btn-sm" onclick="modalBalanca(${b.servidor_id}, ${b.id})" title="Editar"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-outline-danger btn-sm" onclick="excluirBalanca(${b.id})" title="Excluir"><i class="bi bi-trash"></i></button>
              </div>
            </td>
          </tr>
        `).join('')}
        </tbody>
      </table>`;
    });
}

// ── CRUD Servidor ───────────────────────────────────────────────
let modalSv, modalBl;

document.addEventListener('DOMContentLoaded', () => {
  modalSv = new bootstrap.Modal(document.getElementById('modalServidor'));
  modalBl = new bootstrap.Modal(document.getElementById('modalBalanca'));
  carregarServidores();
});

function modalServidor(id) {
  document.getElementById('sv-titulo').textContent = id ? 'Editar Servidor' : 'Novo Servidor';
  document.getElementById('sv-id').value = id || '';
  if (id) {
    fetch('inventario_balancas.php?action=listar_servidores')
      .then(r => r.json())
      .then(lista => {
        const sv = lista.find(s => s.id == id);
        if (sv) {
          document.getElementById('sv-nome').value    = sv.nome || '';
          document.getElementById('sv-ip').value      = sv.ip || '';
          document.getElementById('sv-obs').value     = sv.observacao || '';
          document.getElementById('sv-fb-host').value = sv.fb_host || '';
          document.getElementById('sv-fb-db').value   = sv.fb_database || '';
          document.getElementById('sv-fb-user').value = sv.fb_user || 'SYSDBA';
          document.getElementById('sv-fb-pass').value = sv.fb_pass || 'masterkey';
          document.getElementById('sv-sync-token').value = sv.sync_token || '';
        }
      });
  } else {
    document.getElementById('sv-nome').value = '';
    document.getElementById('sv-ip').value = '';
    document.getElementById('sv-obs').value = '';
    document.getElementById('sv-fb-host').value = '';
    document.getElementById('sv-fb-db').value = '';
    document.getElementById('sv-fb-user').value = 'SYSDBA';
    document.getElementById('sv-fb-pass').value = 'masterkey';
    document.getElementById('sv-sync-token').value = '';
  }
  modalSv.show();
}

function gerarToken() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let token = '';
  for (let i = 0; i < 32; i++) {
    token += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  document.getElementById('sv-sync-token').value = token;
}

function salvarServidor() {
  const fd = new FormData();
  fd.set('action', 'salvar_servidor');
  fd.set('id',      document.getElementById('sv-id').value);
  fd.set('nome',    document.getElementById('sv-nome').value);
  fd.set('ip',      document.getElementById('sv-ip').value);
  fd.set('observacao', document.getElementById('sv-obs').value);
  fd.set('fb_host', document.getElementById('sv-fb-host').value);
  fd.set('fb_database', document.getElementById('sv-fb-db').value);
  fd.set('fb_user', document.getElementById('sv-fb-user').value);
  fd.set('fb_pass', document.getElementById('sv-fb-pass').value);
  fd.set('sync_token', document.getElementById('sv-sync-token').value);

  fetch('inventario_balancas.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) { modalSv.hide(); carregarServidores(); mostrarMsg('Servidor salvo!'); }
      else mostrarMsg(d.erro || 'Erro ao salvar', 'danger');
    });
}

function excluirServidor(id) {
  if (!confirm('Excluir este servidor MGV? Todas as balanças vinculadas serão removidas.')) return;
  const fd = new FormData();
  fd.set('action', 'excluir_servidor');
  fd.set('id', id);
  fetch('inventario_balancas.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => { if (d.ok) { carregarServidores(); mostrarMsg('Servidor excluído!'); } });
}

// ── CRUD Balança ────────────────────────────────────────────────
function modalBalanca(servidorId, balancaId) {
  // Carrega select de servidores
  fetch('inventario_balancas.php?action=listar_servidores')
    .then(r => r.json())
    .then(lista => {
      const sel = document.getElementById('bl-servidor');
      sel.innerHTML = lista.map(s =>
        `<option value="${s.id}">${escHtml(s.nome)} (${escHtml(s.ip)})</option>`
      ).join('');
    });

  document.getElementById('bl-titulo').textContent  = balancaId ? 'Editar Balança' : 'Nova Balança';
  document.getElementById('bl-id').value            = balancaId || '';
  document.getElementById('bl-servidor-id').value    = servidorId || '';

  if (balancaId) {
    // Busca dados da balança
    const svId = servidorId || 0;
    fetch('inventario_balancas.php?action=listar_balancas&servidor_id=' + svId)
      .then(r => r.json())
      .then(lista => {
        const b = lista.find(x => x.id == balancaId);
        if (b) {
          document.getElementById('bl-servidor').value      = b.servidor_id;
          document.getElementById('bl-identificacao').value = b.identificacao || '';
          document.getElementById('bl-modelo').value        = b.modelo || '';
          document.getElementById('bl-serie').value         = b.serie || '';
          document.getElementById('bl-loja').value          = b.loja || '';
          document.getElementById('bl-departamento').value  = b.departamento || '';
          document.getElementById('bl-ip').value            = b.ip || '';
          document.getElementById('bl-obs').value           = b.observacao || '';
        }
      });
  } else {
    document.getElementById('bl-identificacao').value = '';
    document.getElementById('bl-modelo').value = '';
    document.getElementById('bl-serie').value = '';
    document.getElementById('bl-loja').value = '';
    document.getElementById('bl-departamento').value = '';
    document.getElementById('bl-ip').value = '';
    document.getElementById('bl-obs').value = '';
    if (servidorId) document.getElementById('bl-servidor').value = servidorId;
  }
  modalBl.show();
}

function salvarBalanca() {
  const fd = new FormData();
  fd.set('action', 'salvar_balanca');
  fd.set('id',            document.getElementById('bl-id').value);
  fd.set('servidor_id',   document.getElementById('bl-servidor').value);
  fd.set('identificacao', document.getElementById('bl-identificacao').value);
  fd.set('modelo',        document.getElementById('bl-modelo').value);
  fd.set('serie',         document.getElementById('bl-serie').value);
  fd.set('loja',          document.getElementById('bl-loja').value);
  fd.set('departamento',  document.getElementById('bl-departamento').value);
  fd.set('ip',            document.getElementById('bl-ip').value);
  fd.set('observacao',    document.getElementById('bl-obs').value);

  fetch('inventario_balancas.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        modalBl.hide();
        carregarServidores();
        mostrarMsg('Balança salva!');
      } else mostrarMsg(d.erro || 'Erro ao salvar', 'danger');
    });
}

function excluirBalanca(id) {
  if (!confirm('Excluir esta balança?')) return;
  const fd = new FormData();
  fd.set('action', 'excluir_balanca');
  fd.set('id', id);
  fetch('inventario_balancas.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => { if (d.ok) { carregarServidores(); mostrarMsg('Balança excluída!'); } });
}

// ── Sync MGV Firebird ──────────────────────────────────────────
function syncMGV(servidorId) {
  if (!confirm('Sincronizar balanças com o Firebird do MGV?\n\nAs balanças existentes serão atualizadas e novas serão importadas.')) return;

  const btn = event?.target?.closest?.('.btn-sync') || document.querySelector('.btn-sync');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sincronizando...'; }

  const fd = new FormData();
  fd.set('action', 'sync_mgv');
  fd.set('servidor_id', servidorId);

  fetch('inventario_balancas.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        mostrarMsg(`Sync concluído! ${d.importados} importada(s), ${d.atualizados} atualizada(s). Tabela: ${d.tabela}`, 'success');
      } else {
        mostrarMsg(d.erro || 'Erro no sync', 'danger');
      }
    })
    .catch(e => mostrarMsg('Erro de rede: ' + e.message, 'danger'))
    .finally(() => {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Sync'; }
      carregarServidores();
    });
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
