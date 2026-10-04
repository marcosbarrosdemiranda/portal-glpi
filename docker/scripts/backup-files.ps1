# Backup diário dos anexos/imagens dos chamados (pasta files/ do GLPI)
# Roda via Tarefa Agendada \Backup\Glpi-Docker-Files
# Espelho incremental (robocopy /MIR) — só copia o que mudou, igual ao FreeFileSync antigo.
# Não é "um arquivo por dia": é uma cópia sempre atualizada (mirror), não dump datado.

$origem = "C:\docker\glpi-portal\glpi2\files"
$destino = "E:\Backup Sistemas\Backup-glpi-portal\Docker-Files"
$logFile = "E:\Backup Sistemas\Backup-glpi-portal\Docker-DB\backup.log"

# Mesma URL de webhook cadastrada em backup-db.ps1 (máquina "GLPI Produção
# (local)" em backup_maquinas.php) — política "Arquivos" reporta separado da
# política "Banco de Dados", pra uma falha não mascarar a outra.
$webhookUrl = ""

function Log($msg) {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - [files] $msg" | Out-File -FilePath $logFile -Append -Encoding utf8
}

# Nunca lança: falha de rede aqui não pode interferir no espelhamento em si.
function Notificar-Portal($status, $erro, $inicio, $fim) {
    if ($webhookUrl -eq "") { return }
    try {
        $msg = "Política: Arquivos`nStatus: $status`nInício: $inicio`nFim: $fim"
        if ($erro) { $msg += "`nErro: $erro" }
        $body = @{ message = $msg } | ConvertTo-Json
        Invoke-RestMethod -Uri $webhookUrl -Method Post -Body $body -ContentType "application/json" -TimeoutSec 10 | Out-Null
    } catch {
        Log "AVISO: falha ao notificar o portal: $($_.Exception.Message)"
    }
}

$inicio = (Get-Date).ToString("o")
Log "Iniciando espelhamento de files/..."
$resultado = robocopy $origem $destino /MIR /R:2 /W:2 /NFL /NDL /NJH 2>&1
$exitCode = $LASTEXITCODE
$fim = (Get-Date).ToString("o")

# Robocopy: 0-7 = sucesso/sem mudancas relevantes, 8+ = erro real
if ($exitCode -lt 8) {
    Log "Espelhamento de files/ concluido com sucesso (codigo $exitCode)."
    Notificar-Portal "success" $null $inicio $fim
} else {
    Log "ERRO no espelhamento de files/ (codigo $exitCode): $resultado"
    Notificar-Portal "error" "robocopy saiu com codigo $exitCode" $inicio $fim
}
