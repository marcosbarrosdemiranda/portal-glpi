<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Área do Conhecimento</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }
    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .hero {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:2rem 1rem 5rem; text-align:center;
    }
    .hero h1 { font-size:1.5rem; font-weight:700; }

    .wrap { max-width:600px; margin:-3rem auto 3rem; padding:0 1rem; }

    .em-construcao {
      background:white; border-radius:16px;
      box-shadow:0 4px 24px rgba(0,0,0,.1);
      padding:3rem 2rem; text-align:center;
    }
    .em-construcao .icon {
      font-size:5rem; color:#f57c00; margin-bottom:1rem;
      animation: pulse 2s infinite;
    }
    @keyframes pulse {
      0%,100% { transform: scale(1); }
      50%      { transform: scale(1.08); }
    }
    .em-construcao h2 { font-weight:700; color:#1a237e; margin-bottom:.5rem; }
    .em-construcao p  { color:#6b7280; font-size:.95rem; }
    .em-construcao .badge-wip {
      display:inline-block; background:#fff3e0; color:#f57c00;
      border:2px solid #f57c00; border-radius:20px;
      padding:.4rem 1.2rem; font-weight:700; font-size:.85rem;
      margin-bottom:1.5rem; letter-spacing:.05em;
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-book-fill"></i> Área do Conhecimento</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-book-fill me-2"></i>Área do Conhecimento</h1>
  <p style="opacity:.8">Documentações, tutoriais e artigos da equipe de TI</p>
</div>

<div class="wrap">
  <div class="em-construcao">
    <div class="icon"><i class="bi bi-tools"></i></div>
    <div class="badge-wip"><i class="bi bi-cone-striped me-1"></i>Em Construção</div>
    <h2>Estamos preparando algo incrível!</h2>
    <p>A Área do Conhecimento está sendo desenvolvida.<br/>Em breve você poderá acessar artigos, tutoriais e documentações da equipe de TI.</p>
  </div>
</div>

</body>
</html>
