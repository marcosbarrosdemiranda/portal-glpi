<?php
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/inventario_lib.php';

echo "🔍 DEBUG: Verificando card Rádios\n\n";

// Roda bootstrap
echo "1️⃣ Rodando inv_bootstrap...\n";
inv_bootstrap($pdo);
echo "✅ inv_bootstrap executado\n\n";

// Verifica card
echo "2️⃣ Buscando card 'radios'...\n";
$st = $pdo->query("SELECT id, slug, titulo, descricao, fonte, ativo FROM portal_inv_cards WHERE slug = 'radios'");
$card = $st->fetch();

if ($card) {
    echo "✅ Card encontrado:\n";
    echo "   ID: {$card['id']}\n";
    echo "   Slug: {$card['slug']}\n";
    echo "   Título: {$card['titulo']}\n";
    echo "   Fonte: {$card['fonte']}\n";
    echo "   Ativo: {$card['ativo']}\n";
} else {
    echo "❌ Card NÃO ENCONTRADO\n";
}

echo "\n3️⃣ Listando todos os cards (computer):\n";
$st = $pdo->query("SELECT id, slug, titulo, fonte, ativo FROM portal_inv_cards WHERE fonte = 'computer' ORDER BY ordem");
foreach ($st as $r) {
    echo "  - {$r['slug']}: {$r['titulo']} (fonte={$r['fonte']}, ativo={$r['ativo']})\n";
}

echo "\n4️⃣ inv_pc_cats():\n";
$cats = inv_pc_cats();
foreach ($cats as $slug => $titulo) {
    echo "  - $slug => $titulo\n";
}
?>
