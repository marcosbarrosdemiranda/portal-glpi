<?php
/**
 * Painel de Relatórios — BI Reformulado
 * Frontend: ApexCharts + Count-up KPIs + Tema escuro profissional
 * Backend: relatorios_dados.php (SQL direto via PDO, sem REST API)
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

// ── Entities list for the filter dropdown ─────────────────────
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/entidade_alias.php';
$entidades_lista = [];
try {
    $st = $pdo->query("SELECT id, completename FROM glpi_entities WHERE id > 0 ORDER BY completename");
    while ($r = $st->fetch()) {
        $entidades_lista[] = ['id' => (int)$r['id'], 'nome' => apelido_entidade($r['completename'])];
    }
} catch (Exception $e) { /* fallback */ }

$entidade_id = (int)($_GET['entidade_id'] ?? 0);
$dt_ini = $_GET['dt_ini'] ?? date('Y-m-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>BI — Painel de Chamados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
  <script src="assets/notificacoes.js"></script>
<style>
/* ══════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Dark BI Theme
   ══════════════════════════════════════════════════════════════ */
:root {
  --bg-page:     #070b14;
  --bg-card:     #0f1525;
  --bg-card-hov: #141c30;
  --bg-elevated: #1a2440;
  --border:      #1e2d50;
  --border-subtle:#162040;
  --text:        #d1d9f0;
  --text-dim:    #7a8aaa;
  --text-bright: #f0f4ff;
  --cyan:        #06b6d4;
  --green:       #22c55e;
  --gold:        #eab308;
  --red:         #ef4444;
  --purple:      #8b5cf6;
  --orange:      #f97316;
  --pink:        #ec4899;
  --chart-colors:#06b6d4,#22c55e,#eab308,#ef4444,#8b5cf6,#f97316,#ec4899,#14b8a6,#f59e0b,#6366f1;
  --radius:      12px;
  --radius-sm:   8px;
  --transition:  .25s cubic-bezier(.4,0,.2,1);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg-page);
  color: var(--text);
  font-family: 'Segoe UI', -apple-system, sans-serif;
  min-height: 100vh;
}

/* ═══ Topbar ═══════════════════════════════════════════════ */
.topbar {
  background: linear-gradient(135deg,#0a0f1f 0%,#0f1a35 50%,#0a0f1f 100%);
  border-bottom: 1px solid var(--border);
  padding: .7rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
}
.topbar .brand {
  display: flex; align-items: center; gap: .6rem;
  color: var(--text-bright);
  font-size: 1.05rem; font-weight: 800;
  letter-spacing: .04em;
}
.topbar .brand i { color: var(--cyan); font-size: 1.3rem; }
.topbar .brand small {
  font-weight: 400; font-size: .7rem; color: var(--text-dim);
  letter-spacing: .08em; text-transform: uppercase;
  margin-left: .3rem;
}
.topbar a.btn-top {
  color: var(--text-dim); text-decoration: none; font-size: .8rem;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: .35rem .85rem; transition: var(--transition);
  display: flex; align-items: center; gap: .4rem;
}
.topbar a.btn-top:hover { border-color: var(--cyan); color: var(--cyan); background: rgba(6,182,212,.08); }

/* ═══ Filters ═══════════════════════════════════════════════ */
.filtros {
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  padding: .75rem 1.5rem;
  display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;
}
.filtros .campo { display: flex; flex-direction: column; gap: .25rem; }
.filtros .campo label {
  font-size: .68rem; color: var(--text-dim); text-transform: uppercase;
  letter-spacing: .06em; font-weight: 700;
}
.filtros .campo input,
.filtros .campo select {
  background: var(--bg-elevated); color: var(--text);
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: .4rem .75rem; font-size: .82rem;
  color-scheme: dark; min-width: 150px;
  transition: border-color var(--transition);
}
.filtros .campo input:focus,
.filtros .campo select:focus { outline: none; border-color: var(--cyan); }
.btn-filtrar {
  background: var(--cyan); color: #000;
  border: none; border-radius: var(--radius-sm); padding: .4rem 1.2rem;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  transition: var(--transition); white-space: nowrap;
  display: flex; align-items: center; gap: .4rem;
}
.btn-filtrar:hover { opacity: .85; transform: translateY(-1px); }
.btn-limpar {
  color: var(--text-dim); text-decoration: none; font-size: .78rem;
  padding: .4rem .9rem; border: 1px solid var(--border);
  border-radius: var(--radius-sm); transition: var(--transition);
}
.btn-limpar:hover { border-color: var(--text-dim); color: var(--text); }

/* ═══ Tabs ══════════════════════════════════════════════════ */
.tabs {
  display: flex; flex-wrap: wrap; gap: .25rem;
  padding: .6rem 1.5rem;
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
}
.tab-btn {
  background: transparent; color: var(--text-dim);
  border: 1px solid transparent; border-radius: var(--radius-sm);
  padding: .4rem 1rem; font-size: .78rem; font-weight: 600;
  cursor: pointer; transition: var(--transition);
  white-space: nowrap;
}
.tab-btn:hover { color: var(--text); border-color: var(--border); }
.tab-btn.active {
  background: rgba(6,182,212,.12); color: var(--cyan);
  border-color: var(--cyan);
}
.tab-btn .badge-tab {
  display: inline-block; background: var(--bg-elevated); color: var(--text-dim);
  border-radius: 20px; padding: 0 7px; font-size: .68rem;
  margin-left: .35rem; font-weight: 700;
}
.tab-btn.active .badge-tab { background: var(--cyan); color: #000; }

/* ═══ Painéis ═══════════════════════════════════════════════ */
.painel { display: none; padding: 1.25rem 1.5rem; animation: fadeIn .35s ease; }
.painel.active { display: block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

.painel-title {
  text-align: center; margin-bottom: 1.5rem;
  color: var(--text-bright);
  font-size: 1.2rem; font-weight: 800;
  letter-spacing: .04em;
}
.painel-title i { color: var(--cyan); margin-right: .5rem; }

/* ═══ Loading / Error ═══════════════════════════════════════ */
.loading-overlay {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 4rem 2rem; gap: 1rem;
}
.spinner {
  width: 40px; height: 40px;
  border: 3px solid var(--border); border-top-color: var(--cyan);
  border-radius: 50%; animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loading-overlay p { color: var(--text-dim); font-size: .85rem; }

.error-box {
  background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
  border-radius: var(--radius); padding: 2rem; text-align: center;
}
.error-box i { font-size: 2.5rem; color: var(--red); margin-bottom: .75rem; display: block; }
.error-box p { color: var(--text-dim); font-size: .85rem; margin-bottom: 1rem; }
.error-box .btn-retry {
  background: var(--red); color: #fff; border: none;
  border-radius: var(--radius-sm); padding: .4rem 1rem;
  cursor: pointer; font-weight: 600; font-size: .8rem;
}

/* ═══ KPIs ══════════════════════════════════════════════════ */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem; margin-bottom: 1.25rem;
}
.kpi-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.1rem 1.4rem;
  position: relative; overflow: hidden;
  transition: var(--transition);
}
.kpi-card:hover { border-color: var(--text-dim); background: var(--bg-card-hov); }
.kpi-card .kpi-icon {
  position: absolute; top: .75rem; right: .75rem;
  font-size: 1.8rem; opacity: .15;
}
.kpi-card .kpi-label {
  font-size: .82rem; color: var(--text-dim); text-transform: uppercase;
  letter-spacing: .06em; font-weight: 700; margin-bottom: .4rem;
}
.kpi-card .kpi-val {
  font-size: 1.9rem; font-weight: 900; line-height: 1.1;
  color: var(--text-bright); font-variant-numeric: tabular-nums;
}
.kpi-card .kpi-sub {
  font-size: .8rem; color: var(--text-dim); margin-top: .25rem; font-weight: 500;
}
.kpi-card.accent-cyan   { border-top: 4px solid var(--cyan); }
.kpi-card.accent-cyan   .kpi-val { color: var(--cyan); }
.kpi-card.accent-green  { border-top: 4px solid var(--green); }
.kpi-card.accent-green  .kpi-val { color: var(--green); }
.kpi-card.accent-gold   { border-top: 4px solid var(--gold); }
.kpi-card.accent-gold   .kpi-val { color: var(--gold); }
.kpi-card.accent-red    { border-top: 4px solid var(--red); }
.kpi-card.accent-red    .kpi-val { color: var(--red); }
.kpi-card.accent-purple { border-top: 4px solid var(--purple); }
.kpi-card.accent-purple .kpi-val { color: var(--purple); }

/* ═══ Charts grid ═══════════════════════════════════════════ */
.chart-grid-2 {
  display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
  margin-bottom: 1rem;
}
.chart-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem;
}
.chart-card h3 {
  font-size: .8rem; font-weight: 700; color: var(--text-dim);
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: .75rem; padding-bottom: .5rem;
  border-bottom: 1px solid var(--border-subtle);
}
.chart-card .chart-wrap { width: 100%; }
.chart-card.full-width { grid-column: 1 / -1; }

/* ═══ Heatmap customizado ═══════════════════════════════════ */
.hm-wrap { overflow-x: auto; padding-bottom: .5rem; }
.hm-col-labels { display: flex; margin-left: 100px; margin-bottom: 4px; }
.hm-col-labels span { flex: 1; min-width: 26px; text-align: center; color: #7a8aaa; font-size: 10px; }
.hm-row { display: flex; align-items: center; gap: 3px; margin-bottom: 3px; }
.hm-day { min-width: 97px; color: #fbbf24; font-weight: 700; font-size: 12px; padding-right: 4px; }
.hm-cells { display: flex; gap: 3px; flex: 1; }
.hm-cell { flex: 1; min-width: 26px; height: 32px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: rgba(255,255,255,.75); cursor: default; transition: transform .1s, opacity .1s; }
.hm-cell:hover { transform: scale(1.15); opacity: .9; z-index: 1; position: relative; }
.hm-legend { display: flex; align-items: center; gap: 6px; margin-top: 10px; justify-content: flex-end; font-size: 11px; color: #7a8aaa; }
.hm-legend-bar { display: flex; gap: 2px; }
.hm-legend-bar span { width: 18px; height: 12px; border-radius: 2px; display: inline-block; }

/* ═══ Tables ════════════════════════════════════════════════ */
.tabela-bi {
  width: 100%; border-collapse: collapse; font-size: .8rem;
}
.tabela-bi thead th {
  background: rgba(30,45,80,.5); color: var(--text-dim);
  padding: .5rem .75rem; text-align: left; font-size: .68rem;
  text-transform: uppercase; letter-spacing: .05em;
  font-weight: 700; border-bottom: 1px solid var(--border);
}
.tabela-bi tbody td {
  padding: .4rem .75rem; border-bottom: 1px solid var(--border-subtle);
}
.tabela-bi tbody tr:hover td { background: rgba(6,182,212,.04); }
.tabela-bi tbody tr:last-child td { border-bottom: none; }
.tabela-bi .bar-bg {
  background: var(--bg-elevated); border-radius: 4px; height: 8px;
  overflow: hidden; display: inline-block; vertical-align: middle;
  width: 120px; margin-right: .5rem;
}
.tabela-bi .bar-fill {
  height: 100%; border-radius: 4px; transition: width .8s ease;
}
.tabela-bi tfoot td {
  font-weight: 700; color: var(--text-bright);
  border-top: 2px solid var(--border);
  padding: .5rem .75rem;
}

/* ═══ SLA ══════════════════════════════════════════════════ */
.sla-verde   { color: var(--green); }
.sla-amarelo { color: var(--gold); }
.sla-vermelho{ color: var(--red); }

/* ═══ Responsive ═══════════════════════════════════════════ */
@media(max-width:900px) {
  .chart-grid-2 { grid-template-columns: 1fr; }
  .filtros { flex-direction: column; }
  .filtros .campo input, .filtros .campo select { min-width: 100%; }
  .kpi-row { grid-template-columns: repeat(2, 1fr); }
  .painel { padding: 1rem; }
  .tabs { padding: .6rem 1rem; flex-wrap: nowrap; }
}
@media(max-width:480px) {
  .kpi-row { grid-template-columns: 1fr; }
  .kpi-card .kpi-val { font-size: 1.6rem; }
}
</style>
</head>
<body>

<!-- ═══════════════════ Topbar ═══════════════════════════ -->
<div class="topbar">
  <div class="brand">
    <i class="bi bi-bar-chart-fill"></i>
    Painel BI
    <small>Chamados</small>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span id="status-badge" style="font-size:.68rem;color:var(--text-dim);display:none">
      <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
    </span>
    <a href="dashboard.php" class="btn-top"><i class="bi bi-grid"></i> Início</a>
  </div>
</div>

<!-- ═══════════════════ Filters ═══════════════════════════ -->
<form id="form-filtros" class="filtros">
  <div class="campo">
    <label>Data Início</label>
    <input type="date" name="dt_ini" value="<?= htmlspecialchars($dt_ini) ?>"/>
  </div>
  <div class="campo">
    <label>Data Fim</label>
    <input type="date" name="dt_fim" value="<?= htmlspecialchars($dt_fim) ?>"/>
  </div>
  <div class="campo">
    <label>Entidade</label>
    <select name="entidade_id" style="min-width:170px">
      <option value="">Todas as lojas</option>
      <?php foreach ($entidades_lista as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $entidade_id === $e['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($e['nome']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn-filtrar"><i class="bi bi-search"></i> Filtrar</button>
  <a href="relatorios.php" class="btn-limpar"><i class="bi bi-x-circle"></i> Limpar</a>
</form>

<!-- ═══════════════════ Tabs ═════════════════════════════ -->
<div class="tabs" id="tab-nav">
  <button class="tab-btn active" data-tab="atendimentos">
    <i class="bi bi-people"></i> Atendimentos
    <span class="badge-tab" id="badge-fechados">0</span>
  </button>
  <button class="tab-btn" data-tab="lojas"><i class="bi bi-shop"></i> Lojas</button>
  <button class="tab-btn" data-tab="categorias"><i class="bi bi-tags"></i> Categorias</button>
  <button class="tab-btn" data-tab="horario"><i class="bi bi-clock"></i> Horário</button>
  <button class="tab-btn" data-tab="evolucao"><i class="bi bi-graph-up-arrow"></i> Evolução</button>
  <button class="tab-btn" data-tab="sla"><i class="bi bi-shield-exclamation"></i> SLA</button>
  <button class="tab-btn" data-tab="rotinas"><i class="bi bi-arrow-repeat"></i> Rotinas</button>
  <button class="tab-btn" data-tab="projetos"><i class="bi bi-folder"></i> Projetos</button>
  <button class="tab-btn" data-tab="impressoes"><i class="bi bi-printer"></i> Impressões</button>
  <button class="tab-btn" data-tab="equipamentos"><i class="bi bi-hdd-network"></i> Equipamentos</button>
</div>

<!-- ═══════════════════ Content ══════════════════════════ -->
<div id="conteudo">

  <!-- Loading state -->
  <div class="loading-overlay" id="loading-state">
    <div class="spinner"></div>
    <p>Carregando dados do período…</p>
  </div>

  <!-- Error state -->
  <div class="painel" id="painel-erro">
    <div class="error-box" id="error-box">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <p id="error-msg">Erro ao carregar dados.</p>
      <button class="btn-retry" onclick="carregarDados()"><i class="bi bi-arrow-clockwise"></i> Tentar novamente</button>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 1: ATENDIMENTOS                              -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel active" id="painel-atendimentos">
    <div class="painel-title"><i class="bi bi-people"></i>Atendimentos</div>
    <div class="kpi-row" id="kpi-atendimentos">
      <div class="kpi-card accent-cyan"><div class="kpi-label">📋 Total Abertos</div><div class="kpi-val" id="kpi-abertos">—</div><div class="kpi-sub">abertos no período</div></div>
      <div class="kpi-card accent-green"><div class="kpi-label">✅ Total Fechados</div><div class="kpi-val" id="kpi-fechados">—</div><div class="kpi-sub">fechados no período</div></div>
      <div class="kpi-card accent-gold"><div class="kpi-label">⚡ Em Andamento</div><div class="kpi-val" id="kpi-andamento">—</div><div class="kpi-sub">carga atual</div></div>
      <div class="kpi-card accent-purple"><div class="kpi-label">⏱ Tempo Médio</div><div class="kpi-val" id="kpi-tempomedio">—</div><div class="kpi-sub">horas para fechamento</div></div>
      <div class="kpi-card accent-green" id="kpi-card-resolucao"><div class="kpi-label">🎯 Taxa de Resolução</div><div class="kpi-val" id="kpi-resolucao">—</div><div class="kpi-sub" id="kpi-resolucao-sub">fechados / abertos</div></div>
    </div>
    <div class="chart-card full-width">
      <h3>📊 Produtividade — Fechados vs Em Andamento por Atendente</h3>
      <div class="chart-wrap" id="chart-produtividade"></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 2: LOJAS                                     -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-lojas">
    <div class="painel-title"><i class="bi bi-shop"></i>Chamados por Loja</div>
    <div class="chart-grid-2">
      <div class="chart-card"><h3>Distribuição por Loja</h3><div class="chart-wrap" id="chart-lojas-donut"></div></div>
      <div class="chart-card"><h3>Ranking — Chamados por Loja</h3>
        <div id="ranking-lojas" style="display:flex;flex-direction:column;gap:.55rem;max-height:560px;overflow-y:auto;padding-right:4px"></div>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 3: CATEGORIAS                                -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-categorias">
    <div class="painel-title"><i class="bi bi-tags"></i>Chamados por Categoria</div>
    <div class="chart-grid-2">
      <div class="chart-card"><h3>Distribuição</h3><div class="chart-wrap" id="chart-categorias-bar"></div></div>
      <div class="chart-card"><h3>Ranking</h3>
        <div style="overflow-x:auto"><table class="tabela-bi" id="tabela-categorias">
          <thead><tr><th>Categoria</th><th>Chamados</th><th>%</th></tr></thead>
          <tbody id="tbody-categorias"></tbody>
        </table></div>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 4: HORÁRIO                                   -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-horario">
    <div class="painel-title"><i class="bi bi-clock"></i>Chamados por Horário</div>
    <div class="kpi-row" id="kpi-horario">
      <div class="kpi-card accent-cyan"><div class="kpi-label">⚡ Hora de Pico</div><div class="kpi-val" id="kpi-hora-pico">—</div><div class="kpi-sub">hora com mais chamados</div></div>
      <div class="kpi-card accent-gold"><div class="kpi-label">📅 Dia Mais Movimentado</div><div class="kpi-val" id="kpi-dia-pico">—</div><div class="kpi-sub">dia da semana com mais chamados</div></div>
      <div class="kpi-card accent-green"><div class="kpi-label">🌙 Hora Mais Tranquila</div><div class="kpi-val" id="kpi-hora-calma">—</div><div class="kpi-sub">menor volume — ideal para manutenções</div></div>
    </div>
    <div class="chart-grid-2">
      <div class="chart-card"><h3>Chamados por Hora do Dia</h3><div class="chart-wrap" id="chart-hora"></div></div>
      <div class="chart-card"><h3>Chamados por Dia da Semana</h3><div class="chart-wrap" id="chart-dia"></div></div>
    </div>
    <div class="chart-card full-width">
      <h3>🔥 Heatmap — Hora × Dia da Semana</h3>
      <div class="chart-wrap" id="chart-heatmap"></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 5: EVOLUÇÃO                                  -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-evolucao">
    <div class="painel-title"><i class="bi bi-graph-up-arrow"></i>Evolução — Últimos 12 Meses</div>
    <div class="kpi-row">
      <div class="kpi-card accent-gold"><div class="kpi-label">📊 Total Abertos (12m)</div><div class="kpi-val" id="kpi-evolucao-total">—</div></div>
      <div class="kpi-card accent-green"><div class="kpi-label">✅ Total Fechados (12m)</div><div class="kpi-val" id="kpi-evolucao-fechados">—</div></div>
      <div class="kpi-card accent-cyan"><div class="kpi-label">📈 Média Mensal</div><div class="kpi-val" id="kpi-evolucao-media">—</div></div>
    </div>
    <div class="chart-grid-2">
      <div class="chart-card"><h3>📈 Abertos por Mês</h3><div class="chart-wrap" id="chart-evolucao"></div></div>
      <div class="chart-card"><h3>✅ Fechados por Mês</h3><div class="chart-wrap" id="chart-evolucao-fechados"></div></div>
    </div>
    <div class="chart-card full-width" style="margin-top:.5rem">
      <h3>📊 Abertos vs Fechados — Comparativo Mensal</h3>
      <div class="chart-wrap" id="chart-evolucao-barras"></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 6: SLA                                       -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-sla">
    <div class="painel-title"><i class="bi bi-shield-exclamation"></i>Monitor SLA — Tempo Real</div>
    <div class="kpi-row" id="kpi-sla">
      <div class="kpi-card accent-green"><div class="kpi-label">🟢 No Prazo</div><div class="kpi-val sla-verde" id="kpi-sla-verde">—</div></div>
      <div class="kpi-card accent-gold"><div class="kpi-label">🟡 Atenção</div><div class="kpi-val sla-amarelo" id="kpi-sla-amarelo">—</div></div>
      <div class="kpi-card accent-red"><div class="kpi-label">🔴 Atrasado</div><div class="kpi-val sla-vermelho" id="kpi-sla-vermelho">—</div></div>
      <div class="kpi-card accent-cyan"><div class="kpi-label">📋 Total Abertos</div><div class="kpi-val" id="kpi-sla-total">—</div></div>
    </div>
    <div class="chart-card full-width">
      <h3>🚦 Distribuição SLA</h3>
      <div class="chart-wrap" id="chart-sla-donut" style="height:220px;max-width:400px;margin:0 auto"></div>
    </div>
    <div class="chart-card full-width" style="margin-top:1rem">
      <h3>📋 Chamados Abertos</h3>
      <div style="overflow-x:auto" id="sla-tabela-wrap">
        <div style="text-align:center;padding:2rem;color:var(--text-dim)"><i class="bi bi-check-circle-fill" style="font-size:2rem;color:var(--green);display:block;margin-bottom:.5rem"></i>Nenhum chamado aberto ativo.</div>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 7: ROTINAS (entidade raiz)                   -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-rotinas">
    <div class="painel-title"><i class="bi bi-arrow-repeat"></i>Rotinas — Entidade Raiz</div>
    <div class="chart-grid-2">
      <div class="chart-card"><h3>📋 Rotinas por Tipo</h3><div class="chart-wrap" id="chart-rot-nome"></div></div>
      <div style="display:flex;flex-direction:column;gap:.75rem">
        <div class="kpi-row" id="kpi-rotinas" style="grid-template-columns:repeat(2,1fr);margin-bottom:0">
          <div class="kpi-card accent-cyan"><div class="kpi-label">📋 Total Rotinas</div><div class="kpi-val" id="kpi-rot-total">—</div><div class="kpi-sub">abertas no período</div></div>
          <div class="kpi-card accent-green"><div class="kpi-label">✅ Concluídas</div><div class="kpi-val" id="kpi-rot-fechados">—</div><div class="kpi-sub">fechadas no período</div></div>
          <div class="kpi-card accent-gold"><div class="kpi-label">⚡ Em andamento</div><div class="kpi-val" id="kpi-rot-andamento">—</div><div class="kpi-sub">carga atual</div></div>
          <div class="kpi-card accent-purple"><div class="kpi-label">📊 % Cumprimento</div><div class="kpi-val" id="kpi-rot-prazo">—</div><div class="kpi-sub">concluídas em ≤24h</div></div>
        </div>
        <div class="chart-card"><h3>✅ Concluídas por Atendente</h3><div class="chart-wrap" id="chart-rot-atendente"></div></div>
      </div>
    </div>
    <div class="chart-card full-width" style="margin-top:.5rem">
      <h3>📈 Evolução Mensal — Rotinas</h3>
      <div class="chart-wrap" id="chart-rot-evolucao"></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL 8: PROJETOS                                  -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-projetos">
    <div class="painel-title"><i class="bi bi-folder"></i>Projetos</div>
    <div id="projetos-content" style="text-align:center;padding:2rem;color:var(--text-dim)">
      <i class="bi bi-hourglass-split" style="font-size:2.5rem;display:block;margin-bottom:.75rem;color:var(--gold)"></i>
      Carregando projetos…
    </div>
  </div>

  <!-- PAINEL: Impressões -->
  <div class="painel" id="painel-impressoes">
    <div class="painel-title"><i class="bi bi-printer"></i>Volume de Impressões</div>

    <!-- Filtro de entidades -->
    <div id="imp-ent-filtros" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.25rem"></div>

    <!-- KPIs -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
      <div class="kpi-card accent-cyan">
        <div class="kpi-label">🖨️ Total Impressões</div>
        <div class="kpi-val" id="imp-kpi-total">—</div>
        <div class="kpi-sub">A4 + A3 + outros</div>
      </div>
      <div class="kpi-card accent-green">
        <div class="kpi-label">📄 Total A4</div>
        <div class="kpi-val" id="imp-kpi-a4">—</div>
        <div class="kpi-sub">Frente, F/V, Adesivo, Placas</div>
      </div>
      <div class="kpi-card accent-purple">
        <div class="kpi-label">📐 Total A3</div>
        <div class="kpi-val" id="imp-kpi-a3">—</div>
        <div class="kpi-sub">Frente, F/V, Placas, Adesivos</div>
      </div>
      <div class="kpi-card accent-gold">
        <div class="kpi-label">🏷️ Etiquetas 5S</div>
        <div class="kpi-val" id="imp-kpi-etq">—</div>
        <div class="kpi-sub">etiquetas no período</div>
      </div>
    </div>

    <!-- Gráfico + Tabela -->
    <div class="chart-grid-2">
      <div class="chart-card">
        <h3>Distribuição por Tipo</h3>
        <div class="chart-wrap" id="chart-imp-donut" style="height:420px"></div>
      </div>
      <div class="chart-card">
        <h3>Detalhamento por Tipo</h3>
        <div id="imp-tabela-wrap"></div>
      </div>
    </div>

    <!-- Gráfico por entidade (barras) -->
    <div class="chart-card full-width" style="margin-top:1rem">
      <h3>Impressões por Entidade</h3>
      <div class="chart-wrap" id="chart-imp-entidades" style="min-height:280px"></div>
    </div>

    <!-- Card acumulado do ano + barras mensais -->
    <div class="chart-card full-width" style="margin-top:1rem">
      <h3 id="imp-ano-titulo">Acumulado <span id="imp-ano-label"></span></h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:1.5rem" id="imp-acum-cards"></div>
      <div class="chart-wrap" id="chart-imp-mensal" style="min-height:300px"></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════ -->
  <!-- PAINEL: EQUIPAMENTOS — histórico de chamados         -->
  <!-- ════════════════════════════════════════════════════ -->
  <div class="painel" id="painel-equipamentos">
    <div class="painel-title"><i class="bi bi-hdd-network"></i>Histórico de Chamados por Equipamento</div>

    <div class="kpi-row">
      <div class="kpi-card accent-cyan"><div class="kpi-label">🖥️ Equipamentos c/ chamado</div><div class="kpi-val" id="eq-kpi-equip">—</div><div class="kpi-sub">no período filtrado</div></div>
      <div class="kpi-card accent-gold"><div class="kpi-label">🔗 Chamados vinculados</div><div class="kpi-val" id="eq-kpi-vinc">—</div><div class="kpi-sub">total de vínculos</div></div>
      <div class="kpi-card accent-red"><div class="kpi-label">🔥 Mais acionado</div><div class="kpi-val" id="eq-kpi-top" style="font-size:1.1rem">—</div><div class="kpi-sub" id="eq-kpi-top-sub">—</div></div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-bottom:1rem">
      <div class="campo" style="display:flex;flex-direction:column;gap:.25rem">
        <label style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.06em;font-weight:700">Categoria</label>
        <select id="eq-filtro-cat" onchange="renderEquipTabela()" style="background:var(--bg-elevated);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:.4rem .75rem;font-size:.82rem;color-scheme:dark;min-width:180px">
          <option value="">Todas as categorias</option>
        </select>
      </div>
      <div class="campo" style="display:flex;flex-direction:column;gap:.25rem">
        <label style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.06em;font-weight:700">Buscar</label>
        <input type="text" id="eq-filtro-busca" oninput="renderEquipTabela()" placeholder="Nome do equipamento…" style="background:var(--bg-elevated);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:.4rem .75rem;font-size:.82rem;min-width:200px"/>
      </div>
      <span style="font-size:.75rem;color:var(--text-dim)">Loja: use o filtro do topo</span>
    </div>

    <div class="chart-grid-2" style="margin-bottom:1rem">
      <div class="chart-card"><h3>Chamados por Categoria de Equipamento</h3><div class="chart-wrap" id="eq-chart-cat"></div></div>
      <div class="chart-card"><h3>Resumo por Categoria</h3>
        <div style="overflow-x:auto"><table class="tabela-bi">
          <thead><tr><th>Categoria</th><th>Equip.</th><th>Chamados</th></tr></thead>
          <tbody id="eq-tbody-cat"></tbody>
        </table></div>
      </div>
    </div>

    <div class="chart-card full-width">
      <h3>📋 Equipamentos <span id="eq-tabela-count" style="color:var(--text-dim)"></span></h3>
      <div style="overflow-x:auto"><table class="tabela-bi" id="eq-tabela">
        <thead><tr><th></th><th>Equipamento</th><th>Categoria</th><th>Loja</th><th>Chamados</th><th>Abertos</th><th>Último</th></tr></thead>
        <tbody id="eq-tbody"></tbody>
      </table></div>
      <div id="eq-vazio" style="text-align:center;padding:2rem;color:var(--text-dim);display:none">
        <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>
        Nenhum equipamento com chamado vinculado no período. Vincule equipamentos nos chamados ou no Inventário.
      </div>
    </div>
  </div>

</div>

<script>
/* ══════════════════════════════════════════════════════════════
   BI PAINEL — JavaScript
   ══════════════════════════════════════════════════════════════ */

const C = ['#06b6d4','#22c55e','#eab308','#ef4444','#8b5cf6','#f97316','#ec4899','#14b8a6','#f59e0b','#6366f1'];

const APEX_DARK = {
  chart: {
    foreColor: '#7a8aaa',
    toolbar: { show: true, tools: { download: true, zoom: true, pan: true, reset: true } },
    background: 'transparent',
  },
  tooltip: { theme: 'dark', style: { fontSize: '12px' } },
  grid: { borderColor: '#1e2d50', strokeDashArray: 3 },
  xaxis: { labels: { style: { colors: '#7a8aaa', fontSize: '11px' } } },
  yaxis: { labels: { style: { colors: '#7a8aaa', fontSize: '11px' } } },
};

let charts = {};
let dadosCache = null;

const DIAS = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
const DIAS_FULL = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];

// ── Animate KPI count-up ──────────────────────────────────────
function animarKPI(el, target, suffix = '', duration = 1200) {
  const start = performance.now();
  function tick(now) {
    const p = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - p, 3);
    const val = Math.round(eased * target);
    el.textContent = val + suffix;
    if (p < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

// ── Tab switching ─────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.painel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    const tab = this.dataset.tab;
    const painel = document.getElementById('painel-' + tab);
    if (painel) painel.classList.add('active');
    // Update URL without reload
    const base = location.pathname + location.search.replace(/[?&]tab=[^&]*/g, '');
    history.replaceState(null, '', base + (base.includes('?') ? '&' : '?') + 'tab=' + tab);
  });
});

function abrirTab(nome) {
  const btn = document.querySelector(`.tab-btn[data-tab="${nome}"]`);
  if (btn) btn.click();
}

function getParams() {
  const fd = new FormData(document.getElementById('form-filtros'));
  const p = new URLSearchParams();
  for (const [k,v] of fd) if (v) p.set(k, v);
  return p.toString();
}

function escHtml(s) {
  if (!s) return '—';
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function fmtHora(h) { return h.toString().padStart(2,'0') + ':00'; }

// ══════════════════════════════════════════════════════════════
// RENDER: Atendimentos
// ══════════════════════════════════════════════════════════════
function renderAtendimentos(d) {
  const k = d.kpis;
  animarKPI(document.getElementById('kpi-abertos'), k.total_abertos);
  animarKPI(document.getElementById('kpi-fechados'), k.total_fechados);
  animarKPI(document.getElementById('kpi-andamento'), k.em_andamento);
  document.getElementById('kpi-tempomedio').textContent = k.tempo_medio + 'h';
  const taxa = k.total_abertos > 0 ? Math.round(k.total_fechados / k.total_abertos * 100) : 0;
  const elTaxa = document.getElementById('kpi-resolucao');
  elTaxa.textContent = taxa + '%';
  const card = document.getElementById('kpi-card-resolucao');
  card.classList.remove('accent-green','accent-gold','accent-red');
  card.classList.add(taxa >= 90 ? 'accent-green' : taxa >= 70 ? 'accent-gold' : 'accent-red');
  elTaxa.style.color = taxa >= 90 ? 'var(--green)' : taxa >= 70 ? 'var(--gold)' : 'var(--red)';
  document.getElementById('kpi-resolucao-sub').textContent = k.total_fechados + ' / ' + k.total_abertos + ' chamados';
  document.getElementById('badge-fechados').textContent = k.total_fechados;

  // Produtividade: barras agrupadas — Fechados vs Em Andamento por Atendente
  const fa = d.por_atendente || [];
  const ea = d.em_andamento_por_atendente || [];
  const todosAtend = [...new Set([...fa.map(x => x.nome), ...ea.map(x => x.nome)])].sort();
  const dataF = todosAtend.map(n => (fa.find(x => x.nome === n) || {}).total || 0);
  const dataE = todosAtend.map(n => (ea.find(x => x.nome === n) || {}).total || 0);

  if (charts.produtividade) charts.produtividade.destroy();
  charts.produtividade = new ApexCharts(document.getElementById('chart-produtividade'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 360, animations: { enabled: true, speed: 600 }, stacked: false },
    series: [
      { name: 'Fechados', data: dataF },
      { name: 'Em andamento', data: dataE },
    ],
    colors: ['#22c55e', '#eab308'],
    xaxis: { categories: todosAtend, labels: { style: { fontSize: '13px', colors: '#fbbf24', fontWeight: 700 } } },
    yaxis: { labels: { formatter: v => Math.round(v), style: { fontSize: '13px', colors: '#7a8aaa' } } },
    plotOptions: { bar: { horizontal: false, columnWidth: '60%', borderRadius: 4 } },
    dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '12px', fontWeight: 700 }, offsetY: -4 },
    stroke: { show: true, width: 1, colors: ['#0f1525'] },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
    legend: { position: 'top', labels: { colors: '#7a8aaa' }, fontSize: '15px', markers: { size: 12 } },
  });
  charts.produtividade.render();
}

// ══════════════════════════════════════════════════════════════
// RENDER: Lojas
// ══════════════════════════════════════════════════════════════
function renderLojas(d) {
  const lojas = d.por_entidade;
  const total = lojas.reduce((s,x) => s + x.total, 0);
  const maxL = Math.max(...lojas.map(x => x.total), 1);

  if (charts.lojasDonut) charts.lojasDonut.destroy();
  charts.lojasDonut = new ApexCharts(document.getElementById('chart-lojas-donut'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'donut', height: 530, animations: { enabled: true, speed: 600 } },
    series: lojas.map(x => x.total),
    labels: lojas.map(x => x.nome),
    colors: C,
    plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'Total', color: '#7a8aaa', fontSize: '32px', fontWeight: 700, formatter: () => total } } } } },
    dataLabels: { enabled: false },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
    legend: { position: 'bottom', labels: { colors: '#7a8aaa' }, itemMargin: { horizontal: 10, vertical: 5 }, fontSize: '15px', markers: { size: 12 } },
  });
  charts.lojasDonut.render();

  document.getElementById('ranking-lojas').innerHTML = lojas.map((x, i) => {
    const pctTotal = (x.total / total * 100).toFixed(1);
    const pct = pctTotal;
    // gradiente: vermelho para quem tem mais, amarelo no meio, verde para menos
    const ratio = x.total / maxL;
    const r = Math.round(220 * ratio + 34 * (1 - ratio));
    const g = Math.round(60 * ratio + 197 * (1 - ratio));
    const b = Math.round(60 * ratio + 94 * (1 - ratio));
    const barColor = `rgb(${r},${g},${b})`;
    return `
    <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .65rem;border-radius:10px;background:rgba(255,255,255,.03);transition:.15s"
         onmouseover="this.style.background='rgba(255,255,255,.07)'" onmouseout="this.style.background='rgba(255,255,255,.03)'">
      <span style="min-width:26px;text-align:center;font-size:.9rem;font-weight:700;color:var(--text-dim)">${i + 1}</span>
      <div style="flex:1;min-width:0">
        <div style="font-size:.95rem;font-weight:600;color:var(--text-bright);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.35rem">${x.nome}</div>
        <div style="background:rgba(255,255,255,.1);border-radius:6px;height:14px;overflow:hidden">
          <div style="width:${pct}%;height:100%;border-radius:6px;background:${barColor};box-shadow:0 0 8px ${barColor}88"></div>
        </div>
      </div>
      <span style="min-width:32px;text-align:right;font-size:1rem;font-weight:700;color:${barColor}">${x.total}</span>
      <span style="min-width:48px;text-align:right;font-size:.82rem;font-weight:600;color:#fbbf24">${pctTotal}%</span>
    </div>`;
  }).join('');
}

// ══════════════════════════════════════════════════════════════
// RENDER: Categorias
// ══════════════════════════════════════════════════════════════
function renderCategorias(d) {
  const cats = d.por_categoria;
  const total = cats.reduce((s,x) => s + x.total, 0);
  const maxC = Math.max(...cats.map(x => x.total), 1);

  if (charts.categoriasBar) charts.categoriasBar.destroy();
  charts.categoriasBar = new ApexCharts(document.getElementById('chart-categorias-bar'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', animations: { enabled: true, speed: 600 } },
    series: [{ name: 'Chamados', data: cats.map(x => x.total).reverse() }],
    colors: ['#06b6d4'],
    xaxis: { categories: cats.map(x => x.nome.length > 35 ? x.nome.slice(0,33)+'…' : x.nome).reverse() },
    plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '70%' } },
    dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '11px', fontWeight: 700 } },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
  });
  charts.categoriasBar.render();

  document.getElementById('tbody-categorias').innerHTML = cats.map(x =>
    `<tr><td>${x.nome}</td>
    <td><div class="bar-bg" style="width:200px"><div class="bar-fill" style="width:${(x.total/maxC*100).toFixed(0)}%;background:var(--purple)"></div></div>${x.total}</td>
    <td style="color:var(--text-dim)">${(x.total/total*100).toFixed(1)}%</td></tr>`
  ).join('');
}

// ══════════════════════════════════════════════════════════════
// RENDER: Horário
// ══════════════════════════════════════════════════════════════
function renderHorario(d) {
  // KPIs de horário
  const porHora = d.por_hora || [];
  const porDia  = d.por_dia  || [];
  if (porHora.length) {
    const maxH  = Math.max(...porHora);
    const minH  = Math.min(...porHora.filter(v => v > 0));
    const idxPH = porHora.indexOf(maxH);
    const idxCH = porHora.indexOf(minH);
    document.getElementById('kpi-hora-pico').textContent  = fmtHora(idxPH);
    document.getElementById('kpi-hora-calma').textContent = fmtHora(idxCH);
  }
  if (porDia.length) {
    const maxD  = Math.max(...porDia);
    const idxPD = porDia.indexOf(maxD);
    document.getElementById('kpi-dia-pico').textContent = DIAS_FULL[idxPD] ?? '—';
  }

  // Hora
  if (charts.hora) charts.hora.destroy();
  charts.hora = new ApexCharts(document.getElementById('chart-hora'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 220, animations: { enabled: true, speed: 500 } },
    series: [{ name: 'Chamados', data: d.por_hora }],
    colors: ['#06b6d4'],
    xaxis: { categories: Array.from({length:24}, (_,i) => fmtHora(i)), tickAmount: 12 },
    plotOptions: { bar: { borderRadius: 2, columnWidth: '70%' } },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
  });
  charts.hora.render();

  // Dia da Semana
  if (charts.dia) charts.dia.destroy();
  charts.dia = new ApexCharts(document.getElementById('chart-dia'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 220, animations: { enabled: true, speed: 500 } },
    series: [{ name: 'Chamados', data: d.por_dia }],
    colors: ['#22c55e'],
    xaxis: { categories: DIAS_FULL },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
  });
  charts.dia.render();

  // Heatmap — grid HTML customizado
  if (charts.heatmap) { charts.heatmap.destroy(); charts.heatmap = null; }
  (function() {
    const hmData = d.heatmap || [];
    const maxVal = Math.max(...hmData.map(x => x.total), 1);
    function hmColor(val) {
      if (val === 0) return '#111b2e';
      const pct = val / maxVal;
      // azul escuro → ciano → amarelo → vermelho
      const stops = [
        [10,45,80], [6,182,212], [234,179,8], [239,68,68]
      ];
      const seg = pct * (stops.length - 1);
      const i = Math.min(Math.floor(seg), stops.length - 2);
      const t = seg - i;
      const [r1,g1,b1] = stops[i], [r2,g2,b2] = stops[i+1];
      return `rgb(${Math.round(r1+(r2-r1)*t)},${Math.round(g1+(g2-g1)*t)},${Math.round(b1+(b2-b1)*t)})`;
    }
    let html = '<div class="hm-wrap">';
    html += '<div class="hm-col-labels">';
    for (let h = 0; h < 24; h++) html += `<span>${fmtHora(h)}</span>`;
    html += '</div>';
    for (let di = 0; di < 7; di++) {
      html += `<div class="hm-row"><span class="hm-day">${DIAS_FULL[di]}</span><div class="hm-cells">`;
      for (let h = 0; h < 24; h++) {
        const item = hmData.find(x => x.hora === h && x.dia === di);
        const val = item ? item.total : 0;
        const bg = hmColor(val);
        const lbl = val > 0 ? val : '';
        html += `<div class="hm-cell" style="background:${bg}" title="${DIAS_FULL[di]} ${fmtHora(h)}: ${val} chamado${val!==1?'s':''}">${lbl}</div>`;
      }
      html += '</div></div>';
    }
    // Legenda
    const legColors = ['#111b2e','#0a2d50','#06b6d4','#eab308','#ef4444'];
    const legLabels = ['0','baixo','médio','alto','pico'];
    html += '<div class="hm-legend">Intensidade:&nbsp;<div class="hm-legend-bar">';
    legColors.forEach(c => html += `<span style="background:${c}"></span>`);
    html += '</div>';
    legLabels.forEach(l => html += `<span>${l}</span>`);
    html += '</div></div>';
    document.getElementById('chart-heatmap').innerHTML = html;
  })();
}

// ══════════════════════════════════════════════════════════════
// RENDER: Evolução
// ══════════════════════════════════════════════════════════════
function renderEvolucao(d) {
  const abertos = d.evolucao_mensal || [];
  const fechados = d.evolucao_fechados || [];
  const totalAbertos = abertos.reduce((s,x) => s + x.total, 0);
  const totalFechados = fechados.reduce((s,x) => s + x.total, 0);
  const media = abertos.length > 0 ? Math.round(totalAbertos / abertos.length) : 0;

  animarKPI(document.getElementById('kpi-evolucao-total'), totalAbertos);
  animarKPI(document.getElementById('kpi-evolucao-fechados'), totalFechados);
  animarKPI(document.getElementById('kpi-evolucao-media'), media);

  // Gráfico 1: Abertos por mês
  if (charts.evolucao) charts.evolucao.destroy();
  charts.evolucao = new ApexCharts(document.getElementById('chart-evolucao'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'area', height: 260, animations: { enabled: true, speed: 600 }, zoom: { enabled: true, type: 'x' } },
    series: [{ name: 'Abertos', data: abertos.map(x => x.total) }],
    colors: ['#06b6d4'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .6, opacityTo: .1,
      colorStops: [{ offset: 0, color: '#06b6d4', opacity: .6 }, { offset: 100, color: '#06b6d4', opacity: .1 }] } },
    xaxis: { categories: abertos.map(x => x.mes), tickAmount: 12, labels: { rotate: -45 } },
    yaxis: { labels: { formatter: v => Math.round(v) } },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    markers: { size: 3, colors: ['#06b6d4'], strokeColors: '#fff', strokeWidth: 1 },
    tooltip: { ...APEX_DARK.tooltip, x: { format: 'yyyy-MM' }, y: { formatter: v => v + ' chamados' } },
  });
  charts.evolucao.render();

  // Gráfico 2: Fechados por mês
  if (charts.evolucaoFechados) charts.evolucaoFechados.destroy();
  charts.evolucaoFechados = new ApexCharts(document.getElementById('chart-evolucao-fechados'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'area', height: 260, animations: { enabled: true, speed: 600 }, zoom: { enabled: true, type: 'x' } },
    series: [{ name: 'Fechados', data: fechados.map(x => x.total) }],
    colors: ['#22c55e'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .6, opacityTo: .1,
      colorStops: [{ offset: 0, color: '#22c55e', opacity: .6 }, { offset: 100, color: '#22c55e', opacity: .1 }] } },
    xaxis: { categories: fechados.map(x => x.mes), tickAmount: 12, labels: { rotate: -45 } },
    yaxis: { labels: { formatter: v => Math.round(v) } },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    markers: { size: 3, colors: ['#22c55e'], strokeColors: '#fff', strokeWidth: 1 },
    tooltip: { ...APEX_DARK.tooltip, x: { format: 'yyyy-MM' }, y: { formatter: v => v + ' chamados' } },
  });
  charts.evolucaoFechados.render();

  // Gráfico 3: Barras agrupadas — Abertos vs Fechados
  const allMeses = [...new Set([...abertos.map(x => x.mes), ...fechados.map(x => x.mes)])].sort();
  const barAbertos = allMeses.map(m => (abertos.find(x => x.mes === m) || {}).total || 0);
  const barFechados = allMeses.map(m => (fechados.find(x => x.mes === m) || {}).total || 0);

  if (charts.evolucaoBarras) charts.evolucaoBarras.destroy();
  charts.evolucaoBarras = new ApexCharts(document.getElementById('chart-evolucao-barras'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 300, animations: { enabled: true, speed: 600 }, stacked: false },
    series: [
      { name: 'Abertos', data: barAbertos },
      { name: 'Fechados', data: barFechados },
    ],
    colors: ['#06b6d4', '#22c55e'],
    xaxis: { categories: allMeses, tickAmount: 12, labels: { rotate: -45 } },
    yaxis: { labels: { formatter: v => Math.round(v) } },
    plotOptions: { bar: { horizontal: false, columnWidth: '60%', borderRadius: 4, dataLabels: { total: { enabled: true, offsetX: 0, style: { fontSize: '10px', colors: ['#fff'] } } } } },
    dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '10px', fontWeight: 700 }, offsetY: -4 },
    stroke: { show: true, width: 1, colors: ['#0f1525'] },
    tooltip: { ...APEX_DARK.tooltip, x: { format: 'yyyy-MM' }, y: { formatter: v => v + ' chamados' } },
    legend: { position: 'top', labels: { colors: '#7a8aaa' } },
  });
  charts.evolucaoBarras.render();
}

// ══════════════════════════════════════════════════════════════
// RENDER: SLA
// ══════════════════════════════════════════════════════════════
function renderSLA(d) {
  const sla = d.sla;
  if (!sla) return;
  const total = (sla.verde||0) + (sla.amarelo||0) + (sla.vermelho||0);

  animarKPI(document.getElementById('kpi-sla-verde'), sla.verde, '', 800);
  animarKPI(document.getElementById('kpi-sla-amarelo'), sla.amarelo, '', 800);
  animarKPI(document.getElementById('kpi-sla-vermelho'), sla.vermelho, '', 800);
  animarKPI(document.getElementById('kpi-sla-total'), total, '', 800);

  // Donut
  if (charts.slaDonut) charts.slaDonut.destroy();
  charts.slaDonut = new ApexCharts(document.getElementById('chart-sla-donut'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'donut', animations: { enabled: true, speed: 600 } },
    series: [sla.verde, sla.amarelo, sla.vermelho],
    labels: ['No Prazo', 'Atenção', 'Atrasado'],
    colors: ['#22c55e','#eab308','#ef4444'],
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', color: '#f0f4ff', formatter: () => total } } } } },
    dataLabels: { enabled: true, style: { fontSize: '12px', colors: ['#fff'] }, dropShadow: { enabled: false } },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
    legend: { position: 'bottom', labels: { colors: '#7a8aaa' } },
  });
  charts.slaDonut.render();

  // Tabela
  const wrap = document.getElementById('sla-tabela-wrap');
  const dados = sla.dados || [];
  if (dados.length === 0) {
    wrap.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-dim)"><i class="bi bi-check-circle-fill" style="font-size:2rem;color:var(--green);display:block;margin-bottom:.5rem"></i>Nenhum chamado aberto ativo.</div>';
    return;
  }
  let html = '<table class="tabela-bi"><thead><tr><th>#</th><th>Título</th><th>Atendente</th><th>Entidade</th><th>Urgência</th><th>Status</th><th>Aberto em</th><th style="text-align:right">Tempo</th><th style="text-align:right">Prazo</th></tr></thead><tbody>';
  dados.forEach(s => {
    const dot = s.cor === 'verde' ? '#22c55e' : s.cor === 'amarelo' ? '#eab308' : '#ef4444';
    const urgC = s.urg_n >= 5 ? '#ef4444' : s.urg_n >= 4 ? '#f97316' : s.urg_n >= 3 ? '#eab308' : '#7a8aaa';
    html += `<tr style="border-bottom:1px solid #1e2d50">
      <td style="color:var(--text-dim)">${s.id}</td>
      <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span style="color:${dot};margin-right:.3rem">●</span>${escHtml(s.titulo)}</td>
      <td>${escHtml(s.atendente)}</td>
      <td style="color:var(--text-dim);font-size:.75rem">${escHtml(s.entidade)}</td>
      <td style="color:${urgC};font-weight:600">${escHtml(s.urgencia)}</td>
      <td style="color:var(--text-dim)">${escHtml(s.status)}</td>
      <td style="color:var(--text-dim);font-size:.75rem">${escHtml(s.abertura)}</td>
      <td style="text-align:right;font-weight:700;color:${dot}">${s.horas >= 1 ? Math.round(s.horas)+'h' : Math.round(s.horas*60)+'min'}</td>
      <td style="text-align:right;color:var(--text-dim)">${s.thresh}h</td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

// ══════════════════════════════════════════════════════════════
// RENDER: Rotinas (entidade raiz)
// ══════════════════════════════════════════════════════════════
function renderRotinas(d) {
  const rot = d.rotinas;
  if (!rot) return;
  const k = rot.kpis || {};

  animarKPI(document.getElementById('kpi-rot-total'), k.total || 0);
  animarKPI(document.getElementById('kpi-rot-fechados'), k.fechados || 0);
  animarKPI(document.getElementById('kpi-rot-andamento'), k.andamento || 0);
  document.getElementById('kpi-rot-prazo').textContent = (k.pct_prazo || 0) + '%';

  // Rotinas por nome — lista HTML com barra proporcional
  const nomes = rot.por_nome || [];
  if (charts.rotNome) { charts.rotNome.destroy(); charts.rotNome = null; }
  (function() {
    const maxN = Math.max(...nomes.map(x => x.total), 1);
    const totalN = nomes.reduce((s, x) => s + x.total, 0);
    let html = '<div style="display:flex;flex-direction:column;gap:8px;padding:4px 0;padding-bottom:4px">';
    nomes.forEach(x => {
      const pct = Math.round(x.total / totalN * 100);
      const barW = Math.round(x.total / maxN * 100);
      const t = x.total / maxN;
      const r = Math.round(6 + t * (234 - 6));
      const g = Math.round(182 + t * (179 - 182));
      const b = Math.round(212 + t * (8 - 212));
      const barColor = `rgb(${r},${g},${b})`;
      html += `<div style="display:flex;flex-direction:column;gap:3px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
          <span style="font-size:.88rem;color:#e2e8f0;font-weight:500;flex:1">${x.nome}</span>
          <span style="font-size:.82rem;font-weight:700;color:#fbbf24;white-space:nowrap">${x.total} <span style="color:#7a8aaa;font-weight:400">(${pct}%)</span></span>
        </div>
        <div style="height:8px;border-radius:4px;background:rgba(255,255,255,.08);overflow:hidden">
          <div style="height:100%;width:${barW}%;background:${barColor};border-radius:4px;transition:width .6s"></div>
        </div>
      </div>`;
    });
    html += '</div>';
    document.getElementById('chart-rot-nome').innerHTML = html;
  })();

  // Por atendente
  const atend = rot.por_atendente || [];
  if (charts.rotAtendente) charts.rotAtendente.destroy();
  charts.rotAtendente = new ApexCharts(document.getElementById('chart-rot-atendente'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 300, animations: { enabled: true, speed: 600 } },
    series: [{ name: 'Concluídas', data: atend.map(x => x.total) }],
    colors: ['#22c55e'],
    xaxis: { categories: atend.map(x => x.nome), labels: { style: { fontSize: '13px', colors: '#fbbf24', fontWeight: 700 } } },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '11px', fontWeight: 700 } },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' rotinas' } },
  });
  charts.rotAtendente.render();

  // Evolução mensal
  const evol = rot.evolucao || [];
  if (charts.rotEvolucao) charts.rotEvolucao.destroy();
  charts.rotEvolucao = new ApexCharts(document.getElementById('chart-rot-evolucao'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'area', height: 220, animations: { enabled: true, speed: 600 }, zoom: { enabled: true, type: 'x' } },
    series: [{ name: 'Rotinas', data: evol.map(x => x.total) }],
    colors: ['#8b5cf6'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .6, opacityTo: .1,
      colorStops: [{ offset: 0, color: '#8b5cf6', opacity: .6 }, { offset: 100, color: '#8b5cf6', opacity: .1 }] } },
    xaxis: { categories: evol.map(x => x.mes), tickAmount: 12, labels: { rotate: -45 } },
    yaxis: { labels: { formatter: v => Math.round(v) } },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    markers: { size: 3, colors: ['#8b5cf6'], strokeColors: '#fff', strokeWidth: 1 },
    tooltip: { ...APEX_DARK.tooltip, x: { format: 'yyyy-MM' }, y: { formatter: v => v + ' rotinas' } },
  });
  charts.rotEvolucao.render();
}

// ══════════════════════════════════════════════════════════════
// IMPRESSÕES
// ══════════════════════════════════════════════════════════════
let _impDados = null;
let _impEntSel = new Set();

const _IMP_TIPOS = [
  { key: 'a4fv',  label: 'A4 Frente/Verso',  cor: '#06b6d4' },
  { key: 'a4f',   label: 'A4 Simples',        cor: '#22c55e' },
  { key: 'a4plc', label: 'A4 Placas',         cor: '#8b5cf6' },
  { key: 'a4adv', label: 'A4 Adesivo',        cor: '#f97316' },
  { key: 'a3fv',  label: 'A3 Frente/Verso',   cor: '#eab308' },
  { key: 'a3f',   label: 'A3 Simples',        cor: '#ec4899' },
  { key: 'a3plc', label: 'A3 Placas',         cor: '#14b8a6' },
  { key: 'a3adv', label: 'A3 Adesivos',       cor: '#f59e0b' },
  { key: 'etq5s', label: 'Etiqueta 5S',       cor: '#6366f1' },
];

async function carregarImpressoes() {
  const params = getParams();
  try {
    const res = await fetch('relatorios_impressoes.php?' + params);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    _impDados = data.entidades || [];
    _impDados._acumulado = data.acumulado || {};
    _impDados._por_mes   = data.por_mes   || [];
    _impDados._ano       = data.ano       || new Date().getFullYear();
    // Seleciona todas por padrão
    _impEntSel = new Set(_impDados.map(e => e.entities_id));
    _renderImpFiltros();
    _renderImp();
  } catch (e) {
    document.getElementById('painel-impressoes').innerHTML +=
      `<p style="color:var(--red);text-align:center">Erro ao carregar impressões: ${e.message}</p>`;
  }
}

function _renderImpFiltros() {
  const wrap = document.getElementById('imp-ent-filtros');
  if (!wrap || !_impDados) return;
  const btnStyle = (ativo) =>
    `cursor:pointer;border:1px solid ${ativo ? 'var(--cyan)' : 'var(--border)'};background:${ativo ? 'rgba(6,182,212,.15)' : 'var(--bg-card)'};color:${ativo ? 'var(--cyan)' : 'var(--text-dim)'};border-radius:6px;padding:.25rem .65rem;font-size:.78rem;font-weight:600;transition:.2s`;

  let html = `<button style="${btnStyle(_impEntSel.size === _impDados.length)}" onclick="_impToggleTodos()">Selecionar tudo</button>`;
  _impDados.forEach(e => {
    const ativo = _impEntSel.has(e.entities_id);
    html += `<button style="${btnStyle(ativo)}" onclick="_impToggleEnt(${e.entities_id})">${escHtml(e.entidade_nome || 'Sem entidade')}</button>`;
  });
  wrap.innerHTML = html;
}

function _impToggleTodos() {
  if (_impEntSel.size === _impDados.length) _impEntSel.clear();
  else _impEntSel = new Set(_impDados.map(e => e.entities_id));
  _renderImpFiltros();
  _renderImp();
}

function _impToggleEnt(id) {
  if (_impEntSel.has(id)) _impEntSel.delete(id); else _impEntSel.add(id);
  _renderImpFiltros();
  _renderImp();
}

function _renderImp() {
  if (!_impDados) return;
  const sel = _impDados.filter(e => _impEntSel.has(e.entities_id));

  // Agrega totais por tipo
  const totTipo = {};
  _IMP_TIPOS.forEach(t => { totTipo[t.key] = sel.reduce((s, e) => s + (e[t.key] || 0), 0); });

  const totalGeral = Object.values(totTipo).reduce((s, v) => s + v, 0);
  const totalA4 = (totTipo.a4fv||0) + (totTipo.a4f||0) + (totTipo.a4plc||0) + (totTipo.a4adv||0);
  const totalA3 = (totTipo.a3fv||0) + (totTipo.a3f||0) + (totTipo.a3plc||0) + (totTipo.a3adv||0);
  const totalEtq = totTipo.etq5s || 0;

  animarKPI(document.getElementById('imp-kpi-total'), totalGeral);
  animarKPI(document.getElementById('imp-kpi-a4'), totalA4);
  animarKPI(document.getElementById('imp-kpi-a3'), totalA3);
  animarKPI(document.getElementById('imp-kpi-etq'), totalEtq);

  // Donut — só tipos com valor > 0
  const tiposAtivos = _IMP_TIPOS.filter(t => totTipo[t.key] > 0);
  if (charts.impDonut) { charts.impDonut.destroy(); charts.impDonut = null; }
  if (tiposAtivos.length > 0) {
    charts.impDonut = new ApexCharts(document.getElementById('chart-imp-donut'), {
      ...APEX_DARK,
      chart: { ...APEX_DARK.chart, type: 'pie', height: 420, animations: { enabled: true, speed: 800,
        dynamicAnimation: { enabled: true, speed: 400 } } },
      series: tiposAtivos.map(t => totTipo[t.key]),
      labels: tiposAtivos.map(t => t.label),
      colors: tiposAtivos.map(t => t.cor),
      fill: { type: 'solid' },
      stroke: { width: 2, colors: ['#0a1020'] },
      plotOptions: { pie: { expandOnClick: true, dataLabels: { offset: -10 } } },
      dataLabels: {
        enabled: true,
        style: { fontSize: '12px', fontWeight: 700, colors: ['#fff'] },
        dropShadow: { enabled: true, top: 2, left: 2, blur: 4, color: '#000', opacity: 0.6 },
        formatter: (val, opts) => {
          const v = opts.w.globals.series[opts.seriesIndex];
          return v > 0 ? [v.toLocaleString('pt-BR'), '(' + val.toFixed(1) + '%)'] : '';
        },
      },
      tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v.toLocaleString('pt-BR') + ' un.' } },
      legend: { position: 'left', labels: { colors: '#c4cfdd' }, fontSize: '12px',
        markers: { size: 10 }, itemMargin: { vertical: 4 } },
    });
    charts.impDonut.render();
  } else {
    document.getElementById('chart-imp-donut').innerHTML =
      '<div style="text-align:center;padding:3rem;color:var(--text-dim)">Nenhum dado no período.</div>';
  }

  // Tabela detalhamento
  const total = totalGeral || 1;
  let tbl = '<table class="tabela-bi" style="font-size:.95rem"><thead><tr><th style="font-size:.8rem">Tipo</th><th style="text-align:right;font-size:.8rem">Total</th><th style="text-align:right;font-size:.8rem">%</th><th style="width:35%;font-size:.8rem">Barra</th></tr></thead><tbody>';
  const maxVal = Math.max(..._IMP_TIPOS.map(t => totTipo[t.key] || 0), 1);
  _IMP_TIPOS.forEach(t => {
    const v = totTipo[t.key] || 0;
    const pct = totalGeral > 0 ? (v / totalGeral * 100).toFixed(1) : '0.0';
    const barW = Math.round(v / maxVal * 100);
    tbl += `<tr>
      <td style="font-size:.95rem;padding:.55rem .75rem"><span style="color:${t.cor};margin-right:.4rem;font-size:1rem">●</span><strong>${t.label}</strong></td>
      <td style="text-align:right;font-weight:700;font-size:1.05rem;color:var(--text-bright);padding:.55rem .75rem">${v.toLocaleString('pt-BR')}</td>
      <td style="text-align:right;color:var(--text-dim);font-size:.88rem;padding:.55rem .75rem">${pct}%</td>
      <td style="padding:.55rem .75rem"><div style="height:10px;border-radius:5px;background:rgba(255,255,255,.08)"><div style="height:100%;width:${barW}%;background:linear-gradient(90deg,${t.cor}cc,${t.cor});border-radius:5px;transition:.6s;box-shadow:0 0 6px ${t.cor}66"></div></div></td>
    </tr>`;
  });
  tbl += `<tfoot><tr><td style="font-weight:700;font-size:1rem;color:var(--text-bright)">TOTAL</td><td style="text-align:right;font-weight:700;font-size:1.1rem;color:var(--cyan)">${totalGeral.toLocaleString('pt-BR')}</td><td></td><td></td></tr></tfoot>`;
  tbl += '</tbody></table>';
  document.getElementById('imp-tabela-wrap').innerHTML = tbl;

  // Acumulado do ano
  const acum = _impDados._acumulado || {};
  const ano  = _impDados._ano || new Date().getFullYear();
  document.getElementById('imp-ano-label').textContent = ano;
  const acumTotal = Object.values(acum).reduce((s, v) => s + v, 0);
  const acumA4 = (acum.a4fv||0)+(acum.a4f||0)+(acum.a4plc||0)+(acum.a4adv||0);
  const acumA3 = (acum.a3fv||0)+(acum.a3f||0)+(acum.a3plc||0)+(acum.a3adv||0);
  const acumCards = [
    { label: '🖨️ Total Ano',  val: acumTotal, cor: 'var(--cyan)'   },
    { label: '📄 A4 Total',   val: acumA4,    cor: 'var(--green)'  },
    { label: '📐 A3 Total',   val: acumA3,    cor: 'var(--purple)' },
    { label: '🏷️ Etiquetas',  val: acum.etq5s||0, cor: 'var(--gold)' },
  ];
  document.getElementById('imp-acum-cards').innerHTML = acumCards.map(c =>
    `<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;border-top:3px solid ${c.cor}">
       <div style="font-size:.75rem;color:var(--text-dim);margin-bottom:.3rem">${c.label}</div>
       <div style="font-size:1.6rem;font-weight:800;color:${c.cor}">${c.val.toLocaleString('pt-BR')}</div>
     </div>`
  ).join('');

  // Gráfico mensal (barras agrupadas por tipo principal)
  const porMes = _impDados._por_mes || [];
  const mesesLabels = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
  const seriesMes = [
    { name:'A4 F/V',    data: porMes.map(m => m.a4fv  || 0), cor:'#06b6d4' },
    { name:'A4 Simples',data: porMes.map(m => m.a4f   || 0), cor:'#22c55e' },
    { name:'A4 Placas', data: porMes.map(m => m.a4plc || 0), cor:'#8b5cf6' },
    { name:'A4 Adesivo',data: porMes.map(m => m.a4adv || 0), cor:'#f97316' },
    { name:'A3 F/V',    data: porMes.map(m => m.a3fv  || 0), cor:'#eab308' },
    { name:'A3 Simples',data: porMes.map(m => m.a3f   || 0), cor:'#ec4899' },
    { name:'A3 Placas', data: porMes.map(m => m.a3plc || 0), cor:'#14b8a6' },
    { name:'A3 Adesivo',data: porMes.map(m => m.a3adv || 0), cor:'#f59e0b' },
    { name:'Etiqueta 5S',data:porMes.map(m => m.etq5s || 0), cor:'#6366f1' },
  ].filter(s => s.data.some(v => v > 0));

  if (charts.impMensal) { charts.impMensal.destroy(); charts.impMensal = null; }
  charts.impMensal = new ApexCharts(document.getElementById('chart-imp-mensal'), {
    ...APEX_DARK,
    chart: { ...APEX_DARK.chart, type: 'bar', height: 300, stacked: true,
      animations: { enabled: true, speed: 600 } },
    series: seriesMes.map(s => ({ name: s.name, data: s.data })),
    colors: seriesMes.map(s => s.cor),
    xaxis: { categories: mesesLabels,
      labels: { style: { fontSize: '12px', colors: '#fbbf24', fontWeight: 700 } } },
    yaxis: { labels: { formatter: v => v >= 1000 ? (v/1000).toFixed(1)+'k' : v,
      style: { colors: '#7a8aaa' } } },
    plotOptions: { bar: { horizontal: false, columnWidth: '65%', borderRadius: 2 } },
    dataLabels: { enabled: false },
    tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v.toLocaleString('pt-BR') + ' un.' } },
    legend: { position: 'top', labels: { colors: '#7a8aaa' }, fontSize: '11px',
      markers: { size: 8 } },
  });
  charts.impMensal.render();

  // Barras por entidade
  const entNomes = sel.map(e => e.entidade_nome || 'S/Entidade');
  const seriesEnt = _IMP_TIPOS.filter(t => sel.some(e => (e[t.key]||0)>0)).map(t => ({
    name: t.label,
    data: sel.map(e => e[t.key] || 0),
  }));
  if (charts.impEntidades) { charts.impEntidades.destroy(); charts.impEntidades = null; }
  if (seriesEnt.length > 0 && sel.length > 0) {
    charts.impEntidades = new ApexCharts(document.getElementById('chart-imp-entidades'), {
      ...APEX_DARK,
      chart: { ...APEX_DARK.chart, type: 'bar', height: 300, stacked: true, animations: { enabled: true, speed: 600 } },
      series: seriesEnt,
      colors: _IMP_TIPOS.filter(t => sel.some(e => (e[t.key]||0)>0)).map(t => t.cor),
      xaxis: { categories: entNomes, labels: { style: { fontSize: '12px', colors: '#fbbf24', fontWeight: 700 } } },
      plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 2 } },
      dataLabels: { enabled: false },
      tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v.toLocaleString('pt-BR') + ' un.' } },
      legend: { position: 'top', labels: { colors: '#7a8aaa' }, fontSize: '11px' },
    });
    charts.impEntidades.render();
  }
}

// ══════════════════════════════════════════════════════════════
// MAIN: carregar dados e renderizar
// ══════════════════════════════════════════════════════════════

async function carregarDados() {
  document.getElementById('painel-erro').classList.remove('active');
  document.getElementById('loading-state').style.display = 'flex';
  document.getElementById('status-badge').style.display = 'none';

  const url = 'relatorios_dados.php?' + getParams();
  try {
    const res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    dadosCache = data;

    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('status-badge').style.display = 'inline';

    renderAtendimentos(data);
    renderLojas(data);
    renderCategorias(data);
    renderHorario(data);
    renderEvolucao(data);
    renderSLA(data);
    renderRotinas(data);
    carregarProjetos();
    carregarImpressoes();
    carregarEquipamentos();
  } catch (err) {
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('painel-erro').classList.add('active');
    document.getElementById('error-msg').textContent = 'Erro: ' + err.message;
    document.getElementById('status-badge').style.display = 'none';
  }
}

// ══════════════════════════════════════════════════════════════
// PROJETOS
// ══════════════════════════════════════════════════════════════

async function carregarProjetos() {
  const container = document.getElementById('projetos-content');
  try {
    const res = await fetch('relatorios_projetos.php');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const projetos = await res.json();
    if (!projetos || projetos.length === 0) {
      container.innerHTML = '<i class="bi bi-folder-open" style="font-size:2.5rem;display:block;margin-bottom:.75rem;color:var(--text-dim)"></i><p style="color:var(--text-dim)">Nenhum projeto encontrado.</p>';
      return;
    }
    let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem">';
    projetos.forEach(p => {
      const pct = p.progresso || 0;
      const cor = pct >= 80 ? 'var(--green)' : pct >= 50 ? 'var(--gold)' : 'var(--red)';
      const badgeCor = p.status === 'Concluído' ? 'var(--green)' : p.status === 'Em Execução' ? 'var(--cyan)' : p.status === 'Futuro' ? 'var(--gold)' : 'var(--red)';
      html += `<div class="chart-card" style="display:flex;flex-direction:column;gap:.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong style="color:var(--text-bright);font-size:.9rem">${escHtml(p.nome)}</strong>
          <span style="background:${badgeCor};color:#000;border-radius:20px;padding:1px 8px;font-size:.68rem;font-weight:700">${escHtml(p.status)}</span>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem">
          <div class="bar-bg" style="flex:1;height:10px;width:auto"><div class="bar-fill" style="width:${pct}%;background:${cor}"></div></div>
          <span style="font-weight:700;color:${cor};font-size:.85rem">${pct}%</span>
        </div>
        <div style="font-size:.72rem;color:var(--text-dim);display:flex;gap:1rem;flex-wrap:wrap">
          <span>📅 ${escHtml(p.prazo || '—')}</span>
          <span>📦 ${p.tarefas || 0} tarefas</span>
          <span>👤 ${escHtml(p.equipe || '—')}</span>
        </div>
      </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
  } catch (err) {
    container.innerHTML = '<i class="bi bi-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:.75rem;color:var(--red)"></i><p style="color:var(--text-dim)">Erro: ' + err.message + '</p>';
  }
}

// ══════════════════════════════════════════════════════════════
// EQUIPAMENTOS — histórico de chamados
// ══════════════════════════════════════════════════════════════
let equipCache = [];
const EQ_STATUS_COR = { 1:'#06b6d4', 2:'#f97316', 3:'#8b5cf6', 4:'#ef4444', 5:'#22c55e', 6:'#7a8aaa' };

async function carregarEquipamentos() {
  try {
    const res = await fetch('relatorios_equipamentos.php?' + getParams());
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const d = await res.json();
    if (!d.ok) throw new Error(d.error || 'falha');

    equipCache = d.equipamentos || [];

    document.getElementById('eq-kpi-equip').textContent = d.kpis.equip_com_chamado;
    document.getElementById('eq-kpi-vinc').textContent  = d.kpis.total_vinculos;
    document.getElementById('eq-kpi-top').textContent   = d.kpis.top_qtd > 0 ? d.kpis.top_nome : '—';
    document.getElementById('eq-kpi-top-sub').textContent = d.kpis.top_qtd > 0 ? d.kpis.top_qtd + ' chamado(s)' : '—';

    // Select de categorias
    const sel = document.getElementById('eq-filtro-cat');
    const atual = sel.value;
    sel.innerHTML = '<option value="">Todas as categorias</option>' +
      (d.categorias || []).map(c => `<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');
    if (atual) sel.value = atual;

    // Resumo por categoria + gráfico
    const pc = d.por_categoria || [];
    document.getElementById('eq-tbody-cat').innerHTML = pc.length
      ? pc.map(x => `<tr><td>${escHtml(x.categoria)}</td><td>${x.equipamentos}</td><td style="font-weight:700;color:var(--gold)">${x.chamados}</td></tr>`).join('')
      : '<tr><td colspan="3" style="color:var(--text-dim)">Sem dados</td></tr>';

    if (charts.eqCat) charts.eqCat.destroy();
    if (pc.length) {
      charts.eqCat = new ApexCharts(document.getElementById('eq-chart-cat'), {
        ...APEX_DARK,
        chart: { ...APEX_DARK.chart, type: 'bar', height: 260 },
        series: [{ name: 'Chamados', data: pc.map(x => x.chamados) }],
        colors: ['#06b6d4'],
        xaxis: { categories: pc.map(x => x.categoria) },
        plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '65%' } },
        dataLabels: { enabled: true, style: { colors: ['#fff'], fontWeight: 700 } },
        tooltip: { ...APEX_DARK.tooltip, y: { formatter: v => v + ' chamados' } },
      });
      charts.eqCat.render();
    } else {
      document.getElementById('eq-chart-cat').innerHTML = '<p style="color:var(--text-dim);text-align:center;padding:2rem">Sem dados</p>';
    }

    renderEquipTabela();
  } catch (err) {
    document.getElementById('eq-tbody').innerHTML =
      `<tr><td colspan="7" style="color:var(--red)">Erro: ${escHtml(err.message)}</td></tr>`;
  }
}

function renderEquipTabela() {
  const cat = document.getElementById('eq-filtro-cat').value;
  const q   = document.getElementById('eq-filtro-busca').value.trim().toLowerCase();
  const rows = equipCache.filter(e =>
    (!cat || e.categoria === cat) &&
    (!q || (e.nome || '').toLowerCase().includes(q))
  );

  const tbody = document.getElementById('eq-tbody');
  const vazio = document.getElementById('eq-vazio');
  document.getElementById('eq-tabela-count').textContent = rows.length ? `(${rows.length})` : '';

  if (!rows.length) { tbody.innerHTML = ''; vazio.style.display = 'block'; return; }
  vazio.style.display = 'none';

  tbody.innerHTML = rows.map((e, i) => {
    const det = e.chamados.map(c => `
      <tr class="eq-det eq-det-${i}" style="display:none;background:rgba(255,255,255,.02)">
        <td></td>
        <td colspan="3" style="padding-left:1.4rem">
          <a href="chamado.php?id=${c.id}" target="_blank" style="color:var(--cyan);text-decoration:none;font-weight:700">#${c.id}</a>
          <span style="color:var(--text);margin-left:.5rem">${escHtml(c.titulo || '')}</span>
        </td>
        <td><span style="font-size:.7rem;font-weight:700;color:${EQ_STATUS_COR[c.status] || '#7a8aaa'}">${escHtml(c.status_nome)}</span></td>
        <td colspan="2" style="color:var(--text-dim);font-size:.75rem">Aberto ${c.data}${c.fechado ? ' · Fechado ' + c.fechado : ''}</td>
      </tr>`).join('');
    return `
      <tr class="eq-head" style="cursor:pointer" onclick="toggleEquipDet(${i}, this)">
        <td><i class="bi bi-chevron-right eq-chev-${i}" style="display:inline-block;transition:.15s;color:var(--text-dim)"></i></td>
        <td style="font-weight:600;color:var(--text-bright)">${escHtml(e.nome)}</td>
        <td>${escHtml(e.categoria)}</td>
        <td>${escHtml(e.loja)}</td>
        <td style="font-weight:700;color:var(--gold)">${e.num_chamados}</td>
        <td style="color:${e.abertos ? 'var(--red)' : 'var(--text-dim)'};font-weight:700">${e.abertos}</td>
        <td style="color:var(--text-dim)">${e.ultimo || '—'}</td>
      </tr>${det}`;
  }).join('');
}

function toggleEquipDet(i, tr) {
  const dets = document.querySelectorAll('.eq-det-' + i);
  const abrir = dets.length && dets[0].style.display === 'none';
  dets.forEach(d => d.style.display = abrir ? 'table-row' : 'none');
  const chev = document.querySelector('.eq-chev-' + i);
  if (chev) chev.style.transform = abrir ? 'rotate(90deg)' : '';
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  const tabParam = new URLSearchParams(location.search).get('tab');
  if (tabParam) abrirTab(tabParam);
  carregarDados();

  document.getElementById('form-filtros').addEventListener('submit', (e) => {
    e.preventDefault();
    const p = new URLSearchParams(new FormData(e.target));
    history.replaceState(null, '', location.pathname + '?' + p.toString());
    carregarDados();
  });
});
</script>

</body>
</html>
