<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';    // PDO $pdo
require_once __DIR__ . '/agenda/config.php';

// ── Chave de criptografia (AES-256) ───────────────────────────
// Definida no config.php ou fallback local
if (!defined('VAULT_KEY')) {
    define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
}

function vault_encrypt(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', VAULT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function vault_decrypt(string $data): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

// ── Cria tabela se não existir ─────────────────────────────────
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

// ── API AJAX ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

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
        $st = $pdo->prepare("SELECT conteudo FROM portal_vault WHERE id = ?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
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
        } else {
            $st = $pdo->prepare("INSERT INTO portal_vault (titulo,categoria,usuario,conteudo,url,tags,notas) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$titulo,$categoria,$usuario,$conteudo_enc,$url,$tags,$notas]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }

    // Excluir
    if ($action === 'delete' && isset($_GET['id'])) {
        $st = $pdo->prepare("DELETE FROM portal_vault WHERE id = ?");
        $st->execute([(int)$_GET['id']]);
        echo json_encode(['ok'=>true]);
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
    .cat-outro    { background:#f5f5f5; color:#616161; }

    .vault-titulo { font-weight:700; font-size:.95rem; margin:.4rem 0 .2rem; color:#111;
                    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .vault-usuario { font-size:.78rem; color:#6b7280; }

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
      <button class="cat-btn" data-cat="outro" onclick="setCategoria(this)">
        <i class="bi bi-three-dots"></i> Outros
      </button>
    </div>

    <div style="flex:1"></div>
    <span id="cnt-itens" style="font-size:.78rem;color:#9ca3af"></span>
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

          <div class="col-12">
            <label class="form-label" id="label-conteudo">
              Senha / Conteúdo <span class="text-muted small">(criptografado)</span>
            </label>
            <div class="pass-wrap">
              <textarea class="form-control" id="item-conteudo" rows="3"
                        placeholder="Senha, comando ou conteúdo a guardar..."
                        style="resize:vertical;font-family:monospace"></textarea>
            </div>
          </div>

          <div class="col-md-8">
            <label class="form-label">Tags <span class="text-muted small">(separadas por vírgula)</span></label>
            <input type="text" class="form-control" id="item-tags"
                   placeholder="servidor, produção, vpn..."/>
          </div>

          <div class="col-12">
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

<!-- Toast container -->
<div id="toast-container" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modal;
let categoriaAtiva = '';
let revelados = {}; // id → conteudo decriptado em memória (limpo ao fechar aba)

document.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('modalCofre'));
  carregar();
  document.getElementById('f-busca').addEventListener('input', carregar);
});

// ── Categorias ─────────────────────────────────────────────────
const CAT_INFO = {
  senha:   { icon:'bi-key-fill',        label:'Senha',        cls:'cat-senha'   },
  comando: { icon:'bi-terminal-fill',   label:'Comando',      cls:'cat-comando' },
  doc:     { icon:'bi-file-text-fill',  label:'Documentação', cls:'cat-doc'     },
  link:    { icon:'bi-link-45deg',      label:'Link',         cls:'cat-link'    },
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
    .then(r => r.json())
    .then(items => renderizar(items))
    .catch(() => toast('Erro ao carregar itens', 'danger'));
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
        <button class="btn-icon" onclick="editarItem(${item.id})" title="Editar">
          <i class="bi bi-pencil-fill"></i>
        </button>
      </div>
    </div>
    <div class="vault-titulo" title="${esc(item.titulo)}">${esc(item.titulo)}</div>
    ${item.usuario ? `<div class="vault-usuario"><i class="bi bi-person me-1"></i>${esc(item.usuario)}</div>` : ''}
    ${item.url ? `<div class="vault-usuario"><i class="bi bi-hdd-network me-1"></i>${esc(item.url)}</div>` : ''}
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
      const r = await fetch(`cofre.php?action=reveal&id=${id}`);
      const d = await r.json();
      texto = d.conteudo || '';
    } catch { toast('Erro ao copiar', 'danger'); return; }
  }
  if (!texto) { toast('Nada para copiar', 'warning'); return; }
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
function ajustarCampos() {
  const cat = document.getElementById('item-categoria').value;
  const mostraUsuario = ['senha', 'link', 'outro'].includes(cat);
  document.getElementById('campo-usuario').style.display = mostraUsuario ? '' : 'none';
  const lblMap = {
    senha:   'Senha (criptografada)',
    comando: 'Comando / Script',
    doc:     'Conteúdo / Documentação',
    link:    'URL / Endereço',
    outro:   'Conteúdo',
  };
  document.getElementById('label-conteudo').innerHTML =
    `${lblMap[cat]||'Conteúdo'} <span class="text-muted small">(criptografado)</span>`;
  document.getElementById('label-url').textContent =
    cat === 'link' ? 'URL' : 'URL / Servidor / IP';
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

  // Carrega conteúdo descriptografado
  const rc = await fetch(`cofre.php?action=reveal&id=${id}`);
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
