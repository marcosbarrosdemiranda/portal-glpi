<?php
/**
 * util_get.php — serve os arquivos de util/ que o Apache do GLPI bloqueia por extensão
 * (.ps1, .exe). Sem auth: são ferramentas públicas (VNC viewer + launcher).
 */
$allow = [
    'gmais-vnc.ps1'       => 'text/plain; charset=utf-8',
    'gmais-vnc-setup.ps1' => 'text/plain; charset=utf-8',
    'VNCViewer.exe'       => 'application/octet-stream',
];
$f = basename($_GET['f'] ?? '');
if (!isset($allow[$f])) { http_response_code(404); exit('nao encontrado'); }

$path = __DIR__ . '/util/' . $f;
if (!is_file($path)) { http_response_code(404); exit('nao encontrado'); }

clearstatcache(true, $path);
header('Content-Type: ' . $allow[$f]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $f . '"');
header('Cache-Control: no-store, must-revalidate');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
