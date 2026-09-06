# deploy-glpi-agent.ps1 (v5) - instala/reconfigura o GLPI Agent e dispara o 1o inventario.
# Uso (fora do dominio / manual). Rode como Administrador.
#   .\deploy-glpi-agent.ps1
#   .\deploy-glpi-agent.ps1 -ServerUrl "http://192.168.1.198/glpi2/"
#
# OBS: o endereco e HTTP no IP interno porque os PCs de loja NAO resolvem
# ti.grupogmais.com pro IP interno. A porta 80 do 192.168.1.198 faz proxy
# so do trafego do agente pro container (ver docker/nginx.conf, bloco "listen 80").
param(
  [string]$Version   = '1.15',
  [string]$ServerUrl = 'http://192.168.1.198/glpi2/',
  [string]$Freq      = 'daily'
)

$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

function Set-GlpiConfig([string]$url) {
  # O SERVER= do MSI so pega em instalacao limpa; num agente ja instalado
  # precisa escrever direto no registro.
  foreach ($k in 'HKLM:\SOFTWARE\GLPI-Agent','HKLM:\SOFTWARE\WOW6432Node\GLPI-Agent') {
    if (Test-Path $k) {
      Set-ItemProperty -Path $k -Name server -Value $url
      Set-ItemProperty -Path $k -Name 'no-ssl-check' -Value 0 -ErrorAction SilentlyContinue
    }
  }
  $cfg = 'C:\Program Files\GLPI-Agent\etc\agent.cfg'
  if (-not (Test-Path $cfg)) { $cfg = 'C:\Program Files (x86)\GLPI-Agent\etc\agent.cfg' }
  if (Test-Path $cfg) {
    $c = Get-Content $cfg
    if ($c -match '^\s*server\s*=') { $c = $c -replace '^\s*server\s*=.*', "server = $url" } else { $c += "server = $url" }
    $c = $c -replace '^\s*no-ssl-check\s*=.*', 'no-ssl-check = 0'
    Set-Content $cfg $c -Encoding ASCII
  }
}

$arch    = if ([Environment]::Is64BitOperatingSystem) { 'x64' } else { 'x86' }
$msiName = "GLPI-Agent-$Version-$arch.msi"
$msiLog  = "$env:WINDIR\Temp\glpi-agent-install.log"

Write-Host "[INFO ] GLPI Agent $Version ($arch) -> SERVER=$ServerUrl" -ForegroundColor Cyan

# --- localiza o MSI: 1) pasta do script  2) C:\Instaladores  3) baixa do GitHub
$msiPath = $null
foreach ($dir in @($PSScriptRoot, 'C:\Instaladores')) {
  if ($dir -and (Test-Path (Join-Path $dir $msiName))) { $msiPath = Join-Path $dir $msiName; break }
}
if (-not $msiPath) {
  $dlDir = 'C:\Instaladores'
  New-Item -ItemType Directory -Path $dlDir -Force | Out-Null
  $msiPath = Join-Path $dlDir $msiName
  $msiUrl  = "https://github.com/glpi-project/glpi-agent/releases/download/$Version/$msiName"
  Write-Host "[INFO ] Baixando: $msiUrl" -ForegroundColor Cyan
  Invoke-WebRequest -Uri $msiUrl -OutFile $msiPath -UseBasicParsing
}
Write-Host "[INFO ] MSI: $msiPath" -ForegroundColor Cyan

# --- instala / reconfigura silencioso
$msiArgs = @(
  '/i', "`"$msiPath`"", '/qn',
  "SERVER=$ServerUrl",
  "TASK_FREQUENCY=$Freq",
  'RUNNOW=1',
  '/L*v', "`"$msiLog`""
)
Write-Host "[INFO ] Instalando/Configurando..." -ForegroundColor Cyan
$p = Start-Process msiexec.exe -ArgumentList $msiArgs -Wait -PassThru
if ($p.ExitCode -ne 0) {
  Write-Host "[ERRO ] msiexec ExitCode=$($p.ExitCode). Veja $msiLog" -ForegroundColor Red
  exit $p.ExitCode
}

# --- garante a URL no registro/cfg (o MSI nao troca se o agente ja existia)
Set-GlpiConfig $ServerUrl

# --- localiza o glpi-agent.bat
$bat = 'C:\Program Files\GLPI-Agent\glpi-agent.bat'
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" 'glpi-agent.bat' }

# --- reinicia o servico e forca um envio imediato
Write-Host "[INFO ] Reiniciando servico e forcando inventario..." -ForegroundColor Cyan
Set-Service glpi-agent -StartupType Automatic -ErrorAction SilentlyContinue
Restart-Service glpi-agent -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3
if (Test-Path $bat) {
  & $bat --server $ServerUrl --tasks=Inventory --full -f --debug
} else {
  Write-Host "[WARN ] glpi-agent.bat nao encontrado - o RUNNOW=1 do MSI ja deve ter enviado." -ForegroundColor Yellow
}

# --- log final
$agentLog = "${env:ProgramFiles}\GLPI-Agent\logs\glpi-agent.log"
if (-not (Test-Path $agentLog)) { $agentLog = "${env:ProgramFiles(x86)}\GLPI-Agent\logs\glpi-agent.log" }
if (Test-Path $agentLog) {
  Write-Host "[INFO ] OK. Confira no GLPI e no log abaixo ($agentLog):" -ForegroundColor Green
  Get-Content $agentLog -Tail 30
} else {
  Write-Host "[WARN ] Log do agente nao localizado. Instalador: $msiLog" -ForegroundColor Yellow
}
