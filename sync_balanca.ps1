<#
.SYNOPSIS
  Agente de sincronização de balanças MGV 6 → Portal TI
.DESCRIPTION
  Conecta no SQL Server local do MGV 6, extrai as balanças e envia
  via HTTP/JSON para o endpoint sync_remoto do portal.
  Projetado para rodar no Task Scheduler de cada servidor MGV.

  Requisitos:
    - Acesso ao SQL Server local (instância do MGV 6)
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
    Write-Host "[i] Criando template... $Config gerado." -ForegroundColor Yellow

    $template = @{
        servidor_nome  = "MGV Loja 01"               # Nome exato cadastrado no portal
        portal_url     = "http://192.168.1.198/inventario_balancas.php"
        sync_token     = "wZDUZdEGzwDWmcU9EPeUFDsvonsnuAhO"

        # ── Conexão SQL Server ──
        sql_server     = "localhost"                   # Instância SQL (ex: localhost, .\SQLEXPRESS, .\MGV6)
        sql_database   = "MGV6"                        # Nome do banco de dados
        sql_auth       = "windows"                     # "windows" (integrada) ou "sql" (usuário/senha)
        sql_user       = "sa"
        sql_pass       = ""

        # Tabelas que o script vai procurar
        tabelas_tentar = @("tbBalanca", "BALANCAS", "TB_BALANCAS", "TB_BALANCA", "EQUIPAMENTOS", "BALANCA")
    } | ConvertTo-Json -Depth 3

    $template | Out-File (Join-Path $scriptPath $Config) -Encoding utf8
    Write-Host "[i] Edite o $Config com os dados do SQL Server e rode novamente." -ForegroundColor Yellow
    exit 1
}

# ── Validação básica ────────────────────────────────────────────
if (-not $cfg.servidor_nome)  { Write-Host "[!] servidor_nome não configurado"; exit 1 }
if (-not $cfg.portal_url)     { Write-Host "[!] portal_url não configurado"; exit 1 }
if (-not $cfg.sql_server)     { Write-Host "[!] sql_server não configurado"; exit 1 }
if (-not $cfg.sql_database)   { Write-Host "[!] sql_database não configurado"; exit 1 }

# ── Conecta no SQL Server ─────────────────────────────────────
Write-Host "[*] Conectando SQL Server: $($cfg.sql_server) / $($cfg.sql_database)..." -ForegroundColor Cyan

if ($cfg.sql_auth -eq "sql") {
    $connStr = "Server=$($cfg.sql_server);Database=$($cfg.sql_database);User Id=$($cfg.sql_user);Password=$($cfg.sql_pass);"
} else {
    $connStr = "Server=$($cfg.sql_server);Database=$($cfg.sql_database);Integrated Security=True;"
}

try {
    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
    Write-Host "[OK] Conectado ao SQL Server!" -ForegroundColor Green
} catch {
    Write-Host "[!] Erro ao conectar no SQL Server: $_" -ForegroundColor Red
    Write-Host "[i] Dica: A instância pode ser .\SQLEXPRESS, .\MGV6 ou o nome do servidor." -ForegroundColor Yellow
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
$cmd.CommandText = "SELECT TOP 1 * FROM $tabela_encontrada"
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

$col_id    = Get-Col @('BAL_CODIGO', 'BAL_NUMERO', 'COD_BALANCA', 'NUMERO', 'ID_BALANCA', 'CODIGO', 'ID')
$col_modelo = Get-Col @('BAL_MODELO', 'MODELO', 'DS_MODELO', 'DESCRICAO_MODELO')
$col_serie  = Get-Col @('BAL_NUMERO_SERIE', 'BAL_SERIE', 'NUM_SERIE', 'SERIE', 'NR_SERIE', 'NUMERO_SERIE')
$col_loja   = Get-Col @('BAL_LOJA', 'COD_LOJA', 'LOJA', 'CD_LOJA')
$col_depto  = Get-Col @('BAL_DEPARTAMENTO', 'COD_DEPARTAMENTO', 'DEPARTAMENTO', 'SETOR', 'CD_DEPARTAMENTO')
$col_ip     = Get-Col @('BAL_ENDERECO_IP', 'BAL_DMP_ENDERECO_IP', 'ENDERECO_IP', 'IP', 'BAL_IP')
$col_versao = Get-Col @('BAL_VERSAO', 'BAL_DMP_VERSAO_BALANCA', 'VERSAO_FIRMWARE', 'VERSAO')
$col_carga_atual = Get-Col @('BAL_NUMERO_CARGA_ATUAL', 'CARGA_ATUAL', 'BAL_DMP_NUM_CARGA', 'NUMERO_CARGA')
$col_carga_prog  = Get-Col @('BAL_NUMERO_CARGA_PROG', 'CARGA_PROGRAMADA', 'BAL_DMP_NUM_CARGA_PROG')
$col_ultima_cx   = Get-Col @('BAL_ULTIMA_CX_GW', 'ULTIMA_COMUNICACAO', 'ULTIMA_CX_GW')
$col_lote        = Get-Col @('BAL_LOTE_FABRICACAO', 'LOTE_FABRICACAO', 'LOTE')

if (-not $col_id) {
    Write-Host "[!] Coluna de identificação não encontrada." -ForegroundColor Red
    Write-Host "[i] Colunas disponíveis: $($colunas -join ', ')" -ForegroundColor Yellow
    $conn.Close()
    exit 1
}

Write-Host "[OK] Mapeamento: ID='$col_id' Modelo='$col_modelo' Serie='$col_serie' Loja='$col_loja' Depto='$col_depto' FW='$col_versao' Carga='$col_carga_atual'" -ForegroundColor Green

# ── Helper pra ler campo com segurança ─────────────────────────
function Get-FieldValue($reader, $colName) {
    if (-not $colName) { return "" }
    $val = $reader[$colName]
    if ($val -eq $null -or $val -eq [DBNull]::Value) { return "" }
    return $val.ToString().Trim()
}

# ── Extrai dados ──────────────────────────────────────────────

# MGV6: esquema específico com JOINs (tbBalanca + tbTipoBalanca + tbDepartamento)
if ($tabela_encontrada -match '^tbBalanca') {
    $balancas = @()
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = @"
SELECT
    b.BAL_CODIGO AS id_bal,
    t.TPB_NOME AS nome_modelo,
    b.BAL_NUMERO_SERIE AS num_serie,
    d.DPT_NOME AS nome_depto,
    COALESCE(NULLIF(b.BAL_ENDERECO_IP, ''), NULLIF(b.BAL_DMP_ENDERECO_IP, ''), '') AS end_ip
FROM tbBalanca b
LEFT JOIN tbTipoBalanca t ON t.TPB_CODIGO = b.TPB_CODIGO
LEFT JOIN tbDepartamento d ON d.DPT_CODIGO = b.DPT_CODIGO
WHERE b.BAL_ATIVA = 1
ORDER BY b.BAL_CODIGO
"@
    $reader = $cmd.ExecuteReader()

    $total = 0
    while ($reader.Read()) {
        $total++
        $ident = (Get-FieldValue $reader "id_bal").Trim()
        if (-not $ident) { continue }

        $balancas += @{
            identificacao    = $ident
            modelo           = Get-FieldValue $reader "nome_modelo"
            serie            = Get-FieldValue $reader "num_serie"
            departamento     = Get-FieldValue $reader "nome_depto"
            ip               = Get-FieldValue $reader "end_ip"
            versao_firmware  = Get-FieldValue $reader "versao_firmware"
            carga_atual      = [int](Get-FieldValue $reader "carga_atual")
            carga_programada = [int](Get-FieldValue $reader "carga_programada")
            ultima_comunicacao = Get-FieldValue $reader "ultima_comunicacao"
            lote_fabricacao  = Get-FieldValue $reader "lote_fabricacao"
        }
    }
    $reader.Close()
    $conn.Close()

    Write-Host "[OK] Extraídas $total balanças (MGV6 com JOINs)" -ForegroundColor Green
} else {
    # ── Generic path: detecta colunas e extrai ──
    $balancas = @()
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SELECT * FROM $tabela_encontrada"
    $reader = $cmd.ExecuteReader()

    $total = 0
    while ($reader.Read()) {
        $total++
        $ident = Get-FieldValue $reader $col_id
        if (-not $ident) { continue }

        $b = @{ identificacao = $ident }
        if ($col_modelo)  { $b.modelo       = Get-FieldValue $reader $col_modelo }
        if ($col_serie)   { $b.serie        = Get-FieldValue $reader $col_serie }
        if ($col_loja)    { $b.loja         = Get-FieldValue $reader $col_loja }
        if ($col_depto)   { $b.departamento = Get-FieldValue $reader $col_depto }
        if ($col_ip)      { $b.ip           = Get-FieldValue $reader $col_ip }
        if ($col_versao)  { $b.versao_firmware = Get-FieldValue $reader $col_versao }
        if ($col_carga_atual) { $b.carga_atual = [int](Get-FieldValue $reader $col_carga_atual) }
        if ($col_carga_prog)  { $b.carga_programada = [int](Get-FieldValue $reader $col_carga_prog) }
        if ($col_ultima_cx)   { $b.ultima_comunicacao = Get-FieldValue $reader $col_ultima_cx }
        if ($col_lote)        { $b.lote_fabricacao = Get-FieldValue $reader $col_lote }

        $balancas += $b
    }
    $reader.Close()
    $conn.Close()

    Write-Host "[OK] Extraídas $total balanças ($($balancas.Count) válidas)" -ForegroundColor Green
}

# ── Modo TEST ─────────────────────────────────────────────────
if ($Test) {
    Write-Host "`n[MODO TEST] Dados que seriam enviados:" -ForegroundColor Yellow
    $endpoint = $cfg.portal_url
    if ($endpoint -notmatch '[?&]action=') {
        $endpoint += if ($endpoint.Contains('?')) { '&action=sync_remoto' } else { '?action=sync_remoto' }
    }
    Write-Host "URL: $endpoint" -ForegroundColor Gray
    # Teste de conexão com o portal (ping)
    try {
        $testUrl = "$($cfg.portal_url)?action=ping&$(Get-Random)"
        $ping = Invoke-RestMethod -Uri $testUrl -TimeoutSec 10
        Write-Host "[OK] Portal respondendo: $($ping.versao) - $($ping.mensagem)" -ForegroundColor Green
    } catch {
        Write-Host "[!] Portal não respondeu ping: $_" -ForegroundColor Red
    }
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
    $endpoint = $cfg.portal_url
    if ($endpoint -notmatch '[?&]action=') {
        $endpoint += if ($endpoint.Contains('?')) { '&action=sync_remoto' } else { '?action=sync_remoto' }
    }
    $endpoint += "&n=$(Get-Random)"  # Evita cache PHP/Apache
    $response = Invoke-RestMethod -Uri $endpoint `
                                  -Method POST `
                                  -Body $body `
                                  -ContentType "application/json; charset=utf-8" `
                                  -TimeoutSec 30

    if ($response -isnot [PSCustomObject]) {
        Write-Host "[!] Resposta inesperada (não-JSON). Verifique URL e servidor." -ForegroundColor Red
        Write-Host "[i] Resposta bruta: $($response | Out-String)" -ForegroundColor Gray
        exit 1
    }

    # DEBUG: mostra a resposta completa
    Write-Host "[DEBUG] Resposta completa do portal:" -ForegroundColor DarkGray
    Write-Host ($response | ConvertTo-Json -Depth 3) -ForegroundColor DarkGray

    if ($response.ok) {
        Write-Host "[OK] Sync concluído!" -ForegroundColor Green
        Write-Host "     Recebidas: $($response.recebidas)" -ForegroundColor Gray
        Write-Host "     Importadas: $($response.importados)" -ForegroundColor Gray
        Write-Host "     Atualizadas: $($response.atualizados)" -ForegroundColor Gray
        exit 0
    } else {
        $erro_msg = if ($response.erro) { $response.erro } else { 'Resposta inesperada do portal (verificar logs do Apache/PHP)' }
        Write-Host "[!] Portal retornou erro: $erro_msg" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "[!] Erro ao enviar para o portal: $_" -ForegroundColor Red
    exit 1
}
