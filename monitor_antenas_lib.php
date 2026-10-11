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
 * Checa 1 antena via SSH (mca-status). Timeout curto explícito — uma
 * antena travada não pode travar a varredura (ausente no padrão original
 * de db_central_lib.php, necessário aqui por N antenas no mesmo ciclo).
 *
 * ATENÇÃO: comando/formato de saída (`mca-status`, JSON) ainda não
 * validados contra uma antena real — risco registrado na spec
 * (2026-10-10-monitor-antenas-unifi-ssh-design.md). Ajustar o parsing
 * abaixo depois do primeiro teste real.
 *
 * @return array{ok:bool,uptime:?int,clientes:?int,firmware:?string,modelo:?string,erro:?string}
 */
function monitor_antena_ssh_check(string $ip, string $usuario, string $senha): array
{
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
    exec(
        "sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=3 $host " . escapeshellarg('mca-status') . ' 2>&1',
        $output,
        $return_var
    );

    if ($return_var !== 0 || !$output) {
        $resultado['erro'] = 'Falha ao conectar via SSH (código ' . $return_var . ')';
        return $resultado;
    }

    $json = json_decode(implode("\n", $output), true);
    if (!is_array($json)) {
        $resultado['erro'] = 'Resposta inesperada do mca-status (formato não-JSON — comando/parsing não validados, ver spec)';
        return $resultado;
    }

    // Esquema esperado (melhor esforço — ajustar após validar contra hardware real):
    // {"sys": {"uptime": N, "fwversion": "...", "board": {"name": "..."}},
    //  "wireless": {"vap_table": [{"num_sta": N}, ...]}}
    $uptime = $json['sys']['uptime'] ?? $json['uptime'] ?? null;
    $firmware = $json['sys']['fwversion'] ?? $json['fwversion'] ?? null;
    $modelo = $json['sys']['board']['name'] ?? $json['board']['name'] ?? $json['model'] ?? null;

    $clientes = null;
    $vaps = $json['wireless']['vap_table'] ?? $json['vap_table'] ?? null;
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
