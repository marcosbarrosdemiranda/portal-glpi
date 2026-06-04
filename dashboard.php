<?php
session_start();
if (empty($_SESSION['autenticado'])) {
    header('Location: auth.php');
    exit;
}
$nome    = $_SESSION['nome']   ?? $_SESSION['usuario'] ?? 'Atendente';
$perfil  = $_SESSION['perfil'] ?? 'tecnico';
$is_self = ($perfil === 'self-service');
$hora    = (int)date('H');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: auth.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Central TI — Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary: #1a237e; --accent: #1a73e8; }

    * { box-sizing: border-box; }
    body {
      min-height: 100vh;
      background: #f0f4f9;
      font-family: 'Segoe UI', sans-serif;
      margin: 0;
    }

    /* ── Navbar ── */
    .topbar {
      background: linear-gradient(135deg, var(--primary), #1565c0);
      color: white;
      padding: .9rem 2rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 12px rgba(0,0,0,.25);
    }
    .topbar .brand { font-size: 1.2rem; font-weight: 700; display:flex; align-items:center; gap:.6rem; }
    .topbar .user  { display:flex; align-items:center; gap:.75rem; font-size:.9rem; }
    .topbar .avatar {
      width: 36px; height: 36px; background: rgba(255,255,255,.2);
      border-radius: 50%; display:flex; align-items:center; justify-content:center;
      border: 2px solid rgba(255,255,255,.4); font-size:1.1rem;
    }
    .btn-logout {
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3);
      color: white; border-radius: 8px; padding: .3rem .8rem;
      font-size: .82rem; cursor: pointer; text-decoration: none;
      transition: background .2s;
    }
    .btn-logout:hover { background: rgba(255,255,255,.25); color: white; }

    /* ── Hero ── */
    .hero {
      background: linear-gradient(135deg, var(--primary) 0%, #1565c0 100%);
      color: white; padding: 3rem 2rem 5rem;
      text-align: center;
    }
    .hero h2 { font-size: 1.8rem; font-weight: 300; margin: 0; }
    .hero h2 strong { font-weight: 700; }
    .hero p  { opacity: .8; margin-top: .5rem; font-size: 1rem; }

    /* ── Cards ── */
    .cards-wrap {
      max-width: 900px; margin: -3rem auto 2rem;
      padding: 0 1.5rem;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1.25rem;
    }

    .dash-card {
      background: white;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,.1);
      padding: 2rem 1.5rem;
      text-align: center;
      text-decoration: none;
      color: #333;
      transition: transform .2s, box-shadow .2s;
      cursor: pointer;
      border-top: 5px solid transparent;
    }
    .dash-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 32px rgba(0,0,0,.15);
      color: #333;
    }

    .dash-card .card-icon {
      width: 70px; height: 70px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.25rem;
      font-size: 2rem;
    }
    .dash-card h5 { font-weight: 700; font-size: 1.1rem; margin: 0 0 .4rem; }
    .dash-card p  { font-size: .85rem; color: #888; margin: 0; }

    /* Cores por card */
    .card-agenda    { border-top-color: #1a73e8; }
    .card-agenda    .card-icon { background: #e8f0fe; color: #1a73e8; }

    .card-atendente { border-top-color: #d93025; }
    .card-atendente .card-icon { background: #fdecea; color: #d93025; }

    .card-usuario   { border-top-color: #1e8e3e; }
    .card-usuario   .card-icon { background: #e6f4ea; color: #1e8e3e; }

    .card-historico { border-top-color: #7b1fa2; }
    .card-historico .card-icon { background: #f3e5f5; color: #7b1fa2; }

    .card-relatorio { border-top-color: #00bfff; }
    .card-relatorio .card-icon { background: #e0f7ff; color: #0077aa; }

    .card-inventario { border-top-color: #0097a7; }
    .card-inventario .card-icon { background: #e0f7fa; color: #0097a7; }

    .card-conhecimento { border-top-color: #f57c00; }
    .card-conhecimento .card-icon { background: #fff3e0; color: #f57c00; }

    .card-projetos    { border-top-color: #e91e63; }
    .card-projetos    .card-icon { background: #fce4ec; color: #e91e63; }

    .card-equipe      { border-top-color: #00897b; }
    .card-equipe      .card-icon { background: #e0f2f1; color: #00897b; }

    .card-orcamento   { border-top-color: #43a047; }
    .card-orcamento   .card-icon { background: #e8f5e9; color: #43a047; }

    .card-contratos   { border-top-color: #5e35b1; }
    .card-contratos   .card-icon { background: #ede7f6; color: #5e35b1; }

    .card-licencas    { border-top-color: #0288d1; }
    .card-licencas    .card-icon { background: #e1f5fe; color: #0288d1; }

    .card-sla         { border-top-color: #e53935; }
    .card-sla         .card-icon { background: #ffebee; color: #e53935; }

    .card-cofre       { border-top-color: #37474f; }
    .card-cofre       .card-icon { background: #eceff1; color: #37474f; }

    .card-acesso-remoto { border-top-color: #1d4ed8; }
    .card-acesso-remoto .card-icon { background: #dbeafe; color: #1d4ed8; }

    .card-infra       { border-top-color: #b91c1c; }
    .card-infra       .card-icon { background: #fee2e2; color: #b91c1c; }

    .card-erp         { border-top-color: #065f46; }
    .card-erp         .card-icon { background: #d1fae5; color: #065f46; }

    /* Separador de seção */
    .section-label {
      grid-column: 1 / -1;
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .1em; color: #6b7280;
      padding: .6rem .25rem .3rem;
      border-bottom: 2px solid #e5e7eb;
      margin-top: 1rem;
      display: flex; align-items: center; gap: .4rem;
    }
    .section-label:first-child { margin-top: 0; }

    /* Rodapé */
    footer {
      text-align: center; color: #bbb; font-size: .78rem;
      padding: 2rem; margin-top: auto;
    }
  </style>
</head>
<body>

<!-- Navbar -->
<div class="topbar">
  <div class="brand">
    <i class="bi bi-headset"></i> Central de TI
  </div>
  <div class="user">
    <div class="avatar"><i class="bi bi-person-fill"></i></div>
    <span><?= htmlspecialchars($nome) ?></span>
    <a href="?logout=1" class="btn-logout">
      <i class="bi bi-box-arrow-right me-1"></i>Sair
    </a>
  </div>
</div>

<!-- Hero -->
<div class="hero">
  <h2><?= $saudacao ?>, <strong><?= htmlspecialchars(explode(' ', $nome)[0]) ?></strong>!</h2>
  <p>O que você deseja fazer hoje?</p>
</div>

<!-- Cards -->
<div class="cards-wrap">

<?php if ($is_self): ?>
  <!-- Self-Service: apenas abrir chamado e meus chamados -->
  <a href="portal/atendente.php" class="dash-card card-usuario">
    <div class="card-icon"><i class="bi bi-person-raised-hand"></i></div>
    <h5>Abrir Chamado</h5>
    <p>Formulário rápido para solicitar suporte ao setor de TI.</p>
  </a>

  <a href="meus_chamados.php" class="dash-card card-agenda">
    <div class="card-icon"><i class="bi bi-clock-history"></i></div>
    <h5>Meus Chamados</h5>
    <p>Acompanhe o status das suas solicitações abertas e resolvidas.</p>
  </a>

  <a href="conhecimento.php" class="dash-card card-conhecimento">
    <div class="card-icon"><i class="bi bi-book-fill"></i></div>
    <h5>Área do Conhecimento</h5>
    <p>Acesse artigos, tutoriais e documentações da equipe de TI.</p>
  </a>

<?php else: ?>

  <!-- ── ATENDIMENTO ── -->
  <div class="section-label"><i class="bi bi-headset me-2"></i>Atendimento</div>

  <a href="agenda/" class="dash-card card-agenda">
    <div class="card-icon"><i class="bi bi-calendar3"></i></div>
    <h5>Agenda de Atendimentos</h5>
    <p>Visualize e gerencie os chamados agendados, crie eventos e acompanhe sua equipe.</p>
  </a>

  <a href="portal/" class="dash-card card-atendente">
    <div class="card-icon"><i class="bi bi-headset"></i></div>
    <h5>Abrir Chamado</h5>
    <p class="text-muted small mb-1">Por atendente</p>
    <p>Formulário completo para técnicos de TI registrarem chamados com todos os detalhes.</p>
  </a>

  <a href="historico.php" class="dash-card card-historico">
    <div class="card-icon"><i class="bi bi-clock-history"></i></div>
    <h5>Histórico de Chamados</h5>
    <p>Liste, filtre e acompanhe todos os chamados registrados no GLPI.</p>
  </a>

  <!-- ── KPIs ── -->
  <div class="section-label"><i class="bi bi-bar-chart-line me-2"></i>KPIs</div>

  <a href="relatorios.php" class="dash-card card-relatorio">
    <div class="card-icon"><i class="bi bi-bar-chart-fill"></i></div>
    <h5>Painel de Relatórios</h5>
    <p>Dashboards com gráficos de chamados por atendente, loja, categoria e Monitor SLA.</p>
  </a>

  <a href="inventario.php" class="dash-card card-inventario">
    <div class="card-icon"><i class="bi bi-pc-display"></i></div>
    <h5>Inventário</h5>
    <p>Monitore máquinas por loja — status online/offline e configurações.</p>
  </a>

  <!-- ── RECURSOS ── -->
  <div class="section-label"><i class="bi bi-collection-fill me-2"></i>Recursos</div>

  <a href="conhecimento.php" class="dash-card card-conhecimento">
    <div class="card-icon"><i class="bi bi-book-fill"></i></div>
    <h5>Área do Conhecimento</h5>
    <p>Acesse artigos, tutoriais e documentações da equipe de TI.</p>
  </a>

  <a href="cofre.php" class="dash-card card-cofre">
    <div class="card-icon"><i class="bi bi-safe2-fill"></i></div>
    <h5>Cofre TI</h5>
    <p>Senhas, comandos e documentação interna da equipe — seguro e com busca rápida.</p>
  </a>

  <!-- ── ACESSOS ── -->
  <div class="section-label"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Acessos</div>

  <a href="acessos.php#remoto" class="dash-card card-acesso-remoto">
    <div class="card-icon"><i class="bi bi-display-fill"></i></div>
    <h5>Acesso Remoto</h5>
    <p>Remote Desktop (RDP), VNC e AnyDesk para suporte e acesso a servidores.</p>
  </a>

  <a href="acessos.php#infra" class="dash-card card-infra">
    <div class="card-icon"><i class="bi bi-hdd-network-fill"></i></div>
    <h5>Infraestrutura</h5>
    <p>pfSense, VMware, Mikrotik e UniFi — gerenciamento da rede e servidores.</p>
  </a>

  <a href="acessos.php#erp" class="dash-card card-erp">
    <div class="card-icon"><i class="bi bi-building-fill-gear"></i></div>
    <h5>Ferramentas ERP</h5>
    <p>Sistemas de gestão e ferramentas corporativas da empresa.</p>
  </a>

  <!-- ── GESTÃO DE TI ── -->
  <div class="section-label"><i class="bi bi-briefcase me-2"></i>Gestão de TI</div>

  <a href="projetos.php" class="dash-card card-projetos">
    <div class="card-icon"><i class="bi bi-kanban-fill"></i></div>
    <h5>Projetos</h5>
    <p>Gerencie projetos de TI — implantações, migrações e upgrades com prazo e progresso.</p>
  </a>

  <a href="equipe.php" class="dash-card card-equipe">
    <div class="card-icon"><i class="bi bi-people-fill"></i></div>
    <h5>Equipe</h5>
    <p>Visão da equipe: carga de chamados por técnico, disponibilidade e desempenho.</p>
  </a>

  <a href="orcamento.php" class="dash-card card-orcamento">
    <div class="card-icon"><i class="bi bi-cash-coin"></i></div>
    <h5>Orçamento</h5>
    <p>Controle de gastos de TI por categoria — planejado vs realizado por período.</p>
  </a>

  <a href="contratos.php" class="dash-card card-contratos">
    <div class="card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
    <h5>Contratos</h5>
    <p>Contratos ativos de TI — manutenção, ISP, licenças — com alertas de vencimento.</p>
  </a>

  <a href="licencas.php" class="dash-card card-licencas">
    <div class="card-icon"><i class="bi bi-key-fill"></i></div>
    <h5>Licenças de Software</h5>
    <p>Controle de licenças: quantidade usada vs disponível, vencimentos e compliance.</p>
  </a>

<?php endif; ?>

</div>

<script src="assets/notificacoes.js"></script>
<footer>
  <i class="bi bi-shield-lock me-1"></i>Central de TI — Integrado com GLPI
</footer>

</body>
</html>
