# Repontar agente.ps1 - so troca a URL do GLPI Agent ja instalado e forca um envio.
# NAO reinstala nada. Para os PCs que ja tem o agente mas ficaram apontando
# pro endereco antigo (http://192.168.1.198/glpi2/ do XAMPP, que morreu).
# Rode como Administrador.
#
# Endereco padrao: IP interno + porta 7412 (HTTPS), com no-ssl-check porque o
# certificado e para ti.grupogmais.com, nao para o IP. E o unico endereco que
# funciona de dentro sem mudar nada no servidor.
param(
  [string]$ServerUrl  = 'https://192.168.1.198:7412/glpi2/',
  [int]$NoSslCheck    = 1
)
$ErrorActionPreference = 'Continue'

# 1) escreve a URL no registro (32 e 64 bits) e no agent.cfg
$mexeu = $false
foreach ($k in 'HKLM:\SOFTWARE\GLPI-Agent','HKLM:\SOFTWARE\WOW6432Node\GLPI-Agent') {
  if (Test-Path $k) {
    Set-ItemProperty -Path $k -Name server -Value $ServerUrl
    Set-ItemProperty -Path $k -Name 'no-ssl-check' -Value $NoSslCheck -ErrorAction SilentlyContinue
    Write-Host "[OK] $k -> server=$ServerUrl (no-ssl-check=$NoSslCheck)"
    $mexeu = $true
  }
}
$cfg = 'C:\Program Files\GLPI-Agent\etc\agent.cfg'
if (-not (Test-Path $cfg)) { $cfg = 'C:\Program Files (x86)\GLPI-Agent\etc\agent.cfg' }
if (Test-Path $cfg) {
  $c = Get-Content $cfg
  if ($c -match '^\s*server\s*=') { $c = $c -replace '^\s*server\s*=.*', "server = $ServerUrl" } else { $c += "server = $ServerUrl" }
  if ($c -match '^\s*no-ssl-check\s*=') { $c = $c -replace '^\s*no-ssl-check\s*=.*', "no-ssl-check = $NoSslCheck" } else { $c += "no-ssl-check = $NoSslCheck" }
  Set-Content $cfg $c -Encoding ASCII
  Write-Host "[OK] agent.cfg atualizado"
  $mexeu = $true
}
if (-not $mexeu) {
  Write-Host "[ERRO] GLPI Agent nao encontrado neste PC. Use o 'Executar Inventario.bat' para instalar." -ForegroundColor Red
  exit 1
}

# 2) reinicia o servico
Restart-Service glpi-agent -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

# 3) forca um envio de inventario agora
$bat = 'C:\Program Files\GLPI-Agent\glpi-agent.bat'
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (Test-Path $bat) {
  Write-Host "[INFO] Enviando inventario forcado para $ServerUrl ..."
  $sslArg = if ($NoSslCheck -eq 1) { '--no-ssl-check' } else { '' }
  & $bat --server $ServerUrl $sslArg --tasks=Inventory --full -f --debug
} else {
  Write-Host "[AVISO] glpi-agent.bat nao encontrado; o servico vai enviar no proximo ciclo." -ForegroundColor Yellow
}

# 4) mostra o resultado no log
$agentLog = "${env:ProgramFiles}\GLPI-Agent\logs\glpi-agent.log"
if (-not (Test-Path $agentLog)) { $agentLog = "${env:ProgramFiles(x86)}\GLPI-Agent\logs\glpi-agent.log" }
if (Test-Path $agentLog) {
  Write-Host "`n--- ultimas linhas do log ---"
  Get-Content $agentLog -Tail 25
}
