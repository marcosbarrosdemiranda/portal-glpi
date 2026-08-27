<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

header('Content-Type: text/html; charset=utf-8');

$log_dir = __DIR__ . '/Portal-Glpi/Logs/';
$log_dir_real = realpath($log_dir);
if (!$log_dir_real) {
    $log_dir_real = $log_dir;
}

// ── Handlers AJAX ─────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$arquivo = $_GET['arquivo'] ?? ($_POST['arquivo'] ?? '');

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // ── Listar arquivos de log ──────────────────────────────────
    if ($action === 'listar') {
        $arquivos = [];
        if (is_dir($log_dir_real)) {
            $itens = new DirectoryIterator($log_dir_real);
            foreach ($itens as $item) {
                if ($item->isFile() && $item->getExtension() === 'md') {
                    $arquivos[] = [
                        'nome'            => $item->getFilename(),
                        'tamanho'         => $item->getSize(),
                        'tamanho_formatado' => formatBytes($item->getSize()),
                        'data_modificacao' => date('d/m/Y H:i:s', $item->getMTime()),
                        'data_modificacao_raw' => $item->getMTime(),
                    ];
                }
            }
        }
        // Ordena por data decrescente (mais recente primeiro)
        usort($arquivos, fn($a, $b) => $b['data_modificacao_raw'] - $a['data_modificacao_raw']);
        echo json_encode(['ok' => true, 'arquivos' => $arquivos]);
        exit;
    }

    // ── Validar path seguro ─────────────────────────────────────
    if ($arquivo === '') {
        echo json_encode(['ok' => false, 'msg' => 'Arquivo não especificado']);
        exit;
    }

    $caminho = realpath($log_dir_real . '/' . basename($arquivo));

    // Prevenção path traversal: garante que resolveu e está dentro do diretório de logs
    if ($caminho === false || strpos($caminho, rtrim($log_dir_real, '\\/')) !== 0) {
        echo json_encode(['ok' => false, 'msg' => 'Arquivo inválido']);
        exit;
    }

    if (!file_exists($caminho)) {
        echo json_encode(['ok' => false, 'msg' => 'Arquivo não encontrado']);
        exit;
    }

    // ── Visualizar conteúdo ─────────────────────────────────────
    if ($action === 'visualizar') {
        $conteudo = file_get_contents($caminho);
        echo json_encode(['ok' => true, 'conteudo' => $conteudo]);
        exit;
    }

    // ── Baixar arquivo ──────────────────────────────────────────
    if ($action === 'baixar') {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    }

    // ── Limpar logs (excluir) ──────────────────────────────────
    if ($action === 'limpar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'msg' => 'Método não permitido']);
            exit;
        }
        $excluidos = 0;
        $erros = 0;
        $itens = new DirectoryIterator($log_dir_real);
        foreach ($itens as $item) {
            if ($item->isFile() && $item->getExtension() === 'md') {
                if (unlink($item->getPathname())) {
                    $excluidos++;
                } else {
                    $erros++;
                }
            }
        }
        echo json_encode(['ok' => true, 'excluidos' => $excluidos, 'erros' => $erros]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────
function formatBytes(int $bytes, int $decimals = 1): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $decimals) . ' ' . $units[$i];
}

// ── Contagem inicial ─────────────────────────────────────────────
$total_logs = 0;
if (is_dir($log_dir_real)) {
    $total_logs = count(glob($log_dir_real . '/*.md'));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Logs do Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{--primary:#1d4ed8;}*{box-sizing:border-box;}
    body{background:#f0f4f9;font-family:'Segoe UI',sans-serif;min-height:100vh;margin:0;}
    .topbar{background:linear-gradient(135deg,#1e3a8a,var(--primary));color:white;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.25);position:sticky;top:0;z-index:100;}
    .topbar .brand{font-weight:700;font-size:1rem;display:flex;align-items:center;gap:.5rem;}
    .topbar a{color:white;text-decoration:none;font-size:.82rem;background:rgba(255,255,255,.15);border-radius:6px;padding:.3rem .75rem;transition:background .15s;}
    .topbar a:hover{background:rgba(255,255,255,.25);}
    .topbar .badge-logs{background:rgba(255,255,255,.2);border-radius:20px;padding:.15rem .6rem;font-size:.7rem;font-weight:400;margin-left:.35rem;}
    .hero{background:linear-gradient(135deg,#1e3a8a,var(--primary));color:white;padding:2rem 1rem 4rem;text-align:center;}
    .hero h1{font-size:1.5rem;font-weight:700;margin:0}
    .hero p{opacity:.8;margin-top:.5rem}
    .wrap{max-width:1000px;margin:-2.5rem auto 3rem;padding:0 1rem;}
    .card-toolbar{background:white;border-radius:12px;border:1px solid #e5e7eb;padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;}
    .card-toolbar .info{font-size:.85rem;color:#6b7280;}
    .card-toolbar .info strong{color:#111;}
    .table-logs{background:white;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;}
    .table-logs table{margin-bottom:0;}
    .table-logs th{background:#f9fafb;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;border-bottom:2px solid #e5e7eb;padding:.75rem 1rem;}
    .table-logs td{padding:.65rem 1rem;font-size:.88rem;vertical-align:middle;border-bottom:1px solid #f3f4f6;}
    .table-logs tr:last-child td{border-bottom:none;}
    .table-logs tr:hover td{background:#f9fafb;}
    .table-logs .nome-arquivo{font-family:Consolas,monospace;font-size:.82rem;color:#1e3a8a;font-weight:500;}
    .table-logs .acoes{white-space:nowrap;display:flex;gap:.3rem;}
    .table-logs .acoes .btn-icon{border:none;background:transparent;color:#6b7280;border-radius:6px;padding:.3rem .5rem;font-size:.82rem;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:.25rem;}
    .table-logs .acoes .btn-icon:hover{background:#e5e7eb;color:#111;}
    .table-logs .acoes .btn-icon.ver{color:#1d4ed8;}
    .table-logs .acoes .btn-icon.ver:hover{background:#dbeafe;}
    .table-logs .acoes .btn-icon.baixar{color:#059669;}
    .table-logs .acoes .btn-icon.baixar:hover{background:#d1fae5;}
    .vazio{text-align:center;padding:4rem 1rem;color:#9ca3af;}
    .vazio i{font-size:3rem;display:block;margin-bottom:1rem;}
    .vazio p{font-size:.92rem;margin:0;}
    .modal-log-content{background:#1e293b;color:#e2e8f0;border-radius:8px;padding:1.25rem;font-family:Consolas,monospace;font-size:.82rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;max-height:65vh;overflow-y:auto;}
    .spinner-log{border:3px solid #e5e7eb;border-top-color:var(--primary);border-radius:50%;width:32px;height:32px;animation:spin .7s linear infinite;margin:2rem auto;}
    @keyframes spin{to{transform:rotate(360deg)}}
    #toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;}
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-journal-text me-1"></i> Logs do Portal</div>
  <div style="display:flex;gap:.5rem">
    <button class="btn btn-sm" style="background:rgba(255,255,255,.15);border:none;color:white;border-radius:6px;padding:.3rem .75rem;font-size:.82rem;cursor:pointer" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="hero">
  <h1><i class="bi bi-journal-text me-2"></i>Logs do Sistema</h1>
  <p>Registros de sessão e alterações do portal — <span id="total-label"><?= $total_logs ?> arquivo(s)</span></p>
</div>

<div class="wrap">

  <!-- Toolbar -->
  <div class="card-toolbar">
    <div class="info">
      <i class="bi bi-folder2-open me-1"></i>
      <strong id="qtd-arquivos"><?= $total_logs ?></strong> arquivo(s) de log
      <span class="text-muted mx-1">|</span>
      <i class="bi bi-clock me-1"></i> Ordenados por data <span class="text-muted">(mais recente)</span>
    </div>
    <div>
      <button class="btn btn-sm btn-outline-danger" onclick="confirmarLimpar()" title="Excluir todos os logs">
        <i class="bi bi-trash3 me-1"></i>Limpar Logs
      </button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="table-logs">
    <table class="table">
      <thead>
        <tr>
          <th style="width:45%">Arquivo</th>
          <th style="width:20%">Modificação</th>
          <th style="width:12%">Tamanho</th>
          <th style="width:23%">Ações</th>
        </tr>
      </thead>
      <tbody id="tbody-logs">
        <tr><td colspan="4" class="text-center py-4 text-muted">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Visualizar -->
<div class="modal fade" id="modalVisualizar" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white">
        <h5 class="modal-title fw-bold" id="modal-vis-title"><i class="bi bi-eye me-2"></i>Visualizar Log</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background:#0f172a;">
        <div id="modal-vis-content" class="modal-log-content"></div>
        <div id="modal-vis-spinner" class="spinner-log"></div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <span class="text-muted small" id="modal-vis-info"></span>
        <div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
          <button type="button" class="btn btn-sm btn-success" id="btn-download-modal"><i class="bi bi-download me-1"></i>Baixar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Confirmacao Limpar -->
<div class="modal fade" id="modalConfirmarLimpar" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#dc2626;color:white">
        <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Limpar Logs</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <i class="bi bi-trash3" style="font-size:2.5rem;color:#dc2626;display:block;margin-bottom:.75rem;"></i>
        <p class="mb-0 fw-semibold">Excluir todos os arquivos de log?</p>
        <p class="text-muted small mt-1 mb-0">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger btn-sm" id="btn-confirmar-limpar"><i class="bi bi-trash3 me-1"></i>Sim, excluir tudo</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    'use strict';

    const tbody = document.getElementById('tbody-logs');
    const qtdSpan = document.getElementById('qtd-arquivos');
    const totalLabel = document.getElementById('total-label');

    // ── Helpers ──────────────────────────────────────────────────
    function toast(tipo, msg) {
        const c = document.getElementById('toast-container');
        const div = document.createElement('div');
        div.className = 'toast align-items-center text-bg-' + tipo + ' border-0 show';
        div.role = 'alert';
        div.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi bi-' + (tipo==='success'?'check-circle-fill':'exclamation-triangle-fill') + ' me-2"></i>' + msg + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        c.appendChild(div);
        setTimeout(() => { div.remove(); }, 4000);
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    // ── Carregar listagem ────────────────────────────────────────
    function carregar() {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-log" style="margin:0 auto"></div><span class="text-muted small mt-2 d-block">Carregando...</span></td></tr>';

        fetch('?action=listar')
            .then(r => r.json())
            .then(res => {
                if (!res.ok || !res.arquivos) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger"><i class="bi bi-exclamation-circle me-1"></i>Erro ao carregar logs</td></tr>';
                    return;
                }

                const arquivos = res.arquivos;
                qtdSpan.textContent = arquivos.length;
                if (totalLabel) totalLabel.textContent = arquivos.length + ' arquivo(s)';

                if (arquivos.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4"><div class="vazio"><i class="bi bi-journal-text"></i><p>Nenhum arquivo de log encontrado.</p></div></td></tr>';
                    return;
                }

                let html = '';
                arquivos.forEach(function(arq) {
                    const nomeEncoded = encodeURIComponent(arq.nome);
                    html += '<tr>' +
                        '<td><span class="nome-arquivo"><i class="bi bi-filetype-md me-1 text-muted"></i>' + escapeHtml(arq.nome) + '</span></td>' +
                        '<td class="text-muted small">' + arq.data_modificacao + '</td>' +
                        '<td class="text-muted small">' + arq.tamanho_formatado + '</td>' +
                        '<td class="acoes">' +
                            '<button class="btn-icon ver" onclick="visualizar(\'' + nomeEncoded + '\',\'' + escapeJs(arq.nome) + '\')" title="Visualizar"><i class="bi bi-eye"></i> Ver</button>' +
                            '<button class="btn-icon baixar" onclick="baixar(\'' + nomeEncoded + '\')" title="Baixar"><i class="bi bi-download"></i></button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger"><i class="bi bi-exclamation-circle me-1"></i>Erro de conexão</td></tr>';
            });
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escapeJs(str) {
        return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // ── Visualizar ───────────────────────────────────────────────
    window.visualizar = function(nomeEncoded, nomeOriginal) {
        const title = document.getElementById('modal-vis-title');
        const content = document.getElementById('modal-vis-content');
        const spinner = document.getElementById('modal-vis-spinner');
        const info = document.getElementById('modal-vis-info');
        const btnDownload = document.getElementById('btn-download-modal');

        title.innerHTML = '<i class="bi bi-eye me-2"></i>' + escapeHtml(nomeOriginal);
        content.textContent = '';
        spinner.style.display = 'block';
        info.textContent = 'Carregando...';
        btnDownload.onclick = function() { baixar(nomeEncoded); };

        const modal = new bootstrap.Modal(document.getElementById('modalVisualizar'));
        modal.show();

        fetch('?action=visualizar&arquivo=' + nomeEncoded)
            .then(r => r.json())
            .then(function(res) {
                spinner.style.display = 'none';
                if (res.ok) {
                    content.textContent = res.conteudo;
                    const lines = res.conteudo.split('\n').length;
                    const size = new Blob([res.conteudo]).size;
                    info.textContent = lines + ' linhas, ' + formatBytes(size);
                } else {
                    content.textContent = 'Erro: ' + (res.msg || 'Falha ao carregar conteúdo');
                    content.style.color = '#f87171';
                    info.textContent = 'Erro';
                }
            })
            .catch(function() {
                spinner.style.display = 'none';
                content.textContent = 'Erro de conexão ao carregar o arquivo.';
                content.style.color = '#f87171';
                info.textContent = 'Erro';
            });
    };

    // ── Baixar ───────────────────────────────────────────────────
    window.baixar = function(nomeEncoded) {
        const a = document.createElement('a');
        a.href = '?action=baixar&arquivo=' + nomeEncoded;
        a.download = '';
        document.body.appendChild(a);
        a.click();
        a.remove();
        toast('success', 'Download iniciado');
    };

    // ── Limpar logs ──────────────────────────────────────────────
    window.confirmarLimpar = function() {
        const modal = new bootstrap.Modal(document.getElementById('modalConfirmarLimpar'));
        modal.show();
    };

    document.getElementById('btn-confirmar-limpar').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Excluindo...';

        fetch('?action=limpar', { method: 'POST' })
            .then(r => r.json())
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Sim, excluir tudo';
                bootstrap.Modal.getInstance(document.getElementById('modalConfirmarLimpar')).hide();

                if (res.ok) {
                    toast('success', res.excluidos + ' arquivo(s) excluído(s)' + (res.erros ? ', ' + res.erros + ' erro(s)' : ''));
                    carregar();
                } else {
                    toast('danger', res.msg || 'Erro ao limpar logs');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Sim, excluir tudo';
                bootstrap.Modal.getInstance(document.getElementById('modalConfirmarLimpar')).hide();
                toast('danger', 'Erro de conexão');
            });
    });

    // ── Iniciar ──────────────────────────────────────────────────
    carregar();
})();
</script>
</body>
</html>
