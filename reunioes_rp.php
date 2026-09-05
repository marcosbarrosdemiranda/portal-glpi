<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';

$perfil_cards = $_SESSION['portal_perfil_cards'] ?? null;
$pode_interagir = ($perfil_cards === null) || (($perfil_cards['reunioes_rp'] ?? '') === 'interagir');

// ── Tabela ──────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS portal_reunioes_rp (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        data_reuniao  DATE NOT NULL,
        participantes VARCHAR(500) DEFAULT '',
        pauta         TEXT,
        decisoes      TEXT,
        criado_por    VARCHAR(120) DEFAULT '',
        criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Action handler (JSON) ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {

        if ($action === 'list') {
            $rows = $pdo->query("SELECT * FROM portal_reunioes_rp ORDER BY data_reuniao DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'dados' => $rows]); exit;
        }

        if ($action === 'add') {
            if (!$pode_interagir) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $d = trim($body['data_reuniao'] ?? '');
            $p = trim($body['participantes'] ?? '');
            $pa = trim($body['pauta'] ?? '');
            $de = trim($body['decisoes'] ?? '');
            if (!$d) { echo json_encode(['ok'=>false,'msg'=>'Informe a data da reunião']); exit; }
            $pdo->prepare("INSERT INTO portal_reunioes_rp (data_reuniao,participantes,pauta,decisoes,criado_por) VALUES (?,?,?,?,?)")
                ->execute([$d, $p, $pa, $de, $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '']);
            echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId()]); exit;
        }

        if ($action === 'edit') {
            if (!$pode_interagir) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = (int)($body['id'] ?? 0);
            $d = trim($body['data_reuniao'] ?? '');
            $p = trim($body['participantes'] ?? '');
            $pa = trim($body['pauta'] ?? '');
            $de = trim($body['decisoes'] ?? '');
            if (!$id || !$d) { echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit; }
            $pdo->prepare("UPDATE portal_reunioes_rp SET data_reuniao=?, participantes=?, pauta=?, decisoes=?, atualizado_em=NOW() WHERE id=?")
                ->execute([$d, $p, $pa, $de, $id]);
            echo json_encode(['ok'=>true]); exit;
        }

        if ($action === 'delete' && isset($_GET['id'])) {
            if (!$pode_interagir) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
            $pdo->prepare("DELETE FROM portal_reunioes_rp WHERE id=?")->execute([(int)$_GET['id']]);
            echo json_encode(['ok'=>true]); exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;

    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'Erro: ' . $e->getMessage()]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Reuniões RP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #6a1b9a; }
    body  { background: #f0f4f9; font-family: 'Segoe UI', sans-serif; margin: 0; }

    .topbar {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: .75rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: .5rem; }
    .topbar a {
      color: white; text-decoration: none; font-size: .82rem;
      background: rgba(255,255,255,.15); border-radius: 6px; padding: .3rem .75rem;
    }
    .topbar a:hover { background: rgba(255,255,255,.25); }

    .hero {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white; padding: 2rem 1rem 4.5rem; text-align: center;
    }
    .hero h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
    .hero p  { opacity: .8; margin-top: .5rem; font-size: .95rem; }

    .wrap { max-width: 1100px; margin: -3rem auto 3rem; padding: 0 1rem; }

    .stats-bar { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .stat-pill {
      background: white; border: 1px solid #e5e7eb; border-radius: 10px;
      padding: .5rem 1rem; font-size: .82rem; font-weight: 600;
      display: flex; align-items: center; gap: .4rem;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }

    .btn-nova {
      background: var(--mod); border: none; color: white; border-radius: 8px;
      padding: .5rem 1.1rem; font-size: .85rem; font-weight: 600; cursor: pointer;
      text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
    }
    .btn-nova:hover { background: #4a148c; color: white; }

    .lista { display: flex; flex-direction: column; gap: .85rem; }
    .reuniao-card {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.1rem 1.25rem;
    }
    .rc-header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .6rem; }
    .rc-data { font-weight: 700; font-size: .95rem; color: var(--mod); display: flex; align-items: center; gap: .4rem; }
    .rc-acoes { display: flex; gap: .4rem; }
    .rc-acoes button {
      border: none; background: #f3f4f6; color: #6b7280; border-radius: 6px;
      width: 30px; height: 30px; cursor: pointer; font-size: .85rem;
    }
    .rc-acoes button:hover { background: #e5e7eb; color: #1f2937; }
    .rc-acoes .btn-excluir:hover { background: #fee2e2; color: #b71c1c; }

    .rc-participantes { font-size: .8rem; color: #6b7280; margin-bottom: .65rem; display: flex; align-items: flex-start; gap: .4rem; }
    .rc-section { margin-top: .6rem; }
    .rc-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: .25rem; }
    .rc-texto { font-size: .85rem; color: #374151; white-space: pre-wrap; line-height: 1.5; background: #f9fafb; border-radius: 8px; padding: .55rem .75rem; }

    .sem-dados {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      text-align: center; padding: 3rem 2rem;
    }
    .sem-dados .icon-wrap {
      width: 80px; height: 80px; background: #f3e5f5; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.25rem; font-size: 2rem; color: var(--mod);
    }

    /* Modal */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.45);
      display: flex; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-box {
      background: white; border-radius: 14px; max-width: 620px; width: 100%;
      max-height: 88vh; display: flex; flex-direction: column;
      box-shadow: 0 16px 48px rgba(0,0,0,.25);
    }
    .modal-header {
      background: var(--primary); color: white; padding: .85rem 1.25rem;
      border-radius: 14px 14px 0 0; display: flex; align-items: center; justify-content: space-between;
    }
    .modal-tit { font-weight: 700; font-size: .95rem; }
    .modal-close { background: none; border: none; color: rgba(255,255,255,.8); font-size: 1.5rem; cursor: pointer; line-height: 1; }
    .modal-close:hover { color: white; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; gap: .6rem; }
    .form-lbl { font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .3rem; display: block; }
    .form-grp { margin-bottom: 1rem; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-people-fill"></i> Reuniões RP</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-people-fill me-2"></i>Reuniões com a RP Info</h1>
  <p>Registro de reuniões: participantes, pauta e decisões/pendências</p>
</div>

<div class="wrap">

  <div class="stats-bar">
    <div class="stat-pill">
      <i class="bi bi-calendar-event" style="color:var(--mod)"></i>
      Reuniões registradas: <strong id="stat-total">—</strong>
    </div>
    <div style="flex:1"></div>
    <?php if ($pode_interagir): ?>
    <button class="btn-nova" onclick="abrirModal()"><i class="bi bi-plus-lg"></i>Nova Reunião</button>
    <?php endif; ?>
  </div>

  <div id="lista-loading" class="sem-dados"><i class="bi bi-arrow-repeat me-1"></i>Carregando…</div>
  <div id="lista" class="lista" style="display:none"></div>
  <div id="sem-dados" class="sem-dados" style="display:none">
    <div class="icon-wrap"><i class="bi bi-calendar-x"></i></div>
    <h5 class="fw-bold" style="color:#1f2937">Nenhuma reunião registrada</h5>
    <p class="text-muted" style="max-width:480px;margin:.5rem auto">
      Registre a primeira reunião com a RP Info clicando em "Nova Reunião".
    </p>
  </div>

</div>

<!-- Modal -->
<div class="modal-overlay" id="modal" style="display:none" onclick="fecharModalOverlay(event)">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div class="modal-tit" id="modal-tit"><i class="bi bi-calendar-event me-2"></i>Nova Reunião</div>
      <button class="modal-close" onclick="fecharModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-id"/>
      <div class="form-grp">
        <label class="form-lbl">Data da reunião *</label>
        <input type="date" id="f-data" class="form-control"/>
      </div>
      <div class="form-grp">
        <label class="form-lbl">Participantes</label>
        <input type="text" id="f-participantes" class="form-control" placeholder="Nomes separados por vírgula"/>
      </div>
      <div class="form-grp">
        <label class="form-lbl">Pauta</label>
        <textarea id="f-pauta" class="form-control" rows="4" placeholder="Assuntos tratados na reunião"></textarea>
      </div>
      <div class="form-grp">
        <label class="form-lbl">Decisões / Pendências</label>
        <textarea id="f-decisoes" class="form-control" rows="4" placeholder="O que foi decidido e o que ficou pendente"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary btn-sm" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-sm" style="background:var(--mod);color:white" onclick="salvarReuniao()">
        <i class="bi bi-check-lg me-1"></i>Salvar
      </button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PODE_INTERAGIR = <?= $pode_interagir ? 'true' : 'false' ?>;
let reunioes = [];

function carregar() {
  fetch('reunioes_rp.php?action=list')
    .then(r => r.json())
    .then(data => {
      document.getElementById('lista-loading').style.display = 'none';
      if (!data.ok) { alert('Erro ao carregar: ' + (data.msg || '')); return; }
      reunioes = data.dados || [];
      document.getElementById('stat-total').textContent = reunioes.length;
      if (reunioes.length === 0) {
        document.getElementById('sem-dados').style.display = '';
        return;
      }
      document.getElementById('lista').style.display = '';
      document.getElementById('lista').innerHTML = reunioes.map(renderCard).join('');
    })
    .catch(err => {
      document.getElementById('lista-loading').innerHTML =
        '<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Erro de rede: ' + escHtml(err.message);
    });
}

function fmtData(iso) {
  if (!iso) return '—';
  const [a,m,d] = iso.split('-');
  return d + '/' + m + '/' + a;
}

function renderCard(r) {
  const acoes = PODE_INTERAGIR
    ? '<div class="rc-acoes">' +
      '<button title="Editar" onclick="editarReuniao(' + r.id + ')"><i class="bi bi-pencil"></i></button>' +
      '<button title="Excluir" class="btn-excluir" onclick="excluirReuniao(' + r.id + ')"><i class="bi bi-trash"></i></button>' +
      '</div>'
    : '';
  return '<div class="reuniao-card">' +
    '<div class="rc-header">' +
    '<div class="rc-data"><i class="bi bi-calendar-event"></i>' + fmtData(r.data_reuniao) + '</div>' +
    acoes +
    '</div>' +
    (r.participantes ? '<div class="rc-participantes"><i class="bi bi-people"></i>' + escHtml(r.participantes) + '</div>' : '') +
    (r.pauta ? '<div class="rc-section"><div class="rc-label"><i class="bi bi-list-check me-1"></i>Pauta</div><div class="rc-texto">' + escHtml(r.pauta) + '</div></div>' : '') +
    (r.decisoes ? '<div class="rc-section"><div class="rc-label"><i class="bi bi-check2-square me-1"></i>Decisões / Pendências</div><div class="rc-texto">' + escHtml(r.decisoes) + '</div></div>' : '') +
    '</div>';
}

function abrirModal() {
  document.getElementById('modal-tit').innerHTML = '<i class="bi bi-calendar-event me-2"></i>Nova Reunião';
  document.getElementById('f-id').value = '';
  document.getElementById('f-data').value = new Date().toISOString().slice(0,10);
  document.getElementById('f-participantes').value = '';
  document.getElementById('f-pauta').value = '';
  document.getElementById('f-decisoes').value = '';
  document.getElementById('modal').style.display = 'flex';
}

function editarReuniao(id) {
  const r = reunioes.find(x => x.id == id);
  if (!r) return;
  document.getElementById('modal-tit').innerHTML = '<i class="bi bi-pencil me-2"></i>Editar Reunião';
  document.getElementById('f-id').value = r.id;
  document.getElementById('f-data').value = r.data_reuniao;
  document.getElementById('f-participantes').value = r.participantes || '';
  document.getElementById('f-pauta').value = r.pauta || '';
  document.getElementById('f-decisoes').value = r.decisoes || '';
  document.getElementById('modal').style.display = 'flex';
}

function fecharModal() { document.getElementById('modal').style.display = 'none'; }
function fecharModalOverlay(e) { if (e.target === e.currentTarget) fecharModal(); }

function salvarReuniao() {
  const id = document.getElementById('f-id').value;
  const body = {
    id: id ? parseInt(id) : null,
    data_reuniao: document.getElementById('f-data').value,
    participantes: document.getElementById('f-participantes').value,
    pauta: document.getElementById('f-pauta').value,
    decisoes: document.getElementById('f-decisoes').value,
  };
  if (!body.data_reuniao) { alert('Informe a data da reunião'); return; }
  const action = id ? 'edit' : 'add';
  fetch('reunioes_rp.php?action=' + action, { method: 'POST', body: JSON.stringify(body) })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) { alert('Erro ao salvar: ' + (data.msg || 'falha')); return; }
      fecharModal();
      carregar();
    })
    .catch(err => alert('Erro de rede: ' + err.message));
}

function excluirReuniao(id) {
  if (!confirm('Excluir esta reunião? Esta ação não pode ser desfeita.')) return;
  fetch('reunioes_rp.php?action=delete&id=' + id)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) { alert('Erro ao excluir: ' + (data.msg || 'falha')); return; }
      carregar();
    })
    .catch(err => alert('Erro de rede: ' + err.message));
}

function escHtml(s) {
  if (typeof s !== 'string') s = String(s || '');
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') fecharModal();
});

carregar();
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI</footer>
</body>
</html>
