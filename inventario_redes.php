<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/vault_crypto.php';
require_once __DIR__ . '/monitor_antenas_lib.php';

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

/** "última verificação há Xmin" do grid de antenas (inventario_redes.php). */
function formatarHaQuanto(string $datetime): string {
    $decorrido = time() - strtotime($datetime);
    if ($decorrido < 0) return 'agora';
    return 'há ' . formatarUptime($decorrido);
}

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'super-admin', 'tecnico']);

// ── AJAX: CRUD de antenas + credencial/limite globais (somente admin) ──
// Tabela portal_monitor_antenas é criada por monitor_antenas_lib.php ao
// incluir. portal_unifi_controladoras/unifi_client.php continuam no
// código (sem UI aqui) — ver spec 2026-10-10-monitor-antenas-unifi-ssh.
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        echo json_encode(['ok' => false, 'msg' => 'Requisição inválida']); exit;
    }
    if (!$is_admin) { echo json_encode(['ok' => false, 'msg' => 'Sem permissão']); exit; }

    if ($action === 'antena_add' || $action === 'antena_save') {
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $nome       = trim($body['nome'] ?? '');
        $ip         = trim($body['ip'] ?? '');
        $sshUsuario = trim($body['ssh_usuario'] ?? '');
        // Senha não é trimada de propósito — pode ter espaços à margem que fazem parte dela.
        $sshSenha   = (string)($body['ssh_senha'] ?? '');
        $ativo      = !empty($body['ativo']);
        $id         = (int)($body['id'] ?? 0);

        if ($action === 'antena_save' && !$id) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit;
        }
        if (!$nome || !$ip) {
            echo json_encode(['ok' => false, 'msg' => 'Nome e IP são obrigatórios']); exit;
        }

        try {
            $novoId = monitor_antena_salvar($pdo, $action === 'antena_save' ? $id : null, $nome, $ip, $sshUsuario, $sshSenha, $ativo);
            echo json_encode(['ok' => true, 'id' => $novoId]);
        } catch (\InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'antena_testar') {
        // Testa com os dados do formulário (antes de salvar) ou, se vier um id
        // sem usuário/senha no corpo, com a credencial já salva/global.
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $ip         = trim($body['ip'] ?? '');
        $sshUsuario = trim($body['ssh_usuario'] ?? '');
        $sshSenha   = (string)($body['ssh_senha'] ?? '');
        $id         = (int)($body['id'] ?? 0);

        if ($ip === '') { echo json_encode(['ok' => false, 'erro' => 'IP obrigatório']); exit; }

        if ($sshUsuario === '' || $sshSenha === '') {
            $antenaAtual = ['ssh_usuario' => $sshUsuario, 'ssh_senha_enc' => ''];
            if ($id) {
                $st = $pdo->prepare("SELECT ssh_usuario, ssh_senha_enc FROM portal_monitor_antenas WHERE id=?");
                $st->execute([$id]);
                $antenaAtual = $st->fetch(PDO::FETCH_ASSOC) ?: $antenaAtual;
            }
            $cred       = monitor_antena_credencial($antenaAtual);
            $sshUsuario = $sshUsuario !== '' ? $sshUsuario : $cred['usuario'];
            $sshSenha   = $sshSenha !== '' ? $sshSenha : $cred['senha'];
        }

        echo json_encode(monitor_antena_ssh_check($ip, $sshUsuario, $sshSenha));
        exit;
    }

    if ($action === 'antena_delete') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        monitor_antena_excluir($pdo, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'antena_config_salvar') {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $usuario = trim($body['usuario'] ?? '');
        $senha   = (string)($body['senha'] ?? '');
        $limite  = (int)($body['limite'] ?? 0);

        wpp_cfg_set('antena_ssh_usuario', $usuario);
        if ($senha !== '') {
            wpp_cfg_set('antena_ssh_senha_enc', vault_encrypt($senha));
        }
        if ($limite > 0) {
            wpp_cfg_set('antena_limite_clientes', (string)$limite);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
    exit;
}

// ── Antenas cadastradas (snapshot já persistido — sem SSH no page load,
// a varredura é feita pelo ciclo da Central de Alertas / worker) ──
$antenas          = monitor_antena_listar($pdo);
$antenaUsuarioCfg = (string) wpp_cfg_get('antena_ssh_usuario', '');
$antenaLimiteCfg  = (int) wpp_cfg_get('antena_limite_clientes', '30');
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

    /* ── Config global (credencial SSH + limite de clientes) ─────── */
    .antena-cfg-section { background:#fff; border:1px solid #e5e7eb; border-radius:12px;
                           padding:1rem; margin-bottom:1.5rem; }
    .antena-cfg-row { display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end; }
    .antena-cfg-row .form-control { min-width:160px; }

    /* ── Antenas (cadastro + status via SSH) ──────────────────────── */
    .antena-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.85rem; }
    .antena-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem;
                    background:#fff; display:flex; flex-direction:column; gap:.4rem; position:relative; }
    .antena-topo { display:flex; align-items:center; gap:.5rem; }
    .antena-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .antena-dot.online        { background:#1e8e3e; }
    .antena-dot.offline       { background:#d93025; }
    .antena-dot.desconhecido  { background:#9ca3af; }
    .antena-nome { font-weight:700; font-size:.88rem; }
    .antena-ip { font-size:.72rem; color:#6b7280; }
    .antena-modelo { font-size:.75rem; color:#6b7280; }
    .antena-meta { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.4rem;
                    padding-top:.5rem; border-top:1px solid #f3f4f6; font-size:.72rem; color:#6b7280; }
    .antena-cfg { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0;
                   position:absolute; top:.75rem; right:.75rem; }
    .antena-cfg:hover { color:#1a237e; }
    .antena-add { display:flex; flex-direction:column; align-items:center; justify-content:center;
                   gap:.35rem; min-height:64px; border:2px dashed #d1d5db; color:#9ca3af;
                   cursor:pointer; border-radius:12px; }
    .antena-add:hover { border-color:#1a237e; color:#1a237e; }
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
  <p style="opacity:.8;margin-top:.5rem">Status das antenas UniFi (verificação via SSH)</p>
</div>

<div class="wrap">

<?php if ($is_admin): ?>
<div class="antena-cfg-section">
  <h6 class="fw-bold mb-2" style="color:#374151">
    <i class="bi bi-gear-fill me-2"></i>Credencial SSH padrão e limite de clientes
  </h6>
  <div class="antena-cfg-row">
    <div>
      <label class="form-label small mb-1">Usuário SSH padrão</label>
      <input type="text" class="form-control" id="cfg-usuario" value="<?= esc($antenaUsuarioCfg) ?>" placeholder="ubnt"/>
    </div>
    <div>
      <label class="form-label small mb-1">Senha SSH padrão</label>
      <input type="password" class="form-control font-monospace" id="cfg-senha" placeholder="Deixe em branco para manter" autocomplete="new-password"/>
    </div>
    <div>
      <label class="form-label small mb-1">Limite de clientes conectados</label>
      <input type="number" class="form-control" id="cfg-limite" value="<?= (int)$antenaLimiteCfg ?>" min="1"/>
    </div>
    <button type="button" class="btn btn-primary" onclick="salvarConfigAntenas()" style="background:#1a237e;border-color:#1a237e">
      <i class="bi bi-check-lg me-1"></i>Salvar
    </button>
  </div>
  <div id="cfg-msg" class="small mt-2" style="display:none"></div>
</div>
<?php endif; ?>

<!-- ═══════════════ ANTENAS (SSH) ═══════════════ -->
<div class="antena-grid">
  <?php foreach ($antenas as $a): ?>
    <div class="antena-card">
      <?php if ($is_admin): ?>
      <button type="button" class="antena-cfg" onclick='editarAntena(<?= json_encode(['id'=>$a['id'],'nome'=>$a['nome'],'ip'=>$a['ip'],'ssh_usuario'=>$a['ssh_usuario'],'ativo'=>(int)$a['ativo']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)' title="Editar">
        <i class="bi bi-gear-fill"></i>
      </button>
      <?php endif; ?>
      <div class="antena-topo">
        <span class="antena-dot <?= esc($a['status']) ?>"></span>
        <span class="antena-nome"><?= esc($a['nome']) ?></span>
      </div>
      <div class="antena-ip"><?= esc($a['ip']) ?></div>
      <div class="antena-modelo"><?= esc($a['modelo'] ?? '—') ?><?= $a['firmware_versao'] ? ' · fw ' . esc($a['firmware_versao']) : '' ?></div>
      <div class="antena-meta">
        <span><i class="bi bi-people-fill me-1"></i><?= $a['clientes_conectados'] !== null ? (int)$a['clientes_conectados'] : '—' ?> clientes</span>
        <span><i class="bi bi-clock-history me-1"></i><?= $a['ultima_verificacao'] ? esc(formatarHaQuanto($a['ultima_verificacao'])) : 'nunca verificado' ?></span>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($is_admin): ?>
    <div class="antena-card antena-add" onclick="abrirModalAntena()">
      <i class="bi bi-plus-circle" style="font-size:1.5rem"></i>
      <div class="antena-nome">Adicionar</div>
    </div>
  <?php endif; ?>
</div>
<?php if (!$antenas && !$is_admin): ?>
  <div class="text-muted small mt-4">Nenhuma antena cadastrada.</div>
<?php endif; ?>

</div><!-- /wrap -->

<!-- Modal: adicionar/editar antena UniFi (via SSH) -->
<div class="modal fade" id="modalAntena" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a237e,#1565c0);color:white">
        <h5 class="modal-title fw-bold" id="modalAntenaTitulo"><i class="bi bi-wifi me-2"></i>Nova Antena</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ant-id"/>
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome</label>
          <input type="text" class="form-control" id="ant-nome" placeholder="Ex: AP Loja 101 - Salão"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">IP</label>
          <input type="text" class="form-control font-monospace" id="ant-ip" placeholder="192.168.x.x"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Usuário SSH <span class="text-muted small">(vazio = usa o padrão global)</span></label>
          <input type="text" class="form-control" id="ant-usuario" placeholder="ubnt"/>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha SSH <span class="text-muted small">(vazio = usa o padrão global)</span></label>
          <input type="password" class="form-control font-monospace" id="ant-senha" placeholder="••••••••" autocomplete="new-password"/>
        </div>
        <div class="mb-2 form-check form-switch">
          <input class="form-check-input" type="checkbox" id="ant-ativo" checked/>
          <label class="form-check-label" for="ant-ativo">Ativa (entra na varredura)</label>
        </div>
        <div id="ant-erro" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="btn-excluir-ant" style="display:none" onclick="excluirAntena()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" id="btn-testar-ant" onclick="testarAntena()"><i class="bi bi-plug me-1"></i>Testar</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarAntena()" style="background:#1a237e;border-color:#1a237e"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalAntena;
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('modalAntena');
  if (el) modalAntena = new bootstrap.Modal(el);
});

function abrirModalAntena() {
  document.getElementById('ant-id').value = '';
  document.getElementById('ant-nome').value = '';
  document.getElementById('ant-ip').value = '';
  document.getElementById('ant-usuario').value = '';
  document.getElementById('ant-senha').value = '';
  document.getElementById('ant-senha').placeholder = '••••••••';
  document.getElementById('ant-ativo').checked = true;
  document.getElementById('ant-erro').style.display = 'none';
  document.getElementById('modalAntenaTitulo').innerHTML = '<i class="bi bi-wifi me-2"></i>Nova Antena';
  document.getElementById('btn-excluir-ant').style.display = 'none';
  modalAntena.show();
}

function editarAntena(a) {
  document.getElementById('ant-id').value = a.id;
  document.getElementById('ant-nome').value = a.nome;
  document.getElementById('ant-ip').value = a.ip;
  document.getElementById('ant-usuario').value = a.ssh_usuario || '';
  document.getElementById('ant-senha').value = '';
  document.getElementById('ant-senha').placeholder = 'Deixe em branco para manter a senha atual';
  document.getElementById('ant-ativo').checked = !!a.ativo;
  document.getElementById('ant-erro').style.display = 'none';
  document.getElementById('modalAntenaTitulo').textContent = a.nome;
  document.getElementById('btn-excluir-ant').style.display = 'inline-block';
  modalAntena.show();
}

async function testarAntena() {
  const id      = document.getElementById('ant-id').value;
  const ip      = document.getElementById('ant-ip').value.trim();
  const usuario = document.getElementById('ant-usuario').value.trim();
  const senha   = document.getElementById('ant-senha').value;
  const erroEl  = document.getElementById('ant-erro');
  erroEl.style.display = 'none';

  if (!ip) { erroEl.className = 'text-danger small'; erroEl.textContent = 'Informe o IP antes de testar.'; erroEl.style.display = ''; return; }

  const btn = document.getElementById('btn-testar-ant');
  const htmlOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Testando...';

  try {
    const r = await fetch('inventario_redes.php?action=antena_testar', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id, ip, ssh_usuario: usuario, ssh_senha: senha}),
    });
    const d = await r.json();
    if (d.ok) {
      erroEl.className = 'text-success small';
      erroEl.textContent = 'Conexão SSH ok' + (d.modelo ? ` — ${d.modelo}` : '') + '.';
      erroEl.style.display = '';
    } else {
      erroEl.className = 'text-danger small';
      erroEl.textContent = d.erro || 'Falha ao testar antena.';
      erroEl.style.display = '';
    }
  } catch (e) {
    erroEl.className = 'text-danger small';
    erroEl.textContent = 'Erro ao testar antena.';
    erroEl.style.display = '';
  } finally {
    btn.disabled = false;
    btn.innerHTML = htmlOriginal;
  }
}

async function salvarAntena() {
  const id      = document.getElementById('ant-id').value;
  const nome    = document.getElementById('ant-nome').value.trim();
  const ip      = document.getElementById('ant-ip').value.trim();
  const usuario = document.getElementById('ant-usuario').value.trim();
  const senha   = document.getElementById('ant-senha').value;
  const ativo   = document.getElementById('ant-ativo').checked;
  const erroEl  = document.getElementById('ant-erro');
  erroEl.style.display = 'none';

  if (!nome || !ip) {
    erroEl.className = 'text-danger small';
    erroEl.textContent = 'Preencha nome e IP.';
    erroEl.style.display = '';
    return;
  }

  // Mesma regra UX do cadastro de controladoras que esta tela substitui:
  // bloqueia o save se o teste de conexão falhar.
  const teste = await fetch('inventario_redes.php?action=antena_testar', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, ip, ssh_usuario: usuario, ssh_senha: senha}),
  }).then(r => r.json());
  if (!teste.ok) {
    erroEl.className = 'text-danger small';
    erroEl.textContent = 'Teste falhou: ' + (teste.erro || 'não foi possível conectar.');
    erroEl.style.display = '';
    return;
  }

  const action = id ? 'antena_save' : 'antena_add';
  const r = await fetch(`inventario_redes.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, nome, ip, ssh_usuario: usuario, ssh_senha: senha, ativo}),
  });
  const d = await r.json();
  if (d.ok) { modalAntena.hide(); location.reload(); }
  else { erroEl.className = 'text-danger small'; erroEl.textContent = d.msg || 'Erro ao salvar'; erroEl.style.display = ''; }
}

async function excluirAntena() {
  const id = document.getElementById('ant-id').value;
  if (!id || !confirm('Excluir esta antena?')) return;
  const r = await fetch('inventario_redes.php?action=antena_delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id}),
  });
  const d = await r.json();
  if (d.ok) { modalAntena.hide(); location.reload(); }
  else alert(d.msg || 'Erro ao excluir');
}

async function salvarConfigAntenas() {
  const usuario = document.getElementById('cfg-usuario').value.trim();
  const senha   = document.getElementById('cfg-senha').value;
  const limite  = document.getElementById('cfg-limite').value;
  const msgEl   = document.getElementById('cfg-msg');

  const r = await fetch('inventario_redes.php?action=antena_config_salvar', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({usuario, senha, limite}),
  });
  const d = await r.json();
  msgEl.className = d.ok ? 'text-success small mt-2' : 'text-danger small mt-2';
  msgEl.textContent = d.ok ? 'Configuração salva.' : (d.msg || 'Erro ao salvar.');
  msgEl.style.display = '';
  if (d.ok) document.getElementById('cfg-senha').value = '';
}
</script>
</body>
</html>
