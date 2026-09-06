# Repontar agente.ps1 - so troca a URL do GLPI Agent ja instalado e forca um envio.
# Nao reinstala nada. Ideal pros PCs que ja tem o agente mas ficaram apontando
# pro endereco antigo (http://192.168.1.198/glpi2/ do XAMPP, que morreu).
# Rode como Administrador.
param(
  [string]$ServerUrl = 'http://192.168.1.198/glpi2/'
)
$ErrorActionPreference = 'Continue'

# 1) escreve a URL no registro (32 e 64 bits) e no agent.cfg
foreach ($k in 'HKLM:\SOFTWARE\GLPI-Agent','HKLM:\SOFTWARE\WOW6432Node\GLPI-Agent') {
  if (Test-Path $k) {
    Set-ItemProperty -Path $k -Name server -Value $ServerUrl
    Set-ItemProperty -Path $k -Name 'no-ssl-check' -Value 0 -ErrorAction SilentlyContinue
    Write-Host "[OK] $k -> server=$ServerUrl"
  }
}
$cfg = 'C:\Program Files\GLPI-Agent\etc\agent.cfg'
if (-not (Test-Path $cfg)) { $cfg = 'C:\Program Files (x86)\GLPI-Agent\etc\agent.cfg' }
if (Test-Path $cfg) {
  $c = Get-Content $cfg
  if ($c -match '^\s*server\s*=') { $c = $c -replace '^\s*server\s*=.*', "server = $ServerUrl" } else { $c += "server = $ServerUrl" }
  $c = $c -replace '^\s*no-ssl-check\s*=.*', 'no-ssl-check = 0'
  Set-Content $cfg $c -Encoding ASCII
  Write-Host "[OK] agent.cfg atualizado"
}

# 2) reinicia o servico
Restart-Service glpi-agent -ErrorAction SilentlyContinue

# 3) forca um envio de inventario
$bat = 'C:\Program Files\GLPI-Agent\glpi-agent.bat'
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (-not (Test-Path $bat)) { $bat = Join-Path "${env:ProgramFiles(x86)}\GLPI-Agent\bin" 'glpi-agent.bat' }
if (Test-Path $bat) {
  Write-Host "[INFO] Enviando inventario forcado..."
  & $bat --server $ServerUrl --tasks=Inventory --full -f --debug
} else {
  Write-Host "[ERRO] glpi-agent.bat nao encontrado - o agente nao esta instalado. Use o 'Executar Inventario.bat'." -ForegroundColor Red
  exit 1
}

# 4) log
$agentLog = "${env:ProgramFiles}\GLPI-Agent\logs\glpi-agent.log"
if (-not (Test-Path $agentLog)) { $agentLog = "${env:ProgramFiles(x86)}\GLPI-Agent\logs\glpi-agent.log" }
if (Test-Path $agentLog) { Get-Content $agentLog -Tail 40 }
