<?php
/**
 * reconhecimento_config.php — configura a integração com o Reconhecimento Facial (coletor FaceEventsIntelbras):
 *   - URL do webhook (com token) para colar no .env do coletor — alerta em tempo real.
 *   - Exibição do último diagnóstico recebido.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/reconhecimento_lib.php';

$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

const RECONHECIMENTO_WEBHOOK_BASE = 'https://ti.grupogmais.com:7412/glpi2/portal-glpi/webhook_reconhecimento.php';

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'status') {
        $token  = reconhecimento_webhook_token();
        $ultimo = reconhecimento_ultimo_diagnostico($pdo);
        echo json_encode([
            'ok'          => true,
            'webhook_url' => $token !== '' ? (RECONHECIMENTO_WEBHOOK_BASE . '?token=' . $token) : null,
            'ultimo'      => $ultimo ? [
                'status'      => $ultimo['status'],
                'resumo'      => $ultimo['resumo'],
                'evento'      => $ultimo['evento'],
                'recebido_em' => $ultimo['recebido_em'],
                // [SPEC: reconhecimento-resumo-rotina-diaria] conferência rápida
                // da última face sem precisar abrir o chamado da Agenda.
                'ultima_face' => !empty($ultimo['ultima_face_quando']) ? [
                    'quando'    => $ultimo['ultima_face_quando'],
                    'nome'      => (string) ($ultimo['ultima_face_nome'] ?? ''),
                    'canal'     => (string) ($ultimo['ultima_face_canal'] ?? ''),
                    'thumb_b64' => $ultimo['ultima_face_thumb'] !== null ? (string) $ultimo['ultima_face_thumb'] : null,
                ] : null,
            ] : null,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }

    if ($action === 'gerar_token') {
        try {
            $token = reconhecimento_gerar_webhook_token();
            echo json_encode(['ok' => true, 'webhook_url' => RECONHECIMENTO_WEBHOOK_BASE . '?token=' . $token]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao gerar token']);
        }
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
  <title>Reconhecimento Facial — Monitoramento</title>
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
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
  <script src="sessao_guard.js"></script>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-camera-video"></i> Reconhecimento Facial — Monitoramento</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="alertas_config.php"><i class="bi bi-arrow-left me-1"></i>Configurar Alertas</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <div class="card-box">
    <h6 class="mb-2">Alerta em tempo real — webhook</h6>
    <p class="small text-muted mb-2">Cole esta URL no arquivo <code>.env</code> do coletor de Reconhecimento Facial (variável de webhook) e reinicie o serviço. O coletor enviará o diagnóstico periodicamente.</p>
    <div id="semToken" class="d-none small text-muted">Nenhum token gerado ainda. Gere um para criar a URL do webhook.</div>
    <div id="comToken" class="d-none">
      <div class="url-box">
        <input type="text" id="webhook-url" class="form-control form-control-sm" readonly>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="copiarUrl()"><i class="bi bi-clipboard"></i></button>
      </div>
    </div>
    <div class="status-linha" id="ultimo">último diagnóstico: —</div>
    <!-- [SPEC: reconhecimento-resumo-rotina-diaria] última face recebida —
         conferência rápida de que a captura está funcionando. -->
    <div id="ultimaFaceWrap" class="d-none mt-2" style="display:flex;align-items:center;gap:.6rem">
      <img id="ultimaFaceImg" style="max-width:90px;border-radius:6px" alt="Última face detectada"/>
      <small id="ultimaFaceLegenda" class="text-muted"></small>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button type="button" class="btn btn-primary btn-sm" onclick="gerarToken()"><i class="bi bi-key me-1"></i>Gerar novo token</button>
    </div>
    <div id="fb" class="small mt-2"></div>
  </div>

  <div class="card-box runbook">
    <h6 class="mb-2">Como configurar no Coletor (FaceEventsIntelbras)</h6>
    <ol>
      <li>No arquivo <code>.env</code> do coletor, defina a URL do webhook com o token gerado acima.</li>
      <li>Configure o intervalo de envio (ex: a cada 5 minutos).</li>
      <li>Reinicie o serviço do coletor para aplicar as variáveis de ambiente.</li>
      <li>Na <b>Central de Alertas</b>, os tipos <i>"Reconhecimento Facial com problema"</i> e <i>"Reconhecimento Facial sem contato"</i> avisarão via WhatsApp caso haja falhas nas checagens (Defense, Evolution, WhatsApp, Envio) ou interrupção do envio.</li>
    </ol>
  </div>
</div>

<footer>Portal GLPI &bull; Reconhecimento Facial</footer>

<script>
// [review] achado pré-existente (não introduzido por esta spec, mas corrigido
// de passagem por estar na mesma função): u.resumo vem do payload do webhook
// (coletor autenticado só por token) e ia direto pra innerHTML sem escapar.
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function carregarStatus() {
    try {
        const res = await fetch('reconhecimento_config.php?action=status');
        const d = await res.json();
        if (!d.ok) return;

        if (d.webhook_url) {
            document.getElementById('comToken').classList.remove('d-none');
            document.getElementById('semToken').classList.add('d-none');
            document.getElementById('webhook-url').value = d.webhook_url;
        } else {
            document.getElementById('comToken').classList.add('d-none');
            document.getElementById('semToken').classList.remove('d-none');
        }

        if (d.ultimo) {
            const u = d.ultimo;
            const badge = u.status === 'ok' ? '✅' : (u.status === 'alerta' ? '⚠️' : '❌');
            document.getElementById('ultimo').innerHTML = `último diagnóstico: <b>${badge} ${escHtml(u.status.toUpperCase())}</b> &mdash; ${escHtml(u.resumo || '(sem resumo)')} <span class="text-muted">(${escHtml(u.recebido_em)})</span>`;

            // [SPEC: reconhecimento-resumo-rotina-diaria] critério de aceite #3 —
            // mostra aviso explícito em vez de só esconder quando o coletor
            // ainda não manda a última face.
            const faceWrap = document.getElementById('ultimaFaceWrap');
            const faceImg = document.getElementById('ultimaFaceImg');
            const faceLegenda = document.getElementById('ultimaFaceLegenda');
            if (u.ultima_face && u.ultima_face.thumb_b64) {
                faceImg.style.display = '';
                faceImg.src = 'data:image/jpeg;base64,' + u.ultima_face.thumb_b64;
                faceLegenda.textContent = 'Última face: ' + (u.ultima_face.nome || '?') + ' em ' + u.ultima_face.quando + (u.ultima_face.canal ? ' (' + u.ultima_face.canal + ')' : '');
                faceWrap.classList.remove('d-none');
            } else {
                faceImg.style.display = 'none';
                faceImg.removeAttribute('src');
                faceLegenda.textContent = 'coletor ainda não envia a última face — atualizar o coletor';
                faceWrap.classList.remove('d-none');
            }
        } else {
            document.getElementById('ultimo').textContent = 'último diagnóstico: nenhum recebido ainda';
        }
    } catch (e) {
        console.error(e);
    }
}

async function gerarToken() {
    if (!confirm('Gerar um novo token invalidará o anterior. Deseja continuar?')) return;
    try {
        const res = await fetch('reconhecimento_config.php?action=gerar_token', { method: 'POST' });
        const d = await res.json();
        if (d.ok) {
            carregarStatus();
            document.getElementById('fb').innerHTML = '<span class="text-success">Token gerado com sucesso!</span>';
        } else {
            document.getElementById('fb').innerHTML = '<span class="text-danger">' + (d.erro || 'Erro ao gerar token') + '</span>';
        }
    } catch (e) {
        document.getElementById('fb').innerHTML = '<span class="text-danger">Erro de rede</span>';
    }
}

function copiarUrl() {
    const input = document.getElementById('webhook-url');
    input.select();
    document.execCommand('copy');
    document.getElementById('fb').innerHTML = '<span class="text-success">URL copiada para a área de transferência!</span>';
    setTimeout(() => { document.getElementById('fb').textContent = ''; }, 3000);
}

carregarStatus();
setInterval(carregarStatus, 15000);
</script>
</body>
</html>
