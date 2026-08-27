<?php
/**
 * Ferramentas de Manutenção do Portal
 * Acessível para técnicos. Fornece:
 *   - Sincronia manual (sync_rotinas.php)
 *   - Teste de conexão GLPI
 *   - Limpeza de cache
 *   - Informações do sistema
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

$nome = $_SESSION['nome'] ?? '';

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/agenda/db.php';

// ── Handlers ──
$action = $_GET['action'] ?? '';
$resultado = [];

if ($action === 'testar_api') {
    header('Content-Type: application/json');
    try {
        $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
        $headers = [
            'Authorization: Basic ' . $auth,
            'App-Token: ' . GLPI_APP_TOKEN,
        ];
        $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            echo json_encode(['ok' => false, 'msg' => 'Erro de conexão cURL: ' . $error]);
            exit;
        }

        // Separa header do body
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // Re-executa sem CURLOPT_HEADER para obter body puro
        $ch2 = curl_init(GLPI_URL . '/apirest.php/initSession');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch2);
        curl_close($ch2);

        $data = json_decode($body, true);
        $session_token = $data['session_token'] ?? '';

        // Tenta matar a sessão se criou
        if ($session_token) {
            $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
            curl_setopt_array($ch3, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Session-Token: ' . $session_token, 'App-Token: ' . GLPI_APP_TOKEN],
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch3);
            curl_close($ch3);
        }

        // Tenta obter versão do GLPI via /apirest.php/ — muitas instalações expõem
        $versao = 'N/A';
        $ch4 = curl_init(GLPI_URL . '/apirest.php/');
        curl_setopt_array($ch4, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $root_data = json_decode(curl_exec($ch4), true);
        curl_close($ch4);
        if (!empty($root_data['version'])) {
            $versao = $root_data['version'];
        } elseif (!empty($root_data['resources']['version'])) {
            $versao = $root_data['resources']['version'];
        }

        echo json_encode([
            'ok'            => $http_code >= 200 && $http_code < 300 && $session_token !== '',
            'http_code'     => $http_code,
            'session_token' => $session_token ? substr($session_token, 0, 20) . '…' : 'N/A',
            'versao'        => $versao,
            'msg'           => $session_token ? 'Conexão OK' : 'Falha na autenticação',
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'testar_mysql') {
    header('Content-Type: application/json');
    try {
        $st = $pdo->query("SELECT VERSION() AS versao");
        $row = $st->fetch();
        echo json_encode(['ok' => true, 'versao' => $row['versao'] ?? 'ok']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'tamanho_tabelas') {
    header('Content-Type: application/json');
    try {
        $st = $pdo->query("
            SELECT table_name AS tabela,
                   table_rows AS linhas,
                   ROUND(data_length/1024/1024, 2)  AS dados_mb,
                   ROUND(index_length/1024/1024, 2) AS indices_mb,
                   ROUND((data_length+index_length)/1024/1024, 2) AS total_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            ORDER BY (data_length+index_length) DESC
            LIMIT 25
        ");
        $tabelas = $st->fetchAll(PDO::FETCH_ASSOC);

        $totais = $pdo->query("
            SELECT ROUND(SUM(data_length+index_length)/1024/1024, 2) AS total_mb,
                   COUNT(*) AS qtd_tabelas
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'          => true,
            'tabelas'     => $tabelas,
            'total_mb'    => $totais['total_mb'] ?? 0,
            'qtd_tabelas' => $totais['qtd_tabelas'] ?? 0,
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'otimizar_tabela') {
    header('Content-Type: application/json');
    $tabela = $_GET['tabela'] ?? '';
    try {
        // Nome de tabela não pode ser parametrizado via bind — valida contra o schema real antes de interpolar
        $check = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $check->execute([$tabela]);
        if (!$check->fetchColumn()) {
            echo json_encode(['ok' => false, 'msg' => 'Tabela não encontrada no banco.']);
            exit;
        }
        $st = $pdo->query("OPTIMIZE TABLE `" . $tabela . "`");
        $resultado = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'tabela' => $tabela, 'resultado' => $resultado]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

function glpi_admin_request(string $method, string $endpoint, $body = null): array {
    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $init = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($init, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'App-Token: ' . GLPI_APP_TOKEN],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $initData = json_decode(curl_exec($init), true);
    curl_close($init);
    $token = $initData['session_token'] ?? '';
    if (!$token) return ['ok' => false, 'msg' => 'Falha ao autenticar na API do GLPI.'];

    $headers = ['Session-Token: ' . $token, 'App-Token: ' . GLPI_APP_TOKEN, 'Content-Type: application/json'];
    $ch = curl_init(GLPI_URL . '/apirest.php/' . $endpoint);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $kill = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($kill, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 5]);
    curl_exec($kill);
    curl_close($kill);

    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => json_decode($resp, true)];
}

if ($action === 'purgelogs_status') {
    header('Content-Type: application/json');
    $r = glpi_admin_request('GET', 'CronTask/33');
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => 'Falha ao consultar CronTask.']); exit; }
    $d = $r['data'];
    echo json_encode([
        'ok'        => true,
        'meses'     => (int)($d['param'] ?? 0),
        'lastrun'   => $d['lastrun'] ?? null,
        'frequency' => $d['frequency'] ?? null,
        'state'     => $d['state'] ?? null,
    ]);
    exit;
}

if ($action === 'purgelogs_salvar') {
    header('Content-Type: application/json');
    $meses = (int)($_GET['meses'] ?? 0);
    if ($meses < 1 || $meses > 120) {
        echo json_encode(['ok' => false, 'msg' => 'Informe um número de meses entre 1 e 120.']);
        exit;
    }
    $r = glpi_admin_request('PUT', 'CronTask/33', ['input' => ['id' => 33, 'param' => $meses]]);
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => 'Falha ao atualizar CronTask.']); exit; }
    echo json_encode(['ok' => true, 'meses' => $meses]);
    exit;
}

if ($action === 'purgelogs_preparar') {
    header('Content-Type: application/json');
    set_time_limit(0);
    try {
        $meses = (int)($_GET['meses'] ?? 0);
        if ($meses < 1) { echo json_encode(['ok' => false, 'msg' => 'Meses inválido.']); exit; }

        $minId = (int)$pdo->query("SELECT MIN(id) FROM glpi_logs")->fetchColumn();
        $maxId = (int)$pdo->query("SELECT MAX(id) FROM glpi_logs")->fetchColumn();

        // Busca binária pelo ID cujo date_mod cruza o corte de retenção — evita escanear
        // a tabela inteira por data_mod (não indexada), já que o ID é ~cronológico.
        $stmtData = $pdo->prepare("SELECT date_mod FROM glpi_logs WHERE id >= ? ORDER BY id ASC LIMIT 1");
        $lo = $minId; $hi = $maxId; $cutoffId = $minId;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $stmtData->execute([$mid]);
            $dataMod = $stmtData->fetchColumn();
            if ($dataMod === false) { $hi = $mid - 1; continue; }
            if (strtotime($dataMod) < strtotime("-{$meses} months")) {
                $cutoffId = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM glpi_logs WHERE id <= ?");
        $stmtCount->execute([$cutoffId]);
        $totalApagar = (int)$stmtCount->fetchColumn();

        $tmpDir = GLPI_ABSPATH . '/files/_tmp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/purgelogs_cutoff.json', json_encode(['cutoff_id' => $cutoffId, 'meses' => $meses]));

        echo json_encode(['ok' => true, 'cutoff_id' => $cutoffId, 'total_apagar' => $totalApagar]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'purgelogs_executar_lote') {
    header('Content-Type: application/json');
    set_time_limit(0);
    try {
        $cutoffPath = GLPI_ABSPATH . '/files/_tmp/purgelogs_cutoff.json';
        if (!is_file($cutoffPath)) {
            echo json_encode(['ok' => false, 'msg' => 'Rode purgelogs_preparar primeiro.']);
            exit;
        }
        $cfg = json_decode(file_get_contents($cutoffPath), true);
        $cutoffId = (int)$cfg['cutoff_id'];
        $limit = 20000;

        $stmt = $pdo->prepare("DELETE FROM glpi_logs WHERE id <= ? ORDER BY id ASC LIMIT $limit");
        $stmt->execute([$cutoffId]);
        $apagados = $stmt->rowCount();

        $restante = $pdo->prepare("SELECT COUNT(*) FROM glpi_logs WHERE id <= ?");
        $restante->execute([$cutoffId]);
        $restanteQtd = (int)$restante->fetchColumn();

        echo json_encode(['ok' => true, 'apagados' => $apagados, 'restante' => $restanteQtd, 'concluido' => $restanteQtd === 0]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'png_backup') {
    header('Content-Type: application/json');
    set_time_limit(0);
    try {
        $origem = GLPI_ABSPATH . '/files/PNG';
        if (!is_dir($origem)) {
            echo json_encode(['ok' => false, 'msg' => 'Pasta files/PNG não encontrada.']);
            exit;
        }
        $destino = GLPI_ABSPATH . '/files/_backup_png_' . date('Ymd_His');
        mkdir($destino, 0777, true);

        $qtd = 0;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile()) continue;
            $rel = substr($file->getPathname(), strlen($origem) + 1);
            $destPath = $destino . '/' . $rel;
            @mkdir(dirname($destPath), 0777, true);
            copy($file->getPathname(), $destPath);
            $qtd++;
        }

        echo json_encode(['ok' => true, 'destino' => $destino, 'qtd' => $qtd]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'png_listar') {
    header('Content-Type: application/json');
    try {
        $dir = GLPI_ABSPATH . '/files/PNG';
        if (!is_dir($dir)) {
            echo json_encode(['ok' => false, 'msg' => 'Pasta files/PNG não encontrada.']);
            exit;
        }
        $tmpDir = GLPI_ABSPATH . '/files/_tmp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);

        // Incremental: só reprocessa arquivos criados/alterados depois da última execução concluída
        $marcadorPath = $tmpDir . '/png_ultima_execucao.txt';
        $ultimaExecucao = is_file($marcadorPath) ? (int)file_get_contents($marcadorPath) : 0;
        $agora = time();

        $lista = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
                if ($ultimaExecucao > 0 && $file->getMTime() <= $ultimaExecucao) continue; // já processado antes
                $lista[] = substr($file->getPathname(), strlen($dir) + 1); // caminho relativo, ex: 00/hash.PNG
            }
        }

        file_put_contents($tmpDir . '/png_lista.json', json_encode(['gerado_em' => $agora, 'arquivos' => $lista]));
        echo json_encode(['ok' => true, 'total' => count($lista), 'incremental' => $ultimaExecucao > 0]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'png_otimizar') {
    header('Content-Type: application/json');
    set_time_limit(0);
    try {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));

        $listaPath = GLPI_ABSPATH . '/files/_tmp/png_lista.json';
        if (!is_file($listaPath)) {
            echo json_encode(['ok' => false, 'msg' => 'Lista não gerada. Rode png_listar primeiro.']);
            exit;
        }
        $listaData = json_decode(file_get_contents($listaPath), true) ?: [];
        $geradoEm  = $listaData['gerado_em'] ?? time();
        $lista     = $listaData['arquivos'] ?? [];
        $total     = count($lista);
        $fatia     = array_slice($lista, $offset, $limit);

        $dirBase = GLPI_ABSPATH . '/files/PNG';
        $processados = 0;
        $otimizados  = 0;
        $economizado = 0; // bytes
        $erros = [];

        $stmt = $pdo->prepare("UPDATE glpi_documents SET sha1sum = ? WHERE filepath = ?");

        foreach ($fatia as $rel) {
            $caminho = $dirBase . '/' . $rel;
            $processados++;
            if (!is_file($caminho)) continue;

            $original = file_get_contents($caminho);
            $tamOriginal = strlen($original);
            if ($tamOriginal === 0) continue;

            $img = @imagecreatefromstring($original);
            if (!$img) { $erros[] = $rel; continue; }

            imagepalettetotruecolor($img);
            imagetruecolortopalette($img, false, 256);
            ob_start();
            imagepng($img, null, 9);
            $novo = ob_get_clean();
            imagedestroy($img);

            // Só sobrescreve se realmente ficou menor (nunca piora um arquivo)
            if ($novo !== false && strlen($novo) < $tamOriginal) {
                file_put_contents($caminho, $novo);
                $economizado += ($tamOriginal - strlen($novo));
                $otimizados++;

                // Mantém glpi_documents.sha1sum coerente com o novo conteúdo do arquivo
                $novoSha1 = sha1($novo);
                $filepathDb = 'PNG/' . str_replace('\\', '/', $rel);
                $stmt->execute([$novoSha1, $filepathDb]);
            }
        }

        $concluido = ($offset + $limit) >= $total;
        if ($concluido) {
            // Marca até quando os arquivos já foram cobertos, pra próxima execução ser incremental
            file_put_contents(GLPI_ABSPATH . '/files/_tmp/png_ultima_execucao.txt', (string)$geradoEm);
        }

        echo json_encode([
            'ok'          => true,
            'processados' => $processados,
            'otimizados'  => $otimizados,
            'economizado' => $economizado,
            'offset'      => $offset,
            'total'       => $total,
            'concluido'   => $concluido,
            'erros'       => $erros,
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'sincronizar') {
    header('Content-Type: application/json');
    try {
        ob_start();
        // Chama sync_rotinas.php via include — ele escreve direto no stdout
        require __DIR__ . '/agenda/sync_rotinas.php';
        $output = ob_get_clean();
        echo json_encode(['ok' => true, 'output' => $output]);
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'limpar_cache') {
    header('Content-Type: application/json');
    $removidos = [];
    try {
        // Cache local se existir
        $cache_dir = __DIR__ . '/cache';
        if (is_dir($cache_dir)) {
            $itens = glob($cache_dir . '/*');
            foreach ($itens as $item) {
                if (is_file($item)) unlink($item);
                elseif (is_dir($item)) {
                    $sub = glob($item . '/*');
                    foreach ($sub as $s) { if (is_file($s)) unlink($s); }
                    rmdir($item);
                }
            }
            $removidos[] = count($itens) . ' arquivo(s) da pasta cache/';
        }

        // Sessão: limpa flash messages se houver
        if (isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
            $removidos[] = 'Flash messages da sessão';
        }

        // Tenta limpar cache do GLPI local (pasta files/_tmp se acessível)
        $glpi_tmp = GLPI_ABSPATH . '/files/_tmp';
        if (defined('GLPI_ABSPATH') && is_dir($glpi_tmp)) {
            $tmp_itens = glob($glpi_tmp . '/*');
            $count = 0;
            foreach ($tmp_itens as $t) {
                if (is_file($t) && (time() - filemtime($t)) > 3600) {
                    unlink($t);
                    $count++;
                }
            }
            if ($count > 0) $removidos[] = $count . ' arquivo(s) temporários do GLPI';
        }

        echo json_encode(['ok' => true, 'msg' => 'Cache limpo.', 'detalhes' => $removidos]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Informações do sistema (renderizadas no HTML) ──
$php_version = phpversion();
$glpi_url = GLPI_URL;
$mysql_status = 'desconectado';
$mysql_versao = '';
try {
    $st = $pdo->query("SELECT VERSION() AS v");
    $row = $st->fetch();
    $mysql_status = 'conectado';
    $mysql_versao = $row['v'] ?? '';
} catch (Exception $e) {
    $mysql_status = 'desconectado (' . $e->getMessage() . ')';
}

$dirs = [
    'Raiz do portal' => __DIR__,
    'GLPI (filesystem)' => defined('GLPI_ABSPATH') ? GLPI_ABSPATH : 'N/A',
    'Cache local' => __DIR__ . '/cache',
    'Logs agenda' => __DIR__ . '/agenda/logs',
];
foreach ($dirs as $k => $d) {
    $dirs[$k] = ['path' => $d, 'existe' => is_dir($d), 'gravavel' => is_writable($d)];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Manutenção do Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; min-height:100vh; }
    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; transition:.2s; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero {
      background:linear-gradient(135deg,var(--primary),#1565c0); color:white;
      padding:2rem 1rem 4.5rem; text-align:center;
    }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p  { opacity:.8; margin:.3rem 0 0; }
    .wrap { max-width:960px; margin:-3rem auto 3rem; padding:0 1rem; }

    .card-ferramenta {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06);
      margin-bottom:1.25rem; overflow:hidden;
    }
    .card-ferramenta .card-header {
      background:white; border-bottom:1px solid #e5e7eb;
      padding:.85rem 1.25rem; font-weight:700; font-size:.9rem;
      display:flex; align-items:center; gap:.5rem;
    }
    .card-ferramenta .card-body { padding:1.25rem; }
    .card-ferramenta .card-footer {
      background:#f9fafb; border-top:1px solid #e5e7eb;
      padding:.75rem 1.25rem;
    }

    .result-box {
      background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px;
      padding:.75rem 1rem; margin-top:.75rem;
      font-size:.8rem; font-family:'Consolas','Courier New',monospace;
      white-space:pre-wrap; word-break:break-all; max-height:400px; overflow-y:auto;
      display:none;
    }
    .result-box.success { border-color:#22c55e; background:#f0fdf4; color:#166534; display:block; }
    .result-box.error   { border-color:#ef4444; background:#fef2f2; color:#991b1b; display:block; }
    .result-box.info    { border-color:#3b82f6; background:#eff6ff; color:#1e40af; display:block; }

    .info-grid {
      display:grid; grid-template-columns:auto 1fr; gap:.4rem 1rem;
      font-size:.85rem;
    }
    .info-grid .label { font-weight:600; color:#6b7280; white-space:nowrap; }
    .info-grid .value { color:#111; }

    .btn-spinner { pointer-events:none; opacity:.7; }
    .btn-spinner .spinner-border { display:inline-block; }

    .badge-dir { font-size:.72rem; padding:.15rem .45rem; border-radius:20px; font-weight:600; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-tools"></i> Manutenção do Portal</div>
  <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
</div>

<div class="hero">
  <h1><i class="bi bi-tools me-2"></i>Ferramentas de Manutenção</h1>
  <p>Diagnóstico, sincronia e limpeza do portal — acesso restrito a técnicos</p>
</div>

<div class="wrap">

  <!-- Card 1: Sincronia Manual -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-arrow-repeat text-primary"></i> Sincronia Manual (Rotinas)</div>
    <div class="card-body">
      <p class="small text-muted mb-2">Executa o script de sincronização de chamados de rotina (<code>agenda/sync_rotinas.php</code>) manualmente. Útil para forçar a atualização fora do horário do cron.</p>
      <button class="btn btn-primary btn-sm" onclick="executarAcao('sincronizar', this)" data-result="resultSinc">
        <i class="bi bi-arrow-repeat me-1"></i>Sincronizar Agora
      </button>
    </div>
    <div class="card-footer">
      <div id="resultSinc" class="result-box"></div>
    </div>
  </div>

  <!-- Card 2: Teste de Conexão GLPI -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-cloud-check text-success"></i> Teste de Conexão GLPI</div>
    <div class="card-body">
      <p class="small text-muted mb-2">Verifica se a API REST do GLPI está respondendo corretamente.</p>
      <button class="btn btn-success btn-sm" onclick="executarAcao('testar_api', this)" data-result="resultApi">
        <i class="bi bi-wifi me-1"></i>Testar
      </button>
    </div>
    <div class="card-footer">
      <div id="resultApi" class="result-box"></div>
    </div>
  </div>

  <!-- Card 3: Limpar Cache -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-trash text-danger"></i> Limpar Cache</div>
    <div class="card-body">
      <p class="small text-muted mb-2">Remove arquivos temporários da pasta <code>cache/</code> e reseta flash messages da sessão. Limpa também arquivos temporários do GLPI com mais de 1 hora.</p>
      <button class="btn btn-danger btn-sm" onclick="executarAcao('limpar_cache', this)" data-result="resultCache">
        <i class="bi bi-eraser me-1"></i>Limpar Cache
      </button>
    </div>
    <div class="card-footer">
      <div id="resultCache" class="result-box"></div>
    </div>
  </div>

  <!-- Card 3.5: Banco de Dados GLPI -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-hdd-stack text-warning"></i> Banco de Dados GLPI</div>
    <div class="card-body">
      <p class="small text-muted mb-2">
        Mostra o tamanho das maiores tabelas do banco (somente leitura) e permite rodar <code>OPTIMIZE TABLE</code>
        pra recuperar espaço em disco depois de exclusões — não apaga nenhum dado, só reorganiza a tabela.
        Em tabelas grandes isso pode demorar e usar bastante I/O; prefira rodar fora do horário de pico.
      </p>
      <button class="btn btn-warning btn-sm" id="btnAnalisarTabelas" onclick="analisarTabelas()">
        <i class="bi bi-search me-1"></i>Analisar Tamanho das Tabelas
      </button>
      <span id="dbResumo" class="small text-muted ms-2"></span>

      <div id="dbTabelasWrap" style="display:none;margin-top:1rem;overflow-x:auto">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem">
          <thead>
            <tr>
              <th>Tabela</th>
              <th class="text-end">Linhas (~)</th>
              <th class="text-end">Dados (MB)</th>
              <th class="text-end">Índices (MB)</th>
              <th class="text-end">Total (MB)</th>
              <th class="text-end">Ação</th>
            </tr>
          </thead>
          <tbody id="dbTabelasBody"></tbody>
        </table>
      </div>
    </div>
    <div class="card-footer">
      <div id="resultDb" class="result-box"></div>
    </div>
  </div>

  <!-- Card 3.55: Retenção de Histórico (glpi_logs) -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-clock-history text-secondary"></i> Retenção de Histórico (glpi_logs)</div>
    <div class="card-body">
      <p class="small text-muted mb-2">
        Controla por quantos meses o GLPI mantém a aba "Histórico" (auditoria de mudança de campo) de cada item
        antes de descartar as entradas mais antigas. <strong>Não afeta</strong> o chamado em si — descrição,
        conversa, anexos e solução continuam intactos pra sempre, só o log granular de "quem mudou o quê e quando".
        A limpeza roda automaticamente uma vez por semana com o valor configurado aqui.
      </p>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="small text-muted">Retenção atual:</span>
        <span id="purgeLogsAtual" class="fw-semibold">carregando...</span>
        <span class="small text-muted ms-2">Alterar para:</span>
        <select id="purgeLogsMeses" class="form-select form-select-sm" style="width:auto">
          <option value="3">3 meses</option>
          <option value="6">6 meses</option>
          <option value="12">12 meses</option>
          <option value="24">24 meses</option>
          <option value="36">36 meses</option>
        </select>
        <button class="btn btn-secondary btn-sm" onclick="purgeLogsSalvar()">
          <i class="bi bi-save me-1"></i>Salvar
        </button>
        <button class="btn btn-outline-danger btn-sm" onclick="purgeLogsExecutarAgora()">
          <i class="bi bi-play-fill me-1"></i>Rodar Agora
        </button>
      </div>
      <div id="purgeLogsProgressWrap" style="display:none;margin-top:1rem">
        <div class="progress" style="height:20px">
          <div id="purgeLogsProgressBar" class="progress-bar bg-danger" style="width:0%">0%</div>
        </div>
        <div id="purgeLogsProgressTexto" class="small text-muted mt-1"></div>
      </div>
    </div>
    <div class="card-footer">
      <div id="resultPurgeLogs" class="result-box"></div>
    </div>
  </div>

  <!-- Card 3.6: Otimização de Imagens dos Chamados -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-file-earmark-image text-primary"></i> Otimização de Imagens (PNG) dos Chamados</div>
    <div class="card-body">
      <p class="small text-muted mb-2">
        Reduz a paleta de cores dos prints de tela anexados nos chamados (256 cores) — em torno de 70-80% menor,
        geralmente imperceptível visualmente. Cada arquivo só é sobrescrito se ficar realmente menor que o original.
        Roda de forma <strong>incremental</strong>: depois da primeira execução, só reprocessa imagens novas
        (anexadas depois da última vez que rodou aqui) — não escaneia tudo de novo.
        O backup é opcional (útil se quiser uma rede de segurança extra antes de rodar em um lote grande de imagens novas).
      </p>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" id="btnPngBackup" onclick="pngBackup()">
          <i class="bi bi-shield-check me-1"></i>Fazer Backup da Pasta PNG (opcional)
        </button>
        <button class="btn btn-primary btn-sm" id="btnPngOtimizar" onclick="pngOtimizarTudo()">
          <i class="bi bi-magic me-1"></i>Rodar Otimização
        </button>
      </div>

      <div id="pngProgressWrap" style="display:none;margin-top:1rem">
        <div class="progress" style="height:20px">
          <div id="pngProgressBar" class="progress-bar bg-primary" style="width:0%">0%</div>
        </div>
        <div id="pngProgressTexto" class="small text-muted mt-1"></div>
      </div>
    </div>
    <div class="card-footer">
      <div id="resultPng" class="result-box"></div>
    </div>
  </div>

  <!-- Card 4: Informações do Sistema -->
  <div class="card-ferramenta">
    <div class="card-header"><i class="bi bi-info-circle text-info"></i> Informações do Sistema</div>
    <div class="card-body">
      <div class="info-grid">
        <span class="label">PHP Version</span>
        <span class="value"><?= htmlspecialchars($php_version) ?></span>

        <span class="label">GLPI URL</span>
        <span class="value"><code><?= htmlspecialchars($glpi_url) ?></code></span>

        <span class="label">MySQL</span>
        <span class="value">
          <?php if ($mysql_status === 'conectado'): ?>
            <span class="badge bg-success">Conectado</span>
            <?= $mysql_versao ? '<span class="text-muted small ms-1">(' . htmlspecialchars($mysql_versao) . ')</span>' : '' ?>
          <?php else: ?>
            <span class="badge bg-danger"><?= htmlspecialchars($mysql_status) ?></span>
          <?php endif; ?>
        </span>

        <span class="label">Sessão</span>
        <span class="value">
          <span class="badge bg-info"><?= htmlspecialchars(session_id() ?: 'N/A') ?></span>
          <span class="text-muted small ms-1"><?= htmlspecialchars($_SESSION['nome'] ?? '—') ?></span>
        </span>
      </div>
    </div>
    <div class="card-footer">
      <div class="fw-semibold small mb-1">Diretórios</div>
      <div class="info-grid">
        <?php foreach ($dirs as $nome_dir => $info): ?>
          <span class="label"><?= htmlspecialchars($nome_dir) ?></span>
          <span class="value">
            <code class="small"><?= htmlspecialchars($info['path']) ?></code>
            <?php if ($info['existe']): ?>
              <span class="badge-dir bg-success text-white">OK</span>
            <?php else: ?>
              <span class="badge-dir bg-secondary text-white">Inexistente</span>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/notificacoes.js"></script>
<script>
(function() {
  'use strict';

  window.executarAcao = function(action, btn) {
    var resultId = btn.getAttribute('data-result');
    var resultBox = document.getElementById(resultId);
    if (!resultBox) return;

    // Loading state
    btn.classList.add('btn-spinner');
    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Aguarde...';

    resultBox.className = 'result-box';
    resultBox.textContent = 'Aguardando resposta...';
    resultBox.style.display = 'block';
    resultBox.className = 'result-box info';
    resultBox.textContent = 'Executando...';

    var params = new URLSearchParams({ action: action });

    fetch('manutencao.php?' + params.toString())
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.classList.remove('btn-spinner');
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        if (data.ok) {
          resultBox.className = 'result-box success';
          if (action === 'sincronizar') {
            resultBox.textContent = data.output || 'Sincronia concluída sem saída.';
          } else if (action === 'testar_api') {
            var lines = [];
            lines.push('HTTP Status: ' + (data.http_code || 'N/A'));
            lines.push('Session Token: ' + (data.session_token || 'N/A'));
            lines.push('Versão GLPI: ' + (data.versao || 'N/A'));
            lines.push('Mensagem: ' + (data.msg || 'OK'));
            resultBox.textContent = lines.join('\n');
          } else if (action === 'limpar_cache') {
            var detalhes = data.detalhes && data.detalhes.length
              ? data.detalhes.join('\n')
              : 'Nada a limpar.';
            resultBox.textContent = data.msg + '\n' + detalhes;
          } else {
            resultBox.textContent = data.msg || 'OK';
          }
        } else {
          resultBox.className = 'result-box error';
          resultBox.textContent = 'Erro: ' + (data.msg || 'Resposta inválida');
        }
      })
      .catch(function(err) {
        btn.classList.remove('btn-spinner');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
      });
  };

  // ── Testar MySQL direto no clique (sem AJAX, já renderizado) ──
  // O card de informações do sistema já mostra o status do MySQL vindo do PHP.
  // Adiciona um atalho para re-testar clicando no badge de MySQL
  var mysqlBadge = document.querySelector('.info-grid .badge.bg-success, .info-grid .badge.bg-danger');
  if (mysqlBadge) {
    mysqlBadge.style.cursor = 'pointer';
    mysqlBadge.title = 'Clique para re-testar';
    mysqlBadge.addEventListener('click', function() {
      var originalText = this.textContent;
      this.textContent = 'Testando...';
      this.style.opacity = '0.6';

      fetch('manutencao.php?action=testar_mysql')
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.ok) {
            mysqlBadge.className = 'badge bg-success';
            mysqlBadge.textContent = 'Conectado';
            // Atualiza a versão ao lado
            var versaoSpan = mysqlBadge.parentElement.querySelector('.text-muted');
            if (versaoSpan && data.versao) {
              versaoSpan.textContent = '(' + data.versao + ')';
            }
          } else {
            mysqlBadge.className = 'badge bg-danger';
            mysqlBadge.textContent = 'Desconectado';
          }
          mysqlBadge.style.opacity = '1';
        })
        .catch(function() {
          mysqlBadge.className = 'badge bg-danger';
          mysqlBadge.textContent = 'Erro no teste';
          mysqlBadge.style.opacity = '1';
        });
    });
  }

  // ── Banco de Dados GLPI: tamanho das tabelas + OPTIMIZE TABLE ──
  window.analisarTabelas = function() {
    var btn      = document.getElementById('btnAnalisarTabelas');
    var resumo   = document.getElementById('dbResumo');
    var wrap     = document.getElementById('dbTabelasWrap');
    var body     = document.getElementById('dbTabelasBody');
    var resultBox = document.getElementById('resultDb');

    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Analisando...';
    resumo.textContent = '';

    fetch('manutencao.php?action=tamanho_tabelas')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (!data.ok) {
          resultBox.className = 'result-box error';
          resultBox.textContent = 'Erro: ' + (data.msg || 'Falha ao consultar tabelas');
          resultBox.style.display = 'block';
          return;
        }
        resumo.textContent = data.qtd_tabelas + ' tabelas · ' + data.total_mb + ' MB no total (banco todo)';
        body.innerHTML = data.tabelas.map(function(t) {
          return '<tr id="tr-' + t.tabela + '">' +
            '<td><code>' + t.tabela + '</code></td>' +
            '<td class="text-end">' + Number(t.linhas || 0).toLocaleString('pt-BR') + '</td>' +
            '<td class="text-end">' + t.dados_mb + '</td>' +
            '<td class="text-end">' + t.indices_mb + '</td>' +
            '<td class="text-end fw-semibold">' + t.total_mb + '</td>' +
            '<td class="text-end">' +
              '<button class="btn btn-outline-warning btn-sm py-0 px-2" style="font-size:.72rem" onclick="otimizarTabela(\'' + t.tabela + '\', this)">Otimizar</button>' +
            '</td>' +
          '</tr>';
        }).join('');
        wrap.style.display = '';
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
        resultBox.style.display = 'block';
      });
  };

  window.otimizarTabela = function(tabela, btn) {
    if (!confirm('Rodar OPTIMIZE TABLE em "' + tabela + '"?\n\nIsso reorganiza a tabela para recuperar espaço em disco. Não apaga nenhum dado, mas em tabelas grandes pode demorar e travar acessos a essa tabela por alguns instantes.\n\nContinuar?')) {
      return;
    }
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    var resultBox = document.getElementById('resultDb');

    fetch('manutencao.php?action=otimizar_tabela&tabela=' + encodeURIComponent(tabela))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        resultBox.style.display = 'block';
        if (data.ok) {
          resultBox.className = 'result-box success';
          var msgs = (data.resultado || []).map(function(r) { return r.Msg_text || JSON.stringify(r); }).join(' · ');
          resultBox.textContent = 'OPTIMIZE TABLE ' + tabela + ' concluído: ' + (msgs || 'OK');
          analisarTabelas(); // atualiza os tamanhos após otimizar
        } else {
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          resultBox.className = 'result-box error';
          resultBox.textContent = 'Erro ao otimizar ' + tabela + ': ' + (data.msg || 'falha desconhecida');
        }
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultBox.className = 'result-box error';
        resultBox.style.display = 'block';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
      });
  };

  // ── Retenção de histórico (glpi_logs / PurgeLogs) ──
  function carregarPurgeLogsStatus() {
    var atualEl = document.getElementById('purgeLogsAtual');
    fetch('manutencao.php?action=purgelogs_status')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          atualEl.textContent = data.meses + ' meses';
          document.getElementById('purgeLogsMeses').value = String(data.meses);
        } else {
          atualEl.textContent = 'erro ao carregar';
        }
      })
      .catch(function() { atualEl.textContent = 'erro ao carregar'; });
  }
  document.addEventListener('DOMContentLoaded', carregarPurgeLogsStatus);

  window.purgeLogsSalvar = function() {
    var meses = document.getElementById('purgeLogsMeses').value;
    var resultBox = document.getElementById('resultPurgeLogs');
    if (!confirm('Mudar a retenção do histórico (glpi_logs) para ' + meses + ' meses?\n\nIsso não apaga o chamado nem sua conversa — só limita quanto tempo a aba "Histórico" (auditoria de mudança de campo) fica disponível. A limpeza roda automaticamente uma vez por semana.')) {
      return;
    }
    resultBox.style.display = 'block';
    resultBox.className = 'result-box info';
    resultBox.textContent = 'Salvando...';

    fetch('manutencao.php?action=purgelogs_salvar&meses=' + encodeURIComponent(meses))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          resultBox.className = 'result-box success';
          resultBox.textContent = 'Retenção atualizada para ' + data.meses + ' meses. Vale na próxima execução automática (semanal).';
          document.getElementById('purgeLogsAtual').textContent = data.meses + ' meses';
        } else {
          resultBox.className = 'result-box error';
          resultBox.textContent = 'Erro: ' + (data.msg || 'falha desconhecida');
        }
      })
      .catch(function(err) {
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
      });
  };

  window.purgeLogsExecutarAgora = function() {
    var meses = document.getElementById('purgeLogsMeses').value;
    var resultBox = document.getElementById('resultPurgeLogs');
    var progressWrap = document.getElementById('purgeLogsProgressWrap');
    var progressBar = document.getElementById('purgeLogsProgressBar');
    var progressTexto = document.getElementById('purgeLogsProgressTexto');

    if (!confirm('Isso vai APAGAR agora (sem esperar a execução semanal) as entradas de histórico com mais de ' + meses + ' meses. ' +
                 'Não afeta o chamado, conversa ou anexos — só a aba "Histórico" de auditoria. Essa ação não tem volta. Continuar?')) {
      return;
    }

    resultBox.style.display = 'block';
    resultBox.className = 'result-box info';
    resultBox.textContent = 'Calculando o que precisa ser apagado (pode levar um instante)...';
    progressWrap.style.display = '';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';

    fetch('manutencao.php?action=purgelogs_preparar&meses=' + encodeURIComponent(meses))
      .then(function(r) { return r.json(); })
      .then(function(prep) {
        if (!prep.ok) throw new Error(prep.msg || 'Falha ao preparar');
        var total = prep.total_apagar;
        if (total === 0) {
          resultBox.className = 'result-box success';
          resultBox.textContent = 'Nada pra apagar — já está tudo dentro da retenção de ' + meses + ' meses.';
          progressWrap.style.display = 'none';
          return;
        }
        progressTexto.textContent = '0 / ' + total + ' registros';
        var apagadosTotal = 0;

        function proximoLote() {
          fetch('manutencao.php?action=purgelogs_executar_lote')
            .then(function(r) { return r.json(); })
            .then(function(res) {
              if (!res.ok) throw new Error(res.msg || 'Falha no lote');
              apagadosTotal += res.apagados;
              var pct = total > 0 ? Math.min(100, Math.round((apagadosTotal / total) * 100)) : 100;
              progressBar.style.width = pct + '%';
              progressBar.textContent = pct + '%';
              progressTexto.textContent = apagadosTotal + ' / ' + total + ' registros apagados';

              if (!res.concluido && res.apagados > 0) {
                proximoLote();
              } else {
                resultBox.className = 'result-box success';
                resultBox.textContent = 'Concluído! ' + apagadosTotal + ' registros de histórico apagados. ' +
                  'Rode "Analisar Tamanho das Tabelas" e depois "Otimizar" em glpi_logs pra recuperar o espaço em disco.';
              }
            })
            .catch(function(err) {
              resultBox.className = 'result-box error';
              resultBox.textContent = 'Erro durante a exclusão (parcial, ' + apagadosTotal + ' já apagados): ' + (err.message || err);
            });
        }
        proximoLote();
      })
      .catch(function(err) {
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro: ' + (err.message || err);
        progressWrap.style.display = 'none';
      });
  };

  // ── Otimização de imagens (PNG) dos chamados ──
  window.pngBackup = function() {
    var btn = document.getElementById('btnPngBackup');
    var resultBox = document.getElementById('resultPng');
    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Copiando (pode demorar alguns minutos)...';
    resultBox.style.display = 'block';
    resultBox.className = 'result-box info';
    resultBox.textContent = 'Fazendo backup da pasta files/PNG...';

    fetch('manutencao.php?action=png_backup')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (data.ok) {
          resultBox.className = 'result-box success';
          resultBox.textContent = 'Backup criado: ' + data.destino + ' (' + data.qtd + ' arquivos).';
        } else {
          resultBox.className = 'result-box error';
          resultBox.textContent = 'Erro no backup: ' + (data.msg || 'falha desconhecida');
        }
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
      });
  };

  window.pngOtimizarTudo = function() {
    if (!confirm('Isso vai reprocessar os PNGs novos anexados aos chamados desde a última execução (reduzindo a paleta de cores). ' +
                 'Só sobrescreve o arquivo se ele ficar menor. Continuar?')) {
      return;
    }
    var btnBackup   = document.getElementById('btnPngBackup');
    var btnOtimizar = document.getElementById('btnPngOtimizar');
    var progressWrap = document.getElementById('pngProgressWrap');
    var progressBar  = document.getElementById('pngProgressBar');
    var progressTexto = document.getElementById('pngProgressTexto');
    var resultBox = document.getElementById('resultPng');

    btnBackup.disabled = true;
    btnOtimizar.disabled = true;
    progressWrap.style.display = '';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
    resultBox.style.display = 'block';
    resultBox.className = 'result-box info';
    resultBox.textContent = 'Listando arquivos...';

    var LOTE = 50;
    var totalOtimizados = 0;
    var totalEconomizado = 0;
    var totalErros = [];

    fetch('manutencao.php?action=png_listar')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) throw new Error(data.msg || 'Falha ao listar arquivos');
        var total = data.total;
        progressTexto.textContent = '0 / ' + total + ' arquivos';

        function processarLote(offset) {
          fetch('manutencao.php?action=png_otimizar&offset=' + offset + '&limit=' + LOTE)
            .then(function(r) { return r.json(); })
            .then(function(res) {
              if (!res.ok) throw new Error(res.msg || 'Falha ao otimizar lote');

              totalOtimizados  += res.otimizados;
              totalEconomizado += res.economizado;
              if (res.erros && res.erros.length) totalErros = totalErros.concat(res.erros);

              var processadosAte = Math.min(offset + LOTE, total);
              var pct = total > 0 ? Math.round((processadosAte / total) * 100) : 100;
              progressBar.style.width = pct + '%';
              progressBar.textContent = pct + '%';
              progressTexto.textContent = processadosAte + ' / ' + total + ' arquivos · ' +
                totalOtimizados + ' otimizados · ' + (totalEconomizado / 1024 / 1024).toFixed(1) + ' MB economizados';

              if (!res.concluido) {
                processarLote(offset + LOTE);
              } else {
                btnOtimizar.disabled = false;
                btnBackup.disabled = false;
                resultBox.className = 'result-box success';
                resultBox.textContent = 'Concluído! ' + totalOtimizados + ' de ' + total + ' imagens otimizadas, ' +
                  (totalEconomizado / 1024 / 1024).toFixed(1) + ' MB economizados.' +
                  (totalErros.length ? ' (' + totalErros.length + ' arquivo(s) com erro ao processar.)' : '');
              }
            })
            .catch(function(err) {
              btnOtimizar.disabled = false;
              btnBackup.disabled = false;
              resultBox.className = 'result-box error';
              resultBox.textContent = 'Erro durante otimização (parcial, processados ' + offset + ' de ' + total + '): ' + (err.message || err);
            });
        }

        processarLote(0);
      })
      .catch(function(err) {
        btnOtimizar.disabled = false;
        btnBackup.disabled = false;
        resultBox.className = 'result-box error';
        resultBox.textContent = 'Erro de conexão: ' + (err.message || err);
      });
  };

})();
</script>
</body>
</html>
