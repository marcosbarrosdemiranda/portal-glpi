# deploy-glpi-agent.ps1 (v4) - instala/reconfigura o GLPI Agent e dispara o 1o inventario.
# Uso (fora do dominio / manual). Rode como Administrador.
#   .\deploy-glpi-agent.ps1
#   .\deploy-glpi-agent.ps1 -ServerUrl "https://ti.grupogmais.com:7412/glpi2/"
param(
  [string]$Version   = '1.15',
  [string]$ServerUrl = 'https://ti.grupogmais.com:7412/glpi2/',
  [string]$Freq      = 'daily'
)

$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

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

# --- instala / reconfigura silencioso (RUNNOW=1 ja envia um inventario)
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

# --- localiza o glpi-agent.bat (64 ou 32 bits)
$bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" 'glpi-agent.bat'
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
