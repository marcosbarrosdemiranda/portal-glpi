<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/unifi_client.php';

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function formatarUptime(int $segundos): string {
    if ($segundos <= 0) return '—';
    $dias    = intdiv($segundos, 86400);
    $horas   = intdiv($segundos % 86400, 3600);
    if ($dias > 0)  return "{$dias}d {$horas}h";
    $minutos = intdiv($segundos % 3600, 60);
    if ($horas > 0) return "{$horas}h {$minutos}min";
    return "{$minutos}min";
}

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'super-admin', 'tecnico']);

// ── Tabela de controladoras UniFi ────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_unifi_controladoras (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        apelido            VARCHAR(60)   NOT NULL,
        url                VARCHAR(255)  NOT NULL,
        usuario            VARCHAR(100)  NOT NULL,
        senha_enc          TEXT          NOT NULL,
        site               VARCHAR(60)   NOT NULL DEFAULT 'default',
        ativo              TINYINT(1)    DEFAULT 1,
        ultimo_teste_ok    TINYINT(1)    DEFAULT NULL,
        ultima_verificacao DATETIME      DEFAULT NULL,
        criado_em          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX: CRUD de controladoras (somente admin) ─────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        echo json_encode(['ok' => false, 'msg' => 'Requisição inválida']); exit;
    }
    if (!$is_admin) { echo json_encode(['ok' => false, 'msg' => 'Sem permissão']); exit; }

    if ($action === 'controladora_add' || $action === 'controladora_save') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $apelido = trim($body['apelido'] ?? '');
        $url     = trim($body['url'] ?? '');
        $usuario = trim($body['usuario'] ?? '');
        // Senha não é trimada de propósito — pode ter espaços à margem que fazem parte dela (mesmo padrão de pfsense_proxy.php)
        $senha   = (string)($body['senha'] ?? '');
        $site    = trim($body['site'] ?? '') ?: 'default';
        $id      = (int)($body['id'] ?? 0);

        if ($action === 'controladora_save' && !$id) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit;
        }

        if (!$apelido || !$url || !$usuario) {
            echo json_encode(['ok' => false, 'msg' => 'Apelido, URL e usuário são obrigatórios']); exit;
        }
        if (!preg_match('~^https?://~i', $url)) {
            echo json_encode(['ok' => false, 'msg' => 'URL deve começar com http:// ou https://']); exit;
        }

        // Edição sem nova senha = mantém a senha atual, não re-testa —
        // mas só quando URL/usuário não mudaram. Se mudaram, a senha salva seria
        // enviada em texto puro pro host novo sem o usuário nunca ter digitado ela de novo.
        if ($action === 'controladora_save' && $senha === '') {
            $stAtual = $pdo->prepare("SELECT url, usuario FROM portal_unifi_controladoras WHERE id=?");
            $stAtual->execute([$id]);
            $atual = $stAtual->fetch(PDO::FETCH_ASSOC);
            $urlOuUsuarioMudou = $atual && ($atual['url'] !== $url || $atual['usuario'] !== $usuario);

            if (!$urlOuUsuarioMudou) {
                $st = $pdo->prepare("UPDATE portal_unifi_controladoras SET apelido=?, url=?, usuario=?, site=? WHERE id=?");
                $st->execute([$apelido, $url, $usuario, $site, $id]);
                echo json_encode(['ok' => true]); exit;
            }
            echo json_encode(['ok' => false, 'msg' => 'Informe a senha novamente ao alterar URL ou usuário']); exit;
        }

        if ($senha === '') { echo json_encode(['ok' => false, 'msg' => 'Senha é obrigatória']); exit; }
        $teste = unifi_testar_login($url, $usuario, $senha);
        if (!$teste['ok']) { echo json_encode(['ok' => false, 'msg' => 'Login falhou: ' . $teste['msg']]); exit; }

        $senhaEnc = vault_encrypt($senha);
        if ($action === 'controladora_add') {
            $st = $pdo->prepare("INSERT INTO portal_unifi_controladoras (apelido,url,usuario,senha_enc,site,ultimo_teste_ok,ultima_verificacao) VALUES (?,?,?,?,?,1,NOW())");
            $st->execute([$apelido, $url, $usuario, $senhaEnc, $site]);
            echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            $st = $pdo->prepare("UPDATE portal_unifi_controladoras SET apelido=?, url=?, usuario=?, senha_enc=?, site=?, ultimo_teste_ok=1, ultima_verificacao=NOW() WHERE id=?");
            $st->execute([$apelido, $url, $usuario, $senhaEnc, $site, $id]);
            echo json_encode(['ok' => true]);
        }
        exit;
    }

    if ($action === 'controladora_testar') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM portal_unifi_controladoras WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Controladora não encontrada']); exit; }

        $teste = unifi_testar_login($row['url'], $row['usuario'], vault_decrypt($row['senha_enc']));
        $pdo->prepare("UPDATE portal_unifi_controladoras SET ultimo_teste_ok=?, ultima_verificacao=NOW() WHERE id=?")
            ->execute([$teste['ok'] ? 1 : 0, $id]);
        echo json_encode($teste);
        exit;
    }

    if ($action === 'controladora_delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM portal_unifi_controladoras WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
    exit;
}

// ── Controladoras ativas (painel + próxima etapa: listagem de APs) ──
$controladoras = $pdo->query("SELECT * FROM portal_unifi_controladoras WHERE ativo=1 ORDER BY apelido")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Redes</title>
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
      box-shadow:0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4rem; text-align:center; }

    .wrap { max-width:1100px; margin:2rem auto 3rem; padding:0 1rem; }

    /* ── Controladoras ─────────────────────────────────────────── */
    .ctrl-section { margin-bottom:1.5rem; }
    .ctrl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:.75rem; }
    .ctrl-card { background:#fff; border:2px solid #e5e7eb; border-radius:12px;
                 padding:1rem; position:relative; transition:all .15s; }
    .ctrl-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
    .ctrl-topo { display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem; }
    .ctrl-badge.ok   { color:#1e8e3e; }
    .ctrl-badge.erro { color:#d93025; }
    .ctrl-cfg { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; }
    .ctrl-cfg:hover { color:#1a237e; }
    .ctrl-apelido { font-weight:700; font-size:.88rem; }
    .ctrl-url { font-size:.72rem; color:#6b7280; word-break:break-all; }
    .ctrl-add { display:flex; flex-direction:column; align-items:center; justify-content:center;
                gap:.35rem; min-height:64px; border:2px dashed #d1d5db; color:#9ca3af;
                cursor:pointer; border-radius:12px; }
    .ctrl-add:hover { border-color:#1a237e; color:#1a237e; }

    .unifi-erro-ctrl { background:#fff3e0; color:#854d0e; border:1px solid #fde68a;
                        border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:.75rem; }

    .unifi-grupo-section { margin-bottom:1.5rem; }
    .unifi-grupo-header { border-radius:12px 12px 0 0; padding:.65rem 1.1rem;
                           display:flex; align-items:center; justify-content:space-between;
                           color:#fff; font-weight:700; font-size:.85rem; background:#2e7d32; }
    .unifi-grupo-count { font-size:.72rem; opacity:.85; font-weight:400; }
    .unifi-grupo-body { background:#fff; border:1px solid #e5e7eb; border-top:none;
                         border-radius:0 0 12px 12px; padding:1rem; }
    .unifi-ap-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.85rem; }
    .unifi-ap-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem;
                      background:#fff; display:flex; flex-direction:column; gap:.4rem; }
    .unifi-ap-topo { display:flex; align-items:center; gap:.5rem; }
    .unifi-ap-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .unifi-ap-dot.online  { background:#1e8e3e; }
    .unifi-ap-dot.offline { background:#d93025; }
    .unifi-ap-nome { font-weight:700; font-size:.88rem; }
    .unifi-ap-modelo { font-size:.75rem; color:#6b7280; }
    .unifi-ap-meta { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.4rem;
                      padding-top:.5rem; border-top:1px solid #f3f4f6; font-size:.72rem; color:#6b7280; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-wifi me-2"></i>Inventário — Redes</div>
  <a href="inventario.php"><i class="bi bi-grid me-1"></i>Inventário</a>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-wifi me-2"></i>Redes — Access Points UniFi
  </h1>
  <p style="opacity:.8;margin-top:.5rem">Status ao vivo das controladoras UniFi do grupo</p>
</div>

<div class="wrap">

<div class="ctrl-section">
  <h6 class="fw-bold mb-2" style="color:#374151">
    <i class="bi bi-hdd-network me-2"></i>Controladoras UniFi
  </h6>
  <div class="ctrl-grid">
    <?php foreach ($controladoras as $c): ?>
      <div class="ctrl-card">
        <div class="ctrl-topo">
          <span class="ctrl-badge <?= $c['ultimo_teste_ok'] ? 'ok' : 'erro' ?>">
            <i class="bi <?= $c['ultimo_teste_ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
          </span>
          <?php if ($is_admin): ?>
          <button type="button" class="ctrl-cfg" onclick='editarControladora(<?= json_encode(['id'=>$c['id'],'apelido'=>$c['apelido'],'url'=>$c['url'],'usuario'=>$c['usuario'],'site'=>$c['site']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)' title="Editar">
            <i class="bi bi-gear-fill"></i>
          </button>
          <?php endif; ?>
        </div>
        <div class="ctrl-apelido"><?= esc($c['apelido']) ?></div>
        <div class="ctrl-url"><?= esc($c['url']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($is_admin): ?>
      <div class="ctrl-card ctrl-add" onclick="abrirModalControladora()">
        <i class="bi bi-plus-circle" style="font-size:1.5rem"></i>
        <div class="ctrl-apelido">Adicionar</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════ ACCESS POINTS ═══════════════ -->
<?php
$errosControladoras = [];
$apsPorControladora  = [];

// Libera o lock de sessão antes do fetch lento — evita travar outras abas do mesmo usuário
session_write_close();

$stAtualizaTeste = $pdo->prepare("UPDATE portal_unifi_controladoras SET ultimo_teste_ok=?, ultima_verificacao=NOW() WHERE id=?");

foreach ($controladoras as $c) {
    $senha     = vault_decrypt($c['senha_enc']);
    $resultado = unifi_listar_aps($c['url'], $c['usuario'], $senha, $c['site']);
    if (isset($resultado['erro'])) {
        $errosControladoras[] = ['apelido' => $c['apelido'], 'msg' => $resultado['erro']];
        $stAtualizaTeste->execute([0, $c['id']]);
        continue;
    }
    $apsPorControladora[$c['id']] = $resultado;
    $stAtualizaTeste->execute([1, $c['id']]);
}
?>

<?php foreach ($errosControladoras as $err): ?>
  <div class="unifi-erro-ctrl">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong><?= esc($err['apelido']) ?>:</strong> não foi possível carregar — <?= esc($err['msg']) ?>
  </div>
<?php endforeach; ?>

<?php if (!$controladoras): ?>
  <div class="text-muted small mt-4">Cadastre uma controladora acima para ver os access points aqui.</div>
<?php else: ?>
  <?php foreach ($controladoras as $c): if (!isset($apsPorControladora[$c['id']])) continue; ?>
    <?php $aps = $apsPorControladora[$c['id']]; ?>
    <div class="unifi-grupo-section">
      <div class="unifi-grupo-header">
        <span><i class="bi bi-wifi me-2"></i><?= esc($c['apelido']) ?></span>
        <span class="unifi-grupo-count"><?= count($aps) ?> access point(s)</span>
      </div>
      <div class="unifi-grupo-body">
        <?php if ($aps): ?>
          <div class="unifi-ap-grid">
            <?php foreach ($aps as $ap): ?>
              <div class="unifi-ap-card">
                <div class="unifi-ap-topo">
                  <span class="unifi-ap-dot <?= $ap['status'] ?>"></span>
                  <span class="unifi-ap-nome"><?= esc($ap['nome']) ?></span>
                </div>
                <div class="unifi-ap-modelo"><?= esc($ap['modelo']) ?></div>
                <div class="unifi-ap-meta">
                  <span><i class="bi bi-people-fill me-1"></i><?= (int)$ap['clientes'] ?> clientes</span>
                  <span><i class="bi bi-clock-history me-1"></i><?= esc(formatarUptime($ap['uptime_seg'])) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted small mb-0">Nenhum access point encontrado nessa controladora.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</div><!-- /wrap -->

<!-- Modal: adicionar/editar controladora UniFi -->
<div class="modal fade" id="modalControladora" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalControladoraTitulo"><i class="bi bi-wifi me-2"></i>Nova Controladora</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ctrl-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Apelido</label>
          <input type="text" class="form-control" id="ctrl-apelido" placeholder="Ex: Loja 101"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">URL <span class="text-muted small">(ex: https://192.168.1.10:8443)</span></label>
          <input type="text" class="form-control font-monospace" id="ctrl-url" placeholder="https://192.168.x.x:8443"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário</label>
          <input type="text" class="form-control" id="ctrl-usuario" placeholder="admin"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha</label>
          <input type="password" class="form-control font-monospace" id="ctrl-senha" placeholder="••••••••" autocomplete="new-password"/>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Site <span class="text-muted small">(padrão: default)</span></label>
          <input type="text" class="form-control" id="ctrl-site" placeholder="default"/>
        </div>
        <div id="ctrl-erro" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="btn-excluir-ctrl" style="display:none" onclick="excluirControladora()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" id="btn-testar-ctrl" style="display:none" onclick="testarControladora()"><i class="bi bi-plug me-1"></i>Testar</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarControladora()" style="background:#1a237e;border-color:#1a237e"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalControladora;
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('modalControladora');
  if (el) modalControladora = new bootstrap.Modal(el);
});

function abrirModalControladora() {
  document.getElementById('ctrl-id').value = '';
  document.getElementById('ctrl-apelido').value = '';
  document.getElementById('ctrl-url').value = '';
  document.getElementById('ctrl-usuario').value = '';
  document.getElementById('ctrl-senha').value = '';
  document.getElementById('ctrl-senha').placeholder = '••••••••';
  document.getElementById('ctrl-site').value = '';
  document.getElementById('ctrl-erro').style.display = 'none';
  document.getElementById('modalControladoraTitulo').innerHTML = '<i class="bi bi-wifi me-2"></i>Nova Controladora';
  document.getElementById('btn-excluir-ctrl').style.display = 'none';
  document.getElementById('btn-testar-ctrl').style.display = 'none';
  modalControladora.show();
}

function editarControladora(c) {
  document.getElementById('ctrl-id').value = c.id;
  document.getElementById('ctrl-apelido').value = c.apelido;
  document.getElementById('ctrl-url').value = c.url;
  document.getElementById('ctrl-usuario').value = c.usuario;
  document.getElementById('ctrl-senha').value = '';
  document.getElementById('ctrl-senha').placeholder = 'Deixe em branco para manter a senha atual';
  document.getElementById('ctrl-site').value = c.site;
  document.getElementById('ctrl-erro').style.display = 'none';
  document.getElementById('modalControladoraTitulo').textContent = c.apelido;
  document.getElementById('btn-excluir-ctrl').style.display = 'inline-block';
  document.getElementById('btn-testar-ctrl').style.display = 'inline-block';
  modalControladora.show();
}

async function testarControladora() {
  const id     = document.getElementById('ctrl-id').value;
  const erroEl = document.getElementById('ctrl-erro');
  erroEl.style.display = 'none';
  if (!id) return;

  const btn = document.getElementById('btn-testar-ctrl');
  const htmlOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Testando...';

  try {
    const r = await fetch('inventario_redes.php?action=controladora_testar', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id}),
    });
    const d = await r.json();
    if (d.ok) {
      erroEl.className = 'text-success small';
      erroEl.textContent = 'Login ok — conexão com a controladora funcionando.';
      erroEl.style.display = '';
    } else {
      erroEl.className = 'text-danger small';
      erroEl.textContent = d.msg || 'Falha ao testar controladora.';
      erroEl.style.display = '';
    }
  } catch (e) {
    erroEl.className = 'text-danger small';
    erroEl.textContent = 'Erro ao testar controladora.';
    erroEl.style.display = '';
  } finally {
    btn.disabled = false;
    btn.innerHTML = htmlOriginal;
  }
}

async function salvarControladora() {
  const id      = document.getElementById('ctrl-id').value;
  const apelido = document.getElementById('ctrl-apelido').value.trim();
  const url     = document.getElementById('ctrl-url').value.trim();
  const usuario = document.getElementById('ctrl-usuario').value.trim();
  const senha   = document.getElementById('ctrl-senha').value;
  const site    = document.getElementById('ctrl-site').value.trim();
  const erroEl  = document.getElementById('ctrl-erro');
  erroEl.style.display = 'none';

  if (!apelido || !url || !usuario) {
    erroEl.textContent = 'Preencha apelido, URL e usuário.';
    erroEl.style.display = '';
    return;
  }

  const action = id ? 'controladora_save' : 'controladora_add';
  const r = await fetch(`inventario_redes.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, apelido, url, usuario, senha, site}),
  });
  const d = await r.json();
  if (d.ok) { modalControladora.hide(); location.reload(); }
  else { erroEl.textContent = d.msg || 'Erro ao salvar'; erroEl.style.display = ''; }
}

async function excluirControladora() {
  const id = document.getElementById('ctrl-id').value;
  if (!id || !confirm('Excluir esta controladora? Os APs dela deixarão de aparecer.')) return;
  const r = await fetch('inventario_redes.php?action=controladora_delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (d.ok) { modalControladora.hide(); location.reload(); }
  else alert(d.msg || 'Erro ao excluir');
}
</script>
</body>
</html>
