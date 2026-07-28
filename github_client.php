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
