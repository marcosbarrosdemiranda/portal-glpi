<?php
/**
 * rdp_launch.php — dispara a Área de Trabalho Remota (gmaisrdp://). Abre numa janelinha:
 * se o handler estiver OK, fecha sozinha; se não, mostra o atalho de configuração.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

if (!defined('VAULT_KEY')) define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
function rdp_dec(string $data): string {
    $raw = base64_decode($data);
    if (strlen($raw) < 17) return '';
    return openssl_decrypt(substr($raw, 16), 'aes-256-cbc', VAULT_KEY, 0, substr($raw, 0, 16)) ?: '';
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT nome, ip, usuario, senha FROM portal_rdp_maquinas WHERE id = ? AND ativo = 1");
$st->execute([$id]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); exit('Máquina não encontrada.'); }

$ip    = trim($m['ip']);
$user  = trim($m['usuario'] ?? '');
$senha = $m['senha'] ? rdp_dec($m['senha']) : '';
$US      = "\x1f";
$payload = rtrim(strtr(base64_encode($ip . $US . $user . $US . $senha), '+/', '-_'), '=');
$uri     = 'gmaisrdp:' . $payload;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Abrindo Área de Trabalho…</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f0f4f9; color:#202124; margin:0; display:flex; min-height:100vh; align-items:center; justify-content:center; }
  .box { text-align:center; padding:1.5rem; max-width:360px; }
  .spin { width:34px; height:34px; border:3px solid #d5dae2; border-top-color:#1d4ed8; border-radius:50%; animation:r .8s linear infinite; margin:0 auto .8rem; }
  @keyframes r { to { transform:rotate(360deg); } }
  h1 { font-size:1rem; margin:.2rem 0; }
  .ip { font-family:Consolas,monospace; color:#5f6368; font-size:.82rem; }
  #fail { display:none; }
  #fail .ic { font-size:2rem; color:#e8a33d; }
  #fail h1 { color:#7a5b00; margin:.5rem 0 .3rem; }
  #fail p { font-size:.83rem; color:#5f6368; margin:.3rem 0 1rem; }
  .btn { display:inline-block; margin:.25rem; padding:.5rem 1.1rem; border-radius:9px; font-size:.85rem; text-decoration:none; }
  .btn-a { background:#1d4ed8; color:#fff; }
  .btn-b { background:#e9edf2; color:#3c4043; }
  .retry { font-size:.8rem; color:#1a73e8; display:block; margin-top:.6rem; cursor:pointer; }
</style>
</head>
<body>
<div class="box">
  <div class="spin"></div>
  <h1>Abrindo <?= $h($m['nome']) ?>…</h1>
  <div class="ip"><?= $h($ip) ?><?= $user ? ' — ' . $h($user) : '' ?></div>
  <a class="btn btn-a" id="go" href="<?= $h($uri) ?>" style="margin-top:.9rem">Abrir Área de Trabalho</a>
  <div id="ajuda" style="display:none;margin-top:1rem;font-size:.8rem;color:#5f6368">
    Não abriu? <a href="vnc_setup.php" target="_blank">Configurar este PC</a> ·
    <a href="rdp_central.php">Central RDP</a>
  </div>
</div>
<script>
  var URI = <?= json_encode($uri) ?>;
  var abriu = false;
  window.addEventListener('blur', function(){ abriu = true; });
  document.addEventListener('visibilitychange', function(){ if (document.hidden) abriu = true; });

  setTimeout(function(){ location.href = URI; }, 300);

  setTimeout(function(){
    if (abriu) {
      if (window.history.length > 1) window.history.back();
      else location.replace('rdp_central.php');
    } else {
      document.getElementById('ajuda').style.display = 'block';
    }
  }, 4000);
</script>
</body>
</html>
