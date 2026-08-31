<?php
/**
 * vnc_launch.php — dispara o VNC Viewer nativo do PC da TI via protocolo gmaisvnc://
 * Ver util/LEIA-ME-VNC.txt para instalar o handler nos PCs.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';

// mesma criptografia do vnc_central.php / cofre
if (!defined('VAULT_KEY')) define('VAULT_KEY', hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais'));
function vnc_decrypt(string $data): string {
    $raw = base64_decode($data);
    if (strlen($raw) < 17) return '';
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', VAULT_KEY, 0, $iv) ?: '';
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT nome, ip, senha FROM portal_rdp_maquinas WHERE id = ? AND protocolo = 'vnc' AND ativo = 1");
$st->execute([$id]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); exit('Máquina VNC não encontrada.'); }

$ip    = trim($m['ip']);
$porta = '5900';
$senha = $m['senha'] ? vnc_decrypt($m['senha']) : '';

// payload: ip <US> porta <US> senha  → base64 → urlencode
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
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f0f4f9; color:#202124; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
  .box { background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.12); padding:2rem 2.25rem; max-width:440px; text-align:center; }
  .box i.big { font-size:2.5rem; color:#059669; }
  h1 { font-size:1.15rem; margin:.6rem 0 .2rem; }
  .ip { font-family:Consolas,monospace; color:#5f6368; font-size:.9rem; }
  .btn { display:inline-block; margin-top:1.2rem; background:#059669; color:#fff; text-decoration:none; border-radius:9px; padding:.6rem 1.4rem; font-size:.9rem; }
  .btn:hover { background:#047857; }
  .hint { margin-top:1.2rem; font-size:.8rem; color:#80868b; line-height:1.5; }
  .hint code { background:#f1f3f4; padding:.1rem .35rem; border-radius:4px; }
  a.volta { display:block; margin-top:1rem; font-size:.82rem; color:#1a73e8; }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
</head>
<body>
<div class="box">
  <i class="bi bi-display-fill big"></i>
  <h1>Abrindo <?= $h($m['nome']) ?></h1>
  <div class="ip"><?= $h($ip) ?>:<?= $porta ?></div>
  <a class="btn" href="<?= $h($uri) ?>" id="go"><i class="bi bi-box-arrow-up-right"></i> Abrir VNC Viewer</a>
  <div class="hint">
    Se nada abriu, o handler <code>gmaisvnc://</code> ainda não está instalado neste PC.<br>
    Rode uma vez: <code>util\LEIA-ME-VNC.txt</code>
  </div>
  <a class="volta" href="vnc_central.php">← Voltar à Central VNC</a>
</div>
<script>
  // tenta abrir automaticamente
  setTimeout(function () { window.location.href = document.getElementById('go').href; }, 250);
</script>
</body>
</html>
