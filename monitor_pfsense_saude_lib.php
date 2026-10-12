<?php
/**
 * monitor_pfsense_saude_lib.php — Monitor de rede, Fase 2 do box pfSense
 * expandido no chamado recorrente "Firewal, Unifi e Comunicação Lojas":
 * memória, carga do sistema (proxy de saúde de CPU) e status de
 * pfBlockerNG/Snort de cada pfSense de loja.
 *
 * Fonte: mesmo login HTTPS (usuário/senha do cofre, portal_pfsense_lojas)
 * já usado por pfsense_proxy.php e monitor_links_lib.php — NÃO precisa de
 * SSH (confirmado via sonda real em 2026-10-12, Lj 030): status_services.php
 * já lista pfb_dnsbl/pfb_filter/snort com ícone verde/vermelho (igual
 * status_gateways.php), e getstats.php devolve mem% e carga do sistema.
 * CPU instantâneo (%) não sai por esse caminho (testado: campo sempre
 * vazio mesmo com 3 chamadas em 20s) — por isso usa-se carga do sistema
 * (load average) como proxy de saúde de CPU, sem SSH.
 *
 * Roteiro: Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md
 * Funções puras: sem HTML de página, sem session_start, sem header().
 */

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/agenda/config.php';  // GLPI_APP_TOKEN — chave do cofre
require_once __DIR__ . '/monitor_lib.php';    // monitor_vpn_fora(), monitor_loja_curta()
require_once __DIR__ . '/wpp/db.php';         // wpp_cfg_get()/wpp_cfg_set()

const MONITOR_PFSENSE_SAUDE_INTERVALO_SEG = 60;

// cria a tabela ao incluir (padrão do portal)
(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_monitor_pfsense_saude (
        loja           VARCHAR(60)  NOT NULL,
        mem_pct        DECIMAL(5,1) NULL,
        carga_1m       DECIMAL(6,2) NULL,
        carga_5m       DECIMAL(6,2) NULL,
        carga_15m      DECIMAL(6,2) NULL,
        pfblocker      ENUM('ativo','parcial','inativo','nao_instalado') NOT NULL DEFAULT 'nao_instalado',
        snort          ENUM('ativo','inativo','nao_instalado') NOT NULL DEFAULT 'nao_instalado',
        atualizado_em  DATETIME     NOT NULL,
        PRIMARY KEY (loja)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── Regras puras ───────────────────────────── */

/**
 * Lê os serviços de interesse (pfb_dnsbl, pfb_filter, snort) do
 * status_services.php (pfSense 2.7). Status pelo ícone: text-success =
 * rodando, text-danger = parado. Serviço ausente no HTML (pacote não
 * instalado) simplesmente não entra no array de retorno.
 *
 * @return array<string,bool> nome do serviço => true (rodando) / false (parado)
 */
function monitor_pfsense_saude_parse_servicos(string $html): array
{
    $alvo = ['pfb_dnsbl', 'pfb_filter', 'snort'];
    $out = [];
    preg_match_all('/<tr>(.*?)<\/tr>/is', $html, $trs);
    foreach ($trs[1] as $tr) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $tds, PREG_SET_ORDER);
        if (count($tds) < 3) continue;
        $nome = trim(preg_replace('/\s+/', ' ', strip_tags($tds[0][1])));
        if (!in_array($nome, $alvo, true)) continue;
        $out[$nome] = str_contains($tds[2][1], 'text-success');
    }
    return $out;
}

/**
 * Lê mem% e carga do sistema do getstats.php (pfSense 2.7) — string com
 * campos separados por "|": campo[2] = mem%, campo[8] = "carga1, carga5, carga15".
 *
 * @return array{mem_pct:?float,carga_1m:?float,carga_5m:?float,carga_15m:?float}
 */
function monitor_pfsense_saude_parse_recursos(string $getstats): array
{
    $campos = explode('|', trim($getstats));
    $mem = isset($campos[2]) && $campos[2] !== '' ? (float) $campos[2] : null;
    $cargas = [null, null, null];
    if (!empty($campos[8])) {
        $partes = array_map('trim', explode(',', $campos[8]));
        foreach ($partes as $i => $p) {
            if ($i > 2) break;
            $cargas[$i] = $p !== '' ? (float) $p : null;
        }
    }
    return ['mem_pct' => $mem, 'carga_1m' => $cargas[0], 'carga_5m' => $cargas[1], 'carga_15m' => $cargas[2]];
}

/**
 * Junta serviços + recursos num resumo por loja. pfBlockerNG: ativo = os 2
 * serviços (dnsbl+filter) rodando, parcial = só 1, inativo = nenhum,
 * não instalado = nenhuma das 2 linhas presente no HTML. Snort: ativo/
 * inativo/não instalado conforme presença e status da linha.
 */
function monitor_pfsense_saude_classificar(array $servicos, array $recursos): array
{
    $temDnsbl = array_key_exists('pfb_dnsbl', $servicos);
    $temFilter = array_key_exists('pfb_filter', $servicos);
    if (!$temDnsbl && !$temFilter) {
        $pfblocker = 'nao_instalado';
    } else {
        $rodando = (int) ($servicos['pfb_dnsbl'] ?? false) + (int) ($servicos['pfb_filter'] ?? false);
        $total = (int) $temDnsbl + (int) $temFilter;
        $pfblocker = $rodando === 0 ? 'inativo' : ($rodando === $total ? 'ativo' : 'parcial');
    }

    $snort = !array_key_exists('snort', $servicos) ? 'nao_instalado' : ($servicos['snort'] ? 'ativo' : 'inativo');

    return $recursos + ['pfblocker' => $pfblocker, 'snort' => $snort];
}

/**
 * Texto pronto com a saúde de cada pfSense, pra acrescentar no resumo do
 * chamado recorrente "Firewal, Unifi e Comunicação Lojas" (ver
 * monitor_resumo_ajax.php), junto da latência e dos links. Faixas de
 * mem%/carga são uma aproximação simples (não leva em conta nº de núcleos
 * por loja, que varia por hardware) — suficiente pra uma leitura rápida no
 * chamado diário, não é alerta automático.
 *
 * @param array $linhas vindo de monitor_pfsense_saude_listar()
 * @param array<string,string> $lojasSemDado loja => motivo (ex.: 'atrás de MikroTik')
 */
function monitor_pfsense_saude_resumo_texto(array $linhas, array $lojasSemDado = []): string
{
    $porLoja = [];
    foreach ($linhas as $l) $porLoja[$l['loja']] = $l;

    $faixa = fn(?float $v, float $atencao, float $critico) => $v === null ? '⚪'
        : ($v > $critico ? '🔴' : ($v > $atencao ? '🟡' : '🟢'));
    $servMarca = fn(string $status, string $okTxt) => $status === 'ativo' ? "🟢 $okTxt"
        : ($status === 'parcial' ? "🟡 $okTxt parcial" : ($status === 'inativo' ? "🔴 $okTxt inativo" : "⚪ $okTxt não instalado"));

    $linhaLoja = function (array $l) use ($faixa, $servMarca) {
        $mem = $l['mem_pct'] ?? null;
        $carga = $l['carga_1m'] ?? null;
        $txtMem = $mem !== null ? $faixa($mem, 70, 85) . " mem {$mem}%" : '⚪ mem sem dado';
        $txtCarga = $carga !== null ? $faixa($carga, 1.0, 2.0) . " carga {$carga}" : '⚪ carga sem dado';
        return "$txtMem · $txtCarga · " . $servMarca($l['pfblocker'], 'pfBlockerNG') . ' · ' . $servMarca($l['snort'], 'Snort');
    };

    $todasLojas = array_unique(array_merge(array_keys($porLoja), array_keys($lojasSemDado)));
    sort($todasLojas, SORT_NATURAL | SORT_FLAG_CASE);

    if (!$todasLojas) {
        return 'Saúde dos pfSense: nenhuma loja monitorada.';
    }

    $out = "Saúde dos pfSense:\n";
    foreach ($todasLojas as $loja) {
        $out .= isset($porLoja[$loja])
            ? "- {$loja}: " . $linhaLoja($porLoja[$loja]) . "\n"
            : "- {$loja}: sem dado (" . ($lojasSemDado[$loja] ?? 'sem dado') . ")\n";
    }
    return rtrim($out);
}

/* ───────────────────────────── pfSense ───────────────────────────── */

/** Mesma chave/formato do cofre de pfsense_proxy.php. */
function monitor_pfsense_saude_senha(string $enc): string
{
    $key = hash('sha256', GLPI_APP_TOKEN . 'cofre_ti_gmais');
    $raw = base64_decode($enc);
    return openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, 0, substr($raw, 0, 16)) ?: '';
}

/**
 * HTML do status_services.php + texto do getstats.php de 1 pfSense. Login
 * HTTPS reaproveitando cookie próprio (não compartilha com
 * monitor_links_lib.php — cookie-jar separado, mesmo padrão de login).
 * null = não conseguiu (pfSense fora/senha errada).
 *
 * @return array{servicos:string,recursos:string}|null
 */
function monitor_pfsense_saude_ler_pfsense(array $pf): ?array
{
    $ck = sys_get_temp_dir() . '/portal_pf_saude_' . (int) $pf['id'] . '.txt';
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

    $urlServicos = "https://{$pf['ip']}/status_services.php";
    $svc = $get($urlServicos);
    $logado = $svc !== '' && stripos($svc, 'usernamefld') === false;

    if (!$logado) {
        $pag = $get("https://{$pf['ip']}/");
        if ($pag === '') return null;
        $csrf = preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/i', $pag, $m) ? $m[1]
              : (preg_match('/__csrf_magic[^v]*value="([^"]+)"/is', $pag, $m) ? $m[1] : '');
        $get("https://{$pf['ip']}/index.php", ['usernamefld' => $pf['usuario'], 'passwordfld' => monitor_pfsense_saude_senha((string) $pf['senha_enc']),
            'login' => 'Login', '__csrf_magic' => html_entity_decode($csrf, ENT_QUOTES)]);
        $svc = $get($urlServicos);
        $logado = $svc !== '' && stripos($svc, 'usernamefld') === false;
    }
    if (!$logado) return null;

    $recursos = $get("https://{$pf['ip']}/getstats.php");
    return ['servicos' => $svc, 'recursos' => $recursos];
}

/* ───────────────────────────── Banco ───────────────────────────── */

/** Grava a saúde de 1 loja. */
function monitor_pfsense_saude_gravar(PDO $pdo, string $loja, array $dados, string $agora): void
{
    $pdo->prepare(
        "INSERT INTO portal_monitor_pfsense_saude (loja, mem_pct, carga_1m, carga_5m, carga_15m, pfblocker, snort, atualizado_em)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE mem_pct = VALUES(mem_pct), carga_1m = VALUES(carga_1m), carga_5m = VALUES(carga_5m),
             carga_15m = VALUES(carga_15m), pfblocker = VALUES(pfblocker), snort = VALUES(snort), atualizado_em = VALUES(atualizado_em)"
    )->execute([$loja, $dados['mem_pct'], $dados['carga_1m'], $dados['carga_5m'], $dados['carga_15m'],
                $dados['pfblocker'], $dados['snort'], $agora]);
}

/** Rodada: a cada 1 min, lê cada pfSense ativo. Chamada pelo monitor_gatilho. */
function monitor_pfsense_saude_rodada(PDO $pdo, bool $forcar = false): array
{
    $ult = (string) wpp_cfg_get('monitor_pfsense_saude_ultima', '');
    if (!$forcar && $ult !== '' && time() - strtotime($ult) < MONITOR_PFSENSE_SAUDE_INTERVALO_SEG - 5) return [];
    $agora = (string) $pdo->query("SELECT NOW()")->fetchColumn();
    wpp_cfg_set('monitor_pfsense_saude_ultima', $agora);

    $vpnFora = monitor_vpn_fora($pdo);
    $res = [];
    foreach ($pdo->query("SELECT id, loja, ip, usuario, senha_enc FROM portal_pfsense_lojas WHERE ativo = 1")->fetchAll(PDO::FETCH_ASSOC) as $pf) {
        $loja = monitor_loja_curta((string) $pf['loja']);
        if (isset($vpnFora[$loja])) { $res[$loja] = 'vpn fora'; continue; }
        try {
            $lido = monitor_pfsense_saude_ler_pfsense($pf);
            if ($lido === null) { $res[$loja] = 'sem acesso'; continue; }
            $dados = monitor_pfsense_saude_classificar(
                monitor_pfsense_saude_parse_servicos($lido['servicos']),
                monitor_pfsense_saude_parse_recursos($lido['recursos'])
            );
            monitor_pfsense_saude_gravar($pdo, $loja, $dados, $agora);
            $res[$loja] = 'ok';
        } catch (\Throwable $e) {
            $res[$loja] = 'erro: ' . $e->getMessage(); // 1 pfSense com problema não trava os outros
        }
    }
    return $res;
}

function monitor_pfsense_saude_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_monitor_pfsense_saude ORDER BY loja")->fetchAll(PDO::FETCH_ASSOC);
}
