<?php
/**
 * github_cache.php — cache curto (default 10 min) das respostas da API do GitHub.
 * Sem cache, projetos.php faz ~4 chamadas por repo a cada load (lento + rate limit).
 * Requer $pdo (agenda/db.php). Erros nunca são cacheados.
 */

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
