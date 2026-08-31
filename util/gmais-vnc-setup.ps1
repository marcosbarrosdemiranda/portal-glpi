# gmais-vnc-setup.ps1
# Script de LOGON de GPO (contexto do usuario, NAO precisa admin).
# Roda todo login, mas so faz algo quando falta: idempotente e silencioso.
#
# Configura no PC os botoes "Abrir no VNC" (Central VNC) e
# "Abrir Area de Trabalho" (Central RDP) do portal:
#   - C:\Util\VNCViewer.exe            (baixa do portal se faltar)
#   - C:\Util\gmais-vnc.ps1 / .vbs     (sempre atualiza)
#   - C:\Util\gmais-rdp.ps1 / .vbs     (sempre atualiza)
#   - protocolos gmaisvnc:// e gmaisrdp:// no HKCU
#
# Configurar 1x:  GPO -> Config. do Usuario -> Politicas -> Config. do Windows
#                 -> Scripts (Logon) -> Adicionar -> PowerShell -> este arquivo.

$ErrorActionPreference = 'SilentlyContinue'
$base = 'https://ti.grupogmais.com:7412/glpi2/portal-glpi/util_get.php?f='
$dir  = 'C:\Util'

if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
[Net.ServicePointManager]::SecurityProtocol = 'Tls12'

function Baixa($url, $dest) {
    try { Invoke-WebRequest $url -OutFile $dest -UseBasicParsing -TimeoutSec 30 } catch {}
}

function Registra-Protocolo($scheme, $vbs, $label) {
    $key = "HKCU:\Software\Classes\$scheme"
    $cmd = '"' + (Join-Path $env:WINDIR 'System32\wscript.exe') + "`" `"C:\Util\$vbs`" `"%1`""
    $atual = (Get-ItemProperty "$key\shell\open\command" -Name '(default)' -ErrorAction SilentlyContinue).'(default)'
    if ($atual -ne $cmd) {
        New-Item -Path "$key\shell\open\command" -Force | Out-Null
        Set-ItemProperty -Path $key -Name '(default)' -Value $label
        Set-ItemProperty -Path $key -Name 'URL Protocol' -Value ''
        Set-ItemProperty -Path "$key\shell\open\command" -Name '(default)' -Value $cmd
    }
}

# VNC viewer (5 MB — so baixa se faltar)
if (-not (Test-Path "$dir\VNCViewer.exe")) { Baixa "${base}VNCViewer.exe" "$dir\VNCViewer.exe" }

# handlers + launchers (pequenos — sempre atualiza)
Baixa "${base}gmais-vnc.ps1" "$dir\gmais-vnc.ps1"
Baixa "${base}gmais-vnc.vbs" "$dir\gmais-vnc.vbs"
Baixa "${base}gmais-rdp.ps1" "$dir\gmais-rdp.ps1"
Baixa "${base}gmais-rdp.vbs" "$dir\gmais-rdp.vbs"

Registra-Protocolo 'gmaisvnc' 'gmais-vnc.vbs' 'URL:Gmais VNC'
Registra-Protocolo 'gmaisrdp' 'gmais-rdp.vbs' 'URL:Gmais RDP'
