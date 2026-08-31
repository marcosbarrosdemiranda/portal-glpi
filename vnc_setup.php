<?php
/**
 * vnc_setup.php — configura o "Abrir no VNC" neste PC (para máquinas fora do domínio).
 * ?get=cmd  -> baixa o configurador .cmd
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$base = 'https://' . ($_SERVER['HTTP_HOST'] ?: 'ti.grupogmais.com:7412')
      . preg_replace('~/[^/]*$~', '', $_SERVER['SCRIPT_NAME'] ?? '/glpi2/portal-glpi/vnc_setup.php');
$setupUrl = $base . '/util_get.php?f=gmais-vnc-setup.ps1';

$oneLiner = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"[Net.ServicePointManager]::SecurityProtocol='Tls12'; iwr '$setupUrl' -UseBasicParsing | iex\"";

if (($_GET['get'] ?? '') === 'cmd') {
    $cmd = "@echo off\r\n"
         . "title Configurar VNC - Gmais\r\n"
         . "echo Configurando o \"Abrir no VNC\" neste PC...\r\n"
         . "echo.\r\n"
         . $oneLiner . "\r\n"
         . "echo.\r\n"
         . "if errorlevel 1 ( echo FALHOU. Rode como Administrador ou chame o TI. ) else ( echo Pronto^! Ja pode usar o botao \"Abrir no VNC\" no portal. )\r\n"
         . "echo.\r\n"
         . "pause\r\n";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="configurar-vnc.cmd"');
    header('Content-Length: ' . strlen($cmd));
    echo $cmd;
    exit;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurar VNC neste PC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f0f4f9; color:#202124; margin:0; padding:2rem 1rem; }
  .box { background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.1); padding:2rem 2.25rem; max-width:640px; margin:0 auto; }
  h1 { font-size:1.3rem; color:#1a237e; margin:0 0 .3rem; }
  .lead { color:#5f6368; font-size:.9rem; margin-bottom:1.5rem; }
  h2 { font-size:1rem; margin:1.4rem 0 .5rem; }
  .step { display:flex; gap:.7rem; margin-bottom:.6rem; font-size:.9rem; }
  .step .n { background:#1a237e; color:#fff; border-radius:50%; width:22px; height:22px; display:flex; align-items:center; justify-content:center; font-size:.78rem; flex-shrink:0; }
  .btn { display:inline-block; background:#059669; color:#fff; text-decoration:none; border-radius:9px; padding:.6rem 1.4rem; font-size:.95rem; margin:.4rem 0; }
  .btn:hover { background:#047857; }
  code, .cli { font-family:Consolas,monospace; font-size:.82rem; background:#f1f3f4; border-radius:6px; padding:.15rem .4rem; }
  .cli { display:block; padding:.75rem .9rem; margin-top:.4rem; white-space:pre-wrap; word-break:break-all; border:1px solid #e0e4ea; }
  .alt { margin-top:1.5rem; padding-top:1.2rem; border-top:1px solid #eef1f5; }
  .hint { font-size:.8rem; color:#80868b; margin-top:.4rem; }
  a.volta { display:inline-block; margin-top:1.5rem; font-size:.85rem; color:#1a73e8; }
</style>
</head>
<body>
<div class="box">
  <h1><i class="bi bi-gear-fill"></i> Configurar "Abrir no VNC" neste PC</h1>
  <p class="lead">Só precisa fazer <strong>uma vez por PC que não está no domínio</strong>. Depois, o botão "Abrir no VNC" da Central VNC abre o viewer direto, em 1 clique.</p>

  <h2>Jeito fácil</h2>
  <div class="step"><span class="n">1</span><div>Clique em <strong>Baixar configurador</strong>.</div></div>
  <div class="step"><span class="n">2</span><div>Abra o arquivo <code>configurar-vnc.cmd</code> baixado (duplo-clique). Se o Windows avisar, "Mais informações" → "Executar assim mesmo".</div></div>
  <div class="step"><span class="n">3</span><div>Esperar "Pronto!" e fechar. Testar na Central VNC.</div></div>
  <a class="btn" href="vnc_setup.php?get=cmd"><i class="bi bi-download"></i> Baixar configurador</a>

  <div class="alt">
    <h2>Ou: colar no PowerShell (admin)</h2>
    <p class="hint">Abra o PowerShell e cole:</p>
    <code class="cli" id="cli"><?= $h($oneLiner) ?></code>
    <button class="btn" style="background:#455a64;border:none;cursor:pointer;font-size:.85rem;padding:.4rem 1rem" onclick="navigator.clipboard.writeText(document.getElementById('cli').innerText);this.textContent='Copiado!'">Copiar</button>
  </div>

  <div class="alt">
    <p class="hint"><strong>O que isso faz:</strong> cria <code>C:\Util</code>, baixa o <code>VNCViewer.exe</code> e o handler, e registra o protocolo <code>gmaisvnc://</code> no seu perfil. Nada de admin permanente, nada que atrapalhe o PC.</p>
  </div>

  <a class="volta" href="vnc_central.php">← Voltar à Central VNC</a>
</div>
</body>
</html>
