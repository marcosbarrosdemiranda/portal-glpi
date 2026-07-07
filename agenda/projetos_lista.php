<?php
/**
 * Lista os nomes dos projetos disponíveis (pastas em Docs/wiki/projects),
 * pra popular o select "Projeto" no evento de agenda tipo=projeto.
 * Mesma fonte de dados que projetos.php usa (rede com fallback local).
 */
header('Content-Type: application/json');

$pastaLocal = __DIR__ . '/../Docs/wiki/projects';
$pasta = $pastaLocal;
$origem = 'local';

$configLocal = __DIR__ . '/../config_projetos.local.php';
if (file_exists($configLocal)) {
    require_once $configLocal;
    if (defined('ORIGEM_PROJETOS') && ORIGEM_PROJETOS && is_dir(ORIGEM_PROJETOS)) {
        $pasta = ORIGEM_PROJETOS;
        $origem = 'rede';
    }
}

$projetos = [];
if (is_dir($pasta)) {
    foreach (glob($pasta . '/*', GLOB_ONLYDIR) as $subPasta) {
        $nome = basename($subPasta);
        if (str_starts_with($nome, '_')) continue; // pastas auxiliares (ex: _Documentação)

        // Só inclui se tiver pelo menos um .md dentro (mesmo critério do projetos.php)
        $temMd = false;
        $rdi = new RecursiveDirectoryIterator($subPasta, RecursiveDirectoryIterator::SKIP_DOTS);
        $rit = new RecursiveIteratorIterator($rdi);
        foreach ($rit as $file) {
            if ($file->getExtension() === 'md') { $temMd = true; break; }
        }
        if ($temMd) $projetos[] = $nome;
    }
}
sort($projetos, SORT_NATURAL | SORT_FLAG_CASE);

echo json_encode(['ok' => true, 'projetos' => $projetos, 'origem' => $origem]);
