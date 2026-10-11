<?php
/**
 * monitor_antenas_lib.php — monitoramento de antenas UniFi via SSH direto
 * (sem depender do Controller — sessão da API clássica não é confiável
 * pra polling em loop).
 *
 * Mesmo precedente de db_central_lib.php (exec + sshpass), generalizado
 * pra N antenas cadastradas manualmente, com persistência (necessária pra
 * detectar reinício comparando uptime entre duas leituras).
 *
 * Só o container glpi-web tem sshpass/ssh instalado — o worker
 * (portal-wpp-worker) nunca chama monitor_antena_ssh_check()/
 * monitor_antenas_varrer() direto, só consome antenas_unifi_status.php
 * via HTTP (mesmo padrão de agenda/postgres_status.php).
 *
 * Roteiro: Docs/superpowers/plans/2026-10-10-monitor-antenas-unifi-ssh.md
 */

require_once __DIR__ . '/agenda/config.php'; // GLPI_APP_TOKEN, usado por vault_crypto.php
require_once __DIR__ . '/agenda/db.php';     // $pdo
require_once __DIR__ . '/vault_crypto.php';  // vault_encrypt()/vault_decrypt()
require_once __DIR__ . '/wpp/db.php';        // wpp_cfg_get()/wpp_cfg_set() — credencial/limite globais

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_monitor_antenas (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        nome                VARCHAR(80) NOT NULL,
        ip                  VARCHAR(45) NOT NULL,
        ssh_usuario         VARCHAR(100) NULL,
        ssh_senha_enc       TEXT NULL,
        ativo               TINYINT(1) NOT NULL DEFAULT 1,
        status              ENUM('online','offline','desconhecido') NOT NULL DEFAULT 'desconhecido',
        modelo              VARCHAR(80) NULL,
        firmware_versao     VARCHAR(40) NULL,
        clientes_conectados INT NULL,
        uptime_segundos     INT NULL,
        ultima_verificacao  DATETIME NULL,
        criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/**
 * Resolve [usuario, senha] pra uma antena: usa a credencial própria se
 * preenchida, senão cai no default global (wpp_cfg).
 *
 * @param array $antena linha de portal_monitor_antenas
 * @return array{usuario:string,senha:string}
 */
function monitor_antena_credencial(array $antena): array
{
    $usuario = trim((string) ($antena['ssh_usuario'] ?? ''));
    $senhaEnc = (string) ($antena['ssh_senha_enc'] ?? '');

    if ($usuario !== '' && $senhaEnc !== '') {
        return ['usuario' => $usuario, 'senha' => vault_decrypt($senhaEnc)];
    }

    $usuarioGlobal = trim((string) wpp_cfg_get('antena_ssh_usuario', ''));
    $senhaEncGlobal = (string) wpp_cfg_get('antena_ssh_senha_enc', '');

    return [
        'usuario' => $usuario !== '' ? $usuario : $usuarioGlobal,
        'senha'   => $senhaEnc !== '' ? vault_decrypt($senhaEnc) : ($senhaEncGlobal !== '' ? vault_decrypt($senhaEncGlobal) : ''),
    ];
}

/**
 * Checa 1 antena via SSH (mca-dump). Timeout curto explícito de conexão
 * E de execução — uma antena travada não pode travar a varredura (ausente
 * no padrão original de db_central_lib.php, necessário aqui por N antenas
 * no mesmo ciclo).
 *
 * Validado 2026-10-11 contra 1 antena real (UAP-AC-Lite, firmware
 * BZ.6.8.2): esse firmware NÃO tem `mca-status` (o comando previsto na
 * spec original) — só `mca-dump` (JSON direto, sem interação) e
 * `mca-cli-op` (interativo — nunca usar sem `timeout` de execução, trava
 * a sessão SSH aberta na antena pra sempre). Daí o `timeout 8` + `</dev/null`
 * abaixo, além do `ConnectTimeout=3` que já existia (esse só limita a
 * conexão, não a execução do comando). Mantém os caminhos `sys.*`/
 * `wireless.*` como fallback pra firmwares mais novos que ainda usem o
 * formato de `mca-status` previsto na spec original
 * (2026-10-10-monitor-antenas-unifi-ssh-design.md) — esse formato
 * continua sem validar contra hardware real.
 *
 * Seam de teste (mesmo padrão de monitor_lib.php):
 * $GLOBALS['__monitor_antena_ssh_fake'] = fn(string $ip, string $usuario, string $senha): array.
 *
 * @return array{ok:bool,uptime:?int,clientes:?int,firmware:?string,modelo:?string,erro:?string}
 */
function monitor_antena_ssh_check(string $ip, string $usuario, string $senha): array
{
    if (isset($GLOBALS['__monitor_antena_ssh_fake']) && is_callable($GLOBALS['__monitor_antena_ssh_fake'])) {
        return ($GLOBALS['__monitor_antena_ssh_fake'])($ip, $usuario, $senha);
    }

    $resultado = ['ok' => false, 'uptime' => null, 'clientes' => null, 'firmware' => null, 'modelo' => null, 'erro' => null];

    $ip = trim($ip);
    $usuario = trim($usuario);
    if ($ip === '' || $usuario === '' || $senha === '') {
        $resultado['erro'] = 'IP/usuário/senha ausentes';
        return $resultado;
    }

    $output = [];
    $return_var = 0;
    putenv('SSHPASS=' . $senha);
    $host = escapeshellarg("$usuario@$ip");
    // `timeout 8` cobre o comando inteiro (conexão + execução remota) —
    // `</dev/null` fecha o stdin pra qualquer comando que tente ler input
    // falhar/sair rápido em vez de ficar pendurado (ver docblock acima).
    // `-o LogLevel=ERROR` suprime o "Warning: Permanently added '<ip>' ...
    // to the list of known hosts." que o SSH imprime no 1º contato com
    // uma antena nova — sem isso, essa linha entra misturada antes do
    // JSON (via 2>&1) e quebra o json_decode (achado real, 2026-10-11:
    // só não apareceu antes porque a antena de teste já tinha host key
    // conhecida de testes anteriores).
    exec(
        "timeout 8 sshpass -e ssh -o StrictHostKeyChecking=no -o LogLevel=ERROR -o ConnectTimeout=3 $host "
            . escapeshellarg('mca-dump') . ' </dev/null 2>&1',
        $output,
        $return_var
    );

    if ($return_var !== 0 || !$output) {
        $resultado['erro'] = 'Falha ao conectar via SSH (código ' . $return_var . ')';
        return $resultado;
    }

    // Defensivo: mesmo com LogLevel=ERROR, se alguma linha de ruído ainda
    // vier antes do JSON (banner diferente, etc.), pula pro primeiro '{'
    // em vez de falhar o parse inteiro por causa de 1 linha extra.
    $bruto = implode("\n", $output);
    $inicioJson = strpos($bruto, '{');
    $json = $inicioJson !== false ? json_decode(substr($bruto, $inicioJson), true) : null;
    if (!is_array($json)) {
        $resultado['erro'] = 'Resposta inesperada do mca-dump (formato não-JSON)';
        return $resultado;
    }

    // Esquema real (validado 2026-10-11 contra UAP-AC-Lite/BZ.6.8.2):
    // uptime/vap_table/model/model_display no topo do JSON. sys.*/
    // wireless.* ficam como fallback pro formato de mca-status previsto
    // na spec original, pra firmwares ainda não testados.
    $uptime = $json['uptime'] ?? $json['sys']['uptime'] ?? null;
    $firmware = $json['sys']['fwversion'] ?? $json['fwversion'] ?? null;
    $modelo = $json['model_display'] ?? $json['model'] ?? $json['sys']['board']['name'] ?? $json['board']['name'] ?? null;

    $clientes = null;
    $vaps = $json['vap_table'] ?? $json['wireless']['vap_table'] ?? null;
    if (is_array($vaps)) {
        $clientes = 0;
        foreach ($vaps as $vap) {
            $clientes += (int) ($vap['num_sta'] ?? 0);
        }
    }

    $resultado['ok'] = true;
    $resultado['uptime'] = $uptime !== null ? (int) $uptime : null;
    $resultado['clientes'] = $clientes;
    $resultado['firmware'] = $firmware !== null ? (string) $firmware : null;
    $resultado['modelo'] = $modelo !== null ? (string) $modelo : null;
    return $resultado;
}

/**
 * Varre todas as antenas ativas, com auto-throttle: só roda SSH de novo
 * numa antena se a última verificação for mais antiga que
 * $minIntervaloSeg — evita triplicar carga de SSH quando os 3 tipos de
 * alerta (Fase 2) chamam o endpoint no mesmo ciclo do worker.
 *
 * Cada item devolvido inclui 'uptime_anterior' (valor antes do UPDATE),
 * pra alerta_check_antena_reinicio comparar sem precisar de outra leitura.
 *
 * @return array<int,array> snapshot de cada antena ativa
 */
function monitor_antenas_varrer(PDO $pdo, int $minIntervaloSeg = 20): array
{
    $antenas = $pdo->query("SELECT * FROM portal_monitor_antenas WHERE ativo = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $resultado = [];

    foreach ($antenas as $antena) {
        $uptimeAnterior = $antena['uptime_segundos'] !== null ? (int) $antena['uptime_segundos'] : null;
        $ultimaVerificacao = $antena['ultima_verificacao'];

        $fresco = $ultimaVerificacao !== null
            && strtotime($ultimaVerificacao) !== false
            && (time() - strtotime($ultimaVerificacao)) < $minIntervaloSeg;

        if ($fresco) {
            $resultado[] = $antena + ['uptime_anterior' => $uptimeAnterior];
            continue;
        }

        $cred = monitor_antena_credencial($antena);
        $check = monitor_antena_ssh_check($antena['ip'], $cred['usuario'], $cred['senha']);
        $status = $check['ok'] ? 'online' : 'offline';

        $pdo->prepare("UPDATE portal_monitor_antenas SET
                status = ?, modelo = ?, firmware_versao = ?, clientes_conectados = ?,
                uptime_segundos = ?, ultima_verificacao = NOW()
            WHERE id = ?")
            ->execute([
                $status,
                $check['modelo'],
                $check['firmware'],
                $check['clientes'],
                $check['uptime'],
                $antena['id'],
            ]);

        $antena['status'] = $status;
        $antena['modelo'] = $check['modelo'];
        $antena['firmware_versao'] = $check['firmware'];
        $antena['clientes_conectados'] = $check['clientes'];
        $antena['uptime_segundos'] = $check['uptime'];
        $antena['ultima_verificacao'] = date('Y-m-d H:i:s');
        $antena['uptime_anterior'] = $uptimeAnterior;

        $resultado[] = $antena;
    }

    return $resultado;
}

/** Lista todas as antenas cadastradas (ativas ou não), pra tela de cadastro. */
function monitor_antena_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_monitor_antenas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Conta quedas (antena_offline) por antena num dia e soma os minutos
 * offline, emparelhando pares 'nova' -> 'resolvida' em
 * portal_alertas_historico pela mesma chave ('antena_offline:<id>') — a
 * tabela e a chave são escritas por gat_alertas_tipo()/alertas_tipos.php,
 * não por este arquivo (depende de quem chama já ter incluído
 * alertas_tipos.php, ver antena_resumo_ajax.php). Queda sem 'resolvida'
 * dentro do dia (ainda offline, ou resolveu só no dia seguinte) conta como
 * 1 queda e soma até o fim do dia consultado (minutos parciais).
 *
 * @return array<int,array{nome:string,quedas:int,minutos_offline:int}> indexado por id da antena
 */
function antena_resumo_quedas_dia(PDO $pdo, string $data): array
{
    $inicio = $data . ' 00:00:00';
    $fim = $data . ' 23:59:59';

    $st = $pdo->prepare("SELECT chave, evento, criado_em FROM portal_alertas_historico
        WHERE tipo = 'antena_offline' AND criado_em BETWEEN ? AND ?
        ORDER BY chave, criado_em, id");
    $st->execute([$inicio, $fim]);

    $porChave = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $porChave[$ev['chave']][] = $ev;
    }

    $nomesPorId = $pdo->query("SELECT id, nome FROM portal_monitor_antenas")->fetchAll(PDO::FETCH_KEY_PAIR);

    $resumo = [];
    foreach ($porChave as $chave => $eventos) {
        $id = (int) str_replace('antena_offline:', '', $chave);
        $abertaDesde = null;

        foreach ($eventos as $ev) {
            if ($ev['evento'] === 'nova') {
                $abertaDesde = $ev['criado_em'];
                $resumo[$id] = $resumo[$id] ?? ['nome' => $nomesPorId[$id] ?? ('Antena #' . $id), 'quedas' => 0, 'minutos_offline' => 0];
                $resumo[$id]['quedas']++;
            } elseif ($ev['evento'] === 'resolvida' && $abertaDesde !== null) {
                $resumo[$id]['minutos_offline'] += max(0, (int) round((strtotime($ev['criado_em']) - strtotime($abertaDesde)) / 60));
                $abertaDesde = null;
            }
        }
        // queda ainda aberta no fim do dia consultado -> soma os minutos parciais
        if ($abertaDesde !== null && isset($resumo[$id])) {
            $resumo[$id]['minutos_offline'] += max(0, (int) round((strtotime($fim) - strtotime($abertaDesde)) / 60));
        }
    }

    return $resumo;
}

/** Texto pronto pra pré-preencher a resposta — mesmo padrão de backup_resumo_texto(). */
function antena_resumo_texto(array $resumo, string $data): string
{
    $dataFmt = date('d/m/Y', strtotime($data));
    if (!$resumo) {
        return "Antenas UniFi: sem quedas em {$dataFmt}.";
    }

    usort($resumo, fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));

    $out = "Antenas UniFi — quedas em {$dataFmt}:\n";
    foreach ($resumo as $r) {
        $out .= '  • ' . $r['nome'] . ': ' . $r['quedas'] . ' queda' . ($r['quedas'] === 1 ? '' : 's')
            . ', ' . $r['minutos_offline'] . " min offline\n";
    }
    return rtrim($out);
}

/**
 * Cria (quando $id é null) ou atualiza uma antena. Senha vazia em edição
 * mantém a senha já salva (não força re-digitar pra só trocar o nome/IP).
 */
function monitor_antena_salvar(PDO $pdo, ?int $id, string $nome, string $ip, string $sshUsuario, string $sshSenha, bool $ativo): int
{
    $nome = trim($nome);
    $ip = trim($ip);
    if ($nome === '') throw new \InvalidArgumentException('nome obrigatório');
    if ($ip === '') throw new \InvalidArgumentException('IP obrigatório');

    $sshUsuario = trim($sshUsuario);
    $senhaEnc = $sshSenha !== '' ? vault_encrypt($sshSenha) : null;

    if ($id === null) {
        $pdo->prepare("INSERT INTO portal_monitor_antenas (nome, ip, ssh_usuario, ssh_senha_enc, ativo)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$nome, $ip, $sshUsuario ?: null, $senhaEnc, $ativo ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    if ($senhaEnc !== null) {
        $pdo->prepare("UPDATE portal_monitor_antenas SET nome = ?, ip = ?, ssh_usuario = ?, ssh_senha_enc = ?, ativo = ? WHERE id = ?")
            ->execute([$nome, $ip, $sshUsuario ?: null, $senhaEnc, $ativo ? 1 : 0, $id]);
    } else {
        $pdo->prepare("UPDATE portal_monitor_antenas SET nome = ?, ip = ?, ssh_usuario = ?, ativo = ? WHERE id = ?")
            ->execute([$nome, $ip, $sshUsuario ?: null, $ativo ? 1 : 0, $id]);
    }
    return $id;
}

function monitor_antena_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_monitor_antenas WHERE id = ?")->execute([$id]);
}
