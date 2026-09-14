<?php
/**
 * impressoras_lib.php — cadastro e consulta de impressoras de rede via SNMP
 * pra Central de Alertas / Inventário.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria as tabelas ao ser incluído (padrão do portal, igual dude_lib.php).
 */

require_once __DIR__ . '/agenda/db.php';

(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45)  NOT NULL,
        apelido      VARCHAR(120) NOT NULL,
        loja         VARCHAR(120) NOT NULL DEFAULT '',
        comunidade   VARCHAR(60)  NOT NULL DEFAULT 'public',
        ativo        TINYINT(1)   NOT NULL DEFAULT 1,
        criado_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_status (
        impressora_id    INT PRIMARY KEY,
        online           TINYINT(1) NOT NULL,
        modelo           VARCHAR(255) NULL,
        serial           VARCHAR(120) NULL,
        firmware         VARCHAR(120) NULL,
        paginas_total    INT NULL,
        consumiveis_json MEDIUMTEXT NULL,
        atualizado_em    DATETIME NOT NULL,
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_historico (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        impressora_id INT NOT NULL,
        paginas_total INT NOT NULL,
        registrado_em DATETIME NOT NULL,
        KEY (impressora_id, registrado_em),
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── CRUD ───────────────────────────── */

function impressora_cadastrar(PDO $pdo, string $ip, string $apelido, string $loja, string $comunidade = 'public'): int
{
    $pdo->prepare(
        "INSERT INTO portal_impressoras (ip, apelido, loja, comunidade) VALUES (?, ?, ?, ?)"
    )->execute([$ip, $apelido, $loja, $comunidade]);
    return (int) $pdo->lastInsertId();
}

/** Só as ativas — mesmo padrão de dude_categorias_vistas (config só existe pra quem está em uso). */
function impressora_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_impressoras WHERE ativo = 1 ORDER BY loja, apelido")->fetchAll(PDO::FETCH_ASSOC);
}

function impressora_buscar(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_impressoras WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function impressora_editar(PDO $pdo, int $id, string $ip, string $apelido, string $loja, string $comunidade): void
{
    $pdo->prepare(
        "UPDATE portal_impressoras SET ip=?, apelido=?, loja=?, comunidade=? WHERE id=?"
    )->execute([$ip, $apelido, $loja, $comunidade, $id]);
}

function impressora_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_impressoras WHERE id = ?")->execute([$id]);
}
