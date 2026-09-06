# Forcar atualizacao.ps1 - dispara um inventario completo e forcado (sem reinstalar o agente).
# Uso: .\Forcar atualizacao.ps1   (ou passe -ServerUrl para sobrescrever)
param(
  [string]$ServerUrl = 'https://ti.grupogmais.com:7412/glpi2/'
)

$ErrorActionPreference = 'Continue'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

# localiza o glpi-agent.bat (64 ou 32 bits)
$bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" 'glpi-agent.bat'
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (-not (Test-Path $bat)) {
  Write-Host "[ERRO ] GLPI Agent nao encontrado. Rode o 'Executar Inventario.bat' primeiro." -ForegroundColor Red
  exit 1
}

Write-Host "[INFO ] Enviando inventario forcado para $ServerUrl ..." -ForegroundColor Cyan
& $bat --server $ServerUrl --tasks=Inventory --full -f --debug

# reinicia o servico para reagendar o proximo ciclo
Restart-Service glpi-agent -ErrorAction SilentlyContinue

# ultimas linhas do log
$agentLog = "${env:ProgramFiles}\GLPI-Agent\logs\glpi-agent.log"
if (-not (Test-Path $agentLog)) { $agentLog = "${env:ProgramFiles(x86)}\GLPI-Agent\logs\glpi-agent.log" }
if (Test-Path $agentLog) { Get-Content $agentLog -Tail 60 }
