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
 * Consulta conexões ativas, uso de CPU e uso de memória do DB Central via SSH.
 *
 * @param int $max_conexoes limiar de conexões para considerar alerta
 * @param int $max_cpu      limiar de CPU (%) para considerar alerta
 * @param int $max_mem      limiar de uso de memória (%) para considerar alerta
 * @return array{conexoes:int,conexao_perc:float,cpu_usage:float,mem_usada_pct:float,mem_total_mb:float,mem_usada_mb:float,alerta:bool,msg:string}|array{error:string}
 */
function db_central_status(int $max_conexoes = 1200, int $max_cpu = 90, int $max_mem = 90): array
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

    // O `top` do servidor roda em locale pt-BR: decimal com vírgula
    // ("10,2 us", "60241,8 total"). Normaliza pra ponto antes de (float).
    $num = static fn (string $s): float => (float) str_replace(',', '.', $s);

    $cpu_line = '';
    $mem_line = '';
    foreach ($output as $line) {
        if ($cpu_line === '' && strpos($line, '%CPU') !== false) {
            $cpu_line = $line;
        }
        if ($mem_line === '' && stripos($line, 'mem') !== false && stripos($line, 'total') !== false) {
            $mem_line = $line;
        }
    }

    preg_match('/%CPU\(s\):\s*([\d,.]+)\s*us,/', $cpu_line, $matches_cpu);
    $cpu_usage = isset($matches_cpu[1]) ? $num($matches_cpu[1]) : 0.0;

    preg_match('/mem\s*:\s*([\d,.]+)\s*total,\s*([\d,.]+)\s*free,\s*([\d,.]+)\s*used/i', $mem_line, $matches_mem);
    $mem_total_mb = isset($matches_mem[1]) ? $num($matches_mem[1]) : 0.0;
    $mem_usada_mb = isset($matches_mem[3]) ? $num($matches_mem[3]) : 0.0;
    $mem_usada_pct = $mem_total_mb > 0 ? round(($mem_usada_mb / $mem_total_mb) * 100, 1) : 0.0;

    $conexao_perc = $max_conexoes > 0 ? round(($conexoes / $max_conexoes) * 100, 1) : 0.0;
    $alerta = ($conexoes > $max_conexoes || $cpu_usage > $max_cpu || $mem_usada_pct > $max_mem);

    return [
        'conexoes'      => $conexoes,
        'conexao_perc'  => $conexao_perc,
        'cpu_usage'     => $cpu_usage,
        'mem_usada_pct' => $mem_usada_pct,
        'mem_total_mb'  => $mem_total_mb,
        'mem_usada_mb'  => $mem_usada_mb,
        'alerta'        => $alerta,
        'msg'           => $alerta ? 'ALERTA: Recursos próximos ao limite!' : 'Status Normal',
    ];
}

/**
 * Texto pronto pra resposta do chamado diário — mesmo padrão de
 * backup_resumo_texto()/solides_relatorio_texto(): 1 linha por métrica,
 * ✅/⚠️ conforme o limiar configurado no tipo de alerta `db_central`.
 */
function db_central_status_texto(array $status, int $max_conexoes = 1200, int $max_cpu = 90, int $max_mem = 90): string
{
    if (isset($status['error'])) {
        return 'Banco de Dados Central: falha ao consultar (' . $status['error'] . ').';
    }

    $icone = fn (float $usado, float $limite): string => $usado > $limite ? '⚠️' : '✅';

    $conexoes  = (int) ($status['conexoes'] ?? 0);
    $cpu       = (float) ($status['cpu_usage'] ?? 0);
    $mem       = (float) ($status['mem_usada_pct'] ?? 0);

    // CPU/memória já são % de 0-100 — "X%/Y%" parecia fração (X de Y) quando
    // na verdade Y é só o limiar de alerta. Formato sem ambiguidade abaixo.
    $linhas = [
        $icone($conexoes, $max_conexoes) . " Conexões: {$conexoes}/{$max_conexoes}",
        $icone($cpu, $max_cpu) . " CPU: {$cpu}% (limite {$max_cpu}%)",
        $icone($mem, $max_mem) . " Memória: {$mem}% (limite {$max_mem}%)",
    ];

    return "Banco de Dados Central (192.168.1.10):\n" . implode("\n", $linhas);
}
