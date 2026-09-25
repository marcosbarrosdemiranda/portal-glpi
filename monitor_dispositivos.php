<?php
/**
 * monitor_dispositivos.php — Monitor de rede: o que monitorar e como, por grupo.
 *   - Config de cada grupo (intervalo, tolerância, queda curta, monitorar novos,
 *     ligar/desligar todos);
 *   - Equipamentos vindos do inventário + manuais, com a chave "Monitorar" e IP fixo.
 * Etapa 1 do roteiro (Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md):
 * ainda não pinga nem alerta.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/monitor_lib.php';

$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    $ok  = fn(array $extra = []) => print(json_encode(['ok' => true] + $extra, JSON_UNESCAPED_UNICODE));
    $err = fn(string $msg) => print(json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE));

    try {
        if ($action === 'listar') {
            $sinc = monitor_sincronizar($pdo); // inventário é a fonte: toda abertura traz o que mudou
            $ok(['grupos' => monitor_grupos_listar($pdo), 'dispositivos' => monitor_listar($pdo), 'sinc' => $sinc]);
            exit;
        }

        // chave "Monitorar" dentro da tela do inventário (inventario_pc.php)
        if ($action === 'por_origem') {
            $d = monitor_dispositivo_por_origem($pdo, (string) ($_GET['origem'] ?? ''), (int) ($_GET['origem_id'] ?? 0));
            $ok(['item' => $d ? [
                'id' => (int) $d['id'], 'monitorar' => (int) $d['monitorar'], 'ip_efetivo' => $d['ip_efetivo'],
                'grupo_nome' => $d['grupo_nome'], 'duplicado_de' => $d['duplicado_de'],
            ] : null]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $err('método inválido'); exit; }
        $id = (int) ($_POST['id'] ?? 0);

        switch ($action) {
            case 'set_monitorar':
                monitor_set_monitorar($pdo, $id, ($_POST['ligar'] ?? '') === '1');
                $ok();
                break;
            case 'set_ip_fixo':
                monitor_set_ip_fixo($pdo, $id, (string) ($_POST['ip'] ?? ''));
                $ok();
                break;
            case 'grupo_salvar':
                $grupo = (string) ($_POST['grupo'] ?? '');
                $g = monitor_grupo($pdo, $grupo);
                if ($g === null) { $err('grupo inexistente'); break; }
                monitor_grupo_salvar($pdo, $grupo, (string) $g['nome'], [
                    'intervalo_seg'        => $_POST['intervalo_seg'] ?? null,
                    'falhas_para_cair'     => $_POST['falhas_para_cair'] ?? null,
                    'sucessos_para_voltar' => $_POST['sucessos_para_voltar'] ?? null,
                    'queda_curta'          => $_POST['queda_curta'] ?? null,
                    'monitorar_novos'      => ($_POST['monitorar_novos'] ?? '') === '1',
                ]);
                $ok(['grupo' => monitor_grupo($pdo, $grupo)]);
                break;
            case 'grupo_todos':
                monitor_grupo_set_monitorar_todos($pdo, (string) ($_POST['grupo'] ?? ''), ($_POST['ligar'] ?? '') === '1');
                $ok();
                break;
            case 'manual_salvar':
                $args = [(string) ($_POST['nome'] ?? ''), (string) ($_POST['ip'] ?? ''), (string) ($_POST['loja'] ?? ''), (string) ($_POST['grupo'] ?? '')];
                if ($id > 0) monitor_manual_atualizar($pdo, $id, ...$args);
                else $id = monitor_manual_criar($pdo, ...$args);
                $ok(['id' => $id]);
                break;
            case 'manual_excluir':
                monitor_manual_excluir($pdo, $id);
                $ok();
                break;
            default:
                $err('ação desconhecida');
        }
    } catch (\InvalidArgumentException $e) {
        $err($e->getMessage());
    } catch (\Throwable $e) {
        error_log('monitor_dispositivos: ' . $e->getMessage());
        $err('falha ao processar');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Monitor de Rede</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:1150px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    table { width:100%; font-size:.82rem; }
    th { color:#6b7280; font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.02em; padding:.35rem .4rem; border-bottom:1px solid #e5e7eb; }
    td { padding:.35rem .4rem; border-bottom:1px solid #f1f3f5; vertical-align:middle; }
    .grupo-titulo { font-weight:700; font-size:.9rem; margin:1rem 0 .3rem; color:#1a237e; }
    .form-select-sm, .form-control-sm { font-size:.78rem; }
    .num { width:64px; }
    .badge-origem { font-size:.65rem; background:#eef2ff; color:#3730a3; border-radius:4px; padding:.1rem .35rem; }
    .dup { color:#b45309; font-size:.72rem; }
    .ips-extra { color:#9ca3af; font-size:.7rem; }
    .ip-fixo { width:130px; }
    .estimativa { color:#6b7280; font-size:.72rem; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-broadcast"></i> Monitor de Rede</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="alertas_config.php"><i class="bi bi-arrow-left me-1"></i>Configurar Alertas</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    Etapa 1 do Monitor de Rede: aqui você escolhe <b>o que</b> vai ser monitorado e <b>como</b>, por grupo.
    O ping e os alertas entram nas próximas etapas — por enquanto nada disso gera notificação.
  </div>

  <div class="card-box">
    <h6 class="mb-1">Configuração por grupo</h6>
    <p class="small text-muted mb-2">Alerta de "caiu" sai depois de ≈ intervalo × falhas. Ficou fora menos que isso e voltou = queda curta (ex.: PDV reiniciando).</p>
    <div class="table-responsive">
      <table>
        <thead><tr>
          <th>Grupo</th><th>Monitorados</th><th>Pinga a cada</th><th>Falhas p/ cair</th><th>Sucessos p/ voltar</th>
          <th>Queda curta</th><th>Monitorar novos</th><th></th>
        </tr></thead>
        <tbody id="grupos"><tr><td colspan="8">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div id="fb-grupo" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
      <h6 class="mb-0 me-auto">Equipamentos</h6>
      <div><label class="form-label small mb-0">Grupo</label>
        <select id="f-grupo" class="form-select form-select-sm" style="width:170px"><option value="">Todos</option></select></div>
      <div><label class="form-label small mb-0">Loja</label>
        <select id="f-loja" class="form-select form-select-sm" style="width:120px"><option value="">Todas</option></select></div>
      <div><label class="form-label small mb-0">Mostrar</label>
        <select id="f-mon" class="form-select form-select-sm" style="width:150px">
          <option value="">Todos</option><option value="1">Só monitorados</option><option value="0">Só não monitorados</option>
        </select></div>
      <div><label class="form-label small mb-0">Buscar</label>
        <input id="f-busca" class="form-control form-control-sm" style="width:160px" placeholder="nome ou IP"></div>
    </div>
    <div id="lista" class="small">Carregando…</div>
    <div id="fb-lista" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-1" id="man-titulo">Adicionar equipamento fora do inventário</h6>
    <p class="small text-muted mb-2">Switch, link, NAS, balança que não está no cadastro de balanças… Os do inventário aparecem sozinhos.</p>
    <input type="hidden" id="man-id" value="0">
    <div class="d-flex gap-2 flex-wrap align-items-end">
      <div><label class="form-label small mb-0">Nome</label><input id="man-nome" class="form-control form-control-sm" style="width:180px"></div>
      <div><label class="form-label small mb-0">IP</label><input id="man-ip" class="form-control form-control-sm" style="width:140px" placeholder="192.168.2.10"></div>
      <div><label class="form-label small mb-0">Loja</label><input id="man-loja" class="form-control form-control-sm" style="width:100px" placeholder="Lj 003"></div>
      <div><label class="form-label small mb-0">Grupo</label><select id="man-grupo" class="form-select form-select-sm" style="width:170px"></select></div>
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarManual()"><i class="bi bi-plus-lg me-1"></i><span id="man-btn">Adicionar</span></button>
      <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="man-cancelar" onclick="limparManual()">Cancelar</button>
    </div>
    <div id="fb-man" class="small mt-2"></div>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Monitor de Rede</footer>

<script>
let GRUPOS = [], DISP = [];
const H = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fb = (id, msg, ok) => { const el = document.getElementById(id); el.className = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger'); el.textContent = msg; };
const post = (action, dados = {}) => fetch('?action=' + action, { method: 'POST', body: new URLSearchParams(dados) }).then(r => r.json());
const INTERVALOS = [[30,'30 s'],[60,'1 min'],[120,'2 min'],[300,'5 min'],[600,'10 min']];
const QUEDA = [['registro','Só registro'],['resumo_diario','Resumo diário'],['na_hora','Aviso na hora']];
const fmtMin = seg => seg < 60 ? seg + ' s' : (seg % 60 ? (seg / 60).toFixed(1).replace('.', ',') : seg / 60) + ' min';

function carregar() {
  fetch('?action=listar').then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-lista', d.erro || 'falha ao carregar', false);
    GRUPOS = d.grupos; DISP = d.dispositivos;
    montarFiltros(); renderGrupos(); renderLista();
    if (d.sinc && d.sinc.novos) fb('fb-lista', d.sinc.novos + ' equipamento(s) novo(s) do inventário entraram agora.', true);
  });
}

function montarFiltros() {
  const fg = document.getElementById('f-grupo'), fl = document.getElementById('f-loja'), mg = document.getElementById('man-grupo');
  const selG = fg.value, selL = fl.value;
  fg.innerHTML = '<option value="">Todos</option>' + GRUPOS.map(g => `<option value="${H(g.grupo)}">${H(g.nome)}</option>`).join('');
  mg.innerHTML = GRUPOS.map(g => `<option value="${H(g.grupo)}">${H(g.nome)}</option>`).join('');
  const lojas = [...new Set(DISP.map(x => x.loja).filter(Boolean))].sort();
  fl.innerHTML = '<option value="">Todas</option>' + lojas.map(l => `<option>${H(l)}</option>`).join('');
  fg.value = selG; fl.value = selL;
}

function renderGrupos() {
  const cont = g => { const t = DISP.filter(x => x.grupo === g); return [t.filter(x => +x.monitorar).length, t.length]; };
  document.getElementById('grupos').innerHTML = GRUPOS.map(g => {
    const [m, t] = cont(g.grupo);
    const sel = (lista, v) => lista.map(([k, l]) => `<option value="${k}" ${String(k) === String(v) ? 'selected' : ''}>${l}</option>`).join('');
    return `<tr data-grupo="${H(g.grupo)}">
      <td style="font-weight:600">${H(g.nome)}<div class="estimativa">caiu ≈ ${fmtMin(g.intervalo_seg * g.falhas_para_cair)}</div></td>
      <td>${m} / ${t}</td>
      <td><select class="form-select form-select-sm g-int">${sel(INTERVALOS, g.intervalo_seg)}</select></td>
      <td><input type="number" min="1" max="60" class="form-control form-control-sm num g-falhas" value="${g.falhas_para_cair}"></td>
      <td><input type="number" min="1" max="10" class="form-control form-control-sm num g-suc" value="${g.sucessos_para_voltar}"></td>
      <td><select class="form-select form-select-sm g-queda">${sel(QUEDA, g.queda_curta)}</select></td>
      <td><div class="form-check form-switch"><input class="form-check-input g-novos" type="checkbox" ${+g.monitorar_novos ? 'checked' : ''}></div></td>
      <td class="text-nowrap">
        <button class="btn btn-primary btn-sm" onclick="salvarGrupo('${H(g.grupo)}')">Salvar</button>
        <button class="btn btn-outline-success btn-sm" title="Ligar Monitorar em todos do grupo" onclick="grupoTodos('${H(g.grupo)}',1)"><i class="bi bi-toggle-on"></i></button>
        <button class="btn btn-outline-secondary btn-sm" title="Desligar Monitorar em todos do grupo" onclick="grupoTodos('${H(g.grupo)}',0)"><i class="bi bi-toggle-off"></i></button>
      </td></tr>`;
  }).join('');
}

function renderLista() {
  const fg = document.getElementById('f-grupo').value, fl = document.getElementById('f-loja').value;
  const fm = document.getElementById('f-mon').value, busca = document.getElementById('f-busca').value.trim().toLowerCase();
  const itens = DISP.filter(x => (!fg || x.grupo === fg) && (!fl || x.loja === fl) && (fm === '' || String(+x.monitorar) === fm)
    && (!busca || (x.nome + ' ' + (x.ips || '') + ' ' + (x.ip_fixo || '')).toLowerCase().includes(busca)));
  if (!itens.length) { document.getElementById('lista').innerHTML = '<div class="text-muted">Nenhum equipamento com esse filtro.</div>'; return; }

  const porGrupo = {};
  itens.forEach(x => (porGrupo[x.grupo_nome || x.grupo] ??= []).push(x));
  let html = '';
  for (const [grupo, lista] of Object.entries(porGrupo)) {
    html += `<div class="grupo-titulo">${H(grupo)} <span class="text-muted fw-normal">(${lista.length})</span></div>
      <table><thead><tr><th style="width:70px">Monitorar</th><th>Nome</th><th>Loja</th><th>IP</th><th>IP fixo</th><th>Origem</th><th></th></tr></thead><tbody>`;
    for (const x of lista) {
      const outros = (x.ips || '').split(',').filter(ip => ip && ip !== x.ip);
      html += `<tr>
        <td><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" ${+x.monitorar ? 'checked' : ''}
             ${x.duplicado_de ? 'disabled title="IP duplicado — resolva antes de monitorar"' : ''} onchange="setMonitorar(${x.id}, this.checked)"></div></td>
        <td style="font-weight:600">${H(x.nome)}${x.duplicado_de ? `<div class="dup"><i class="bi bi-exclamation-triangle"></i> mesmo IP de ${H(x.duplicado_de)} (registro antigo?)</div>` : ''}</td>
        <td>${H(x.loja) || '<span class="text-muted">—</span>'}</td>
        <td>${H(x.ip_efetivo) || '<span class="text-danger">sem IP</span>'}${outros.length ? `<div class="ips-extra" title="IPs antigos/alternativos do inventário — o monitor testa todos">+ ${H(outros.join(', '))}</div>` : ''}</td>
        <td>${x.origem === 'manual' ? '<span class="text-muted">—</span>' :
             `<input class="form-control form-control-sm ip-fixo" value="${H(x.ip_fixo || '')}" placeholder="usar do inventário"
                     onchange="setIpFixo(${x.id}, this)">`}</td>
        <td><span class="badge-origem">${H(x.origem)}</span></td>
        <td class="text-nowrap">${x.origem === 'manual' ?
             `<button class="btn btn-link btn-sm p-0 me-2" onclick="editarManual(${x.id})">editar</button>
              <button class="btn btn-link btn-sm p-0 text-danger" onclick="excluirManual(${x.id})">excluir</button>` : ''}</td>
      </tr>`;
    }
    html += '</tbody></table>';
  }
  document.getElementById('lista').innerHTML = html;
}

function salvarGrupo(grupo) {
  const tr = document.querySelector(`tr[data-grupo="${CSS.escape(grupo)}"]`);
  post('grupo_salvar', {
    grupo,
    intervalo_seg: tr.querySelector('.g-int').value,
    falhas_para_cair: tr.querySelector('.g-falhas').value,
    sucessos_para_voltar: tr.querySelector('.g-suc').value,
    queda_curta: tr.querySelector('.g-queda').value,
    monitorar_novos: tr.querySelector('.g-novos').checked ? '1' : '0',
  }).then(d => {
    if (!d.ok) return fb('fb-grupo', d.erro || 'falha', false);
    fb('fb-grupo', 'Grupo salvo.', true);
    GRUPOS = GRUPOS.map(g => g.grupo === grupo ? d.grupo : g);
    renderGrupos();
  });
}

function grupoTodos(grupo, ligar) {
  const g = GRUPOS.find(x => x.grupo === grupo);
  if (!confirm((ligar ? 'Ligar' : 'Desligar') + ' Monitorar em todos os equipamentos de "' + (g ? g.nome : grupo) + '"?')) return;
  post('grupo_todos', { grupo, ligar }).then(d => { if (!d.ok) return fb('fb-grupo', d.erro || 'falha', false); carregar(); });
}

function setMonitorar(id, ligar) {
  post('set_monitorar', { id, ligar: ligar ? '1' : '0' }).then(d => {
    if (!d.ok) { fb('fb-lista', d.erro || 'falha', false); return carregar(); }
    const x = DISP.find(y => +y.id === id); if (x) x.monitorar = ligar ? 1 : 0;
    renderGrupos();
  });
}

function setIpFixo(id, input) {
  post('set_ip_fixo', { id, ip: input.value.trim() }).then(d => {
    if (!d.ok) { fb('fb-lista', d.erro || 'falha', false); return; }
    fb('fb-lista', input.value.trim() ? 'IP fixo salvo.' : 'Voltou a usar o IP do inventário.', true);
    carregar();
  });
}

function salvarManual() {
  post('manual_salvar', {
    id: document.getElementById('man-id').value,
    nome: document.getElementById('man-nome').value, ip: document.getElementById('man-ip').value,
    loja: document.getElementById('man-loja').value, grupo: document.getElementById('man-grupo').value,
  }).then(d => {
    if (!d.ok) return fb('fb-man', d.erro || 'falha', false);
    fb('fb-man', 'Salvo.', true); limparManual(); carregar();
  });
}

function editarManual(id) {
  const x = DISP.find(y => +y.id === id); if (!x) return;
  document.getElementById('man-id').value = id;
  document.getElementById('man-nome').value = x.nome; document.getElementById('man-ip').value = x.ip;
  document.getElementById('man-loja').value = x.loja; document.getElementById('man-grupo').value = x.grupo;
  document.getElementById('man-titulo').textContent = 'Editar equipamento manual';
  document.getElementById('man-btn').textContent = 'Salvar';
  document.getElementById('man-cancelar').classList.remove('d-none');
  document.getElementById('man-nome').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function limparManual() {
  ['man-nome', 'man-ip', 'man-loja'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('man-id').value = '0';
  document.getElementById('man-titulo').textContent = 'Adicionar equipamento fora do inventário';
  document.getElementById('man-btn').textContent = 'Adicionar';
  document.getElementById('man-cancelar').classList.add('d-none');
}

function excluirManual(id) {
  const x = DISP.find(y => +y.id === id);
  if (!confirm('Excluir "' + (x ? x.nome : id) + '" do monitor?')) return;
  post('manual_excluir', { id }).then(d => { if (!d.ok) return fb('fb-lista', d.erro || 'falha', false); carregar(); });
}

['f-grupo', 'f-loja', 'f-mon'].forEach(i => document.getElementById(i).addEventListener('change', renderLista));
document.getElementById('f-busca').addEventListener('input', renderLista);
carregar();
</script>
</body>
</html>
