<?php
/**
 * solides_config.php — liga o portal ao Ponto (API Sólides) nos dois sentidos:
 *   - URL do webhook (com token) pra colar no painel do Ponto — alerta em tempo real;
 *   - token X-Token-Saude + URL do Ponto — relatório do chamado diário.
 * Mostra também o último diagnóstico recebido. As regras de alerta (minutos,
 * WhatsApp, lembrete) ficam em alertas_config.php, como os outros tipos.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/solides_lib.php';

$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

const SOLIDES_WEBHOOK_BASE = 'https://ti.grupogmais.com:7412/glpi2/portal-glpi/webhook_solides.php';

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'status') {
        $token      = solides_webhook_token();
        $tokenSaude = solides_token_saude();
        $ultimo     = solides_ultimo_diagnostico($pdo);
        echo json_encode([
            'ok'          => true,
            'webhook_url' => $token !== '' ? (SOLIDES_WEBHOOK_BASE . '?token=' . $token) : null,
            // token do Ponto nunca volta inteiro pro navegador — só o final, pra conferir
            'token_saude' => $tokenSaude !== '' ? ('••••' . substr($tokenSaude, -4)) : null,
            'base_url'    => solides_base_url(),
            'ultimo'      => $ultimo ? [
                'status'      => $ultimo['status'],
                'resumo'      => $ultimo['resumo'],
                'evento'      => $ultimo['evento'],
                'recebido_em' => $ultimo['recebido_em'],
            ] : null,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }

    if ($action === 'gerar_token') {
        try {
            $token = solides_gerar_webhook_token();
            echo json_encode(['ok' => true, 'webhook_url' => SOLIDES_WEBHOOK_BASE . '?token=' . $token]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao gerar token']);
        }
        exit;
    }

    if ($action === 'salvar_ponto') {
        $baseUrl    = trim((string) ($_POST['base_url'] ?? ''));
        $tokenSaude = trim((string) ($_POST['token_saude'] ?? ''));
        if ($baseUrl !== '' && !preg_match('#^https?://\S+$#i', $baseUrl)) {
            echo json_encode(['ok' => false, 'erro' => 'URL inválida — precisa começar com http:// ou https://']);
            exit;
        }
        wpp_cfg_set('solides_base_url', rtrim($baseUrl, '/'));
        // campo vazio = mantém o token atual (a tela não recebe o token inteiro de volta)
        if ($tokenSaude !== '') wpp_cfg_set('solides_token_saude', $tokenSaude);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'testar_relatorio') {
        $r = solides_buscar_relatorio();
        echo json_encode($r['ok']
            ? ['ok' => true, 'texto' => solides_relatorio_texto($r['relatorio'])]
            : ['ok' => false, 'erro' => $r['erro']]);
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Ponto — API Sólides</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:820px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    .url-box { display:flex; gap:.4rem; margin-top:.5rem; align-items:center; }
    .url-box input { font-size:.78rem; font-family:monospace; }
    .status-linha { font-size:.85rem; color:#6b7280; margin-top:.5rem; }
    .runbook { font-size:.85rem; color:#374151; }
    .runbook ol { padding-left:1.2rem; }
    pre.rel { background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:.75rem; font-size:.75rem; white-space:pre-wrap; margin:.5rem 0 0; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-fingerprint"></i> Ponto — API Sólides</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="alertas_config.php"><i class="bi bi-arrow-left me-1"></i>Configurar Alertas</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <div class="card-box">
    <h6 class="mb-2">Alerta em tempo real — webhook</h6>
    <p class="small text-muted mb-2">Cole esta URL no painel do Ponto (Saúde do sistema → Monitoramento externo) e marque <b>Ativo</b>. O Ponto manda o diagnóstico a cada 5 min.</p>
    <div id="semToken" class="d-none small text-muted">Nenhum token gerado ainda. Gere um pra criar a URL do webhook.</div>
    <div id="comToken" class="d-none">
      <div class="url-box">
        <input type="text" id="webhook-url" class="form-control form-control-sm" readonly>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="copiarUrl()"><i class="bi bi-clipboard"></i></button>
      </div>
    </div>
    <div class="status-linha" id="ultimo">último diagnóstico: —</div>
    <div class="d-flex gap-2 mt-3">
      <button type="button" class="btn btn-primary btn-sm" onclick="gerarToken()"><i class="bi bi-key me-1"></i>Gerar novo token</button>
    </div>
    <div id="fb" class="small mt-2"></div>
  </div>

  <div class="card-box">
    <h6 class="mb-2">Relatório do chamado diário — acesso ao Ponto</h6>
    <p class="small text-muted mb-2">Token <code>X-Token-Saude</code> do painel do Ponto (Saúde do sistema → Monitoramento externo → Mostrar/Copiar).</p>
    <div class="d-flex gap-2 flex-wrap align-items-end">
      <div style="flex:1;min-width:220px"><label class="form-label small mb-0">URL do Ponto</label>
        <input type="text" id="base-url" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(SOLIDES_BASE_URL_PADRAO) ?>"></div>
      <div style="flex:1;min-width:220px"><label class="form-label small mb-0">Token do Ponto <span id="token-atual" class="text-muted"></span></label>
        <input type="password" id="token-saude" class="form-control form-control-sm" placeholder="em branco = mantém o atual" autocomplete="off"></div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarPonto()"><i class="bi bi-save me-1"></i>Salvar</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testarRelatorio()"><i class="bi bi-play-circle me-1"></i>Testar relatório</button>
    </div>
    <div id="fb-ponto" class="small mt-2"></div>
    <pre id="rel" class="rel d-none"></pre>
  </div>

  <div class="card-box runbook">
    <h6 class="mb-2">Onde isso aparece</h6>
    <ol>
      <li><b>Central de Alertas:</b> tipos "Ponto (API Sólides) com problema" e "Ponto (API Sólides) sem contato" — ligar/desligar WhatsApp e lembrete em Configurar Alertas.</li>
      <li><b>Agenda:</b> ao responder a rotina "Backup, Relatórios e Banco de Dados", a caixa "🕒 Ponto (API Sólides)" traz as anormalidades de ontem e hoje e entra na resposta do chamado.</li>
    </ol>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Ponto</footer>

<script>
const fb = (id, msg, ok) => { const el = document.getElementById(id); el.className = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger'); el.textContent = msg; };
const post = (action, dados = {}) => fetch('?action=' + action, { method: 'POST', body: new URLSearchParams(dados) }).then(r => r.json());

function carregar() {
  fetch('?action=status').then(r => r.json()).then(d => {
    if (!d.ok) return;
    document.getElementById('semToken').classList.toggle('d-none', !!d.webhook_url);
    document.getElementById('comToken').classList.toggle('d-none', !d.webhook_url);
    if (d.webhook_url) document.getElementById('webhook-url').value = d.webhook_url;
    document.getElementById('base-url').value = d.base_url || '';
    document.getElementById('token-atual').textContent = d.token_saude ? '(atual: ' + d.token_saude + ')' : '(não configurado)';
    const u = d.ultimo;
    document.getElementById('ultimo').textContent = u
      ? 'último diagnóstico: ' + u.recebido_em + ' · ' + u.status + (u.evento === 'teste' ? ' (teste)' : '') + ' — ' + u.resumo
      : 'último diagnóstico: nunca recebido';
  });
}

function copiarUrl() {
  navigator.clipboard.writeText(document.getElementById('webhook-url').value).then(() => fb('fb', 'URL copiada.', true));
}

function gerarToken() {
  post('gerar_token').then(d => {
    if (!d.ok) return fb('fb', d.erro || 'falha', false);
    fb('fb', 'Token novo gerado — atualize a URL no painel do Ponto (a antiga parou de valer).', true);
    carregar();
  });
}

function salvarPonto() {
  post('salvar_ponto', {
    base_url: document.getElementById('base-url').value,
    token_saude: document.getElementById('token-saude').value,
  }).then(d => {
    if (!d.ok) return fb('fb-ponto', d.erro || 'falha', false);
    document.getElementById('token-saude').value = '';
    fb('fb-ponto', 'Salvo.', true);
    carregar();
  });
}

function testarRelatorio() {
  const rel = document.getElementById('rel');
  fb('fb-ponto', 'Consultando o Ponto…', true);
  post('testar_relatorio').then(d => {
    if (!d.ok) { rel.classList.add('d-none'); return fb('fb-ponto', d.erro || 'falha', false); }
    fb('fb-ponto', 'Relatório recebido.', true);
    rel.textContent = d.texto;
    rel.classList.remove('d-none');
  }).catch(() => fb('fb-ponto', 'falha de conexão com o portal', false));
}

carregar();
</script>
</body>
</html>
