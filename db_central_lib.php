<?php
/**
 * db_central_lib.php — monitoramento de leitura do Banco de Dados Central (192.168.1.10).
 *
 * Única fonte de verdade da checagem via SSH (somente leitura: ps/top).
 * Só o container glpi-web tem sshpass/ssh instalado e a credencial
 * DB_CENTRAL_*, então só agenda/postgres_status.php chama esta lib
 * diretamente. alerta_check_db_central() em alertas_tipos.php (que roda
 * dentro do portal-wpp-worker, sem SSH) consome o endpoint HTTP interno
 * em vez de chamar db_central_status() direto — mesmo padrão já usado
 * por agenda/monitor_db_worker.php.
 */

/**
 * Consulta conexões ativas e uso de CPU do DB Central via SSH.
 *
 * @param int $max_conexoes limiar de conexões para considerar alerta
 * @param int $max_cpu      limiar de CPU (%) para considerar alerta
 * @return array{conexoes:int,conexao_perc:float,cpu_usage:float,alerta:bool,msg:string}|array{error:string}
 */
function db_central_status(int $max_conexoes = 1200, int $max_cpu = 90): array
{
    // Nomes de variável dedicados (DB_CENTRAL_*) — evita colidir com DB_PASSWORD
    // de outros serviços no mesmo docker/.env compartilhado do host.
    $user = getenv('DB_CENTRAL_USER') ?: 'buzaneli';
    $host = "$user@192.168.1.10";
    $db_password = getenv('DB_CENTRAL_PASSWORD');

    if (!$db_password) {
        return ['error' => 'DB_CENTRAL_PASSWORD não configurada'];
    }

    $cmd_conexoes = 'ps aux | grep "postgres:" | grep -v grep | wc -l';
    $cmd_top      = 'top -bn1 | head -n 5';

    $output = [];
    $return_var = 0;

    putenv("SSHPASS=" . $db_password);
    exec("sshpass -e ssh -o StrictHostKeyChecking=no " . escapeshellarg($host) . " " . escapeshellarg("$cmd_conexoes; $cmd_top"), $output, $return_var);

    if ($return_var !== 0 || !isset($output[0])) {
        return ['error' => 'Falha ao conectar no banco via SSH'];
    }

    $conexoes = (int) $output[0];

    $top_line = '';
    foreach ($output as $line) {
        if (strpos($line, '%CPU') !== false) {
            $top_line = $line;
            break;
        }
    }
    preg_match('/%CPU\(s\):\s*([\d\.]+) us,/', $top_line, $matches);
    $cpu_usage = isset($matches[1]) ? (float) $matches[1] : 0.0;

    $conexao_perc = $max_conexoes > 0 ? round(($conexoes / $max_conexoes) * 100, 1) : 0.0;
    $alerta = ($conexoes > $max_conexoes || $cpu_usage > $max_cpu);

    return [
        'conexoes'     => $conexoes,
        'conexao_perc' => $conexao_perc,
        'cpu_usage'    => $cpu_usage,
        'alerta'       => $alerta,
        'msg'          => $alerta ? 'ALERTA: Recursos próximos ao limite!' : 'Status Normal',
    ];
}
