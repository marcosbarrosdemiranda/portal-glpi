<?php
/**
 * github_client.php — chamadas à API do GitHub (api.github.com)
 * Sem HTML/sessão — só HTTP + parsing. Usado por projetos.php.
 */

function github_testar_token(string $token): array {
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) return ['ok' => false, 'login' => null, 'msg' => 'Falha de conexão com o GitHub'];
    if ($code !== 200) return ['ok' => false, 'login' => null, 'msg' => 'Token inválido ou sem permissão (HTTP ' . $code . ')'];

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['login'])) return ['ok' => false, 'login' => null, 'msg' => 'Resposta inesperada do GitHub'];

    return ['ok' => true, 'login' => $data['login'], 'msg' => 'OK'];
}

function github_listar_repos(string $token): array {
    $repos  = [];
    $pagina = 1;
    do {
        $ch = curl_init('https://api.github.com/user/repos?affiliation=owner&per_page=100&sort=pushed&page=' . $pagina);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: portal-glpi',
            ],
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) return ['erro' => 'Falha de conexão com o GitHub'];
        if ($code !== 200) return ['erro' => 'GitHub retornou HTTP ' . $code];

        $pageData = json_decode($body, true);
        if (!is_array($pageData)) return ['erro' => 'Resposta inesperada do GitHub'];

        foreach ($pageData as $r) {
            if (!empty($r['fork'])) continue;
            $repos[] = [
                'nome'           => $r['name'] ?? '',
                'descricao'      => $r['description'] ?? '',
                'url'            => $r['html_url'] ?? '',
                'privado'        => !empty($r['private']),
                'linguagem'      => $r['language'] ?? '',
                'ultimo_push'    => $r['pushed_at'] ?? null,
                'issues_abertas' => (int)($r['open_issues_count'] ?? 0),
                'arquivado'      => !empty($r['archived']),
            ];
        }

        $pagina++;
        $continua = count($pageData) === 100;
    } while ($continua);

    return $repos;
}

function github_analisar_readme(string $token, string $owner, string $repo): array {
    $vazio = ['descricao' => '', 'progresso' => ['feitas' => 0, 'total' => 0, 'pct' => null]];

    $ch = curl_init('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/readme');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return $vazio;

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['content'])) return $vazio;

    $conteudo = base64_decode(str_replace("\n", '', $data['content']));
    if ($conteudo === false) return $vazio;

    $descricao = '';
    $feitas    = 0;
    $total     = 0;

    foreach (explode("\n", str_replace("\r", '', $conteudo)) as $linha) {
        $linhaTrim = trim($linha);

        // Checklist markdown ("- [x] tarefa" / "- [ ] tarefa") em qualquer parte do README
        if (preg_match('/^-\s\[x\]\s/i', $linhaTrim)) { $feitas++; $total++; continue; }
        if (preg_match('/^-\s\[\s\]\s/', $linhaTrim))  { $total++; continue; }

        if ($descricao === '') {
            if ($linhaTrim === '') continue;
            // Pula headers, imagens/badges, HTML solto, separadores e blocos de código
            if (preg_match('/^(#|!\[|\[!\[|<|---|```)/', $linhaTrim)) continue;

            $texto = trim(strip_tags($linhaTrim));
            $texto = preg_replace('/[*_`]/', '', $texto);
            if (mb_strlen($texto) >= 15) $descricao = mb_substr($texto, 0, 200);
        }
    }

    $pct = $total > 0 ? (int)round($feitas / $total * 100) : null;

    return ['descricao' => $descricao, 'progresso' => ['feitas' => $feitas, 'total' => $total, 'pct' => $pct]];
}

function github_obter_previsao(string $token, string $owner, string $repo): ?string {
    $ch = curl_init('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/milestones?state=open&sort=due_on&direction=asc&per_page=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data[0]['due_on'])) return null;

    return $data[0]['due_on'];
}

/**
 * Issues do repo (state=all). Ignora Pull Requests. Sem paginação — trunca em 100.
 * Retorna abertas (pendências), fechadas recentes (feito) e as contagens.
 */
function github_issues(string $token, string $owner, string $repo): array {
    $vazio = ['abertas' => [], 'fechadas_recentes' => [], 'total_abertas' => 0, 'total_fechadas' => 0];

    $ch = curl_init('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/issues?state=all&per_page=100&sort=updated&direction=desc');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return $vazio;

    $data = json_decode($body, true);
    if (!is_array($data)) return $vazio;

    $abertas = $fechadas = [];
    foreach ($data as $i) {
        if (isset($i['pull_request'])) continue; // PR, não issue
        $item = [
            'numero'     => (int)($i['number'] ?? 0),
            'titulo'     => $i['title'] ?? '(sem título)',
            'url'        => $i['html_url'] ?? '',
            'labels'     => array_values(array_map(fn($l) => $l['name'] ?? '', $i['labels'] ?? [])),
            'atualizado' => $i['updated_at'] ?? null,
            'fechado'    => $i['closed_at'] ?? null,
        ];
        if (($i['state'] ?? '') === 'open') $abertas[] = $item;
        else $fechadas[] = $item;
    }
    // fechadas: mais recentes primeiro (por data de fechamento)
    usort($fechadas, fn($a, $b) => strcmp($b['fechado'] ?? '', $a['fechado'] ?? ''));

    return [
        'abertas'           => array_slice($abertas, 0, 20),
        'fechadas_recentes' => array_slice($fechadas, 0, 8),
        'total_abertas'     => count($abertas),
        'total_fechadas'    => count($fechadas),
    ];
}

function github_http_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body, true);
    return [$code, is_array($data) ? $data : []];
}

/**
 * "Feito recentemente" — últimos commits considerando TODOS os branches
 * (o trabalho costuma estar num branch de feature, não no padrão).
 * [{sha, msg, autor, data, branch, url}], mais recente primeiro.
 */
function github_commits_recentes(string $token, string $owner, string $repo, int $n = 8): array {
    $base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);

    [$code, $branches] = github_http_get($base . '/branches?per_page=100', $token);
    $refs = ($code === 200 && $branches)
        ? array_slice(array_column($branches, 'name'), 0, 6)
        : [null];   // null = branch padrão

    $porSha = [];
    foreach ($refs as $ref) {
        $url = $base . '/commits?per_page=4' . ($ref !== null ? '&sha=' . rawurlencode($ref) : '');
        [$c, $data] = github_http_get($url, $token);
        if ($c !== 200) continue;
        foreach ($data as $cm) {
            $sha = $cm['sha'] ?? '';
            if ($sha === '' || isset($porSha[$sha])) continue;
            $porSha[$sha] = [
                'sha'    => substr($sha, 0, 7),
                'msg'    => trim(explode("\n", $cm['commit']['message'] ?? '')[0]),
                'autor'  => $cm['commit']['author']['name'] ?? ($cm['author']['login'] ?? ''),
                'data'   => $cm['commit']['author']['date'] ?? ($cm['commit']['committer']['date'] ?? null),
                'branch' => $ref ?? '',
                'url'    => $cm['html_url'] ?? '',
            ];
        }
    }

    $out = array_values($porSha);
    usort($out, fn($a, $b) => strcmp($b['data'] ?? '', $a['data'] ?? ''));
    return array_slice($out, 0, $n);
}

/**
 * Histórico completo de commits (todos os branches), com data/hora.
 * [{sha, msg, autor, data (ISO), branch, url}], mais recente primeiro.
 * $paginas = quantas páginas de 100 por branch (default 3 = ~300/branch).
 */
function github_commits_completo(string $token, string $owner, string $repo, int $paginas = 3): array {
    $base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);

    [$code, $branches] = github_http_get($base . '/branches?per_page=100', $token);
    $refs = ($code === 200 && $branches)
        ? array_slice(array_column($branches, 'name'), 0, 8)
        : [null];

    $porSha = [];
    foreach ($refs as $ref) {
        for ($p = 1; $p <= max(1, $paginas); $p++) {
            $url = $base . '/commits?per_page=100&page=' . $p
                . ($ref !== null ? '&sha=' . rawurlencode($ref) : '');
            [$c, $data] = github_http_get($url, $token);
            if ($c !== 200 || !$data) break;
            foreach ($data as $cm) {
                $sha = $cm['sha'] ?? '';
                if ($sha === '' || isset($porSha[$sha])) continue;
                $porSha[$sha] = [
                    'sha'    => substr($sha, 0, 7),
                    'msg'    => trim(explode("\n", $cm['commit']['message'] ?? '')[0]),
                    'autor'  => $cm['commit']['author']['name'] ?? ($cm['author']['login'] ?? ''),
                    'data'   => $cm['commit']['author']['date'] ?? ($cm['commit']['committer']['date'] ?? null),
                    'branch' => $ref ?? '',
                    'url'    => $cm['html_url'] ?? '',
                ];
            }
            if (count($data) < 100) break;
        }
    }

    $out = array_values($porSha);
    usort($out, fn($a, $b) => strcmp($b['data'] ?? '', $a['data'] ?? ''));
    return $out;
}

function github_obter_readme_html(string $token, string $owner, string $repo): array {
    $ch = curl_init('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/readme');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github.html+json',
            'User-Agent: portal-glpi',
        ],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) return ['erro' => 'Falha de conexão com o GitHub'];
    if ($code === 404) return ['html' => ''];
    if ($code !== 200) return ['erro' => 'GitHub retornou HTTP ' . $code];

    // O GitHub já retorna HTML renderizado/sanitizado (mesmo motor usado no site deles)
    return ['html' => $body];
}
