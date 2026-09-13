<?php
/**
 * alertas_historico.php — histórico permanente das transições da Central de
 * Alertas (🔔 nova / ✅ resolvida), com filtro por tipo/evento/período.
 *
 * Lê de portal_alertas_historico (alertas_tipos.php), alimentada por
 * gat_alertas_tipo() a cada passada do worker de WhatsApp.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/alertas_tipos.php';

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$tipo   = (string) ($_GET['tipo'] ?? '');
$evento = (string) ($_GET['evento'] ?? '');
$dias   = (int) ($_GET['dias'] ?? 30);
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$LIMITE = 50;

$catalogo = alertas_catalogo();
if ($tipo !== '' && !isset($catalogo[$tipo])) { $tipo = ''; }
if (!in_array($evento, ['nova', 'resolvida'], true)) { $evento = ''; }

$res = alertas_historico_listar($pdo, $tipo, $evento, $dias, $LIMITE, $pagina);
$totalPaginas = max(1, (int) ceil($res['total'] / $LIMITE));

function qs(array $over = []): string {
    $base = ['tipo' => $_GET['tipo'] ?? '', 'evento' => $_GET['evento'] ?? '', 'dias' => $_GET['dias'] ?? 30, 'pagina' => $_GET['pagina'] ?? 1];
    return http_build_query(array_merge($base, $over));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Histórico de Alertas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#0097a7; }
    * { box-sizing:border-box; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; margin:0; }

    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
      display:flex; align-items:center; gap:1rem; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.28); }

    .hero { text-align:center; padding:1.75rem 1rem 1rem; }
    .hero h1 { font-size:1.5rem; font-weight:800; color:#1a237e; margin:0; }
    .hero p  { color:#5f6368; margin:.25rem 0 0; font-size:.85rem; }

    .wrap { max-width:1100px; margin:0 auto 3rem; padding:0 1.5rem; }

    .filtros { background:#fff; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:1.25rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:end; }
    .filtros .campo { display:flex; flex-direction:column; gap:.3rem; }
    .filtros label { font-size:.75rem; color:#5f6368; font-weight:600; }
    .filtros select { font-size:.85rem; padding:.4rem .6rem; border-radius:8px; border:1px solid #dadce0; min-width:180px; }

    .lista { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.08); overflow:hidden; }
    table { width:100%; border-collapse:collapse; font-size:.85rem; }
    th { text-align:left; padding:.7rem 1rem; background:#f5f7fa; color:#5f6368; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; }
    td { padding:.65rem 1rem; border-top:1px solid #f0f0f0; vertical-align:top; }
    tr:hover td { background:#fafbfe; }

    .badge-evento { font-size:.75rem; font-weight:700; padding:.2rem .55rem; border-radius:20px; white-space:nowrap; }
    .badge-nova { background:#fff3e0; color:#e65100; }
    .badge-resolvida { background:#e8f5e9; color:#2e7d32; }

    .vazio { padding:2.5rem; text-align:center; color:#9aa0a6; font-size:.9rem; }

    .paginacao { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; font-size:.82rem; color:#5f6368; }
    .paginacao a { text-decoration:none; color:#1a237e; font-weight:600; }
    .paginacao a.disabled { color:#c0c0c0; pointer-events:none; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-clock-history"></i> Histórico de Alertas</div>
  <span class="spacer"></span>
  <a href="alertas.php"><i class="bi bi-bell-fill me-1"></i>Central de Alertas</a>
  <a href="inventario.php"><i class="bi bi-box-seam me-1"></i>Inventário</a>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-clock-history me-2"></i>Histórico de Alertas</h1>
  <p>Todas as transições 🔔 nova / ✅ resolvida registradas pelo motor de alertas</p>
</div>

<div class="wrap">
  <form class="filtros" method="get">
    <div class="campo">
      <label for="f-tipo">Tipo</label>
      <select id="f-tipo" name="tipo" onchange="this.form.submit()">
        <option value="">Todos os tipos</option>
        <?php foreach ($catalogo as $slug => $def): ?>
          <option value="<?= $H($slug) ?>" <?= $slug === $tipo ? 'selected' : '' ?>><?= $H($def['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="f-evento">Evento</label>
      <select id="f-evento" name="evento" onchange="this.form.submit()">
        <option value="">Nova + Resolvida</option>
        <option value="nova" <?= $evento === 'nova' ? 'selected' : '' ?>>🔔 Só novas</option>
        <option value="resolvida" <?= $evento === 'resolvida' ? 'selected' : '' ?>>✅ Só resolvidas</option>
      </select>
    </div>
    <div class="campo">
      <label for="f-dias">Período</label>
      <select id="f-dias" name="dias" onchange="this.form.submit()">
        <option value="7" <?= $dias === 7 ? 'selected' : '' ?>>Últimos 7 dias</option>
        <option value="30" <?= $dias === 30 ? 'selected' : '' ?>>Últimos 30 dias</option>
        <option value="90" <?= $dias === 90 ? 'selected' : '' ?>>Últimos 90 dias</option>
        <option value="0" <?= $dias === 0 ? 'selected' : '' ?>>Tudo</option>
      </select>
    </div>
    <div class="campo" style="font-size:.8rem;color:#9aa0a6;padding-bottom:.4rem"><?= (int) $res['total'] ?> registro(s)</div>
  </form>

  <div class="lista">
    <?php if (!$res['linhas']): ?>
      <div class="vazio"><i class="bi bi-inbox" style="font-size:1.8rem;display:block;margin-bottom:.5rem"></i>Nenhum registro no período/filtro selecionado.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Quando</th><th>Evento</th><th>Tipo</th><th>Título</th><th>Loja</th><th>Detalhe</th></tr>
        </thead>
        <tbody>
          <?php foreach ($res['linhas'] as $l): ?>
          <tr>
            <td style="white-space:nowrap"><?= $H(date('d/m/Y H:i', strtotime($l['criado_em']))) ?></td>
            <td>
              <?php if ($l['evento'] === 'nova'): ?>
                <span class="badge-evento badge-nova">🔔 Nova</span>
              <?php else: ?>
                <span class="badge-evento badge-resolvida">✅ Resolvida</span>
              <?php endif; ?>
            </td>
            <td><?= $H($catalogo[$l['tipo']]['nome'] ?? $l['tipo']) ?></td>
            <td><?= $H($l['titulo'] ?: '—') ?></td>
            <td><?= $H($l['loja'] ?: '—') ?></td>
            <td style="color:#5f6368"><?= $H($l['detalhe'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="paginacao">
        <span>Página <?= (int) $pagina ?> de <?= (int) $totalPaginas ?></span>
        <span>
          <a class="<?= $pagina <= 1 ? 'disabled' : '' ?>" href="?<?= qs(['pagina' => max(1, $pagina - 1)]) ?>">&larr; Anterior</a>
          &nbsp;&nbsp;
          <a class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>" href="?<?= qs(['pagina' => min($totalPaginas, $pagina + 1)]) ?>">Próxima &rarr;</a>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
