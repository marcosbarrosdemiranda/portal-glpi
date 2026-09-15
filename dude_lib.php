<?php
/**
 * dude_lib.php — monitoramento de rede (The Dude, 192.168.1.246) pra Central
 * de Alertas.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria a tabela de estado ao ser incluído (padrão do portal, igual a
 * alertas_tipos.php / backup_lib.php). O Dude não tem API — só empurra
 * mudança de estado via GET pro webhook (the_dude_webhook.php), autenticado
 * por um token único global (não por dispositivo — só existe 1 Dude).
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/wpp/db.php'; // wpp_cfg_get()/wpp_cfg_set() — mesmo mecanismo do grupo_alertas_jid

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_dude_estado (
        tipo          VARCHAR(20)  NOT NULL,
        chave         VARCHAR(160) NOT NULL,
        nome          VARCHAR(160) DEFAULT '',
        endereco      VARCHAR(120) DEFAULT '',
        loja          VARCHAR(120) DEFAULT '',
        categoria     VARCHAR(40)  DEFAULT '',
        status        ENUM('up','down') NOT NULL,
        detalhe       VARCHAR(255) DEFAULT '',
        atualizado_em DATETIME NOT NULL,
        PRIMARY KEY (tipo, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // migrações leves acrescentadas depois do primeiro deploy
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM portal_dude_estado") as $r) $cols[] = $r['Field'];
    if (!in_array('loja', $cols, true)) {
        $pdo->exec("ALTER TABLE portal_dude_estado ADD COLUMN loja VARCHAR(120) DEFAULT '' AFTER endereco");
    }
    if (!in_array('categoria', $cols, true)) {
        // "PDV", "Servidor" etc. — fixo por notification/grupo no Dude, igual a loja.
        // Guardado pra regras futuras (ex.: horário restrito de notificação por categoria).
        $pdo->exec("ALTER TABLE portal_dude_estado ADD COLUMN categoria VARCHAR(40) DEFAULT '' AFTER loja");
    }

    // config por categoria: horário em que "down" vira alerta (NULL = sempre) e
    // horas ligado contínuo antes de virar alerta de "ligado demais" (NULL = não checa).
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_dude_categoria_config (
        categoria        VARCHAR(40) NOT NULL PRIMARY KEY,
        horario_inicio   TIME NULL,
        horario_fim      TIME NULL,
        ligado_horas_max INT NULL,
        atualizado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // exceção de horário por (categoria, loja, dia da semana) — só usada
    // quando existe uma linha pra hoje; senão cai no horário padrão da
    // categoria (portal_dude_categoria_config). dia_semana segue date('w')
    // do PHP: 0=domingo ... 6=sábado. Caso real: PDV fecha mais cedo aos
    // domingos em algumas lojas, mas não em todas.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_dude_horario_excecao (
        categoria        VARCHAR(40)  NOT NULL,
        loja             VARCHAR(120) NOT NULL,
        dia_semana       TINYINT      NOT NULL,
        horario_inicio   TIME         NOT NULL,
        horario_fim      TIME         NOT NULL,
        atualizado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (categoria, loja, dia_semana)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // feriados / dias sem notificação: data específica, loja='' = todas as
    // lojas nesse dia, ou uma loja específica. Não é por categoria — um
    // feriado silencia qualquer alerta de "device" daquela(s) loja(s) no dia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_dude_feriado (
        data  DATE NOT NULL,
        loja  VARCHAR(120) NOT NULL DEFAULT '',
        PRIMARY KEY (data, loja)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Token + estado ───────────────────────────── */

const DUDE_TIPOS_VALIDOS = ['device', 'link', 'latencia', 'service'];

/** String vazia = token ainda não gerado (webhook deve rejeitar tudo nesse caso). */
function dude_token_atual(PDO $pdo): string
{
    return (string) wpp_cfg_get('dude_token', '');
}

/** Gera e grava um novo token (invalida o anterior). Retorna o novo token. */
function dude_gerar_novo_token(PDO $pdo): string
{
    $token = bin2hex(random_bytes(16));
    wpp_cfg_set('dude_token', $token);
    return $token;
}

/**
 * Upsert do estado de 1 (tipo, chave). $tipo já deve estar validado pelo
 * chamador. $loja e $categoria são opcionais (string vazia = sem
 * agrupamento) — cada mapa/notification do Dude manda um valor fixo próprio
 * (ex.: loja="Loja 05", categoria="PDV"). $categoria ainda não é usada em
 * nenhuma regra — guardada pra uso futuro (ex.: horário restrito por tipo
 * de equipamento).
 */
function dude_registrar_estado(PDO $pdo, string $tipo, string $chave, string $nome, string $endereco, string $loja, string $categoria, string $status, string $detalhe): void
{
    $pdo->prepare(
        "INSERT INTO portal_dude_estado (tipo, chave, nome, endereco, loja, categoria, status, detalhe, atualizado_em)
         VALUES (?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE nome=VALUES(nome), endereco=VALUES(endereco), loja=VALUES(loja),
             categoria=VALUES(categoria), status=VALUES(status), detalhe=VALUES(detalhe), atualizado_em=NOW()"
    )->execute([$tipo, $chave, $nome, $endereco, $loja, $categoria, $status, $detalhe]);
}

/** Timestamp (string DATETIME) da última notificação recebida de qualquer tipo, ou null se nunca houve. */
function dude_ultima_notificacao(PDO $pdo): ?string
{
    $v = $pdo->query("SELECT MAX(atualizado_em) FROM portal_dude_estado")->fetchColumn();
    return $v !== null && $v !== false ? (string) $v : null;
}

/* ───────────────────────────── Config por categoria ───────────────────────────── */

/** Lista de categorias distintas já vistas em portal_dude_estado (pra montar a tela de config). */
function dude_categorias_vistas(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT categoria FROM portal_dude_estado WHERE categoria != '' ORDER BY categoria"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Categorias vistas + config (LEFT JOIN — categoria sem linha de config ainda = tudo null/sem restrição). */
function dude_categoria_config_listar(PDO $pdo): array
{
    return $pdo->query(
        "SELECT v.categoria, c.horario_inicio, c.horario_fim, c.ligado_horas_max
         FROM (SELECT DISTINCT categoria FROM portal_dude_estado WHERE categoria != '') v
         LEFT JOIN portal_dude_categoria_config c ON c.categoria = v.categoria
         ORDER BY v.categoria"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Upsert da config de 1 categoria. Passar null em qualquer campo = sem restrição/checagem naquele campo. */
function dude_categoria_config_salvar(PDO $pdo, string $categoria, ?string $horarioInicio, ?string $horarioFim, ?int $ligadoHorasMax): void
{
    $pdo->prepare(
        "INSERT INTO portal_dude_categoria_config (categoria, horario_inicio, horario_fim, ligado_horas_max)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE horario_inicio=VALUES(horario_inicio), horario_fim=VALUES(horario_fim),
             ligado_horas_max=VALUES(ligado_horas_max)"
    )->execute([$categoria, $horarioInicio, $horarioFim, $ligadoHorasMax]);
}

/**
 * true = pode virar alerta agora (categoria sem restrição configurada, OU
 * dentro do horário permitido). false = fora do horário — "down" nesse
 * período é esperado (ex.: PDV desligado à noite), não deve alertar.
 * Suporta janela que cruza meia-noite (ex.: 22:00-06:00).
 *
 * $loja opcional: se houver uma exceção cadastrada pra (categoria, loja,
 * dia da semana de hoje) em portal_dude_horario_excecao, ela manda —
 * senão cai no horário padrão da categoria (comportamento de antes).
 */
function dude_categoria_no_horario(PDO $pdo, string $categoria, string $loja = ''): bool
{
    if ($categoria === '') return true;

    $ini = null;
    $fim = null;

    if ($loja !== '') {
        $st = $pdo->prepare(
            "SELECT horario_inicio, horario_fim FROM portal_dude_horario_excecao
             WHERE categoria = ? AND loja = ? AND dia_semana = ?"
        );
        $st->execute([$categoria, $loja, (int) date('w')]);
        $exc = $st->fetch(PDO::FETCH_ASSOC);
        if ($exc) {
            $ini = (string) $exc['horario_inicio'];
            $fim = (string) $exc['horario_fim'];
        }
    }

    if ($ini === null) {
        $st = $pdo->prepare("SELECT horario_inicio, horario_fim FROM portal_dude_categoria_config WHERE categoria = ?");
        $st->execute([$categoria]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['horario_inicio'] === null || $row['horario_fim'] === null) return true;
        $ini = (string) $row['horario_inicio'];
        $fim = (string) $row['horario_fim'];
    }

    $agora = date('H:i:s');
    if ($ini <= $fim) return $agora >= $ini && $agora <= $fim;
    return $agora >= $ini || $agora <= $fim; // janela cruza meia-noite
}

/** Lojas distintas já vistas em portal_dude_estado (pra montar o seletor da tela de exceções). */
function dude_lojas_vistas(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT loja FROM portal_dude_estado WHERE loja != '' ORDER BY loja"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Todas as exceções cadastradas, ordenadas pra exibição na tela. */
function dude_horario_excecao_listar(PDO $pdo): array
{
    return $pdo->query(
        "SELECT categoria, loja, dia_semana, horario_inicio, horario_fim
         FROM portal_dude_horario_excecao
         ORDER BY categoria, loja, dia_semana"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Upsert de 1 exceção (categoria, loja, dia_semana). Passar horarioInicio
 * OU horarioFim como null remove a exceção (volta a usar o horário padrão
 * da categoria pra essa loja/dia).
 */
function dude_horario_excecao_salvar(PDO $pdo, string $categoria, string $loja, int $diaSemana, ?string $horarioInicio, ?string $horarioFim): void
{
    if ($horarioInicio === null || $horarioFim === null) {
        dude_horario_excecao_excluir($pdo, $categoria, $loja, $diaSemana);
        return;
    }
    $pdo->prepare(
        "INSERT INTO portal_dude_horario_excecao (categoria, loja, dia_semana, horario_inicio, horario_fim)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE horario_inicio=VALUES(horario_inicio), horario_fim=VALUES(horario_fim)"
    )->execute([$categoria, $loja, $diaSemana, $horarioInicio, $horarioFim]);
}

/** Remove a exceção de (categoria, loja, dia_semana) — volta ao horário padrão da categoria. */
function dude_horario_excecao_excluir(PDO $pdo, string $categoria, string $loja, int $diaSemana): void
{
    $pdo->prepare(
        "DELETE FROM portal_dude_horario_excecao WHERE categoria = ? AND loja = ? AND dia_semana = ?"
    )->execute([$categoria, $loja, $diaSemana]);
}

/* ───────────────────────────── Feriados ───────────────────────────── */

/** true = hoje está marcado como feriado (sem notificação) pra essa loja — via linha específica ou '' (todas as lojas). */
function dude_feriado_hoje(PDO $pdo, string $loja): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM portal_dude_feriado WHERE data = CURDATE() AND (loja = '' OR loja = ?) LIMIT 1"
    );
    $st->execute([$loja]);
    return (bool) $st->fetchColumn();
}

/** Lista de feriados cadastrados (passados e futuros — a tela decide o que mostrar). */
function dude_feriado_listar(PDO $pdo): array
{
    return $pdo->query(
        "SELECT data, loja FROM portal_dude_feriado ORDER BY data DESC, loja"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Cadastra 1 feriado. $loja = '' silencia TODAS as lojas nessa data. */
function dude_feriado_salvar(PDO $pdo, string $data, string $loja): void
{
    $pdo->prepare(
        "INSERT IGNORE INTO portal_dude_feriado (data, loja) VALUES (?, ?)"
    )->execute([$data, $loja]);
}

/** Remove 1 feriado cadastrado. */
function dude_feriado_excluir(PDO $pdo, string $data, string $loja): void
{
    $pdo->prepare("DELETE FROM portal_dude_feriado WHERE data = ? AND loja = ?")->execute([$data, $loja]);
}

/* ───────────────────────────── Catálogo da Central de Alertas ───────────────────────────── */

/** @return array ocorrências: cada uma ['chave','titulo','loja','categoria','detalhe'] */
function dude_check_tipo(PDO $pdo, string $tipo): array
{
    $st = $pdo->prepare(
        "SELECT chave, nome, endereco, loja, categoria, detalhe, atualizado_em
         FROM portal_dude_estado WHERE tipo = ? AND status = 'down'
         ORDER BY loja, atualizado_em"
    );
    $st->execute([$tipo]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $desde = date('H:i', strtotime($r['atualizado_em']));
        $out[] = [
            'chave'     => 'dude:' . $tipo . ':' . $r['chave'],
            'titulo'    => $r['nome'] !== '' ? $r['nome'] : $r['chave'],
            'loja'      => (string) $r['loja'],
            'categoria' => (string) $r['categoria'],
            'detalhe'   => trim(trim($r['endereco'] . ' · ' . $r['detalhe'], ' ·')) . " (desde {$desde})",
        ];
    }
    return $out;
}

/**
 * Único dos 4 tipos "down atual" que respeita o horário por categoria
 * (ex.: PDV só alerta 06:00-22:00) — decisão do usuário: Link/Latência/
 * Serviço sempre alertam, independente de horário.
 */
function alerta_check_dude_device(PDO $pdo, array $p): array
{
    $ocorr = dude_check_tipo($pdo, 'device');
    return array_values(array_filter(
        $ocorr,
        fn($o) => dude_categoria_no_horario($pdo, $o['categoria'], $o['loja']) && !dude_feriado_hoje($pdo, $o['loja'])
    ));
}
function alerta_check_dude_link(PDO $pdo, array $p): array     { return dude_check_tipo($pdo, 'link'); }
function alerta_check_dude_latencia(PDO $pdo, array $p): array { return dude_check_tipo($pdo, 'latencia'); }
function alerta_check_dude_service(PDO $pdo, array $p): array  { return dude_check_tipo($pdo, 'service'); }

/**
 * @return array ocorrências de dispositivos "up" continuamente além do
 * limite (`ligado_horas_max`) configurado pra categoria deles. Genérico —
 * qualquer categoria com esse campo preenchido entra na checagem (não é
 * hardcoded pra "PCs Retaguarda"; o usuário configura qual categoria quer).
 */
function alerta_check_dude_ligado_muito_tempo(PDO $pdo, array $p): array
{
    $st = $pdo->query("
        SELECT e.chave, e.nome, e.loja, e.categoria, e.atualizado_em, c.ligado_horas_max
        FROM portal_dude_estado e
        JOIN portal_dude_categoria_config c ON c.categoria = e.categoria
        WHERE e.tipo = 'device' AND e.status = 'up'
          AND c.ligado_horas_max IS NOT NULL
          AND e.atualizado_em <= NOW() - INTERVAL c.ligado_horas_max HOUR
        ORDER BY e.loja, e.categoria
    ");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = max(0, (int) floor((time() - strtotime($r['atualizado_em'])) / 3600));
        $out[] = [
            'chave'     => 'dude:ligado:' . $r['chave'],
            'titulo'    => $r['nome'] !== '' ? $r['nome'] : $r['chave'],
            'loja'      => (string) $r['loja'],
            'categoria' => (string) $r['categoria'],
            'detalhe'   => "ligado continuamente há {$h}h (limite: {$r['ligado_horas_max']}h)",
        ];
    }
    return $out;
}

/** @return array ocorrências: 0 ou 1 item (watchdog geral, não por dispositivo) */
function alerta_check_dude_sem_contato(PDO $pdo, array $p): array
{
    $ultima = dude_ultima_notificacao($pdo);
    if ($ultima === null) return []; // nunca recebeu nada ainda -> não é "silêncio", é "nunca configurado"

    $horas     = (int) ($p['horas'] ?? 6);
    $decorrido = time() - strtotime($ultima);
    if ($decorrido < $horas * 3600) return [];

    $h = max(0, (int) floor($decorrido / 3600));
    return [[
        'chave'   => 'dude:sem_contato',
        'titulo'  => 'The Dude não está notificando',
        'loja'    => '',
        'detalhe' => "última notificação há {$h}h — verifique o Dude ou a rede até ele",
    ]];
}

/**
 * innerHTML do corpo da seção — reusada pelos 5 tipos do Dude. Agrupa por
 * categoria (ex.: PDV, Servidor) e, dentro de cada categoria, por loja —
 * cada notification do Dude manda os dois valores fixos (mapa = loja,
 * grupo de equipamento = categoria). Tipos que nunca preenchem nenhum dos
 * dois (ex.: dude_sem_contato) caem na tabela simples.
 */
function alerta_render_dude(array $ocorr, string $tipo = ''): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nada fora do ar.</div>';
    }
    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $temAgrupamento = (bool) array_filter(
        $ocorr,
        fn($o) => trim((string) ($o['categoria'] ?? '')) !== '' || trim((string) ($o['loja'] ?? '')) !== ''
    );

    if (!$temAgrupamento) {
        $out = '<table><thead><tr><th>Dispositivo/Enlace</th><th>Detalhe</th><th></th></tr></thead><tbody>';
        foreach ($ocorr as $o) {
            $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
                  . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td>'
                  . '<td>' . alerta_botao_dispensar_html($tipo, (string) $o['chave']) . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }

    $porCategoria = [];
    foreach ($ocorr as $o) {
        $cat  = trim((string) ($o['categoria'] ?? '')) ?: 'Sem categoria';
        $loja = trim((string) ($o['loja'] ?? '')) ?: 'Sem loja';
        $porCategoria[$cat][$loja][] = $o;
    }
    ksort($porCategoria, SORT_NATURAL | SORT_FLAG_CASE);

    $out = '';
    foreach ($porCategoria as $cat => $porLoja) {
        ksort($porLoja, SORT_NATURAL | SORT_FLAG_CASE);
        $totalCat = array_sum(array_map('count', $porLoja));
        $out .= '<div class="loja-h" style="font-size:.9rem"><i class="bi bi-tag-fill"></i> ' . $H($cat)
              . ' <span style="color:#9ca3af;font-weight:400">(' . $totalCat . ')</span></div>';
        foreach ($porLoja as $loja => $itens) {
            $out .= '<div class="loja-h" style="margin-left:1rem;font-size:.82rem"><i class="bi bi-shop"></i> ' . $H($loja)
                  . ' <span style="color:#9ca3af;font-weight:400">(' . count($itens) . ')</span></div><table><tbody>';
            foreach ($itens as $o) {
                $out .= '<tr><td style="font-weight:600">' . $H($o['titulo']) . '</td>'
                      . '<td style="color:#6b7280">' . $H($o['detalhe']) . '</td>'
                      . '<td>' . alerta_botao_dispensar_html($tipo, (string) $o['chave']) . '</td></tr>';
            }
            $out .= '</tbody></table>';
        }
    }
    return $out;
}

/* ───────────────────────────── Verificação direta (ping) ───────────────────────────── */

/**
 * Verifica se um IP responde (ICMP + fallback TCP) — mesmo mecanismo de
 * ping.php. Cobre o caso do Dude travar o acompanhamento de um device
 * específico sem avisar: o portal confere direto, sem depender do Dude.
 * Nunca lança. Suporta seam de teste via $GLOBALS['__dude_ping_fake'].
 */
function dude_ping_ip(string $ip): bool
{
    if (isset($GLOBALS['__dude_ping_fake']) && is_callable($GLOBALS['__dude_ping_fake'])) {
        return (bool) ($GLOBALS['__dude_ping_fake'])($ip);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

    $ipSafe = escapeshellarg($ip);
    $isWin  = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmd    = $isWin ? "ping -n 1 -w 1000 $ipSafe" : "ping -c 1 -W 1 $ipSafe 2>/dev/null";
    @exec($cmd, $out, $code);
    if ($code === 0) return true;

    foreach ([445, 3389, 135, 139, 80, 443, 22] as $porta) {
        $conn = @fsockopen($ip, $porta, $errno, $errstr, 0.5);
        if ($conn !== false) { fclose($conn); return true; }
    }
    return false;
}

/**
 * Confere via ping direto os devices 'down' há pelo menos $minutosMin
 * minutos (0 = todos os down agora — usado pelo botão manual "Atualizar").
 * Corrige pra 'up' os que responderem. 1 device falhando não trava os outros.
 *
 * @return array chaves corrigidas
 */
function dude_verificar_down(PDO $pdo, int $minutosMin = 0): array
{
    $st = $pdo->prepare(
        "SELECT chave, endereco FROM portal_dude_estado
         WHERE tipo = 'device' AND status = 'down' AND endereco != ''
           AND TIMESTAMPDIFF(MINUTE, atualizado_em, NOW()) >= ?"
    );
    $st->execute([$minutosMin]);

    $corrigidos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            if (dude_ping_ip((string) $r['endereco'])) {
                $pdo->prepare(
                    "UPDATE portal_dude_estado SET status='up', detalhe='confirmado via ping direto do portal', atualizado_em=NOW()
                     WHERE tipo='device' AND chave=?"
                )->execute([$r['chave']]);
                $corrigidos[] = $r['chave'];
            }
        } catch (\Throwable $e) {
            // 1 device falhando no ping nao pode travar a verificacao dos outros
        }
    }
    return $corrigidos;
}

/**
 * Confere via ping direto os devices 'up' cuja categoria tem ligado_horas_max
 * configurado e que não atualizam há pelo menos $horasMin horas — mesmo
 * incidente do Dude travar notificação de um device específico (visto em
 * central-alertas-dude-travado.md), só que travado no lado "ligado" em vez
 * do lado "caído": o device caiu de verdade mas o webhook de "down" nunca
 * chegou, então o estado antigo 'up' fica preso e dispara falso positivo em
 * "ligado há muito tempo" em vez do alerta correto de "device caído".
 * Corrige pra 'down' os que não responderem. 1 device falhando não trava os outros.
 *
 * @return array chaves corrigidas
 */
function dude_verificar_up(PDO $pdo, int $horasMin = 3): array
{
    $st = $pdo->prepare(
        "SELECT e.chave, e.endereco FROM portal_dude_estado e
         JOIN portal_dude_categoria_config c ON c.categoria = e.categoria
         WHERE e.tipo = 'device' AND e.status = 'up' AND e.endereco != ''
           AND c.ligado_horas_max IS NOT NULL
           AND TIMESTAMPDIFF(HOUR, e.atualizado_em, NOW()) >= ?"
    );
    $st->execute([$horasMin]);

    $corrigidos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            if (!dude_ping_ip((string) $r['endereco'])) {
                $pdo->prepare(
                    "UPDATE portal_dude_estado SET status='down', detalhe='sem resposta a ping direto do portal', atualizado_em=NOW()
                     WHERE tipo='device' AND chave=?"
                )->execute([$r['chave']]);
                $corrigidos[] = $r['chave'];
            }
        } catch (\Throwable $e) {
            // 1 device falhando no ping nao pode travar a verificacao dos outros
        }
    }
    return $corrigidos;
}

/** Chamado pelo worker a cada passada — devices down há 30+ min (evita pingar toda hora à toa)
 *  e devices 'up' travados há 3+ horas (lado espelhado do mesmo incidente). */
function dude_gatilho_verificar_ping(PDO $pdo): void
{
    dude_verificar_down($pdo, 30);
    dude_verificar_up($pdo, 3);
}
