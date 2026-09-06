# deploy-glpi-agent.ps1 (v6) - instala/reconfigura o GLPI Agent e dispara o 1o inventario.
# Uso (fora do dominio / manual). Rode como Administrador.
#   .\deploy-glpi-agent.ps1
#   .\deploy-glpi-agent.ps1 -ServerUrl "https://192.168.1.198:7412/glpi2/"
#
# Endereco: IP interno + porta 7412 (HTTPS), com no-ssl-check porque o cert e
# para ti.grupogmais.com, nao para o IP - e os PCs de loja nao resolvem o DNS
# interno. E o unico endereco que funciona de dentro sem mudar o servidor.
param(
  [string]$Version    = '1.15',
  [string]$ServerUrl  = 'https://192.168.1.198:7412/glpi2/',
  [int]$NoSslCheck    = 1,
  [string]$Freq       = 'daily'
)

$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

function Set-GlpiConfig([string]$url, [int]$nossl) {
  # O SERVER= do MSI so pega em instalacao limpa; num agente ja instalado
  # precisa escrever direto no registro.
  foreach ($k in 'HKLM:\SOFTWARE\GLPI-Agent','HKLM:\SOFTWARE\WOW6432Node\GLPI-Agent') {
    if (Test-Path $k) {
      Set-ItemProperty -Path $k -Name server -Value $url
      Set-ItemProperty -Path $k -Name 'no-ssl-check' -Value $nossl -ErrorAction SilentlyContinue
    }
  }
  $cfg = 'C:\Program Files\GLPI-Agent\etc\agent.cfg'
  if (-not (Test-Path $cfg)) { $cfg = 'C:\Program Files (x86)\GLPI-Agent\etc\agent.cfg' }
  if (Test-Path $cfg) {
    $c = Get-Content $cfg
    if ($c -match '^\s*server\s*=')       { $c = $c -replace '^\s*server\s*=.*', "server = $url" }       else { $c += "server = $url" }
    if ($c -match '^\s*no-ssl-check\s*=')  { $c = $c -replace '^\s*no-ssl-check\s*=.*', "no-ssl-check = $nossl" } else { $c += "no-ssl-check = $nossl" }
    Set-Content $cfg $c -Encoding ASCII
  }
}

$arch    = if ([Environment]::Is64BitOperatingSystem) { 'x64' } else { 'x86' }
$msiName = "GLPI-Agent-$Version-$arch.msi"
$msiLog  = "$env:WINDIR\Temp\glpi-agent-install.log"

Write-Host "[INFO ] GLPI Agent $Version ($arch) -> SERVER=$ServerUrl  no-ssl-check=$NoSslCheck" -ForegroundColor Cyan

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
  Write-Host "[INFO ] MSI nao encontrado na pasta. Baixando: $msiUrl" -ForegroundColor Cyan
  try {
    Invoke-WebRequest -Uri $msiUrl -OutFile $msiPath -UseBasicParsing
  } catch {
    Write-Host "[ERRO ] Falha ao baixar o MSI ($($_.Exception.Message))." -ForegroundColor Red
    Write-Host "         Baixe 'GLPI-Agent-$Version-$arch.msi' de github.com/glpi-project/glpi-agent/releases" -ForegroundColor Red
    Write-Host "         e coloque na mesma pasta deste script, depois rode de novo." -ForegroundColor Red
    exit 1
  }
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
# 3010 = ERROR_SUCCESS_REBOOT_REQUIRED (ok)
if ($p.ExitCode -ne 0 -and $p.ExitCode -ne 3010) {
  Write-Host "[ERRO ] msiexec ExitCode=$($p.ExitCode). Veja $msiLog" -ForegroundColor Red
  exit $p.ExitCode
}

# --- garante a URL no registro/cfg (o MSI nao troca se o agente ja existia)
Set-GlpiConfig $ServerUrl $NoSslCheck

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
  $sslArg = if ($NoSslCheck -eq 1) { '--no-ssl-check' } else { '' }
  & $bat --server $ServerUrl $sslArg --tasks=Inventory --full -f --debug
} else {
  Write-Host "[WARN ] glpi-agent.bat nao encontrado - o RUNNOW=1 do MSI ja deve ter enviado." -ForegroundColor Yellow
}

# --- log final
$agentLog = "${env:ProgramFiles}\GLPI-Agent\logs\glpi-agent.log"
if (-not (Test-Path $agentLog)) { $agentLog = "${env:ProgramFiles(x86)}\GLPI-Agent\logs\glpi-agent.log" }
if (Test-Path $agentLog) {
  Write-Host "`n[INFO ] Ultimas linhas do log ($agentLog):" -ForegroundColor Green
  Get-Content $agentLog -Tail 30
} else {
  Write-Host "[WARN ] Log do agente nao localizado. Instalador: $msiLog" -ForegroundColor Yellow
}
