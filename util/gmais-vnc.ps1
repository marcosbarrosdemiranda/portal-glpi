# gmais-vnc.ps1 — handler do protocolo gmaisvnc:// (Central VNC do portal)
# Instalado via util\gmaisvnc.reg. Ver util\LEIA-ME-VNC.txt
param([string]$Uri)

$ErrorActionPreference = 'Stop'
$Viewer = 'C:\Util\VNCViewer.exe'
$Style  = 'tightvnc'          # tightvnc | ultravnc
$Log    = 'C:\Util\gmais-vnc.log'

function Log($m) { "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m | Out-File -Append -Encoding utf8 $Log }

try {
    if (-not (Test-Path $Viewer)) { throw "VNC Viewer nao encontrado em $Viewer" }

    $tok = $Uri -replace '^gmaisvnc:/*', ''
    $tok = [Uri]::UnescapeDataString($tok)
    $raw = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($tok))
    $p   = $raw -split ([char]0x1F)

    $ip   = $p[0]
    $port = if ($p.Count -ge 2 -and $p[1]) { $p[1] } else { '5900' }
    $pass = if ($p.Count -ge 3) { $p[2] } else { '' }
    if (-not $ip) { throw "IP vazio no link" }
    Log "conectar $ip`:$port  style=$Style  senha=$([bool]$pass)"

    if ($Style -eq 'ultravnc') {
        $args = @('-connect', "$ip`:$port")
        if ($pass) { $args += @('-password', $pass) }
    } else {
        # TightVNC Viewer
        $args = @("$ip`::$port")
        if ($pass) { $args += "-password=$pass" }
    }

    Start-Process -FilePath $Viewer -ArgumentList $args
}
catch {
    Log "ERRO: $_"
    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show("Nao consegui abrir o VNC:`n$_", "Gmais VNC", 'OK', 'Error') | Out-Null
    } catch {}
}
