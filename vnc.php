<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

// ── Configuração — ajustar conforme seu servidor ───────────────
define('VNC_PROXY_HOST', '192.168.1.198');   // IP do servidor websockify
define('VNC_PROXY_PORT', '6080');            // Porta do websockify
define('NOVNC_PATH',     '/novnc/vnc.html'); // Caminho do noVNC no XAMPP

// ── Cria tabela ────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_vnc (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(100) NOT NULL,
        descricao VARCHAR(255) DEFAULT '',
        ip        VARCHAR(45)  NOT NULL,
        porta     INT          DEFAULT 5900,
        token     VARCHAR(100) DEFAULT '',
        senha_enc TEXT         DEFAULT '',
        grupo     VARCHAR(60)  DEFAULT '',
        ativo     TINYINT(1)   DEFAULT 1,
        criado_em DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Criptografia simples para senha VNC ───────────────────────
function vnc_enc(string $plain): string {
    if (!$plain) return '';
    $key = hash('sha256', VNC_PROXY_HOST . 'vnc_portal');
    $iv  = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain,'aes-256-cbc',$key,0,$iv));
}
function vnc_dec(string $enc): string {
    if (!$enc) return '';
    $key = hash('sha256', VNC_PROXY_HOST . 'vnc_portal');
    $raw = base64_decode($enc);
    $iv  = substr($raw,0,16);
    return openssl_decrypt(substr($raw,16),'aes-256-cbc',$key,0,$iv) ?: '';
}

// ── API AJAX ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $g   = $_GET['grupo'] ?? '';
        $q   = '%'.trim($_GET['q']??'').'%';
        $sql = "SELECT id,nome,descricao,ip,porta,token,grupo,ativo FROM portal_vnc WHERE (nome LIKE ? OR ip LIKE ? OR grupo LIKE ?)";
        $p   = [$q,$q,$q];
        if ($g) { $sql .= " AND grupo=?"; $p[] = $g; }
        $sql .= " ORDER BY grupo, nome";
        $st = $pdo->prepare($sql); $st->execute($p);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Retorna senha descriptografada para preencher campo (edição)
    if ($action === 'senha' && isset($_GET['id'])) {
        $st = $pdo->prepare("SELECT senha_enc FROM portal_vnc WHERE id=?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
        echo json_encode(['senha' => $row ? vnc_dec($row['senha_enc']) : '']);
        exit;
    }

    // Retorna URL de conexão noVNC
    if ($action === 'connect' && isset($_GET['id'])) {
        $st = $pdo->prepare("SELECT * FROM portal_vnc WHERE id=? AND ativo=1");
        $st->execute([(int)$_GET['id']]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) { echo json_encode(['ok'=>false,'msg'=>'Máquina não encontrada']); exit; }
        $senha = vnc_dec($m['senha_enc']);
        // Token do websockify (pode ser o IP:porta direto ou um alias)
        $token = $m['token'] ?: $m['ip'].':'.$m['porta'];
        $params = http_build_query([
            'host'        => VNC_PROXY_HOST,
            'port'        => VNC_PROXY_PORT,
            'path'        => 'websockify?token='.$token,
            'password'    => $senha,
            'autoconnect' => 'true',
            'reconnect'   => 'true',
            'show_dot'    => 'false',
            'resize'      => 'scale',
        ]);
        $url = 'http://'.VNC_PROXY_HOST.NOVNC_PATH.'?'.$params;
        echo json_encode(['ok'=>true,'url'=>$url,'nome'=>$m['nome'],'ip'=>$m['ip']]);
        exit;
    }

    // Salvar máquina
    if ($action === 'save') {
        $body  = json_decode(file_get_contents('php://input'),true)??[];
        $id    = (int)($body['id']??0);
        $nome  = trim($body['nome']??'');
        $ip    = trim($body['ip']??'');
        if (!$nome||!$ip) { echo json_encode(['ok'=>false,'msg'=>'Nome e IP obrigatórios']); exit; }
        $porta = (int)($body['porta']??5900);
        $token = trim($body['token']??'');
        $grupo = trim($body['grupo']??'');
        $desc  = trim($body['descricao']??'');
        $senha_raw = $body['senha']??'';
        // Se senha vier vazia na edição, mantém a atual
        if ($id && $senha_raw === '') {
            $st = $pdo->prepare("UPDATE portal_vnc SET nome=?,ip=?,porta=?,token=?,grupo=?,descricao=? WHERE id=?");
            $st->execute([$nome,$ip,$porta,$token,$grupo,$desc,$id]);
        } else {
            $senha_enc = vnc_enc($senha_raw);
            if ($id) {
                $st = $pdo->prepare("UPDATE portal_vnc SET nome=?,ip=?,porta=?,token=?,grupo=?,descricao=?,senha_enc=? WHERE id=?");
                $st->execute([$nome,$ip,$porta,$token,$grupo,$desc,$senha_enc,$id]);
            } else {
                $st = $pdo->prepare("INSERT INTO portal_vnc (nome,ip,porta,token,grupo,descricao,senha_enc) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$nome,$ip,$porta,$token,$grupo,$desc,$senha_enc]);
                $id = $pdo->lastInsertId();
            }
        }
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }

    // Excluir
    if ($action === 'delete' && isset($_GET['id'])) {
        $pdo->prepare("DELETE FROM portal_vnc WHERE id=?")->execute([(int)$_GET['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Gera tokens.cfg para websockify
    if ($action === 'tokens_cfg') {
        $rows = $pdo->query("SELECT nome,ip,porta,token FROM portal_vnc WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="tokens.cfg"');
        echo "# tokens.cfg — websockify token file\n";
        echo "# Gerado em: ".date('d/m/Y H:i')."\n\n";
        foreach ($rows as $r) {
            $tk = $r['token'] ?: $r['ip'].':'.$r['porta'];
            echo "{$tk}: {$r['ip']}:{$r['porta']}\n";
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Lista grupos únicos para filtro ───────────────────────────
$grupos = $pdo->query("SELECT DISTINCT grupo FROM portal_vnc WHERE grupo != '' ORDER BY grupo")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>VNC — Acesso Remoto</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --vnc:#1d4ed8; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,#1e3a8a,#1d4ed8);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:200;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,#1e3a8a,#1d4ed8); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    .toolbar { background:white; border-radius:12px; border:1px solid #e5e7eb;
               box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
               margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; }

    /* Cards VNC */
    .vnc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1rem; }

    .vnc-card {
      background:white; border-radius:12px; border:2px solid #e5e7eb;
      padding:1.1rem; transition:all .15s; position:relative;
    }
    .vnc-card:hover { border-color:#1d4ed8; box-shadow:0 4px 16px rgba(29,78,216,.12); }

    .vnc-card-header { display:flex; align-items:center; gap:.75rem; margin-bottom:.6rem; }
    .vnc-avatar {
      width:44px; height:44px; background:#dbeafe; border-radius:10px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.3rem; color:#1d4ed8; flex-shrink:0;
    }
    .vnc-nome   { font-weight:700; font-size:.92rem; color:#111; }
    .vnc-ip     { font-size:.75rem; color:#6b7280; font-family:monospace; }
    .vnc-grupo  { font-size:.68rem; background:#eff6ff; color:#1d4ed8;
                  border-radius:8px; padding:.1rem .5rem; font-weight:600; }
    .vnc-desc   { font-size:.76rem; color:#9ca3af; margin-bottom:.75rem;
                  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .btn-vnc {
      width:100%; border:none; border-radius:8px; padding:.5rem;
      background:linear-gradient(135deg,#1d4ed8,#2563eb);
      color:white; font-size:.84rem; font-weight:600; cursor:pointer;
      transition:all .15s; display:flex; align-items:center; justify-content:center; gap:.4rem;
    }
    .btn-vnc:hover  { background:linear-gradient(135deg,#1e40af,#1d4ed8); transform:translateY(-1px); }
    .btn-vnc.loading { opacity:.7; pointer-events:none; }

    .btn-edit-card {
      position:absolute; top:.6rem; right:.6rem;
      background:none; border:none; color:#d1d5db; cursor:pointer;
      font-size:.85rem; padding:.2rem .35rem; border-radius:6px; transition:all .15s;
    }
    .btn-edit-card:hover { background:#f0f4ff; color:#1d4ed8; }

    /* Modal noVNC iframe */
    .vnc-frame-wrap {
      position:fixed; inset:0; background:rgba(0,0,0,.85);
      z-index:9999; display:flex; flex-direction:column;
    }
    .vnc-frame-bar {
      background:#1e3a8a; color:white; padding:.5rem 1rem;
      display:flex; align-items:center; justify-content:space-between; gap:1rem;
      flex-shrink:0;
    }
    .vnc-frame-bar .maquina { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .vnc-frame-bar .btn-close-vnc {
      background:rgba(255,255,255,.15); border:none; color:white;
      border-radius:8px; padding:.35rem .9rem; cursor:pointer; font-size:.85rem;
    }
    .vnc-frame-bar .btn-close-vnc:hover { background:#dc2626; }
    #vnc-iframe { flex:1; border:none; width:100%; }

    /* Status badge */
    .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .dot-ok  { background:#16a34a; }
    .dot-off { background:#dc2626; }

    /* Setup banner */
    .setup-banner {
      background:#fffbeb; border:1px solid #fcd34d; border-radius:10px;
      padding:.75rem 1.1rem; margin-bottom:1rem; font-size:.82rem; color:#92400e;
    }

    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-camera-video-fill"></i> VNC — Acesso Remoto</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a onclick="baixarTokens()" style="cursor:pointer" title="Baixar tokens.cfg para websockify">
      <i class="bi bi-download me-1"></i>tokens.cfg
    </a>
    <a href="acessos.php"><i class="bi bi-arrow-left me-1"></i>Acessos</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-camera-video-fill me-2"></i>VNC — Acesso Remoto via Browser
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Conecte-se às máquinas diretamente pelo portal</p>
</div>

<div class="wrap">

  <!-- Banner de setup se não houver máquinas -->
  <div class="setup-banner" id="banner-setup" style="display:none">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Configuração necessária:</strong>
    O noVNC + websockify precisa estar rodando no servidor <code><?= VNC_PROXY_HOST ?>:<?= VNC_PROXY_PORT ?></code>.
    Baixe o <strong>tokens.cfg</strong> após cadastrar as máquinas e configure o websockify.
    <a href="#" onclick="abrirGuia()" style="color:#92400e;font-weight:600">Ver guia de instalação</a>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:220px"
           placeholder="🔍 Buscar máquina ou IP..." oninput="carregar()"/>

    <select id="f-grupo" class="form-select form-select-sm" style="width:170px" onchange="carregar()">
      <option value="">Todos os grupos</option>
      <?php foreach ($grupos as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
      <?php endforeach; ?>
    </select>

    <div style="flex:1"></div>
    <span id="cnt-maquinas" style="font-size:.78rem;color:#9ca3af"></span>
    <button class="btn btn-primary btn-sm" onclick="abrirModal()"
            style="background:#1d4ed8;border-color:#1d4ed8">
      <i class="bi bi-plus-lg me-1"></i>Cadastrar Máquina
    </button>
  </div>

  <div id="vnc-grid" class="vnc-grid"></div>
  <p id="sem-maquinas" class="text-center text-muted py-5" style="display:none">
    <i class="bi bi-camera-video-off d-block" style="font-size:2.5rem;margin-bottom:.5rem"></i>
    Nenhuma máquina cadastrada. Clique em "Cadastrar Máquina" para começar.
  </p>

</div><!-- /wrap -->

<!-- Viewer noVNC (overlay full-screen) -->
<div id="vnc-overlay" class="vnc-frame-wrap" style="display:none">
  <div class="vnc-frame-bar">
    <div class="maquina">
      <i class="bi bi-camera-video-fill"></i>
      <span id="vnc-label">Conectando...</span>
      <code id="vnc-ip-label" style="font-size:.75rem;opacity:.7"></code>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
      <button onclick="toggleFullscreen()" class="btn-close-vnc" title="Fullscreen">
        <i class="bi bi-fullscreen me-1"></i>Tela cheia
      </button>
      <button onclick="fecharVNC()" class="btn-close-vnc">
        <i class="bi bi-x-lg me-1"></i>Fechar sessão
      </button>
    </div>
  </div>
  <iframe id="vnc-iframe" allowfullscreen></iframe>
</div>

<!-- Modal cadastro/edição -->
<div class="modal fade" id="modalVNC" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white">
        <h5 class="modal-title fw-bold" id="modal-vnc-titulo">
          <i class="bi bi-plus-circle-fill me-2"></i>Cadastrar Máquina VNC
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="vnc-id"/>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Nome da máquina <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="vnc-nome"
                   placeholder="Ex: PDV-LJ001, Servidor Fiscal"/>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Grupo / Setor</label>
            <input type="text" class="form-control" id="vnc-grupo"
                   placeholder="Ex: Loja 01, Servidores, Retaguarda"
                   list="lista-grupos"/>
            <datalist id="lista-grupos">
              <?php foreach ($grupos as $g): ?>
                <option value="<?= htmlspecialchars($g) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">IP da máquina <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" id="vnc-ip"
                   placeholder="192.168.1.50"/>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Porta VNC</label>
            <input type="number" class="form-control font-monospace" id="vnc-porta"
                   value="5900" min="1" max="65535"/>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">
              Token websockify
              <i class="bi bi-question-circle text-muted" title="Alias do tokens.cfg. Se vazio, usa IP:porta"></i>
            </label>
            <input type="text" class="form-control font-monospace" id="vnc-token"
                   placeholder="PDV-LJ001 (opcional)"/>
          </div>
          <div class="col-md-8">
            <label class="form-label fw-semibold">
              Senha VNC
              <span class="text-muted small">(criptografada — deixe vazio para manter na edição)</span>
            </label>
            <div class="input-group">
              <input type="password" class="form-control font-monospace" id="vnc-senha"
                     placeholder="Senha do RealVNC" autocomplete="new-password"/>
              <button class="btn btn-outline-secondary" type="button"
                      onclick="toggleSenha()" id="btn-toggle-senha">
                <i class="bi bi-eye-fill"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Descrição</label>
            <input type="text" class="form-control" id="vnc-descricao"
                   placeholder="Ex: PDV principal da Loja 01 — Windows 10"/>
          </div>
        </div>

        <!-- Dica configuração RealVNC -->
        <div class="alert alert-warning mt-3 mb-0 py-2" style="font-size:.78rem">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          <strong>RealVNC:</strong> certifique-se de que em cada máquina o
          <strong>Encryption</strong> está como <code>Prefer off</code> e
          <strong>Authentication</strong> como <code>VNC password</code>
          (Security tab do RealVNC Server).
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger me-auto" id="btn-del-vnc" style="display:none" onclick="excluir()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvar()"
                style="background:#1d4ed8;border-color:#1d4ed8">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal guia de instalação -->
<div class="modal fade" id="modalGuia" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#1e3a8a;color:white">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-book-fill me-2"></i>Guia de Instalação — noVNC + websockify
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="font-size:.85rem">
        <h6 class="fw-bold mb-2">1. Baixar noVNC</h6>
        <p>Acesse <a href="https://github.com/novnc/noVNC/releases" target="_blank">github.com/novnc/noVNC/releases</a>,
        baixe a última versão e extraia em:</p>
        <code class="d-block bg-light p-2 rounded mb-3">C:\xampp\htdocs\novnc\</code>

        <h6 class="fw-bold mb-2">2. Baixar websockify</h6>
        <p>Acesse <a href="https://github.com/novnc/websockify/releases" target="_blank">github.com/novnc/websockify/releases</a>
        e baixe o <strong>websockify-win.exe</strong>. Salve em:</p>
        <code class="d-block bg-light p-2 rounded mb-3">C:\xampp\htdocs\novnc\utils\websockify.exe</code>

        <h6 class="fw-bold mb-2">3. Gerar o tokens.cfg</h6>
        <p>Cadastre as máquinas aqui no portal, depois clique em
        <strong>tokens.cfg</strong> na barra de navegação para baixar o arquivo.<br>
        Salve em: <code>C:\xampp\htdocs\novnc\tokens.cfg</code></p>

        <h6 class="fw-bold mb-2">4. Iniciar websockify</h6>
        <p>Abra o Prompt de Comando <strong>como Administrador</strong> e execute:</p>
        <code class="d-block bg-dark text-success p-2 rounded mb-3">
          cd C:\xampp\htdocs\novnc\utils<br>
          websockify.exe <?= VNC_PROXY_PORT ?> --web ..\  --token-plugin TokenFile --token-source ..\tokens.cfg
        </code>

        <h6 class="fw-bold mb-2">5. Configurar RealVNC em cada máquina</h6>
        <ul>
          <li>Abra o <strong>RealVNC Server</strong> → clique no ícone de engrenagem</li>
          <li><strong>Security</strong> → Encryption: <code>Prefer off</code></li>
          <li><strong>Security</strong> → Authentication: <code>VNC password</code></li>
          <li>Defina uma senha forte e salve aqui no portal</li>
        </ul>

        <h6 class="fw-bold mb-2">6. Testar</h6>
        <p>Cadastre uma máquina e clique em <strong>Conectar VNC</strong>. O noVNC abrirá em tela cheia no portal.</p>

        <div class="alert alert-info mb-0 py-2" style="font-size:.78rem">
          <i class="bi bi-lightbulb-fill me-1"></i>
          Para rodar o websockify automaticamente no Windows, use o <strong>NSSM</strong>
          (Non-Sucking Service Manager) para registrá-lo como serviço Windows.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalVNC, modalGuia;

document.addEventListener('DOMContentLoaded', () => {
  modalVNC   = new bootstrap.Modal(document.getElementById('modalVNC'));
  modalGuia  = new bootstrap.Modal(document.getElementById('modalGuia'));
  carregar();
});

// ── Carregar lista ──────────────────────────────────────────────
function carregar() {
  const q     = document.getElementById('f-busca').value;
  const grupo = document.getElementById('f-grupo').value;
  fetch(`vnc.php?action=list&q=${encodeURIComponent(q)}&grupo=${encodeURIComponent(grupo)}`)
    .then(r => r.json())
    .then(items => renderizar(items));
}

function renderizar(items) {
  const grid = document.getElementById('vnc-grid');
  const sem  = document.getElementById('sem-maquinas');
  const banner = document.getElementById('banner-setup');
  document.getElementById('cnt-maquinas').textContent =
    items.length ? `${items.length} máquina${items.length > 1 ? 's' : ''}` : '';

  if (!items.length) { grid.innerHTML = ''; sem.style.display = ''; banner.style.display = ''; return; }
  sem.style.display    = 'none';
  banner.style.display = 'none';

  grid.innerHTML = items.map(m => `
    <div class="vnc-card">
      <button class="btn-edit-card" onclick="editarMaquina(${m.id})" title="Editar">
        <i class="bi bi-pencil-fill"></i>
      </button>
      <div class="vnc-card-header">
        <div class="vnc-avatar"><i class="bi bi-pc-display-horizontal"></i></div>
        <div style="min-width:0">
          <div class="vnc-nome">${esc(m.nome)}</div>
          <div class="vnc-ip">${esc(m.ip)}:${m.porta}</div>
          ${m.grupo ? `<span class="vnc-grupo">${esc(m.grupo)}</span>` : ''}
        </div>
      </div>
      ${m.descricao ? `<div class="vnc-desc" title="${esc(m.descricao)}">${esc(m.descricao)}</div>` : ''}
      <button class="btn-vnc" id="btn-connect-${m.id}" onclick="conectarVNC(${m.id}, this)">
        <i class="bi bi-camera-video-fill"></i> Conectar VNC
      </button>
    </div>`).join('');
}

// ── Conectar VNC ────────────────────────────────────────────────
async function conectarVNC(id, btn) {
  btn.classList.add('loading');
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Abrindo...';
  try {
    const r = await fetch(`vnc.php?action=connect&id=${id}`);
    const d = await r.json();
    if (!d.ok) { toast(d.msg || 'Erro ao conectar', 'danger'); return; }
    document.getElementById('vnc-label').textContent    = d.nome;
    document.getElementById('vnc-ip-label').textContent = d.ip;
    document.getElementById('vnc-iframe').src           = d.url;
    document.getElementById('vnc-overlay').style.display= 'flex';
    document.body.style.overflow = 'hidden';
  } finally {
    btn.classList.remove('loading');
    btn.innerHTML = '<i class="bi bi-camera-video-fill"></i> Conectar VNC';
  }
}

function fecharVNC() {
  document.getElementById('vnc-overlay').style.display = 'none';
  document.getElementById('vnc-iframe').src = '';
  document.body.style.overflow = '';
}

function toggleFullscreen() {
  const el = document.getElementById('vnc-overlay');
  if (!document.fullscreenElement) el.requestFullscreen?.();
  else document.exitFullscreen?.();
}

// Fecha VNC com ESC
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('vnc-overlay').style.display !== 'none') {
    fecharVNC();
  }
});

// ── Modal cadastro / edição ─────────────────────────────────────
function abrirModal() {
  document.getElementById('vnc-id').value        = '';
  document.getElementById('vnc-nome').value      = '';
  document.getElementById('vnc-ip').value        = '';
  document.getElementById('vnc-porta').value     = '5900';
  document.getElementById('vnc-token').value     = '';
  document.getElementById('vnc-senha').value     = '';
  document.getElementById('vnc-grupo').value     = '';
  document.getElementById('vnc-descricao').value = '';
  document.getElementById('btn-del-vnc').style.display = 'none';
  document.getElementById('modal-vnc-titulo').innerHTML =
    '<i class="bi bi-plus-circle-fill me-2"></i>Cadastrar Máquina VNC';
  modalVNC.show();
}

async function editarMaquina(id) {
  const r = await fetch(`vnc.php?action=list&q=`);
  const items = await r.json();
  const m = items.find(x => x.id == id);
  if (!m) return;
  document.getElementById('vnc-id').value        = m.id;
  document.getElementById('vnc-nome').value      = m.nome;
  document.getElementById('vnc-ip').value        = m.ip;
  document.getElementById('vnc-porta').value     = m.porta;
  document.getElementById('vnc-token').value     = m.token || '';
  document.getElementById('vnc-senha').value     = '';   // não pré-preenche por segurança
  document.getElementById('vnc-grupo').value     = m.grupo || '';
  document.getElementById('vnc-descricao').value = m.descricao || '';
  document.getElementById('btn-del-vnc').style.display = '';
  document.getElementById('modal-vnc-titulo').innerHTML =
    `<i class="bi bi-pencil-fill me-2"></i>Editar — ${esc(m.nome)}`;
  modalVNC.show();
}

async function salvar() {
  const nome = document.getElementById('vnc-nome').value.trim();
  const ip   = document.getElementById('vnc-ip').value.trim();
  if (!nome || !ip) { alert('Nome e IP são obrigatórios.'); return; }
  const r = await fetch('vnc.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      id:        document.getElementById('vnc-id').value || null,
      nome, ip,
      porta:     parseInt(document.getElementById('vnc-porta').value) || 5900,
      token:     document.getElementById('vnc-token').value.trim(),
      senha:     document.getElementById('vnc-senha').value,
      grupo:     document.getElementById('vnc-grupo').value.trim(),
      descricao: document.getElementById('vnc-descricao').value.trim(),
    }),
  });
  const d = await r.json();
  if (d.ok) { modalVNC.hide(); carregar(); toast('✅ Máquina salva!'); }
  else alert(d.msg || 'Erro ao salvar');
}

async function excluir() {
  const id = document.getElementById('vnc-id').value;
  if (!confirm('Remover esta máquina do VNC?')) return;
  await fetch(`vnc.php?action=delete&id=${id}`);
  modalVNC.hide(); carregar(); toast('🗑️ Máquina removida.');
}

function toggleSenha() {
  const inp = document.getElementById('vnc-senha');
  const btn = document.getElementById('btn-toggle-senha');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.innerHTML = inp.type === 'password'
    ? '<i class="bi bi-eye-fill"></i>'
    : '<i class="bi bi-eye-slash-fill"></i>';
}

function baixarTokens() { window.location.href = 'vnc.php?action=tokens_cfg'; }
function abrirGuia()    { modalGuia.show(); }

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toast(msg, type='success') {
  const id = 'toast-' + Date.now();
  const bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',`
    <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2">
      <div class="d-flex"><div class="toast-body">${msg}</div>
      <button class="btn-close btn-close-white me-2 m-auto"
              onclick="document.getElementById('${id}').remove()"></button></div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}
</script>
</body>
</html>
