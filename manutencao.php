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

})();
</script>
</body>
</html>
