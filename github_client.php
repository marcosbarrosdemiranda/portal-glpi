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

function github_obter_descricao_readme(string $token, string $owner, string $repo): string {
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

    if ($code !== 200) return '';

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['content'])) return '';

    $conteudo = base64_decode(str_replace("\n", '', $data['content']));
    if ($conteudo === false) return '';

    foreach (explode("\n", str_replace("\r", '', $conteudo)) as $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;
        // Pula headers, imagens/badges, HTML solto, separadores e blocos de código
        if (preg_match('/^(#|!\[|\[!\[|<|---|```)/', $linha)) continue;

        $texto = trim(strip_tags($linha));
        $texto = preg_replace('/[*_`]/', '', $texto);
        if (mb_strlen($texto) < 15) continue; // linha curta demais pra ser descrição (ex: link solto)

        return mb_substr($texto, 0, 200);
    }

    return '';
}

function github_obter_progresso(string $token, string $owner, string $repo, int $issuesAbertas): array {
    $ch = curl_init('https://api.github.com/search/issues?q=' . rawurlencode("repo:{$owner}/{$repo} type:issue state:closed") . '&per_page=1');
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

    $fechadas = 0;
    if ($code === 200) {
        $data = json_decode($body, true);
        $fechadas = (int)($data['total_count'] ?? 0);
    }

    $total = $fechadas + $issuesAbertas;
    $pct   = $total > 0 ? (int)round($fechadas / $total * 100) : null;

    return ['fechadas' => $fechadas, 'abertas' => $issuesAbertas, 'total' => $total, 'pct' => $pct];
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
