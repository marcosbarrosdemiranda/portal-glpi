<?php
/**
 * github_cache.php — cache curto das respostas da API do GitHub.
 * Sem cache, projetos.php faz ~4 chamadas por repo a cada load (lento + rate limit).
 * Requer $pdo (agenda/db.php). Erros nunca são cacheados.
 *
 * TTLs em segundos — usados por projetos.php (request HTTP) E por
 * github_cache_worker.php (ciclo em background, sleep 600s/10min no
 * entrypoint do docker-compose). 900s dá folga sobre o intervalo do worker
 * pra nunca correr risco de expirar entre dois ciclos.
 */
const TTL_REPOS   = 900;
const TTL_DETALHE = 900;

function gh_cache_bootstrap(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_github_cache (
            chave     VARCHAR(190) PRIMARY KEY,
            payload   LONGTEXT,
            criado_em INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** Limpa todo o cache (usado pelo botão "Atualizar"). */
function gh_cache_limpar(PDO $pdo): void {
    gh_cache_bootstrap($pdo);
    $pdo->exec("DELETE FROM portal_github_cache");
}

/**
 * Retorna o valor cacheado de $chave se fresco; senão roda $fn(), guarda e retorna.
 * $fn deve devolver algo serializável em JSON. Um array com ['erro'=>...] não é cacheado.
 */
function gh_cached(PDO $pdo, string $chave, int $ttl, callable $fn) {
    gh_cache_bootstrap($pdo);

    $st = $pdo->prepare("SELECT payload, criado_em FROM portal_github_cache WHERE chave = ?");
    $st->execute([$chave]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && (time() - (int)$row['criado_em']) < $ttl) {
        $v = json_decode($row['payload'], true);
        if ($v !== null) return $v;
    }

    $v = $fn();
    if (!(is_array($v) && isset($v['erro']))) {
        $pdo->prepare("INSERT INTO portal_github_cache (chave, payload, criado_em) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE payload = VALUES(payload), criado_em = VALUES(criado_em)")
            ->execute([$chave, json_encode($v, JSON_UNESCAPED_UNICODE), time()]);
    }
    return $v;
}

// ── Leitura de PRD de projeto (Docs/wiki/projects/<repo>/*.md) ─────────────
// Movidas de projetos.php (fix/projetos-github-cache-worker) pra que
// github_cache_worker.php (CLI, sem a guarda de sessão HTTP de projetos.php)
// consiga chamar docPrdDoProjeto() sem dar require no projetos.php inteiro.
// Lógica interna 100% preservada — só mudou de arquivo.

function parseDataBR(string $d): int {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m))
        return mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
    return 0;
}

function parsePrazoRange(string $prazo): array {
    // Duas datas separadas por qualquer coisa não-numérica (→, —, >, espaço, etc.)
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\D+(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [parseDataBR($m[1]), parseDataBR($m[2])];
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $prazo, $m))
        return [0, parseDataBR($m[1])];
    return [0, 0];
}

function parseProjeto(string $filepath): ?array {
    $content = @file_get_contents($filepath);
    if (!$content) return null;

    $lines  = explode("\n", str_replace("\r", '', $content));
    $proj   = [
        'titulo'    => '',
        'objetivo'  => '',
        'equipe'    => '',
        'prazo'     => '',
        'repo'      => '',
        'modulos'   => [],
        'cronograma'=> [],
    ];

    $modo        = 'header';
    $moduloAtual = null;
    $subSecao    = null;
    $inCodeBlock = false;

    foreach ($lines as $line) {
        $l = rtrim($line);

        if (preg_match('/^```/', $l)) { $inCodeBlock = !$inCodeBlock; continue; }

        if ($inCodeBlock) {
            if ($modo === 'cronograma' &&
                preg_match('/Semana\s*(\d+)\s*\(([^)]+)\)\s*[→\-]+\s*(.+)/u', $l, $m)) {
                $proj['cronograma'][] = [
                    'semana'   => 'S'.$m[1],
                    'periodo'  => trim($m[2]),
                    'descricao'=> trim($m[3]),
                ];
            }
            continue;
        }

        if (preg_match('/^# (.+)$/u', $l, $m)) {
            $proj['titulo'] = trim($m[1]); $modo = 'header'; continue;
        }
        if ($modo === 'header' && preg_match('/^> \*\*(.+?):\*\*\s*(.+)/u', $l, $m)) {
            $k = mb_strtolower($m[1]);
            if (str_contains($k,'objetivo'))    $proj['objetivo'] = $m[2];
            elseif (str_contains($k,'equipe'))  $proj['equipe']   = $m[2];
            elseif (str_contains($k,'prazo'))   $proj['prazo']    = $m[2];
            elseif (str_contains($k,'reposit')) $proj['repo']     = $m[2];
            continue;
        }
        if (preg_match('/^## (.+)$/u', $l, $m)) {
            if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;
            $nome = trim($m[1]);
            if (preg_match('/cronograma/iu', $nome))      { $modo='cronograma'; $moduloAtual=null; }
            elseif (preg_match('/progresso/iu', $nome))   { $modo='tabprog';    $moduloAtual=null; }
            else {
                $modo = 'modulo';
                $moduloAtual = ['nome'=>$nome,'descricao'=>'','prazo'=>'','tarefas'=>[]];
                $subSecao = null;
            }
            continue;
        }
        if ($modo === 'modulo' && preg_match('/^### (.+)$/u', $l, $m)) {
            $subSecao = trim($m[1]); continue;
        }
        if ($modo === 'modulo' && $moduloAtual !== null) {
            if (preg_match('/^- \[x\] (.+)/iu', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>true,  'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^- \[ \] (.+)/u', $l, $m))
                $moduloAtual['tarefas'][] = ['done'=>false, 'texto'=>$m[1], 'sub'=>$subSecao];
            elseif (preg_match('/^> \*\*Prazo:\*\*\s*(.+)/ui', $l, $m)) {
                $v = trim($m[1]);
                // 4+ barras = duas datas = prazo do projeto; 2 barras = prazo do módulo
                if (!$proj['prazo'] && substr_count($v, '/') >= 4)
                    $proj['prazo'] = $v;
                else
                    $moduloAtual['prazo'] = $v;
            }
            elseif (preg_match('/^> (.+)/u', $l, $m) && !count($moduloAtual['tarefas']))
                $moduloAtual['descricao'] = trim($m[1]);
        }
    }
    if ($moduloAtual !== null) $proj['modulos'][] = $moduloAtual;

    $tot = $done = 0;
    foreach ($proj['modulos'] as &$mod) {
        $mt = count($mod['tarefas']);
        $md = count(array_filter($mod['tarefas'], fn($t) => $t['done']));
        $mod['pct']  = $mt > 0 ? round($md / $mt * 100) : 0;
        $mod['done'] = $md;
        $mod['tot']  = $mt;
        $tot  += $mt;
        $done += $md;
    }
    unset($mod);

    $proj['pct']   = $tot > 0 ? round($done / $tot * 100) : 0;
    $proj['done']  = $done;
    $proj['total'] = $tot;

    return $proj['titulo'] ? $proj : null;
}

/**
 * Retorna o PRD parseado de um projeto (pasta = nome do repo), SÓ o PRD —
 * sem mesclar logs de sessão / ADRs. Fonte pro card e pra Apresentação.
 */
function docPrdDoProjeto(string $repoNome): ?array {
    static $cache = [];
    $chave = mb_strtolower($repoNome);
    if (array_key_exists($chave, $cache)) return $cache[$chave];

    $base = __DIR__ . '/Docs/wiki/projects';
    $cfg  = __DIR__ . '/config_projetos.local.php';
    if (file_exists($cfg)) { require_once $cfg; if (defined('ORIGEM_PROJETOS') && is_dir(ORIGEM_PROJETOS)) $base = ORIGEM_PROJETOS; }

    $sub = null;
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sp) {
        if (strcasecmp(basename($sp), $repoNome) === 0) { $sub = $sp; break; }
    }
    if (!$sub) return $cache[$chave] = null;

    $cands = glob($sub . '/*.md') ?: [];
    $norm  = fn($s) => mb_strtolower(str_replace(['-', '_', ' '], '', $s));
    $pn    = $norm($repoNome);
    $main  = null;
    // 1) "<repo>-prd.md"  2) qualquer "*-prd.md" que contenha o nome do repo
    // 3) "prd.md" / "PRD-<algo>.md"  4) arquivo com o nome do repo  5) README/index
    foreach ($cands as $md) if ($norm(basename($md, '.md')) === $pn . 'prd') { $main = $md; break; }
    if (!$main) foreach ($cands as $md) { $nb = $norm(basename($md, '.md'));
        if (str_ends_with($nb, 'prd') && str_contains($nb, $pn)) { $main = $md; break; } }
    if (!$main) foreach ($cands as $md) { $nb = $norm(basename($md, '.md'));
        if ($nb === 'prd' || str_starts_with($nb, 'prd')) { $main = $md; break; } }
    if (!$main) foreach ($cands as $md) if (str_contains($norm(basename($md, '.md')), $pn)) { $main = $md; break; }
    if (!$main) foreach ($cands as $md) { $b = mb_strtolower(basename($md, '.md')); if ($b === 'readme' || $b === 'index') { $main = $md; break; } }
    if (!$main) $main = $cands[0] ?? null;
    if (!$main) return $cache[$chave] = null;

    return $cache[$chave] = parseProjeto($main);
}
