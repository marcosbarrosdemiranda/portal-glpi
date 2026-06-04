<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

// ── Buscar técnicos via API GLPI ──────────────────────────────────────────────
$tecnicos  = [];
$glpi_erro = '';

function glpi_request(string $method, string $endpoint, array $headers = [], $body = null): array {
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
    // Iniciar sessão
    $init = glpi_request('GET', 'initSession', [
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

        // Buscar usuários com perfil técnico (profiles_id=4)
        $users = glpi_request('GET',
            'User?range=0-100&expand_dropdowns=true&searchText[profiles_id]=4',
            $hdrs
        );

        if (!empty($users['data']) && is_array($users['data'])) {
            foreach ($users['data'] as $u) {
                if (!isset($u['id'])) continue;
                $nome_tec = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                if (!$nome_tec) $nome_tec = $u['name'] ?? 'Técnico';

                // Buscar chamados abertos atribuídos ao técnico via Search API
                // field=5 = técnico atribuído | field=12 = status | lessthan 5 = não resolvido/fechado
                $search = 'search/Ticket' .
                    '?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $u['id'] .
                    '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=lessthan&criteria[1][value]=5' .
                    '&range=0-0';
                $tickets     = glpi_request('GET', $search, $hdrs);
                $qtd_abertos = 0;
                if (isset($tickets['data']['totalcount'])) {
                    $qtd_abertos = (int) $tickets['data']['totalcount'];
                } elseif (!empty($tickets['data']['data']) && is_array($tickets['data']['data'])) {
                    $qtd_abertos = $tickets['data']['count'] ?? count($tickets['data']['data']);
                }

                $tecnicos[] = [
                    'id'         => $u['id'],
                    'nome'       => $nome_tec,
                    'email'      => $u['email'] ?? '',
                    'abertos'    => $qtd_abertos,
                    'fonte'      => 'glpi',
                ];
            }
        }

        // Encerrar sessão
        glpi_request('GET', 'killSession', $hdrs);
    } else {
        $glpi_erro = 'Não foi possível autenticar na API do GLPI.';
    }
} catch (\Throwable $e) {
    $glpi_erro = 'Erro ao conectar ao GLPI: ' . htmlspecialchars($e->getMessage());
}

// Fallback: lista padrão de exemplo
if (empty($tecnicos)) {
    if (!$glpi_erro) $glpi_erro = 'API GLPI não retornou técnicos.';
    $tecnicos = [
        ['id'=>1,'nome'=>'Carlos Silva',  'email'=>'carlos@ti.local',  'abertos'=>3,'fonte'=>'exemplo'],
        ['id'=>2,'nome'=>'Ana Souza',     'email'=>'ana@ti.local',     'abertos'=>5,'fonte'=>'exemplo'],
        ['id'=>3,'nome'=>'Pedro Martins', 'email'=>'pedro@ti.local',   'abertos'=>1,'fonte'=>'exemplo'],
        ['id'=>4,'nome'=>'Julia Lima',    'email'=>'julia@ti.local',   'abertos'=>7,'fonte'=>'exemplo'],
    ];
}

// Paleta de cores para avatares
$cores = ['#1a73e8','#e67c00','#0f9d58','#9c27b0','#e53935','#0288d1','#00897b','#f57c00'];

function iniciais(string $nome): string {
    $partes = array_filter(explode(' ', $nome));
    if (count($partes) >= 2) {
        return strtoupper(substr(reset($partes), 0, 1) . substr(end($partes), 0, 1));
    }
    return strtoupper(substr($nome, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Equipe de TI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --mod: #00897b; }
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

    /* ── Seção header ── */
    .sec-header {
      background: var(--mod); color: white;
      border-radius: 12px 12px 0 0; padding: .75rem 1.25rem;
      display: flex; align-items: center; justify-content: space-between;
    }
    .sec-header .title { font-weight: 700; font-size: .95rem; display: flex; align-items: center; gap: .5rem; }

    /* ── Cards de técnicos ── */
    .cards-grid {
      background: white; border-radius: 0 0 12px 12px;
      border: 1px solid #e5e7eb; border-top: none;
      padding: 1.25rem;
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;
    }

    .card-tec {
      border: 1px solid #e5e7eb; border-radius: 12px;
      padding: 1.25rem; background: #fafafa;
      transition: transform .15s, box-shadow .15s;
      position: relative;
    }
    .card-tec:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.1); background: white; }

    .avatar-circle {
      width: 54px; height: 54px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: white; font-weight: 700; font-size: 1.2rem;
      margin: 0 auto .85rem;
      box-shadow: 0 3px 10px rgba(0,0,0,.18);
    }

    .tec-nome  { font-weight: 700; font-size: .95rem; text-align: center; color: #1f2937; }
    .tec-email { font-size: .75rem; color: #9ca3af; text-align: center; margin-top: .15rem; word-break: break-all; }

    .tec-stats {
      display: flex; justify-content: space-around; margin-top: 1rem;
      padding-top: .75rem; border-top: 1px solid #f3f4f6;
    }
    .tec-stat-item { text-align: center; }
    .tec-stat-item .val { font-size: 1.4rem; font-weight: 700; line-height: 1; }
    .tec-stat-item .lbl { font-size: .68rem; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; }

    /* barra de carga estética */
    .carga-bar { height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: .85rem; overflow: hidden; }
    .carga-fill { height: 100%; border-radius: 3px; transition: width .6s; }

    .badge-fonte {
      position: absolute; top: .6rem; right: .6rem;
      font-size: .6rem; padding: .2rem .45rem; border-radius: 6px;
      font-weight: 600; text-transform: uppercase;
    }
    .badge-glpi    { background: #e8f0fe; color: #1a73e8; }
    .badge-exemplo { background: #fff3e0; color: #e65100; }

    /* Alerta GLPI */
    .alerta-glpi {
      background: #fff8e1; border: 1px solid #ffe082; border-radius: 10px;
      padding: .75rem 1rem; margin-bottom: 1rem;
      font-size: .82rem; color: #6d4c00; display: flex; align-items: center; gap: .5rem;
    }

    /* Stats globais */
    .stats-bar {
      display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem;
    }
    .stat-pill {
      background: white; border: 1px solid #e5e7eb; border-radius: 10px;
      padding: .5rem 1rem; font-size: .82rem; font-weight: 600;
      display: flex; align-items: center; gap: .4rem;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }

    .btn-atualizar {
      background: var(--mod); border: none; color: white;
      border-radius: 8px; padding: .4rem 1.1rem;
      font-size: .82rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: .4rem;
      text-decoration: none;
    }
    .btn-atualizar:hover { background: #00796b; color: white; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-people-fill"></i> Equipe de TI</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<!-- Hero -->
<div class="hero">
  <h1><i class="bi bi-people-fill me-2"></i>Equipe de TI</h1>
  <p>Visão da equipe: carga de chamados por técnico e disponibilidade</p>
</div>

<div class="wrap">

  <?php if ($glpi_erro): ?>
  <div class="alerta-glpi">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($glpi_erro) ?>
    <?php if (array_column($tecnicos, 'fonte')[0] === 'exemplo'): ?>
    — Exibindo lista de exemplo.
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-bar">
    <div class="stat-pill">
      <i class="bi bi-people" style="color:var(--mod)"></i>
      Técnicos: <strong><?= count($tecnicos) ?></strong>
    </div>
    <div class="stat-pill">
      <i class="bi bi-ticket-detailed" style="color:#e53935"></i>
      Chamados abertos: <strong><?= array_sum(array_column($tecnicos,'abertos')) ?></strong>
    </div>
    <div class="stat-pill">
      <i class="bi bi-arrow-repeat" style="color:#1a73e8"></i>
      Última atualização: <strong><?= date('H:i') ?></strong>
    </div>
    <div style="flex:1"></div>
    <a href="equipe.php" class="btn-atualizar">
      <i class="bi bi-arrow-clockwise"></i>Atualizar
    </a>
  </div>

  <!-- Seção Técnicos -->
  <div class="sec-header">
    <div class="title"><i class="bi bi-person-badge-fill"></i> Técnicos de TI</div>
    <span style="font-size:.75rem;opacity:.85"><?= count($tecnicos) ?> técnico<?= count($tecnicos) !== 1 ? 's' : '' ?> encontrado<?= count($tecnicos) !== 1 ? 's' : '' ?></span>
  </div>
  <div class="cards-grid">
    <?php
    $max_abertos = max(array_column($tecnicos, 'abertos') ?: [1]);
    foreach ($tecnicos as $i => $tec):
        $cor       = $cores[$i % count($cores)];
        $ini       = iniciais($tec['nome']);
        $pct       = $max_abertos > 0 ? round($tec['abertos'] / $max_abertos * 100) : 0;
        $cor_barra = $pct >= 80 ? '#e53935' : ($pct >= 50 ? '#fb8c00' : '#00897b');
    ?>
    <div class="card-tec">
      <span class="badge-fonte badge-<?= $tec['fonte'] ?>"><?= $tec['fonte'] === 'glpi' ? 'GLPI' : 'Exemplo' ?></span>
      <div class="avatar-circle" style="background:<?= $cor ?>">
        <?= htmlspecialchars($ini) ?>
      </div>
      <div class="tec-nome"><?= htmlspecialchars($tec['nome']) ?></div>
      <?php if ($tec['email']): ?>
      <div class="tec-email"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($tec['email']) ?></div>
      <?php endif; ?>
      <div class="tec-stats">
        <div class="tec-stat-item">
          <div class="val" style="color:#e53935"><?= $tec['abertos'] ?></div>
          <div class="lbl">Abertos</div>
        </div>
        <div class="tec-stat-item">
          <div class="val" style="color:#1a73e8"><?= $pct ?>%</div>
          <div class="lbl">Carga</div>
        </div>
        <div class="tec-stat-item">
          <a href="historico.php" style="font-size:.72rem;color:var(--mod);text-decoration:none;font-weight:600">
            <i class="bi bi-clock-history"></i><br>Ver histórico
          </a>
        </div>
      </div>
      <div class="carga-bar">
        <div class="carga-fill" style="width:<?= $pct ?>%;background:<?= $cor_barra ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI</footer>
</body>
</html>
