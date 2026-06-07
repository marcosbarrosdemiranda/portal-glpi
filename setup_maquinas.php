<?php
// Script único para inserir máquinas na Central RDP
// Rode com: php setup_maquinas.php
require_once __DIR__ . '/agenda/db.php';

$maquinas = [
    ['TS - Marcos',  '192.168.1.116', 'Micro do Marcos',       'marcos@grupogmais', 'pc',     3],
];

$st = $pdo->prepare("INSERT IGNORE INTO portal_rdp_maquinas (nome,ip,descricao,usuario,categoria,ordem) VALUES (?,?,?,?,?,?)");
foreach ($maquinas as $m) {
    // Verifica se já existe
    $chk = $pdo->prepare("SELECT COUNT(*) FROM portal_rdp_maquinas WHERE nome=? AND ip=?");
    $chk->execute([$m[0], $m[1]]);
    if ($chk->fetchColumn() == 0) {
        $st->execute($m);
        echo "✅ {$m[0]} adicionada\n";
    } else {
        echo "⏭️  {$m[0]} já existe\n";
    }
}
echo "Pronto!\n";
