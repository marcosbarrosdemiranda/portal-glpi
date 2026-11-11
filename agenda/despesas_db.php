<?php
// agenda/despesas_db.php
require_once __DIR__ . '/db.php';
// DDL movido para migração manual.

// Guard aditivo de coluna — mesma dimensão "projeto" do orçamento
// (glpi_portal_projetos, criada em agenda/orcamento_db.php). Envolvido em
// try/catch porque este arquivo nunca criou a tabela glpi_portal_despesas
// em si (ela já existe em produção via migração manual) — se por algum
// motivo não existir ainda num ambiente, o SHOW COLUMNS falha e aqui só
// ignoramos, sem travar o include.
try {
    $col_projeto_despesa = $pdo->query("SHOW COLUMNS FROM glpi_portal_despesas LIKE 'projeto_id'")->fetch();
    if (!$col_projeto_despesa) {
        $pdo->exec("ALTER TABLE glpi_portal_despesas ADD COLUMN projeto_id INT DEFAULT NULL");
    }
} catch (\Throwable $e) {
    // tabela ainda não existe neste ambiente — nada a fazer aqui
}
?>
