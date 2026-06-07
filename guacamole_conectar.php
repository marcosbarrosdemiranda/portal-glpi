<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: rdp_central.php'); exit; }

$st = $pdo->prepare("SELECT nome, guac_id, ip FROM portal_rdp_maquinas WHERE id=? AND ativo=1 AND guac_id IS NOT NULL");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "<script>alert('Máquina sem Guacamole');location.href='rdp_central.php';</script>"; exit; }

$guac_url = rtrim(GUACAMOLE_URL, '/');
$conn_id = (int)$row['guac_id'];

// Login na API
$ch = curl_init($guac_url . '/api/tokens');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => GUACAMOLE_USER, 'password' => GUACAMOLE_PASS]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

$token = '';
if ($info['http_code'] === 200 && $resp) {
    $auth = json_decode($resp, true);
    $token = $auth['authToken'] ?? '';
}

$clientId = base64_encode($conn_id . "\0" . 'c' . "\0" . 'mysql');
if ($token) {
    $guacIframeUrl = $guac_url . '/?token=' . urlencode($token) . '#/client/' . $clientId;
} else {
    $guacIframeUrl = $guac_url . '/#/client/' . $clientId;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($row['nome']) ?> — Guacamole</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "Segoe UI", system-ui, sans-serif; background: #000; display: flex; flex-direction: column; height: 100vh; }

/* ── Top Bar (mesmo estilo do pfSense proxy) ── */
.guac-topbar {
  background: linear-gradient(135deg, #1e3a8a, #0f172a);
  color: white;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 1.25rem;
  flex-shrink: 0;
  box-shadow: 0 2px 10px rgba(0,0,0,.4);
  z-index: 100;
}
.guac-topbar .left { display: flex; align-items: center; gap: 0.75rem; }
.guac-topbar .right { display: flex; align-items: center; gap: 0.5rem; }
.guac-topbar a, .guac-topbar button {
  background: rgba(255,255,255,.12); border: none; color: white;
  border-radius: 6px; padding: 0.35rem 0.85rem; font-size: 0.85rem;
  cursor: pointer; transition: all .12s; text-decoration: none;
  display: inline-flex; align-items: center; gap: .35rem;
  font-family: inherit; white-space: nowrap; font-weight: 500;
}
.guac-topbar a:hover, .guac-topbar button:hover { background: rgba(255,255,255,.25); }
.guac-topbar .maq-nome { font-weight: 700; font-size: 1rem; }
.guac-topbar .maq-ip { font-size: .8rem; color: #93c5fd; font-family: Consolas, monospace; }

/* ── Iframe ── */
iframe {
  flex: 1;
  width: 100%;
  border: none;
  background: #fff;
}
</style>
</head>
<body>

<div class="guac-topbar">
  <div class="left">
    <a href="rdp_central.php" onclick="event.preventDefault();window.close()">← Fechar</a>
    <span class="guac-sep" style="color:#6b7280;font-size:.7rem;">|</span>
    <span class="maq-nome"><i class="bi bi-display-fill"></i> <?= htmlspecialchars($row['nome']) ?></span>
    <span class="maq-ip"><?= htmlspecialchars($row['ip']) ?></span>
  </div>
  <div class="right">
    <a href="rdp_central.php">← Central RDP</a>
    <a href="<?= htmlspecialchars($guac_url) ?>" target="_blank">Abrir Guacamole</a>
  </div>
</div>

<iframe id="guac-frame" src="<?= htmlspecialchars($guacIframeUrl) ?>" allowfullscreen></iframe>

</body>
</html>
