# Backup diário dos anexos/imagens dos chamados (pasta files/ do GLPI)
# Roda via Tarefa Agendada \Backup\Glpi-Docker-Files
# Espelho incremental (robocopy /MIR) — só copia o que mudou, igual ao FreeFileSync antigo.
# Não é "um arquivo por dia": é uma cópia sempre atualizada (mirror), não dump datado.

$origem = "C:\docker\glpi-portal\glpi2\files"
$destino = "E:\Backup Sistemas\Backup-glpi-portal\Docker-Files"
$logFile = "E:\Backup Sistemas\Backup-glpi-portal\Docker-DB\backup.log"

function Log($msg) {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - [files] $msg" | Out-File -FilePath $logFile -Append -Encoding utf8
}

Log "Iniciando espelhamento de files/..."
$resultado = robocopy $origem $destino /MIR /R:2 /W:2 /NFL /NDL /NJH 2>&1
$exitCode = $LASTEXITCODE

# Robocopy: 0-7 = sucesso/sem mudancas relevantes, 8+ = erro real
if ($exitCode -lt 8) {
    Log "Espelhamento de files/ concluido com sucesso (codigo $exitCode)."
} else {
    Log "ERRO no espelhamento de files/ (codigo $exitCode): $resultado"
}
