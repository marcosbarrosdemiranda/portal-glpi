<?php
// Simples script de sync — acesse no navegador
// https://ti.grupogmais.com:7412/glpi2/portal-glpi/sync.php

@set_time_limit(60);
echo "<pre>";

// Executa git pull
$output = shell_exec("cd " . __DIR__ . " && git pull origin infra/migracao-docker-glpi 2>&1");
echo "Git pull:\n";
echo htmlspecialchars($output);

// Roda bootstrap
echo "\n\nRodando inv_bootstrap...\n";
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/inventario_lib.php';
try {
    inv_bootstrap($pdo);
    echo "✅ inv_bootstrap OK\n";

    $r = $pdo->query("SELECT slug, titulo, fonte FROM portal_inv_cards WHERE slug = 'radios'")->fetch();
    if ($r) {
        echo "\n✅ Card Radios:\n";
        echo "   Título: " . htmlspecialchars($r['titulo']) . "\n";
        echo "   Fonte: " . htmlspecialchars($r['fonte']) . "\n";
    } else {
        echo "\n❌ Card radios não encontrado\n";
    }
} catch (Exception $e) {
    echo "❌ Erro: " . htmlspecialchars($e->getMessage()) . "\n";
}

echo "\n\n✅ Sync completo! Recarrega https://ti.grupogmais.com:7412/glpi2/portal-glpi/inventario.php\n";
echo "</pre>";
?>
