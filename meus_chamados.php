<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
$nome    = $_SESSION['nome']    ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

define('POR_PAGINA', 200);

function get_meus_chamados(array $filtros = [], int $pagina = 1, int $user_id = 0, int $por_pagina = 0): array {
    if (!$user_id) return ['tickets'=>[], 'total'=>0];
    $limit = $por_pagina > 0 ? $por_pagina : POR_PAGINA;

    $auth  = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch    = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r     = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return ['tickets'=>[], 'total'=>0];

    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];
    $offset = ($pagina - 1) * $limit;
    $range  = $offset . '-' . ($offset + $limit - 1);

    // Filtro fixo: requerente = usuário logado (field=4 = users_id_requester)
    $params = 'range='.$range.'&order=DESC&expand_dropdowns=true';
    $params .= '&criteria[0][field]=4&criteria[0][searchtype]=equals&criteria[0][value]='.$user_id;

    $criteria_idx = 1;
    if (!empty($filtros['status'])) {
        $params .= '&criteria['.$criteria_idx.'][link]=AND&criteria['.$criteria_idx.'][field]=12&criteria['.$criteria_idx.'][searchtype]=equals&criteria['.$criteria_idx.'][value]='.$filtros['status'];
        $criteria_idx++;
    }
    if (!empty($filtros['tipo'])) {
        $params .= '&criteria['.$criteria_idx.'][link]=AND&criteria['.$criteria_idx.'][field]=14&criteria['.$criteria_idx.'][searchtype]=equals&criteria['.$criteria_idx.'][value]='.$filtros['tipo'];
        $criteria_idx++;
    }
    if (!empty($filtros['dt_ini'])) {
        $params .= '&criteria['.$criteria_idx.'][link]=AND&criteria['.$criteria_idx.'][field]=15&criteria['.$criteria_idx.'][searchtype]=morethan&criteria['.$criteria_idx.'][value]='.urlencode($filtros['dt_ini'].' 00:00:00');
        $criteria_idx++;
    }
    if (!empty($filtros['dt_fim'])) {
        $params .= '&criteria['.$criteria_idx.'][link]=AND&criteria['.$criteria_idx.'][field]=15&criteria['.$criteria_idx.'][searchtype]=lessthan&criteria['.$criteria_idx.'][value]='.urlencode($filtros['dt_fim'].' 23:59:59');
        $criteria_idx++;
    }

    $ch2 = curl_init(GLPI_URL . '/apirest.php/Ticket?'.$params);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>true,
        CURLOPT_HTTPHEADER=>$h
    ]);
    $resp = curl_exec($ch2);
    $header_size = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
    $header = substr($resp, 0, $header_size);
    $body   = substr($resp, $header_size);
    curl_close($ch2);

    $total = 0;
    if (preg_match('/Content-Range:\s*\d+-\d+\/(\d+)/i', $header, $m)) {
        $total = (int)$m[1];
    }

    $tickets = json_decode($body, true) ?? [];

    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch3); curl_close($ch3);

    if (!is_array($tickets) || isset($tickets['ERROR'])) return ['tickets'=>[], 'total'=>0];

    $status_map = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
    $status_cor = [1=>'primary',2=>'info',3=>'warning',4=>'secondary',5=>'success',6=>'dark'];
    $urg_map    = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta'];
    $urg_cor    = [1=>'success',2=>'info',3=>'warning',4=>'danger',5=>'purple'];

    $result = array_values(array_filter(array_map(fn($t) => !isset($t['id']) ? null : [
        'id'        => $t['id'],
        'titulo'    => $t['name'] ?? 'Sem título',
        'status'    => $status_map[$t['status'] ?? 1] ?? '?',
        'status_n'  => (int)($t['status'] ?? 1),
        'status_cor'=> $status_cor[$t['status'] ?? 1] ?? 'secondary',
        'tipo'      => ($t['type'] ?? 1) == 1 ? 'Incidente' : 'Requisição',
        'tipo_n'    => (int)($t['type'] ?? 1),
        'urgencia'  => $urg_map[$t['urgency'] ?? 3] ?? 'Média',
        'urg_cor'   => $urg_cor[$t['urgency'] ?? 3] ?? 'warning',
        'entidade'  => apelido_entidade($t['entities_id'] ?? ''),
        'entidade_id'=> (int)($t['entities_id'] ?? 0),
        'data'      => substr($t['date'] ?? '', 0, 16),
        'atualizado'=> substr($t['date_mod'] ?? '', 0, 16),
        'requerente'=> $t['_users_id_requester']['name'] ?? $t['users_id'] ?? '',
    ], $tickets)));

    return ['tickets' => $result, 'total' => $total ?: count($result)];
}

// ── Parâmetros ──
$f_status = $_GET['status'] ?? '';
$f_tipo   = $_GET['tipo']   ?? '';
$f_dt_ini = $_GET['dt_ini'] ?? '';
$f_dt_fim = $_GET['dt_fim'] ?? '';
$pagina   = max(1, (int)($_GET['pagina'] ?? 1));
$export   = $_GET['export'] ?? '';

$resultado = get_meus_chamados([
    'status'=>$f_status,
    'tipo'=>$f_tipo,
    'dt_ini'=>$f_dt_ini,
    'dt_fim'=>$f_dt_fim,
], $pagina, $user_id);
$chamados  = $resultado['tickets'];
$total_api = $resultado['total'];
$total_pags = max(1, (int)ceil($total_api / POR_PAGINA));

// ── Exportação CSV ──
if ($export === 'csv') {
    $todos = get_meus_chamados([
        'status'=>$f_status,
        'tipo'=>$f_tipo,
        'dt_ini'=>$f_dt_ini,
        'dt_fim'=>$f_dt_fim,
    ], 1, $user_id, 100000);
    $export_dados = $todos['tickets'] ?? [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meus_chamados_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['#','Título','Tipo','Status','Urgência','Entidade','Abertura','Atualização'], ';');
    foreach ($export_dados as $c) {
        fputcsv($out, [$c['id'],$c['titulo'],$c['tipo'],$c['status'],$c['urgencia'],$c['entidade'],$c['data'],$c['atualizado']], ';');
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Meus Chamados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; transition:.2s; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
            padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p  { opacity:.8; margin:.3rem 0 0; }

    .wrap { max-width:1100px; margin:-3rem auto 3rem; padding:0 1rem; }

    .filtros-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
      margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end;
    }
    .filtros-card .form-label { font-size:.78rem; font-weight:600; color:#6b7280; margin-bottom:.2rem; }
    .filtros-card select, .filtros-card input { font-size:.83rem; }
    .btn-filtrar { background:var(--accent); border:none; color:white; border-radius:8px;
                   padding:.45rem 1.25rem; font-size:.85rem; font-weight:600; }

    .stats { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
    .stat-chip {
      background:white; border-radius:10px; border:1px solid #e5e7eb;
      padding:.5rem 1rem; font-size:.82rem; font-weight:600;
      display:flex; align-items:center; gap:.4rem;
      box-shadow:0 1px 4px rgba(0,0,0,.05);
    }

    .tabela-card {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
    }
    .tabela-card table { margin:0; font-size:.84rem; }
    .tabela-card thead th {
      background:#f9fafb; font-size:.75rem; font-weight:700;
      color:#6b7280; text-transform:uppercase; letter-spacing:.04em;
      border-bottom:2px solid #e5e7eb; padding:.65rem 1rem;
    }
    .tabela-card tbody td { padding:.7rem 1rem; vertical-align:middle; border-color:#f3f4f6; }
    .tabela-card tbody tr { cursor:pointer; transition:background .15s; }
    .tabela-card tbody tr:hover { background:#e8f0fe; }

    .ticket-id { font-weight:700; color:var(--accent); font-size:.82rem; }
    .ticket-titulo { font-weight:600; color:#111; }
    .ticket-titulo small { display:block; font-weight:400; color:#9ca3af; font-size:.75rem; }

    .badge { font-size:.72rem; padding:.3rem .6rem; border-radius:20px; }
    .bg-purple { background:#7c3aed!important; }

    .urg-dot { width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:4px; }
    .urg-success { background:#16a34a; }
    .urg-info    { background:#0ea5e9; }
    .urg-warning { background:#d97706; }
    .urg-danger  { background:#dc2626; }
    .urg-purple  { background:#7c3aed; }

    .empty { text-align:center; color:#9ca3af; padding:4rem 1rem; }

    @media(max-width:768px) {
      .tabela-card { overflow-x:auto; }
      .filtros-card { flex-direction:column; }
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-clock-history"></i> Meus Chamados</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-ticket-perforated me-2"></i>Meus Chamados</h1>
  <p>Acompanhe o status das suas solicitações</p>
</div>

<div class="wrap">

  <!-- Filtros -->
  <form method="GET" action="">
    <div class="filtros-card">
      <div>
        <div class="form-label">Status</div>
        <select name="status" class="form-select form-select-sm" style="width:140px">
          <option value="">Todos</option>
          <option value="1" <?= $f_status=='1'?'selected':'' ?>>Novo</option>
          <option value="2" <?= $f_status=='2'?'selected':'' ?>>Atribuído</option>
          <option value="3" <?= $f_status=='3'?'selected':'' ?>>Planejado</option>
          <option value="4" <?= $f_status=='4'?'selected':'' ?>>Pendente</option>
          <option value="5" <?= $f_status=='5'?'selected':'' ?>>Solucionado</option>
          <option value="6" <?= $f_status=='6'?'selected':'' ?>>Fechado</option>
        </select>
      </div>
      <div>
        <div class="form-label">Tipo</div>
        <select name="tipo" class="form-select form-select-sm" style="width:125px">
          <option value="">Todos</option>
          <option value="1" <?= $f_tipo=='1'?'selected':'' ?>>Incidente</option>
          <option value="2" <?= $f_tipo=='2'?'selected':'' ?>>Requisição</option>
        </select>
      </div>
      <div>
        <div class="form-label">De</div>
        <input type="date" name="dt_ini" class="form-control form-control-sm" style="width:145px"
               value="<?= htmlspecialchars($f_dt_ini) ?>"/>
      </div>
      <div>
        <div class="form-label">Até</div>
        <input type="date" name="dt_fim" class="form-control form-control-sm" style="width:145px"
               value="<?= htmlspecialchars($f_dt_fim) ?>"/>
      </div>
      <div>
        <button type="submit" class="btn-filtrar"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="meus_chamados.php" class="btn btn-sm btn-outline-secondary ms-1">Limpar</a>
        <?php if (!empty($chamados)): ?>
        <a href="meus_chamados.php?export=csv&<?= http_build_query(array_filter(['status'=>$f_status,'tipo'=>$f_tipo,'dt_ini'=>$f_dt_ini,'dt_fim'=>$f_dt_fim])) ?>" class="btn btn-sm btn-success ms-2">
          <i class="bi bi-download me-1"></i>Exportar CSV
        </a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Estatísticas -->
  <?php
  $novos      = count(array_filter($chamados, fn($c) => $c['status_n'] == 1));
  $abertos    = count(array_filter($chamados, fn($c) => in_array($c['status_n'], [2,3,4])));
  $resolvidos = count(array_filter($chamados, fn($c) => in_array($c['status_n'], [5,6])));
  $inicio_reg = ($pagina - 1) * POR_PAGINA + 1;
  $fim_reg    = min($pagina * POR_PAGINA, $total_api);
  ?>
  <div class="stats">
    <div class="stat-chip"><i class="bi bi-ticket text-secondary"></i> Total: <strong><?= $total_api ?></strong></div>
    <div class="stat-chip"><i class="bi bi-layers text-secondary"></i> Exibindo: <strong><?= $inicio_reg ?>–<?= $fim_reg ?></strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-primary" style="font-size:.6rem"></i> Novos: <strong><?= $novos ?></strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-warning" style="font-size:.6rem"></i> Em andamento: <strong><?= $abertos ?></strong></div>
    <div class="stat-chip"><i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i> Resolvidos/Fechados: <strong><?= $resolvidos ?></strong></div>
  </div>

  <!-- Tabela -->
  <div class="tabela-card">
    <?php if (empty($chamados)): ?>
      <div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum chamado encontrado.</div>
    <?php else: ?>
    <table class="table table-hover">
      <thead>
        <tr>
          <th>#</th>
          <th>Título</th>
          <th>Tipo</th>
          <th>Status</th>
          <th>Urgência</th>
          <th>Entidade</th>
          <th>Abertura</th>
          <th>Atualização</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($chamados as $c): ?>
        <tr style="cursor:pointer" onclick="window.location.href='chamado.php?id=<?= $c['id'] ?>'">
          <td class="ticket-id"><?= $c['id'] ?></td>
          <td class="ticket-titulo"><?= htmlspecialchars($c['titulo']) ?></td>
          <td><span class="badge <?= $c['tipo_n']==1 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $c['tipo'] ?></span></td>
          <td><span class="badge bg-<?= $c['status_cor'] ?>"><?= $c['status'] ?></span></td>
          <td>
            <span class="urg-dot urg-<?= $c['urg_cor'] ?>"></span>
            <?= $c['urgencia'] ?>
          </td>
          <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($c['entidade']) ?></td>
          <td class="text-muted" style="font-size:.78rem"><?= $c['data'] ?></td>
          <td class="text-muted" style="font-size:.78rem"><?= $c['atualizado'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Paginação -->
  <?php if ($total_pags > 1): ?>
  <?php
  $qs = array_filter(['status'=>$f_status,'tipo'=>$f_tipo,'dt_ini'=>$f_dt_ini,'dt_fim'=>$f_dt_fim]);
  $url_base = 'meus_chamados.php?' . http_build_query($qs) . '&pagina=';
  ?>
  <div class="d-flex justify-content-center align-items-center gap-2 mt-3 flex-wrap">
    <?php if ($pagina > 1): ?>
      <a href="<?= $url_base.($pagina-1) ?>" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-chevron-left"></i> Anterior
      </a>
    <?php endif; ?>

    <?php
    $inicio_pag = max(1, $pagina - 3);
    $fim_pag    = min($total_pags, $pagina + 3);
    if ($inicio_pag > 1): ?>
      <a href="<?= $url_base.'1' ?>" class="btn btn-sm btn-outline-secondary">1</a>
      <?php if ($inicio_pag > 2): ?><span class="text-muted">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $inicio_pag; $p <= $fim_pag; $p++): ?>
      <a href="<?= $url_base.$p ?>" class="btn btn-sm <?= $p==$pagina ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $p ?>
      </a>
    <?php endfor; ?>

    <?php if ($fim_pag < $total_pags): ?>
      <?php if ($fim_pag < $total_pags - 1): ?><span class="text-muted">...</span><?php endif; ?>
      <a href="<?= $url_base.$total_pags ?>" class="btn btn-sm btn-outline-secondary"><?= $total_pags ?></a>
    <?php endif; ?>

    <?php if ($pagina < $total_pags): ?>
      <a href="<?= $url_base.($pagina+1) ?>" class="btn btn-sm btn-outline-primary">
        Próximo <i class="bi bi-chevron-right"></i>
      </a>
    <?php endif; ?>

    <span class="text-muted small">Página <?= $pagina ?> de <?= $total_pags ?></span>
  </div>
  <?php endif; ?>

</div>
<script src="assets/notificacoes.js"></script>
</body>
</html>
