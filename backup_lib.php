<?php
/**
 * backup_lib.php — monitoramento de backups (back-gmais) pra Central de Alertas.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria as tabelas de máquinas/execuções ao ser incluído (padrão do portal,
 * igual a alertas_tipos.php). As máquinas de backup são cadastradas em
 * backup_maquinas.php; cada uma recebe um token que identifica de onde veio
 * o POST em webhook_backup.php (o payload do back-gmais não traz hostname).
 */

require_once __DIR__ . '/agenda/db.php';

// cria as tabelas ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_backup_maquinas (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        nome            VARCHAR(80) NOT NULL,
        token           VARCHAR(40) NOT NULL UNIQUE,
        ativo           TINYINT(1) NOT NULL DEFAULT 1,
        silencio_horas  INT DEFAULT NULL,
        ultimo_contato  DATETIME NULL,
        ultima_politica VARCHAR(120) DEFAULT NULL,
        ultimo_status   ENUM('success','warning','error','cancelled','running') DEFAULT NULL,
        criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_backup_execucoes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        maquina_id  INT NOT NULL,
        politica    VARCHAR(120) NOT NULL DEFAULT '(sem nome)',
        status      ENUM('success','warning','error','cancelled','running') NOT NULL,
        mensagem    TEXT,
        recebido_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (maquina_id) REFERENCES portal_backup_maquinas(id) ON DELETE CASCADE,
        INDEX idx_maquina_politica (maquina_id, politica, recebido_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Webhook: parse + gravação ───────────────────────────── */

/**
 * Parseia o corpo de texto que o back-gmais manda no campo "message" do
 * webhook (linhas "Chave: valor", geradas por notifyIfConfigured/runner.go).
 * Nunca lança — retorno best-effort, chaves ausentes viram null.
 */
function backup_parse_mensagem(string $message): array
{
    $out = ['politica' => null, 'status' => null, 'erro' => null];
    foreach (explode("\n", $message) as $linha) {
        if (!str_contains($linha, ':')) continue;
        [$chave, $valor] = explode(':', $linha, 2);
        $valor = trim($valor);
        switch (trim($chave)) {
            case 'Política': $out['politica'] = $valor !== '' ? $valor : null; break;
            case 'Status':   $out['status']   = $valor !== '' ? $valor : null; break;
            case 'Erro':     $out['erro']     = $valor !== '' ? $valor : null; break;
        }
    }
    return $out;
}

/** 32 chars hex — vai na URL do webhook (?m=). */
function backup_token_novo(): string
{
    return bin2hex(random_bytes(16));
}

/** null se o token não existir OU a máquina estiver inativa (mesmo tratamento). */
function backup_maquina_por_token(PDO $pdo, string $token): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_backup_maquinas WHERE token = ? AND ativo = 1");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Grava 1 execução recebida + atualiza o "último contato" da máquina. */
function backup_registrar_execucao(PDO $pdo, int $maquinaId, ?string $politica, string $status, string $mensagemCrua): void
{
    $politica = ($politica !== null && $politica !== '') ? $politica : '(sem nome)';
    $pdo->prepare("INSERT INTO portal_backup_execucoes (maquina_id, politica, status, mensagem) VALUES (?,?,?,?)")
        ->execute([$maquinaId, $politica, $status, $mensagemCrua]);
    $pdo->prepare("UPDATE portal_backup_maquinas SET ultimo_contato = NOW(), ultima_politica = ?, ultimo_status = ? WHERE id = ?")
        ->execute([$politica, $status, $maquinaId]);
}

/* ───────────────────────────── CRUD de máquinas (backup_maquinas.php) ───────────────────────────── */

function backup_maquinas_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_backup_maquinas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

/** Retorna a linha criada (com o token). Tenta de novo só na hipótese astronômica de colisão de token. */
function backup_maquina_criar(PDO $pdo, string $nome, ?int $silencioHoras): array
{
    for ($tentativa = 0; $tentativa < 3; $tentativa++) {
        $token = backup_token_novo();
        try {
            $pdo->prepare("INSERT INTO portal_backup_maquinas (nome, token, silencio_horas) VALUES (?,?,?)")
                ->execute([$nome, $token, $silencioHoras]);
            $id = (int) $pdo->lastInsertId();
            $st = $pdo->prepare("SELECT * FROM portal_backup_maquinas WHERE id = ?");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'token')) throw $e;
        }
    }
    throw new \RuntimeException('não foi possível gerar um token único');
}

function backup_maquina_atualizar(PDO $pdo, int $id, string $nome, bool $ativo, ?int $silencioHoras): void
{
    $pdo->prepare("UPDATE portal_backup_maquinas SET nome=?, ativo=?, silencio_horas=? WHERE id=?")
        ->execute([$nome, $ativo ? 1 : 0, $silencioHoras, $id]);
}

function backup_maquina_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_backup_maquinas WHERE id = ?")->execute([$id]);
}

/* ───────────────────────────── Catálogo da Central de Alertas ───────────────────────────── */

/** @return array ocorrências: cada uma ['chave','titulo','loja','detalhe'] */
function alerta_check_backup_erro(PDO $pdo, array $p): array
{
    $rows = $pdo->query("
        SELECT e.maquina_id, e.politica, e.mensagem, e.recebido_em, m.nome
        FROM portal_backup_execucoes e
        JOIN portal_backup_maquinas m ON m.id = e.maquina_id AND m.ativo = 1
        WHERE e.status = 'error'
          AND e.id = (
              SELECT MAX(e2.id) FROM portal_backup_execucoes e2
              WHERE e2.maquina_id = e.maquina_id AND e2.politica = e.politica
          )
        ORDER BY m.nome, e.politica
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $horas = max(0, (int) floor((time() - strtotime($r['recebido_em'])) / 3600));
        $erro = backup_parse_mensagem((string) $r['mensagem'])['erro'];
        $out[] = [
            'chave'   => 'backup_erro:' . $r['maquina_id'] . ':' . $r['politica'],
            'titulo'  => (string) $r['nome'],
            'loja'    => '',
            'detalhe' => $r['politica'] . ': ' . ($erro ?: 'falhou') . ' (há ' . $horas . 'h)',
        ];
    }
    return $out;
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','detalhe'] */
function alerta_check_backup_silencio(PDO $pdo, array $p): array
{
    $horasPadrao = (int) ($p['horas'] ?? 26);
    $st = $pdo->prepare("
        SELECT id, nome, ultimo_contato, ultima_politica
        FROM portal_backup_maquinas
        WHERE ativo = 1
          AND (ultimo_contato IS NULL
               OR ultimo_contato < NOW() - INTERVAL COALESCE(silencio_horas, ?) HOUR)
        ORDER BY nome
    ");
    $st->execute([$horasPadrao]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if ($m['ultimo_contato'] === null) {
            $detalhe = 'nunca contatou';
        } else {
            $horas = max(0, (int) floor((time() - strtotime($m['ultimo_contato'])) / 3600));
            $detalhe = 'sem contato há ' . $horas . 'h (última: ' . ($m['ultima_politica'] ?: '?') . ')';
        }
        $out[] = [
            'chave'   => 'backup_silencio:' . $m['id'],
            'titulo'  => (string) $m['nome'],
            'loja'    => '',
            'detalhe' => $detalhe,
        ];
    }
    return $out;
}

/** innerHTML do corpo da seção — tabela simples, mesmo visual dos outros tipos. */
function alerta_render_backup_erro(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nenhum backup com falha.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<table><thead><tr><th>Máquina</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($ocorr as $o) {
        $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}

/** innerHTML do corpo da seção — tabela simples, mesmo visual dos outros tipos. */
function alerta_render_backup_silencio(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Todas as máquinas de backup estão em contato.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<table><thead><tr><th>Máquina</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($ocorr as $o) {
        $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
