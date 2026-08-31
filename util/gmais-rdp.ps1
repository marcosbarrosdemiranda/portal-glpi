# gmais-rdp.ps1 — handler do protocolo gmaisrdp:// (fica em C:\Util)
# Abre a Área de Trabalho Remota (mstsc) ja autenticada.
param([string]$Uri)

$ErrorActionPreference = 'Stop'
$Log = 'C:\Util\gmais-rdp.log'
function Log($m) { "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m | Out-File -Append -Encoding utf8 $Log }

try {
    $tok = $Uri -replace '^gmaisrdp:/*', ''
    $tok = ($tok -replace '/$', '')
    $tok = [Uri]::UnescapeDataString($tok)
    $b = $tok.Replace('-', '+').Replace('_', '/')
    switch ($b.Length % 4) { 2 { $b += '==' } 3 { $b += '=' } }
    $raw = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($b))
    $p = $raw -split ([char]0x1F)

    $ip   = $p[0]
    $user = if ($p.Count -ge 2) { $p[1] } else { '' }
    $pass = if ($p.Count -ge 3) { $p[2] } else { '' }
    if (-not $ip) { throw "IP vazio" }
    Log "conectar $ip  user=$user  senha=$([bool]$pass)"

    $target = "TERMSRV/$ip"
    if ($user -and $pass) {
        cmdkey /delete:$target 2>&1 | Out-Null
        cmdkey /generic:$target /user:$user /pass:$pass 2>&1 | Out-Null
    }

    $proc = Start-Process -FilePath 'mstsc.exe' -ArgumentList "/v:$ip" -PassThru

    if ($user -and $pass) {
        # espera a sessao fechar (ou 4h) e limpa a credencial
        try { $proc.WaitForExit(4 * 60 * 60 * 1000) | Out-Null } catch {}
        Start-Sleep -Seconds 2
        cmdkey /delete:$target 2>&1 | Out-Null
        Log "credencial removida ($ip)"
    }
}
catch {
    Log "ERRO: $_"
    try { Add-Type -AssemblyName System.Windows.Forms; [Windows.Forms.MessageBox]::Show("RDP: $_","Gmais RDP") | Out-Null } catch {}
}
