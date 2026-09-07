<?php
/**
 * Hub de Notificações (Fase 1)
 * Página central da seção "Notificações" da Configuração do portal.
 * Por enquanto só reúne o acesso à Configuração do WhatsApp — sem envio
 * automático de nada nesta fase.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

// ── Checagem defensiva de permissão de perfil ──────────────────────────────
// Além do card do dashboard: se o usuário tem perfil restrito e não possui
// a permissão 'notificacoes_config', redireciona pro dashboard.
require_once __DIR__ . '/agenda/db.php';
$uid   = (int)($_SESSION['user_id'] ?? 0);
$cards = null;
try {
    $st = $pdo->prepare("
        SELECT pp.cards FROM portal_perfil_usuarios pu
        JOIN portal_perfis pp ON pp.id = pu.perfil_id
        WHERE pu.user_id = ?
    ");
    $st->execute([$uid]);
    $row = $st->fetch();
    if ($row) $cards = json_decode($row['cards'] ?? '{}', true) ?: [];
} catch (Exception $e) { /* tabelas ainda não existem — ignora */ }
if ($cards !== null && !isset($cards['notificacoes_config'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Notificações</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15);
                border-radius:6px; padding:.3rem .75rem; margin-left:.4rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p { opacity:.8; margin-top:.5rem; font-size:.95rem; }
    .wrap { max-width:900px; margin:-3rem auto 3rem; padding:0 1.5rem; }

    /* ── Cards (mesmo padrão do dashboard.php) ── */
    .dash-grid {
      display:grid;
      grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));
      gap:1.25rem;
    }
    .dash-card {
      background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.1);
      padding:2rem 1.5rem; text-align:center; text-decoration:none; color:#333;
      transition:transform .2s, box-shadow .2s; cursor:pointer; border-top:5px solid transparent;
    }
    .dash-card:hover { transform:translateY(-5px); box-shadow:0 10px 32px rgba(0,0,0,.15); color:#333; }
    .dash-card .card-icon {
      width:70px; height:70px; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 1.25rem; font-size:2rem;
    }
    .dash-card h5 { font-weight:700; font-size:1.1rem; margin:0 0 .4rem; }
    .dash-card p  { font-size:.85rem; color:#888; margin:0; }
    .card-whatsapp { border-top-color:#25d366; }
    .card-whatsapp .card-icon { background:#e7f9ef; color:#128c7e; }

    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-bell-fill"></i> Notificações</div>
  <div>
    <a href="dashboard.php"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-bell-fill me-2"></i>Notificações</h1>
  <p>Canais de notificação do portal</p>
</div>

<div class="wrap">
  <div class="dash-grid">
    <a href="config_whatsapp.php" class="dash-card card-whatsapp">
      <div class="card-icon"><i class="bi bi-whatsapp"></i></div>
      <h5>WhatsApp</h5>
      <p>Conexão da linha do TI e grupos de Alertas e Chamados.</p>
    </a>
  </div>
</div>

<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Notificações (Fase 1)</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
