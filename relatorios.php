<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Busca dados do GLPI ─────────────────────────────────────────
function glpi_api(string $endpoint, string $token): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) && !isset($r['ERROR']) ? $r : [];
}

// Abre sessão admin
$auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch    = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r     = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';

// Parâmetros de filtro
$dt_ini = $_GET['dt_ini'] ?? date('Y-m-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-m-d');
$entidade_filtro = $_GET['entidade'] ?? '';

// ── Search API: tickets do período + nome do técnico em uma chamada ──
// Campos: 2=ID, 1=Título, 5=Técnico, 12=Status, 15=Abertura, 16=Fechamento,
//         80=Entidade, 10=Urgência, 7=Categoria
$fd = 'forcedisplay[0]=2&forcedisplay[1]=1&forcedisplay[2]=5&forcedisplay[3]=12' .
      '&forcedisplay[4]=15&forcedisplay[5]=16&forcedisplay[6]=80&forcedisplay[7]=10&forcedisplay[8]=7';

// Filtra abertura OU fechamento dentro do período (cobre abertos e fechados)
$crit_periodo =
    'criteria[0][field]=15&criteria[0][searchtype]=morethan&criteria[0][value]=' . urlencode($dt_ini . ' 00:00:00') .
    '&criteria[1][link]=AND&criteria[1][field]=15&criteria[1][searchtype]=lessthan&criteria[1][value]=' . urlencode($dt_fim . ' 23:59:59');

$sf = "$fd&$crit_periodo&range=0-1000&order=DESC&sort=15";
$res_all = glpi_api("search/Ticket?$sf", $token);

// Busca separada para chamados fechados no período (por closedate = field 16)
$crit_fechados =
    'criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=6' .
    '&criteria[1][link]=AND&criteria[1][field]=16&criteria[1][searchtype]=morethan&criteria[1][value]=' . urlencode($dt_ini . ' 00:00:00') .
    '&criteria[2][link]=AND&criteria[2][field]=16&criteria[2][searchtype]=lessthan&criteria[2][value]=' . urlencode($dt_fim . ' 23:59:59');
$sf_fechados = "$fd&$crit_fechados&range=0-1000&order=DESC&sort=16";
$res_fechados = glpi_api("search/Ticket?$sf_fechados", $token);

// Evolução mensal: só precisa de ID, data de abertura (sem filtro de período)
$sf_evolucao = 'forcedisplay[0]=2&forcedisplay[1]=15&range=0-2000&order=DESC&sort=15';
$res_evolucao = glpi_api("search/Ticket?$sf_evolucao", $token);

// SLA: mesmos campos, filtra apenas abertos (status notold = 1,2,3,4)
$sf_sla = 'forcedisplay[0]=2&forcedisplay[1]=1&forcedisplay[2]=5&forcedisplay[3]=12' .
          '&forcedisplay[4]=15&forcedisplay[5]=80&forcedisplay[6]=10' .
          '&criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=notold' .
          '&range=0-500&order=ASC&sort=15';
$res_sla = glpi_api("search/Ticket?$sf_sla", $token);

$entidades_raw = glpi_api('Entity?range=0-100', $token);

// Encerra sessão
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
curl_exec($ch); curl_close($ch);

// ── Normaliza linha da Search API → array ticket ───────────────
function norm_ticket(array $row): array {
    $tec = $row[5] ?? '';
    if (is_array($tec)) $tec = implode(', ', array_filter(array_map('trim', $tec)));
    $cat = $row[7] ?? '';
    if (is_array($cat)) $cat = implode(', ', array_filter(array_map('trim', $cat)));
    return [
        'id'          => (int)($row[2]  ?? 0),
        'name'        => (string)($row[1]  ?? ''),
        '_technician' => trim((string)$tec),
        'status'      => (int)($row[12] ?? 0),
        'date'        => (string)($row[15] ?? ''),
        'closedate'   => (string)($row[16] ?? ''),
        'entities_id' => (string)($row[80] ?? ''),
        'urgency'     => (int)($row[10] ?? 3),
        'categoria'   => trim((string)$cat) ?: '(Sem categoria)',
    ];
}

// ── Normaliza resultados das buscas ───────────────────────────
function norm_rows(array $res): array {
    $out = [];
    foreach ((array)($res['data'] ?? []) as $row) {
        $t = norm_ticket($row);
        if ($t['id']) $out[] = $t;
    }
    return $out;
}

$mapa_atendente_full = [];

// Tickets do período (abertura dentro do range)
$tickets_raw = norm_rows($res_all);
foreach ($tickets_raw as $t) {
    if ($t['_technician']) $mapa_atendente_full[$t['id']] = $t['_technician'];
}

// Tickets fechados no período (por closedate) — busca separada
$fechados_raw = norm_rows($res_fechados);
foreach ($fechados_raw as $t) {
    if ($t['_technician']) $mapa_atendente_full[$t['id']] = $t['_technician'];
}

// SLA
$sla_raw = norm_rows($res_sla);

// ── Filtra $tickets_raw pela entidade ─────────────────────────
// (data já filtrada pelo GLPI; só aplica filtro de entidade local)
$tickets = array_values(array_filter($tickets_raw, function($t) use ($entidade_filtro) {
    $ent = $t['entities_id'];
    if ($ent === '' || strtolower($ent) === 'entidade raiz') return false;
    if ($entidade_filtro && $ent !== $entidade_filtro) return false;
    return true;
}));
$total = count($tickets);

// ── Mapa atendente filtrado pelo período ──────────────────────
$mapa_atendente = [];
foreach ($tickets as $t) {
    if ($t['_technician']) $mapa_atendente[$t['id']] = $t['_technician'];
}

// 1. Por atendente
$por_atendente = [];
foreach ($tickets as $t) {
    $tec = $t['_technician'] ?: 'Sem atendente';
    $por_atendente[$tec] = ($por_atendente[$tec] ?? 0) + 1;
}
arsort($por_atendente);

// 2. Por entidade (loja)
$por_entidade = [];
foreach ($tickets as $t) {
    $ent = $t['entities_id'] ?: 'Entidade raiz';
    $por_entidade[$ent] = ($por_entidade[$ent] ?? 0) + 1;
}
arsort($por_entidade);

// 3. Por categoria
$por_categoria = [];
foreach ($tickets as $t) {
    $cat = $t['categoria'];
    $por_categoria[$cat] = ($por_categoria[$cat] ?? 0) + 1;
}
arsort($por_categoria);

// 4. Chamados FECHADOS no período (já filtrados pelo GLPI por closedate)
$fechados = array_values(array_filter($fechados_raw, function($t) use ($entidade_filtro) {
    $ent = $t['entities_id'];
    if ($ent === '' || strtolower($ent) === 'entidade raiz') return false;
    if ($entidade_filtro && $ent !== $entidade_filtro) return false;
    return true;
}));
$total_fechados = count($fechados);

$por_atendente_fechados = [];
foreach ($fechados as $t) {
    $tec = $t['_technician'] ?: 'Sem atendente';
    $por_atendente_fechados[$tec] = ($por_atendente_fechados[$tec] ?? 0) + 1;
}
arsort($por_atendente_fechados);

// 5. Por hora do dia
$por_hora = array_fill(0, 24, 0);
foreach ($tickets as $t) {
    $hora = (int)substr($t['date'] ?? '00:00:00', 11, 2);
    $por_hora[$hora]++;
}

// 5. Por dia da semana
$por_dia = [0=>0,1=>0,2=>0,3=>0,4=>0,5=>0,6=>0];
foreach ($tickets as $t) {
    if (!empty($t['date'])) {
        $dow = (int)date('w', strtotime($t['date']));
        $por_dia[$dow]++;
    }
}

// 6. Evolução mensal — usa busca sem filtro de data ($res_evolucao)
$por_mes = [];
foreach ((array)($res_evolucao['data'] ?? []) as $row) {
    $mes = substr((string)($row[15] ?? ''), 0, 7);
    if (!$mes) continue;
    $por_mes[$mes] = ($por_mes[$mes] ?? 0) + 1;
}
ksort($por_mes);

// 7. Chamados com múltiplos atendentes
$multiplos = 0; // placeholder — seria via Ticket_User

// Entidades para filtro — exclui entidade raiz (id=0)
$entidades_lista = [];
foreach ($entidades_raw as $e) {
    if (!isset($e['id'])) continue;
    if ((int)$e['id'] === 0) continue; // Exclui entidade raiz
    $nome = $e['completename'] ?? $e['name'] ?? '';
    if (strtolower($nome) === 'entidade raiz' || $nome === '') continue;
    $entidades_lista[] = ['id' => $nome, 'nome' => $nome];
}

// ── Monitor SLA ───────────────────────────────────────────────
// Threshold em horas por urgência (1=Muito baixa … 5=Muito alta)
$sla_thresh = [1 => 24, 2 => 12, 3 => 8, 4 => 4, 5 => 2];
$agora_ts   = time();
$sla_status_label = [1=>'Novo', 2=>'Em atendimento', 3=>'Planejado', 4=>'Em espera'];
$sla_urg_label    = [1=>'Muito baixa', 2=>'Baixa', 3=>'Média', 4=>'Alta', 5=>'Muito alta'];

$sla_dados = [];
foreach ($sla_raw as $t) {
    if (!isset($t['id'])) continue;
    $st = (int)($t['status'] ?? 0);
    if (!in_array($st, [1, 2, 3])) continue; // exclui Em espera e fechados
    $abertura = strtotime($t['date'] ?? '');
    if (!$abertura) continue;
    $horas    = round(($agora_ts - $abertura) / 3600, 1);
    $urg      = max(1, min(5, (int)($t['urgency'] ?? 3)));
    $thresh   = $sla_thresh[$urg];
    $cor = $horas <= $thresh * 0.5 ? 'verde' : ($horas <= $thresh ? 'amarelo' : 'vermelho');
    $sla_dados[] = [
        'id'        => $t['id'],
        'titulo'    => $t['name'] ?? '(sem título)',
        'status'    => $sla_status_label[$st] ?? $st,
        'urgencia'  => $sla_urg_label[$urg],
        'urg_n'     => $urg,
        'entidade'  => $t['entities_id'] ?? '—',
        'abertura'  => substr($t['date'] ?? '', 0, 16),
        'horas'     => $horas,
        'thresh'    => $thresh,
        'cor'       => $cor,
        'atendente' => $t['_technician'] ?: ($mapa_atendente_full[$t['id']] ?? '—'),
    ];
}
usort($sla_dados, fn($a,$b) =>
    ['vermelho'=>0,'amarelo'=>1,'verde'=>2][$a['cor']] <=>
    ['vermelho'=>0,'amarelo'=>1,'verde'=>2][$b['cor']] ?: $b['horas'] <=> $a['horas']
);
$sla_verde    = count(array_filter($sla_dados, fn($x)=>$x['cor']==='verde'));
$sla_amarelo  = count(array_filter($sla_dados, fn($x)=>$x['cor']==='amarelo'));
$sla_vermelho = count(array_filter($sla_dados, fn($x)=>$x['cor']==='vermelho'));

// JSON para JS
$json_atendente         = json_encode(array_map(fn($k,$v)=>['nome'=>$k,'total'=>$v], array_keys($por_atendente), $por_atendente));
$json_atendente_fechados= json_encode(array_map(fn($k,$v)=>['nome'=>$k,'total'=>$v], array_keys($por_atendente_fechados), $por_atendente_fechados));
$json_entidade          = json_encode(array_map(fn($k,$v)=>['nome'=>$k,'total'=>$v], array_keys($por_entidade), $por_entidade));
$json_categoria         = json_encode(array_map(fn($k,$v)=>['nome'=>$k,'total'=>$v], array_keys($por_categoria), $por_categoria));
$json_hora              = json_encode(array_values($por_hora));
$json_dia               = json_encode(array_values($por_dia));
$json_mes               = json_encode(array_map(fn($k,$v)=>['mes'=>$k,'total'=>$v], array_keys($por_mes), $por_mes));
$total_mes_todos        = array_sum($por_mes);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Painel de Chamados</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root {
      --bg: #050d1a;
      --bg2: #0a1628;
      --bg3: #0d1f3c;
      --accent: #00bfff;
      --gold: #ffd700;
      --green: #00ff88;
      --text: #c8d8f0;
      --border: #1a3a6a;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Segoe UI', sans-serif;
      min-height: 100vh;
    }

    /* Topbar */
    .topbar {
      background: linear-gradient(90deg, #020a18, #0d1f3c, #020a18);
      border-bottom: 2px solid var(--accent);
      padding: .75rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 100;
    }
    .topbar .brand {
      color: var(--gold);
      font-size: 1.1rem;
      font-weight: 900;
      letter-spacing: .1em;
      text-transform: uppercase;
    }
    .topbar a {
      color: var(--accent); text-decoration: none; font-size: .82rem;
      border: 1px solid var(--accent); border-radius: 6px; padding: .3rem .75rem;
      transition: all .2s;
    }
    .topbar a:hover { background: var(--accent); color: #000; }

    /* Filtros */
    .filtros {
      background: var(--bg2);
      border-bottom: 1px solid var(--border);
      padding: .75rem 1.5rem;
      display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;
    }
    .filtros label { font-size: .78rem; color: var(--gold); font-weight: 700; margin-bottom: .2rem; display: block; }
    .filtros input, .filtros select {
      background: var(--bg3); color: var(--text);
      border: 1px solid var(--border); border-radius: 6px;
      padding: .35rem .75rem; font-size: .82rem;
      color-scheme: dark;
    }
    .btn-filtrar {
      background: var(--accent); color: #000;
      border: none; border-radius: 6px; padding: .4rem 1.2rem;
      font-size: .85rem; font-weight: 700; cursor: pointer;
      transition: opacity .2s; align-self: flex-end;
    }
    .btn-filtrar:hover { opacity: .85; }

    /* Tabs de painéis */
    .tabs {
      display: flex; flex-wrap: wrap; gap: .3rem;
      padding: .75rem 1.5rem;
      background: var(--bg2);
      border-bottom: 1px solid var(--border);
    }
    .tab-btn {
      background: var(--bg3); color: var(--text);
      border: 1px solid var(--border); border-radius: 6px;
      padding: .4rem 1rem; font-size: .8rem; font-weight: 600;
      cursor: pointer; transition: all .2s;
    }
    .tab-btn.active { background: var(--accent); color: #000; border-color: var(--accent); }
    .tab-btn:hover:not(.active) { border-color: var(--accent); color: var(--accent); }

    /* Painéis */
    .painel { display: none; padding: 1.25rem 1.5rem; }
    .painel.active { display: block; }

    .painel-title {
      text-align: center; margin-bottom: 1.5rem;
      color: var(--gold);
      font-size: 1.4rem; font-weight: 900;
      text-transform: uppercase; letter-spacing: .08em;
      text-shadow: 0 0 20px rgba(255,215,0,.4);
    }

    /* Grid de cards */
    .cards-row { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem; }

    .kpi-card {
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: 10px; padding: 1rem 1.5rem;
      min-width: 140px; text-align: center;
    }
    .kpi-card .kpi-label { font-size: .72rem; color: var(--text); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .3rem; }
    .kpi-card .kpi-val { font-size: 2.5rem; font-weight: 900; line-height: 1; }
    .kpi-card.green .kpi-val { color: var(--green); }
    .kpi-card.gold  .kpi-val { color: var(--gold); }
    .kpi-card.blue  .kpi-val { color: var(--accent); }

    /* Chart containers */
    .chart-card {
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: 10px; padding: 1rem;
      margin-bottom: 1rem;
    }
    .chart-card h3 {
      color: var(--accent); font-size: .9rem; font-weight: 700;
      margin-bottom: .75rem; text-transform: uppercase; letter-spacing: .05em;
    }
    .chart-wrap { position: relative; }

    /* Tabela atendente */
    .tabela-atendente {
      width: 100%; border-collapse: collapse; font-size: .83rem;
    }
    .tabela-atendente th {
      background: #1a2a4a; color: var(--gold);
      padding: .5rem .75rem; text-align: left; font-size: .75rem;
      text-transform: uppercase;
    }
    .tabela-atendente td { padding: .45rem .75rem; border-bottom: 1px solid #0d1f3c; }
    .tabela-atendente tr:nth-child(odd) td { background: rgba(0,191,255,.04); }
    .tabela-atendente .bar-inline {
      height: 16px; background: linear-gradient(90deg, #d93025, #ff5252);
      border-radius: 3px; display: inline-block; min-width: 4px;
      transition: width .5s;
    }
    .tabela-atendente tfoot td { font-weight: 700; color: var(--gold); border-top: 2px solid var(--border); }

    /* Grid dois painéis */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }

    @media(max-width:900px) {
      .two-col, .three-col { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-bar-chart-fill me-2"></i>Painel de Chamados</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Filtros -->
<form method="GET">
<div class="filtros">
  <div>
    <label>Data Início</label>
    <input type="date" name="dt_ini" value="<?= $dt_ini ?>"/>
  </div>
  <div>
    <label>Data Fim</label>
    <input type="date" name="dt_fim" value="<?= $dt_fim ?>"/>
  </div>
  <div>
    <label>Entidade</label>
    <select name="entidade" style="min-width:180px">
      <option value="">Selecionar tudo</option>
      <?php foreach ($entidades_lista as $e): ?>
        <option value="<?= htmlspecialchars($e['id']) ?>" <?= $entidade_filtro===$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn-filtrar"><i class="bi bi-search me-1"></i>Filtrar</button>
  <a href="relatorios.php" style="align-self:flex-end;padding:.4rem .9rem;border-radius:6px;border:1px solid #1a3a6a;color:#c8d8f0;text-decoration:none;font-size:.82rem">Limpar</a>
</div>
</form>

<!-- Tabs -->
<div class="tabs">
  <button class="tab-btn active" onclick="showTab('atendimentos')">📊 Atendimentos</button>
  <button class="tab-btn" onclick="showTab('lojas')">🏪 Lojas</button>
  <button class="tab-btn" onclick="showTab('categorias')">🏷️ Categorias</button>
  <button class="tab-btn" onclick="showTab('monitor')">⏰ Monitor Hora/Dia</button>
  <button class="tab-btn" onclick="showTab('evolucao')">📈 Evolução Mensal</button>
  <button class="tab-btn" onclick="showTab('sla')" style="border-left:2px solid #e53935">🚦 Monitor SLA</button>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 1: ATENDIMENTOS                                        -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel active" id="painel-atendimentos">
  <div class="painel-title">Painel de Chamados — Atendimentos</div>

  <div class="cards-row">
    <div class="kpi-card green">
      <div class="kpi-label">Total Abertos</div>
      <div class="kpi-val"><?= $total ?></div>
    </div>
    <div class="kpi-card" style="border-color:#00ff88">
      <div class="kpi-label">Total Fechados</div>
      <div class="kpi-val" style="color:#00ff88"><?= $total_fechados ?></div>
    </div>
  </div>

  <!-- Chamados abertos por atendente -->
  <div class="two-col" style="margin-bottom:1rem">
    <div class="chart-card">
      <h3>Chamados Abertos por Atendente</h3>
      <table class="tabela-atendente">
        <thead><tr><th>Atendente</th><th>Chamados</th><th>%</th></tr></thead>
        <tbody>
          <?php $max_at = max(array_values($por_atendente) ?: [1]); ?>
          <?php foreach ($por_atendente as $nome_at => $qtd): ?>
          <tr>
            <td><?= htmlspecialchars($nome_at) ?></td>
            <td>
              <span class="bar-inline" style="width:<?= round(($qtd/$max_at)*120) ?>px"></span>
              <strong style="color:#fff;margin-left:6px"><?= $qtd ?></strong>
            </td>
            <td style="color:#9ca3af"><?= $total > 0 ? round($qtd/$total*100) : 0 ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td><?= $total ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
    <div class="chart-card">
      <h3>Chamados Abertos — Atendentes</h3>
      <div class="chart-wrap" style="height:280px">
        <canvas id="chartAtendente"></canvas>
      </div>
    </div>
  </div>

  <!-- Chamados FECHADOS por atendente -->
  <div class="two-col">
    <div class="chart-card" style="border-color:#1e8e3e">
      <h3 style="color:#00ff88">Chamados Fechados por Atendente <small style="font-size:.75rem;color:#9ca3af">(por data de fechamento)</small></h3>
      <table class="tabela-atendente">
        <thead><tr><th>Atendente</th><th>Fechados</th><th>%</th></tr></thead>
        <tbody>
          <?php if (empty($por_atendente_fechados)): ?>
          <tr><td colspan="3" style="color:#9ca3af;text-align:center;padding:1rem">Nenhum chamado fechado no período</td></tr>
          <?php else: ?>
          <?php $max_f = max(array_values($por_atendente_fechados) ?: [1]); ?>
          <?php foreach ($por_atendente_fechados as $nome_at => $qtd): ?>
          <tr>
            <td><?= htmlspecialchars($nome_at) ?></td>
            <td>
              <span class="bar-inline" style="width:<?= round(($qtd/$max_f)*120) ?>px;background:linear-gradient(90deg,#0f9d58,#00ff88)"></span>
              <strong style="color:#fff;margin-left:6px"><?= $qtd ?></strong>
            </td>
            <td style="color:#9ca3af"><?= $total_fechados > 0 ? round($qtd/$total_fechados*100) : 0 ?>%</td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td><?= $total_fechados ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
    <div class="chart-card" style="border-color:#1e8e3e">
      <h3 style="color:#00ff88">Chamados Fechados — Atendentes</h3>
      <div class="chart-wrap" style="height:280px">
        <canvas id="chartAtendenteFechados"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 2: LOJAS                                               -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel" id="painel-lojas">
  <div class="painel-title">Painel de Chamados — Lojas</div>

  <div class="cards-row">
    <div class="kpi-card green">
      <div class="kpi-label">Total</div>
      <div class="kpi-val"><?= $total ?></div>
    </div>
  </div>

  <div class="two-col">
    <div class="chart-card">
      <h3>Distribuição por Loja/Entidade</h3>
      <div class="chart-wrap" style="height:340px">
        <canvas id="chartLojas"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <h3>Chamados por Entidade</h3>
      <table class="tabela-atendente">
        <thead><tr><th>Entidade</th><th>Total</th><th>%</th></tr></thead>
        <tbody>
          <?php foreach ($por_entidade as $ent => $qtd): ?>
          <tr>
            <td><?= htmlspecialchars($ent) ?></td>
            <td><strong style="color:#fff"><?= $qtd ?></strong></td>
            <td style="color:#9ca3af"><?= $total > 0 ? round($qtd/$total*100,1) : 0 ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td><?= $total ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 3: CATEGORIAS                                          -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel" id="painel-categorias">
  <div class="painel-title">Painel de Chamados — Categorias</div>

  <div class="chart-card">
    <h3>Chamados por Categoria</h3>
    <div class="chart-wrap" style="height:<?= max(300, count($por_categoria)*30) ?>px">
      <canvas id="chartCategorias"></canvas>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 4: MONITOR HORA/DIA                                    -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel" id="painel-monitor">
  <div class="painel-title">Monitor de Chamados por Hora — Dia Semana</div>

  <div class="chart-card">
    <h3>Chamados por Hora do Dia</h3>
    <div class="chart-wrap" style="height:260px">
      <canvas id="chartHora"></canvas>
    </div>
  </div>

  <div class="chart-card">
    <h3>Chamados por Dia da Semana</h3>
    <div class="chart-wrap" style="height:260px">
      <canvas id="chartDia"></canvas>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 5: EVOLUÇÃO MENSAL                                     -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel" id="painel-evolucao">
  <div class="painel-title">Evolução Mensal de Chamados</div>

  <div class="cards-row">
    <div class="kpi-card gold">
      <div class="kpi-label">Total Geral</div>
      <div class="kpi-val"><?= $total_mes_todos ?></div>
    </div>
  </div>

  <div class="chart-card">
    <h3>Chamados por Mês</h3>
    <div class="chart-wrap" style="height:360px">
      <canvas id="chartMes"></canvas>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- PAINEL 6: MONITOR SLA                                         -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="painel" id="painel-sla">
  <div class="painel-title" style="border-left-color:#e53935">🚦 Monitor SLA — Chamados Abertos em Tempo Real</div>

  <!-- KPIs semáforo -->
  <div class="cards-row">
    <div class="kpi-card green" style="background:linear-gradient(135deg,#0d2b1a,#0d3321);border-color:#1e8e3e">
      <div class="kpi-label">🟢 No Prazo</div>
      <div class="kpi-val" style="color:#00ff88"><?= $sla_verde ?></div>
    </div>
    <div class="kpi-card" style="background:linear-gradient(135deg,#2b2200,#332b00);border-color:#f57c00">
      <div class="kpi-label">🟡 Atenção</div>
      <div class="kpi-val" style="color:#ffd700"><?= $sla_amarelo ?></div>
    </div>
    <div class="kpi-card" style="background:linear-gradient(135deg,#2b0000,#3d0a0a);border-color:#c62828">
      <div class="kpi-label">🔴 Atrasado</div>
      <div class="kpi-val" style="color:#ff4444"><?= $sla_vermelho ?></div>
    </div>
    <div class="kpi-card" style="border-color:#00bfff">
      <div class="kpi-label">Total Abertos</div>
      <div class="kpi-val"><?= count($sla_dados) ?></div>
    </div>
  </div>

  <!-- Legenda thresholds -->
  <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.75rem;color:#9ca3af">
    <span>Thresholds por urgência:</span>
    <span style="color:#00ff88">🟢 Verde = &lt; 50% do prazo</span>
    <span style="color:#ffd700">🟡 Amarelo = 50–100% do prazo</span>
    <span style="color:#ff4444">🔴 Vermelho = Prazo estourado</span>
    <span style="color:#9ca3af">| Prazos: Alta=4h · Média=8h · Baixa=12h</span>
  </div>

  <!-- Tabela de chamados -->
  <?php if (empty($sla_dados)): ?>
    <div style="text-align:center;padding:3rem;color:#9ca3af">
      <i class="bi bi-check-circle-fill" style="font-size:3rem;color:#00ff88;display:block;margin-bottom:.75rem"></i>
      Nenhum chamado aberto ativo no momento.
    </div>
  <?php else: ?>
  <div class="chart-card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
      <thead>
        <tr style="background:#0a1628;color:#9ca3af;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
          <th style="padding:.6rem 1rem;text-align:left">#</th>
          <th style="padding:.6rem 1rem;text-align:left">Título</th>
          <th style="padding:.6rem 1rem;text-align:left">Atendente</th>
          <th style="padding:.6rem 1rem;text-align:left">Entidade</th>
          <th style="padding:.6rem 1rem;text-align:left">Urgência</th>
          <th style="padding:.6rem 1rem;text-align:left">Status</th>
          <th style="padding:.6rem 1rem;text-align:left">Aberto em</th>
          <th style="padding:.6rem 1rem;text-align:right">Tempo</th>
          <th style="padding:.6rem 1rem;text-align:right">Prazo</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sla_dados as $s):
        $bg_row = match($s['cor']) {
            'vermelho' => 'background:rgba(220,38,38,.12)',
            'amarelo'  => 'background:rgba(234,179,8,.08)',
            default    => '',
        };
        $dot_col = match($s['cor']) {
            'vermelho' => '#ff4444',
            'amarelo'  => '#ffd700',
            default    => '#00ff88',
        };
        $urg_col = match($s['urg_n']) {
            5 => '#ff4444', 4 => '#ffa500', 3 => '#ffd700', default => '#9ca3af'
        };
      ?>
      <tr style="border-bottom:1px solid #1a3a6a;<?= $bg_row ?>">
        <td style="padding:.55rem 1rem;color:#9ca3af"><?= $s['id'] ?></td>
        <td style="padding:.55rem 1rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($s['titulo']) ?>">
          <span style="color:<?= $dot_col ?>;margin-right:.4rem">●</span>
          <?= htmlspecialchars($s['titulo']) ?>
        </td>
        <td style="padding:.55rem 1rem;color:#c8d8f0"><?= htmlspecialchars($s['atendente']) ?></td>
        <td style="padding:.55rem 1rem;color:#9ca3af;font-size:.75rem"><?= htmlspecialchars($s['entidade']) ?></td>
        <td style="padding:.55rem 1rem;color:<?= $urg_col ?>;font-weight:600;font-size:.75rem"><?= $s['urgencia'] ?></td>
        <td style="padding:.55rem 1rem;color:#9ca3af;font-size:.75rem"><?= $s['status'] ?></td>
        <td style="padding:.55rem 1rem;color:#9ca3af;font-size:.72rem"><?= $s['abertura'] ?></td>
        <td style="padding:.55rem 1rem;text-align:right;font-weight:700;color:<?= $dot_col ?>">
          <?= $s['horas'] >= 1 ? round($s['horas']).'h' : round($s['horas']*60).'min' ?>
        </td>
        <td style="padding:.55rem 1rem;text-align:right;color:#9ca3af;font-size:.75rem"><?= $s['thresh'] ?>h</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <div style="text-align:right;color:#9ca3af;font-size:.72rem;margin-top:.5rem">
    <i class="bi bi-clock me-1"></i>Gerado em: <?= date('d/m/Y H:i:s') ?> —
    <a href="relatorios.php?tab=sla" style="color:#00bfff;text-decoration:none">
      <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
    </a>
  </div>
</div>

<script>
// ── Dados do PHP ─────────────────────────────────────────────────
const dadosAtendente        = <?= $json_atendente ?>;
const dadosAtendenteFechados= <?= $json_atendente_fechados ?>;
const dadosEntidade         = <?= $json_entidade  ?>;
const dadosCategoria = <?= $json_categoria ?>;
const dadosHora      = <?= $json_hora      ?>;
const dadosDia       = <?= $json_dia       ?>;
const dadosMes       = <?= $json_mes       ?>;

// ── Cores pizza ──────────────────────────────────────────────────
const coresPizza = ['#1e90ff','#ff6600','#9b59b6','#ff1493','#00ced1','#ffa500','#32cd32','#dc143c','#00bfff','#ff69b4'];

// ── Chart defaults dark ──────────────────────────────────────────
Chart.defaults.color = '#c8d8f0';
Chart.defaults.borderColor = '#1a3a6a';

// ── Tabs ─────────────────────────────────────────────────────────
function showTab(id, btn) {
  document.querySelectorAll('.painel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('painel-' + id).classList.add('active');
  (btn || event.target).classList.add('active');
  history.replaceState(null, '', '?tab=' + id);
}

// Abre aba via URL (?tab=sla etc.)
const tabParam = new URLSearchParams(location.search).get('tab');
if (tabParam) {
  const btnAlvo = [...document.querySelectorAll('.tab-btn')]
    .find(b => b.getAttribute('onclick')?.includes("'" + tabParam + "'"));
  if (btnAlvo) showTab(tabParam, btnAlvo);
}

// ── Gráfico Atendente ────────────────────────────────────────────
new Chart(document.getElementById('chartAtendente'), {
  type: 'bar',
  data: {
    labels: dadosAtendente.map(d => d.nome),
    datasets: [{
      data: dadosAtendente.map(d => d.total),
      backgroundColor: '#00cc66',
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      datalabels: { display: false }
    },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } }
    }
  }
});

// ── Gráfico Fechados por Atendente ──────────────────────────────
new Chart(document.getElementById('chartAtendenteFechados'), {
  type: 'bar',
  data: {
    labels: dadosAtendenteFechados.map(d => d.nome),
    datasets: [{
      data: dadosAtendenteFechados.map(d => d.total),
      backgroundColor: '#00ff88',
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' }, beginAtZero: true }
    }
  }
});

// ── Gráfico Lojas (pizza) ────────────────────────────────────────
new Chart(document.getElementById('chartLojas'), {
  type: 'pie',
  data: {
    labels: dadosEntidade.map(d => d.nome + ' (' + d.total + ')'),
    datasets: [{
      data: dadosEntidade.map(d => d.total),
      backgroundColor: coresPizza,
      borderColor: '#050d1a',
      borderWidth: 2,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { color: '#c8d8f0', boxWidth: 14, font: { size: 11 } } },
    }
  }
});

// ── Gráfico Categorias (horizontal) ─────────────────────────────
new Chart(document.getElementById('chartCategorias'), {
  type: 'bar',
  data: {
    labels: dadosCategoria.map(d => d.nome.length > 30 ? d.nome.slice(0,28)+'…' : d.nome),
    datasets: [{
      data: dadosCategoria.map(d => d.total),
      backgroundColor: '#1e90ff',
      borderRadius: 4,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0', font: { size: 11 } } }
    }
  }
});

// ── Gráfico Hora ─────────────────────────────────────────────────
const horas = Array.from({length:24}, (_,i) => i.toString().padStart(2,'0')+':00');
new Chart(document.getElementById('chartHora'), {
  type: 'bar',
  data: {
    labels: horas,
    datasets: [{
      label: 'Chamados',
      data: dadosHora,
      backgroundColor: '#00bfff',
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } }
    }
  }
});

// ── Gráfico Dia da Semana ────────────────────────────────────────
const dias = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
new Chart(document.getElementById('chartDia'), {
  type: 'bar',
  data: {
    labels: dias,
    datasets: [{
      label: 'Chamados',
      data: dadosDia,
      backgroundColor: '#00ff88',
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } }
    }
  }
});

// ── Gráfico Evolução Mensal ──────────────────────────────────────
new Chart(document.getElementById('chartMes'), {
  type: 'bar',
  data: {
    labels: dadosMes.map(d => d.mes),
    datasets: [{
      label: 'Chamados',
      data: dadosMes.map(d => d.total),
      backgroundColor: '#1e90ff',
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => ' ' + ctx.raw + ' chamados'
        }
      }
    },
    scales: {
      x: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } },
      y: { grid: { color: '#1a3a6a' }, ticks: { color: '#c8d8f0' } }
    }
  }
});
</script>

</body>
</html>
