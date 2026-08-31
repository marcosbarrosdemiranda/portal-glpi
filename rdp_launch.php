<?php
/**
 * rdp_launch.php — abre a Área de Trabalho Remota (mstsc) do PC via protocolo gmaisrdp://
 * Handler instalado 1x (GPO ou "Configurar este PC"). Ver util/gmais-rdp.ps1
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Abrindo Área de Trabalho — <?= $h($m['nome']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f0f4f9; color:#202124; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
  .box { background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.12); padding:2rem 2.25rem; max-width:420px; text-align:center; }
  .box i.big { font-size:2.4rem; color:#1d4ed8; }
  h1 { font-size:1.1rem; margin:.6rem 0 .2rem; }
  .ip { font-family:Consolas,monospace; color:#5f6368; font-size:.9rem; }
  .btn { display:inline-block; margin-top:1.1rem; background:#1d4ed8; color:#fff; text-decoration:none; border-radius:9px; padding:.55rem 1.3rem; font-size:.9rem; }
  .hint { margin-top:1rem; font-size:.78rem; color:#80868b; line-height:1.5; }
  a.volta { display:block; margin-top:.9rem; font-size:.8rem; color:#1a73e8; }
</style>
</head>
<body>
<div class="box">
  <i class="bi bi-pc-display-horizontal big"></i>
  <h1>Abrindo <?= $h($m['nome']) ?></h1>
  <div class="ip"><?= $h($ip) ?><?= $user ? ' — ' . $h($user) : '' ?></div>
  <a class="btn" href="<?= $h($uri) ?>" id="go"><i class="bi bi-box-arrow-up-right"></i> Abrir Área de Trabalho</a>
  <div class="hint">Não abriu? Este PC ainda não foi configurado — <a href="vnc_setup.php">clique aqui para configurar</a> (1 vez só).</div>
  <a class="volta" href="rdp_central.php">← Central RDP</a>
</div>
<script>
  setTimeout(function(){ window.location.href = document.getElementById('go').href; }, 200);
  setTimeout(function(){ window.close(); }, 1500);
</script>
</body>
</html>
