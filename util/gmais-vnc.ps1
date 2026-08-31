# gmais-vnc.ps1 — handler do protocolo gmaisvnc:// (fica em C:\Util)
# Viewer: RealVNC Viewer 6 (VNCViewer.exe da RP Info).
param([string]$Uri)

$ErrorActionPreference = 'Stop'
$Viewer = 'C:\Util\VNCViewer.exe'
$Log    = 'C:\Util\gmais-vnc.log'

function Log($m) { "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m | Out-File -Append -Encoding utf8 $Log }

# Ofusca a senha VNC no formato padrao (DES, chave fixa com bits invertidos) — RealVNC lê via -PasswordFile
function New-VncPasswordFile([string]$plain) {
    $key = [byte[]](232,74,214,96,196,114,26,224)   # {23,82,107,6,35,78,88,7} com os bits de cada byte invertidos
    $data = New-Object byte[] 8
    $pb   = [Text.Encoding]::ASCII.GetBytes($plain)
    [Array]::Copy($pb, $data, [Math]::Min(8, $pb.Length))
    $des = [Security.Cryptography.DES]::Create()
    $des.Mode = [Security.Cryptography.CipherMode]::ECB
    $des.Padding = [Security.Cryptography.PaddingMode]::None
    $des.Key = $key
    $enc = $des.CreateEncryptor().TransformFinalBlock($data, 0, 8)
    $file = Join-Path $env:TEMP ('gv_' + [Guid]::NewGuid().ToString('N').Substring(0,8) + '.vncpwd')
    [IO.File]::WriteAllBytes($file, $enc)
    return $file
}

try {
    if (-not (Test-Path $Viewer)) { throw "VNC Viewer nao encontrado em $Viewer" }

    $tok = $Uri -replace '^gmaisvnc:/*', ''
    $tok = ($tok -replace '/$', '')
    $tok = [Uri]::UnescapeDataString($tok)
    # base64url -> base64
    $b = $tok.Replace('-', '+').Replace('_', '/')
    switch ($b.Length % 4) { 2 { $b += '==' } 3 { $b += '=' } }
    $raw = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($b))
    $p   = $raw -split ([char]0x1F)

    $ip   = $p[0]
    $port = if ($p.Count -ge 2 -and $p[1]) { $p[1] } else { '5900' }
    $pass = if ($p.Count -ge 3) { $p[2] } else { '' }
    if (-not $ip) { throw "IP vazio" }
    Log "conectar $ip`::$port  senha=$([bool]$pass)"

    $args = @("$ip`::$port", '-WarnUnencrypted=0', '-VerifyId=0', '-Encryption=PreferOff', '-AlwaysConnected=1')
    $pwFile = $null
    if ($pass) {
        $pwFile = New-VncPasswordFile $pass
        $args += @('-PasswordFile', $pwFile)
    }

    $proc = Start-Process -FilePath $Viewer -ArgumentList $args -PassThru
    if ($pwFile) {
        Start-Sleep -Seconds 8
        Remove-Item $pwFile -ErrorAction SilentlyContinue
    }
}
catch {
    Log "ERRO: $_"
    try { Add-Type -AssemblyName System.Windows.Forms; [Windows.Forms.MessageBox]::Show("VNC: $_","Gmais VNC") | Out-Null } catch {}
}
