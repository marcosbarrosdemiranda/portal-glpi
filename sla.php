<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Buscar tickets abertos via API GLPI ───────────────────────────────────────
$tickets   = [];
$glpi_erro = '';
$ultima_atualizacao = date('H:i:s');

function glpi_api(string $method, string $endpoint, array $headers = []): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    return ['code' => $code, 'data' => $data];
}

try {
    $init = glpi_api('GET', 'initSession', [
        'Content-Type: application/json',
        'App-Token: ' . GLPI_APP_TOKEN,
        'Authorization: Basic ' . base64_encode(GLPI_USER . ':' . GLPI_PASS),
    ]);

    if (!empty($init['data']['session_token'])) {
        $token = $init['data']['session_token'];
        $hdrs  = [
            'Content-Type: application/json',
            'App-Token: ' . GLPI_APP_TOKEN,
            'Session-Token: ' . $token,
        ];

        // Status 1 = Novo, Status 2 = Em atendimento (processando)
        // Buscamos os dois em uma única chamada com range amplo
        $resp = glpi_api('GET',
            'Ticket?range=0-200&expand_dropdowns=true' .
            '&criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=1' .
            '&criteria[1][link]=OR' .
            '&criteria[1][field]=12&criteria[1][searchtype]=equals&criteria[1][value]=2',
            $hdrs
        );

        // Fallback: busca simples sem critérios de status
        if (empty($resp['data']) || !is_array($resp['data'])) {
            $resp = glpi_api('GET', 'Ticket?range=0-200&expand_dropdowns=true&is_deleted=0', $hdrs);
        }

        if (!empty($resp['data']) && is_array($resp['data'])) {
            foreach ($resp['data'] as $t) {
                if (!isset($t['id'])) continue;
                // Filtrar apenas status 1 e 2
                $st = (int)($t['status'] ?? 0);
                if ($st !== 1 && $st !== 2) continue;

                $tickets[] = [
                    'id'          => $t['id'],
                    'titulo'      => $t['name'] ?? 'Ticket #' . $t['id'],
                    'status'      => $st,
                    'status_nome' => $st === 1 ? 'Novo' : 'Em atendimento',
                    'urgencia'    => (int)($t['urgency'] ?? 2),
                    'prioridade'  => (int)($t['priority'] ?? 2),
                    'abertura'    => $t['date'] ?? $t['date_mod'] ?? null,
                    'atribuido'   => $t['_users_id_assign'] ?? '—',
                    'solicitante' => $t['_users_id_requester'] ?? '—',
                    'categoria'   => $t['itilcategories_id'] ?? '—',
                ];
            }
        }

        glpi_api('GET', 'killSession', $hdrs);
    } else {
        $glpi_erro = 'Não foi possível autenticar na API do GLPI. Verifique as credenciais em agenda/config.php.';
    }
} catch (\Throwable $e) {
    $glpi_erro = 'Erro ao conectar ao GLPI: ' . htmlspecialchars($e->getMessage());
}

// ── Calcular SLA por ticket ───────────────────────────────────────────────────
// Regras: < 4h = VERDE, 4-8h = AMARELO, > 8h = VERMELHO
// Ajuste baseado em urgência: alta urgência (4-5) reduz thresholds à metade
function calcularSLA(array $ticket): array {
    if (!$ticket['abertura']) {
        return ['cor' => 'cinza', 'horas' => 0, 'label' => '—'];
    }
    $abertura = strtotime($ticket['abertura']);
    $agora    = time();
    $segundos = $agora - $abertura;
    $horas    = $segundos / 3600;

    $urg = $ticket['urgencia'];
    // Urgência 4 (alto) ou 5 (muito alto): thresholds reduzidos
    $limVerde    = ($urg >= 4) ? 2  : 4;   // horas
    $limAmarelo  = ($urg >= 4) ? 4  : 8;

    if ($horas < $limVerde) {
        $cor = 'verde';
    } elseif ($horas < $limAmarelo) {
        $cor = 'amarelo';
    } else {
        $cor = 'vermelho';
    }

    $h = (int)floor($horas);
    $m = (int)(($horas - $h) * 60);
    $label = $h > 0 ? "{$h}h{$m}m" : "{$m}m";

    return ['cor' => $cor, 'horas' => $horas, 'label' => $label];
}

foreach ($tickets as &$t) {
    $t['sla'] = calcularSLA($t);
}
unset($t);

// Contagem por semáforo
$cnt_verde   = count(array_filter($tickets, fn($t) => $t['sla']['cor'] === 'verde'));
$cnt_amarelo = count(array_filter($tickets, fn($t) => $t['sla']['cor'] === 'amarelo'));
$cnt_vermelho= count(array_filter($tickets, fn($t) => $t['sla']['cor'] === 'vermelho'));

// Ordenar: vermelho → amarelo → verde
usort($tickets, function($a, $b) {
    $ordem = ['vermelho' => 0, 'amarelo' => 1, 'verde' => 2, 'cinza' => 3];
    return ($ordem[$a['sla']['cor']] ?? 3) <=> ($ordem[$b['sla']['cor']] ?? 3);
});

$urgLabels = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Monitor SLA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #e53935; }
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

    /* ── Semáforo Stats ── */
    .sla-semaforo {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem; margin-bottom: 1.25rem;
    }
    .sem-card {
      background: white; border-radius: 14px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.25rem;
      text-align: center; position: relative; overflow: hidden;
    }
    .sem-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
    }
    .sem-verde::before   { background: #43a047; }
    .sem-amarelo::before { background: #fb8c00; }
    .sem-vermelho::before{ background: #e53935; }
    .sem-total::before   { background: var(--mod); }

    .sem-icon {
      width: 52px; height: 52px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto .75rem; font-size: 1.5rem;
    }
    .sem-verde   .sem-icon { background: #e8f5e9; color: #2e7d32; }
    .sem-amarelo .sem-icon { background: #fff8e1; color: #f57f17; }
    .sem-vermelho .sem-icon { background: #ffebee; color: #c62828; }
    .sem-total   .sem-icon { background: #ffebee; color: var(--mod); }

    .sem-val  { font-size: 2rem; font-weight: 800; line-height: 1; }
    .sem-lbl  { font-size: .75rem; color: #9ca3af; text-transform: uppercase; letter-spacing: .05em; margin-top: .25rem; }
    .sem-sub  { font-size: .72rem; color: #6b7280; margin-top: .2rem; }

    /* ── Barra de ação ── */
    .acao-bar {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: .75rem 1.25rem;
      display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem;
    }
    .info-update { font-size: .78rem; color: #9ca3af; display: flex; align-items: center; gap: .4rem; }
    .btn-atualizar {
      background: var(--mod); border: none; color: white; border-radius: 8px;
      padding: .4rem 1.1rem; font-size: .82rem; font-weight: 600; cursor: pointer;
      text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
    }
    .btn-atualizar:hover { background: #c62828; color: white; }
    .auto-refresh {
      font-size: .78rem; color: #6b7280; display: flex; align-items: center; gap: .3rem;
    }

    /* ── Tabela ── */
    .tbl-wrap {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden;
    }
    .tbl-header {
      background: var(--mod); color: white; padding: .75rem 1.25rem;
      display: flex; align-items: center; justify-content: space-between;
    }
    .tbl-header .title { font-weight: 700; font-size: .95rem; display: flex; align-items: center; gap: .5rem; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    thead th {
      background: #f9fafb; padding: .65rem 1rem; text-align: left;
      font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb;
      font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
    }
    tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
    tbody td { padding: .65rem 1rem; vertical-align: middle; }

    /* Linhas coloridas por SLA */
    tr.sla-verde   { border-left: 4px solid #43a047; }
    tr.sla-amarelo { border-left: 4px solid #fb8c00; background: #fffde7; }
    tr.sla-vermelho{ border-left: 4px solid #e53935; background: #fff5f5; }
    tr.sla-cinza   { border-left: 4px solid #9ca3af; }

    /* Semáforo badge */
    .sla-badge {
      display: inline-flex; align-items: center; gap: .3rem;
      font-size: .75rem; font-weight: 700; padding: .25rem .6rem; border-radius: 10px;
    }
    .sla-badge.verde   { background: #e8f5e9; color: #1b5e20; }
    .sla-badge.amarelo { background: #fff8e1; color: #e65100; }
    .sla-badge.vermelho{ background: #ffebee; color: #b71c1c; }
    .sla-badge.cinza   { background: #f3f4f6; color: #6b7280; }

    /* Dot semáforo */
    .sla-dot {
      width: 10px; height: 10px; border-radius: 50%; display: inline-block;
    }
    .dot-verde   { background: #43a047; box-shadow: 0 0 6px #43a047; }
    .dot-amarelo { background: #fb8c00; box-shadow: 0 0 6px #fb8c00; }
    .dot-vermelho{ background: #e53935; box-shadow: 0 0 6px #e53935; animation: piscar 1.2s infinite; }
    .dot-cinza   { background: #9ca3af; }

    @keyframes piscar {
      0%, 100% { opacity: 1; }
      50%       { opacity: .3; }
    }

    /* Status badge */
    .st-novo     { background: #e8f0fe; color: #1a73e8; font-size:.7rem; padding:.18rem .5rem; border-radius:8px; font-weight:600; }
    .st-atend    { background: #fff3e0; color: #e65100; font-size:.7rem; padding:.18rem .5rem; border-radius:8px; font-weight:600; }

    /* Urgência */
    .urg-badge { font-size: .68rem; padding: .18rem .45rem; border-radius: 8px; font-weight: 700; }
    .urg-5 { background: #ffebee; color: #b71c1c; }
    .urg-4 { background: #fff3e0; color: #e65100; }
    .urg-3 { background: #fff9c4; color: #f57f17; }
    .urg-2 { background: #f3f4f6; color: #4b5563; }
    .urg-1 { background: #e8f5e9; color: #2e7d32; }

    /* Alerta erro */
    .alerta-erro {
      background: #ffebee; border: 1px solid #ef9a9a; border-radius: 10px;
      padding: 1.5rem; text-align: center; color: #b71c1c;
    }
    .alerta-erro .icon { font-size: 2.5rem; display: block; margin-bottom: .75rem; }

    .empty-row td { text-align: center; color: #9ca3af; padding: 2.5rem; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-stopwatch-fill"></i> Monitor SLA</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-stopwatch-fill me-2"></i>Monitor SLA em Tempo Real</h1>
  <p>Chamados abertos — semáforo verde / amarelo / vermelho por tempo de resposta</p>
</div>

<div class="wrap">

  <?php if ($glpi_erro): ?>
  <div class="alerta-erro">
    <span class="icon"><i class="bi bi-wifi-off"></i></span>
    <strong>Não foi possível buscar dados do GLPI</strong><br>
    <span style="font-size:.88rem"><?= htmlspecialchars($glpi_erro) ?></span><br>
    <a href="sla.php" class="btn-atualizar d-inline-flex mt-3" style="text-decoration:none">
      <i class="bi bi-arrow-clockwise"></i>Tentar novamente
    </a>
  </div>

  <?php else: ?>

  <!-- Semáforo Stats -->
  <div class="sla-semaforo">
    <div class="sem-card sem-total">
      <div class="sem-icon"><i class="bi bi-ticket-detailed-fill"></i></div>
      <div class="sem-val" style="color:var(--mod)"><?= count($tickets) ?></div>
      <div class="sem-lbl">Total de Chamados</div>
      <div class="sem-sub">abertos (novos + em atend.)</div>
    </div>
    <div class="sem-card sem-verde">
      <div class="sem-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="sem-val" style="color:#2e7d32"><?= $cnt_verde ?></div>
      <div class="sem-lbl">Dentro do SLA</div>
      <div class="sem-sub">menos de 4 horas</div>
    </div>
    <div class="sem-card sem-amarelo">
      <div class="sem-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
      <div class="sem-val" style="color:#e65100"><?= $cnt_amarelo ?></div>
      <div class="sem-lbl">Atenção</div>
      <div class="sem-sub">entre 4 e 8 horas</div>
    </div>
    <div class="sem-card sem-vermelho">
      <div class="sem-icon"><i class="bi bi-x-circle-fill"></i></div>
      <div class="sem-val" style="color:#c62828"><?= $cnt_vermelho ?></div>
      <div class="sem-lbl">SLA Violado</div>
      <div class="sem-sub">mais de 8 horas</div>
    </div>
  </div>

  <!-- Barra ação -->
  <div class="acao-bar">
    <div class="info-update">
      <i class="bi bi-clock"></i>
      Última atualização: <strong><?= $ultima_atualizacao ?></strong>
    </div>
    <div class="auto-refresh">
      <i class="bi bi-arrow-repeat"></i>
      Auto-refresh em <strong id="countdown">5:00</strong>
    </div>
    <div style="flex:1"></div>
    <a href="sla.php" class="btn-atualizar">
      <i class="bi bi-arrow-clockwise"></i>Atualizar agora
    </a>
  </div>

  <!-- Tabela de chamados -->
  <div class="tbl-wrap">
    <div class="tbl-header">
      <div class="title"><i class="bi bi-table"></i> Chamados Abertos</div>
      <span style="font-size:.78rem;opacity:.85"><?= count($tickets) ?> chamado<?= count($tickets) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th style="width:36px">&nbsp;</th>
            <th>#</th>
            <th>Título</th>
            <th>Status</th>
            <th>Urgência</th>
            <th>Abertura</th>
            <th>Tempo Decorrido</th>
            <th>SLA</th>
            <th>Atribuído a</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tickets)): ?>
          <tr class="empty-row"><td colspan="9"><i class="bi bi-check2-all fs-4 d-block mb-2 text-success"></i>Nenhum chamado aberto ou em atendimento no momento.</td></tr>
          <?php else: ?>
          <?php foreach ($tickets as $t):
            $sla    = $t['sla'];
            $cor    = $sla['cor'];
            $urgCls = 'urg-' . $t['urgencia'];
            $urgLbl = $urgLabels[$t['urgencia']] ?? 'N/D';
            $stCls  = $t['status'] === 1 ? 'st-novo' : 'st-atend';
            $abertura_fmt = $t['abertura'] ? date('d/m H:i', strtotime($t['abertura'])) : '—';
            $atrib  = is_array($t['atribuido']) ? ($t['atribuido']['name'] ?? '—') : ($t['atribuido'] ?: '—');
          ?>
          <tr class="sla-<?= $cor ?>">
            <td style="text-align:center"><span class="sla-dot dot-<?= $cor ?>"></span></td>
            <td style="font-weight:700;color:#1a73e8"><?= (int)$t['id'] ?></td>
            <td style="max-width:280px">
              <span style="font-weight:600;font-size:.85rem"><?= htmlspecialchars(mb_strimwidth($t['titulo'], 0, 70, '…')) ?></span>
              <?php if ($t['categoria'] && $t['categoria'] !== '—'): ?>
              <div style="font-size:.7rem;color:#9ca3af"><?= htmlspecialchars(is_array($t['categoria']) ? ($t['categoria']['completename'] ?? '') : $t['categoria']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="<?= $stCls ?>"><?= htmlspecialchars($t['status_nome']) ?></span></td>
            <td><span class="urg-badge <?= $urgCls ?>"><?= $urgLbl ?></span></td>
            <td style="font-size:.8rem;white-space:nowrap"><?= $abertura_fmt ?></td>
            <td style="font-weight:700;font-size:.88rem;color:<?= $cor === 'verde' ? '#2e7d32' : ($cor === 'amarelo' ? '#e65100' : '#b71c1c') ?>">
              <?= htmlspecialchars($sla['label']) ?>
            </td>
            <td>
              <span class="sla-badge <?= $cor ?>">
                <span class="sla-dot dot-<?= $cor ?>" style="width:8px;height:8px"></span>
                <?= $cor === 'verde' ? 'OK' : ($cor === 'amarelo' ? 'Atenção' : 'Violado') ?>
              </span>
            </td>
            <td style="font-size:.8rem;max-width:120px">
              <?= htmlspecialchars(is_array($atrib) ? ($atrib['name'] ?? '—') : ($atrib ?: '—')) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Auto-refresh a cada 5 minutos com contador regressivo ──────────────────
(function() {
  const TOTAL = 5 * 60; // segundos
  let restante = TOTAL;
  const el = document.getElementById('countdown');
  if (!el) return;

  function atualizar() {
    const m = Math.floor(restante / 60);
    const s = restante % 60;
    el.textContent = m + ':' + String(s).padStart(2, '0');
    if (restante <= 30) el.style.color = '#e53935';
    else if (restante <= 60) el.style.color = '#fb8c00';
    else el.style.color = '';
    if (restante <= 0) {
      window.location.reload();
    } else {
      restante--;
      setTimeout(atualizar, 1000);
    }
  }
  atualizar();
})();
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Monitor SLA — Integrado com GLPI</footer>
</body>
</html>
