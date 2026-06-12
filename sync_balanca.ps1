<#
.SYNOPSIS
  Agente de sincronização de balanças MGV 6 → Portal TI
.DESCRIPTION
  Conecta no Firebird local do MGV 6, extrai as balanças e envia
  via HTTP/JSON para o endpoint sync_remoto do portal.
  Projetado para rodar no Task Scheduler de cada servidor MGV.

  Requisitos:
    - Firebird ODBC driver instalado (já vem com MGV 6)
    - Acesso HTTP ao servidor do portal (VPN)
    - Arquivo de config: sync_balanca.config.json (lado a lado)

.EXAMPLE
  .\sync_balanca.ps1                    # usa config padrão
  .\sync_balanca.ps1 -Config .\mgv-loja01.json
  .\sync_balanca.ps1 -Test              # só mostra o que vai enviar
#>

param(
    [string]$Config = "sync_balanca.config.json",
    [switch]$Test
)

# ── Config ──────────────────────────────────────────────────────
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path

if (Test-Path (Join-Path $scriptPath $Config)) {
    $cfg = Get-Content (Join-Path $scriptPath $Config) -Raw | ConvertFrom-Json
} else {
    Write-Host "[!] Arquivo de config não encontrado: $Config" -ForegroundColor Red
    Write-Host "[i] Criando template... Gerando $Config" -ForegroundColor Yellow

    $template = @{
        servidor_nome  = "MGV Loja 01"              # Nome exato cadastrado no portal
        portal_url     = "http://192.168.1.3/inventario_balancas.php"
        sync_token     = ""                          # Token se configurado no portal
        fb_driver      = "Firebird/InterBase(r) driver"
        fb_host        = "localhost"
        fb_port        = 3050
        fb_database    = "C:\MGV6\DADOS\MGV6.FDB"    # Ajuste para o caminho real
        fb_user        = "SYSDBA"
        fb_pass        = "masterkey"
        tabelas_tentar = @("BALANCAS", "TB_BALANCAS", "EQUIPAMENTOS", "BALANCA")
    } | ConvertTo-Json -Depth 3

    $template | Out-File (Join-Path $scriptPath $Config) -Encoding utf8
    Write-Host "[i] Template criado. Edite o arquivo e rode novamente." -ForegroundColor Yellow
    exit 1
}

# ── Validação básica ────────────────────────────────────────────
if (-not $cfg.servidor_nome)  { Write-Host "[!] servidor_nome não configurado"; exit 1 }
if (-not $cfg.portal_url)     { Write-Host "[!] portal_url não configurado"; exit 1 }
if (-not $cfg.fb_database)    { Write-Host "[!] fb_database não configurado"; exit 1 }

# ── Conecta no Firebird via ODBC ──────────────────────────────
Write-Host "[*] Conectando Firebird: $($cfg.fb_database)..." -ForegroundColor Cyan

$connStr = "Driver={$($cfg.fb_driver)};DBNAME=$($cfg.fb_host)/$($cfg.fb_port):$($cfg.fb_database);UID=$($cfg.fb_user);PWD=$($cfg.fb_pass)"
try {
    $conn = New-Object System.Data.Odbc.OdbcConnection($connStr)
    $conn.Open()
    Write-Host "[OK] Conectado ao Firebird!" -ForegroundColor Green
} catch {
    Write-Host "[!] Erro ao conectar Firebird: $_" -ForegroundColor Red
    exit 1
}

# ── Detecta tabela de balanças ─────────────────────────────────
$tabela_encontrada = $null
foreach ($tbl in $cfg.tabelas_tentar) {
    try {
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = "SELECT COUNT(*) AS c FROM $tbl"
        $r = $cmd.ExecuteReader()
        if ($r.Read()) {
            $tabela_encontrada = $tbl
            $r.Close()
            break
        }
        $r.Close()
    } catch { continue }
}

if (-not $tabela_encontrada) {
    Write-Host "[!] Nenhuma tabela de balanças encontrada." -ForegroundColor Red
    Write-Host "[i] Tabelas procuradas: $($cfg.tabelas_tentar -join ', ')"
    $conn.Close()
    exit 1
}

Write-Host "[OK] Tabela encontrada: $tabela_encontrada" -ForegroundColor Green

# ── Detecta colunas ───────────────────────────────────────────
$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT FIRST 1 * FROM $tabela_encontrada"
$reader = $cmd.ExecuteReader()
$colunas = @()
if ($reader.Read()) {
    for ($i = 0; $i -lt $reader.FieldCount; $i++) {
        $colunas += $reader.GetName($i)
    }
}
$reader.Close()
$colunas_upper = $colunas | ForEach-Object { $_.ToUpper() }

function Get-Col([string[]]$alias) {
    foreach ($a in $alias) {
        if ($colunas_upper -contains $a.ToUpper()) { return $a }
        $a_underscore = $a.Replace(' ', '_')
        if ($colunas_upper -contains $a_underscore.ToUpper()) { return $a_underscore }
    }
    return $null
}

$col_id    = Get-Col @('BAL_NUMERO', 'COD_BALANCA', 'NUMERO', 'ID_BALANCA', 'CODIGO', 'ID')
$col_modelo = Get-Col @('BAL_MODELO', 'MODELO', 'DS_MODELO', 'DESCRICAO_MODELO')
$col_serie  = Get-Col @('BAL_SERIE', 'NUM_SERIE', 'SERIE', 'NR_SERIE', 'NUMERO_SERIE')
$col_loja   = Get-Col @('BAL_LOJA', 'COD_LOJA', 'LOJA', 'CD_LOJA')
$col_depto  = Get-Col @('BAL_DEPARTAMENTO', 'COD_DEPARTAMENTO', 'DEPARTAMENTO', 'SETOR', 'CD_DEPARTAMENTO')

if (-not $col_id) {
    Write-Host "[!] Coluna de identificação não encontrada. Colunas: $($colunas -join ', ')" -ForegroundColor Red
    $conn.Close()
    exit 1
}

Write-Host "[OK] Mapeamento: ID='$col_id' Modelo='$col_modelo' Serie='$col_serie' Loja='$col_loja' Depto='$col_depto'" -ForegroundColor Green

# ── Extrai dados ──────────────────────────────────────────────
$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT * FROM $tabela_encontrada"
$reader = $cmd.ExecuteReader()

$balancas = @()
$total = 0
while ($reader.Read()) {
    $total++
    $ident = ($reader[$col_id] -as [string] ?? "").Trim()
    if (-not $ident) { continue }

    $b = @{ identificacao = $ident }

    if ($col_modelo)  { $b.modelo       = ($reader[$col_modelo] -as [string] ?? "").Trim() }
    if ($col_serie)   { $b.serie        = ($reader[$col_serie] -as [string] ?? "").Trim() }
    if ($col_loja)    { $b.loja         = ($reader[$col_loja] -as [string] ?? "").Trim() }
    if ($col_depto)   { $b.departamento = ($reader[$col_depto] -as [string] ?? "").Trim() }

    $balancas += $b
}
$reader.Close()
$conn.Close()

Write-Host "[OK] Extraídas $total balanças ($($balancas.Count) válidas)" -ForegroundColor Green

# ── Modo TEST ─────────────────────────────────────────────────
if ($Test) {
    Write-Host "`n[MODO TEST] Dados que seriam enviados:" -ForegroundColor Yellow
    $payload = @{
        servidor_nome = $cfg.servidor_nome
        sync_token    = $cfg.sync_token
        balancas      = $balancas
    } | ConvertTo-Json -Depth 3
    Write-Host $payload
    exit 0
}

# ── Envia para o portal ───────────────────────────────────────
Write-Host "[*] Enviando para $($cfg.portal_url)..." -ForegroundColor Cyan

$payload = @{
    servidor_nome = $cfg.servidor_nome
    sync_token    = $cfg.sync_token
    balancas      = $balancas
}

$body = $payload | ConvertTo-Json -Depth 3 -Compress

try {
    $response = Invoke-RestMethod -Uri $cfg.portal_url `
                                  -Method POST `
                                  -Body $body `
                                  -ContentType "application/json; charset=utf-8" `
                                  -TimeoutSec 30

    if ($response.ok) {
        Write-Host "[OK] Sync concluído!" -ForegroundColor Green
        Write-Host "     Recebidas: $($response.recebidas)" -ForegroundColor Gray
        Write-Host "     Importadas: $($response.importados)" -ForegroundColor Gray
        Write-Host "     Atualizadas: $($response.atualizados)" -ForegroundColor Gray
        exit 0
    } else {
        Write-Host "[!] Portal retornou erro: $($response.erro)" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "[!] Erro ao enviar para o portal: $_" -ForegroundColor Red
    exit 1
}
