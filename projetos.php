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

        // toggle bloco de código (```)
        if (preg_match('/^```/', $l)) {
            $inCodeBlock = !$inCodeBlock;
            continue;
        }

        // dentro de bloco de código: só parseia cronograma
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

        // H1 — título
        if (preg_match('/^# (.+)$/u', $l, $m)) {
            $proj['titulo'] = trim($m[1]);
            $modo = 'header';
            continue;
        }

        // Metadados (> **Chave:** valor)
        if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
            $k = mb_strtolower($m[1]);
            if (str_contains($k,'objetivo'))    $proj['objetivo'] = $m[2];
            elseif (str_contains($k,'equipe'))  $proj['equipe']   = $m[2];
            elseif (str_contains($k,'prazo'))   $proj['prazo']    = $m[2];
            elseif (str_contains($k,'reposit')) $proj['repo']     = $m[2];
            continue;
        }

        // H2 — módulo ou seção especial
        if (preg_match('/^## (.+)$/u', $l, $m)) {
            if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
            $nome = trim($m[1]);

            if (preg_match('/cronograma/iu', $nome)) {
                $modo = 'cronograma'; $moduloAtual = null;
            } elseif (preg_match('/progresso\s+geral/iu', $nome)) {
                $modo = 'tabprog'; $moduloAtual = null;
            } else {
                $modo = 'modulo';
                $moduloAtual = ['nome'=>$nome,'descricao'=>'','tarefas'=>[]];
                $subSecao = null;
            }
            continue;
        }

        // H3 — subseção dentro do módulo
        if ($modo === 'modulo' && preg_match('/^### (.+)$/u', $l, $m)) {
            $subSecao = trim($m[1]);
            continue;
        }

        // Tarefas
        if ($modo === 'modulo' && $moduloAtual !== null) {
            if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>true,  'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^> (.+)/u', $l, $m) && !count($moduloAtual['tarefas']))
                $moduloAtual['descricao'] = trim($m[1]);
        }
    }

    if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;

    // % total calculado das tarefas
    $tot = $done = 0;
    foreach ($proj['modulos'] as $mod) {
        $tot  += count($mod['tarefas']);
        $done += count(array_filter($mod['tarefas'], fn($t) => $t['done']));
    }
    $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
    $proj['done']  = $done;
    $proj['total'] = $tot;

    return $proj['titulo'] ? $proj : null;
}

// ── Converte "dd/mm" ou "dd–dd/mm" para timestamp ─────────────────────────
function parseData(string $str, int $ano = 2026): ?int {
    $str = trim($str);
    // "02/07" → dia/mes
    if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $str, $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],$ano);
    // "03" sem mês — não conseguimos parsear sem contexto
    return null;
}

function parsePeriodo(string $periodo, int $ano = 2026): array {
    // "03–10/06"  "25/06–01/07"  "02–03/07"
    $p = trim($periodo);
    $p = preg_replace('/[–—-]/', '-', $p); // normaliza traços

    // "25/06-01/07"
    if (preg_match('/^(\d{1,2})\/(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [
            mktime(0,0,0,(int)$m[2],(int)$m[1],$ano),
            mktime(0,0,0,(int)$m[4],(int)$m[3],$ano),
        ];

    // "03-10/06"
    if (preg_match('/^(\d{1,2})-(\d{1,2})\/(\d{1,2})$/', $p, $m))
        return [
            mktime(0,0,0,(int)$m[3],(int)$m[1],$ano),
            mktime(0,0,0,(int)$m[3],(int)$m[2],$ano),
        ];

    return [0, 0];
}

// ── Carrega projetos ───────────────────────────────────────────────────────
$pastaProj = __DIR__ . '/Docs/wiki/projects';
$projetos  = [];
if (is_dir($pastaProj)) {
    foreach (glob($pastaProj . '/*.md') as $arq) {
        $p = parseProjeto($arq);
        if ($p) { $p['arquivo'] = basename($arq); $projetos[] = $p; }
    }
}

// Projeto selecionado (query string ?proj=nome-do-arquivo)
$selArq  = $_GET['proj'] ?? ($projetos[0]['arquivo'] ?? null);
$projeto = null;
foreach ($projetos as $p) {
    if ($p['arquivo'] === $selArq) { $projeto = $p; break; }
}
if (!$projeto && $projetos) $projeto = $projetos[0];

// ── Calcula dados do Gantt ─────────────────────────────────────────────────
$ganttBars  = [];
$dataInicio = 0;
$dataFim    = 0;
if ($projeto && $projeto['cronograma']) {
    foreach ($projeto['cronograma'] as $cr) {
        [$ini, $fim] = parsePeriodo($cr['periodo']);
        if ($ini && $fim) {
            if (!$dataInicio || $ini < $dataInicio) $dataInicio = $ini;
            if ($fim > $dataFim) $dataFim = $fim;
            $ganttBars[] = array_merge($cr, ['ini'=>$ini,'fim'=>$fim]);
        }
    }
}
$totalDias = ($dataInicio && $dataFim) ? max(1, ($dataFim - $dataInicio) / 86400) : 0;
$hoje      = mktime(0,0,0, date('n'), date('j'), date('Y'));

function barPct(int $ts, int $inicio, int $total): float {
    return $total > 0 ? round(($ts - $inicio) / 86400 / $total * 100, 2) : 0;
}

function corPct(int $pct): string {
    if ($pct >= 80) return '#1e8e3e';
    if ($pct >= 40) return '#f57c00';
    return '#1a73e8';
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
:root { --primary:#1a237e; --accent:#e91e63; }
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

/* Nav de projetos */
.proj-nav { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.proj-nav a { background:#fff; border:1px solid #e5e7eb; border-radius:8px;
              padding:.4rem .9rem; font-size:.8rem; font-weight:600; color:#374151;
              text-decoration:none; transition:all .15s; }
.proj-nav a:hover { border-color:#1a237e; color:#1a237e; }
.proj-nav a.ativo { background:#1a237e; color:#fff; border-color:#1a237e; }

/* Card principal */
.card-box { background:#fff; border-radius:12px; border:1px solid #e5e7eb;
            box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.25rem; margin-bottom:1rem; }

/* Progresso */
.prog-bar  { height:10px; border-radius:5px; background:#e5e7eb; overflow:hidden; }
.prog-fill { height:100%; border-radius:5px; transition:width .6s ease; }

/* ── GANTT ──────────────────────────────────────────────────────────────── */
.gantt-wrap { overflow-x:auto; }
.gantt { min-width:600px; }
.gantt-header { display:flex; border-bottom:2px solid #e5e7eb; margin-bottom:.25rem; }
.gantt-col-label { width:180px; flex-shrink:0; font-size:.75rem; font-weight:700;
                   color:#6b7280; padding:.25rem 0; }
.gantt-timeline { flex:1; position:relative; display:flex; }
.gantt-week { flex:1; font-size:.68rem; font-weight:600; color:#9ca3af;
              text-align:center; border-left:1px dashed #e5e7eb; padding:.2rem 2px; }
.gantt-row { display:flex; align-items:center; margin-bottom:.4rem; min-height:32px; }
.gantt-label { width:180px; flex-shrink:0; font-size:.76rem; color:#374151;
               font-weight:600; padding-right:.5rem;
               white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gantt-track { flex:1; position:relative; height:22px; background:#f3f4f6;
               border-radius:4px; overflow:hidden; }
.gantt-bar { position:absolute; top:2px; height:18px; border-radius:4px;
             display:flex; align-items:center; padding:0 6px;
             font-size:.65rem; font-weight:700; color:#fff;
             white-space:nowrap; overflow:hidden; transition:opacity .2s; }
.gantt-bar:hover { opacity:.85; }
.gantt-today { position:absolute; top:0; bottom:0; width:2px;
               background:#ef4444; z-index:5; }
.gantt-today-label { position:absolute; top:-16px; font-size:.6rem;
                     color:#ef4444; font-weight:700; transform:translateX(-50%); }

/* Módulos */
.mod-header { display:flex; align-items:center; gap:.5rem; cursor:pointer;
              padding:.5rem 0; border-bottom:1px solid #f3f4f6;
              user-select:none; }
.mod-header:hover { color:#1a237e; }
.mod-body { padding:.5rem 0 .25rem 1rem; }
.task-item { display:flex; align-items:flex-start; gap:.5rem;
             padding:.2rem 0; font-size:.8rem; color:#374151; }
.task-item.done { color:#9ca3af; text-decoration:line-through; }
.task-check { flex-shrink:0; margin-top:1px; }
.sub-label { font-size:.68rem; font-weight:700; color:#9ca3af;
             text-transform:uppercase; letter-spacing:.04em;
             margin:.5rem 0 .2rem; }

/* Stats */
.stat-pill { background:#fff; border:1px solid #e5e7eb; border-radius:8px;
             padding:.4rem .9rem; font-size:.8rem; font-weight:600;
             display:inline-flex; align-items:center; gap:.35rem;
             box-shadow:0 1px 3px rgba(0,0,0,.04); }
.badge-obsidian { background:#7c3aed; color:#fff; font-size:.65rem;
                  padding:.15rem .5rem; border-radius:8px; font-weight:600; }
</style>
</head>
<body>

<div class="topbar">
  <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem">
    <i class="bi bi-kanban-fill"></i> Projetos de TI
  </div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0">
    <i class="bi bi-kanban-fill me-2"></i>Projetos de TI
  </h1>
  <p style="opacity:.8;margin-top:.35rem;font-size:.85rem">
    Documentação lida direto do Obsidian
    <span class="badge-obsidian ms-2"><i class="bi bi-journal-bookmark me-1"></i>Obsidian</span>
  </p>
</div>

<div class="wrap">

<?php if (!$projetos): ?>
  <div class="card-box text-center py-5 text-muted">
    <i class="bi bi-folder-x fs-1 d-block mb-2"></i>
    <p>Nenhum projeto encontrado em <code>Docs/wiki/projects/</code></p>
    <p class="small">Crie um arquivo <code>.md</code> no Obsidian para aparecer aqui.</p>
  </div>
<?php else: ?>

  <!-- Nav de projetos -->
  <?php if (count($projetos) > 1): ?>
  <div class="proj-nav">
    <?php foreach ($projetos as $p): ?>
      <a href="?proj=<?= urlencode($p['arquivo']) ?>"
         class="<?= $p['arquivo'] === $selArq ? 'ativo' : '' ?>">
        <?= htmlspecialchars($p['titulo']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Header do projeto -->
  <div class="card-box">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <h2 style="font-size:1.15rem;font-weight:700;margin:0">
          <?= htmlspecialchars($projeto['titulo']) ?>
        </h2>
        <?php if ($projeto['objetivo']): ?>
          <p style="color:#6b7280;font-size:.82rem;margin:.3rem 0 0">
            <?= htmlspecialchars($projeto['objetivo']) ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($projeto['equipe']): ?>
          <span class="stat-pill"><i class="bi bi-people text-primary"></i><?= htmlspecialchars($projeto['equipe']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['prazo']): ?>
          <span class="stat-pill"><i class="bi bi-calendar-check text-danger"></i><?= htmlspecialchars($projeto['prazo']) ?></span>
        <?php endif; ?>
        <?php if ($projeto['repo']): ?>
          <a href="<?= htmlspecialchars($projeto['repo']) ?>" target="_blank" class="stat-pill text-decoration-none">
            <i class="bi bi-github"></i>GitHub
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Progresso geral -->
    <div class="d-flex align-items-center gap-3 mb-1">
      <div class="prog-bar flex-grow-1">
        <div class="prog-fill" style="width:<?= $projeto['pct'] ?>%;background:<?= corPct($projeto['pct']) ?>"></div>
      </div>
      <span style="font-weight:700;font-size:.9rem;color:<?= corPct($projeto['pct']) ?>;min-width:40px">
        <?= $projeto['pct'] ?>%
      </span>
    </div>
    <div style="font-size:.75rem;color:#9ca3af">
      <?= $projeto['done'] ?> / <?= $projeto['total'] ?> tarefas concluídas
      · <?= count($projeto['modulos']) ?> módulos
    </div>
  </div>

  <?php if ($ganttBars): ?>
  <!-- ── GANTT ─────────────────────────────────────────────────────────── -->
  <div class="card-box">
    <h6 style="font-weight:700;margin-bottom:.75rem">
      <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Cronograma — Linha do Tempo
    </h6>
    <div class="gantt-wrap">
      <div class="gantt">
        <!-- Cabeçalho de semanas -->
        <div class="gantt-header">
          <div class="gantt-col-label">Etapa</div>
          <div class="gantt-timeline">
            <?php foreach ($ganttBars as $bar): ?>
              <div class="gantt-week"><?= htmlspecialchars($bar['semana']) ?><br><?= htmlspecialchars($bar['periodo']) ?></div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Linha de hoje -->
        <?php
        $hojePct = ($dataInicio && $totalDias) ? barPct($hoje, $dataInicio, $totalDias) : -1;
        ?>

        <!-- Barras do Gantt -->
        <?php
        $cores = ['#1a73e8','#1e8e3e','#f57c00','#7b1fa2','#c62828','#0097a7'];
        foreach ($ganttBars as $i => $bar):
            $left  = barPct($bar['ini'], $dataInicio, $totalDias);
            $width = barPct($bar['fim'], $dataInicio, $totalDias) - $left;
            $cor   = $cores[$i % count($cores)];
            $isPast= $bar['fim'] < $hoje;
            $isNow = $bar['ini'] <= $hoje && $bar['fim'] >= $hoje;
        ?>
        <div class="gantt-row">
          <div class="gantt-label" title="<?= htmlspecialchars($bar['descricao']) ?>">
            <?= htmlspecialchars($bar['semana']) ?> — <?= htmlspecialchars(mb_substr($bar['descricao'],0,30)) ?>…
          </div>
          <div class="gantt-track">
            <?php if ($hojePct >= 0 && $hojePct <= 100): ?>
              <div class="gantt-today" style="left:<?= $hojePct ?>%">
                <?php if ($i === 0): ?>
                  <span class="gantt-today-label">Hoje</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="gantt-bar"
                 style="left:<?= $left ?>%;width:<?= max($width,2) ?>%;
                        background:<?= $cor ?>;opacity:<?= $isPast ? '.5' : '1' ?>;
                        outline:<?= $isNow ? '2px solid '.$cor : 'none' ?>;"
                 title="<?= htmlspecialchars($bar['descricao']) ?>">
              <?= htmlspecialchars($bar['periodo']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="font-size:.72rem;color:#9ca3af;margin-top:.5rem">
      <span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:50%;margin-right:4px"></span>Hoje
      &nbsp;·&nbsp; Barras opacas = semanas passadas
    </div>
  </div>
  <?php endif; ?>

  <!-- ── MÓDULOS ──────────────────────────────────────────────────────────── -->
  <div class="row g-2">
  <?php foreach ($projeto['modulos'] as $idx => $mod):
      $tot  = count($mod['tarefas']);
      $done = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
      $pct  = $tot > 0 ? round($done / $tot * 100) : 0;
      if ($tot === 0) continue;
  ?>
    <div class="col-md-6">
      <div class="card-box" style="padding:1rem">
        <!-- Cabeçalho do módulo -->
        <div class="mod-header" onclick="toggleMod(<?= $idx ?>)">
          <i class="bi bi-chevron-right" id="chevron-<?= $idx ?>" style="font-size:.75rem;color:#9ca3af;transition:transform .2s"></i>
          <span style="font-weight:700;font-size:.85rem;flex:1"><?= htmlspecialchars($mod['nome']) ?></span>
          <span style="font-size:.72rem;color:<?= corPct($pct) ?>;font-weight:700"><?= $done ?>/<?= $tot ?></span>
        </div>
        <!-- Barra de progresso do módulo -->
        <div class="prog-bar" style="height:6px;margin:.35rem 0">
          <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= corPct($pct) ?>"></div>
        </div>

        <!-- Lista de tarefas (colapsável) -->
        <div id="mod-body-<?= $idx ?>" style="display:none">
          <?php
          $subAtual = null;
          foreach ($mod['tarefas'] as $t):
              if ($t['sub'] !== $subAtual):
                  $subAtual = $t['sub'];
                  if ($subAtual):
          ?>
            <div class="sub-label"><?= htmlspecialchars($subAtual) ?></div>
          <?php       endif; endif; ?>
            <div class="task-item <?= $t['done'] ? 'done' : '' ?>">
              <span class="task-check">
                <?php if ($t['done']): ?>
                  <i class="bi bi-check-circle-fill text-success"></i>
                <?php else: ?>
                  <i class="bi bi-circle" style="color:#d1d5db"></i>
                <?php endif; ?>
              </span>
              <span><?= htmlspecialchars($t['texto']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Nota Obsidian -->
  <div style="text-align:center;margin-top:1.5rem;font-size:.75rem;color:#9ca3af">
    <i class="bi bi-journal-bookmark me-1"></i>
    Dados lidos de <code>Docs/wiki/projects/<?= htmlspecialchars($projeto['arquivo']) ?></code>
    · Edite no Obsidian e recarregue para atualizar
    · Última leitura: <?= date('d/m/Y H:i') ?>
  </div>

<?php endif; ?>
</div><!-- wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleMod(idx) {
  const body    = document.getElementById('mod-body-' + idx);
  const chevron = document.getElementById('chevron-' + idx);
  const aberto  = body.style.display !== 'none';
  body.style.display    = aberto ? 'none' : '';
  chevron.style.transform = aberto ? '' : 'rotate(90deg)';
}
</script>
</body>
</html>
