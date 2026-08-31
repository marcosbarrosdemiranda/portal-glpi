# gmais-vnc.ps1 — handler do protocolo gmaisvnc:// (fica em C:\Util)
param([string]$Uri)

$ErrorActionPreference = 'Stop'
$Viewer = 'C:\Util\VNCViewer.exe'
$Style  = 'ultravnc'          # ultravnc | tightvnc
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
    if (-not $ip) { throw "IP vazio" }
    Log "conectar $ip`:$port style=$Style"

    if ($Style -eq 'tightvnc') {
        $a = @("$ip`::$port"); if ($pass) { $a += "-password=$pass" }
    } else {
        $a = @('-connect', "$ip`:$port"); if ($pass) { $a += @('-password', $pass) }
    }
    Start-Process -FilePath $Viewer -ArgumentList $a
}
catch {
    Log "ERRO: $_"
    try { Add-Type -AssemblyName System.Windows.Forms; [Windows.Forms.MessageBox]::Show("VNC: $_","Gmais VNC") | Out-Null } catch {}
}
