# gmais-vnc-setup.ps1
# Script de LOGON de GPO (contexto do usuario, NAO precisa admin).
# Roda todo login, mas so faz algo quando falta: idempotente e silencioso.
#
# O que garante em cada PC:
#   1. C:\Util\VNCViewer.exe          (baixa do portal se faltar)
#   2. C:\Util\gmais-vnc.ps1          (baixa do portal se faltar/desatualizado)
#   3. protocolo gmaisvnc:// no HKCU  (registra se faltar)
#
# Configurar 1x:  GPO -> Config. do Usuario -> Politicas -> Config. do Windows
#                 -> Scripts (Logon) -> Adicionar -> PowerShell -> este arquivo.

$ErrorActionPreference = 'SilentlyContinue'
$base   = 'https://ti.grupogmais.com:7412/glpi2/portal-glpi/util_get.php?f='
$dir    = 'C:\Util'
$viewer = "$dir\VNCViewer.exe"
$psh    = "$dir\gmais-vnc.ps1"

if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
[Net.ServicePointManager]::SecurityProtocol = 'Tls12'

function Baixa($url, $dest) {
    try { Invoke-WebRequest $url -OutFile $dest -UseBasicParsing -TimeoutSec 30 } catch {}
}

# 1) viewer (5 MB — só baixa se faltar)
if (-not (Test-Path $viewer)) { Baixa "${base}VNCViewer.exe" $viewer }

# 2) handler — sempre baixa (arquivo pequeno, garante versão atual)
Baixa "${base}gmais-vnc.ps1" $psh

# 3) protocolo gmaisvnc:// no perfil do usuario
$key = 'HKCU:\Software\Classes\gmaisvnc'
$cmd = '"' + (Join-Path $PSHOME 'powershell.exe') + '" -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\Util\gmais-vnc.ps1" "%1"'
$atual = (Get-ItemProperty "$key\shell\open\command" -Name '(default)' -ErrorAction SilentlyContinue).'(default)'
if ($atual -ne $cmd) {
    New-Item -Path "$key\shell\open\command" -Force | Out-Null
    Set-ItemProperty -Path $key -Name '(default)' -Value 'URL:Gmais VNC'
    Set-ItemProperty -Path $key -Name 'URL Protocol' -Value ''
    Set-ItemProperty -Path "$key\shell\open\command" -Name '(default)' -Value $cmd
}
