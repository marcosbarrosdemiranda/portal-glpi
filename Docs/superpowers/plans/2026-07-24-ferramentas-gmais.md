# Ferramentas Gmais Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Ferramentas Gmais" card to the dashboard's Recursos section that opens an admin-manageable list of the group's internal web subsystems (starting with Checklist Gmais and Ponto Gmais).

**Architecture:** New page `ferramentas_gmais.php` mirrors the existing `acessos.php` pattern (self-managed MySQL table + CRUD modal, no code changes needed to add future subsystems). The dashboard gets a new card gated by the project's existing `pode_ver()` permission helper, and the new permission key is registered in `perfis.php`'s card catalog so admins can grant/revoke it per profile.

**Tech Stack:** PHP 8.2, PDO/MySQL (MariaDB 10.4), Bootstrap 5.3 + Bootstrap Icons (CDN, no build step), vanilla JS (fetch). Runs in Docker (`glpi-web` container) on `192.168.1.198:7412`, source synced via `scp` to `D:/docker/glpi-portal/glpi2/portal-glpi/`.

## Global Constraints

- No automated test framework exists in this codebase (plain PHP app, manual QA in browser). Verification = `php -l` syntax check inside the `glpi-web` container + an HTTP smoke check, not unit tests.
- Every new/changed file must be synced to the production server via `scp` to `glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/<relative path>"` immediately after it's written (per project convention — the user tests live on the server).
- Comments in code: Portuguese. Commit messages: English, Conventional Commits (`feat:`, `fix:`, `docs:`).
- Follow the exact visual/CSS conventions already in `dashboard.php` and `acessos.php` (`.dash-card`, `.acesso-card`, Bootstrap Icons, color pattern `border-top-color` + light `card-icon` background).
- Table/column names, defaults, and colors must match `docs/superpowers/specs/2026-07-24-ferramentas-gmais-design.md` exactly (table `portal_ferramentas_gmais`, color `#ad1457` / bg `#fce4ec`, icon `bi-diagram-3-fill` for the dashboard card).

---

## File Structure

- **Create `ferramentas_gmais.php`** (repo root, alongside `acessos.php`) — self-contained page: table bootstrap, AJAX CRUD endpoints (`?action=save|add|delete`), and the HTML/CSS/JS grid UI. One file, same as every other top-level page in this project (`acessos.php`, `cofre.php`, etc.) — no framework, no splitting.
- **Modify `dashboard.php`** — add one CSS rule pair and one card block inside the existing Recursos section; extend the section-label visibility condition.
- **Modify `perfis.php`** — add one entry to the `$SECOES['Recursos']` catalog array.

---

### Task 1: `ferramentas_gmais.php` page + `portal_ferramentas_gmais` table

**Files:**
- Create: `ferramentas_gmais.php`

**Interfaces:**
- Consumes: `auth_guard.php` (session guard, sets `$_SESSION['autenticado']`, `$_SESSION['perfil']`), `agenda/db.php` (provides `$pdo`, a connected `PDO` instance).
- Produces: the URL `ferramentas_gmais.php` (used by Task 2's dashboard card) and the table `portal_ferramentas_gmais` (columns: `id, nome, descricao, url, icone, cor_bg, cor_text, ordem, ativo`).

- [ ] **Step 1: Write `ferramentas_gmais.php`**

Create the file with this exact content:

```php
<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico']);

// ── Cria tabela na primeira vez ─────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_ferramentas_gmais (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(100)  NOT NULL,
        descricao VARCHAR(255)  DEFAULT '',
        url       VARCHAR(500)  DEFAULT '',
        icone     VARCHAR(60)   DEFAULT 'bi-link',
        cor_bg    VARCHAR(20)   DEFAULT '#f3e5f5',
        cor_text  VARCHAR(20)   DEFAULT '#ad1457',
        ordem     INT           DEFAULT 0,
        ativo     TINYINT(1)    DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($pdo->query("SELECT COUNT(*) FROM portal_ferramentas_gmais")->fetchColumn() == 0) {
    $ins = $pdo->prepare("INSERT INTO portal_ferramentas_gmais
        (nome,descricao,url,icone,cor_bg,cor_text,ordem) VALUES (?,?,?,?,?,?,?)");
    $defaults = [
        ['Checklist Gmais','Checklist digital do grupo','https://checklist-gmais.grupogmais.com:7414/login','bi-list-check','#f3e5f5','#ad1457',1],
        ['Ponto Gmais','Controle de ponto eletrônico do grupo','https://ponto.grupogmais.com:7413/','bi-clock-history','#f3e5f5','#ad1457',2],
    ];
    foreach ($defaults as $d) $ins->execute($d);
}

// ── API AJAX ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (!$is_admin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }

    // Salvar edição de item existente
    if ($action === 'save') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
        $st = $pdo->prepare("UPDATE portal_ferramentas_gmais SET url=?, nome=?, descricao=? WHERE id=?");
        $st->execute([trim($body['url']??''), trim($body['nome']??''), trim($body['descricao']??''), $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Adicionar novo subsistema
    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $nome = trim($body['nome'] ?? '');
        if (!$nome) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
        $st = $pdo->prepare("INSERT INTO portal_ferramentas_gmais (nome,descricao,url,icone,cor_bg,cor_text,ordem)
                              VALUES (?,?,?,?,?,?,
                              (SELECT COALESCE(MAX(ordem),0)+1 FROM portal_ferramentas_gmais pfg2))");
        $st->execute([
            $nome, trim($body['descricao']??''),
            trim($body['url']??''),
            $body['icone']??'bi-link', $body['cor_bg']??'#f3e5f5', $body['cor_text']??'#ad1457',
        ]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        exit;
    }

    // Excluir item (não exclui os 2 defaults — id 1 e 2)
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id > 2) {
            $pdo->prepare("DELETE FROM portal_ferramentas_gmais WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
    exit;
}

// ── Busca itens ativos ───────────────────────────────────────
$itens = $pdo->query("SELECT * FROM portal_ferramentas_gmais WHERE ativo=1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Ferramentas Gmais</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    * { box-sizing:border-box; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:-2.5rem auto 3rem; padding:0 1rem; }

    .grupo-section { margin-bottom:2rem; }
    .grupo-header {
      border-radius:12px 12px 0 0; padding:.75rem 1.25rem;
      display:flex; align-items:center; justify-content:space-between; gap:.75rem;
      color:white; font-weight:700; font-size:.9rem;
      background:linear-gradient(135deg,#880e4f,#ad1457);
    }
    .grupo-body {
      background:white; border-radius:0 0 12px 12px;
      border:1px solid #e5e7eb; border-top:none;
      box-shadow:0 2px 8px rgba(0,0,0,.06);
      padding:1.25rem;
    }
    .grupo-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));
      gap:1rem;
    }

    .acesso-card {
      border-radius:12px; border:2px solid #e5e7eb;
      padding:1.25rem 1rem; text-align:center;
      transition:all .18s; cursor:pointer; background:white;
      display:flex; flex-direction:column; align-items:center; gap:.5rem;
      position:relative;
    }
    .acesso-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.12); }
    .acesso-card.sem-url { opacity:.65; }
    .acesso-card.sem-url:hover { transform:none; box-shadow:none; }

    .ac-icon {
      width:64px; height:64px; border-radius:16px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.8rem; margin-bottom:.25rem; flex-shrink:0;
    }
    .ac-nome { font-weight:700; font-size:.9rem; color:#111; line-height:1.3; }
    .ac-desc { font-size:.73rem; color:#9ca3af; min-height:28px; line-height:1.4; }

    .btn-acessar {
      margin-top:auto; width:100%; border-radius:8px; border:none;
      padding:.4rem .75rem; font-size:.8rem; font-weight:600;
      cursor:pointer; transition:all .15s;
    }
    .btn-acessar:disabled { opacity:.45; cursor:not-allowed; }

    .badge-config {
      position:absolute; top:.5rem; right:.5rem;
      background:#fef3c7; color:#92400e; border-radius:6px;
      font-size:.6rem; font-weight:700; padding:.15rem .4rem;
    }

    .btn-cfg {
      position:absolute; top:.5rem; left:.5rem;
      background:rgba(0,0,0,.06); border:none; border-radius:6px;
      padding:.2rem .4rem; font-size:.75rem; color:#6b7280; cursor:pointer;
      opacity:0; transition:opacity .15s;
    }
    .acesso-card:hover .btn-cfg { opacity:1; }
    .btn-cfg:hover { background:rgba(0,0,0,.12); color:#111; }

    .card-add {
      border:2px dashed #d1d5db; background:transparent;
      color:#9ca3af; cursor:pointer; min-height:160px;
      justify-content:center;
    }
    .card-add:hover { border-color:#6b7280; color:#374151; background:#f9fafb; }

    #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-diagram-3-fill"></i> Ferramentas Gmais</div>
  <div style="display:flex;gap:.5rem">
    <?php if ($is_admin): ?>
    <button onclick="abrirModalAdicionar()" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer">
      <i class="bi bi-plus-lg me-1"></i>Adicionar
    </button>
    <?php endif; ?>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-diagram-3-fill me-2"></i>Ferramentas Gmais
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Sistemas internos do grupo em um só lugar</p>
</div>

<div class="wrap">
<div class="grupo-section">
  <div class="grupo-header">
    <span><i class="bi bi-diagram-3-fill me-2"></i>Subsistemas do grupo</span>
    <span style="font-size:.72rem;opacity:.7;font-weight:400"><?= count($itens) ?> ferramenta(s)</span>
  </div>
  <div class="grupo-body">
    <div class="grupo-grid">

    <?php foreach ($itens as $item):
      $sem_url = empty($item['url']);
      $card_cls = $sem_url ? 'acesso-card sem-url' : 'acesso-card';
    ?>
      <div class="<?= $card_cls ?>" id="card-<?= $item['id'] ?>">
        <?php if ($is_admin): ?>
        <button class="btn-cfg" onclick="editarItem(<?= $item['id'] ?>,<?= htmlspecialchars(json_encode($item)) ?>)" title="Configurar">
          <i class="bi bi-gear-fill"></i>
        </button>
        <?php endif; ?>
        <?php if ($sem_url): ?>
        <span class="badge-config">Configurar</span>
        <?php endif; ?>

        <div class="ac-icon" style="background:<?= $item['cor_bg'] ?>;color:<?= $item['cor_text'] ?>">
          <i class="bi <?= $item['icone'] ?>"></i>
        </div>
        <div class="ac-nome"><?= htmlspecialchars($item['nome']) ?></div>
        <div class="ac-desc"><?= htmlspecialchars($item['descricao']) ?></div>

        <button class="btn-acessar"
                style="background:<?= $item['cor_text'] ?>;color:white"
                <?= $sem_url ? 'disabled title="Configure a URL primeiro"' : "onclick=\"abrirUrl('".htmlspecialchars($item['url'],ENT_QUOTES)."')\"" ?>>
          <i class="bi bi-box-arrow-up-right me-1"></i>Acessar
        </button>
      </div>
    <?php endforeach; ?>

    <?php if ($is_admin): ?>
      <div class="acesso-card card-add" onclick="abrirModalAdicionar()">
        <i class="bi bi-plus-circle" style="font-size:1.8rem"></i>
        <div class="ac-nome">Adicionar</div>
      </div>
    <?php endif; ?>

    </div>
  </div>
</div>
</div><!-- /wrap -->

<!-- Modal: configurar item existente -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#880e4f,#ad1457);color:white">
        <h5 class="modal-title fw-bold" id="modal-edit-titulo">
          <i class="bi bi-gear-fill me-2"></i>Configurar
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome</label>
          <input type="text" class="form-control" id="edit-nome"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">URL <span class="text-muted small">(ex: https://sistema.grupogmais.com)</span></label>
          <input type="text" class="form-control font-monospace" id="edit-url" placeholder="https://"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Descrição</label>
          <input type="text" class="form-control" id="edit-descricao"/>
        </div>
      </div>
      <div class="modal-footer">
        <?php if (false): // exclusão fica disponível só para itens id > 2 via JS ?>
        <?php endif; ?>
        <button class="btn btn-outline-danger me-auto" id="btn-excluir-item" onclick="excluirItem()">
          <i class="bi bi-trash me-1"></i>Excluir
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="salvarConfig()" style="background:#ad1457;border-color:#ad1457">
          <i class="bi bi-check-lg me-1"></i>Salvar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: adicionar novo subsistema -->
<div class="modal fade" id="modalAdicionar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#880e4f,#ad1457);color:white">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-plus-circle-fill me-2"></i>Novo Subsistema
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="add-nome" placeholder="Ex: Frota Gmais"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">URL</label>
            <input type="text" class="form-control font-monospace" id="add-url" placeholder="https://"/>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Descrição</label>
            <input type="text" class="form-control" id="add-descricao"/>
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Ícone Bootstrap</label>
            <input type="text" class="form-control" id="add-icone" value="bi-link" placeholder="bi-link"/>
            <div class="form-text">
              <a href="https://icons.getbootstrap.com/" target="_blank">Ver ícones disponíveis</a>
            </div>
          </div>
          <div class="col-3">
            <label class="form-label fw-semibold">Cor fundo</label>
            <input type="color" class="form-control form-control-color" id="add-cor-bg" value="#f3e5f5"/>
          </div>
          <div class="col-3">
            <label class="form-label fw-semibold">Cor ícone</label>
            <input type="color" class="form-control form-control-color" id="add-cor-text" value="#ad1457"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" onclick="adicionarItem()" style="background:#ad1457;border-color:#ad1457">
          <i class="bi bi-plus-lg me-1"></i>Adicionar
        </button>
      </div>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalEditar, modalAdicionar;
let editandoId = 0;

document.addEventListener('DOMContentLoaded', () => {
  modalEditar    = new bootstrap.Modal(document.getElementById('modalEditar'));
  modalAdicionar = new bootstrap.Modal(document.getElementById('modalAdicionar'));
});

function abrirUrl(url) {
  if (!url) return;
  if (!url.startsWith('http://') && !url.startsWith('https://')) {
    location.href = url;
    return;
  }
  window.open(url, '_blank', 'noopener');
}

// ── Configurar item existente ──────────────────────────────────
function editarItem(id, item) {
  editandoId = id;
  document.getElementById('edit-id').value        = id;
  document.getElementById('edit-nome').value       = item.nome;
  document.getElementById('edit-url').value        = item.url || '';
  document.getElementById('edit-descricao').value  = item.descricao || '';
  document.getElementById('modal-edit-titulo').innerHTML =
    `<i class="bi bi-gear-fill me-2"></i>${item.nome}`;
  document.getElementById('btn-excluir-item').style.display = (id > 2) ? 'inline-block' : 'none';
  modalEditar.show();
}

async function salvarConfig() {
  const id   = document.getElementById('edit-id').value;
  const url  = document.getElementById('edit-url').value.trim();
  const nome = document.getElementById('edit-nome').value.trim();
  const desc = document.getElementById('edit-descricao').value.trim();
  const r = await fetch('ferramentas_gmais.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, url, nome, descricao: desc}),
  });
  const d = await r.json();
  if (d.ok) { modalEditar.hide(); toast('✅ Configuração salva!'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro ao salvar');
}

async function excluirItem() {
  if (!editandoId || !confirm('Excluir este subsistema?')) return;
  const r = await fetch(`ferramentas_gmais.php?action=delete&id=${editandoId}`);
  const d = await r.json();
  if (d.ok) { modalEditar.hide(); toast('🗑️ Removido.'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro ao excluir');
}

// ── Adicionar novo subsistema ──────────────────────────────────
function abrirModalAdicionar() { modalAdicionar.show(); }

async function adicionarItem() {
  const nome = document.getElementById('add-nome').value.trim();
  if (!nome) { alert('Informe o nome.'); return; }
  const r = await fetch('ferramentas_gmais.php?action=add', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      nome,
      descricao: document.getElementById('add-descricao').value.trim(),
      url:       document.getElementById('add-url').value.trim(),
      icone:     document.getElementById('add-icone').value.trim() || 'bi-link',
      cor_bg:    document.getElementById('add-cor-bg').value,
      cor_text:  document.getElementById('add-cor-text').value,
    }),
  });
  const d = await r.json();
  if (d.ok) { modalAdicionar.hide(); toast('✅ Subsistema adicionado!'); setTimeout(()=>location.reload(),800); }
  else alert(d.msg || 'Erro');
}

// ── Toast ──────────────────────────────────────────────────────
function toast(msg, type='success') {
  const id = 'toast-' + Date.now();
  const bg = type === 'success' ? 'bg-success' : 'bg-danger';
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
```

- [ ] **Step 2: Remove the dead `<?php if (false): ?>` block**

The template above has a leftover no-op `<?php if (false): ?><?php endif; ?>` pair in the edit modal footer (an artifact of drafting — it does nothing but is dead code). Delete these two lines before saving:

```php
        <?php if (false): // exclusão fica disponível só para itens id > 2 via JS ?>
        <?php endif; ?>
```

The modal footer should go straight from `</div>` (closing `.mb-2`) to the `<button class="btn btn-outline-danger me-auto" ...>` button.

- [ ] **Step 3: Deploy to the server**

```bash
scp "C:/claude code/portal-glpi/ferramentas_gmais.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/ferramentas_gmais.php"
```

- [ ] **Step 4: Lint-check on the server (no local PHP CLI available)**

```bash
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/ferramentas_gmais.php"
```
Expected: `No syntax errors detected in /var/www/html/glpi2/portal-glpi/ferramentas_gmais.php`

- [ ] **Step 5: HTTP smoke check**

```bash
ssh glpi-server "curl -s -o NUL -w \"%{http_code}\" http://localhost:7412/glpi2/portal-glpi/ferramentas_gmais.php"
```
Expected: `302` (redirect to `auth.php` — confirms the file parses and `auth_guard.php` ran without a fatal error; a PHP fatal error would produce a `500` here instead).

If Step 4 or Step 5 fails, fix the file, redo Step 3, and re-run Steps 4–5 before continuing.

- [ ] **Step 6: Commit**

```bash
git add ferramentas_gmais.php
git commit -m "$(cat <<'EOF'
feat: adiciona pagina Ferramentas Gmais com CRUD de subsistemas do grupo

Nova pagina ferramentas_gmais.php, mesmo padrao de acessos.php: tabela
portal_ferramentas_gmais criada automaticamente com os 2 subsistemas
atuais (Checklist Gmais, Ponto Gmais) e modal de admin para
adicionar/editar/remover novos subsistemas sem editar codigo.
EOF
)"
```

---

### Task 2: Dashboard card + permission catalog entry

**Files:**
- Modify: `dashboard.php:184-186` (CSS), `dashboard.php:342` (section-label condition), `dashboard.php:354-360` (card markup)
- Modify: `perfis.php:38` (`$SECOES['Recursos']` catalog)

**Interfaces:**
- Consumes: `ferramentas_gmais.php` (from Task 1 — the link target), the existing `pode_ver(string $key, ?array $cards): bool` helper already defined in `dashboard.php:47-50`, and the existing `$SECOES` catalog array structure in `perfis.php:25-61`.
- Produces: nothing consumed by later tasks — this is the last task.

- [ ] **Step 1: Add the CSS rule pair in `dashboard.php`**

Find this block (around line 184):
```css
    .card-cofre       { border-top-color: #37474f; }
    .card-cofre       .card-icon { background: #eceff1; color: #37474f; }
```
Insert immediately after it:
```css

    .card-ferramentas-gmais { border-top-color: #ad1457; }
    .card-ferramentas-gmais .card-icon { background: #fce4ec; color: #ad1457; }
```

- [ ] **Step 2: Extend the Recursos section-label condition**

Find (around line 342):
```php
  <?php if ((pode_ver('conhecimento',$perfil_cards) && !$is_self_glpi) || pode_ver('cofre',$perfil_cards)): ?>
  <div class="section-label"><i class="bi bi-collection-fill me-2"></i>Recursos</div>
  <?php endif; ?>
```
Replace the `if` line with:
```php
  <?php if ((pode_ver('conhecimento',$perfil_cards) && !$is_self_glpi) || pode_ver('cofre',$perfil_cards) || pode_ver('ferramentas_gmais',$perfil_cards)): ?>
```

- [ ] **Step 3: Add the card markup after "Cofre TI"**

Find (around line 354-360):
```php
  <?php if (pode_ver('cofre', $perfil_cards)): ?>
  <a href="cofre.php" class="dash-card card-cofre">
    <div class="card-icon"><i class="bi bi-safe2-fill"></i></div>
    <h5>Cofre TI</h5>
    <p>Senhas, comandos e documentação interna da equipe — seguro e com busca rápida.</p>
  </a>
  <?php endif; ?>
```
Insert immediately after the closing `<?php endif; ?>`:
```php

  <?php if (pode_ver('ferramentas_gmais', $perfil_cards)): ?>
  <a href="ferramentas_gmais.php" class="dash-card card-ferramentas-gmais">
    <div class="card-icon"><i class="bi bi-diagram-3-fill"></i></div>
    <h5>Ferramentas Gmais</h5>
    <p>Acesso rápido aos sistemas internos do grupo — Checklist, Ponto e outros.</p>
  </a>
  <?php endif; ?>
```

- [ ] **Step 4: Register the permission key in `perfis.php`**

Find (line 38):
```php
        'cofre'         => ['label' => 'Cofre TI',               'icon' => 'bi-safe2-fill',          'css' => 'card-cofre'],
```
Insert immediately after it (still inside the `'Recursos' => [ ... ]` array):
```php
        'ferramentas_gmais' => ['label' => 'Ferramentas Gmais',  'icon' => 'bi-diagram-3-fill',      'css' => 'card-ferramentas-gmais'],
```

- [ ] **Step 5: Deploy both files to the server**

```bash
scp "C:/claude code/portal-glpi/dashboard.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/dashboard.php"
scp "C:/claude code/portal-glpi/perfis.php" glpi-server:"D:/docker/glpi-portal/glpi2/portal-glpi/perfis.php"
```

- [ ] **Step 6: Lint-check both files on the server**

```bash
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/dashboard.php"
ssh glpi-server "docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/perfis.php"
```
Expected for each: `No syntax errors detected in ...`

- [ ] **Step 7: HTTP smoke check**

```bash
ssh glpi-server "curl -s -o NUL -w \"%{http_code}\" http://localhost:7412/glpi2/portal-glpi/dashboard.php"
ssh glpi-server "curl -s -o NUL -w \"%{http_code}\" http://localhost:7412/glpi2/portal-glpi/perfis.php"
```
Expected for each: `302`

If any lint or smoke check fails, fix the file, redo Step 5, and re-run Steps 6-7 before continuing.

- [ ] **Step 8: Commit**

```bash
git add dashboard.php perfis.php
git commit -m "$(cat <<'EOF'
feat: liga card Ferramentas Gmais no dashboard e catalogo de permissoes

Card adicionado na secao Recursos do dashboard (dashboard.php),
condicionado a pode_ver('ferramentas_gmais'). Chave registrada no
catalogo de perfis.php para o admin liberar por perfil em /perfis.php.
EOF
)"
```

- [ ] **Step 9: Manual QA (requires portal login — hand off to user)**

The remaining verification needs an authenticated session in the portal, which is outside what `php -l`/`curl` can confirm. Ask the user (or do it yourself if you're given credentials / browser access) to walk through, using the checklist already written in `docs/superpowers/specs/2026-07-24-ferramentas-gmais-design.md` under "Teste manual":

1. As admin: open `/perfis.php`, confirm "Ferramentas Gmais" appears under Recursos and can be checked for a profile.
2. As a user in that profile: dashboard shows the "Ferramentas Gmais" card in Recursos.
3. Click the card → `ferramentas_gmais.php` loads with the two default cards (Checklist Gmais, Ponto Gmais); "Acessar" opens each URL in a new tab.
4. As admin: add a third test subsystem via the modal, confirm it appears without a code change; edit an existing item's URL; delete the test item (defaults — id 1/2 — should have no delete button).
5. As a user without the `ferramentas_gmais` permission: confirm the card does NOT appear on the dashboard.

Report back any issue found so it can be fixed before considering this done.

---

## Self-Review Notes

- **Spec coverage:** table schema ✅ (Task 1), page + CRUD modal ✅ (Task 1), dashboard card + CSS ✅ (Task 2 Steps 1-3), permission catalog entry ✅ (Task 2 Step 4), manual test checklist from spec ✅ (Task 2 Step 9). "Fora de escopo" items (SSO, storing credentials in Cofre) are intentionally not implemented.
- **Placeholder scan:** no TBD/TODO; the one intentionally-dead `if (false)` snippet from drafting is explicitly called out and removed in Task 1 Step 2, not left in place.
- **Type/naming consistency:** table name `portal_ferramentas_gmais` and columns match the spec exactly; JS functions (`editarItem`, `adicionarItem`, `excluirItem`, `salvarConfig`, `abrirUrl`, `toast`) are consistent between the modal `onclick` handlers and their definitions in the same file (Task 1) — no cross-task JS dependency exists. PHP permission key `ferramentas_gmais` is spelled identically in `dashboard.php` (Task 2 Steps 2-3) and `perfis.php` (Task 2 Step 4).
