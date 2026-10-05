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
require_once __DIR__ . '/monitor_links_lib.php'; // quadro "Links de internet"

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
            $ok(['grupos' => monitor_grupos_listar($pdo), 'dispositivos' => monitor_listar($pdo), 'sinc' => $sinc,
                 'rodada' => monitor_resumo_rodada(), 'links' => monitor_links_listar($pdo)]);
            exit;
        }

        if ($action === 'quedas') {
            $ok(['quedas' => monitor_quedas_curtas($pdo, (int) ($_GET['id'] ?? 0))]);
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

        if ($action === 'categorias_horario_listar') {
            $ok(['categorias' => monitor_categoria_config_listar($pdo)]);
            exit;
        }

        if ($action === 'excecoes_listar') {
            $ok(['excecoes' => monitor_horario_excecao_listar($pdo)]);
            exit;
        }

        if ($action === 'feriados_listar') {
            $ok(['feriados' => monitor_feriado_listar($pdo)]);
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
            case 'set_porta_tcp':
                monitor_set_porta_tcp($pdo, $id, (int) ($_POST['porta'] ?? 0));
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
                    'pacotes'              => $_POST['pacotes'] ?? null,
                    'queda_curta'          => $_POST['queda_curta'] ?? null,
                    'monitorar_novos'      => ($_POST['monitorar_novos'] ?? '') === '1',
                ]);
                $ok(['grupo' => monitor_grupo($pdo, $grupo)]);
                break;
            case 'grupo_todos':
                monitor_grupo_set_monitorar_todos($pdo, (string) ($_POST['grupo'] ?? ''), ($_POST['ligar'] ?? '') === '1');
                $ok();
                break;
            case 'categoria_horario_salvar':
                $categoria = (string) ($_POST['categoria'] ?? '');
                if ($categoria === '') { $err('categoria inválida'); break; }
                $horaInicio = trim((string) ($_POST['horario_inicio'] ?? ''));
                $horaFim    = trim((string) ($_POST['horario_fim'] ?? ''));
                if (($horaInicio === '') !== ($horaFim === '')) { $err('preencha os dois horários, ou deixe os dois em branco (sempre notifica)'); break; }
                if ($horaInicio !== '' && (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFim))) { $err('horário inválido (use HH:MM)'); break; }
                $ligadoStr = trim((string) ($_POST['ligado_horas_max'] ?? ''));
                $ligado = null;
                if ($ligadoStr !== '') {
                    $ligado = (int) $ligadoStr;
                    if ($ligado < 1 || $ligado > 720) { $err('horas ligado: informe de 1 a 720, ou deixe em branco'); break; }
                }
                monitor_categoria_config_salvar($pdo, $categoria, $horaInicio !== '' ? $horaInicio . ':00' : null, $horaFim !== '' ? $horaFim . ':00' : null, $ligado);
                $ok();
                break;
            case 'excecoes_salvar':
                $categoria = trim((string) ($_POST['categoria'] ?? ''));
                $loja      = trim((string) ($_POST['loja'] ?? ''));
                $diaSemana = (int) ($_POST['dia_semana'] ?? -1);
                if ($categoria === '' || $loja === '' || $diaSemana < 0 || $diaSemana > 6) { $err('categoria, loja e dia da semana são obrigatórios'); break; }
                $horaInicio = trim((string) ($_POST['horario_inicio'] ?? ''));
                $horaFim    = trim((string) ($_POST['horario_fim'] ?? ''));
                if ($horaInicio === '' || $horaFim === '') { $err('preencha os dois horários (pra remover a exceção, use o botão excluir)'); break; }
                if (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFim)) { $err('horário inválido (use HH:MM)'); break; }
                monitor_horario_excecao_salvar($pdo, $categoria, $loja, $diaSemana, $horaInicio . ':00', $horaFim . ':00');
                $ok();
                break;
            case 'excecoes_excluir':
                $categoria = trim((string) ($_POST['categoria'] ?? ''));
                $loja      = trim((string) ($_POST['loja'] ?? ''));
                $diaSemana = (int) ($_POST['dia_semana'] ?? -1);
                if ($categoria === '' || $loja === '' || $diaSemana < 0 || $diaSemana > 6) { $err('parâmetros inválidos'); break; }
                monitor_horario_excecao_excluir($pdo, $categoria, $loja, $diaSemana);
                $ok();
                break;
            case 'feriados_salvar':
                $data = trim((string) ($_POST['data'] ?? ''));
                $loja = trim((string) ($_POST['loja'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { $err('data inválida'); break; }
                monitor_feriado_salvar($pdo, $data, $loja);
                $ok();
                break;
            case 'feriados_excluir':
                $data = trim((string) ($_POST['data'] ?? ''));
                $loja = trim((string) ($_POST['loja'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { $err('data inválida'); break; }
                monitor_feriado_excluir($pdo, $data, $loja);
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
    <b>Monitor de rede ativo:</b> é ele que gera os alertas de rede da Central (equipamento fora, queda de VPN,
    link de internet). O The Dude não altera mais nada. A coluna "Central" mostra o que a Central de Alertas está vendo.
    <div id="rodada" class="mt-1"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-1">Links de internet das lojas</h6>
    <div class="text-muted small mb-2">Lido do Status → Gateways de cada pfSense a cada 1 min. ⭐ = link principal · ➜ = por onde a loja está saindo agora.</div>
    <table>
      <thead><tr><th>Loja</th><th>Link</th><th>Status</th><th>Latência</th><th>Perda</th><th>Desde</th></tr></thead>
      <tbody id="links"><tr><td colspan="6">Carregando…</td></tr></tbody>
    </table>
  </div>

  <div class="card-box">
    <h6 class="mb-1">Configuração por grupo</h6>
    <p class="small text-muted mb-2">Alerta de "caiu" sai depois de ≈ intervalo × falhas. Ficou fora menos que isso e voltou = queda curta (ex.: PDV reiniciando).</p>
    <div class="table-responsive">
      <table>
        <thead><tr>
          <th>Grupo</th><th>Monitorados</th><th>Pinga a cada</th><th>Falhas p/ cair</th><th>Sucessos p/ voltar</th>
          <th title="Pacotes de ping por rodada — conta falha só se TODOS se perderem (VPN: 3)">Pacotes</th><th>Queda curta</th><th>Monitorar novos</th><th></th>
        </tr></thead>
        <tbody id="grupos"><tr><td colspan="8">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div id="fb-grupo" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-1">Silêncio por horário (equipamento fora do ar)</h6>
    <p class="small text-muted mb-2">Só vale pro alerta "Sem comunicação (IPs/dispositivos)" — Link/Latência/Serviço sempre alertam, qualquer hora.</p>

    <div class="grupo-titulo" style="margin-top:0">Horário padrão por grupo</div>
    <div class="table-responsive">
      <table>
        <thead><tr><th>Grupo</th><th>Início</th><th>Fim</th><th title="Deixe em branco pra não usar essa regra">Ligado há muito tempo (h)</th><th></th></tr></thead>
        <tbody id="cat-horario"><tr><td colspan="5">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div id="fb-cat-horario" class="small mt-2"></div>

    <div class="grupo-titulo">Exceções por loja/dia da semana</div>
    <p class="small text-muted mb-2">Quando existir, manda sobre o horário padrão do grupo pra essa loja, nesse dia.</p>
    <div class="table-responsive">
      <table>
        <thead><tr><th>Grupo</th><th>Loja</th><th>Dia</th><th>Início</th><th>Fim</th><th></th></tr></thead>
        <tbody id="excecoes"><tr><td colspan="6">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-end mt-2">
      <div><label class="form-label small mb-0">Grupo</label><select id="exc-categoria" class="form-select form-select-sm" style="width:150px"></select></div>
      <div><label class="form-label small mb-0">Loja</label><select id="exc-loja" class="form-select form-select-sm" style="width:110px"></select></div>
      <div><label class="form-label small mb-0">Dia</label>
        <select id="exc-dia" class="form-select form-select-sm" style="width:120px">
          <option value="0">Domingo</option><option value="1">Segunda</option><option value="2">Terça</option>
          <option value="3">Quarta</option><option value="4">Quinta</option><option value="5">Sexta</option><option value="6">Sábado</option>
        </select></div>
      <div><label class="form-label small mb-0">Início</label><input id="exc-inicio" type="time" class="form-control form-control-sm" style="width:100px"></div>
      <div><label class="form-label small mb-0">Fim</label><input id="exc-fim" type="time" class="form-control form-control-sm" style="width:100px"></div>
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarExcecao()"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
    </div>
    <div id="fb-excecoes" class="small mt-2"></div>

    <div class="grupo-titulo">Feriados (sem alerta na data)</div>
    <div class="table-responsive">
      <table>
        <thead><tr><th>Data</th><th>Loja</th><th></th></tr></thead>
        <tbody id="feriados"><tr><td colspan="3">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-end mt-2">
      <div><label class="form-label small mb-0">Data</label><input id="fer-data" type="date" class="form-control form-control-sm" style="width:150px"></div>
      <div><label class="form-label small mb-0">Loja</label><select id="fer-loja" class="form-select form-select-sm" style="width:130px"><option value="">Todas as lojas</option></select></div>
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarFeriado()"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
    </div>
    <div id="fb-feriados" class="small mt-2"></div>
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
          <option value="down">Só fora do ar</option><option value="quedas">Com reinício/queda curta (24h)</option>
          <option value="diverge">Diferente da Central</option>
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

function carregar(bg = false) {
  fetch('?action=listar' + (bg ? '&bg=1' : '')).then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-lista', d.erro || 'falha ao carregar', false);
    GRUPOS = d.grupos; DISP = d.dispositivos;
    montarFiltros(); renderGrupos(); renderLista(); renderLinks(d.links || []);
    const rd = d.rodada || {};
    const on = DISP.filter(x => +x.monitorar);
    const cnt = st => on.filter(x => x.status === st).length;
    document.getElementById('rodada').innerHTML = rd.ultima
      ? `Última rodada: <b>${H(rd.ultima.slice(11))}</b> · ${rd.qtd} pingado(s) em ${(rd.ms / 1000).toFixed(1).replace('.', ',')} s ·
         🟢 ${cnt('up')} no ar · 🔴 ${cnt('down')} fora · ⚪ ${cnt('desconhecido')} sem resposta ainda ·
         🟡 ${on.filter(x => +x.quedas_24h).length} com reinício nas 24h · ⚠️ ${on.filter(diverge).length} diferente da Central`
      : 'Nenhuma rodada ainda — o worker roda a cada ~30 s.';
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

  // mesmos GRUPOS/lojas, pros selects de exceção/feriado
  const ec = document.getElementById('exc-categoria'), el = document.getElementById('exc-loja'), ferl = document.getElementById('fer-loja');
  const selEc = ec.value, selEl = el.value, selFerl = ferl.value;
  ec.innerHTML = GRUPOS.map(g => `<option value="${H(g.grupo)}">${H(g.nome)}</option>`).join('');
  el.innerHTML = lojas.map(l => `<option>${H(l)}</option>`).join('');
  ferl.innerHTML = '<option value="">Todas as lojas</option>' + lojas.map(l => `<option>${H(l)}</option>`).join('');
  ec.value = selEc; el.value = selEl; ferl.value = selFerl;
}

/* ───────────────────── Silêncio por horário / feriado ───────────────────── */
let CAT_HORARIO = [], EXCECOES = [], FERIADOS = [];
const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

function carregarCatHorario() {
  fetch('?action=categorias_horario_listar').then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-cat-horario', d.erro || 'falha ao carregar', false);
    CAT_HORARIO = d.categorias; renderCatHorario();
  });
}
function renderCatHorario() {
  document.getElementById('cat-horario').innerHTML = CAT_HORARIO.map(c => {
    const nome = (GRUPOS.find(g => g.grupo === c.categoria) || {}).nome || c.categoria;
    return `<tr data-cat="${H(c.categoria)}">
      <td style="font-weight:600">${H(nome)}</td>
      <td><input type="time" class="form-control form-control-sm ch-ini" style="width:110px" value="${H((c.horario_inicio || '').slice(0, 5))}"></td>
      <td><input type="time" class="form-control form-control-sm ch-fim" style="width:110px" value="${H((c.horario_fim || '').slice(0, 5))}"></td>
      <td><input type="number" min="1" max="720" class="form-control form-control-sm num ch-max" value="${H(c.ligado_horas_max ?? '')}"></td>
      <td><button class="btn btn-primary btn-sm" onclick="salvarCatHorario('${H(c.categoria)}')">Salvar</button></td>
    </tr>`;
  }).join('');
}
function salvarCatHorario(categoria) {
  const tr = document.querySelector(`#cat-horario tr[data-cat="${CSS.escape(categoria)}"]`);
  post('categoria_horario_salvar', {
    categoria,
    horario_inicio: tr.querySelector('.ch-ini').value,
    horario_fim: tr.querySelector('.ch-fim').value,
    ligado_horas_max: tr.querySelector('.ch-max').value,
  }).then(d => {
    if (!d.ok) return fb('fb-cat-horario', d.erro || 'falha', false);
    fb('fb-cat-horario', 'Salvo.', true); carregarCatHorario();
  });
}

function carregarExcecoes() {
  fetch('?action=excecoes_listar').then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-excecoes', d.erro || 'falha ao carregar', false);
    EXCECOES = d.excecoes; renderExcecoes();
  });
}
function renderExcecoes() {
  const tb = document.getElementById('excecoes');
  if (!EXCECOES.length) { tb.innerHTML = '<tr><td colspan="6" class="text-muted">Nenhuma exceção cadastrada.</td></tr>'; return; }
  tb.innerHTML = EXCECOES.map(e => {
    const nome = (GRUPOS.find(g => g.grupo === e.categoria) || {}).nome || e.categoria;
    return `<tr>
      <td>${H(nome)}</td><td>${H(e.loja)}</td><td>${DIAS[e.dia_semana] || e.dia_semana}</td>
      <td>${H((e.horario_inicio || '').slice(0, 5))}</td><td>${H((e.horario_fim || '').slice(0, 5))}</td>
      <td><button class="btn btn-link btn-sm p-0 text-danger" onclick="excluirExcecao('${H(e.categoria)}','${H(e.loja)}',${e.dia_semana})">excluir</button></td>
    </tr>`;
  }).join('');
}
function salvarExcecao() {
  post('excecoes_salvar', {
    categoria: document.getElementById('exc-categoria').value,
    loja: document.getElementById('exc-loja').value,
    dia_semana: document.getElementById('exc-dia').value,
    horario_inicio: document.getElementById('exc-inicio').value,
    horario_fim: document.getElementById('exc-fim').value,
  }).then(d => {
    if (!d.ok) return fb('fb-excecoes', d.erro || 'falha', false);
    fb('fb-excecoes', 'Exceção salva.', true); carregarExcecoes();
  });
}
function excluirExcecao(categoria, loja, diaSemana) {
  if (!confirm('Excluir essa exceção?')) return;
  post('excecoes_excluir', { categoria, loja, dia_semana: diaSemana }).then(d => {
    if (!d.ok) return fb('fb-excecoes', d.erro || 'falha', false);
    carregarExcecoes();
  });
}

function carregarFeriados() {
  fetch('?action=feriados_listar').then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-feriados', d.erro || 'falha ao carregar', false);
    FERIADOS = d.feriados; renderFeriados();
  });
}
function renderFeriados() {
  const tb = document.getElementById('feriados');
  if (!FERIADOS.length) { tb.innerHTML = '<tr><td colspan="3" class="text-muted">Nenhum feriado cadastrado.</td></tr>'; return; }
  tb.innerHTML = FERIADOS.map(f => `<tr>
      <td>${H(f.data)}</td><td>${H(f.loja) || '<span class="text-muted">Todas</span>'}</td>
      <td><button class="btn btn-link btn-sm p-0 text-danger" onclick="excluirFeriado('${H(f.data)}','${H(f.loja)}')">excluir</button></td>
    </tr>`).join('');
}
function salvarFeriado() {
  const data = document.getElementById('fer-data').value;
  if (!data) return fb('fb-feriados', 'escolha uma data', false);
  post('feriados_salvar', { data, loja: document.getElementById('fer-loja').value }).then(d => {
    if (!d.ok) return fb('fb-feriados', d.erro || 'falha', false);
    fb('fb-feriados', 'Feriado salvo.', true); carregarFeriados();
  });
}
function excluirFeriado(data, loja) {
  if (!confirm('Excluir esse feriado?')) return;
  post('feriados_excluir', { data, loja }).then(d => {
    if (!d.ok) return fb('fb-feriados', d.erro || 'falha', false);
    carregarFeriados();
  });
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
      <td><input type="number" min="1" max="5" class="form-control form-control-sm num g-pac" value="${g.pacotes ?? 1}"></td>
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
  const itens = DISP.filter(x => (!fg || x.grupo === fg) && (!fl || x.loja === fl) && filtroMostrar(x, fm)
    && (!busca || (x.nome + ' ' + (x.ips || '') + ' ' + (x.ip_fixo || '')).toLowerCase().includes(busca)));
  if (!itens.length) { document.getElementById('lista').innerHTML = '<div class="text-muted">Nenhum equipamento com esse filtro.</div>'; return; }

  const porGrupo = {};
  itens.forEach(x => (porGrupo[x.grupo_nome || x.grupo] ??= []).push(x));
  let html = '';
  for (const [grupo, lista] of Object.entries(porGrupo)) {
    html += `<div class="grupo-titulo">${H(grupo)} <span class="text-muted fw-normal">(${lista.length})</span></div>
      <table><thead><tr><th style="width:70px">Monitorar</th><th>Status</th><th>Nome</th><th>Loja</th><th>IP</th><th>Latência</th><th>Reinícios 24h</th><th title="O que a Central de Alertas está vendo (atualiza a cada rodada)">Central</th><th>IP fixo</th><th title="Para equipamento que bloqueia ping: testa só esta porta TCP">Porta TCP</th><th>Origem</th><th></th></tr></thead><tbody>`;
    for (const x of lista) {
      const outros = (x.ips || '').split(',').filter(ip => ip && ip !== x.ip);
      html += `<tr>
        <td><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" ${+x.monitorar ? 'checked' : ''}
             ${x.duplicado_de ? 'disabled title="IP duplicado — resolva antes de monitorar"' : ''} onchange="setMonitorar(${x.id}, this.checked)"></div></td>
        <td class="text-nowrap">${statusHtml(x)}</td>
        <td style="font-weight:600">${H(x.nome)}${x.duplicado_de ? `<div class="dup"><i class="bi bi-exclamation-triangle"></i> mesmo IP de ${H(x.duplicado_de)} (registro antigo?)</div>` : ''}</td>
        <td>${H(x.loja) || '<span class="text-muted">—</span>'}</td>
        <td>${H(x.ip_efetivo) || '<span class="text-danger">sem IP</span>'}${outros.length ? `<div class="ips-extra" title="IPs antigos/alternativos do inventário — o monitor testa todos">+ ${H(outros.join(', '))}</div>` : ''}</td>
        <td>${x.latencia_ms !== null && +x.monitorar ? H(Math.round(x.latencia_ms)) + ' ms' : '<span class="text-muted">—</span>'}</td>
        <td>${+x.quedas_24h ? `<a href="#" onclick="verQuedas(${x.id});return false">🟡 ${x.quedas_24h}</a>` : '<span class="text-muted">0</span>'}</td>
        <td>${dudeHtml(x)}</td>
        <td>${x.origem === 'manual' ? '<span class="text-muted">—</span>' :
             `<input class="form-control form-control-sm ip-fixo" value="${H(x.ip_fixo || '')}" placeholder="usar do inventário"
                     onchange="setIpFixo(${x.id}, this)">`}</td>
        <td><input class="form-control form-control-sm ip-fixo" style="width:70px" value="${H(x.porta_tcp || '')}" placeholder="ping"
                   title="Vazio = ping, e se falhar testa sozinho as portas 5900 (VNC) e 445. Preencha só se o equipamento usar outra porta"
                   onchange="setPortaTcp(${x.id}, this)"></td>
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

// ── Etapa 2: status do ping (modo sombra) ──
const desdeTxt = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '';
function statusHtml(x) {
  if (!+x.monitorar) return '<span class="text-muted">⚪ não monitorado</span>';
  if (x.status === 'up')   return `🟢 no ar <span class="estimativa">desde ${desdeTxt(x.status_desde)}</span>`;
  if (x.status === 'down') return `🔴 <b>fora</b> <span class="estimativa">desde ${desdeTxt(x.status_desde)}</span>`;
  return +x.falhas_seguidas ? `⚪ sem resposta (${x.falhas_seguidas}x)` : '⚪ aguardando 1º ping';
}
// "diferente da Central" só conta quando os dois têm opinião formada
const diverge = x => +x.monitorar && x.dude_status && (x.status === 'up' || x.status === 'down') && x.status !== x.dude_status;
function dudeHtml(x) {
  if (!x.dude_status) return '<span class="text-muted">—</span>';
  const t = x.dude_status === 'up' ? 'no ar' : 'fora';
  return diverge(x) ? `<span class="text-warning fw-semibold" title="A Central ainda mostra diferente do ping — corrige na próxima rodada">⚠️ ${t}</span>` : `<span class="text-muted">${t}</span>`;
}
function filtroMostrar(x, fm) {
  if (fm === '') return true;
  if (fm === 'down') return +x.monitorar && x.status === 'down';
  if (fm === 'quedas') return +x.quedas_24h > 0;
  if (fm === 'diverge') return diverge(x);
  return String(+x.monitorar) === fm;
}
function verQuedas(id) {
  const x = DISP.find(y => +y.id === id);
  fetch('?action=quedas&id=' + id).then(r => r.json()).then(d => {
    if (!d.ok) return fb('fb-lista', d.erro || 'falha', false);
    const linhas = d.quedas.map(q => `• ${desdeTxt(q.inicio)} → ${q.fim.slice(11, 16)} (fora ~${Math.max(1, Math.round(q.segundos / 60))} min, ${q.falhas} ping(s) sem resposta)`);
    alert(`Reinícios / quedas curtas — ${x ? x.nome : id}\n\n` + (linhas.join('\n') || 'nenhuma'));
  });
}

function salvarGrupo(grupo) {
  const tr = document.querySelector(`tr[data-grupo="${CSS.escape(grupo)}"]`);
  post('grupo_salvar', {
    grupo,
    intervalo_seg: tr.querySelector('.g-int').value,
    falhas_para_cair: tr.querySelector('.g-falhas').value,
    sucessos_para_voltar: tr.querySelector('.g-suc').value,
    pacotes: tr.querySelector('.g-pac').value,
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

function renderLinks(links) {
  const cor = { up: '🟢 no ar', down: '🔴 fora', alerta: '🟡 perda/latência' };
  document.getElementById('links').innerHTML = links.length ? links.map(l => `<tr>
      <td>${H(l.loja)}</td>
      <td style="font-weight:600">${+l.principal ? '⭐ ' : ''}${+l.padrao ? '➜ ' : ''}${H(l.nome)}
        ${l.descricao && l.descricao.toLowerCase() !== l.nome.toLowerCase() ? `<span class="text-muted fw-normal">(${H(l.descricao)})</span>` : ''}</td>
      <td>${cor[l.status] || H(l.status)}</td>
      <td>${l.rtt_ms !== null ? H(Math.round(l.rtt_ms)) + ' ms' : '—'}</td>
      <td>${l.perda !== null ? H(l.perda) + '%' : '—'}</td>
      <td>${H(desdeTxt(l.status_desde))}</td></tr>`).join('')
    : '<tr><td colspan="6" class="text-muted">Nenhum link lido ainda (pfSense sem cadastro em pfSense Lojas, ou links atrás de MikroTik).</td></tr>';
}

function setPortaTcp(id, input) {
  post('set_porta_tcp', { id, porta: input.value.trim() }).then(d => {
    if (!d.ok) { fb('fb-lista', d.erro || 'falha', false); return; }
    fb('fb-lista', input.value.trim() ? 'Porta TCP salva — esse equipamento passa a ser testado só nela.' : 'Voltou a usar ping.', true);
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
carregarCatHorario(); carregarExcecoes(); carregarFeriados();
// status muda a cada rodada; bg=1 = não conta como atividade pro logout por inatividade.
// Não atualiza com alguém digitando/escolhendo num campo (perderia o que foi digitado).
setInterval(() => {
  const foco = document.activeElement;
  if (document.hidden || (foco && ['INPUT', 'SELECT', 'TEXTAREA'].includes(foco.tagName))) return;
  carregar(true);
}, 60000);
</script>
</body>
</html>
