<?php
// agenda/orcamento_db.php
require_once __DIR__ . '/db.php';

// Cria/Garante a tabela no banco glpi2 (usando o $pdo do sistema)
$pdo->exec("CREATE TABLE IF NOT EXISTS glpi_portal_orcamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(50) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    mes_ano VARCHAR(7) NOT NULL,

    qty_prevista INT DEFAULT 1,
    unit_previsto DECIMAL(15,2) DEFAULT 0.00,
    total_previsto DECIMAL(15,2) AS (qty_prevista * unit_previsto) STORED,

    qty_realizada INT DEFAULT 0,
    unit_realizado DECIMAL(15,2) DEFAULT 0.00,
    total_realizado DECIMAL(15,2) AS (qty_realizada * unit_realizado) STORED,

    observacao TEXT,
    concluido TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Adiciona a coluna concluido se não existir (para quem já tem a tabela)
$db = $pdo->query("SHOW COLUMNS FROM glpi_portal_orcamento LIKE 'concluido'")->fetch();
if (!$db) {
    $pdo->exec("ALTER TABLE glpi_portal_orcamento ADD COLUMN concluido TINYINT(1) DEFAULT 0");
}

// Adiciona a coluna ativo se não existir — item suspenso (ativo=0) continua
// listado, mas não entra em nenhuma soma do orçamento
$col_ativo = $pdo->query("SHOW COLUMNS FROM glpi_portal_orcamento LIKE 'ativo'")->fetch();
if (!$col_ativo) {
    $pdo->exec("ALTER TABLE glpi_portal_orcamento ADD COLUMN ativo TINYINT(1) DEFAULT 1");
}

// Adiciona tipo_despesa_id se não existir — categoria passa a ser o mesmo
// catálogo compartilhado com despesas (glpi_portal_despesas_tipos), em vez
// de texto livre. Coluna categoria (texto) não é apagada, fica como
// histórico/fallback.
$col_tipo = $pdo->query("SHOW COLUMNS FROM glpi_portal_orcamento LIKE 'tipo_despesa_id'")->fetch();
if (!$col_tipo) {
    $pdo->exec("ALTER TABLE glpi_portal_orcamento ADD COLUMN tipo_despesa_id INT NULL");
}

// Migração de dados: linka cada linha ainda sem tipo_despesa_id ao catálogo,
// por nome (case-insensitive); se a categoria não existir no catálogo,
// cria a entrada (nunca deixa linha órfã). Roda a cada load, mas é barato
// depois da primeira vez (WHERE tipo_despesa_id IS NULL já não acha nada).
$pendentes = $pdo->query("SELECT DISTINCT categoria FROM glpi_portal_orcamento WHERE tipo_despesa_id IS NULL AND categoria IS NOT NULL AND categoria <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($pendentes as $cat) {
    $busca = $pdo->prepare("SELECT id FROM glpi_portal_despesas_tipos WHERE LOWER(nome) = LOWER(?)");
    $busca->execute([$cat]);
    $tipoId = $busca->fetchColumn();
    if (!$tipoId) {
        $pdo->prepare("INSERT INTO glpi_portal_despesas_tipos (nome) VALUES (?)")->execute([$cat]);
        $tipoId = $pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE glpi_portal_orcamento SET tipo_despesa_id = ? WHERE categoria = ? AND tipo_despesa_id IS NULL")->execute([$tipoId, $cat]);
}
?>