<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
$nome    = $_SESSION['nome']    ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
require_once __DIR__ . '/agenda/config.php';

// Busca chamados do usuário via API checando Ticket_User
function get_meus_chamados(int $user_id): array {
    if (!$user_id) return [];

    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $r['session_token'] ?? '';
    if (!$token) return [];

    $h = ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN];

    // Busca todos os Ticket_User onde o usuário é requerente (type=1)
    $ch2 = curl_init(GLPI_URL . '/apirest.php/Ticket_User?searchText[users_id]='.$user_id.'&searchText[type]=1&range=0-200&order=DESC');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    $ticket_users = json_decode(curl_exec($ch2), true) ?? [];
    curl_close($ch2);

    $status_map = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
    $status_cor = [1=>'primary',2=>'info',3=>'warning',4=>'secondary',5=>'success',6=>'dark'];

    $chamados = [];

    if (!is_array($ticket_users) || isset($ticket_users['ERROR'])) {
        // Fallback: busca todos e filtra via Ticket_User individualmente
        $ch3 = curl_init(GLPI_URL . '/apirest.php/Ticket?range=0-100&order=DESC');
        curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
        $all_tickets = json_decode(curl_exec($ch3), true) ?? [];
        curl_close($ch3);

        foreach ((array)$all_tickets as $t) {
            if (!isset($t['id'])) continue;
            // Checa Ticket_User deste ticket
            $ch4 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$t['id'].'/Ticket_User');
            curl_setopt_array($ch4, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
            $tu = json_decode(curl_exec($ch4), true) ?? [];
            curl_close($ch4);
            foreach ((array)$tu as $u) {
                if (($u['users_id'] ?? 0) == $user_id && ($u['type'] ?? 0) == 1) {
                    $chamados[] = [
                        'id'     => $t['id'],
                        'titulo' => $t['name'] ?? 'Sem título',
                        'status' => $status_map[$t['status'] ?? 1] ?? 'Desconhecido',
                        'cor'    => $status_cor[$t['status'] ?? 1] ?? 'secondary',
                        'data'   => substr($t['date'] ?? '', 0, 10),
                        'tipo'   => ($t['type'] ?? 1) == 1 ? 'Incidente' : 'Requisição',
                    ];
                    break;
                }
            }
        }
    } else {
        // Busca cada ticket pelo ID encontrado no Ticket_User
        foreach ($ticket_users as $tu) {
            if (($tu['users_id'] ?? 0) != $user_id || ($tu['type'] ?? 0) != 1) continue;
            $tid = $tu['tickets_id'] ?? 0;
            if (!$tid) continue;

            $ch5 = curl_init(GLPI_URL . '/apirest.php/Ticket/'.$tid);
            curl_setopt_array($ch5, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
            $t = json_decode(curl_exec($ch5), true) ?? [];
            curl_close($ch5);

            if (!isset($t['id'])) continue;
            $chamados[] = [
                'id'     => $t['id'],
                'titulo' => $t['name'] ?? 'Sem título',
                'status' => $status_map[$t['status'] ?? 1] ?? 'Desconhecido',
                'cor'    => $status_cor[$t['status'] ?? 1] ?? 'secondary',
                'data'   => substr($t['date'] ?? '', 0, 10),
                'tipo'   => ($t['type'] ?? 1) == 1 ? 'Incidente' : 'Requisição',
            ];
        }
    }

    // Encerra sessão
    $ch6 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch6, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch6); curl_close($ch6);

    // Ordena por ID decrescente
    usort($chamados, fn($a, $b) => $b['id'] - $a['id']);
    return $chamados;
}

$chamados = get_meus_chamados($user_id);

// DEBUG: mostra no comentário HTML
if (empty($chamados) && $user_id) {
    // Log para debug
    $debug_log = "<!-- DEBUG: user_id=$user_id, chamados encontrados: " . count($chamados) . " -->";
} else {
    $debug_log = "<!-- Chamados: " . count($chamados) . " -->";
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
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:white; padding:.9rem 2rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; font-size:1.1rem; display:flex; align-items:center; gap:.5rem; }
    .btn-back { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); color:white;
                border-radius:8px; padding:.3rem .8rem; font-size:.82rem; text-decoration:none; }
    .btn-back:hover { background:rgba(255,255,255,.25); color:white; }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:white; padding:2rem 1rem 4rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; }
    .wrap { max-width:860px; margin:-2.5rem auto 3rem; padding:0 1rem; }
    .ticket-card { background:white; border-radius:12px; border:1px solid #e5e7eb;
                   box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1rem 1.25rem;
                   margin-bottom:.75rem; display:flex; align-items:center; gap:1rem;
                   cursor:pointer; transition:all .15s; text-decoration:none; color:inherit; }
    .ticket-card:hover { box-shadow:0 6px 16px rgba(0,0,0,.1); transform:translateY(-1px); }
    .ticket-id { font-size:.75rem; color:#9ca3af; min-width:50px; }
    .ticket-info { flex:1; }
    .ticket-titulo { font-weight:600; color:#111; margin:0; font-size:.95rem; }
    .ticket-meta { font-size:.78rem; color:#6b7280; margin-top:.15rem; }
    .empty { text-align:center; color:#9ca3af; padding:3rem; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-clock-history"></i> Meus Chamados</div>
  <a href="dashboard.php" class="btn-back"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-ticket-perforated me-2"></i>Meus Chamados</h1>
  <p style="opacity:.8">Acompanhe o status das suas solicitações</p>
</div>

<div class="wrap">
  <?php if (empty($chamados)): ?>
    <div class="empty"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum chamado encontrado.</div>
  <?php else: ?>
    <?php foreach ($chamados as $c): ?>
    <a href="chamado.php?id=<?= $c['id'] ?>" class="ticket-card">
      <div class="ticket-id">#<?= $c['id'] ?></div>
      <div class="ticket-info">
        <p class="ticket-titulo"><?= htmlspecialchars($c['titulo']) ?></p>
        <div class="ticket-meta">
          <i class="bi bi-calendar3 me-1"></i><?= $c['data'] ?>
          &nbsp;·&nbsp; <?= $c['tipo'] ?>
        </div>
      </div>
      <span class="badge bg-<?= $c['cor'] ?>"><?= $c['status'] ?></span>
    </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</body>
</html>
