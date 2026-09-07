# deploy-glpi-agent.ps1 - instala/atualiza o GLPI Agent via GPO (startup script).
# O MSI tem que estar NESTA MESMA PASTA (SYSVOL da GPO). Nunca baixa da internet.
param(
  # IP interno + porta 7412 (HTTPS), com no-ssl-check: o certificado e para
  # ti.grupogmais.com, nao para o IP - e os PCs de loja nao resolvem o DNS
  # interno. E o unico endereco que funciona de dentro sem mexer no servidor.
  # (A porta 80 do proxy foi removida do nginx - ver commit b149b18.)
  [string]$ServerUrl = 'https://192.168.1.198:7412/glpi2/',
  [int]$NoSslCheck   = 1,
  [string]$Version   = '1.15',
  [string]$Freq      = 'daily'
)

$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

$Log = "$env:WINDIR\Temp\glpi-gpo.log"
function Log($m){ "$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) [INFO] $m" | Out-File -FilePath $Log -Append -Encoding utf8 }

Log "Deploy GLPI Agent iniciado. Server=$ServerUrl | NoSslCheck=$NoSslCheck | Version=$Version | Freq=$Freq | PSScriptRoot=$PSScriptRoot"

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

# 2b) Garante a URL no registro (o SERVER= do MSI so pega em instalacao limpa;
#     num agente ja instalado - inclusive um que ficou no endereco velho -
#     precisa escrever direto no registro/cfg)
foreach ($k in 'HKLM:\SOFTWARE\GLPI-Agent','HKLM:\SOFTWARE\WOW6432Node\GLPI-Agent') {
  if (Test-Path $k) {
    Set-ItemProperty -Path $k -Name server -Value $ServerUrl
    Set-ItemProperty -Path $k -Name 'no-ssl-check' -Value $NoSslCheck -ErrorAction SilentlyContinue
  }
}
$cfg = 'C:\Program Files\GLPI-Agent\etc\agent.cfg'
if (-not (Test-Path $cfg)) { $cfg = 'C:\Program Files (x86)\GLPI-Agent\etc\agent.cfg' }
if (Test-Path $cfg) {
  $cc = Get-Content $cfg
  if ($cc -match '^\s*server\s*=') { $cc = $cc -replace '^\s*server\s*=.*', "server = $ServerUrl" } else { $cc += "server = $ServerUrl" }
  if ($cc -match '^\s*no-ssl-check\s*=') { $cc = $cc -replace '^\s*no-ssl-check\s*=.*', "no-ssl-check = $NoSslCheck" } else { $cc += "no-ssl-check = $NoSslCheck" }
  Set-Content $cfg $cc -Encoding ASCII
}
Log "URL forcada no registro/cfg: $ServerUrl (no-ssl-check=$NoSslCheck)"

# 3) Reinicia servico e agenda envio forcado no proximo ciclo do servico
$bat = 'C:\Program Files\GLPI-Agent\glpi-agent.bat'
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" "glpi-agent.bat" }
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" "glpi-agent.bat" }
if (Test-Path $bat) {
  try {
    Log "Reiniciando servico glpi-agent..."
    Stop-Service glpi-agent -Force -ErrorAction SilentlyContinue
    Start-Service glpi-agent -ErrorAction SilentlyContinue
    Log "Forcando envio de inventario para $ServerUrl ..."
    $sslArg = if ($NoSslCheck -eq 1) { '--no-ssl-check' } else { '' }
    & $bat --server $ServerUrl $sslArg --tasks=Inventory --full -f --debug 2>&1 | Out-File -FilePath $Log -Append -Encoding utf8
    & $bat --set-forcerun --debug 2>&1 | Out-File -FilePath $Log -Append -Encoding utf8
  } catch {
    Log "Falha ao acionar agente: $($_.Exception.Message)"
  }
} else {
  Log "ERRO: glpi-agent.bat nao encontrado apos instalacao."
}

Log "Deploy GLPI Agent finalizado."
