<?php
require_once __DIR__ . '/agenda/db.php';

echo "🔍 Verificando card Rádios...\n\n";

$st = $pdo->query("SELECT id, slug, titulo, fonte, ativo FROM portal_inv_cards WHERE slug='radios' OR slug LIKE '%radio%' ORDER BY slug");
$cards = $st->fetchAll();

if (empty($cards)) {
    echo "❌ Nenhum card encontrado com 'radios' no slug\n";
} else {
    foreach ($cards as $c) {
        echo "Card encontrado:\n";
        echo "  ID: {$c['id']}\n";
        echo "  Slug: {$c['slug']}\n";
        echo "  Título: {$c['titulo']}\n";
        echo "  Fonte: {$c['fonte']}\n";
        echo "  Ativo: {$c['ativo']}\n\n";
    }
}

echo "Todos os cards computer:\n";
$st = $pdo->query("SELECT slug, titulo, fonte, ativo FROM portal_inv_cards WHERE fonte='computer' ORDER BY ordem, titulo");
foreach ($st as $c) {
    echo "  - {$c['slug']}: {$c['titulo']} (ativo={$c['ativo']})\n";
}
?>
