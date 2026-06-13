<?php
/**
 * sync_projetos.php — Sincroniza projetos .md da rede para o portal
 *
 * Lê de ORIGEM_PROJETOS (pasta compartilhada na rede) e copia os
 * arquivos .md para Docs/wiki/projects/, organizados por subpasta.
 *
 * Uso:
 *   php sync_projetos.php                          # linha de comando
 *   php sync_projetos.php --origem="\\SERVER\Path" # origem customizada
 *
 * Task Scheduler (recomendado: a cada 60 min):
 *   C:\xampp\php\php.exe -f C:\xampp\htdocs\glpi2\portal-glpi\sync_projetos.php
 *
 * @package PortalTI
 */

// ── Config ──────────────────────────────────────────────────────────
// PADRÃO: pasta compartilhada no próprio servidor do portal
// Se quiser apontar para outra máquina, crie um config_local.php com:
//   define('ORIGEM_PROJETOS', '\\\MAQUINA\TI\PROJETOS E DOCUMENTAÇÕES');
$ORIGEM_PADRAO = __DIR__ . '/Docs/projetos-compartilhados';

// Carrega config local se existir (ignorado pelo git)
$configLocal = __DIR__ . '/config_projetos.local.php';
if (file_exists($configLocal)) {
    require_once $configLocal;
}

if (!defined('ORIGEM_PROJETOS')) {
    define('ORIGEM_PROJETOS', $ORIGEM_PADRAO);
}

$DESTINO = __DIR__ . '/Docs/wiki/projects';
$LOG_DIR = __DIR__ . '/Portal-Glpi/Logs';

// ── CLI args ────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && $argc > 1) {
    foreach ($argv as $arg) {
        if (preg_match('/^--origem=(.+)$/', $arg, $m)) {
            define('ORIGEM_PROJETOS', $m[1]);
        }
        if ($arg === '--help') {
            echo "Uso: php sync_projetos.php [--origem=\"\\\\SERVER\\Path\"]\n";
            echo "  --origem   Caminho da pasta de projetos na rede\n";
            echo "  --help     Mostra esta ajuda\n";
            exit(0);
        }
    }
}

// ── Log ─────────────────────────────────────────────────────────────
function logSync(string $msg): void {
    global $LOG_DIR;
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";

    if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0777, true);
    $logFile = $LOG_DIR . '/sync-projetos.log';
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

// ── Sync ────────────────────────────────────────────────────────────
$origem  = ORIGEM_PROJETOS;
$destino = $DESTINO;

logSync("=== INICIANDO SYNC DE PROJETOS ===");
logSync("Origem:  $origem");
logSync("Destino: $destino");

if (!is_dir($origem)) {
    logSync("ERRO: Pasta de origem não encontrada: $origem");
    logSync("Dica: Crie a pasta compartilhada e acesse-a, ou edite config_projetos.local.php");
    exit(1);
}

if (!is_dir($destino)) {
    @mkdir($destino, 0777, true);
    logSync("Criado destino: $destino");
}

// Varre subpastas da origem (cada subpasta = um projeto)
$pastas = glob($origem . '/*', GLOB_ONLYDIR);
$totalCopiados = 0;
$totalProjetos = 0;

foreach ($pastas as $pasta) {
    $nomeProjeto = basename($pasta);
    $arqs = glob($pasta . '/*.md');

    if (empty($arqs)) {
        logSync("  ↺ Pasta '$nomeProjeto' — sem .md, ignorada");
        continue;
    }

    // Cria pasta de destino para este projeto
    $pastaDestino = $destino . '/' . $nomeProjeto;
    if (!is_dir($pastaDestino)) {
        @mkdir($pastaDestino, 0777, true);
    }

    $copiados = 0;
    foreach ($arqs as $arq) {
        $nomeArq = basename($arq);
        $destArq = $pastaDestino . '/' . $nomeArq;

        // Só copia se origem for mais nova que destino, ou destino não existir
        $srcTime = filemtime($arq);
        $dstTime = file_exists($destArq) ? filemtime($destArq) : 0;

        if ($srcTime > $dstTime) {
            if (@copy($arq, $destArq)) {
                touch($destArq, $srcTime); // preserva timestamp
                $copiados++;
                logSync("  ✓ $nomeProjeto/$nomeArq");
            } else {
                logSync("  ✗ ERRO ao copiar $nomeProjeto/$nomeArq");
            }
        }
    }

    if ($copiados > 0) {
        $totalCopiados += $copiados;
        $totalProjetos++;
        logSync("  → $nomeProjeto: $copiados arquivo(s) copiado(s)");
    }

    // Limpa arquivos no destino que não existem mais na origem (opcional)
    $destArqs = glob($pastaDestino . '/*.md');
    $origArqs = array_map('basename', $arqs);
    foreach ($destArqs as $destArq) {
        if (!in_array(basename($destArq), $origArqs)) {
            @unlink($destArq);
            logSync("  ✗ Removido obsoleto: $nomeProjeto/" . basename($destArq));
        }
    }
}

// Também lida com arquivos .md que estão na raiz do destino (legado)
// Estes permanecem inalterados para compatibilidade

logSync("=== SYNC CONCLUÍDO ===");
logSync("Projetos atualizados: $totalProjetos");
logSync("Arquivos copiados: $totalCopiados");

// Salva timestamp do último sync
$tsFile = $destino . '/.last_sync';
file_put_contents($tsFile, date('Y-m-d H:i:s'));

exit(0);
