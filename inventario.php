<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#0097a7; }
    * { box-sizing:border-box; }
    body {
      background:#f0f4f9;
      font-family:'Segoe UI',sans-serif;
      min-height:100vh;
      margin:0;
    }

    .topbar {
      background:#1a237e;
      color:#fff;
      padding:.75rem 1.5rem;
      display:flex;
      align-items:center;
      gap:1rem;
      box-shadow:0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight:700; font-size:1.05rem; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a {
      color:rgba(255,255,255,.8);
      text-decoration:none;
      font-size:.85rem;
      display:flex;
      align-items:center;
      gap:.35rem;
      padding:.3rem .7rem;
      border-radius:6px;
      transition:.15s;
    }
    .topbar a:hover { background:rgba(255,255,255,.15); color:#fff; }

    .hero {
      text-align:center;
      padding:2.5rem 1rem 1.5rem;
    }
    .hero h1 { font-size:1.6rem; font-weight:800; color:#1a237e; margin:0; }
    .hero p  { color:#5f6368; margin:.25rem 0 0; font-size:.9rem; }

    .cat-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
      gap:1.25rem;
      max-width:900px;
      margin:1rem auto 3rem;
      padding:0 1.5rem;
    }

    .cat-card {
      background:#fff;
      border-radius:12px;
      padding:1.75rem 1.25rem;
      text-align:center;
      text-decoration:none;
      color:#202124;
      box-shadow:0 1px 4px rgba(0,0,0,.08);
      transition:.2s;
      border-top:4px solid var(--accent);
      position:relative;
      overflow:hidden;
    }
    .cat-card:hover {
      box-shadow:0 4px 16px rgba(0,0,0,.15);
      transform:translateY(-3px);
    }
    .cat-card .cat-icon {
      width:60px; height:60px; border-radius:14px;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto .85rem;
      font-size:1.6rem;
    }
    .cat-card h3 { font-size:1rem; font-weight:700; margin:0 0 .25rem; }
    .cat-card p  { font-size:.8rem; color:#5f6368; margin:0; }

    .cat-card.disabled {
      opacity:.45;
      cursor:default;
      pointer-events:none;
      filter:grayscale(1);
    }
    .cat-card .badge-embreve {
      position:absolute;
      top:8px; right:8px;
      background:#e0e0e0;
      color:#616161;
      font-size:.65rem;
      font-weight:700;
      padding:2px 8px;
      border-radius:20px;
    }

    .pc-icon   { background:#e0f7fa; color:#0097a7; }
    .printer-icon { background:#fce4ec; color:#e91e63; }
    .net-icon  { background:#e8f5e9; color:#2e7d32; }
    .serv-icon { background:#fff3e0; color:#e65100; }
    .monitor-icon { background:#f3e5f5; color:#7b1fa2; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-box-seam me-1"></i> Inventário</div>
  <span class="spacer"></span>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-box-seam me-2"></i>Inventário</h1>
  <p>Selecione uma categoria para visualizar os ativos</p>
</div>

<div class="cat-grid">
  <a href="inventario_pcs.php" class="cat-card" style="border-top-color:#0097a7">
    <div class="cat-icon pc-icon"><i class="bi bi-pc-display"></i></div>
    <h3>Máquinas / PCs</h3>
    <p>Computadores, notebooks e estações de trabalho</p>
  </a>

  <div class="cat-card disabled">
    <div class="cat-icon printer-icon"><i class="bi bi-printer"></i></div>
    <h3>Impressoras</h3>
    <p>Impressoras, multifuncionais e scanners</p>
    <span class="badge-embreve">Em breve</span>
  </div>

  <div class="cat-card disabled">
    <div class="cat-icon net-icon"><i class="bi bi-hdd-stack"></i></div>
    <h3>Servidores</h3>
    <p>Servidores físicos e virtuais</p>
    <span class="badge-embreve">Em breve</span>
  </div>

  <div class="cat-card disabled">
    <div class="cat-icon net-icon"><i class="bi bi-diagram-3"></i></div>
    <h3>Redes</h3>
    <p>Switches, roteadores, access points</p>
    <span class="badge-embreve">Em breve</span>
  </div>

  <div class="cat-card disabled">
    <div class="cat-icon monitor-icon"><i class="bi bi-phone"></i></div>
    <h3>Dispositivos Móveis</h3>
    <p>Celulares, tablets e coletores</p>
    <span class="badge-embreve">Em breve</span>
  </div>

  <div class="cat-card disabled">
    <div class="cat-icon monitor-icon"><i class="bi bi-tv"></i></div>
    <h3>Monitores</h3>
    <p>Monitores, TVs e projetores</p>
    <span class="badge-embreve">Em breve</span>
  </div>
</div>

</body>
</html>
