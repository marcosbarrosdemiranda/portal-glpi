<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Criptografia (mesma do Cofre TI e Central RDP) ───────────
if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}
function rdp_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function rdp_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

// ── Tabelas ───────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_anydesk_grupos (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(60) NOT NULL UNIQUE,
        icone     VARCHAR(40) DEFAULT 'bi-laptop',
        cor_bg    VARCHAR(20) DEFAULT '#c2410c',
        cor_fundo VARCHAR(20) DEFAULT '#fff7ed',
        cor_badge VARCHAR(20) DEFAULT '#ffedd5',
        cor_text  VARCHAR(20) DEFAULT '#c2410c',
        ordem     INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_anydesk_maquinas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(80)  NOT NULL,
        anydesk_id  VARCHAR(20)  NOT NULL,
        descricao   VARCHAR(255) DEFAULT '',
        senha       TEXT         DEFAULT NULL,
        categoria   VARCHAR(60)  NOT NULL DEFAULT 'Servidores',
        ordem       INT          DEFAULT 0,
        ativo       TINYINT(1)   DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Popula grupos defaults se vazio
if (!$pdo->query("SELECT COUNT(*) FROM portal_anydesk_grupos")->fetchColumn()) {
    $ins = $pdo->prepare("INSERT INTO portal_anydesk_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text,ordem) VALUES (?,?,?,?,?,?,?)");
    $ins->execute(['Servidores','bi-server','#c2410c','#fff7ed','#ffedd5','#c2410c',1]);
    $ins->execute(['Coletores','bi-cpu-fill','#d97706','#fffbeb','#fef3c7','#b45309',2]);
    $ins->execute(['PCs Estratégicos','bi-pc-display-horizontal','#dc2626','#fef2f2','#fee2e2','#b91c1c',3]);
}

// ── Carrega grupos do banco ───────────────────────────────────
$grupos_rows = $pdo->query("SELECT * FROM portal_anydesk_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$cats = [];
$cat_lista = [];
foreach ($grupos_rows as $gr) {
    $key = $gr['nome'];
    $cats[$key] = [
        'label'      => $gr['nome'],
        'icon'       => $gr['icone'],
        'bg'         => $gr['cor_bg'],
        'color'      => $gr['cor_fundo'],
        'badge'      => $gr['cor_badge'],
        'badge-text' => $gr['cor_text'],
    ];
    $cat_lista[] = $key;
}

// ── Action handler ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {

    // ── List ─────────────────────────────────────────────────
    if ($action === 'list') {
        $categoria = $_GET['categoria'] ?? '';
        $sql = "SELECT id, nome, anydesk_id, descricao,
                       CASE WHEN senha IS NOT NULL AND senha != '' THEN 1 ELSE 0 END as has_senha,
                       categoria, ordem
                FROM portal_anydesk_maquinas WHERE ativo=1";
        $params = [];
        if ($categoria && in_array($categoria, $cat_lista)) {
            $sql .= " AND categoria=?";
            $params[] = $categoria;
        }
        $sql .= " ORDER BY ordem, nome";
        $rows = $pdo->prepare($sql);
        $rows->execute($params);
        echo json_encode(['ok' => true, 'dados' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── Add ─────────────────────────────────────────────────
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = trim($body['nome']??''); $a = trim($body['anydesk_id']??'');
        $d = trim($body['descricao']??''); $s = $body['senha'] ?? '';
        $c = $body['categoria']??($cat_lista[0]??'Servidores');
        if (!$n||!$a) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e AnyDesk ID']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0]??'Servidores';
        $senha_enc = $s ? rdp_encrypt($s) : null;
        $st = $pdo->prepare("INSERT INTO portal_anydesk_maquinas (nome,anydesk_id,descricao,senha,categoria,ordem) VALUES (?,?,?,?,?,?)");
        $st->execute([$n,$a,$d,$senha_enc,$c,0]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }

    // ── Edit ────────────────────────────────────────────────
    if ($action === 'edit') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id=(int)($body['id']??0); $n=trim($body['nome']??''); $a=trim($body['anydesk_id']??'');
        $d=trim($body['descricao']??''); $s = $body['senha'] ?? '';
        $c=$body['categoria']??($cat_lista[0]??'Servidores');
        if (!$id||!$n||!$a) { echo json_encode(['ok'=>false,'msg'=>'Preencha nome e AnyDesk ID']); exit; }
        if (!in_array($c, $cat_lista)) $c = $cat_lista[0]??'Servidores';
        if ($s !== '') {
            $senha_enc = rdp_encrypt($s);
            $pdo->prepare("UPDATE portal_anydesk_maquinas SET nome=?,anydesk_id=?,descricao=?,senha=?,categoria=? WHERE id=?")
                ->execute([$n,$a,$d,$senha_enc,$c,$id]);
        } else {
            $pdo->prepare("UPDATE portal_anydesk_maquinas SET nome=?,anydesk_id=?,descricao=?,categoria=? WHERE id=?")
                ->execute([$n,$a,$d,$c,$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Delete ──────────────────────────────────────────────
    if ($action === 'delete' && isset($_GET['id'])) {
        $del_id = (int)$_GET['id'];
        $pdo->prepare("DELETE FROM portal_anydesk_maquinas WHERE id=?")->execute([$del_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Baixar .bat com ID e senha ──────────────────────────
    if ($action === 'bat' && isset($_GET['id'])) {
        $mid = (int)$_GET['id'];
        $st = $pdo->prepare("SELECT nome, anydesk_id, senha FROM portal_anydesk_maquinas WHERE id=?");
        $st->execute([$mid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) { echo json_encode(['ok'=>false,'msg'=>'Máquina não encontrada']); exit; }
        $anydesk_id = $m['anydesk_id'];
        $senha_raw = ($m['senha'] && $m['senha'] !== '') ? rdp_decrypt($m['senha']) : '';
        // Monta .bat
        $bat = '@echo off' . "\r\n";
        $bat .= 'title Conectando AnyDesk — ' . $m['nome'] . "\r\n";
        $bat .= 'echo Conectando a ' . $m['nome'] . ' (ID: ' . $anydesk_id . ')...' . "\r\n";
        $bat .= '' . "\r\n";
        // Tenta 1: protocolo anydesk:// (se registrado no Windows)
        $bat .= 'echo [1/3] Tentando protocolo anydesk://...' . "\r\n";
        $bat .= 'start anydesk://' . $anydesk_id . "\r\n";
        if ($senha_raw) {
            $bat .= "\r\n";
            $bat .= 'echo [2/3] Tentando AnyDesk.exe com senha automatica...' . "\r\n";
            $bat .= 'for %%p in (' . "\r\n";
            $bat .= '  "%ProgramFiles%\\AnyDesk\\AnyDesk.exe"' . "\r\n";
            $bat .= '  "%ProgramFiles(x86)%\\AnyDesk\\AnyDesk.exe"' . "\r\n";
            $bat .= '  "%LOCALAPPDATA%\\AnyDesk\\AnyDesk.exe"' . "\r\n";
            $bat .= '  "AnyDesk.exe"' . "\r\n";
            $bat .= ') do (' . "\r\n";
            $bat .= '  if exist "%%~p" (' . "\r\n";
            $bat .= '    start "" "%%~p" ' . $anydesk_id . ' --with-password ' . $senha_raw . "\r\n";
            $bat .= '    goto :fim' . "\r\n";
            $bat .= '  )' . "\r\n";
            $bat .= ')' . "\r\n";
        }
        $bat .= "\r\n";
        $bat .= ':fim' . "\r\n";
        $bat .= 'echo.' . "\r\n";
        $bat .= 'echo ✅ Se nao abrir, cole manualmente o ID: ' . $anydesk_id . "\r\n";
        $bat .= 'pause' . "\r\n";
        $bat_nome = 'anydesk_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $m['nome']) . '.bat';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $bat_nome . '"');
        echo $bat;
        exit;
    }

    // ── Obter senha (para copiar) ───────────────────────────
    if ($action === 'get_password' && isset($_GET['id'])) {
        $st = $pdo->prepare("SELECT senha FROM portal_anydesk_maquinas WHERE id=?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['senha']) {
            echo json_encode(['ok'=>true, 'senha'=>rdp_decrypt($row['senha'])]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Nenhuma senha salva']);
        }
        exit;
    }

    // ── Batch ordem ─────────────────────────────────────────
    if ($action === 'batch') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!empty($body['itens'])) {
            $st = $pdo->prepare("UPDATE portal_anydesk_maquinas SET ordem=? WHERE id=?");
            foreach ($body['itens'] as $idx => $item) {
                $st->execute([$idx, (int)$item['id']]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Listar grupos ───────────────────────────────────────
    if ($action === 'list_grupos') {
        $rows = $pdo->query("SELECT * FROM portal_anydesk_grupos ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'dados'=>$rows]); exit;
    }

    // ── Salvar grupo ────────────────────────────────────────
    if ($action === 'save_grupo') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id'] ?? 0);
        $nome = trim($body['nome'] ?? '');
        $icone = trim($body['icone'] ?? 'bi-laptop');
        $cor_bg = trim($body['cor_bg'] ?? '#c2410c');
        $cor_fundo = trim($body['cor_fundo'] ?? '#fff7ed');
        $cor_badge = trim($body['cor_badge'] ?? '#ffedd5');
        $cor_text = trim($body['cor_text'] ?? '#c2410c');
        if (!$nome) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
        if ($id) {
            $st = $pdo->prepare("UPDATE portal_anydesk_grupos SET nome=?,icone=?,cor_bg=?,cor_fundo=?,cor_badge=?,cor_text=? WHERE id=?");
            $st->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text,$id]);
        } else {
            $st = $pdo->prepare("INSERT INTO portal_anydesk_grupos (nome,icone,cor_bg,cor_fundo,cor_badge,cor_text) VALUES (?,?,?,?,?,?)");
            $st->execute([$nome,$icone,$cor_bg,$cor_fundo,$cor_badge,$cor_text]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Excluir grupo ───────────────────────────────────────
    if ($action === 'delete_grupo') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID obrigatório']); exit; }
        $st = $pdo->prepare("SELECT nome FROM portal_anydesk_grupos WHERE id=?");
        $st->execute([$id]);
        $g = $st->fetch();
        if (!$g) { echo json_encode(['ok'=>false,'msg'=>'Grupo não encontrado']); exit; }
        $primeiro = $pdo->query("SELECT nome FROM portal_anydesk_grupos WHERE id!={$id} ORDER BY ordem, nome LIMIT 1")->fetchColumn();
        if ($primeiro) {
            $pdo->prepare("UPDATE portal_anydesk_maquinas SET categoria=? WHERE categoria=?")->execute([$primeiro, $g['nome']]);
        }
        $pdo->prepare("DELETE FROM portal_anydesk_grupos WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'Erro: '.$e->getMessage()]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Central AnyDesk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
:root{--primary:#c2410c}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",system-ui,sans-serif;background:#f3f4f6;min-height:100vh}
.topbar{background:linear-gradient(135deg,#9a3412,#c2410c);color:#fff;display:flex;align-items:center;padding:.6rem 1.25rem;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.25);gap:.75rem}
.topbar h2{font-size:1.15rem;font-weight:700;margin:0}
.topbar a{color:rgba(255,255,255,.8);text-decoration:none;font-size:.85rem;transition:color .12s}
.topbar a:hover{color:#fff}
.hero{background:linear-gradient(135deg,#9a3412,#ea580c);color:#fff;padding:1.5rem 2rem;text-align:center}
.hero h1{font-size:1.5rem;font-weight:700;margin:0}
.hero p{font-size:.9rem;margin:.25rem 0 0;opacity:.9}
.wrap{max-width:1100px;margin:0 auto;padding:1.25rem}
.stats-row{display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap}
.stat-pill{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:.3rem .85rem;font-size:.78rem;color:#374151;display:flex;align-items:center;gap:.35rem}
.filtro-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap}
.filtro-bar .btn-filtro{border:1px solid #d1d5db;background:white;border-radius:20px;padding:.3rem .85rem;font-size:.78rem;cursor:pointer;transition:all .12s;color:#374151;display:flex;align-items:center;gap:.35rem}
.filtro-bar .btn-filtro:hover{border-color:#6b7280;background:#f9fafb}
.filtro-bar .btn-filtro.ativo{border-color:var(--primary);background:#ffedd5;color:#c2410c;font-weight:600}
.filtro-bar .btn-filtro .qtd{border-radius:10px;background:rgba(0,0,0,.08);padding:.05rem .4rem;font-size:.65rem;margin-left:.2rem}
.section-header{display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;border-radius:10px;cursor:pointer;font-weight:600;font-size:.9rem;transition:all .12s;user-select:none}
.section-header:hover{filter:brightness(.95)}
.section-header .badge-cat{margin-left:auto;border-radius:12px;padding:.1rem .55rem;font-size:.72rem;font-weight:600}
.section-header .chevron{font-size:.7rem;opacity:.6;transition:transform .2s}
.section-header.expanded .chevron{transform:rotate(0)}
.section-body{display:none}
.section-body.open{display:block}
.section-body-inner{display:flex;flex-direction:column;gap:.35rem;padding:.4rem 0 .3rem 1.5rem}
.maq-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:.5rem .75rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;transition:all .12s}
.maq-card:hover{border-color:#d1d5db;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.maq-info{display:flex;align-items:center;gap:.6rem;min-width:0;flex:1}
.maq-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.maq-nome{font-weight:600;font-size:.85rem;color:#111827}
.maq-anydesk{font-size:.75rem;color:#6b7280;font-family:Consolas,monospace}
.maq-desc{font-size:.72rem;color:#9ca3af;margin-top:.1rem}
.maq-actions{display:flex;align-items:center;gap:.35rem;flex-shrink:0}
.btn-any{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .7rem;border-radius:6px;font-size:.75rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all .12s;background:#ea580c;color:#fff}
.btn-any:hover{background:#c2410c;color:#fff}
.btn-senha{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .6rem;border-radius:6px;font-size:.7rem;font-weight:500;text-decoration:none;border:1px solid #d1d5db;cursor:pointer;background:#fff;color:#6b7280;transition:all .12s}
.btn-senha:hover{background:#f3f4f6}
.btn-config{background:none;border:none;color:#6b7280;padding:.25rem;border-radius:4px;cursor:pointer;font-size:.82rem;transition:all .12s}
.btn-config:hover{background:#f3f4f6;color:#111827}
.card-add{border:2px dashed #d1d5db;border-radius:12px;padding:1.25rem;text-align:center;cursor:pointer;color:#6b7280;transition:all .12s;margin-top:.5rem}
.card-add:hover{border-color:#c2410c;color:#c2410c;background:#fff7ed}
.badge-senha{background:#fef3c7;color:#d97706;border-radius:4px;padding:.05rem .35rem;font-size:.62rem;font-weight:600;vertical-align:middle}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem}
.toast{box-shadow:0 4px 12px rgba(0,0,0,.15);border-radius:8px;font-size:.85rem;max-width:380px}
</style>
</head>
<body>

<div class="topbar">
  <i class="bi bi-arrows-fullscreen" style="font-size:1.3rem"></i>
  <h2 style="margin-right:auto">Central AnyDesk</h2>
  <a href="acessos.php"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Acessos</a>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-arrows-fullscreen me-2"></i>AnyDesk — Acesso Remoto</h1>
  <p>Clique em <strong>Baixar .bat</strong> para gerar um atalho que conecta direto ao AnyDesk</p>
</div>

<div class="wrap" id="app">
  <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:.5rem;margin-bottom:.5rem">
    <div class="stats-row" id="stats" style="margin-bottom:0"></div>
    <button class="btn btn-sm btn-outline-secondary" onclick="abrirModalGrupo()" title="Gerenciar grupos AnyDesk"><i class="bi bi-tags-fill me-1"></i>Grupos</button>
  </div>
  <div class="filtro-bar" id="filtro-bar"></div>
  <div id="lista-categorias"></div>
</div>

<!-- Modal CRUD -->
<div class="modal fade" id="modalMaq" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#9a3412,#ea580c);color:white">
        <h5 class="modal-title fw-bold" id="modal-label"><i class="bi bi-arrows-fullscreen me-2"></i>Nova Máquina</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3"><label class="form-label fw-semibold">Nome</label><input type="text" class="form-control" id="edit-nome" placeholder="SRV-APP"/></div>
        <div class="mb-3"><label class="form-label fw-semibold">AnyDesk ID <span class="text-muted small">(9 dígitos)</span></label>
          <input type="text" class="form-control font-monospace" id="edit-anydesk-id" placeholder="123456789" maxlength="20"/>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold">Descrição <span class="text-muted small">(opcional)</span></label><input type="text" class="form-control" id="edit-desc" placeholder="Servidor de aplicações..."/></div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha <span class="text-muted small">(para acesso não assistido)</span></label>
          <div class="input-group">
            <input type="password" class="form-control" id="edit-senha" placeholder="Deixe em branco para não salvar"/>
            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()" style="font-size:.75rem"><i class="bi bi-eye-fill"></i></button>
          </div>
          <div class="form-text text-muted small">Senha fica criptografada no banco. Disponivel no botão "Copiar senha".</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Categoria</label>
          <div class="input-group">
            <select class="form-select" id="edit-categoria" style="flex:1"></select>
            <button class="btn btn-outline-secondary" type="button" onclick="abrirModalGrupo()" title="Gerenciar grupos AnyDesk"><i class="bi bi-gear-fill"></i></button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvar()" style="background:#ea580c;border-color:#ea580c"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Gerenciar Grupos -->
<div class="modal fade" id="modalGrupos" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#9a3412,#c2410c);color:white">
        <h5 class="modal-title fw-bold"><i class="bi bi-tags-fill me-2"></i>Gerenciar Grupos AnyDesk</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="grupo-list">
        <p class="text-muted small mb-3">Os grupos aparecem como abas na tela principal. Máquinas de um grupo excluído são movidas para o primeiro grupo.</p>
        <div id="grupos-container"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-primary" onclick="salvarGrupo()" style="background:#ea580c;border-color:#ea580c"><i class="bi bi-plus-circle me-1"></i>Novo Grupo</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CATS = <?= json_encode($cats) ?>;
let GRUPOS = [];
let modalMaq, modalGrupos;
let filtroAtivo = '';
const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
  modalMaq = new bootstrap.Modal(document.getElementById('modalMaq'));
  if (document.getElementById('modalGrupos')) modalGrupos = new bootstrap.Modal(document.getElementById('modalGrupos'));
  carregarTudo();
});

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function getLabel(cat) { return CATS[cat]?.label || cat; }
function getIcon(cat) { return CATS[cat]?.icon || 'bi-laptop'; }
function getBg(cat) { return CATS[cat]?.bg || '#6b7280'; }
function getColor(cat) { return CATS[cat]?.color || '#f3f4f6'; }
function getBadgeText(cat) { return CATS[cat]?.['badge-text'] || '#374151'; }
function getBadge(cat) { return CATS[cat]?.badge || '#e5e7eb'; }

async function carregarTudo() {
  await carregarGrupos();
  await carregarMaquinas();
}

async function carregarGrupos() {
  const r = await fetch('anydesk_central.php?action=list_grupos');
  const d = await r.json();
  GRUPOS = d.dados || [];
  renderizarSelectCategoria();
}

function renderizarSelectCategoria() {
  const sel = document.getElementById('edit-categoria');
  if (!sel) return;
  sel.innerHTML = GRUPOS.map(g => `<option value="${escAttr(g.nome)}">${esc(g.nome)}</option>`).join('');
}

async function carregarMaquinas() {
  const url = filtroAtivo ? 'anydesk_central.php?action=list&categoria=' + filtroAtivo : 'anydesk_central.php?action=list';
  const r = await fetch(url), d = await r.json();
  const maqs = d.dados || [];
  renderStats(maqs);
  renderFiltro(maqs);
  renderLista(maqs);
}

function renderStats(maqs) {
  const total = maqs.length;
  const qtds = {};
  GRUPOS.forEach(g => qtds[g.nome] = 0);
  maqs.forEach(m => qtds[m.categoria] = (qtds[m.categoria]||0) + 1);
  let h = `<div class="stat-pill"><i class="bi bi-arrows-fullscreen text-primary"></i>${total} máquina(s)</div>`;
  GRUPOS.forEach(g => {
    const c = g.nome;
    if (qtds[c]) h += `<div class="stat-pill"><i class="${getIcon(c)}" style="color:${getBg(c)}"></i>${getLabel(c)}: ${qtds[c]}</div>`;
  });
  document.getElementById('stats').innerHTML = h;
}

function renderFiltro(maqs) {
  let h = `<button class="btn-filtro ${!filtroAtivo ? 'ativo' : ''}" onclick="setFiltro('')"><i class="bi bi-funnel"></i>Todas</button>`;
  GRUPOS.forEach(g => {
    const c = g.nome;
    const qtd = maqs.filter(m => m.categoria === c).length;
    h += `<button class="btn-filtro ${filtroAtivo === c ? 'ativo' : ''}" onclick="setFiltro('${escAttr(c)}')"><i class="${getIcon(c)}"></i>${getLabel(c)}<span class="qtd">${qtd}</span></button>`;
  });
  document.getElementById('filtro-bar').innerHTML = h;
}

function setFiltro(cat) {
  filtroAtivo = cat;
  carregarMaquinas();
}

function renderLista(maqs) {
  const agrupado = {};
  GRUPOS.forEach(g => agrupado[g.nome] = []);
  maqs.forEach(m => { if (agrupado[m.categoria]) agrupado[m.categoria].push(m); });

  if (typeof window._catAberto === 'undefined') window._catAberto = {};
  const aberto = window._catAberto;

  const el = document.getElementById('lista-categorias');
  let html = '';
  GRUPOS.forEach(g => {
    const c = g.nome;
    const itens = agrupado[c];
    if (!itens || !itens.length) return;
    const isOpen = aberto[c] === true;
    html += `<section>
      <div class="section-header ${isOpen?'expanded':''}" style="background:${getColor(c)};color:${getBg(c)}" onclick="toggleCategoria('${escAttr(c)}')">
        <i class="${getIcon(c)}"></i>${getLabel(c)}
        <span class="badge-cat" style="background:${getBadge(c)};color:${getBadgeText(c)}">${itens.length}</span>
        <span class="chevron">${isOpen?'▴':'▾'}</span>
      </div>
      <div class="section-body ${isOpen?'open':''}" id="corpo-${c}">
        <div class="section-body-inner">`;
    itens.forEach(m => {
      const temSenha = m.has_senha == 1;
      html += `<div class="maq-card" id="maq-${m.id}">
        <div class="maq-info">
          <div class="maq-icon" style="background:${getColor(c)};color:${getBg(c)}"><i class="${getIcon(c)}"></i></div>
          <div>
            <div class="maq-nome">${esc(m.nome)} ${temSenha ? '<span class="badge-senha"><i class="bi bi-lock-fill"></i> senha</span>' : ''}</div>
            <div class="maq-anydesk">ID: ${esc(m.anydesk_id)}</div>
            ${m.descricao ? `<div class="maq-desc">${esc(m.descricao)}</div>` : ''}
          </div>
        </div>
        <div class="maq-actions">
          <button class="btn-any" onclick="baixarBat(${m.id})"><i class="bi bi-download"></i>Baixar .bat</button>
          <button class="btn-senha" onclick="copiarId('${esc(m.anydesk_id)}')" title="Copiar ID"><i class="bi bi-clipboard"></i></button>
          ${temSenha ? `<button class="btn-senha" onclick="copiarSenha(${m.id})" title="Copiar senha"><i class="bi bi-key-fill"></i></button>` : ''}
          ${isAdmin ? `<button class="btn-config" onclick="editar(${m.id})"><i class="bi bi-pencil-fill"></i></button><button class="btn-config" onclick="excluir(${m.id})" style="color:#ef4444"><i class="bi bi-trash-fill"></i></button>` : ''}
        </div>
      </div>`;
    });
    html += `</div></div></section>`;
  });

  if (isAdmin) {
    html += `<div class="card-add" onclick="abrirModal()"><i class="bi bi-plus-circle" style="font-size:1.3rem;display:block;margin-bottom:.35rem"></i><strong>Adicionar nova máquina AnyDesk</strong></div>`;
  }

  if (!maqs.length) {
    html = `<div style="text-align:center;padding:3rem 1rem;color:#9ca3af"><i class="bi bi-arrows-fullscreen" style="font-size:3rem;display:block;margin-bottom:1rem"></i><p>Nenhuma máquina cadastrada.</p>${isAdmin ? '<button class="btn btn-primary btn-sm" onclick="abrirModal()" style="background:#ea580c;border-color:#ea580c">Adicionar</button>' : ''}</div>`;
  }

  el.innerHTML = html;
}

function toggleCategoria(cat) {
  if (typeof window._catAberto === 'undefined') window._catAberto = {};
  const aberto = window._catAberto;
  aberto[cat] = !aberto[cat];
  const corpo = document.getElementById('corpo-' + cat);
  if (corpo) corpo.classList.toggle('open');
  const secao = corpo?.closest('section');
  if (secao) {
    const hdr = secao.querySelector('.section-header');
    if (hdr) {
      hdr.classList.toggle('expanded');
      const ch = hdr.querySelector('.chevron');
      if (ch) ch.textContent = aberto[cat] ? '▴' : '▾';
    }
  }
}

function abrirModal() {
  ['edit-id','edit-nome','edit-anydesk-id','edit-desc','edit-senha'].forEach(id => document.getElementById(id).value = '');
  if (GRUPOS.length) document.getElementById('edit-categoria').value = GRUPOS[0].nome;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Nova Máquina';
  modalMaq.show();
}

async function editar(id) {
  const r = await fetch('anydesk_central.php?action=list'), d = await r.json();
  const item = (d.dados||[]).find(x => x.id == id);
  if (!item) { toast('Máquina não encontrada', 'danger'); return; }
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-nome').value = item.nome;
  document.getElementById('edit-anydesk-id').value = item.anydesk_id;
  document.getElementById('edit-desc').value = item.descricao || '';
  document.getElementById('edit-senha').value = '';
  document.getElementById('edit-categoria').value = item.categoria;
  document.getElementById('modal-label').innerHTML = '<i class="bi bi-pencil-fill me-2"></i>' + esc(item.nome);
  if (item.has_senha == 1) {
    document.getElementById('edit-senha').placeholder = '🔒 Mantenha em branco para não alterar';
  }
  modalMaq.show();
}

async function salvar() {
  const id = document.getElementById('edit-id').value;
  const nome = document.getElementById('edit-nome').value.trim();
  const anydesk_id = document.getElementById('edit-anydesk-id').value.trim();
  const desc = document.getElementById('edit-desc').value.trim();
  const senha = document.getElementById('edit-senha').value;
  const categoria = document.getElementById('edit-categoria').value;
  if (!nome || !anydesk_id) { toast('Preencha nome e AnyDesk ID', 'danger'); return; }
  const action = id ? 'edit' : 'add';
  const r = await fetch('anydesk_central.php?action=' + action, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: parseInt(id)||0, nome, anydesk_id, descricao: desc, senha, categoria})
  });
  const d = await r.json();
  if (d.ok) {
    modalMaq.hide();
    if (!id) toast('✅ Adicionada!', 'success');
    else toast('✅ Atualizada!', 'success');
    carregarTudo();
  } else toast(d.msg || 'Erro', 'danger');
}

async function excluir(id) {
  if (!confirm('Excluir esta máquina?')) return;
  const r = await fetch('anydesk_central.php?action=delete&id=' + id), d = await r.json();
  if (d.ok) { toast('🗑️ Excluída'); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function baixarBat(id) {
  window.location.href = 'anydesk_central.php?action=bat&id=' + id;
}

function copiarId(id) {
  var ok = false;
  try { navigator.clipboard.writeText(id); ok = true; } catch(e) {}
  if (!ok) {
    var ta = document.createElement('textarea');
    ta.value = id; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); document.body.removeChild(ta);
  }
  toast('📋 ID ' + id + ' copiado! Cole no AnyDesk.', 'success');
}

async function copiarSenha(id) {
  const r = await fetch('anydesk_central.php?action=get_password&id=' + id);
  const d = await r.json();
  if (d.ok && d.senha) {
    try {
      await navigator.clipboard.writeText(d.senha);
      toast('🔑 Senha copiada!', 'success');
    } catch {
      // Fallback para HTTP
      const ta = document.createElement('textarea');
      ta.value = d.senha; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta);
      toast('🔑 Senha copiada!', 'success');
    }
  } else {
    toast('Nenhuma senha salva', 'warning');
  }
}

function toggleSenha() {
  const el = document.getElementById('edit-senha');
  el.type = el.type === 'password' ? 'text' : 'password';
}

function toast(msg, type = 'success') {
  const id = 't-' + Date.now(), bg = type === 'success' ? 'bg-success' : 'bg-danger';
  document.getElementById('toast-container').insertAdjacentHTML('beforeend',
    `<div id="${id}" class="toast align-items-center text-white ${bg} border-0 show mb-2"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="document.getElementById('${id}').remove()"></button></div></div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── Modal Grupos ────────────────────────────────────────
function abrirModalGrupo() {
  listarGrupos();
  modalGrupos.show();
}

async function listarGrupos() {
  const r = await fetch('anydesk_central.php?action=list_grupos');
  const d = await r.json();
  const grupos = d.dados || [];
  let h = '';
  grupos.forEach(g => {
    h += `<div class="card mb-2" style="border-left:4px solid ${g.cor_bg}">
      <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
        <div>
          <i class="${g.icone} me-2" style="color:${g.cor_bg}"></i>
          <strong>${esc(g.nome)}</strong>
          <span class="ms-2 badge" style="background:${g.cor_badge};color:${g.cor_text}">#${g.id}</span>
        </div>
        <div>
          <button class="btn btn-sm btn-outline-primary me-1" onclick="editarGrupo(${g.id},'${escAttr(g.nome)}','${escAttr(g.icone)}','${escAttr(g.cor_bg)}','${escAttr(g.cor_fundo)}','${escAttr(g.cor_badge)}','${escAttr(g.cor_text)}')"><i class="bi bi-pencil-fill"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="excluirGrupo(${g.id},'${escAttr(g.nome)}')"><i class="bi bi-trash-fill"></i></button>
        </div>
      </div>
    </div>`;
  });
  if (!grupos.length) h = '<p class="text-muted">Nenhum grupo cadastrado.</p>';
  document.getElementById('grupos-container').innerHTML = h;
}

async function salvarGrupo() {
  const nome = prompt('Nome do grupo:');
  if (!nome) return;
  const icone = prompt('Ícone Bootstrap (ex: bi-server, bi-laptop, bi-cpu):', 'bi-laptop');
  const cor_bg = prompt('Cor do background (ex: #c2410c):', '#c2410c');
  const cor_fundo = prompt('Cor de fundo do card (ex: #fff7ed):', '#fff7ed');
  const r = await fetch('anydesk_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: 0, nome, icone, cor_bg, cor_fundo, cor_badge: cor_fundo, cor_text: cor_bg})
  });
  const d = await r.json();
  if (d.ok) { toast('✅ Grupo adicionado!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function editarGrupo(id, nome, icone, cor_bg, cor_fundo, cor_badge, cor_text) {
  const novoNome = prompt('Nome do grupo:', nome);
  if (!novoNome) return;
  const novoIcone = prompt('Ícone Bootstrap:', icone);
  const novaCorBg = prompt('Cor do background:', cor_bg);
  const novaCorFundo = prompt('Cor de fundo do card:', cor_fundo);
  const r = await fetch('anydesk_central.php?action=save_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id, nome: novoNome, icone: novoIcone, cor_bg: novaCorBg, cor_fundo: novaCorFundo, cor_badge: novaCorFundo, cor_text: novaCorBg})
  });
  const d = await r.json();
  if (d.ok) { toast('✅ Grupo atualizado!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}

async function excluirGrupo(id, nome) {
  if (!confirm(`Excluir grupo "${nome}"? Máquinas serão movidas para o primeiro grupo.`)) return;
  const r = await fetch('anydesk_central.php?action=delete_grupo', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.ok) { toast('🗑️ Grupo excluído!', 'success'); listarGrupos(); carregarTudo(); }
  else toast(d.msg || 'Erro', 'danger');
}
</script>
</body>
</html>
