<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Buscar licenças via API GLPI ──────────────────────────────────────────────
$licencas   = [];
$glpi_erro  = '';
$glpi_ok    = false;

function glpi_req(string $method, string $endpoint, array $headers = [], $body = null): array {
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    return ['code' => $code, 'data' => $data];
}

try {
    $init = glpi_req('GET', 'initSession', [
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

        $resp = glpi_req('GET', 'SoftwareLicense?range=0-200&expand_dropdowns=true', $hdrs);

        if (isset($resp['data']) && is_array($resp['data']) && !empty($resp['data'])) {
            $glpi_ok = true;
            foreach ($resp['data'] as $lic) {
                if (!isset($lic['id'])) continue;
                $licencas[] = [
                    'id'            => $lic['id'],
                    'nome'          => $lic['softwares_id'] ?? ($lic['name'] ?? 'Licença #' . $lic['id']),
                    'numero_serie'  => $lic['serial'] ?? '—',
                    'tipo'          => $lic['softwarelicensetypes_id'] ?? 'N/D',
                    'expiracao'     => $lic['expire'] ?? null,
                    'qtd_total'     => (int)($lic['number'] ?? 0),
                    'qtd_usada'     => (int)($lic['used'] ?? 0),
                    'fabricante'    => $lic['manufacturers_id'] ?? '',
                    'comentario'    => $lic['comment'] ?? '',
                ];
            }
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            // Array vazio — retornou mas sem dados
            $glpi_ok = true;
        } else {
            $glpi_erro = 'API GLPI retornou resposta inesperada ao buscar licenças.';
        }

        glpi_req('GET', 'killSession', $hdrs);
    } else {
        $glpi_erro = 'Não foi possível autenticar na API do GLPI.';
    }
} catch (\Throwable $e) {
    $glpi_erro = 'Erro ao conectar ao GLPI: ' . htmlspecialchars($e->getMessage());
}

// Calcular status de expiração
function statusExpiracao(?string $data): string {
    if (!$data || $data === '0000-00-00') return 'sem_expiracao';
    $dias = (int)floor((strtotime($data) - time()) / 86400);
    if ($dias < 0) return 'expirada';
    if ($dias <= 30) return 'expira_breve';
    return 'ok';
}

$hoje = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Licenças de Software</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #0288d1; }
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

    /* ── Stats ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 1rem; margin-bottom: 1.25rem;
    }
    .stat-card {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1rem 1.25rem;
    }
    .stat-card .s-label { font-size: .72rem; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .stat-card .s-value { font-size: 1.55rem; font-weight: 700; margin-top: .2rem; }
    .stat-card .s-sub   { font-size: .78rem; color: #6b7280; margin-top: .15rem; }

    /* ── Filtros ── */
    .filtros-bar {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1rem 1.25rem;
      display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem;
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
    tbody tr:hover { background: #e1f5fe; }
    tbody td { padding: .65rem 1rem; vertical-align: middle; }

    /* Badges */
    .badge-lic {
      font-size: .68rem; padding: .2rem .55rem; border-radius: 8px; font-weight: 700;
    }
    .st-expirada      { background: #ffebee; color: #b71c1c; }
    .st-expira-breve  { background: #fff9c4; color: #f57f17; }
    .st-ok            { background: #e8f5e9; color: #1b5e20; }
    .st-sem-exp       { background: #f3f4f6; color: #6b7280; }

    /* Barra uso */
    .uso-bar { height: 6px; background: #e5e7eb; border-radius: 3px; min-width: 70px; overflow: hidden; margin-top: 3px; }
    .uso-fill { height: 100%; border-radius: 3px; }

    /* Alerta erro */
    .alerta-glpi {
      background: #ffebee; border: 1px solid #ef9a9a; border-radius: 10px;
      padding: .75rem 1rem; margin-bottom: 1rem;
      font-size: .82rem; color: #b71c1c; display: flex; align-items: center; gap: .5rem;
    }

    /* Mensagem sem dados */
    .sem-dados {
      background: white; border-radius: 12px; border: 1px solid #e5e7eb;
      text-align: center; padding: 3rem 2rem;
    }
    .sem-dados .icon-wrap {
      width: 80px; height: 80px; background: #e1f5fe; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.25rem; font-size: 2rem; color: var(--mod);
    }

    .btn-glpi {
      background: var(--mod); border: none; color: white; border-radius: 8px;
      padding: .5rem 1.5rem; font-size: .9rem; font-weight: 600; cursor: pointer;
      text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
      margin-top: .5rem;
    }
    .btn-glpi:hover { background: #0277bd; color: white; }

    .btn-atualizar {
      background: var(--mod); border: none; color: white; border-radius: 8px;
      padding: .4rem 1.1rem; font-size: .82rem; font-weight: 600; cursor: pointer;
      text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
    }
    .btn-atualizar:hover { background: #0277bd; color: white; }

    .empty-row td { text-align: center; color: #9ca3af; padding: 2.5rem; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-key-fill"></i> Licenças de Software</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-key-fill me-2"></i>Licenças de Software</h1>
  <p>Controle de licenças — quantidade usada vs disponível, vencimentos e compliance</p>
</div>

<div class="wrap">

  <?php if ($glpi_erro): ?>
  <div class="alerta-glpi">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($glpi_erro) ?>
  </div>
  <?php endif; ?>

  <?php if ($glpi_ok && empty($licencas)): ?>
  <!-- GLPI conectado mas sem licenças cadastradas -->
  <div class="sem-dados">
    <div class="icon-wrap"><i class="bi bi-key"></i></div>
    <h5 class="fw-bold" style="color:#1f2937">Nenhuma licença cadastrada no GLPI</h5>
    <p class="text-muted" style="max-width:480px;margin:.5rem auto">
      Não foram encontradas licenças de software no GLPI. Acesse o GLPI para cadastrar e gerenciar licenças de software da sua organização.
    </p>
    <a href="<?= htmlspecialchars(GLPI_URL) ?>" target="_blank" class="btn-glpi">
      <i class="bi bi-box-arrow-up-right"></i>Acessar o GLPI
    </a>
  </div>

  <?php elseif (!$glpi_ok && !$glpi_erro): ?>
  <!-- Não conectou e sem erro específico -->
  <div class="sem-dados">
    <div class="icon-wrap" style="background:#ffebee;color:#e53935"><i class="bi bi-wifi-off"></i></div>
    <h5 class="fw-bold" style="color:#1f2937">Não foi possível conectar ao GLPI</h5>
    <p class="text-muted">Verifique as configurações de acesso à API do GLPI.</p>
    <a href="licencas.php" class="btn-glpi"><i class="bi bi-arrow-clockwise"></i>Tentar novamente</a>
  </div>

  <?php elseif (!empty($licencas)): ?>
  <?php
    $total     = count($licencas);
    $expiradas = count(array_filter($licencas, fn($l) => statusExpiracao($l['expiracao']) === 'expirada'));
    $alertas   = count(array_filter($licencas, fn($l) => statusExpiracao($l['expiracao']) === 'expira_breve'));
    $qtd_total = array_sum(array_column($licencas, 'qtd_total'));
  ?>
  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card" style="border-left:4px solid var(--mod)">
      <div class="s-label"><i class="bi bi-key me-1"></i>Total Licenças</div>
      <div class="s-value" style="color:var(--mod)"><?= $total ?></div>
      <div class="s-sub"><?= $qtd_total ?> assentos</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #2e7d32">
      <div class="s-label"><i class="bi bi-check-circle me-1"></i>Ativas (OK)</div>
      <div class="s-value" style="color:#2e7d32"><?= $total - $expiradas - $alertas ?></div>
      <div class="s-sub">dentro do prazo</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #fb8c00">
      <div class="s-label"><i class="bi bi-clock-history me-1"></i>Expira em breve</div>
      <div class="s-value" style="color:#fb8c00"><?= $alertas ?></div>
      <div class="s-sub">próximos 30 dias</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #e53935">
      <div class="s-label"><i class="bi bi-x-circle me-1"></i>Expiradas</div>
      <div class="s-value" style="color:#e53935"><?= $expiradas ?></div>
      <div class="s-sub">requer renovação</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="filtros-bar">
    <input type="text" id="f-busca" class="form-control form-control-sm" style="width:250px"
           placeholder="🔍 Buscar por nome..." oninput="filtrar()"/>
    <select id="f-status" class="form-select form-select-sm" style="width:170px" onchange="filtrar()">
      <option value="">Todos os status</option>
      <option value="expirada">Expirada</option>
      <option value="expira_breve">Expira em breve</option>
      <option value="ok">OK</option>
      <option value="sem_expiracao">Sem expiração</option>
    </select>
    <div style="flex:1"></div>
    <a href="licencas.php" class="btn-atualizar"><i class="bi bi-arrow-clockwise"></i>Atualizar</a>
  </div>

  <!-- Tabela -->
  <div class="tbl-wrap">
    <div class="tbl-header">
      <div class="title"><i class="bi bi-table"></i> Licenças de Software — GLPI</div>
      <span id="tbl-count" style="font-size:.78rem;opacity:.85"><?= $total ?> licenças</span>
    </div>
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>Software</th>
            <th>Número de Série</th>
            <th>Tipo</th>
            <th>Expiração</th>
            <th>Status</th>
            <th>Uso (Total / Usado)</th>
          </tr>
        </thead>
        <tbody id="tbl-body">
          <?php foreach ($licencas as $lic):
            $st     = statusExpiracao($lic['expiracao']);
            $stClass = match($st) {
              'expirada'     => 'st-expirada',
              'expira_breve' => 'st-expira-breve',
              'ok'           => 'st-ok',
              default        => 'st-sem-exp',
            };
            $stLabel = match($st) {
              'expirada'     => 'Expirada',
              'expira_breve' => 'Expira em breve',
              'ok'           => 'OK',
              default        => 'Sem expiração',
            };
            $pctUso   = $lic['qtd_total'] > 0 ? min(100, round($lic['qtd_usada'] / $lic['qtd_total'] * 100)) : 0;
            $corUso   = $pctUso >= 90 ? '#e53935' : ($pctUso >= 70 ? '#fb8c00' : '#0288d1');
            $rowStyle = $st === 'expirada' ? 'background:#fff5f5' : ($st === 'expira_breve' ? 'background:#fffde7' : '');
          ?>
          <tr style="<?= $rowStyle ?>">
            <td style="font-weight:600;max-width:200px"><?= htmlspecialchars($lic['nome']) ?></td>
            <td style="font-family:monospace;font-size:.8rem;color:#6b7280"><?= htmlspecialchars($lic['numero_serie']) ?></td>
            <td><?= htmlspecialchars($lic['tipo']) ?></td>
            <td><?= $lic['expiracao'] && $lic['expiracao'] !== '0000-00-00' ? htmlspecialchars($lic['expiracao']) : '—' ?></td>
            <td><span class="badge-lic <?= $stClass ?>"><?= $stLabel ?></span></td>
            <td>
              <span style="font-size:.8rem;font-weight:600"><?= $lic['qtd_usada'] ?> / <?= $lic['qtd_total'] ?></span>
              <?php if ($lic['qtd_total'] > 0): ?>
              <div class="uso-bar">
                <div class="uso-fill" style="width:<?= $pctUso ?>%;background:<?= $corUso ?>"></div>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filtro client-side sobre as linhas já renderizadas
function filtrar() {
  const q      = document.getElementById('f-busca')?.value.toLowerCase() || '';
  const status = document.getElementById('f-status')?.value || '';
  const rows   = document.querySelectorAll('#tbl-body tr');
  let count = 0;
  rows.forEach(row => {
    const nome     = row.cells[0]?.textContent.toLowerCase() || '';
    const badgeTxt = row.cells[4]?.textContent.toLowerCase() || '';
    const stMap    = { 'expirada':'expirada', 'expira em breve':'expira_breve', 'ok':'ok', 'sem expiração':'sem_expiracao' };
    let rowStatus  = '';
    for (const [txt, val] of Object.entries(stMap)) {
      if (badgeTxt.includes(txt)) { rowStatus = val; break; }
    }
    const show = (!q || nome.includes(q)) && (!status || rowStatus === status);
    row.style.display = show ? '' : 'none';
    if (show) count++;
  });
  const cnt = document.getElementById('tbl-count');
  if (cnt) cnt.textContent = count + ' licença' + (count !== 1 ? 's' : '');
}
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI</footer>
</body>
</html>
