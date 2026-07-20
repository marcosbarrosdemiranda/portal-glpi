# Backup diário do banco glpi2 (container glpi-db) — dump comprimido (gzip streaming) + retenção
# Roda via Tarefa Agendada \Backup\Glpi-Docker-DB
# Usa GZipStream (não Compress-Archive) porque o Compress-Archive do PowerShell 5.1
# quebra em arquivos >4GB ("Fluxo muito longo"). GZipStream comprime direto do
# stdout do mysqldump sem nunca gravar o .sql descomprimido em disco.

$date = Get-Date -Format "yyyy-MM-dd"
$backupDir = "D:\Backup Glpi\Docker-DB"
$retencaoDias = 5

if (-not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$gzFile = "$backupDir\glpi2_$date.sql.gz"
$logFile = "$backupDir\backup.log"

function Log($msg) {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - $msg" | Out-File -FilePath $logFile -Append -Encoding utf8
}

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
        exit 1
    }
    if (-not (Test-Path $gzFile) -or (Get-Item $gzFile).Length -eq 0) {
        Log "ERRO: arquivo de backup vazio ou nao criado."
        exit 1
    }

    Log "Dump concluido: $gzFile ($([math]::Round((Get-Item $gzFile).Length/1MB,1)) MB comprimido)"

    $antigos = Get-ChildItem $backupDir -Filter "glpi2_*.sql.gz" | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$retencaoDias) }
    foreach ($f in $antigos) {
        Remove-Item $f.FullName -Force
        Log "Removido backup antigo (>$retencaoDias dias): $($f.Name)"
    }

    Log "Backup do banco concluido com sucesso."
} catch {
    Log "ERRO: $($_.Exception.Message)"
    exit 1
}
