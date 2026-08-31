<?php
/**
 * vnc_launch.php — dispara o VNC Viewer nativo via protocolo gmaisvnc://
 * Handler instalado 1x por GPO (util/gmais-vnc-setup.ps1). Sem tocar PC a PC.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

if (!defined('VAULT_KEY')) define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
function vnc_decrypt(string $data): string {
    $raw = base64_decode($data);
    if (strlen($raw) < 17) return '';
    return openssl_decrypt(substr($raw, 16), 'aes-256-cbc', VAULT_KEY, 0, substr($raw, 0, 16)) ?: '';
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT nome, ip, senha FROM portal_rdp_maquinas WHERE id = ? AND protocolo = 'vnc' AND ativo = 1");
$st->execute([$id]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); exit('Máquina VNC não encontrada.'); }

$ip    = trim($m['ip']);
$porta = '5900';
$senha = $m['senha'] ? vnc_decrypt($m['senha']) : '';

$US      = "\x1f";
$payload = rawurlencode(base64_encode($ip . $US . $porta . $US . $senha));
$uri     = 'gmaisvnc://' . $payload;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Abrindo VNC — <?= $h($m['nome']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f0f4f9; color:#202124; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
  .box { background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.12); padding:2rem 2.25rem; max-width:420px; text-align:center; }
  .box i.big { font-size:2.4rem; color:#059669; }
  h1 { font-size:1.1rem; margin:.6rem 0 .2rem; }
  .ip { font-family:Consolas,monospace; color:#5f6368; font-size:.9rem; }
  .btn { display:inline-block; margin-top:1.1rem; background:#059669; color:#fff; text-decoration:none; border-radius:9px; padding:.55rem 1.3rem; font-size:.9rem; }
  .hint { margin-top:1rem; font-size:.78rem; color:#80868b; line-height:1.5; }
  a.volta { display:block; margin-top:.9rem; font-size:.8rem; color:#1a73e8; }
</style>
</head>
<body>
<div class="box">
  <i class="bi bi-display-fill big"></i>
  <h1>Abrindo <?= $h($m['nome']) ?></h1>
  <div class="ip"><?= $h($ip) ?>:<?= $porta ?></div>
  <a class="btn" href="<?= $h($uri) ?>" id="go"><i class="bi bi-box-arrow-up-right"></i> Abrir VNC Viewer</a>
  <div class="hint">Não abriu? Este PC ainda não foi configurado — <a href="vnc_setup.php">clique aqui para configurar</a> (1 vez só).</div>
  <a class="volta" href="vnc_central.php">← Central VNC</a>
</div>
<script>
  setTimeout(function(){ window.location.href = document.getElementById('go').href; }, 200);
  setTimeout(function(){ window.close(); }, 1500);
</script>
</body>
</html>
