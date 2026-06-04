<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

// ── Parser de projeto Markdown ─────────────────────────────────────────────
function parseProjeto(string $filepath): ?array {
    $content = @file_get_contents($filepath);
    if (!$content) return null;

    $lines  = explode("\n", str_replace("\r", '', $content));
    $proj   = [
        'titulo'    => '',
        'objetivo'  => '',
        'equipe'    => '',
        'prazo'     => '',
        'repo'      => '',
        'modulos'   => [],
        'cronograma'=> [],
    ];

    $modo        = 'header';
    $moduloAtual = null;
    $subSecao    = null;
    $inCodeBlock = false;

    foreach ($lines as $line) {
        $l = rtrim($line);

        if (preg_match('/^```/', $l)) { $inCodeBlock = !$inCodeBlock; continue; }

        if ($inCodeBlock) {
            if ($modo === 'cronograma' &&
                preg_match('/Semana\s*(\d+)\s*\(([^)]+)\)\s*[→\-]+\s*(.+)/u', $l, $m)) {
                $proj['cronograma'][] = [
                    'semana'   => 'S'.$m[1],
                    'periodo'  => trim($m[2]),
                    'descricao'=> trim($m[3]),
                ];
            }
            continue;
        }

        if (preg_match('/^# (.+)$/u', $l, $m)) {
            $proj['titulo'] = trim($m[1]); $modo = 'header'; continue;
        }
        if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
            $k = mb_strtolower($m[1]);
            if (str_contains($k,'objetivo'))    $proj['objetivo'] = $m[2];
            elseif (str_contains($k,'equipe'))  $proj['equipe']   = $m[2];
            elseif (str_contains($k,'prazo'))   $proj['prazo']    = $m[2];
            elseif (str_contains($k,'reposit')) $proj['repo']     = $m[2];
            continue;
        }
        if (preg_match('/^## (.+)$/u', $l, $m)) {
            if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
            $nome = trim($m[1]);
            if (preg_match('/cronograma/iu', $nome))      { $modo='cronograma'; $moduloAtual=null; }
            elseif (preg_match('/progresso/iu', $nome))   { $modo='tabprog';    $moduloAtual=null; }
            else {
                $modo = 'modulo';
                $moduloAtual = ['nome'=>$nome,'descricao'=>'','prazo'=>'','tarefas'=>[]];
                $subSecao = null;
            }
            continue;
        }
        if ($modo === 'modulo' && preg_match('/^### (.+)$/u', $l, $m)) {
            $subSecao = trim($m[1]); continue;
        }
        if ($modo === 'modulo' && $moduloAtual !== null) {
            if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>true,  'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^> \*\*Prazo:\*\*\s*(.+)/ui', $l, $m)) {
                $v = trim($m[1]);
                // 4+ barras = duas datas = prazo do projeto; 2 barras = prazo do módulo
                if (!$proj['prazo'] && substr_count($v, '/') >= 4)
                    $proj['prazo'] = $v;
                else
                    $moduloAtual['prazo'] = $v;
            }
            elseif (preg_match('/^> (.+)/u', $l, $m) && !count($moduloAtual['tarefas']))
                $moduloAtual['descricao'] = trim($m[1]);
        }
    }
    if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;

    $tot = $done = 0;
    foreach ($proj['modulos'] as &$mod) {
        $mt = count($mod['tarefas']);
        $md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
        $mod['pct']  = $mt > 0 ? round($md / $mt * 100) : 0;
        $mod['done'] = $md;
        $mod['tot']  = $mt;
        $tot  += $mt;
        $done += $md;
    }
    unset($mod);

    $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
    $proj['done']  = $done;
    $proj['total'] = $tot;

    return $proj['titulo'] ? $proj : null;
}

function parsePeriodo(string $periodo, int $ano = 2026): array {
    $p = trim($periodo);
    $p = preg_replace('/[–—-]/', '-', $p);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [mktime(0,0,0,(int)$m[2],(int)$m[1],$ano), mktime(0,0,0,(int)$m[4],(int)$m[3],$ano)];
    if (preg_match('/^(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [mktime(0,0,0,(int)$m[3],(int)$m[1],$ano), mktime(0,0,0,(int)$m[3],(int)$m[2],$ano)];
    return [0, 0];
}

function parseDataBR(string $d): int {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
    return 0;
}

function parsePrazoRange(string $prazo): array {
    // Duas datas separadas por qualquer coisa não-numérica (→, —, >, espaço, etc.)
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\D+(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [parseDataBR($m[1]), parseDataBR($m[2])];
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [0, parseDataBR($m[1])];
    return [0, 0];
}

function corPct(int $pct): string {
    if ($pct >= 80) return '#1e8e3e';
    if ($pct >= 40) return '#f57c00';
    return '#1a73e8';
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Carrega todos os projetos ──────────────────────────────────────────────
$pastaProj = __DIR__ . '/Docs/wiki/projects';
$projetos  = [];
if (is_dir($pastaProj)) {
    foreach (glob($pastaProj . '/*.md') as $arq) {
        $p = parseProjeto($arq);
        if ($p) { $p['arquivo'] = basename($arq); $projetos[] = $p; }
    }
}

// ── Modo: lista (padrão) ou detalhe (?proj=arquivo.md) ────────────────────
$selArq  = $_GET['proj'] ?? null;
$projeto = null;
if ($selArq) {
    foreach ($projetos as $p) {
        if ($p['arquivo'] === $selArq) { $projeto = $p; break; }
    }
}
$modoDetalhe = $projeto !== null;

// ── Gantt (só no detalhe) ──────────────────────────────────────────────────
$ganttBars  = [];
$dataInicio = 0;
$dataFim    = 0;
if ($modoDetalhe && $projeto['cronograma']) {
    foreach ($projeto['cronograma'] as $cr) {
        [$ini, $fim] = parsePeriodo($cr['periodo']);
        if ($ini && $fim) {
            if (!$dataInicio || $ini < $dataInicio) $dataInicio = $ini;
            if ($fim > $dataFim) $dataFim = $fim;
            $ganttBars[] = array_merge($cr, ['ini'=>$ini,'fim'=>$fim]);
        }
    }
}
$totalDias = ($dataInicio && $dataFim) ? max(1,($dataFim-$dataInicio)/86400) : 0;
$hoje      = mktime(0,0,0,date('n'),date('j'),date('Y'));

// ── Status e Previsão de Término ──────────────────────────────────────────
$projInicio = $projFim2 = 0;
$pctEsperado = $diasDecorridos = $totalDiasPrazo = 0;
$statusProj  = 'sem_data';
$dataForecast = $diasForecast = null;

if ($modoDetalhe && $projeto['prazo']) {
    [$projInicio, $projFim2] = parsePrazoRange($projeto['prazo']);
    if ($projInicio && $projFim2) {
        $totalDiasPrazo = max(1, ($projFim2 - $projInicio) / 86400);
        $diasDecorridos = max(0, ($hoje - $projInicio) / 86400);
        $pctEsperado    = min(100, round($diasDecorridos / $totalDiasPrazo * 100));
        $pctAtual       = $projeto['pct'];

        if ($diasDecorridos >= 1) {
            $taxa         = $pctAtual / max(1, $diasDecorridos);
            $diasForecast = $taxa > 0 ? (int) ceil((100 - $pctAtual) / $taxa) : null;
        } else {
            $diasForecast = $totalDiasPrazo > 0
                ? (int) ceil($totalDiasPrazo * (100 - $pctAtual) / 100)
                : null;
        }
        $dataForecast = $diasForecast !== null ? $hoje + $diasForecast * 86400 : null;

        $diff = $pctAtual - $pctEsperado;
        if ($pctAtual >= 100)    $statusProj = 'concluido';
        elseif ($diff >= 10)     $statusProj = 'adiantado';
        elseif ($diff >= -10)    $statusProj = 'no_prazo';
        elseif ($diff >= -25)    $statusProj = 'atencao';
        else                     $statusProj = 'atrasado';
    }
}

function barPct(int $ts, int $inicio, int $total): float {
    return $total > 0 ? round(($ts-$inicio)/86400/$total*100,2) : 0;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Projetos de TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
:root { --primary:#1a237e; }
body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; font-size:.9rem; }
.topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff;
          padding:.75rem 1.5rem; display:flex; align-items:center;
          justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
.topbar a { color:#fff; text-decoration:none; font-size:.82rem;
            background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
.topbar a:hover { background:rgba(255,255,255,.25); }
.hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff;
        padding:1.75rem 1rem 3.5rem; text-align:center; }
.wrap { max-width:1200px; margin:-2rem auto 3rem; padding:0 1rem; }

/* ── CARDS ─────────────────────────────────────────────────── */
.proj-card { background:#fff; border-radius:14px; border:1px solid #e5e7eb;
             box-shadow:0 2px 10px rgba(0,0,0,.06); padding:1.35rem;
             cursor:pointer; transition:all .18s; text-decoration:none; color:inherit;
             display:block; }
.proj-card:hover { box-shadow:0 6px 24px rgba(26,35,126,.13);
                   border-color:#1a237e; transform:translateY(-2px); color:inherit; }
.proj-card-title { font-size:1rem; font-weight:700; margin-bottom:.2rem;
                   display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.proj-card-desc  { font-size:.78rem; color:#6b7280; margin-bottom:.75rem;
                   display:-webkit-box; -webkit-line-clamp:2;
                   -webkit-box-orient:vertical; overflow:hidden; }
.prog-bar  { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.prog-fill { height:100%; border-radius:4px; transition:width .6s ease; }
.prog-label { font-size:.75rem; font-weight:700; min-width:36px; text-align:right; }

/* Mini módulos no card */
.mod-mini { margin-top:.85rem; display:flex; flex-direction:column; gap:.35rem; }
.mod-mini-row { display:flex; align-items:center; gap:.5rem; }
.mod-mini-name { font-size:.76rem; color:#374151; flex:1;
                 white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mod-mini-bar  { width:80px; height:5px; border-radius:3px;
                 background:#e5e7eb; flex-shrink:0; overflow:hidden; }
.mod-mini-fill { height:100%; border-radius:3px; }
.mod-mini-pct  { font-size:.68rem; font-weight:700; min-width:28px; text-align:right; }
.mod-mais      { font-size:.72rem; color:#9ca3af; margin-top:.25rem; }

/* Meta info */
.card-meta { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.85rem;
             padding-top:.75rem; border-top:1px solid #f3f4f6; }
.meta-pill { font-size:.72rem; color:#6b7280; display:flex; align-items:center; gap:.25rem; }
.btn-detalhe { font-size:.75rem; font-weight:700; color:#1a237e;
               display:flex; align-items:center; gap:.25rem; margin-left:auto; }

/* ── DETALHE ───────────────────────────────────────────────── */
.card-box { background:#fff; border-radius:12px; border:1px solid #e5e7eb;
            box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.25rem; margin-bottom:1rem; }
.gantt-wrap  { overflow-x:auto; }
.gantt       { min-width:600px; }
.gantt-header { display:flex; border-bottom:2px solid #e5e7eb; margin-bottom:.25rem; }
.gantt-col-label { width:190px; flex-shrink:0; font-size:.75rem; font-weight:700;
                   color:#6b7280; padding:.25rem 0; }
.gantt-timeline { flex:1; display:flex; }
.gantt-week { flex:1; font-size:.68rem; font-weight:600; color:#9ca3af;
              text-align:center; border-left:1px dashed #e5e7eb; padding:.2rem 2px; }
.gantt-row { display:flex; align-items:center; margin-bottom:.4rem; min-height:32px; }
.gantt-label { width:190px; flex-shrink:0; font-size:.76rem; color:#374151;
               font-weight:600; padding-right:.5rem;
               white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gantt-track { flex:1; position:relative; height:22px; background:#f3f4f6;
               border-radius:4px; overflow:hidden; }
.gantt-bar { position:absolute; top:2px; height:18px; border-radius:4px;
             display:flex; align-items:center; padding:0 6px;
             font-size:.65rem; font-weight:700; color:#fff;
             white-space:nowrap; overflow:hidden; }
.gantt-bar:hover { opacity:.85; }
.gantt-today { position:absolute; top:0; bottom:0; width:2px;
               background:#ef4444; z-index:5; }
.gantt-today-label { position:absolute; top:-15px; font-size:.6rem;
                     color:#ef4444; font-weight:700; transform:translateX(-50%); }
.mod-header { display:flex; align-items:center; gap:.5rem; cursor:pointer;
              padding:.5rem 0; border-bottom:1px solid #f3f4f6; user-select:none; }
.mod-header:hover { color:#1a237e; }
.mod-body { padding:.5rem 0 .25rem 1rem; }
.task-item { display:flex; align-items:flex-start; gap:.5rem;
             padding:.2rem 0; font-size:.8rem; color:#374151; }
.task-item.done { color:#9ca3af; text-decoration:line-through; }
.sub-label { font-size:.68rem; font-weight:700; color:#9ca3af;
             text-transform:uppercase; letter-spacing:.04em; margin:.5rem 0 .2rem; }
.stat-pill { background:#fff; border:1px solid #e5e7eb; border-radius:8px;
             padding:.4rem .9rem; font-size:.8rem; font-weight:600;
             display:inline-flex; align-items:center; gap:.35rem;
             box-shadow:0 1px 3px rgba(0,0,0,.04); }
.badge-obsidian { background:#7c3aed; color:#fff; font-size:.65rem;
                  padding:.15rem .5rem; border-radius:8px; font-weight:600; }

/* ── Status e Previsão ─────────────────────────────────────────── */
.status-badge { display:inline-flex; align-items:center; gap:.35rem;
                padding:.28rem .75rem; border-radius:20px; font-size:.75rem; font-weight:700; }
.status-concluido { background:#d1fae5; color:#065f46; }
.status-adiantado { background:#dbeafe; color:#1e40af; }
.status-no_prazo  { background:#dcfce7; color:#166534; }
.status-atencao   { background:#fef9c3; color:#854d0e; }
.status-atrasado  { background:#fee2e2; color:#991b1b; }
.mod-prazo { font-size:.67rem; margin:.2rem 0 .28rem; }
</style>
</head>
<body>

<div class="topbar">
  <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem">
    <i class="bi bi-kanban-fill"></i>
    <?php if ($modoDetalhe): ?>
      <a href="projetos.php" style="background:none;padding:0;font-weight:400;font-size:.85rem;opacity:.8">
        Projetos
      </a>
      <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
      <?= esc(mb_substr($projeto['titulo'],0,40)) ?>
    <?php else: ?>
      Projetos de TI
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:.5rem">
    <?php if ($modoDetalhe): ?>
      <a href="projetos.php"><i class="bi bi-grid-3x3-gap me-1"></i>Todos os projetos</a>
    <?php endif; ?>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-kanban-fill me-2"></i>
    <?= $modoDetalhe ? esc($projeto['titulo']) : 'Projetos de TI' ?>
  </h1>
  <p style="opacity:.8;margin-top:.35rem;font-size:.85rem">
    <?php if ($modoDetalhe): ?>
      <?= esc($projeto['objetivo']) ?>
    <?php else: ?>
      Acompanhe o progresso de cada projeto
      <span class="badge-obsidian ms-2"><i class="bi bi-journal-bookmark me-1"></i>Obsidian</span>
    <?php endif; ?>
  </p>
</div>

<div class="wrap">

<?php if (!$projetos): ?>
  <div class="card-box text-center py-5 text-muted">
    <i class="bi bi-folder-x fs-1 d-block mb-2"></i>
    <p>Nenhum projeto em <code>Docs/wiki/projects/</code></p>
    <p class="small">Crie um arquivo <code>.md</code> no Obsidian para aparecer aqui.</p>
  </div>

<?php elseif (!$modoDetalhe): ?>
  <!-- ═══════════════ LISTA DE CARDS ═══════════════ -->
  <div class="row g-3">
  <?php foreach ($projetos as $p):
      $modsVisiveis = array_filter($p['modulos'], fn($m) => $m['tot'] > 0);
      $modsVisiveis = array_values($modsVisiveis);
      $exibir = array_slice($modsVisiveis, 0, 5);
      $extras  = max(0, count($modsVisiveis) - 5);
  ?>
    <div class="col-md-6 col-xl-4">
      <a href="projetos.php?proj=<?= urlencode($p['arquivo']) ?>" class="proj-card h-100">

        <!-- Título + % -->
        <div class="proj-card-title">
          <span><?= esc($p['titulo']) ?></span>
          <span style="font-size:1.1rem;font-weight:800;color:<?= corPct($p['pct']) ?>;flex-shrink:0">
            <?= $p['pct'] ?>%
          </span>
        </div>

        <!-- Descrição -->
        <?php if ($p['objetivo']): ?>
          <div class="proj-card-desc"><?= esc($p['objetivo']) ?></div>
        <?php endif; ?>

        <!-- Barra geral -->
        <div class="d-flex align-items-center gap-2">
          <div class="prog-bar flex-grow-1">
            <div class="prog-fill" style="width:<?= $p['pct'] ?>%;background:<?= corPct($p['pct']) ?>"></div>
          </div>
          <span class="prog-label" style="color:<?= corPct($p['pct']) ?>">
            <?= $p['done'] ?>/<?= $p['total'] ?>
          </span>
        </div>

        <!-- Mini módulos -->
        <?php if ($exibir): ?>
        <div class="mod-mini">
          <?php foreach ($exibir as $mod): ?>
            <div class="mod-mini-row">
              <span class="mod-mini-name"><?= esc($mod['nome']) ?></span>
              <div class="mod-mini-bar">
                <div class="mod-mini-fill" style="width:<?= $mod['pct'] ?>%;background:<?= corPct($mod['pct']) ?>"></div>
              </div>
              <span class="mod-mini-pct" style="color:<?= corPct($mod['pct']) ?>"><?= $mod['pct'] ?>%</span>
            </div>
          <?php endforeach; ?>
          <?php if ($extras > 0): ?>
            <div class="mod-mais">+ <?= $extras ?> módulos</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Meta info + botão -->
        <div class="card-meta">
          <?php if ($p['equipe']): ?>
            <span class="meta-pill"><i class="bi bi-people"></i><?= esc($p['equipe']) ?></span>
          <?php endif; ?>
          <?php if ($p['prazo']): ?>
            <span class="meta-pill"><i class="bi bi-calendar-check"></i><?= esc($p['prazo']) ?></span>
          <?php endif; ?>
          <span class="btn-detalhe">
            Ver detalhes <i class="bi bi-arrow-right"></i>
          </span>
        </div>

      </a>
    </div>
  <?php endforeach; ?>
  </div>

<?php else: ?>
  <!-- ═══════════════ DETALHE DO PROJETO ═══════════════ -->

  <!-- Header -->
  <div class="card-box">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <h2 style="font-size:1.1rem;font-weight:700;margin:0"><?= esc($projeto['titulo']) ?></h2>
        <?php if ($projeto['objetivo']): ?>
          <p style="color:#6b7280;font-size:.82rem;margin:.3rem 0 0"><?= esc($projeto['objetivo']) ?></p>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($projeto['equipe']): ?>
          <span class="stat-pill"><i class="bi bi-people text-primary"></i><?= esc($projeto['equipe']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['prazo']): ?>
          <span class="stat-pill"><i class="bi bi-calendar-check text-danger"></i><?= esc($projeto['prazo']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['repo']): ?>
          <a href="<?= esc($projeto['repo']) ?>" target="_blank" class="stat-pill text-decoration-none">
            <i class="bi bi-github"></i>GitHub
          </a>
        <?php endif; ?>
        <?php
        $sIcons  = ['concluido'=>'check-circle-fill','adiantado'=>'arrow-up-circle-fill',
                    'no_prazo'=>'check-circle','atencao'=>'exclamation-triangle-fill','atrasado'=>'x-circle-fill'];
        $sLabels = ['concluido'=>'Concluído','adiantado'=>'Adiantado',
                    'no_prazo'=>'No prazo','atencao'=>'Atenção','atrasado'=>'Em atraso'];
        if (isset($sLabels[$statusProj])): ?>
          <span class="status-badge status-<?= $statusProj ?>">
            <i class="bi bi-<?= $sIcons[$statusProj] ?>"></i><?= $sLabels[$statusProj] ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3 mb-1">
      <div class="prog-bar flex-grow-1">
        <div class="prog-fill" style="width:<?= $projeto['pct'] ?>%;background:<?= corPct($projeto['pct']) ?>"></div>
      </div>
      <span style="font-weight:700;font-size:.9rem;color:<?= corPct($projeto['pct']) ?>;min-width:40px">
        <?= $projeto['pct'] ?>%
      </span>
    </div>
    <div style="font-size:.75rem;color:#9ca3af">
      <?= $projeto['done'] ?> / <?= $projeto['total'] ?> tarefas · <?= count($projeto['modulos']) ?> módulos
    </div>
  </div>

  <!-- ── Previsão de Término ──────────────────────────────────────────── -->
  <?php if ($projInicio && $projFim2):
        $svgW = 560; $svgH = 185;
        $padL = 44; $padR = 18; $padT = 22; $padB = 42;
        $cW = $svgW - $padL - $padR;
        $cH = $svgH - $padT - $padB;
        $xMax   = max($projFim2, $dataForecast ?? $projFim2);
        $xRange = max(1, $xMax - $projInicio) * 1.08;
        $px = fn($ts) => round($padL + ($ts - $projInicio) / $xRange * $cW, 1);
        $py = fn($pct) => round($padT + $cH * (1 - $pct / 100), 1);
        $xFim   = $px($projFim2);
        $xToday = $px($hoje);
        $xFcPx  = $dataForecast ? min($px($dataForecast), $padL + $cW) : null;
        $yActual = $py($projeto['pct']);
        $yBot    = $py(0);
        $dotColor = match($statusProj) {
            'concluido','adiantado','no_prazo' => '#1e8e3e',
            'atencao' => '#f57c00',
            default   => '#ef4444',
        };
        $modsPrazo = array_filter($projeto['modulos'], fn($m) => !empty($m['prazo']));
  ?>
  <div class="card-box">
    <h6 style="font-weight:700;margin-bottom:.75rem">
      <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Cronograma de Previsão de Término
    </h6>
    <svg viewBox="0 0 <?=$svgW?> <?=$svgH?>" style="width:100%;height:auto;display:block">
      <!-- Y grid -->
      <?php foreach ([0,25,50,75,100] as $g):
            $gy = $py($g); ?>
        <line x1="<?=$padL?>" y1="<?=$gy?>" x2="<?=$padL+$cW?>" y2="<?=$gy?>"
              stroke="<?=$g==0?'#d1d5db':'#f3f4f6'?>" stroke-width="<?=$g==0?'1.5':'1'?>"/>
        <text x="<?=$padL-5?>" y="<?=$gy+3.5?>" text-anchor="end" font-size="9" fill="#9ca3af"><?=$g?>%</text>
      <?php endforeach; ?>

      <!-- Planned line (0% at start → 100% at deadline) -->
      <line x1="<?=$padL?>" y1="<?=$yBot?>" x2="<?=$xFim?>" y2="<?=$py(100)?>"
            stroke="#93c5fd" stroke-width="2" stroke-dasharray="6,3"/>

      <!-- Module deadlines -->
      <?php foreach ($modsPrazo as $mod):
            $mts = parseDataBR($mod['prazo']);
            if (!$mts) continue;
            $mx  = $px($mts);
            $mc  = ($mts < $hoje && $mod['pct'] < 100) ? '#ef4444' : '#d1d5db';
      ?>
        <line x1="<?=$mx?>" y1="<?=$py(100)?>" x2="<?=$mx?>" y2="<?=$yBot?>"
              stroke="<?=$mc?>" stroke-width="1" stroke-dasharray="2,3" opacity="0.55"/>
        <circle cx="<?=$mx?>" cy="<?=$py($mod['pct'])?>" r="3.5" fill="<?=$mc?>" opacity="0.85"/>
      <?php endforeach; ?>

      <!-- Forecast line -->
      <?php if ($xFcPx): ?>
        <line x1="<?=$xToday?>" y1="<?=$yActual?>" x2="<?=$xFcPx?>" y2="<?=$py(100)?>"
              stroke="#9ca3af" stroke-width="2" stroke-dasharray="8,4" opacity="0.8"/>
        <?php if ($dataForecast && abs($dataForecast - $projFim2) > 86400): ?>
          <text x="<?=$xFcPx?>" y="<?=$svgH-4?>" text-anchor="middle"
                font-size="9" fill="#6b7280">Prev.&nbsp;<?=date('d/m',$dataForecast)?></text>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Deadline vertical -->
      <line x1="<?=$xFim?>" y1="<?=$padT?>" x2="<?=$xFim?>" y2="<?=$yBot?>"
            stroke="#3b82f6" stroke-width="1.5" stroke-dasharray="5,3" opacity="0.6"/>
      <text x="<?=$xFim?>" y="<?=$svgH-4?>" text-anchor="middle"
            font-size="9" fill="#3b82f6" font-weight="600">Prazo&nbsp;<?=date('d/m',$projFim2)?></text>

      <!-- Today line -->
      <line x1="<?=$xToday?>" y1="<?=$padT?>" x2="<?=$xToday?>" y2="<?=$yBot?>"
            stroke="#ef4444" stroke-width="2" stroke-dasharray="4,2" opacity="0.7"/>
      <text x="<?=$xToday?>" y="<?=$svgH-4?>"
            text-anchor="<?=$xToday > $padL+$cW*0.88 ? 'end' : 'middle'?>"
            font-size="9" fill="#ef4444" font-weight="600">Hoje</text>

      <!-- Progress dot -->
      <circle cx="<?=$xToday?>" cy="<?=$yActual?>" r="7" fill="<?=$dotColor?>" stroke="#fff" stroke-width="2"/>
      <text x="<?=$xToday+11?>" y="<?=$yActual+4?>" font-size="10" fill="<?=$dotColor?>" font-weight="700"><?=$projeto['pct']?>%</text>

      <!-- Legend -->
      <line x1="<?=$padL+5?>" y1="13" x2="<?=$padL+22?>" y2="13" stroke="#93c5fd" stroke-width="2" stroke-dasharray="6,3"/>
      <text x="<?=$padL+26?>" y="17" font-size="9" fill="#6b7280">Planejado</text>
      <line x1="<?=$padL+82?>" y1="13" x2="<?=$padL+99?>" y2="13" stroke="#9ca3af" stroke-width="2" stroke-dasharray="8,4"/>
      <text x="<?=$padL+103?>" y="17" font-size="9" fill="#6b7280">Previsão atual</text>
      <circle cx="<?=$padL+175?>" cy="13" r="4" fill="<?=$dotColor?>"/>
      <text x="<?=$padL+182?>" y="17" font-size="9" fill="#6b7280">Progresso real</text>
    </svg>

    <div class="d-flex flex-wrap gap-3 mt-2 pt-2" style="font-size:.75rem;color:#6b7280;border-top:1px solid #f3f4f6">
      <?php if ($projInicio): ?>
        <span><i class="bi bi-play-circle me-1"></i><strong>Início:</strong> <?=date('d/m/Y',$projInicio)?></span>
      <?php endif; ?>
      <span><i class="bi bi-flag me-1"></i><strong>Prazo:</strong> <?=date('d/m/Y',$projFim2)?></span>
      <?php if ($diasDecorridos > 0): ?>
        <span><i class="bi bi-bar-chart me-1"></i><strong>Esperado hoje:</strong> <?=$pctEsperado?>%
          · <strong>Real:</strong> <?=$projeto['pct']?>%
          <span style="color:<?=$projeto['pct']>=$pctEsperado?'#1e8e3e':'#ef4444'?>">
            (<?=$projeto['pct']>=$pctEsperado?'+':''?><?=$projeto['pct']-$pctEsperado?>pp)
          </span>
        </span>
      <?php endif; ?>
      <?php if ($dataForecast): ?>
        <span><i class="bi bi-calendar-check me-1"></i><strong>Conclusão prevista:</strong>
          <?=date('d/m/Y',$dataForecast)?>
          <?php if ($dataForecast <= $projFim2): ?>
            <span style="color:#1e8e3e">✓ dentro do prazo</span>
          <?php else: ?>
            <span style="color:#ef4444">✗ <?=round(($dataForecast-$projFim2)/86400)?> dias de atraso</span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Gantt -->
  <?php if ($ganttBars): ?>
  <div class="card-box">
    <h6 style="font-weight:700;margin-bottom:.75rem">
      <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Cronograma — Linha do Tempo
    </h6>
    <div class="gantt-wrap"><div class="gantt">
      <div class="gantt-header">
        <div class="gantt-col-label">Etapa</div>
        <div class="gantt-timeline">
          <?php foreach ($ganttBars as $bar): ?>
            <div class="gantt-week"><?= esc($bar['semana']) ?><br><?= esc($bar['periodo']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php
      $cores = ['#1a73e8','#1e8e3e','#f57c00','#7b1fa2','#c62828','#0097a7'];
      $hojePct = ($dataInicio && $totalDias) ? barPct($hoje,$dataInicio,$totalDias) : -1;
      foreach ($ganttBars as $i => $bar):
          $left  = barPct($bar['ini'],$dataInicio,$totalDias);
          $width = barPct($bar['fim'],$dataInicio,$totalDias) - $left;
          $cor   = $cores[$i % count($cores)];
          $isPast= $bar['fim'] < $hoje;
      ?>
      <div class="gantt-row">
        <div class="gantt-label" title="<?= esc($bar['descricao']) ?>">
          <?= esc($bar['semana']) ?> — <?= esc(mb_substr($bar['descricao'],0,28)) ?>…
        </div>
        <div class="gantt-track">
          <?php if ($hojePct >= 0 && $hojePct <= 100): ?>
            <div class="gantt-today" style="left:<?= $hojePct ?>%">
              <?php if ($i===0): ?><span class="gantt-today-label">Hoje</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="gantt-bar"
               style="left:<?= $left ?>%;width:<?= max($width,2) ?>%;background:<?= $cor ?>;opacity:<?= $isPast?.5:1 ?>"
               title="<?= esc($bar['descricao']) ?>">
            <?= esc($bar['periodo']) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div></div>
    <div style="font-size:.72rem;color:#9ca3af;margin-top:.5rem">
      <span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:50%;margin-right:4px"></span>Hoje
      &nbsp;·&nbsp; Barras opacas = semanas passadas
    </div>
  </div>
  <?php endif; ?>

  <!-- Módulos -->
  <div class="row g-2">
  <?php foreach ($projeto['modulos'] as $idx => $mod):
      if ($mod['tot'] === 0) continue; ?>
    <div class="col-md-6">
      <div class="card-box" style="padding:1rem">
        <div class="mod-header" onclick="toggleMod(<?= $idx ?>)">
          <i class="bi bi-chevron-right" id="chv-<?= $idx ?>" style="font-size:.75rem;color:#9ca3af;transition:transform .2s"></i>
          <span style="font-weight:700;font-size:.85rem;flex:1"><?= esc($mod['nome']) ?></span>
          <span style="font-size:.72rem;font-weight:700;color:<?= corPct($mod['pct']) ?>"><?= $mod['done'] ?>/<?= $mod['tot'] ?></span>
        </div>
        <div class="prog-bar" style="height:5px;margin:.3rem 0">
          <div class="prog-fill" style="width:<?= $mod['pct'] ?>%;background:<?= corPct($mod['pct']) ?>"></div>
        </div>
        <?php if (!empty($mod['prazo'])):
              $mts = parseDataBR($mod['prazo']);
              $mAtrasado = $mts && $mts < $hoje && $mod['pct'] < 100; ?>
          <div class="mod-prazo" style="color:<?= $mAtrasado ? '#ef4444' : '#9ca3af' ?>">
            <i class="bi bi-calendar<?= $mAtrasado ? '-x' : '' ?> me-1"></i><?php
            if ($mAtrasado) echo '<strong>Em atraso</strong> · '; ?>Prazo: <?= esc($mod['prazo']) ?>
          </div>
        <?php endif; ?>
        <div id="mod-body-<?= $idx ?>" style="display:none">
          <?php $subAtual = null;
          foreach ($mod['tarefas'] as $t):
              if ($t['sub'] !== $subAtual):
                  $subAtual = $t['sub'];
                  if ($subAtual): ?><div class="sub-label"><?= esc($subAtual) ?></div><?php endif;
              endif; ?>
            <div class="task-item <?= $t['done']?'done':'' ?>">
              <span style="flex-shrink:0;margin-top:1px">
                <?= $t['done'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle" style="color:#d1d5db"></i>' ?>
              </span>
              <span><?= esc($t['texto']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <div style="text-align:center;margin-top:1.5rem;font-size:.75rem;color:#9ca3af">
    <i class="bi bi-journal-bookmark me-1"></i>
    <code>Docs/wiki/projects/<?= esc($projeto['arquivo']) ?></code>
    · Edite no Obsidian e recarregue · <?= date('d/m/Y H:i') ?>
  </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleMod(idx) {
  const b = document.getElementById('mod-body-' + idx);
  const c = document.getElementById('chv-' + idx);
  const open = b.style.display !== 'none';
  b.style.display     = open ? 'none' : '';
  c.style.transform   = open ? '' : 'rotate(90deg)';
}
</script>
</body>
</html>
