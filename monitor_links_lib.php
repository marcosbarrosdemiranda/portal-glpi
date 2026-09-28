<?php
/**
 * monitor_links_lib.php — Monitor de rede, etapa 3c: qual link de internet
 * cada loja está usando (ex.: Lj 030 mikrotik x starlink).
 *
 * Fonte: Status → Gateways de cada pfSense cadastrado em pfsense_lojas.php
 * (o próprio pfSense já monitora cada link com dpinger). O portal loga com o
 * usuário/senha do cofre, lê a tabela a cada 1 min e guarda em
 * portal_monitor_links. Loja com a VPN fora é pulada (a etapa 3b já avisa).
 *
 * Roteiro: Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md
 * Funções puras: sem HTML de página, sem session_start, sem header().
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';  // GLPI_APP_TOKEN — chave do cofre
require_once __DIR__ . '/monitor_lib.php';    // monitor_vpn_fora()
require_once __DIR__ . '/wpp/db.php';         // wpp_cfg_get()/wpp_cfg_set()

const MONITOR_LINKS_INTERVALO_SEG = 60;

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    // principal = link que estava como padrão (default) na 1ª leitura da loja
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_monitor_links (
        loja          VARCHAR(60)  NOT NULL,
        nome          VARCHAR(60)  NOT NULL,
        descricao     VARCHAR(120) NOT NULL DEFAULT '',
        gateway       VARCHAR(45)  NOT NULL DEFAULT '',
        status        ENUM('up','down','alerta') NOT NULL,
        rtt_ms        DECIMAL(8,2) NULL,
        perda         DECIMAL(5,1) NULL,
        padrao        TINYINT(1)   NOT NULL DEFAULT 0,
        principal     TINYINT(1)   NOT NULL DEFAULT 0,
        status_desde  DATETIME     NOT NULL,
        atualizado_em DATETIME     NOT NULL,
        PRIMARY KEY (loja, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Regras puras ───────────────────────────── */

/**
 * Lê a tabela do status_gateways.php (pfSense 2.7). Status pela cor da
 * célula: bg-success = no ar, bg-danger = fora, bg-warning = perda/latência.
 *
 * @return array cada: nome, gateway, monitor (null = não monitorado), rtt_ms, perda, status, padrao, descricao
 */
function monitor_links_parse(string $html): array
{
    if (!preg_match('/<tbody>(.*?)<\/tbody>/is', $html, $tb)) return [];
    $txt = fn(string $s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES)));
    $out = [];
    preg_match_all('/<tr>(.*?)<\/tr>/is', $tb[1], $trs);
    foreach ($trs[1] as $tr) {
        preg_match_all('/<td([^>]*)>(.*?)<\/td>/is', $tr, $tds, PREG_SET_ORDER);
        if (count($tds) < 8) continue;
        $nomeCel = $tds[0][2];
        $monitor = $txt($tds[2][2]);
        $cls     = $tds[6][1];
        $status  = str_contains($cls, 'bg-danger') ? 'down' : (str_contains($cls, 'bg-warning') ? 'alerta' : 'up');
        if ($monitor === '(unmonitored)' || $monitor === '') { $monitor = null; $status = 'nao_monitorado'; }
        $rtt   = $txt($tds[3][2]);
        $perda = $txt($tds[5][2]);
        $out[] = [
            'nome'      => $txt(preg_replace('/<strong>\s*\(default\)\s*<\/strong>/i', '', $nomeCel)),
            'gateway'   => $txt($tds[1][2]),
            'monitor'   => $monitor,
            'rtt_ms'    => $rtt !== '' ? (float) $rtt : null,
            'perda'     => $perda !== '' ? (float) $perda : null,
            'status'    => $status,
            'padrao'    => (bool) preg_match('/\(default\)/i', $nomeCel),
            'descricao' => $txt($tds[7][2]),
        ];
    }
    return $out;
}

/** Só links de internet: o pfSense monitora e não é VPN (nome/descrição com "vpn"). */
function monitor_links_internet(array $gws): array
{
    return array_values(array_filter($gws, fn($g) => $g['monitor'] !== null
        && stripos($g['nome'], 'vpn') === false && stripos($g['descricao'], 'vpn') === false));
}

/**
 * Ocorrências da Central — PURA. Link fora (vermelho) = 1 por link. Loja
 * saindo por um link que não é o principal = 1 por loja. Amarelo (perda/
 * latência) só aparece na tela, não alerta.
 */
function monitor_links_ocorrencias(array $links): array
{
    $out = [];
    $porLoja = [];
    foreach ($links as $l) $porLoja[$l['loja']][] = $l;
    foreach ($porLoja as $loja => $ls) {
        $principal = null;
        $emUso = null;
        foreach ($ls as $l) {
            if ((int) $l['principal']) $principal = $l;
            if ((int) $l['padrao']) $emUso = $l;
            if ($l['status'] === 'down') {
                $out[] = [
                    'chave'     => "rede_link:{$loja}:{$l['nome']}",
                    'titulo'    => "Link {$l['nome']} fora",
                    'loja'      => (string) $loja,
                    'categoria' => 'Links de internet',
                    'detalhe'   => "{$l['descricao']} · fora desde " . date('H:i', strtotime($l['status_desde'])),
                ];
            }
        }
        if ($principal && $emUso && $emUso['nome'] !== $principal['nome']) {
            $out[] = [
                'chave'     => "rede_link:{$loja}:reserva",
                'titulo'    => "Saindo pelo {$emUso['nome']}",
                'loja'      => (string) $loja,
                'categoria' => 'Links de internet',
                'detalhe'   => "link principal ({$principal['nome']}) não está em uso · desde " . date('H:i', strtotime($emUso['status_desde'])),
            ];
        }
    }
    return $out;
}

/* ───────────────────────────── pfSense ───────────────────────────── */

/** Mesma chave/formato do cofre de pfsense_proxy.php. */
function monitor_links_senha(string $enc): string
{
    $key = hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais');
    $raw = base64_decode($enc);
    return openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, 0, substr($raw, 0, 16)) ?: '';
}

/**
 * HTML do Status → Gateways de 1 pfSense. Reaproveita o cookie da sessão
 * anterior (evita 1 login por minuto no log do pfSense); só loga de novo se
 * voltar a tela de login. null = não conseguiu (pfSense fora/senha errada).
 */
function monitor_links_ler_pfsense(array $pf): ?string
{
    $ck = sys_get_temp_dir() . '/portal_pf_links_' . (int) $pf['id'] . '.txt';
    $base = [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_COOKIEJAR => $ck,
             CURLOPT_COOKIEFILE => $ck, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
             CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'Mozilla/5.0'];
    $get = function (string $url, ?array $post = null) use ($base): string {
        $ch = curl_init();
        curl_setopt_array($ch, $base + [CURLOPT_URL => $url]
            + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)] : []));
        $r = curl_exec($ch);
        curl_close($ch);
        return is_string($r) ? $r : '';
    };

    $url = "https://{$pf['ip']}/status_gateways.php";
    $pag = $get($url);
    if ($pag !== '' && stripos($pag, 'usernamefld') === false) return $pag;

    // login (o CSRF vem na própria tela de login)
    if ($pag === '') $pag = $get("https://{$pf['ip']}/");
    if ($pag === '') return null;
    $csrf = preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/i', $pag, $m) ? $m[1]
          : (preg_match('/__csrf_magic[^v]*value="([^"]+)"/is', $pag, $m) ? $m[1] : '');
    $get("https://{$pf['ip']}/index.php", ['usernamefld' => $pf['usuario'], 'passwordfld' => monitor_links_senha((string) $pf['senha_enc']),
        'login' => 'Login', '__csrf_magic' => html_entity_decode($csrf, ENT_QUOTES)]);
    $pag = $get($url);
    return ($pag !== '' && stripos($pag, 'usernamefld') === false) ? $pag : null;
}

/* ───────────────────────────── Banco ───────────────────────────── */

/** Grava os links de internet de 1 loja; status_desde só muda quando o status muda. */
function monitor_links_gravar(PDO $pdo, string $loja, array $links, string $agora): void
{
    $temPrincipal = (bool) $pdo->query("SELECT 1 FROM portal_monitor_links WHERE loja = " . $pdo->quote($loja) . " AND principal = 1")->fetchColumn();
    $st = $pdo->prepare(
        "INSERT INTO portal_monitor_links (loja, nome, descricao, gateway, status, rtt_ms, perda, padrao, principal, status_desde, atualizado_em)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), gateway = VALUES(gateway),
             status_desde = IF(status <> VALUES(status) OR padrao <> VALUES(padrao), VALUES(status_desde), status_desde),
             status = VALUES(status), rtt_ms = VALUES(rtt_ms), perda = VALUES(perda), padrao = VALUES(padrao),
             principal = IF(VALUES(principal) = 1, 1, principal), atualizado_em = VALUES(atualizado_em)"
    );
    foreach ($links as $l) {
        $principal = !$temPrincipal && $l['padrao'] ? 1 : 0; // 1ª leitura: o padrão vira o principal
        $st->execute([$loja, $l['nome'], $l['descricao'], $l['gateway'], $l['status'], $l['rtt_ms'], $l['perda'],
                      $l['padrao'] ? 1 : 0, $principal, $agora, $agora]);
    }
    // link que sumiu do pfSense (renomeado/removido) sai da tabela
    $nomes = array_column($links, 'nome');
    if ($nomes) {
        $pdo->prepare("DELETE FROM portal_monitor_links WHERE loja = ? AND nome NOT IN (" . implode(',', array_fill(0, count($nomes), '?')) . ")")
            ->execute(array_merge([$loja], $nomes));
    }
}

/** Rodada: a cada 1 min, lê cada pfSense ativo. Chamada pelo monitor_gatilho. */
function monitor_links_rodada(PDO $pdo, bool $forcar = false): array
{
    $ult = (string) wpp_cfg_get('monitor_links_ultima', '');
    if (!$forcar && $ult !== '' && time() - strtotime($ult) < MONITOR_LINKS_INTERVALO_SEG - 5) return [];
    $agora = (string) $pdo->query("SELECT NOW()")->fetchColumn();
    wpp_cfg_set('monitor_links_ultima', $agora);

    $vpnFora = monitor_vpn_fora($pdo);
    $res = [];
    foreach ($pdo->query("SELECT id, loja, ip, usuario, senha_enc FROM portal_pfsense_lojas WHERE ativo = 1")->fetchAll(PDO::FETCH_ASSOC) as $pf) {
        $loja = monitor_loja_curta((string) $pf['loja']);
        if (isset($vpnFora[$loja])) { $res[$loja] = 'vpn fora'; continue; }
        try {
            $html = monitor_links_ler_pfsense($pf);
            if ($html === null) { $res[$loja] = 'sem acesso'; continue; }
            $links = monitor_links_internet(monitor_links_parse($html));
            monitor_links_gravar($pdo, $loja, $links, $agora);
            $res[$loja] = count($links) . ' links';
        } catch (\Throwable $e) {
            $res[$loja] = 'erro: ' . $e->getMessage(); // 1 pfSense com problema não trava os outros
        }
    }
    return $res;
}

function monitor_links_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_monitor_links ORDER BY loja, principal DESC, nome")->fetchAll(PDO::FETCH_ASSOC);
}

/** Check da Central (tipo rede_link). Loja com VPN fora não entra (dado velho; a VPN já avisa). */
function alerta_check_rede_link(PDO $pdo, array $p): array
{
    $vpnFora = monitor_vpn_fora($pdo);
    return monitor_links_ocorrencias(array_values(array_filter(monitor_links_listar($pdo), fn($l) => !isset($vpnFora[$l['loja']]))));
}
