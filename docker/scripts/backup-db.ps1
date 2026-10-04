# Backup diário do banco glpi2 (container glpi-db) — dump comprimido (gzip streaming) + retenção
# Roda via Tarefa Agendada \Backup\Glpi-Docker-DB
# Usa GZipStream (não Compress-Archive) porque o Compress-Archive do PowerShell 5.1
# quebra em arquivos >4GB ("Fluxo muito longo"). GZipStream comprime direto do
# stdout do mysqldump sem nunca gravar o .sql descomprimido em disco.

$date = Get-Date -Format "yyyy-MM-dd"
$backupDir = "E:\Backup Sistemas\Backup-glpi-portal\Docker-DB"
$retencaoDias = 5

if (-not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$gzFile = "$backupDir\glpi2_$date.sql.gz"
$logFile = "$backupDir\backup.log"

# URL do webhook gerada em backup_maquinas.php ao cadastrar a máquina
# "GLPI Produção (local)" (ver Docs/superpowers/specs/2026-10-04-backup-codigo-banco-glpi.md).
# Vazia = reporte desativado (script continua funcionando normalmente, só não notifica o portal).
$webhookUrl = ""

function Log($msg) {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - $msg" | Out-File -FilePath $logFile -Append -Encoding utf8
}

# Reporta o resultado do job pro mesmo mecanismo que já monitora os servidores
# do back-gmais (webhook_backup.php -> Central de Alertas). Nunca lança: falha
# de rede aqui não pode derrubar o backup em si, só fica registrada no log.
function Notificar-Portal($status, $erro, $dados, $inicio, $fim) {
    if ($webhookUrl -eq "") { return }
    try {
        $msg = "Política: Banco de Dados`nStatus: $status`nInício: $inicio`nFim: $fim"
        if ($erro) { $msg += "`nErro: $erro" }
        if ($dados) { $msg += "`nDados: $dados" }
        $body = @{ message = $msg } | ConvertTo-Json
        Invoke-RestMethod -Uri $webhookUrl -Method Post -Body $body -ContentType "application/json" -TimeoutSec 10 | Out-Null
    } catch {
        Log "AVISO: falha ao notificar o portal: $($_.Exception.Message)"
    }
}

$inicio = (Get-Date).ToString("o")

try {
    Log "Iniciando dump comprimido de glpi2..."

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = "docker"
    $psi.Arguments = "exec glpi-db mysqldump -uroot -proot_password --single-transaction --routines --triggers --events glpi2"
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $proc = [System.Diagnostics.Process]::Start($psi)

    $outFile = [System.IO.File]::Create($gzFile)
    $gzStream = New-Object System.IO.Compression.GZipStream($outFile, [System.IO.Compression.CompressionLevel]::Optimal)
    $proc.StandardOutput.BaseStream.CopyTo($gzStream)
    $gzStream.Close()
    $outFile.Close()

    $stderr = $proc.StandardError.ReadToEnd()
    $proc.WaitForExit()

    if ($proc.ExitCode -ne 0) {
        Log "ERRO: mysqldump saiu com codigo $($proc.ExitCode): $stderr"
        Notificar-Portal "error" "mysqldump saiu com codigo $($proc.ExitCode): $stderr" $null $inicio (Get-Date).ToString("o")
        exit 1
    }
    if (-not (Test-Path $gzFile) -or (Get-Item $gzFile).Length -eq 0) {
        Log "ERRO: arquivo de backup vazio ou nao criado."
        Notificar-Portal "error" "arquivo de backup vazio ou nao criado" $null $inicio (Get-Date).ToString("o")
        exit 1
    }

    $tamanhoMb = [math]::Round((Get-Item $gzFile).Length/1MB,1)
    Log "Dump concluido: $gzFile ($tamanhoMb MB comprimido)"

    $antigos = Get-ChildItem $backupDir -Filter "glpi2_*.sql.gz" | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$retencaoDias) }
    foreach ($f in $antigos) {
        Remove-Item $f.FullName -Force
        Log "Removido backup antigo (>$retencaoDias dias): $($f.Name)"
    }

    Log "Backup do banco concluido com sucesso."
    Notificar-Portal "success" $null "$tamanhoMb MB" $inicio (Get-Date).ToString("o")
} catch {
    Log "ERRO: $($_.Exception.Message)"
    Notificar-Portal "error" "$($_.Exception.Message)" $null $inicio (Get-Date).ToString("o")
    exit 1
}
