<?php
/**
 * Configuração do WhatsApp (Fase 1)
 * Duas abas:
 *   - Conexão: parear a linha do TI via QR code (Evolution API) e desconectar.
 *   - Grupos:  escolher qual grupo do WhatsApp recebe Alertas e qual recebe Chamados.
 * Sem envio automático de nada nesta fase — a tela só lê status / mostra QR / lista e salva grupos.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/wpp/db.php';
require_once __DIR__ . '/wpp/evo_api.php';
require_once __DIR__ . '/wpp/guardrails.php'; // wpp_norm_telefone()

// ── Checagem defensiva de permissão de perfil ──────────────────────────────
// Espelha config_notificacoes.php: se o usuário tem perfil restrito do portal
// e não possui a permissão 'notificacoes_config', volta pro dashboard.
// Fica ANTES do dispatch de ?action= para que as chamadas AJAX também sejam
// barradas (QR, logout, save_groups).
$cards = null;
if (array_key_exists('portal_perfil_cards', $_SESSION)) {
    // auth_guard.php já carregou (null = sem perfil; array = cards do perfil)
    $cards = $_SESSION['portal_perfil_cards'];
} else {
    require_once __DIR__ . '/agenda/db.php';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    try {
        $st = $pdo->prepare("
            SELECT pp.cards FROM portal_perfil_usuarios pu
            JOIN portal_perfis pp ON pp.id = pu.perfil_id
            WHERE pu.user_id = ?
        ");
        $st->execute([$uid]);
        $row = $st->fetch();
        if ($row) $cards = json_decode($row['cards'] ?? '{}', true) ?: [];
    } catch (Exception $e) { /* tabelas ainda não existem — ignora */ }
}
if ($cards !== null && !isset($cards['notificacoes_config'])) {
    header('Location: dashboard.php');
    exit;
}

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ─────────── Handlers AJAX (antes de qualquer HTML) ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    switch ($action) {
        case 'status':
            echo json_encode(evo_status());
            break;
        case 'ensure':
            echo json_encode(evo_ensure_instance());
            break;
        case 'qr':
            echo json_encode(evo_qr());
            break;
        case 'logout':
            // ação destrutiva: exige POST (evita logout via GET/URL)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            echo json_encode(evo_logout());
            break;
        case 'chatbot_webhook_status':
            $st = evo_webhook_status();
            echo json_encode(['ok' => $st['ok'], 'ativo' => $st['ativo'], 'erro' => $st['erro']]);
            break;
        case 'chatbot_webhook_toggle':
            // ação que liga/desliga recepção de mensagem: exige POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $ligar = (($_POST['ligar'] ?? '') === '1');
            echo json_encode(evo_set_webhook($ligar));
            break;
        case 'groups':
            echo json_encode(evo_groups());
            break;
        case 'save_groups':
            // ação destrutiva (regrava os JIDs): exige POST — um GET com
            // parâmetros vazios apagaria silenciosamente os dois grupos
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $a = trim($_POST['alertas_jid'] ?? '');
            $c = trim($_POST['chamados_jid'] ?? '');
            // aceita só JID de grupo do WhatsApp (dígitos, opcionalmente com hífen, + @g.us) ou string vazia
            foreach (['alertas' => $a, 'chamados' => $c] as $k => $v) {
                if ($v !== '' && !preg_match('/^[0-9]+(-[0-9]+)?@g\.us$/', $v)) {
                    echo json_encode(['ok' => false, 'erro' => "JID de $k inválido"]);
                    exit;
                }
            }
            wpp_cfg_set('grupo_alertas_jid', $a);
            wpp_cfg_set('grupo_chamados_jid', $c);
            echo json_encode(['ok' => true]);
            break;
        case 'contatos_listar':
            // Lista técnicos (perfil GLPI profiles_id=4) + o contato WhatsApp já salvo, se houver.
            try {
                $sql = "SELECT DISTINCT u.id, u.realname, u.firstname, u.name AS login, u.mobile, u.phone,
                               c.telefone, c.ativo
                        FROM glpi_users u
                        JOIN glpi_profiles_users pu ON pu.users_id = u.id AND pu.profiles_id = 4
                        LEFT JOIN portal_wpp_contatos c ON c.glpi_user_id = u.id
                        WHERE u.is_active = 1 AND u.is_deleted = 0
                        ORDER BY u.realname, u.firstname";
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                $tecnicos = [];
                foreach ($rows as $r) {
                    $nome = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
                    if ($nome === '') { $nome = (string)($r['login'] ?? ''); }
                    $tecnicos[] = [
                        'glpi_user_id' => (int)$r['id'],
                        'nome'         => $nome,
                        'telefone'     => $r['telefone'] ?? '',
                        // contato ainda não salvo (telefone NULL) entra como "ativo" por padrão
                        'ativo'        => $r['telefone'] === null ? 1 : (int)$r['ativo'],
                        // sugestão de preenchimento vinda do cadastro GLPI (celular ou telefone)
                        'mobile_glpi'  => wpp_norm_telefone((string)($r['mobile'] ?: ($r['phone'] ?: ''))),
                    ];
                }
                echo json_encode(['ok' => true, 'tecnicos' => $tecnicos]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao listar contatos']);
            }
            break;
        case 'contatos_salvar':
            // grava/atualiza o telefone de UM técnico. POST-only (igual save_groups):
            // um GET com telefone vazio zeraria o contato silenciosamente.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $uid = (int)($_POST['glpi_user_id'] ?? 0);
            if ($uid <= 0) { echo json_encode(['ok' => false, 'erro' => 'técnico inválido']); exit; }
            $tel = wpp_norm_telefone((string)($_POST['telefone'] ?? ''));
            // vazio é permitido ("sem telefone" — o DM ignora esse técnico); se preenchido, 10..13 dígitos
            if ($tel !== '' && (strlen($tel) < 10 || strlen($tel) > 13)) {
                echo json_encode(['ok' => false, 'erro' => 'telefone deve ter de 10 a 13 dígitos']);
                exit;
            }
            $ativo = (($_POST['ativo'] ?? '1') === '1') ? 1 : 0;
            try {
                $st = $pdo->prepare(
                    "INSERT INTO portal_wpp_contatos (glpi_user_id, telefone, ativo) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE telefone = VALUES(telefone), ativo = VALUES(ativo)"
                );
                $st->execute([$uid, $tel, $ativo]);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao salvar contato']);
            }
            break;
        case 'vinculos_buscar_usuario':
            $q = trim((string) ($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'usuarios' => []]); exit; }
            try {
                $st = $pdo->prepare(
                    "SELECT id, realname, firstname, name
                     FROM glpi_users
                     WHERE is_active = 1 AND is_deleted = 0
                       AND (realname LIKE ? OR firstname LIKE ? OR name LIKE ?)
                     ORDER BY realname, firstname LIMIT 20"
                );
                $like = '%' . $q . '%';
                $st->execute([$like, $like, $like]);
                $usuarios = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nome = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
                    if ($nome === '') { $nome = (string) ($r['name'] ?? ''); }
                    $usuarios[] = ['id' => (int) $r['id'], 'nome' => $nome];
                }
                echo json_encode(['ok' => true, 'usuarios' => $usuarios]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao buscar usuário']);
            }
            break;
        case 'vinculos_listar':
            try {
                $sql = "SELECT v.id, v.telefone, v.glpi_user_id, v.rotulo, v.ativo,
                               COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS nome
                        FROM portal_wpp_vinculos v
                        JOIN glpi_users u ON u.id = v.glpi_user_id
                        ORDER BY nome, v.telefone";
                $linhas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'linhas' => $linhas]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao listar vínculos']);
            }
            break;
        case 'vinculos_salvar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $telefone = wpp_norm_telefone((string) ($_POST['telefone'] ?? ''));
            $uid      = (int) ($_POST['glpi_user_id'] ?? 0);
            $rotulo   = trim((string) ($_POST['rotulo'] ?? ''));
            $ativo    = (($_POST['ativo'] ?? '1') === '1') ? 1 : 0;
            if ($telefone === '' || strlen($telefone) < 10 || strlen($telefone) > 13) {
                echo json_encode(['ok' => false, 'erro' => 'telefone deve ter de 10 a 13 dígitos']);
                exit;
            }
            if ($uid <= 0) { echo json_encode(['ok' => false, 'erro' => 'escolha um usuário']); exit; }
            try {
                $st = $pdo->prepare(
                    "INSERT INTO portal_wpp_vinculos (telefone, glpi_user_id, rotulo, ativo) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE glpi_user_id = VALUES(glpi_user_id), rotulo = VALUES(rotulo), ativo = VALUES(ativo)"
                );
                $st->execute([$telefone, $uid, $rotulo, $ativo]);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao salvar vínculo']);
            }
            break;
        case 'vinculos_remover':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id inválido']); exit; }
            try {
                $pdo->prepare("DELETE FROM portal_wpp_vinculos WHERE id = ?")->execute([$id]);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao remover']);
            }
            break;
        case 'gatilhos_ler':
            // Lê on/off dos 4 gatilhos + parâmetros de tempo, cada um com default.
            echo json_encode(['ok' => true, 'cfg' => [
                'on_novo'                => wpp_cfg_get('on_novo', '1'),
                'on_atribuido'           => wpp_cfg_get('on_atribuido', '1'),
                'on_alertas'             => wpp_cfg_get('on_alertas', '1'),
                'on_sla'                 => wpp_cfg_get('on_sla', '1'),
                'on_chatbot'             => wpp_cfg_get('on_chatbot', '0'),
                'cfg_delay_dm_min'       => (int) wpp_cfg_get('cfg_delay_dm_min', '5'),
                'cfg_digest_alertas_min' => (int) wpp_cfg_get('cfg_digest_alertas_min', '15'),
                'cfg_sla_horas'          => (int) wpp_cfg_get('cfg_sla_horas', '4'),
                'cfg_sla_prevenc_min'    => (int) wpp_cfg_get('cfg_sla_prevenc_min', '30'),
                'cfg_offline_reset_min'  => (int) wpp_cfg_get('cfg_offline_reset_min', '30'),
            ]]);
            break;
        case 'gatilhos_salvar':
            // regrava a config dos gatilhos: POST-only (um GET zeraria tudo silenciosamente)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            // toggles: qualquer valor diferente de '1' vira '0'
            $toggles = ['on_novo', 'on_atribuido', 'on_alertas', 'on_sla', 'on_chatbot'];
            // numéricos: inteiros de 1..1440 — cfg_delay_dm_min aceita 0 (DM imediata)
            $numeros = ['cfg_delay_dm_min', 'cfg_digest_alertas_min', 'cfg_sla_horas', 'cfg_sla_prevenc_min', 'cfg_offline_reset_min'];
            foreach ($numeros as $k) {
                $v   = (int) ($_POST[$k] ?? 0);
                $min = ($k === 'cfg_delay_dm_min') ? 0 : 1;
                if ($v < $min || $v > 1440) {
                    echo json_encode(['ok' => false, 'erro' => "$k fora do intervalo (1..1440)"]);
                    exit;
                }
            }
            // só grava depois de validar todos os campos
            foreach ($toggles as $k) {
                wpp_cfg_set($k, (($_POST[$k] ?? '') === '1') ? '1' : '0');
            }
            foreach ($numeros as $k) {
                wpp_cfg_set($k, (string) (int) ($_POST[$k] ?? 0));
            }
            echo json_encode(['ok' => true]);
            break;
        case 'log_listar':
            // tabela read-only do portal_wpp_log. LIMIT interpolado com (int) já
            // sanitizado por min/max (10..500) — não dá pra fazer bind de LIMIT.
            $limite = min(500, max(10, (int) ($_GET['limite'] ?? 100)));
            try {
                $sql = "SELECT criado_em, direcao, destino, resumo, status
                        FROM portal_wpp_log ORDER BY id DESC LIMIT $limite";
                echo json_encode(['ok' => true, 'linhas' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'erro' => 'falha ao listar log']);
            }
            break;
        default:
            echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    }
    exit;
}

$alertas_jid  = wpp_cfg_get('grupo_alertas_jid', '');
$chamados_jid = wpp_cfg_get('grupo_chamados_jid', '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Configuração do WhatsApp</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --primary:#1a237e; --wpp:#25d366; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15);
                border-radius:6px; padding:.3rem .75rem; margin-left:.4rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }
    .hero { background:linear-gradient(135deg,var(--primary),#1565c0); color:#fff; padding:2rem 1rem 4.5rem; text-align:center; }
    .hero h1 { font-size:1.5rem; font-weight:700; margin:0; }
    .hero p { opacity:.8; margin-top:.5rem; font-size:.95rem; }
    .wrap { max-width:760px; margin:-3rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06);
                overflow:hidden; }
    .nav-tabs { padding:0 1rem; border-bottom:1px solid #eef1f5; }
    .nav-tabs .nav-link { border:0; color:#6b7280; font-weight:600; font-size:.9rem; padding:.9rem 1rem; cursor:pointer; }
    .nav-tabs .nav-link.active { color:var(--primary); border-bottom:3px solid var(--primary); background:transparent; }
    .tab-body { padding:1.5rem; }
    .state-line { display:flex; align-items:center; gap:.5rem; font-weight:700; font-size:.95rem; margin-bottom:1rem; }
    .dot { width:12px; height:12px; border-radius:50%; display:inline-block; background:#9ca3af; }
    .dot.open { background:#16a34a; } .dot.connecting { background:#f59e0b; } .dot.close { background:#e53935; }
    #qr-wrap { text-align:center; margin-top:1rem; }
    #qr-wrap img { width:260px; height:260px; border:1px solid #e5e7eb; border-radius:8px; padding:.4rem; background:#fff; }
    .feedback { border-radius:8px; padding:.6rem .9rem; font-size:.85rem; margin-top:1rem; display:none; }
    .feedback.ok { background:#f0fdf4; border:1px solid #22c55e; color:#166534; display:block; }
    .feedback.err { background:#fef2f2; border:1px solid #ef4444; color:#991b1b; display:block; }
    .feedback.info { background:#eff6ff; border:1px solid #3b82f6; color:#1e40af; display:block; }
    .aviso { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:8px; padding:.6rem .9rem;
             font-size:.85rem; margin-bottom:1rem; }
    label.form-label { font-weight:600; font-size:.85rem; color:#374151; }
    .gat-bloco { border:1px solid #e5e7eb; border-radius:10px; padding:.7rem .9rem; margin-bottom:.6rem;
                 display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
    .gat-bloco .form-check { margin:0; }
    .gat-bloco .form-check-label { cursor:pointer; }
    .gat-sub { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; transition:opacity .15s; }
    .gat-sub.off { opacity:.35; }
    .gat-campo { display:flex; align-items:center; gap:.4rem; white-space:nowrap; }
    .gat-campo .form-label { margin:0; }
    .gat-campo input { width:80px; }
    .gat-campo .un { font-size:.8rem; color:#6b7280; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-whatsapp"></i> Configuração do WhatsApp</div>
  <div>
    <a href="config_notificacoes.php"><i class="bi bi-arrow-left me-1"></i>Configuração</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a>
  </div>
</div>
<div class="hero">
  <h1><i class="bi bi-whatsapp me-2"></i>Configuração do WhatsApp</h1>
  <p>Pareie a linha do TI e escolha os grupos de Alertas e Chamados</p>
</div>

<div class="wrap">
  <div class="card-box">
    <ul class="nav nav-tabs" id="wpp-tabs">
      <li class="nav-item"><span class="nav-link active" data-tab="conexao"><i class="bi bi-qr-code me-1"></i>Conexão</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="grupos"><i class="bi bi-people me-1"></i>Grupos</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="contatos"><i class="bi bi-person-vcard me-1"></i>Contatos</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="vinculos"><i class="bi bi-link-45deg me-1"></i>Vínculos</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="gatilhos"><i class="bi bi-toggles me-1"></i>Gatilhos</span></li>
      <li class="nav-item"><span class="nav-link" data-tab="log"><i class="bi bi-list-ul me-1"></i>Log</span></li>
    </ul>

    <!-- ─────────── Aba Conexão ─────────── -->
    <div class="tab-body" id="tab-conexao">
      <div class="state-line">
        <span class="dot" id="state-dot"></span>
        <span id="state-text">Verificando…</span>
      </div>

      <button class="btn btn-success btn-sm" id="btn-conectar" style="background:var(--wpp);border-color:var(--wpp)">
        <i class="bi bi-qr-code me-1"></i>Conectar / mostrar QR
      </button>
      <button class="btn btn-outline-danger btn-sm" id="btn-desconectar" style="display:none">
        <i class="bi bi-box-arrow-right me-1"></i>Desconectar
      </button>

      <div id="qr-wrap" style="display:none">
        <p class="small text-muted mb-2">Abra o WhatsApp no celular da linha do TI → Aparelhos conectados → Conectar um aparelho e aponte para o código.</p>
        <img id="qr-img" alt="QR code"/>
      </div>

      <hr class="my-3">
      <div class="d-flex align-items-center gap-2">
        <span class="small text-muted">Chatbot de entrada:</span>
        <span id="chatbot-webhook-estado" class="small fw-semibold text-muted">verificando…</span>
        <button class="btn btn-outline-primary btn-sm" id="btn-chatbot-webhook-toggle">Ativar/desativar</button>
      </div>
      <p class="small text-muted mt-1">Precisa do toggle "Chatbot de entrada" ligado na aba Gatilhos também.</p>

      <div class="feedback" id="fb-conexao"></div>
    </div>

    <!-- ─────────── Aba Grupos ─────────── -->
    <div class="tab-body" id="tab-grupos" style="display:none">
      <div class="aviso" id="aviso-conecte" style="display:none">
        <i class="bi bi-exclamation-triangle me-1"></i>Conecte primeiro na aba Conexão. Você pode tentar carregar a lista mesmo assim.
      </div>

      <button class="btn btn-primary btn-sm mb-3" id="btn-recarregar">
        <i class="bi bi-arrow-repeat me-1"></i>Recarregar lista de grupos
      </button>

      <div class="mb-3">
        <label class="form-label" for="sel-alertas">Grupo de Alertas</label>
        <select class="form-select form-select-sm" id="sel-alertas">
          <option value="">— nenhum —</option>
          <?php if ($alertas_jid !== ''): ?>
            <option value="<?= $H($alertas_jid) ?>" selected><?= $H($alertas_jid) ?> (salvo)</option>
          <?php endif; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label" for="sel-chamados">Grupo de Chamados</label>
        <select class="form-select form-select-sm" id="sel-chamados">
          <option value="">— nenhum —</option>
          <?php if ($chamados_jid !== ''): ?>
            <option value="<?= $H($chamados_jid) ?>" selected><?= $H($chamados_jid) ?> (salvo)</option>
          <?php endif; ?>
        </select>
      </div>

      <button class="btn btn-success btn-sm" id="btn-salvar" style="background:var(--wpp);border-color:var(--wpp)">
        <i class="bi bi-save me-1"></i>Salvar
      </button>

      <div class="feedback" id="fb-grupos"></div>
    </div>

    <!-- ─────────── Aba Contatos ─────────── -->
    <div class="tab-body" id="tab-contatos" style="display:none">
      <p class="small text-muted mb-2">
        Mapeie cada técnico (usuário GLPI) ao número de WhatsApp que recebe a DM de chamado atribuído.
        Deixe o telefone em branco para não notificar o técnico.
      </p>

      <button class="btn btn-outline-primary btn-sm mb-3" id="btn-puxar-glpi">
        <i class="bi bi-download me-1"></i>Puxar celulares do GLPI
      </button>

      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tbl-contatos">
          <thead>
            <tr>
              <th>Técnico</th>
              <th style="width:11rem">Telefone (só dígitos)</th>
              <th class="text-center" style="width:4rem">Ativo</th>
              <th style="width:6rem"></th>
            </tr>
          </thead>
          <tbody id="tbody-contatos">
            <tr><td colspan="4" class="text-muted">Carregando…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="feedback" id="fb-contatos"></div>
    </div>

    <!-- ─────────── Aba Vínculos ─────────── -->
    <div class="tab-body" id="tab-vinculos" style="display:none">
      <p class="small text-muted mb-2">
        Números que pulam direto pro título ao abrir chamado pelo WhatsApp (sem escolher loja/usuário).
        Um usuário pode ter vários números — ex.: "SAC Loja X" com 3 celulares diferentes.
      </p>

      <div class="row g-2 mb-3">
        <div class="col-4">
          <input type="text" class="form-control form-control-sm" id="vinc-telefone" placeholder="Telefone (só dígitos)">
        </div>
        <div class="col-4" style="position:relative">
          <input type="text" class="form-control form-control-sm" id="vinc-busca-usuario" placeholder="Buscar usuário GLPI…" autocomplete="off">
          <div id="vinc-resultados" class="list-group" style="position:absolute; z-index:10; width:100%; max-height:200px; overflow:auto;"></div>
          <input type="hidden" id="vinc-usuario-id">
        </div>
        <div class="col-3">
          <input type="text" class="form-control form-control-sm" id="vinc-rotulo" placeholder="Rótulo (opcional)">
        </div>
        <div class="col-1">
          <button class="btn btn-success btn-sm w-100" id="btn-vinc-adicionar" style="background:var(--wpp);border-color:var(--wpp)">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tbl-vinculos">
          <thead>
            <tr>
              <th>Usuário</th>
              <th>Telefone</th>
              <th>Rótulo</th>
              <th class="text-center" style="width:4rem">Ativo</th>
              <th style="width:3rem"></th>
            </tr>
          </thead>
          <tbody id="tbody-vinculos">
            <tr><td colspan="5" class="text-muted">Carregando…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="feedback" id="fb-vinculos"></div>
    </div>

    <!-- ─────────── Aba Gatilhos ─────────── -->
    <div class="tab-body" id="tab-gatilhos" style="display:none">
      <p class="small text-muted mb-3">
        Marque as notificações que você quer. O ajuste de tempo de cada uma
        só vale quando ela está marcada.
      </p>

      <!-- Chamado novo -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_novo" data-dep="novo">
          <label class="form-check-label fw-semibold" for="g-on_novo">Chamado novo → grupo Chamados</label>
        </div>
        <span class="small text-muted">na hora</span>
      </div>

      <!-- Chamado atribuído -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_atribuido" data-dep="atribuido">
          <label class="form-check-label fw-semibold" for="g-on_atribuido">Chamado atribuído → DM pro técnico</label>
        </div>
        <div class="gat-sub" data-dep="atribuido">
          <div class="gat-campo">
            <label class="form-label" for="g-cfg_delay_dm_min">Atraso da DM</label>
            <input type="number" min="0" max="1440" step="1" class="form-control form-control-sm" id="g-cfg_delay_dm_min">
            <span class="un">min (0 = na hora)</span>
          </div>
        </div>
      </div>

      <!-- Alertas -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_alertas" data-dep="alertas">
          <label class="form-check-label fw-semibold" for="g-on_alertas">Alertas do parque → grupo Alertas</label>
        </div>
        <div class="gat-sub" data-dep="alertas">
          <div class="gat-campo">
            <label class="form-label" for="g-cfg_digest_alertas_min">Intervalo do resumo</label>
            <input type="number" min="1" max="1440" step="1" class="form-control form-control-sm" id="g-cfg_digest_alertas_min">
            <span class="un">min</span>
          </div>
        </div>
      </div>

      <!-- Chatbot de entrada (Fase 3) -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_chatbot">
          <label class="form-check-label fw-semibold" for="g-on_chatbot">Chatbot de entrada (abrir/consultar chamado pelo WhatsApp)</label>
        </div>
        <span class="small text-muted">responde DM de quem escrever pro número do TI</span>
      </div>

      <!-- SLA / parado -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_sla" data-dep="sla">
          <label class="form-check-label fw-semibold" for="g-on_sla">SLA / chamado parado → grupo Chamados</label>
        </div>
        <div class="gat-sub" data-dep="sla">
          <div class="gat-campo">
            <label class="form-label" for="g-cfg_sla_horas">Parado após</label>
            <input type="number" min="1" max="1440" step="1" class="form-control form-control-sm" id="g-cfg_sla_horas">
            <span class="un">h</span>
          </div>
          <div class="gat-campo">
            <label class="form-label" for="g-cfg_sla_prevenc_min">Aviso antes de vencer</label>
            <input type="number" min="1" max="1440" step="1" class="form-control form-control-sm" id="g-cfg_sla_prevenc_min">
            <span class="un">min</span>
          </div>
        </div>
      </div>

      <details class="mt-2">
        <summary class="small text-muted" style="cursor:pointer">Avançado</summary>
        <div class="gat-campo mt-2">
          <label class="form-label" for="g-cfg_offline_reset_min">Re-semear baseline se o worker ficar offline por</label>
          <input type="number" min="1" max="1440" step="1" class="form-control form-control-sm" id="g-cfg_offline_reset_min">
          <span class="un">min</span>
        </div>
        <div class="text-muted small mt-1">Se o worker cair e voltar depois desse tempo, ele re-marca tudo como "já notificado" em vez de despejar o acúmulo.</div>
      </details>

      <button class="btn btn-success btn-sm mt-3" id="btn-gatilhos-salvar" style="background:var(--wpp);border-color:var(--wpp)">
        <i class="bi bi-save me-1"></i>Salvar
      </button>

      <div class="feedback" id="fb-gatilhos"></div>
    </div>

    <!-- ─────────── Aba Log ─────────── -->
    <div class="tab-body" id="tab-log" style="display:none">
      <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <button class="btn btn-primary btn-sm" id="btn-log-atualizar">
          <i class="bi bi-arrow-repeat me-1"></i>Atualizar
        </button>
        <select class="form-select form-select-sm" id="sel-log-limite" style="width:auto">
          <option value="50">50 linhas</option>
          <option value="100" selected>100 linhas</option>
          <option value="200">200 linhas</option>
          <option value="500">500 linhas</option>
        </select>
        <div class="form-check form-switch mb-0 ms-1">
          <input type="checkbox" class="form-check-input" id="chk-log-auto">
          <label class="form-check-label small" for="chk-log-auto">Auto (15s)</label>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tbl-log">
          <thead>
            <tr>
              <th style="width:11rem">Data</th>
              <th style="width:5rem">Direção</th>
              <th style="width:10rem">Destino</th>
              <th>Resumo</th>
              <th style="width:7rem">Status</th>
            </tr>
          </thead>
          <tbody id="tbody-log">
            <tr><td colspan="5" class="text-muted">Carregando…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="feedback" id="fb-log"></div>
    </div>
  </div>
</div>

<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Integração WhatsApp (Fase 1)</footer>

<script>
(function () {
  'use strict';

  var PAGE = 'config_whatsapp.php';
  // JIDs salvos no banco (pré-seleção dos selects)
  // Flags JSON_HEX_* escapam < / ' & — evita quebrar o <script> com valor persistido malicioso
  var SALVO = {
    alertas:  <?= json_encode($alertas_jid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    chamados: <?= json_encode($chamados_jid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
  };
  var estadoAtual = 'desconhecido';
  var pollTimer = null;
  var pollTentativas = 0;          // total de polls desta rodada
  var pollTerminais = 0;           // estados terminais (close/desconhecido) seguidos
  var POLL_MAX = 40;               // ~2 min a cada 3s
  var POLL_TERMINAIS_MAX = 5;      // desiste após 5 estados terminais seguidos

  function $(id) { return document.getElementById(id); }

  function feedback(el, tipo, msg) {
    el.className = 'feedback ' + tipo;
    el.textContent = msg;
  }

  /* ─────────── Abas ─────────── */
  document.querySelectorAll('#wpp-tabs .nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      document.querySelectorAll('#wpp-tabs .nav-link').forEach(function (l) { l.classList.remove('active'); });
      link.classList.add('active');
      var alvo = link.getAttribute('data-tab');
      pararLogAuto(); // ao sair da aba Log, encerra o auto-refresh de fundo
      $('tab-conexao').style.display  = (alvo === 'conexao')  ? '' : 'none';
      $('tab-grupos').style.display   = (alvo === 'grupos')   ? '' : 'none';
      $('tab-contatos').style.display = (alvo === 'contatos') ? '' : 'none';
      $('tab-vinculos').style.display  = (alvo === 'vinculos')  ? '' : 'none';
      $('tab-gatilhos').style.display = (alvo === 'gatilhos') ? '' : 'none';
      $('tab-log').style.display      = (alvo === 'log')      ? '' : 'none';
      if (alvo === 'grupos') atualizarAvisoGrupos();
      if (alvo === 'contatos' && !contatosCarregados) { carregarContatos(); }
      if (alvo === 'vinculos' && !vinculosCarregados) { carregarVinculos(); }
      if (alvo === 'gatilhos' && !gatilhosCarregados) { carregarGatilhos(); }
      if (alvo === 'log') { carregarLog(false); if ($('chk-log-auto').checked) { iniciarLogAuto(); } }
    });
  });

  /* ─────────── Conexão ─────────── */
  function pintarEstado(estado) {
    estadoAtual = estado || 'desconhecido';
    var dot = $('state-dot'), txt = $('state-text');
    dot.className = 'dot';
    if (estado === 'open') {
      dot.classList.add('open');
      txt.textContent = 'Conectado';
      $('btn-desconectar').style.display = '';
      $('btn-conectar').style.display = 'none';
      $('qr-wrap').style.display = 'none';
      pararPoll();
    } else if (estado === 'connecting') {
      dot.classList.add('connecting');
      txt.textContent = 'Conectando…';
      $('btn-desconectar').style.display = 'none';
      $('btn-conectar').style.display = '';
    } else {
      dot.classList.add('close');
      txt.textContent = 'Desconectado';
      $('btn-desconectar').style.display = 'none';
      $('btn-conectar').style.display = '';
    }
    atualizarAvisoGrupos();
  }

  function carregarStatus() {
    fetch(PAGE + '?action=status')
      .then(function (r) { return r.json(); })
      .then(function (d) { pintarEstado(d.estado); })
      .catch(function () { pintarEstado('desconhecido'); });
  }

  function conectar() {
    var btn = $('btn-conectar');
    btn.disabled = true;
    feedback($('fb-conexao'), 'info', 'Preparando instância…');
    fetch(PAGE + '?action=ensure')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao preparar instância'); }
        feedback($('fb-conexao'), 'info', 'Gerando QR code…');
        return fetch(PAGE + '?action=qr').then(function (r) { return r.json(); });
      })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok || !d.base64) { throw new Error(d.erro || 'QR indisponível — talvez já esteja conectado'); }
        var src = d.base64.indexOf('data:') === 0 ? d.base64 : 'data:image/png;base64,' + d.base64;
        $('qr-img').src = src;
        $('qr-wrap').style.display = '';
        feedback($('fb-conexao'), 'info', 'Escaneie o QR com a linha do TI. A tela atualiza sozinha ao conectar.');
        iniciarPoll();
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  function iniciarPoll() {
    pararPoll();
    pollTentativas = 0;
    pollTerminais = 0;
    pollTimer = setInterval(pollStatus, 3000);
  }
  function pararPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }
  function pollStatus() {
    pollTentativas++;
    if (pollTentativas > POLL_MAX) {
      pararPoll();
      feedback($('fb-conexao'), 'err', 'Pareamento expirado — tente de novo.');
      return;
    }
    // bg=1: polling de fundo NÃO renova o relógio de inatividade (auth_guard.php)
    fetch(PAGE + '?action=status&bg=1')
      .then(function (r) {
        // 440 (ou qualquer não-OK) = sessão do portal expirou
        if (r.status === 440 || !r.ok) { throw new Error('timeout'); }
        return r.json();
      })
      .then(function (d) {
        if (d && d.timeout) { throw new Error('timeout'); }
        if (d.estado === 'open') {
          pollTerminais = 0;
          feedback($('fb-conexao'), 'ok', 'Conectado com sucesso.');
          pintarEstado('open'); // pintarEstado já chama pararPoll()
          return;
        }
        if (d.estado === 'close' || d.estado === 'desconhecido') {
          pollTerminais++;
          if (pollTerminais >= POLL_TERMINAIS_MAX) {
            pararPoll();
            feedback($('fb-conexao'), 'err', 'Pareamento expirado — tente de novo.');
          }
        } else {
          pollTerminais = 0;
        }
        pintarEstado(d.estado);
      })
      .catch(function (err) {
        if (err && err.message === 'timeout') {
          pararPoll();
          feedback($('fb-conexao'), 'err', 'Sessão expirada — recarregue a página.');
        }
      });
  }

  function desconectar() {
    if (!confirm('Desconectar a linha do WhatsApp? Será preciso escanear o QR de novo para reconectar.')) { return; }
    var btn = $('btn-desconectar');
    btn.disabled = true;
    fetch(PAGE + '?action=logout', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.ok) {
          feedback($('fb-conexao'), 'ok', 'Desconectado.');
          pintarEstado('close');
        } else {
          feedback($('fb-conexao'), 'err', 'Erro: ' + (d.erro || 'falha ao desconectar'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  function carregarChatbotWebhook() {
    fetch(PAGE + '?action=chatbot_webhook_status')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        $('chatbot-webhook-estado').textContent = d.ok ? (d.ativo ? 'ativo' : 'desativado') : 'erro ao verificar';
      })
      .catch(function () { $('chatbot-webhook-estado').textContent = 'erro ao verificar'; });
  }

  function alternarChatbotWebhook() {
    var btn = $('btn-chatbot-webhook-toggle');
    var ligarAgora = $('chatbot-webhook-estado').textContent.trim() !== 'ativo';
    btn.disabled = true;
    fetch(PAGE + '?action=chatbot_webhook_toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ligar=' + (ligarAgora ? '1' : '0')
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { feedback($('fb-conexao'), 'err', 'Erro: ' + (d.erro || 'falha ao alternar')); return; }
        carregarChatbotWebhook();
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  /* ─────────── Grupos ─────────── */
  function atualizarAvisoGrupos() {
    var aviso = $('aviso-conecte');
    if (aviso) { aviso.style.display = (estadoAtual === 'open') ? 'none' : ''; }
  }

  function preencherSelect(sel, grupos, salvo) {
    var atual = sel.value || salvo || '';
    sel.innerHTML = '<option value="">— nenhum —</option>';
    var achouSalvo = false;
    grupos.forEach(function (g) {
      var opt = document.createElement('option');
      opt.value = g.jid;
      opt.textContent = g.nome + (g.tamanho ? ' (' + g.tamanho + ')' : '');
      if (g.jid === atual) { opt.selected = true; achouSalvo = true; }
      sel.appendChild(opt);
    });
    // Mantém o JID salvo como opção mesmo se não veio na lista (linha desconectada, etc.)
    if (atual && !achouSalvo) {
      var opt2 = document.createElement('option');
      opt2.value = atual;
      opt2.textContent = atual + ' (salvo)';
      opt2.selected = true;
      sel.appendChild(opt2);
    }
  }

  function carregarGrupos() {
    var btn = $('btn-recarregar');
    btn.disabled = true;
    feedback($('fb-grupos'), 'info', 'Carregando grupos…');
    fetch(PAGE + '?action=groups')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { throw new Error(d.erro || 'falha ao listar grupos'); }
        var grupos = d.grupos || [];
        preencherSelect($('sel-alertas'), grupos, SALVO.alertas);
        preencherSelect($('sel-chamados'), grupos, SALVO.chamados);
        feedback($('fb-grupos'), 'ok', grupos.length + ' grupo(s) carregado(s).');
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-grupos'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  function salvarGrupos() {
    var btn = $('btn-salvar');
    btn.disabled = true;
    feedback($('fb-grupos'), 'info', 'Salvando…');
    var body = new URLSearchParams({
      alertas_jid:  $('sel-alertas').value,
      chamados_jid: $('sel-chamados').value
    });
    fetch(PAGE + '?action=save_groups', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.ok) {
          SALVO.alertas  = $('sel-alertas').value;
          SALVO.chamados = $('sel-chamados').value;
          feedback($('fb-grupos'), 'ok', 'Grupos salvos.');
        } else {
          feedback($('fb-grupos'), 'err', 'Erro: ' + (d.erro || 'falha ao salvar'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-grupos'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  /* ─────────── Contatos ─────────── */
  var contatosCarregados = false;

  function carregarContatos() {
    feedback($('fb-contatos'), 'info', 'Carregando técnicos…');
    fetch(PAGE + '?action=contatos_listar')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao carregar contatos'); }
        renderContatos(d.tecnicos || []);
        contatosCarregados = true;
        feedback($('fb-contatos'), 'ok', (d.tecnicos || []).length + ' técnico(s) carregado(s).');
      })
      .catch(function (err) {
        feedback($('fb-contatos'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  function renderContatos(tecnicos) {
    var tb = $('tbody-contatos');
    tb.innerHTML = '';
    if (!tecnicos.length) {
      var tr0 = document.createElement('tr');
      var td0 = document.createElement('td');
      td0.colSpan = 4;
      td0.className = 'text-muted';
      td0.textContent = 'Nenhum técnico encontrado.';
      tr0.appendChild(td0); tb.appendChild(tr0);
      return;
    }
    tecnicos.forEach(function (t) {
      var tr = document.createElement('tr');
      tr.setAttribute('data-uid', t.glpi_user_id);
      tr.setAttribute('data-mobile', t.mobile_glpi || '');

      var tdNome = document.createElement('td');
      tdNome.textContent = t.nome;            // textContent -> sem XSS no nome do técnico
      tr.appendChild(tdNome);

      var tdTel = document.createElement('td');
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'form-control form-control-sm tel-input';
      inp.value = t.telefone || '';
      inp.placeholder = t.mobile_glpi ? ('GLPI: ' + t.mobile_glpi) : 'sem telefone';
      inp.addEventListener('input', function () { inp.value = inp.value.replace(/\D+/g, ''); });
      tdTel.appendChild(inp);
      tr.appendChild(tdTel);

      var tdAtivo = document.createElement('td');
      tdAtivo.className = 'text-center';
      var chk = document.createElement('input');
      chk.type = 'checkbox';
      chk.className = 'form-check-input ativo-input';
      chk.checked = (t.ativo === 1 || t.ativo === true);
      tdAtivo.appendChild(chk);
      tr.appendChild(tdAtivo);

      var tdBtn = document.createElement('td');
      var btn = document.createElement('button');
      btn.className = 'btn btn-success btn-sm';
      btn.style.background = 'var(--wpp)';
      btn.style.borderColor = 'var(--wpp)';
      btn.textContent = 'Salvar';
      btn.addEventListener('click', function () { salvarContato(t.glpi_user_id, btn); });
      tdBtn.appendChild(btn);
      tr.appendChild(tdBtn);

      tb.appendChild(tr);
    });
  }

  function salvarContato(uid, btn) {
    var tr = document.querySelector('#tbody-contatos tr[data-uid="' + uid + '"]');
    if (!tr) { return; }
    var tel = tr.querySelector('.tel-input').value.replace(/\D+/g, '');
    var ativo = tr.querySelector('.ativo-input').checked ? '1' : '0';
    if (tel !== '' && (tel.length < 10 || tel.length > 13)) {
      feedback($('fb-contatos'), 'err', 'Telefone deve ter de 10 a 13 dígitos (ou ficar vazio).');
      return;
    }
    if (btn) { btn.disabled = true; }
    feedback($('fb-contatos'), 'info', 'Salvando…');
    var body = new URLSearchParams({ glpi_user_id: uid, telefone: tel, ativo: ativo });
    fetch(PAGE + '?action=contatos_salvar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (btn) { btn.disabled = false; }
        if (d.ok) {
          feedback($('fb-contatos'), 'ok', 'Contato salvo.');
        } else {
          feedback($('fb-contatos'), 'err', 'Erro: ' + (d.erro || 'falha ao salvar'));
        }
      })
      .catch(function (err) {
        if (btn) { btn.disabled = false; }
        feedback($('fb-contatos'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  function puxarDoGlpi() {
    var preenchidos = 0;
    document.querySelectorAll('#tbody-contatos tr[data-uid]').forEach(function (tr) {
      var inp = tr.querySelector('.tel-input');
      var mobile = tr.getAttribute('data-mobile') || '';
      // só preenche o que está VAZIO — não sobrescreve telefone já digitado/salvo
      if (mobile && inp && inp.value.trim() === '') {
        inp.value = mobile;
        preenchidos++;
      }
    });
    feedback($('fb-contatos'), preenchidos ? 'ok' : 'info',
      preenchidos
        ? (preenchidos + ' campo(s) preenchido(s) com o celular do GLPI. Revise e clique em Salvar.')
        : 'Nenhum campo vazio com celular disponível no GLPI.');
  }

  /* ─────────── Vínculos ─────────── */
  var vinculosCarregados = false;
  var vincUsuarioSelecionado = null;

  function carregarVinculos() {
    feedback($('fb-vinculos'), 'info', 'Carregando…');
    fetch(PAGE + '?action=vinculos_listar')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao carregar'); }
        renderVinculos(d.linhas);
        vinculosCarregados = true;
        feedback($('fb-vinculos'), 'ok', 'Vínculos carregados.');
      })
      .catch(function (err) { feedback($('fb-vinculos'), 'err', 'Erro: ' + (err.message || err)); });
  }

  function renderVinculos(linhas) {
    var tbody = $('tbody-vinculos');
    tbody.innerHTML = '';
    if (!linhas.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-muted">Nenhum vínculo cadastrado.</td></tr>';
      return;
    }
    linhas.forEach(function (v) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + v.nome.replace(/</g, '&lt;') + '</td>' +
        '<td>' + v.telefone + '</td>' +
        '<td>' + (v.rotulo || '').replace(/</g, '&lt;') + '</td>' +
        '<td class="text-center"><input type="checkbox" class="form-check-input vinc-ativo" ' + (v.ativo == 1 ? 'checked' : '') + '></td>' +
        '<td><button class="btn btn-outline-danger btn-sm btn-vinc-remover"><i class="bi bi-trash"></i></button></td>';
      tr.querySelector('.vinc-ativo').addEventListener('change', function () {
        var params = new URLSearchParams();
        params.set('telefone', v.telefone);
        params.set('glpi_user_id', v.glpi_user_id);
        params.set('rotulo', v.rotulo || '');
        params.set('ativo', this.checked ? '1' : '0');
        fetch(PAGE + '?action=vinculos_salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) { feedback($('fb-vinculos'), d.ok ? 'ok' : 'err', d.ok ? 'Atualizado.' : (d.erro || 'erro')); });
      });
      tr.querySelector('.btn-vinc-remover').addEventListener('click', function () {
        if (!confirm('Remover o vínculo de ' + v.telefone + '?')) { return; }
        var params = new URLSearchParams();
        params.set('id', v.id);
        fetch(PAGE + '?action=vinculos_remover', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.ok) { carregarVinculos(); } else { feedback($('fb-vinculos'), 'err', d.erro || 'erro ao remover'); }
          });
      });
      tbody.appendChild(tr);
    });
  }

  var vincBuscaTimer = null;
  $('vinc-busca-usuario').addEventListener('input', function () {
    var termo = this.value;
    vincUsuarioSelecionado = null;
    $('vinc-usuario-id').value = '';
    clearTimeout(vincBuscaTimer);
    if (termo.trim().length < 2) { $('vinc-resultados').innerHTML = ''; return; }
    vincBuscaTimer = setTimeout(function () {
      fetch(PAGE + '?action=vinculos_buscar_usuario&q=' + encodeURIComponent(termo))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var box = $('vinc-resultados');
          box.innerHTML = '';
          (d.usuarios || []).forEach(function (u) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            item.textContent = u.nome;
            item.addEventListener('click', function () {
              vincUsuarioSelecionado = u;
              $('vinc-busca-usuario').value = u.nome;
              $('vinc-usuario-id').value = u.id;
              box.innerHTML = '';
            });
            box.appendChild(item);
          });
        });
    }, 300);
  });

  $('btn-vinc-adicionar').addEventListener('click', function () {
    var telefone = $('vinc-telefone').value.replace(/\D+/g, '');
    var uid = $('vinc-usuario-id').value;
    var rotulo = $('vinc-rotulo').value;
    if (!telefone || telefone.length < 10) { feedback($('fb-vinculos'), 'err', 'Telefone inválido.'); return; }
    if (!uid) { feedback($('fb-vinculos'), 'err', 'Escolha um usuário na busca.'); return; }
    var params = new URLSearchParams();
    params.set('telefone', telefone);
    params.set('glpi_user_id', uid);
    params.set('rotulo', rotulo);
    params.set('ativo', '1');
    fetch(PAGE + '?action=vinculos_salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { feedback($('fb-vinculos'), 'err', d.erro || 'erro ao salvar'); return; }
        $('vinc-telefone').value = ''; $('vinc-busca-usuario').value = ''; $('vinc-usuario-id').value = ''; $('vinc-rotulo').value = '';
        carregarVinculos();
      });
  });

  /* ─────────── Gatilhos ─────────── */
  var gatilhosCarregados = false;
  var GAT_TOGGLES = ['on_novo', 'on_atribuido', 'on_alertas', 'on_sla', 'on_chatbot'];
  var GAT_NUMS = ['cfg_delay_dm_min', 'cfg_digest_alertas_min', 'cfg_sla_horas', 'cfg_sla_prevenc_min', 'cfg_offline_reset_min'];

  function carregarGatilhos() {
    feedback($('fb-gatilhos'), 'info', 'Carregando…');
    fetch(PAGE + '?action=gatilhos_ler')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao carregar'); }
        GAT_TOGGLES.forEach(function (k) { $('g-' + k).checked = (String(d.cfg[k]) === '1'); });
        GAT_NUMS.forEach(function (k) { $('g-' + k).value = parseInt(d.cfg[k], 10) || 0; });
        gatilhosCarregados = true;
        sincronizarGatilhos();
        feedback($('fb-gatilhos'), 'ok', 'Configuração carregada.');
      })
      .catch(function (err) {
        feedback($('fb-gatilhos'), 'err', 'Erro: ' + (err.message || err));
      });
  }

  // apaga/desabilita os campos de tempo de um gatilho quando ele está desmarcado
  function sincronizarGatilhos() {
    ['atribuido', 'alertas', 'sla'].forEach(function (dep) {
      var on = $('g-on_' + dep).checked;
      document.querySelectorAll('.gat-sub[data-dep="' + dep + '"]').forEach(function (el) {
        el.classList.toggle('off', !on);
        el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
      });
    });
  }

  function salvarGatilhos() {
    var btn = $('btn-gatilhos-salvar');
    var params = new URLSearchParams();
    GAT_TOGGLES.forEach(function (k) { params.set(k, $('g-' + k).checked ? '1' : '0'); });
    // qual gatilho "dono" de cada campo — se ele está desmarcado, não valida
    var DONO = {
      cfg_delay_dm_min: 'on_atribuido', cfg_digest_alertas_min: 'on_alertas',
      cfg_sla_horas: 'on_sla', cfg_sla_prevenc_min: 'on_sla'
    };
    var LABEL = {
      cfg_delay_dm_min: 'Atraso da DM', cfg_digest_alertas_min: 'Intervalo do resumo de alertas',
      cfg_sla_horas: 'Parado após', cfg_sla_prevenc_min: 'Aviso antes de vencer',
      cfg_offline_reset_min: 'Re-semear baseline'
    };
    var erroLocal = null;
    GAT_NUMS.forEach(function (k) {
      var v = parseInt($('g-' + k).value, 10);
      var min = (k === 'cfg_delay_dm_min') ? 0 : 1; // só o atraso da DM aceita 0
      var ativo = !DONO[k] || $('g-' + DONO[k]).checked;
      if (isNaN(v)) { v = min; }
      if (ativo && (v < min || v > 1440)) {
        erroLocal = erroLocal || (LABEL[k] + ': informe um valor de ' + min + ' a 1440');
      }
      if (!ativo && (v < min || v > 1440)) { v = min; } // gatilho off: só normaliza pra não travar o save
      params.set(k, v);
    });
    if (erroLocal) { feedback($('fb-gatilhos'), 'err', erroLocal); return; }
    btn.disabled = true;
    feedback($('fb-gatilhos'), 'info', 'Salvando…');
    fetch(PAGE + '?action=gatilhos_salvar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        feedback($('fb-gatilhos'), d.ok ? 'ok' : 'err',
          d.ok ? 'Gatilhos salvos.' : ('Erro: ' + (d.erro || 'falha ao salvar')));
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-gatilhos'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  /* ─────────── Log ─────────── */
  var logAutoTimer = null;

  function corStatus(s) {
    var v = String(s || '').toLowerCase();
    if (v === 'ok') { return '#16a34a'; }        // verde
    if (v === 'bloqueado') { return '#e53935'; } // vermelho
    if (v === 'erro') { return '#ea580c'; }      // laranja
    return '#6b7280';                            // cinza (demais status)
  }

  function carregarLog(bg) {
    var lim = $('sel-log-limite').value || '100';
    // bg=1: auto-refresh de fundo NÃO renova o relógio de inatividade (auth_guard.php)
    var url = PAGE + '?action=log_listar&limite=' + encodeURIComponent(lim) + (bg ? '&bg=1' : '');
    fetch(url)
      .then(function (r) {
        if (r.status === 440 || !r.ok) { throw new Error('timeout'); }
        return r.json();
      })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.erro || 'falha ao carregar log'); }
        renderLog(d.linhas || []);
        if (!bg) { feedback($('fb-log'), 'ok', (d.linhas || []).length + ' linha(s).'); }
      })
      .catch(function (err) {
        if (err && err.message === 'timeout') {
          pararLogAuto();
          $('chk-log-auto').checked = false;
          feedback($('fb-log'), 'err', 'Sessão expirada — recarregue a página.');
          return;
        }
        if (!bg) { feedback($('fb-log'), 'err', 'Erro: ' + (err.message || err)); }
      });
  }

  function renderLog(linhas) {
    var tb = $('tbody-log');
    tb.innerHTML = '';
    if (!linhas.length) {
      var tr0 = document.createElement('tr');
      var td0 = document.createElement('td');
      td0.colSpan = 5; td0.className = 'text-muted';
      td0.textContent = 'Nenhum registro.';
      tr0.appendChild(td0); tb.appendChild(tr0);
      return;
    }
    linhas.forEach(function (l) {
      var tr = document.createElement('tr');
      // textContent em tudo — destino/resumo/status podem conter o que um viewer gravou
      [l.criado_em, l.direcao, l.destino, l.resumo].forEach(function (val) {
        var td = document.createElement('td');
        td.textContent = (val == null) ? '' : String(val);
        tr.appendChild(td);
      });
      var tdSt = document.createElement('td');
      tdSt.textContent = (l.status == null) ? '' : String(l.status);
      tdSt.style.fontWeight = '700';
      tdSt.style.color = corStatus(l.status);
      tr.appendChild(tdSt);
      tb.appendChild(tr);
    });
  }

  function iniciarLogAuto() {
    pararLogAuto();
    logAutoTimer = setInterval(function () { carregarLog(true); }, 15000);
  }
  function pararLogAuto() {
    if (logAutoTimer) { clearInterval(logAutoTimer); logAutoTimer = null; }
  }

  /* ─────────── Ligações ─────────── */
  $('btn-conectar').addEventListener('click', conectar);
  $('btn-desconectar').addEventListener('click', desconectar);
  $('btn-chatbot-webhook-toggle').addEventListener('click', alternarChatbotWebhook);
  $('btn-recarregar').addEventListener('click', carregarGrupos);
  $('btn-salvar').addEventListener('click', salvarGrupos);
  $('btn-puxar-glpi').addEventListener('click', puxarDoGlpi);
  $('btn-gatilhos-salvar').addEventListener('click', salvarGatilhos);
  document.querySelectorAll('.gat-toggle').forEach(function (t) {
    t.addEventListener('change', sincronizarGatilhos);
  });
  $('btn-log-atualizar').addEventListener('click', function () { carregarLog(false); });
  $('sel-log-limite').addEventListener('change', function () { carregarLog(false); });
  $('chk-log-auto').addEventListener('change', function () {
    if ($('chk-log-auto').checked) { iniciarLogAuto(); } else { pararLogAuto(); }
  });

  carregarStatus();
  carregarChatbotWebhook();
})();
</script>
</body>
</html>
