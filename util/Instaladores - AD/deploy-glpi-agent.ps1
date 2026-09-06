# deploy-glpi-agent.ps1 - instala/atualiza o GLPI Agent via GPO (startup script).
# O MSI tem que estar NESTA MESMA PASTA (SYSVOL da GPO).
param(
  [string]$ServerUrl = 'https://ti.grupogmais.com:7412/glpi2/',
  [string]$Version   = '1.15',
  [string]$Freq      = 'daily'
)

$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

$Log = "$env:WINDIR\Temp\glpi-gpo.log"
function Log($m){ "$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) [INFO] $m" | Out-File -FilePath $Log -Append -Encoding utf8 }

Log "Deploy GLPI Agent iniciado. Server=$ServerUrl | Version=$Version | Freq=$Freq | PSScriptRoot=$PSScriptRoot"

# 1) MSI: so na pasta da GPO (SYSVOL) - nunca baixa da internet num startup script
$msiExact = Join-Path $PSScriptRoot "GLPI-Agent-$Version-x64.msi"
if (-not (Test-Path $msiExact)) {
  $cand = Get-ChildItem -Path $PSScriptRoot -Filter "GLPI-Agent-*-x64.msi" -ErrorAction SilentlyContinue |
          Sort-Object Name -Descending | Select-Object -First 1
  if ($cand) { $msiExact = $cand.FullName }
}
if (-not (Test-Path $msiExact)) { Log "ERRO: MSI nao encontrado em $PSScriptRoot"; exit 1 }
Log "MSI: $msiExact"

# 2) Instala/atualiza silencioso (RUNNOW=1 ja envia um inventario)
$instLog = "$env:WINDIR\Temp\glpi-agent-install.log"
$msiArgs = @(
  "/i","`"$msiExact`"","/qn",
  "SERVER=$ServerUrl",
  "TASK_FREQUENCY=$Freq",
  "RUNNOW=1",
  "/L*v","`"$instLog`""
)
Log "Instalando/Atualizando agente..."
$proc = Start-Process msiexec -ArgumentList $msiArgs -PassThru -Wait
Log "msiexec exitcode=$($proc.ExitCode)"

# 3) Reinicia servico e agenda envio forcado no proximo ciclo do servico
$bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" "glpi-agent.bat"
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" "glpi-agent.bat" }
if (Test-Path $bat) {
  try {
    Log "Reiniciando servico glpi-agent..."
    Stop-Service glpi-agent -Force -ErrorAction SilentlyContinue
    Start-Service glpi-agent -ErrorAction SilentlyContinue
    Log "Marcando forcerun..."
    & $bat --set-forcerun --debug 2>&1 | Out-File -FilePath $Log -Append -Encoding utf8
  } catch {
    Log "Falha ao acionar agente: $($_.Exception.Message)"
  }
} else {
  Log "ERRO: glpi-agent.bat nao encontrado apos instalacao."
}

Log "Deploy GLPI Agent finalizado."
