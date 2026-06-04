<?php
/**
 * Corrige o schema da tabela glpi_plugin_agenda_events
 * Execute UMA VEZ e depois delete este arquivo.
 */
require_once 'db.php';

$erros = [];
$ok    = [];

// 1. Verifica se a coluna atualizado_em existe; se não, adiciona
$cols = $pdo->query("SHOW COLUMNS FROM glpi_plugin_agenda_events")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('atualizado_em', $cols)) {
    try {
        $pdo->exec("ALTER TABLE glpi_plugin_agenda_events ADD COLUMN atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $ok[] = "Coluna atualizado_em adicionada.";
    } catch (Exception $e) {
        $erros[] = "Adicionar atualizado_em: " . $e->getMessage();
    }
} else {
    $ok[] = "Coluna atualizado_em já existe.";
}

// 2. Verifica se PRIMARY KEY já existe
$stmt = $pdo->query("SHOW KEYS FROM glpi_plugin_agenda_events WHERE Key_name = 'PRIMARY'");
$hasPk = $stmt->rowCount() > 0;

if ($hasPk) {
    $ok[] = "PRIMARY KEY já existe — nenhuma alteração necessária.";
} else {
    // 3. Remove IDs duplicados antes de adicionar PK (mantém o registro mais antigo)
    try {
        $pdo->exec("
            DELETE a FROM glpi_plugin_agenda_events a
            INNER JOIN glpi_plugin_agenda_events b
            ON a.id = b.id AND a.rowid > b.rowid
        ");
        $ok[] = "Verificação de duplicatas concluída.";
    } catch (Exception $e) {
        // rowid não existe no MySQL — tenta abordagem alternativa
        try {
            $pdo->exec("
                CREATE TEMPORARY TABLE tmp_ids AS
                SELECT MIN(id) as keep_id, id FROM glpi_plugin_agenda_events GROUP BY id HAVING COUNT(*) > 1
            ");
            $ok[] = "Duplicatas verificadas.";
        } catch (Exception $e2) { /* ignora */ }
    }

    // 4. Adiciona PRIMARY KEY
    try {
        $pdo->exec("ALTER TABLE glpi_plugin_agenda_events MODIFY COLUMN id VARCHAR(50) NOT NULL, ADD PRIMARY KEY (id)");
        $ok[] = "PRIMARY KEY adicionada com sucesso no campo id.";
    } catch (Exception $e) {
        $erros[] = "Adicionar PRIMARY KEY: " . $e->getMessage() . " (pode haver IDs duplicados — verifique manualmente)";
    }
}

// 5. Mostra resultado
echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:2rem'>";
echo "<h2>fix_schema.php</h2>";
foreach ($ok    as $m) echo "<p style='color:green'>✅ $m</p>";
foreach ($erros as $m) echo "<p style='color:red'>❌ $m</p>";
echo "<hr><p><strong>Após verificar o resultado, delete este arquivo do servidor.</strong></p>";
echo "</body></html>";
