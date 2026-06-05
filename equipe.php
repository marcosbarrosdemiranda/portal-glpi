<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

// ── Buscar técnicos via API GLPI ──────────────────────────────────────────────
$tecnicos  = [];
$glpi_erro = '';

// Filtro de data (padrão: mês corrente)
$startDate = $_GET['startDate'] ?? date('Y-m-01');
$endDate   = $_GET['endDate'] ?? date('Y-m-t');

// Monta critério de data para API (field=15 = date de criação)
$dateCriteriaCard = '';
if ($startDate) {
    $dateCriteriaCard .= '&criteria[2][link]=AND&criteria[2][field]=15&criteria[2][searchtype]=morethan&criteria[2][value]=' . urlencode($startDate);
}
if ($endDate) {
    $idx = $startDate ? 3 : 2;
    $dateCriteriaCard .= '&criteria[' . $idx . '][link]=AND&criteria[' . $idx . '][field]=15&criteria[' . $idx . '][searchtype]=lessthan&criteria[' . $idx . '][value]=' . urlencode($endDate);
}

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
				$primeiro_nome = $u['firstname'] ?? $u['name'] ?? 'Técnico';
									$nome_comp = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
									if (!$nome_comp) $nome_comp = $primeiro_nome;

					// Buscar chamados abertos atribuídos ao técnico via Search API
										$search = 'search/Ticket' .
						'?criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=' . $u['id'] .
						'&criteria[1][link]=AND&criteria[1][field]=3&criteria[1][searchtype]=equals&criteria[1][value]=3' .
							$dateCriteriaCard . '&range=0-0';
										$tickets     = glpi_request('GET', $search, $hdrs);
										$qtd_abertos = 0;
										if (isset($tickets['data']['totalcount'])) {
											$qtd_abertos = (int) $tickets['data']['totalcount'];
										} elseif (!empty($tickets['data']['data']) && is_array($tickets['data']['data'])) {
											$qtd_abertos = $tickets['data']['count'] ?? count($tickets['data']['data']);
				}

										$tecnicos[] = [
						'id'         => $u['id'],
						'nome'       => $primeiro_nome,
											'nome_comp'  => $nome_comp,
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
    .sec-header input[type="date"] { color-scheme: dark; }

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

    /* ── Painel de detalhes inline ── */
    .detail-panel {
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      margin-top: 1rem;
      display: none;
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(0,0,0,.08);
    }
    .detail-panel.open { display: block; animation: slideDown .25s ease; }
    @keyframes slideDown {
      from { opacity: 0; transform: translateY(-10px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .detail-panel .dp-header {
      background: var(--primary); color: white;
      padding: .75rem 1.25rem;
      display: flex; align-items: center; justify-content: space-between;
    }
    .detail-panel .dp-header .dp-nome {
      font-weight: 700; font-size: .95rem;
      display: flex; align-items: center; gap: .5rem;
    }
    .detail-panel .dp-header .dp-close {
      background: none; border: none; color: rgba(255,255,255,.8);
      font-size: 1.3rem; cursor: pointer; padding: 0 .3rem; line-height: 1;
    }
    .detail-panel .dp-header .dp-close:hover { color: white; }
    .detail-panel .dp-body { padding: 1rem 1.25rem; max-height: 70vh; overflow-y: auto; }
    .detail-panel .dp-body::-webkit-scrollbar { width: 6px; }
    .detail-panel .dp-body::-webkit-scrollbar-thumb { background: #ddd; border-radius: 3px; }

    /* Seções internas do painel */
    .dp-section { margin-bottom: 1.5rem; }
    .dp-section:last-child { margin-bottom: 0; }
    .dp-section-title {
      font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
      color: #6b7280; margin-bottom: .6rem;
      display: flex; align-items: center; gap: .4rem;
      border-bottom: 1px solid #f3f4f6; padding-bottom: .4rem;
    }

    /* Rotinas */
    .rotina-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
                   padding: .5rem .75rem; margin-bottom: .35rem; display: flex; align-items: center; gap: .5rem; }
    .rotina-card .rot-icon { color: var(--mod); font-size: .9rem; }
    .rotina-card .rot-nome { font-size: .82rem; font-weight: 600; color: #1f2937; flex: 1; }
    .rotina-card .rot-badge { font-size: .65rem; background: #e8f0fe; color: #1a73e8;
                              padding: .15rem .5rem; border-radius: 8px; font-weight: 600; }
    .rotina-desc { font-size: .78rem; color: #6b7280; padding: .35rem .75rem .5rem 2.2rem;
                   border-bottom: 1px solid #f3f4f6; line-height: 1.4; }

    /* Chamados */
    .chamado-item { padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; display: flex;
                    align-items: flex-start; gap: .5rem; }
    .chamado-item:last-of-type { border-bottom: none; }
    .chamado-item .ch-id   { font-weight: 700; color: #1a73e8; font-size: .78rem; min-width: 50px; }
    .chamado-item .ch-info { flex: 1; }
    .chamado-item .ch-tit  { font-size: .82rem; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: .35rem; }
    .chamado-item .ch-meta { font-size: .72rem; color: #9ca3af; margin-top: .15rem; display: flex; gap: .75rem; }
    .chamado-item:hover    { background: #f9fafb; }
    .badge-urg-success { background: #dcfce7; color: #166534; }
    .badge-urg-info    { background: #dbeafe; color: #1e40af; }
    .badge-urg-warning { background: #fef9c3; color: #854d0e; }
    .badge-urg-danger  { background: #fee2e2; color: #991b1b; }
    .badge-urg-purple  { background: #f3e8ff; color: #6b21a8; }
    .vazio-msg { text-align: center; color: #9ca3af; padding: 2rem 0; font-size: .85rem; }

    /* Desempenho */
    .proj-card-mini { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
                      padding: .75rem 1rem; margin-bottom: .5rem; }
    .proj-card-mini .proj-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: .35rem; }
    .proj-card-mini .proj-nome  { font-size: .85rem; font-weight: 700; color: #1f2937; }
    .proj-card-mini .proj-pct   { font-size: .85rem; font-weight: 800; }
    .proj-card-mini .prog-bar   { height: 5px; background: #e5e7eb; border-radius: 3px; overflow: hidden; margin-bottom: .35rem; }
    .proj-card-mini .prog-fill  { height: 100%; border-radius: 3px; }
    .proj-card-mini .proj-meta  { font-size: .7rem; color: #9ca3af; display: flex; gap: .75rem; }
    .proj-card-mini .mod-atraso { margin-top: .35rem; font-size: .72rem; color: #ef4444; display: flex; align-items: center; gap: .3rem; }
    .status-concluido { color: #1e8e3e; }
    .status-atrasado  { color: #ef4444; }
    .status-atencao   { color: #f57c00; }
    .status-no_prazo  { color: #1a73e8; }

    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem; }

    /* ── Modal de Chamado ── */
    .chamado-modal-overlay {
      position: fixed; inset: 0; z-index: 9999;
      background: rgba(0,0,0,.45);
      display: flex; align-items: center; justify-content: center;
      padding: 1rem;
    }
    .chamado-modal-box {
      background: white; border-radius: 14px;
      max-width: 640px; width: 100%; max-height: 85vh;
      display: flex; flex-direction: column;
      box-shadow: 0 16px 48px rgba(0,0,0,.25);
      animation: modalIn .2s ease;
    }
    @keyframes modalIn {
      from { opacity: 0; transform: scale(.96) translateY(10px); }
      to   { opacity: 1; transform: scale(1) translateY(0); }
    }
    .chamado-modal-header {
      background: var(--primary); color: white;
      padding: .85rem 1.25rem; border-radius: 14px 14px 0 0;
      display: flex; align-items: center; justify-content: space-between;
    }
    .chamado-modal-tit { font-weight: 700; font-size: .95rem; display: flex; align-items: center; }
    .chamado-modal-close {
      background: none; border: none; color: rgba(255,255,255,.8);
      font-size: 1.5rem; cursor: pointer; line-height: 1; padding: 0 .2rem;
    }
    .chamado-modal-close:hover { color: white; }
    .chamado-modal-body {
      padding: 1.25rem; overflow-y: auto; flex: 1;
    }
    .chamado-modal-body::-webkit-scrollbar { width: 6px; }
    .chamado-modal-body::-webkit-scrollbar-thumb { background: #ddd; border-radius: 3px; }
    .cm-section { margin-bottom: 1rem; }
    .cm-section:last-child { margin-bottom: 0; }
    .cm-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #9ca3af; margin-bottom: .25rem; }
    .cm-value { font-size: .88rem; color: #1f2937; }
    .cm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1rem; }
    .cm-grid .cm-item { background: #f9fafb; border-radius: 8px; padding: .5rem .75rem; }

    /* Novo layout do modal */
    .cm-header-info { margin-bottom: .85rem; }
    .cm-id-status { display: flex; align-items: center; gap: .5rem; margin-bottom: .25rem; }
    .cm-id { font-weight: 800; color: #1a73e8; font-size: .85rem; }
    .cm-titulo { font-weight: 700; font-size: .95rem; color: #1f2937; line-height: 1.3; }
    .cm-val { font-size: .82rem; color: #1f2937; }
    .cm-desc { font-size: .85rem; background: #f9fafb; border-radius: 8px; padding: .6rem .85rem; white-space: pre-wrap; }

    /* Badges de urgência (mesmo estilo da agenda) */
    .badge-urg { font-size: .68rem; border-radius: 10px; padding: .15rem .5rem; font-weight: 600; }
    .urg-1 { background:#e8f5e9; color:#2e7d32; }
    .urg-2 { background:#e3f2fd; color:#1565c0; }
    .urg-3 { background:#fff3e0; color:#e65100; }
    .urg-4 { background:#fce4ec; color:#c62828; }
    .urg-5 { background:#f3e5f5; color:#6a1b9a; }

    /* Anexos (mesmo estilo da agenda) */
    .anexo-grid { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.4rem; }
    .anexo-thumb { width:80px; height:60px; object-fit:cover; border-radius:6px;
                   border:1px solid #dee2e6; cursor:pointer; transition:opacity .15s; }
    .anexo-thumb:hover { opacity:.8; }
    .anexo-file  { display:inline-flex; align-items:center; gap:.35rem; background:#f1f3f4;
                   border-radius:6px; padding:.3rem .6rem; font-size:.76rem; color:#374151;
                   text-decoration:none; }
    .anexo-file:hover { background:#e2e8f0; }

    /* Followups no modal (mesmo estilo da agenda) */
    .cm-followup { background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:.6rem .85rem; font-size:.82rem; margin-bottom:.5rem; }
    .cm-followup .fw-autor { font-weight:600; color:#1a237e; font-size:.75rem; }
    .cm-followup .fw-data  { color:#888; font-size:.72rem; margin-left:.5rem; }
    .cm-followup .fw-texto { margin-top:.25rem; color:#333; white-space:pre-wrap; line-height:1.45; }
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
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span style="font-size:.75rem;opacity:.85"><?= count($tecnicos) ?> técnico<?= count($tecnicos) !== 1 ? 's' : '' ?></span>
      <span style="font-size:.75rem;color:rgba(255,255,255,.7)">|</span>
      <input type="date" id="filtro-data-inicio"
             value="<?= htmlspecialchars($startDate) ?>"
             onchange="aplicarFiltroData()"
             style="border:none;border-radius:6px;padding:.2rem .5rem;font-size:.75rem;background:rgba(255,255,255,.2);color:white;max-width:130px">
      <span style="font-size:.72rem;color:rgba(255,255,255,.7)">até</span>
      <input type="date" id="filtro-data-fim"
             value="<?= htmlspecialchars($endDate) ?>"
             onchange="aplicarFiltroData()"
             style="border:none;border-radius:6px;padding:.2rem .5rem;font-size:.75rem;background:rgba(255,255,255,.2);color:white;max-width:130px">
      <button onclick="limparFiltroData()"
              style="border:none;background:rgba(255,255,255,.2);border-radius:6px;padding:.2rem .6rem;font-size:.72rem;color:white;cursor:pointer"
              title="Resetar para mês corrente">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
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
    <div class="card-tec"
         data-tec-id="<?= $tec['id'] ?>"
         data-tec-nome="<?= htmlspecialchars($tec['nome'], ENT_QUOTES, 'UTF-8') ?>"
         onclick="abrirDetalheTecnico(this)" style="cursor:pointer">
      <span class="badge-fonte badge-<?= $tec['fonte'] ?>"><?= $tec['fonte'] === 'glpi' ? 'GLPI' : 'Exemplo' ?></span>
      <div class="avatar-circle" style="background:<?= $cor ?>">
        <?= htmlspecialchars($ini) ?>
      </div>
      <div class="tec-nome"><?= htmlspecialchars(primeiro_nome($tec['nome'])) ?></div>
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
          <span style="font-size:.72rem;color:var(--mod);font-weight:600">
            <i class="bi bi-search"></i><br>Detalhes
          </span>
        </div>
      </div>
      <div class="carga-bar">
        <div class="carga-fill" style="width:<?= $pct ?>%;background:<?= $cor_barra ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════ PAINEL DE DETALHES INLINE ═══════════ -->
  <div class="detail-panel" id="detailPanel">
    <div class="dp-header">
      <div class="dp-nome">
        <i class="bi bi-person-circle"></i>
        <span id="det-nome"></span>
        <span id="det-carga" style="font-size:.78rem;opacity:.8;font-weight:400"></span>
      </div>
      <button class="dp-close" onclick="fecharPainel()" title="Fechar">&times;</button>
    </div>
    <div class="dp-body">
      <!-- Rotinas -->
      <div class="dp-section">
        <div class="dp-section-title"><i class="bi bi-arrow-repeat" style="color:var(--mod)"></i>Rotinas</div>
        <div id="det-rotinas-loading" class="vazio-msg"><i class="bi bi-arrow-repeat me-1"></i>Carregando rotinas…</div>
        <div id="det-rotinas-content" style="display:none"></div>
      </div>
      <!-- Chamados -->
      <div class="dp-section">
        <div class="dp-section-title"><i class="bi bi-ticket-detailed" style="color:#e53935"></i>Chamados</div>
        <div id="det-chamados-loading" class="vazio-msg"><i class="bi bi-arrow-repeat me-1"></i>Carregando chamados…</div>
        <div id="det-chamados-content" style="display:none"></div>
      </div>
      <!-- Desempenho -->
      <div class="dp-section">
        <div class="dp-section-title"><i class="bi bi-graph-up" style="color:#1a73e8"></i>Desempenho em Projetos</div>
        <div id="det-desempenho-loading" class="vazio-msg"><i class="bi bi-arrow-repeat me-1"></i>Carregando desempenho…</div>
        <div id="det-desempenho-content" style="display:none"></div>
      </div>
    </div>
  </div>

<!-- ═══════════ MODAL DE CHAMADO ═══════════ -->
<div class="chamado-modal-overlay" id="chamadoModal" onclick="fecharModalChamado(event)" style="display:none">
  <div class="chamado-modal-box" onclick="event.stopPropagation()">
    <div class="chamado-modal-header">
      <div class="chamado-modal-tit" id="chamado-modal-tit"><i class="bi bi-ticket-detailed me-2"></i><span id="chamado-modal-ticket"></span></div>
      <button class="chamado-modal-close" onclick="fecharModalChamado()">&times;</button>
    </div>
    <div class="chamado-modal-body" id="chamado-modal-body">
      <div class="vazio-msg" id="chamado-modal-loading"><i class="bi bi-arrow-repeat me-1"></i>Carregando…</div>
    </div>
  </div>
</div>

</div><!-- /wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Painel de detalhes inline ──────────────────────────────────────────────
let tecIdAtual = null;

function abrirDetalheTecnico(el) {
  const id = parseInt(el.dataset.tecId);
  const nome = el.dataset.tecNome;

  // Se clicou no mesmo card já aberto, fecha
  if (tecIdAtual === id && document.getElementById('detailPanel').classList.contains('open')) {
    fecharPainel();
    return;
  }
  tecIdAtual = id;

  const panel = document.getElementById('detailPanel');

  // Limpa conteúdo anterior
  ['rotinas','chamados','desempenho'].forEach(t => {
    document.getElementById('det-' + t + '-content').style.display = 'none';
    document.getElementById('det-' + t + '-content').innerHTML = '';
    document.getElementById('det-' + t + '-loading').style.display = '';
  });

  // Seta cabeçalho
  document.getElementById('det-nome').textContent = nome;
  document.getElementById('det-carga').textContent = '#' + id;

  // Abre painel
  panel.classList.add('open');

  // Scroll suave até o painel
  setTimeout(() => {
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, 50);

  // Fetch dos dados (com filtro de data)
  const dtInicio = document.getElementById('filtro-data-inicio').value;
  const dtFim = document.getElementById('filtro-data-fim').value;
  let url = 'equipe_detalhe.php?id=' + encodeURIComponent(id) + '&nome=' + encodeURIComponent(nome);
  if (dtInicio) url += '&startDate=' + encodeURIComponent(dtInicio);
  if (dtFim) url += '&endDate=' + encodeURIComponent(dtFim);

  fetch(url)
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(data => {
      if (!data.ok) {
        mostrarErro(escHtml(data.msg || 'Erro ao carregar dados'));
        return;
      }
      renderRotinas(data.rotinas);
      renderChamados(data.chamados);
      renderDesempenho(data.desempenho);
    })
    .catch(err => {
      mostrarErro('Erro: ' + escHtml(err.message) + '. Verifique console (F12).');
    });
}

function fecharPainel() {
  document.getElementById('detailPanel').classList.remove('open');
  tecIdAtual = null;
}

function mostrarErro(msg) {
  ['rotinas','chamados','desempenho'].forEach(t => {
    document.getElementById('det-' + t + '-loading').style.display = 'none';
    document.getElementById('det-' + t + '-content').style.display = '';
    document.getElementById('det-' + t + '-content').innerHTML =
      '<div class="vazio-msg"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>' + msg + '</div>';
  });
}

// ── Filtro de data global ────────────────────────────────────────────────
function aplicarFiltroData() {
  const dtInicio = document.getElementById('filtro-data-inicio').value;
  const dtFim = document.getElementById('filtro-data-fim').value;
  const url = new URL(window.location);
  if (dtInicio) url.searchParams.set('startDate', dtInicio);
  else url.searchParams.delete('startDate');
  if (dtFim) url.searchParams.set('endDate', dtFim);
  else url.searchParams.delete('endDate');
  window.location.href = url.toString();
}

function limparFiltroData() {
  // Volta para mês corrente
  const hoje = new Date();
  const mes = String(hoje.getMonth() + 1).padStart(2, '0');
  const ano = hoje.getFullYear();
  const ultimoDia = new Date(ano, hoje.getMonth() + 1, 0).getDate();
  document.getElementById('filtro-data-inicio').value = ano + '-' + mes + '-01';
  document.getElementById('filtro-data-fim').value = ano + '-' + mes + '-' + String(ultimoDia).padStart(2, '0');
  aplicarFiltroData();
}

// ── Render: Rotinas ──────────────────────────────────────────────────────
const LABELS = {
  diario:    { icon: 'bi-calendar-check', label: 'Diário' },
  semanal:   { icon: 'bi-calendar-week',  label: 'Semanal' },
  quinzenal: { icon: 'bi-calendar2-week', label: 'Quinzenal' },
  mensal:    { icon: 'bi-calendar-month',  label: 'Mensal' },
};

function renderRotinas(rotinas) {
  const el = document.getElementById('det-rotinas-content');
  const ld = document.getElementById('det-rotinas-loading');
  ld.style.display = 'none';

  let html = '';
  let total = 0;

  for (const chave of ['diario','semanal','quinzenal','mensal']) {
    const items = rotinas[chave] || [];
    if (items.length === 0) continue;
    total += items.length;

    const lbl = LABELS[chave] || { icon: 'bi-calendar', label: chave };

    html += '<div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:.7rem 0 .35rem;display:flex;align-items:center;gap:.4rem">' +
      '<i class="bi ' + lbl.icon + '" style="color:var(--mod)"></i>' + lbl.label +
      ' <span style="font-weight:400;color:#bbb;font-size:.72rem">(' + items.length + ')</span></div>';

    items.forEach((r, idx) => {
      // Determina badge de frequência (D/S/Q/M) baseado no valor de periodicidade
      const per = r.periodicidade || '';
      let freqBadge = '?';
      if (per === '86400' || per === 'DAY') freqBadge = 'D';
      else if (per === '604800' || per === 'WEEK') freqBadge = 'S';
      else if (per === '1296000') freqBadge = 'Q';
      else if (per === '2592000' || per === 'MONTH' || /month/i.test(per)) freqBadge = 'M';
      else if (/2\s*month/i.test(per)) freqBadge = 'M';

      const temDesc = r.descricao && r.descricao.length > 0;
      const descId = 'rd-' + chave + '-' + idx;

      html += '<div class="rotina-card">' +
        '<i class="bi bi-arrow-repeat rot-icon"></i>' +
        '<span class="rot-nome" ' +
        (temDesc ? 'onclick="toggleRotDesc(\'' + descId + '\')" style="cursor:pointer;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px"' : '') +
        '>' + escHtml(r.nome) + '</span>' +
        '<span class="rot-badge">' + freqBadge + '</span>' +
        '</div>' +
        (temDesc ? '<div id="' + descId + '" class="rotina-desc" style="display:none">' + escHtml(r.descricao.substr(0, 300)) + '</div>' : '');
    });
  }

  if (total === 0) {
    html = '<div class="vazio-msg" style="padding:.75rem 0"><i class="bi bi-emoji-neutral me-1"></i>Nenhuma rotina ativa para este técnico.</div>';
  }

  el.innerHTML = html;
  el.style.display = '';
}

function toggleRotDesc(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}

// ── Render: Chamados ─────────────────────────────────────────────────────
function renderChamados(chamados) {
  const el = document.getElementById('det-chamados-content');
  const ld = document.getElementById('det-chamados-loading');
  ld.style.display = 'none';

  let html = '';

  // Abertos
  const abertos = chamados.abertos || [];
  html += '<div class="chamado-section">' +
    '<div class="chamado-sec-header" onclick="toggleChamadosSec(this)" style="cursor:pointer;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:.35rem 0;display:flex;align-items:center;gap:.4rem;border-bottom:1px solid #f3f4f6;user-select:none">' +
    '<i class="bi bi-ticket-fill text-danger"></i>Em Andamento (' + abertos.length + ')' +
    '<span style="flex:1"></span>' +
    '<i class="bi bi-chevron-down" style="font-size:.6rem;transition:transform .2s"></i></div>' +
    '<div class="chamados-sec-body" style="display:none">';
  if (abertos.length === 0) {
    html += '<div class="vazio-msg" style="padding:.5rem 0"><i class="bi bi-emoji-smile me-1"></i>Nenhum chamado em aberto.</div>';
  } else {
    for (const c of abertos) {
      html += renderChamadoItem(c);
    }
  }
  html += '</div></div>';

  // Concluídos
  const concluidos = chamados.concluidos || [];
  html += '<div class="chamado-section" style="margin-top:.25rem">' +
    '<div class="chamado-sec-header" onclick="toggleChamadosSec(this)" style="cursor:pointer;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:.35rem 0;display:flex;align-items:center;gap:.4rem;border-bottom:1px solid #f3f4f6;user-select:none">' +
    '<i class="bi bi-check-circle-fill text-success"></i>Concluídos (' + concluidos.length + ')' +
    '<span style="flex:1"></span>' +
    '<i class="bi bi-chevron-down" style="font-size:.6rem;transition:transform .2s"></i></div>' +
    '<div class="chamados-sec-body" style="display:none">';
  if (concluidos.length === 0) {
    html += '<div class="vazio-msg" style="padding:.5rem 0"><i class="bi bi-inbox me-1"></i>Nenhum chamado concluído.</div>';
  } else {
    const limitados = concluidos.slice(0, 10);
    for (const c of limitados) {
      html += renderChamadoItem(c);
    }
    if (concluidos.length > 10) {
      html += '<div class="vazio-msg" style="padding:.5rem 0;font-size:.78rem">… e mais ' + (concluidos.length - 10) + ' chamado(s).</div>';
    }
  }
  html += '</div></div>';

  // Ambos os blocos começam recolhidos
  el.innerHTML = html;
  el.style.display = '';
}

function toggleChamadosSec(header) {
  const body = header.nextElementSibling;
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : '';
  const ch = header.querySelector('.bi-chevron-down');
  if (ch) ch.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function renderChamadoItem(c) {
  const tipoIcon = c.tipo === 'Requisição' ? 'bi-box-seam' : 'bi-bug';
  return '<div class="chamado-item" onclick="abrirModalChamado(' + c.id + ')" style="cursor:pointer">' +
    '<span class="ch-id">#' + c.id + '</span>' +
    '<div class="ch-info">' +
    '<div class="ch-tit">' +
    escHtml(c.titulo.substr(0, 55)) +
    '</div>' +
    '<div class="ch-meta">' +
    '<span><i class="bi ' + tipoIcon + ' me-1"></i>' + c.tipo + '</span>' +
    '<span><i class="bi bi-building me-1"></i>' + escHtml(c.entidade || '—') + '</span>' +
    '<span><i class="bi bi-clock me-1"></i>' + c.atualizado + '</span>' +
    '</div>' +
    '</div>' +
    '</div>';
}

// ── Render: Desempenho ────────────────────────────────────────────────────
const STATUS_CFG = {
  concluido: { icon: 'bi-check-circle-fill',  cls: 'status-concluido', label: 'Concluído' },
  atrasado:  { icon: 'bi-x-circle-fill',      cls: 'status-atrasado',  label: 'Em atraso' },
  atencao:   { icon: 'bi-exclamation-triangle-fill', cls: 'status-atencao',  label: 'Atenção' },
  no_prazo:  { icon: 'bi-check-circle',       cls: 'status-no_prazo',  label: 'No prazo' },
};

function renderDesempenho(projetos) {
  const el = document.getElementById('det-desempenho-content');
  const ld = document.getElementById('det-desempenho-loading');
  ld.style.display = 'none';

  let html = '';

  if (projetos.length === 0) {
    html = '<div class="vazio-msg" style="padding:.5rem 0"><i class="bi bi-folder me-1"></i>Nenhum projeto associado a este técnico.</div>';
  } else {
    html += '<div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem">' +
      '<i class="bi bi-kanban" style="color:#1a73e8"></i>' + projetos.length + ' projeto(s)</div>';

    for (const p of projetos) {
      const cfg = STATUS_CFG[p.status] || STATUS_CFG.no_prazo;
      const pctColor = p.pct >= 80 ? '#1e8e3e' : (p.pct >= 40 ? '#f57c00' : '#1a73e8');

      html += '<div class="proj-card-mini">' +
        '<div class="proj-header">' +
        '<span class="proj-nome">' + escHtml(p.titulo) + '</span>' +
        '<span class="proj-pct" style="color:' + pctColor + '">' + p.pct + '%</span>' +
        '</div>' +
        '<div class="prog-bar"><div class="prog-fill" style="width:' + p.pct + '%;background:' + pctColor + '"></div></div>' +
        '<div class="d-flex align-items-center justify-content-between">' +
        '<div class="proj-meta">' +
        '<span><i class="bi bi-check-circle me-1"></i>' + p.done + '/' + p.total + ' tarefas</span>' +
        '<span><i class="bi bi-layers me-1"></i>' + p.modulos + ' módulos</span>' +
        (p.prazo ? '<span><i class="bi bi-calendar me-1"></i>' + p.prazo + '</span>' : '') +
        '</div>' +
        '<span class="' + cfg.cls + '" style="font-size:.72rem;font-weight:700"><i class="bi ' + cfg.icon + ' me-1"></i>' + cfg.label + '</span>' +
        '</div>';

      if (p.mod_atraso && p.mod_atraso.length > 0) {
        for (const m of p.mod_atraso) {
          html += '<div class="mod-atraso"><i class="bi bi-exclamation-circle-fill"></i>' +
            escHtml(m.nome) + ' — ' + m.pct + '% concluído</div>';
        }
      }

      html += '</div>';
    }
  }

  el.innerHTML = html;
  el.style.display = '';
}

// ── Modal de Chamado ──────────────────────────────────────────────────────
function abrirModalChamado(id) {
  const modal = document.getElementById('chamadoModal');
  document.getElementById('chamado-modal-ticket').textContent = '#' + id;
  document.getElementById('chamado-modal-body').innerHTML =
    '<div class="vazio-msg" id="chamado-modal-loading"><i class="bi bi-arrow-repeat me-1"></i>Carregando…</div>';
  modal.style.display = 'flex';

  fetch('chamado_ajax.php?id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        document.getElementById('chamado-modal-body').innerHTML =
          '<div class="vazio-msg"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>' + escHtml(data.msg || 'Erro ao carregar chamado') + '</div>';
        return;
      }
      renderModalChamado(data.chamado, data.followups || [], data.docs || []);
    })
    .catch(err => {
      document.getElementById('chamado-modal-body').innerHTML =
        '<div class="vazio-msg"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Erro de rede: ' + escHtml(err.message) + '</div>';
    });
}

function fecharModalChamado(e) {
  if (e && e.target !== e.currentTarget) return; // só fecha se clicar no overlay
  document.getElementById('chamadoModal').style.display = 'none';
}
// ESC fecha modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const modal = document.getElementById('chamadoModal');
    if (modal.style.display === 'flex') modal.style.display = 'none';
  }
});

const URG_CLASSES = { 1:'urg-1',2:'urg-2',3:'urg-3',4:'urg-4',5:'urg-5' };
const STATUS_CLASSES = { 1:'badge bg-secondary',2:'badge bg-info',3:'badge bg-primary',4:'badge bg-warning text-dark',5:'badge bg-success',6:'badge bg-dark' };

function renderModalChamado(c, followups, docs) {
  const urgCls = URG_CLASSES[c.status_num] || 'urg-3';
  const sttCls = STATUS_CLASSES[c.status_num] || 'badge bg-secondary';

  // Badge de urgência
  const urgBadge = '<span class="badge-urg ' + urgCls + '">' + escHtml(c.urgencia) + '</span>';

  // Followups HTML
  const fuHtml = followups.map(fu => {
    const autor = escHtml(fu.autor || 'Sistema');
    const data = escHtml(fu.data || '');
    const conteudo = fu.conteudo || '—';
    return '<div class="followup-item cm-followup">' +
      '<span class="fw-autor"><i class="bi bi-person-circle me-1"></i>' + autor + '</span>' +
      '<span class="fw-data"><i class="bi bi-clock me-1"></i>' + data + '</span>' +
      '<div class="fw-texto">' + conteudo + '</div>' +
      '</div>';
  }).join('');

  // Anexos HTML
  let anexosHtml = '';
  if (docs && docs.length > 0) {
    anexosHtml = docs.map(doc => {
      if (doc.isImg) {
        return '<img class="anexo-thumb" src="glpi_doc_proxy.php?docid=' + doc.id + '" alt="' + escHtml(doc.nome) + '" title="' + escHtml(doc.nome) + '" onclick="window.open(\'glpi_doc_proxy.php?docid=' + doc.id + '\')">';
      }
      return '<a class="anexo-file" href="glpi_doc_proxy.php?docid=' + doc.id + '" target="_blank"><i class="bi bi-file-earmark"></i>' + escHtml(doc.nome.length > 25 ? doc.nome.slice(0,23) + '…' : doc.nome) + '</a>';
    }).join('');
    anexosHtml = '<div class="cm-label"><i class="bi bi-paperclip me-1"></i>Anexos</div><div class="anexo-grid">' + anexosHtml + '</div>';
  }

  const html =
    '<div class="cm-header-info">' +
    '<div class="cm-id-status"><span class="cm-id">#' + c.id + '</span> <span class="' + sttCls + '" style="font-size:.75rem">' + escHtml(c.status) + '</span></div>' +
    '<div class="cm-titulo">' + escHtml(c.titulo) + '</div>' +
    '</div>' +
    '<div class="cm-grid">' +
    '<div class="cm-item"><div class="cm-label">Tipo</div><div class="cm-val">' + escHtml(c.tipo) + '</div></div>' +
    '<div class="cm-item"><div class="cm-label">Urgência</div><div class="cm-val">' + urgBadge + '</div></div>' +
    '<div class="cm-item"><div class="cm-label">Entidade</div><div class="cm-val">' + escHtml(c.entidade || '—') + '</div></div>' +
    '<div class="cm-item"><div class="cm-label">Requerente</div><div class="cm-val">' + escHtml(c.requerente || '—') + '</div></div>' +
    '<div class="cm-item"><div class="cm-label">Categoria</div><div class="cm-val">' + escHtml(c.categoria || '—') + '</div></div>' +
    '<div class="cm-item"><div class="cm-label">Data</div><div class="cm-val">' + escHtml(c.data || '—') + '</div></div>' +
    '</div>' +
    '<div class="cm-section"><div class="cm-label">Descrição</div><div class="cm-value cm-desc" style="line-height:1.5">' + (c.descricao || '—') + '</div></div>' +
    (c.solucao ? '<div class="cm-section"><div class="cm-label"><i class="bi bi-check-circle-fill text-success me-1"></i>Solução</div><div class="cm-value cm-desc" style="line-height:1.5">' + c.solucao + '</div></div>' : '') +
    (followups.length > 0 ? '<div class="cm-section"><div class="cm-label"><i class="bi bi-chat-dots me-1"></i>Acompanhamento (' + followups.length + ')</div>' + fuHtml + '</div>' : '') +
    (anexosHtml ? '<div class="cm-section">' + anexosHtml + '</div>' : '') +
    '<div style="text-align:right;margin-top:.75rem">' +
    '<a href="chamado.php?id=' + c.id + '" class="btn btn-sm btn-outline-primary" target="_blank" style="font-size:.78rem;text-decoration:none">' +
    '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir página completa</a></div>';

  document.getElementById('chamado-modal-body').innerHTML = html;
}

function escHtml(s) {
  if (typeof s !== 'string') s = String(s || '');
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI</footer>
</body>
</html>