<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) { header('Location: historico.php'); exit; }

require_once __DIR__ . '/agenda/config.php';
require_once __DIR__ . '/entidade_alias.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

$_cards_cham  = $_SESSION['portal_perfil_cards'] ?? null;
$cham_ouvinte = ($_cards_cham !== null) && (($_cards_cham['historico'] ?? 'ouvinte') === 'ouvinte');

// ── Handler para editar entidade/categoria/atendente via AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar_campos') {
    header('Content-Type: application/json');
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $entidade_id  = isset($_POST['entidade_id']) && $_POST['entidade_id'] !== '' ? (int)$_POST['entidade_id'] : null;
    $categoria_id = isset($_POST['categoria_id']) && $_POST['categoria_id'] !== '' ? (int)$_POST['categoria_id'] : null;
    $atendente_id = isset($_POST['atendente_id']) && $_POST['atendente_id'] !== '' ? (int)$_POST['atendente_id'] : null;

    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'ticket_id inválido']); exit; }

    $auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
    $ch = curl_init(GLPI_URL . '/apirest.php/initSession');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
    $r = json_decode(curl_exec($ch), true); curl_close($ch);
    $token_s = $r['session_token'] ?? '';
    if (!$token_s) { echo json_encode(['ok'=>false,'msg'=>'Falha na autenticação GLPI']); exit; }

    $h = ['Content-Type: application/json','Session-Token: '.$token_s,'App-Token: '.GLPI_APP_TOKEN];
    $erros = [];

    // 1️⃣ Atualiza entidade / categoria no ticket
    $input = [];
    if ($entidade_id)  $input['entities_id']       = $entidade_id;
    if ($categoria_id) $input['itilcategories_id'] = $categoria_id;
    if (!empty($input)) {
        $ch2 = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PUT',
            CURLOPT_POSTFIELDS=>json_encode(['input'=>$input]), CURLOPT_HTTPHEADER=>$h
        ]);
        $resTicket = json_decode(curl_exec($ch2), true);
        $errTicket = curl_error($ch2);
        curl_close($ch2);
        $okTicket = !empty($resTicket['id']) || (!empty($resTicket[0]) && !empty($resTicket[0]['id']));
        if ($errTicket || !$okTicket) {
            $erros[] = 'Falha ao atualizar ticket: ' . ($errTicket ?: json_encode($resTicket));
        }
    }

    // 2️⃣ Reatribui técnico se informado
    if ($atendente_id) {
        // Remove técnicos existentes (type=2)
        $ch_tu = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid . '/Ticket_User?searchText[type]=2');
        curl_setopt_array($ch_tu, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
        $atuais = json_decode(curl_exec($ch_tu), true) ?? [];
        curl_close($ch_tu);
        foreach ($atuais as $tu) {
            if (!isset($tu['id'])) continue;
            $ch_d = curl_init(GLPI_URL . '/apirest.php/Ticket_User/' . $tu['id']);
            curl_setopt_array($ch_d, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'DELETE', CURLOPT_HTTPHEADER=>$h]);
            curl_exec($ch_d); curl_close($ch_d);
        }
        // Atribui novo técnico
        $ch_a = curl_init(GLPI_URL . '/apirest.php/Ticket_User');
        curl_setopt_array($ch_a, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['input'=>['tickets_id'=>$tid,'users_id'=>$atendente_id,'type'=>2]]),
            CURLOPT_HTTPHEADER=>$h]);
        $resTec = json_decode(curl_exec($ch_a), true);
        $errTec = curl_error($ch_a);
        curl_close($ch_a);
        $okTec = !empty($resTec['id']) || (!empty($resTec[0]) && !empty($resTec[0]['id']));
        if ($errTec || !$okTec) {
            $erros[] = 'Falha ao atribuir técnico: ' . ($errTec ?: json_encode($resTec));
        }

        // Seta status para Atribuído (2) e sincroniza agenda se conseguiu atribuir
        if (empty($erros)) {
            $ch_st = curl_init(GLPI_URL . '/apirest.php/Ticket/' . $tid);
            curl_setopt_array($ch_st, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PUT',
                CURLOPT_POSTFIELDS=>json_encode(['input'=>['status'=>2]]), CURLOPT_HTTPHEADER=>$h]);
            curl_exec($ch_st); curl_close($ch_st);

            // Atualiza atendente na tabela da agenda para que o filtro funcione corretamente
            try {
                require_once __DIR__ . '/agenda/db.php';
                $stU = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(realname,''), ' ', COALESCE(firstname,''))) FROM glpi_users WHERE id = ?");
                $stU->execute([$atendente_id]);
                $nome_full = trim($stU->fetchColumn() ?: '');
                $nome_tec  = $nome_full ?: ('ID ' . $atendente_id);
                $stAg = $pdo->prepare("UPDATE glpi_plugin_agenda_events SET atendente_id = ?, atendente = ? WHERE ticket_id = ?");
                $stAg->execute([$atendente_id, $nome_tec, $tid]);
            } catch (\Throwable $e) { /* falha silenciosa — GLPI já foi atualizado */ }
        }
    }

    $ch3 = curl_init(GLPI_URL . '/apirest.php/killSession');
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h]);
    curl_exec($ch3); curl_close($ch3);

    if (empty($erros)) {
        echo json_encode(['ok'=>true,'msg'=>'Campos atualizados com sucesso!']);
    } else {
        echo json_encode(['ok'=>false,'msg'=>implode(' | ', $erros)]);
    }
    exit;
}

// ── Handler: editar quantidades de impressão (funciona mesmo com chamado fechado) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_impressao') {
    header('Content-Type: application/json');
    if ($cham_ouvinte) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
    $tid = (int)($_POST['ticket_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'ticket_id inválido']); exit; }

    require_once __DIR__ . '/agenda/db.php';
    $imp_fields = [
        'qtdimpressesafourfvfield','qtdimpressesafourffield',
        'qtdimpressesathreefvfield','qtdimpressesathreeffield',
        'qtdimpafouradesivofield','qtdimpafourplacasfield',
        'qtdetiquetafivefield','qtdimpathreeplacafield','qtdimpathreeadesivofield',
    ];
    $vals = [];
    foreach ($imp_fields as $f) $vals[$f] = max(0, (int)($_POST[$f] ?? 0));

    try {
        $chk = $pdo->prepare("SELECT id FROM glpi_plugin_fields_ticketqtdimpressesafourfrenteversos WHERE items_id = ?");
        $chk->execute([$tid]);
        if ($chk->fetchColumn()) {
            $sets = implode(',', array_map(fn($f) => "`$f`=:$f", $imp_fields));
            $pdo->prepare("UPDATE glpi_plugin_fields_ticketqtdimpressesafourfrenteversos SET $sets WHERE items_id=:items_id")
                ->execute(array_merge($vals, ['items_id' => $tid]));
        } else {
            $entRow = $pdo->prepare("SELECT entities_id FROM glpi_tickets WHERE id = ? LIMIT 1");
            $entRow->execute([$tid]);
            $entities_id = (int)($entRow->fetchColumn() ?: 0);
            $cols = implode(',', array_map(fn($f) => "`$f`", $imp_fields));
            $phs  = implode(',', array_map(fn($f) => ":$f", $imp_fields));
            $pdo->prepare("INSERT INTO glpi_plugin_fields_ticketqtdimpressesafourfrenteversos
                           (items_id,itemtype,plugin_fields_containers_id,entities_id,$cols)
                           VALUES (:items_id,'Ticket',1,:entities_id,$phs)")
                ->execute(array_merge($vals, ['items_id' => $tid, 'entities_id' => $entities_id]));
        }
        echo json_encode(['ok'=>true, 'msg'=>'Impressões atualizadas!', 'total'=>array_sum($vals)]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false, 'msg'=>'Erro ao gravar: ' . $e->getMessage()]);
    }
    exit;
}

function glpi_req(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Session-Token: '.$token, 'App-Token: '.GLPI_APP_TOKEN],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) ? $r : [];
}

// Abre sessão
$auth = base64_encode(GLPI_USER . ':' . GLPI_PASS);
$ch = curl_init(GLPI_URL . '/apirest.php/initSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Basic '.$auth,'App-Token: '.GLPI_APP_TOKEN]]);
$r = json_decode(curl_exec($ch), true); curl_close($ch);
$token = $r['session_token'] ?? '';

if (!$token) { echo 'Erro de autenticação GLPI'; exit; }

// Dados do ticket
$ticket    = glpi_req(GLPI_URL.'/apirest.php/Ticket/'.$ticket_id.'?expand_dropdowns=true', $token);
$followups = glpi_req(GLPI_URL.'/apirest.php/Ticket/'.$ticket_id.'/ITILFollowup?expand_dropdowns=true', $token);
$users_req = glpi_req(GLPI_URL.'/apirest.php/Ticket/'.$ticket_id.'/Ticket_User', $token);
// NOTA: Ticket_User SEM expand_dropdowns para que users_id seja NUMÉRICO.
// Os nomes são resolvidos via $user_nomes abaixo.

// Mapa de IDs → nomes dos usuários (para exibir nomes nos atores)
$user_nomes = [];
if ($token) {
    $raw_users = glpi_req(GLPI_URL.'/apirest.php/User?range=0-500&expand_dropdowns=true', $token);
    foreach ($raw_users as $u) {
        if (!isset($u['id'])) continue;
        $nome = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
        if (!$nome) $nome = $u['name'] ?? 'ID ' . $u['id'];
        $user_nomes[(int)$u['id']] = $nome;
    }
}
function nome_user(int $id, array $map): string {
    return primeiro_nome($map[$id] ?? 'ID ' . $id);
}

// Campos adicionais de impressão (plugin fields)
$campos_impressao = null;
try {
    require_once __DIR__ . '/agenda/db.php';
    $stImp = $pdo->prepare(
        "SELECT * FROM glpi_plugin_fields_ticketqtdimpressesafourfrenteversos WHERE items_id = ? LIMIT 1"
    );
    $stImp->execute([$ticket_id]);
    $campos_impressao = $stImp->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { /* silencioso se tabela não existir */ }

// Documentos ligados diretamente ao Ticket (raramente usados)
$doc_items_ticket = glpi_req(GLPI_URL.'/apirest.php/Ticket/'.$ticket_id.'/Document_Item', $token);

// Documentos por followup: os anexos enviados via "Responder Chamado" ficam em
// itemtype=ITILFollowup — por isso Ticket/Document_Item retorna [].
// Busca os docs de cada followup e injeta em $followup['_docs'].
$img_ext_map = ['jpg'=>1,'jpeg'=>1,'png'=>1,'gif'=>1,'webp'=>1,'bmp'=>1,'svg'=>1];
function glpi_docs_of(int $fu_id, string $token, array $img_ext_map): array {
    $di_list = glpi_req(GLPI_URL.'/apirest.php/ITILFollowup/'.$fu_id.'/Document_Item', $token);
    $result  = [];
    foreach ((array)$di_list as $di) {
        $docid = (int)($di['documents_id'] ?? 0);
        if (!$docid) continue;
        $doc = glpi_req(GLPI_URL.'/apirest.php/Document/'.$docid, $token);
        if (empty($doc['id'])) continue;
        $fname = $doc['filename'] ?? $doc['name'] ?? 'arquivo';
        $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $result[] = [
            'documents_id' => $docid,
            'filename'     => $fname,
            'isImg'        => isset($img_ext_map[$ext]),
            'filesize'     => (int)($doc['filesize'] ?? 0),
        ];
    }
    return $result;
}

foreach ($followups as &$fu) {
    $fu_id = (int)($fu['id'] ?? 0);
    $fu['_docs'] = $fu_id ? glpi_docs_of($fu_id, $token, $img_ext_map) : [];
}
unset($fu);

// Docs diretamente no Ticket (mantém compatibilidade)
$docs = [];
foreach ((array)$doc_items_ticket as $di) {
    $docid = (int)($di['documents_id'] ?? 0);
    if (!$docid) continue;
    $doc = glpi_req(GLPI_URL.'/apirest.php/Document/'.$docid, $token);
    if (empty($doc['id'])) continue;
    $fname = $doc['filename'] ?? $doc['name'] ?? 'arquivo';
    $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $docs[] = [
        'documents_id' => $docid,
        'filename'     => $fname,
        'isImg'        => isset($img_ext_map[$ext]),
        'filesize'     => (int)($doc['filesize'] ?? 0),
    ];
}

// Encerra sessão
$ch = curl_init(GLPI_URL . '/apirest.php/killSession');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Session-Token: '.$token,'App-Token: '.GLPI_APP_TOKEN]]);
curl_exec($ch); curl_close($ch);

if (empty($ticket['id'])) { echo 'Chamado não encontrado.'; exit; }

// Mapas
$status_map = [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
$status_cor = [1=>'primary',2=>'info',3=>'warning',4=>'secondary',5=>'success',6=>'dark'];
$urg_map    = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta'];
$tipo_map   = [1=>'Incidente',2=>'Requisição'];

$status  = $status_map[$ticket['status'] ?? 1] ?? '?';
$st_cor  = $status_cor[$ticket['status'] ?? 1] ?? 'secondary';
$urgencia= $urg_map[$ticket['urgency'] ?? 3] ?? 'Média';
$tipo    = $tipo_map[$ticket['type'] ?? 1] ?? 'Incidente';

// Separa atores por tipo
$requerentes = array_filter($users_req, fn($u) => ($u['type'] ?? 0) == 1);
$atribuidos  = array_filter($users_req, fn($u) => ($u['type'] ?? 0) == 2);

// Pega o ID do primeiro técnico atribuído (para o edit mode)
$primeiro_atendente_id = 0;
foreach ($atribuidos as $a) {
    $uid = (int)($a['users_id'] ?? 0);
    if ($uid) { $primeiro_atendente_id = $uid; break; }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Chamado #<?= $ticket_id ?> — <?= htmlspecialchars($ticket['name'] ?? '') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
  <style>
    :root { --primary:#1a237e; --accent:#1a73e8; }
    body  { background:#f0f4f9; font-family:'Segoe UI',sans-serif; }

    .topbar {
      background:linear-gradient(135deg,var(--primary),#1565c0);
      color:white; padding:.75rem 1.5rem;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 8px rgba(0,0,0,.25); position:sticky; top:0; z-index:100;
    }
    .topbar .brand { font-weight:700; font-size:1rem; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:white; text-decoration:none; font-size:.82rem;
                background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .topbar a:hover { background:rgba(255,255,255,.25); }

    .wrap { max-width:900px; margin:1.5rem auto 4rem; padding:0 1rem; }

    .card-glpi {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:1.25rem; overflow:hidden;
    }
    .card-glpi .card-header-glpi {
      background:#f9fafb; border-bottom:2px solid #e5e7eb;
      padding:.65rem 1.25rem; font-weight:700; font-size:.85rem;
      color:#374151; display:flex; align-items:center; gap:.4rem;
    }
    .card-glpi .card-body-glpi { padding:1.25rem; }

    /* Cabeçalho do chamado */
    .ticket-header {
      background:white; border-radius:12px; border:1px solid #e5e7eb;
      box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.5rem; margin-bottom:1.25rem;
    }
    .ticket-num  { font-size:.8rem; color:#9ca3af; font-weight:600; }
    .ticket-title{ font-size:1.35rem; font-weight:700; color:#111; margin:.3rem 0 .75rem; }

    .meta-grid {
      display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
      gap:.5rem; margin-top:.75rem;
    }
    .meta-item { background:#f9fafb; border-radius:8px; padding:.5rem .75rem; }
    .meta-item .label { font-size:.7rem; font-weight:700; color:#9ca3af; text-transform:uppercase; }
    .meta-item .val   { font-size:.85rem; font-weight:600; color:#374151; margin-top:.1rem; }

    /* Descrição */
    .descricao { font-size:.9rem; line-height:1.7; color:#374151; }
    .descricao img { max-width:100%; border-radius:8px; margin:.5rem 0; }

    /* Followups */
    .followup {
      border-left:3px solid var(--accent);
      padding:.75rem 1rem; margin-bottom:.75rem;
      background:#f8faff; border-radius:0 8px 8px 0;
    }
    .followup .fu-author { font-weight:700; font-size:.82rem; color:var(--primary); }
    .followup .fu-date   { font-size:.75rem; color:#9ca3af; margin-left:.5rem; }
    .followup .fu-body   { font-size:.88rem; color:#374151; margin-top:.4rem; line-height:1.6; }
    .followup .fu-body img { max-width:100%; border-radius:6px; margin:.4rem 0; }
    .followup .fu-docs  { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.6rem; padding-top:.5rem; border-top:1px solid #e9ecef; }

    /* Documentos */
    .doc-item {
      display:flex; align-items:center; gap:.75rem;
      padding:.6rem .75rem; border-radius:8px; border:1px solid #e5e7eb;
      background:#fafafa; margin-bottom:.5rem; text-decoration:none; color:#374151;
      transition:background .15s;
    }
    .doc-item:hover { background:#f0f4ff; color:#1a73e8; }
    .doc-icon { font-size:1.4rem; flex-shrink:0; }
    .doc-nome { font-size:.83rem; font-weight:600; }
    .doc-size { font-size:.73rem; color:#9ca3af; }

    .doc-img-thumb {
      width:80px; height:60px; object-fit:cover;
      border-radius:6px; border:1px solid #e5e7eb;
      cursor:zoom-in; flex-shrink:0;
    }

    /* Lightbox */
    #lb { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85);
          z-index:9999; align-items:center; justify-content:center; cursor:zoom-out; }
    #lb.open { display:flex; }
    #lb img  { max-width:90vw; max-height:88vh; border-radius:8px;
               box-shadow:0 8px 40px rgba(0,0,0,.6); cursor:default; }
    #lb .lb-x { position:absolute; top:16px; right:20px; color:#fff;
                font-size:2rem; cursor:pointer; line-height:1; }

    /* Atores */
    .ator-chip {
      display:inline-flex; align-items:center; gap:.35rem;
      background:#eff6ff; border:1px solid #bfdbfe; border-radius:20px;
      padding:.25rem .75rem; font-size:.8rem; color:#1d4ed8; margin:.2rem;
    }

    .badge { font-size:.73rem; }

    /* Sem conteúdo */
    .empty-msg { color:#9ca3af; font-size:.85rem; font-style:italic; }

    /* ── Responder ── */
    .drop-zone-responder {
      border:2px dashed #d1d5db; border-radius:10px;
      padding:1rem 1.5rem; text-align:center; cursor:pointer;
      transition:all .2s; background:#fafbfc;
    }
    .drop-zone-responder:hover,
    .drop-zone-responder.dragover {
      border-color:var(--accent); background:#e8f0fe;
    }
    .arquivo-chip {
      display:inline-flex; align-items:center; gap:.4rem;
      background:#f3f4f6; border:1px solid #e5e7eb;
      border-radius:8px; padding:.3rem .6rem;
      font-size:.78rem;
    }
    .arquivo-chip .rm {
      cursor:pointer; color:#9ca3af; margin-left:.2rem;
    }
    .arquivo-chip .rm:hover { color:#dc2626; }
    .arquivo-chip.chip-img { flex-direction:column; padding:.4rem; }
    .arquivo-chip .chip-thumb {
      width:60px; height:45px; object-fit:cover;
      border-radius:4px; cursor:zoom-in;
    }
    #lista-arquivos-responder { display:flex; flex-wrap:wrap; gap:.5rem; }
    .btn-enviar-resposta { background:#d93025; border:none; }
    .btn-enviar-resposta:hover { background:#b71c1c; }
    .resp-label { font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.2rem; }
    .edit-field { margin-top:.15rem; }
    .edit-field select { max-width:100%; }
    .edit-mode .meta-item .val { display:none; }
    .edit-mode .edit-field { display:block !important; }
    .btn-excluir-chamado { border-color:#dc3545; color:#dc3545; }
    .btn-excluir-chamado:hover { background:#dc3545; color:#fff; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-ticket-perforated"></i> Chamado #<?= $ticket_id ?></div>
  <div class="d-flex gap-2">
    <a href="historico.php"><i class="bi bi-arrow-left me-1"></i>Histórico</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">

  <!-- ── Cabeçalho ── -->
  <div class="ticket-header">
    <div class="ticket-num">Chamado #<?= $ticket_id ?></div>
    <div class="ticket-title"><?= htmlspecialchars($ticket['name'] ?? 'Sem título') ?></div>

    <div class="d-flex flex-wrap gap-2 mb-2">
      <span class="badge bg-<?= $st_cor ?>"><?= $status ?></span>
      <span class="badge <?= ($ticket['type']??1)==1 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $tipo ?></span>
      <span class="badge bg-secondary"><?= $urgencia ?></span>
      <?php if (!$cham_ouvinte): ?>
      <button class="btn btn-sm btn-outline-primary ms-auto" id="btnEditarCampos" onclick="ativarEdicao()">
        <i class="bi bi-pencil-fill me-1"></i>Editar
      </button>
      <button class="btn btn-sm btn-outline-danger btn-excluir-chamado" onclick="abrirModalExcluir()">
        <i class="bi bi-trash3 me-1"></i>Excluir
      </button>
      <?php endif; ?>
    </div>

    <div class="meta-grid" id="metaGrid">
      <div class="meta-item">
        <div class="label">Entidade</div>
        <div class="val" id="view-entidade"><?= htmlspecialchars(apelido_entidade($ticket['entities_id'] ?? '')) ?></div>
        <div class="edit-field" id="edit-entidade" style="display:none">
          <select class="form-select form-select-sm" id="edit-entidade-select">
            <option value="">Carregando...</option>
          </select>
        </div>
      </div>
      <div class="meta-item">
        <div class="label">Categoria</div>
        <div class="val" id="view-categoria"><?php $cat = $ticket['itilcategories_id'] ?? '—'; if (is_array($cat)) $cat = $cat['name'] ?? $cat['completename'] ?? '—'; echo htmlspecialchars($cat); ?></div>
        <div class="edit-field" id="edit-categoria" style="display:none">
          <select class="form-select form-select-sm" id="edit-categoria-select">
            <option value="">Carregando...</option>
          </select>
        </div>
      </div>
      <div class="meta-item">
        <div class="label">Atribuído</div>
        <div class="val" id="view-atribuido"><?php
          $nomes_tecnicos = array_map(function($u) use ($user_nomes) {
            return htmlspecialchars(nome_user((int)($u['users_id'] ?? 0), $user_nomes));
          }, $atribuidos);
          echo $nomes_tecnicos ? implode(', ', $nomes_tecnicos) : '—';
        ?></div>
        <div class="edit-field" id="edit-atribuido" style="display:none">
          <select class="form-select form-select-sm" id="edit-atendente-select">
            <option value="">Carregando...</option>
          </select>
        </div>
      </div>
      <div class="meta-item">
        <div class="label">Abertura</div>
        <div class="val"><?= substr($ticket['date'] ?? '', 0, 16) ?></div>
      </div>
      <div class="meta-item">
        <div class="label">Última atualização</div>
        <div class="val"><?= substr($ticket['date_mod'] ?? '', 0, 16) ?></div>
      </div>
      <?php if (!empty($ticket['solvedate'])): ?>
      <div class="meta-item">
        <div class="label">Resolvido em</div>
        <div class="val"><?= substr($ticket['solvedate'], 0, 16) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Botões Salvar/Cancelar edição -->
    <div id="edit-acoes" class="mt-2 mb-0" style="display:none">
      <button class="btn btn-sm btn-primary" onclick="salvarEdicao()"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      <button class="btn btn-sm btn-outline-secondary ms-1" onclick="cancelarEdicao()"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
      <span id="edit-status" class="small ms-2"></span>
    </div>

    <!-- Atores -->
    <div class="mt-3 d-flex flex-wrap gap-3">
      <?php if (!empty($requerentes)): ?>
      <div>
        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:.2rem">Requerente</div>
        <?php foreach ($requerentes as $u): ?>
          <span class="ator-chip"><i class="bi bi-person-fill"></i><?= htmlspecialchars(nome_user((int)($u['users_id'] ?? 0), $user_nomes)) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($atribuidos)): ?>
      <div>
        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:.2rem">Atribuído</div>
        <?php foreach ($atribuidos as $u): ?>
          <span class="ator-chip"><i class="bi bi-headset"></i><?= htmlspecialchars(nome_user((int)($u['users_id'] ?? 0), $user_nomes)) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Descrição ── -->
  <div class="card-glpi">
    <div class="card-header-glpi"><i class="bi bi-file-text"></i> Descrição</div>
    <div class="card-body-glpi">
      <div class="descricao">
        <?php
        $content = $ticket['content'] ?? '';
        // GLPI retorna conteúdo com entidades HTML (&#60; = <). Decodifica antes de checar.
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/<[a-z][\s\S]*>/i', $content)) {
            $content = nl2br(htmlspecialchars($content));
        }
        echo $content;
        ?>
      </div>
    </div>
  </div>

  <!-- ── Documentos diretos do Ticket (raro) ── -->
  <?php if (!empty($docs)): ?>
  <div class="card-glpi">
    <div class="card-header-glpi"><i class="bi bi-paperclip"></i> Anexos (<?= count($docs) ?>)</div>
    <div class="card-body-glpi">
      <?php foreach ($docs as $doc): ?>
        <?php if ($doc['isImg']): ?>
          <div class="doc-item" style="cursor:pointer" onclick="abrirLb('glpi_doc_proxy.php?docid=<?= $doc['documents_id'] ?>')">
            <img src="glpi_doc_proxy.php?docid=<?= $doc['documents_id'] ?>" alt="<?= htmlspecialchars($doc['filename']) ?>" class="doc-img-thumb" onerror="this.style.display='none'"/>
            <div><div class="doc-nome"><?= htmlspecialchars($doc['filename']) ?></div><?php if($doc['filesize']>0):?><div class="doc-size"><?= round($doc['filesize']/1024,1) ?> KB</div><?php endif;?></div>
            <i class="bi bi-zoom-in ms-auto text-muted"></i>
          </div>
        <?php else: ?>
          <a href="glpi_doc_proxy.php?docid=<?= $doc['documents_id'] ?>" target="_blank" class="doc-item">
            <i class="bi bi-file-earmark doc-icon text-secondary"></i>
            <div><div class="doc-nome"><?= htmlspecialchars($doc['filename']) ?></div><?php if($doc['filesize']>0):?><div class="doc-size"><?= round($doc['filesize']/1024,1) ?> KB</div><?php endif;?></div>
            <i class="bi bi-download ms-auto text-muted"></i>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Campos Adicionais: Impressões ── -->
  <?php if ($campos_impressao): ?>
  <?php
    $imp_labels = [
      'qtdimpressesafourfvfield'  => 'A4 Frente/Verso',
      'qtdimpressesafourffield'   => 'A4 Simples',
      'qtdimpressesathreefvfield' => 'A3 Frente/Verso',
      'qtdimpressesathreeffield'  => 'A3 Simples',
      'qtdimpafouradesivofield'   => 'A4 Adesivo',
      'qtdimpafourplacasfield'    => 'A4 Placas',
      'qtdetiquetafivefield'      => 'Etiqueta 5S',
      'qtdimpathreeplacafield'    => 'A3 Placas',
      'qtdimpathreeadesivofield'  => 'A3 Adesivos',
    ];
    $total_imp = array_sum(array_map(fn($k) => (int)($campos_impressao[$k] ?? 0), array_keys($imp_labels)));
  ?>
  <div class="card-glpi">
    <div class="card-header-glpi"><i class="bi bi-printer"></i> Impressões / Placas
      <span class="badge bg-primary ms-2" id="imp-total"><?= number_format($total_imp, 0, ',', '.') ?> un.</span>
      <?php if (!$cham_ouvinte): ?>
      <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="btn-editar-imp" onclick="toggleEditImp()" style="float:right;font-size:.75rem;padding:.15rem .6rem"><i class="bi bi-pencil-fill me-1"></i>Editar</button>
      <?php endif; ?>
    </div>
    <div class="card-body-glpi">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.6rem" id="imp-grid">
        <?php foreach ($imp_labels as $campo => $label):
          $val = (int)($campos_impressao[$campo] ?? 0);
        ?>
        <div style="background:<?= $val > 0 ? '#eff6ff' : '#f9fafb' ?>;border:1px solid <?= $val > 0 ? '#bfdbfe' : '#e5e7eb' ?>;border-radius:8px;padding:.5rem .8rem">
          <div style="font-size:.72rem;font-weight:600;color:#6b7280;text-transform:uppercase;margin-bottom:.1rem"><?= $label ?></div>
          <div class="imp-view" style="font-size:1.2rem;font-weight:700;color:<?= $val > 0 ? '#1d4ed8' : '#9ca3af' ?>"><?= number_format($val, 0, ',', '.') ?></div>
          <input type="number" min="0" class="imp-edit form-control form-control-sm" data-campo="<?= $campo ?>" value="<?= $val ?>" style="display:none;font-size:1.05rem;font-weight:700"/>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$cham_ouvinte): ?>
      <div id="imp-acoes" style="display:none;margin-top:.8rem;text-align:right">
        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleEditImp()">Cancelar</button>
        <button type="button" class="btn btn-sm btn-primary" onclick="salvarImpressoes()"><i class="bi bi-check-lg me-1"></i>Salvar impressões</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  function toggleEditImp() {
    const editando = document.querySelector('.imp-edit').style.display !== 'none';
    document.querySelectorAll('.imp-view').forEach(e => e.style.display = editando ? '' : 'none');
    document.querySelectorAll('.imp-edit').forEach(e => e.style.display = editando ? 'none' : '');
    document.getElementById('imp-acoes').style.display = editando ? 'none' : '';
    document.getElementById('btn-editar-imp').style.display = editando ? '' : 'none';
  }
  function salvarImpressoes() {
    const fd = new FormData();
    fd.set('action', 'save_impressao');
    fd.set('ticket_id', '<?= $ticket_id ?>');
    document.querySelectorAll('.imp-edit').forEach(i => fd.set(i.dataset.campo, i.value || 0));
    fetch('chamado.php?id=<?= $ticket_id ?>', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) { location.reload(); }
        else alert('Erro: ' + (d.msg || 'falha'));
      })
      .catch(e => alert('Erro de rede: ' + e.message));
  }
  </script>
  <?php endif; ?>

  <!-- ── Acompanhamentos (followups) ── -->
  <div class="card-glpi">
    <div class="card-header-glpi">
      <i class="bi bi-chat-left-text"></i> Acompanhamentos
      <span class="badge bg-secondary ms-1"><?= count($followups) ?></span>
    </div>
    <div class="card-body-glpi">
      <?php if (empty($followups)): ?>
        <p class="empty-msg">Nenhum acompanhamento registrado.</p>
      <?php else: ?>
        <?php foreach ($followups as $fu):
          $fu_content = $fu['content'] ?? '';
          $fu_content = html_entity_decode($fu_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          if (!preg_match('/<[a-z][\s\S]*>/i', $fu_content)) {
              $fu_content = nl2br(htmlspecialchars($fu_content));
          }
          $fu_docs = $fu['_docs'] ?? [];
        ?>
        <div class="followup">
          <div>
            <span class="fu-author"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars(primeiro_nome($fu['users_id'] ?? 'Sistema')) ?></span>
            <span class="fu-date"><?= substr($fu['date'] ?? '', 0, 16) ?></span>
          </div>
          <div class="fu-body"><?= $fu_content ?></div>

          <?php if (!empty($fu_docs)): ?>
          <div class="fu-docs">
            <?php foreach ($fu_docs as $fdoc): ?>
              <?php if ($fdoc['isImg']): ?>
                <img src="glpi_doc_proxy.php?docid=<?= $fdoc['documents_id'] ?>"
                     alt="<?= htmlspecialchars($fdoc['filename']) ?>"
                     class="doc-img-thumb"
                     title="<?= htmlspecialchars($fdoc['filename']) ?>"
                     style="cursor:zoom-in"
                     onclick="abrirLb('glpi_doc_proxy.php?docid=<?= $fdoc['documents_id'] ?>')"
                     onerror="this.style.display='none'"/>
              <?php else: ?>
                <a href="glpi_doc_proxy.php?docid=<?= $fdoc['documents_id'] ?>" target="_blank" class="doc-item" style="margin-top:.4rem">
                  <i class="bi bi-file-earmark doc-icon text-secondary"></i>
                  <div><div class="doc-nome"><?= htmlspecialchars($fdoc['filename']) ?></div></div>
                  <i class="bi bi-download ms-auto text-muted"></i>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Responder Chamado ── -->
  <?php if (!$cham_ouvinte): ?>
  <div class="card-glpi">
    <div class="card-header-glpi" style="border-bottom:3px solid #d93025;">
      <i class="bi bi-reply-fill text-danger"></i> Responder Chamado
    </div>
    <div class="card-body-glpi">
      <form id="formResponder" enctype="multipart/form-data">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>"/>

        <div class="row g-2 mb-3">
          <!-- Data início -->
          <div class="col-md-6">
            <div class="resp-label">Data / Início <span class="text-danger">*</span></div>
            <input type="datetime-local" class="form-control form-control-sm" id="resp-start"
                   value="<?= date('Y-m-d') . 'T08:00' ?>"/>
          </div>

          <!-- Data fim -->
          <div class="col-md-6">
            <div class="resp-label">Fim <span class="text-danger">*</span></div>
            <input type="datetime-local" class="form-control form-control-sm" id="resp-end"
                   value="<?= date('Y-m-d') . 'T09:00' ?>"/>
          </div>
        </div>

        <!-- Atendente -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Atendente</label>
          <select class="form-select form-select-sm" id="resp-atendente-select">
            <option value="">Carregando...</option>
          </select>
        </div>

        <!-- Mensagem -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Mensagem <span class="text-danger">*</span></label>
          <textarea name="resposta" id="resp-texto" class="form-control" rows="4"
            placeholder="Descreva o que foi feito, orientações ao usuário, próximos passos..."></textarea>
        </div>

        <!-- Anexos -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Anexar arquivos (imagens, docs, prints)</label>
          <div class="drop-zone-responder" id="dropZone" onclick="document.getElementById('resp-arquivos').click()">
            <i class="bi bi-cloud-upload fs-4 text-muted"></i>
            <p class="mb-0 text-muted small">Clique ou arraste arquivos aqui</p>
            <p class="mb-0 text-muted" style="font-size:.75rem"><kbd>Ctrl+V</kbd> para colar imagem da área de transferência</p>
            <input type="file" id="resp-arquivos" name="arquivos[]" multiple
                   accept="image/*,.pdf,.doc,.docx,.txt,.zip,.xls,.xlsx" class="d-none"
                   onchange="listarArquivos()"/>
          </div>
          <div id="lista-arquivos-responder" class="mt-2"></div>
        </div>

        <!-- Opções finais -->
        <div class="d-flex flex-wrap align-items-center gap-3">
          <label class="d-flex align-items-center gap-2 text-danger fw-semibold" style="cursor:pointer">
            <input type="checkbox" id="resp-fechar"/>
            🔒 Fechar chamado no GLPI
          </label>

          <div class="ms-auto d-flex align-items-center gap-2">
            <button type="submit" class="btn btn-danger btn-enviar-resposta" id="btnEnviarResposta">
              <i class="bi bi-send-fill me-2"></i>Enviar Resposta
            </button>
            <div id="resp-status" class="small text-muted"></div>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Modal Excluir Chamado -->
<div class="modal fade" id="modalExcluir" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Excluir chamado?</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1 small">Tem certeza que deseja excluir o chamado <strong>#<?= $ticket_id ?></strong>?</p>
        <p class="mb-0 text-muted small">Ele será movido para a lixeira do GLPI.</p>
        <div id="excluir-status" class="small mt-2"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-sm btn-danger" id="btnConfirmarExcluir" onclick="confirmarExcluir()">
          <i class="bi bi-trash3 me-1"></i>Excluir
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div id="lb" onclick="fecharLb()">
  <span class="lb-x" onclick="fecharLb()">&times;</span>
  <img id="lb-img" src="" alt="" onclick="event.stopPropagation()"/>
</div>

<script>
function abrirLb(src) {
  document.getElementById('lb-img').src = src;
  document.getElementById('lb').classList.add('open');
}
function fecharLb() {
  document.getElementById('lb').classList.remove('open');
  document.getElementById('lb-img').src = '';
}
// Abre lightbox ao clicar na miniatura do anexo
document.querySelectorAll('.doc-img-thumb').forEach(img => {
  img.addEventListener('click', e => { e.preventDefault(); abrirLb(img.src); });
});
// Fecha com Esc
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharLb(); });

// ── Responder Chamado ──
let _arquivosAnexos = [];
const TICKET_ID = <?= $ticket_id ?>;

// ── Constantes para edição ──
const CURRENT_ENTIDADE = <?php $eid = $ticket['entities_id'] ?? 0; if (is_array($eid)) $eid = $eid['id'] ?? 0; echo (int)$eid; ?>;
const CURRENT_CATEGORIA = <?php $cid = $ticket['itilcategories_id'] ?? 0; if (is_array($cid)) $cid = $cid['id'] ?? 0; echo (int)$cid; ?>;
const CURRENT_ATENDENTE_ID = <?= $primeiro_atendente_id ?>;
const CURRENT_ATENDENTE_NOME = <?php
  $primeiro_nome_tecnico = '';
  foreach ($atribuidos as $a) {
    $uid = (int)($a['users_id'] ?? 0);
    if ($uid) { $primeiro_nome_tecnico = $user_nomes[$uid] ?? ''; break; }
  }
  echo json_encode($primeiro_nome_tecnico);
?>;

function listarArquivos() {
  adicionarArquivos(document.getElementById('resp-arquivos').files);
}

function adicionarArquivos(files) {
  for (const f of files) {
    if (_arquivosAnexos.find(a => a.name === f.name && a.size === f.size)) continue;
    _arquivosAnexos.push(f);
  }
  renderizarArquivos();
}

function renderizarArquivos() {
  const lista = document.getElementById('lista-arquivos-responder');
  lista.innerHTML = '';
  _arquivosAnexos.forEach((f, i) => {
    const isImg = f.type.startsWith('image/');
    const chip = document.createElement('div');
    const nome = f.name.length > 20 ? f.name.slice(0, 18) + '…' : f.name;

    if (isImg) {
      chip.className = 'arquivo-chip chip-img';
      const thumb = document.createElement('img');
      thumb.className = 'chip-thumb';
      thumb.title = f.name;
      const reader = new FileReader();
      reader.onload = ev => { thumb.src = ev.target.result; };
      reader.readAsDataURL(f);

      const footer = document.createElement('div');
      footer.className = 'chip-footer d-flex align-items-center gap-1 w-100';
      const span = document.createElement('span');
      span.title = f.name;
      span.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem';
      span.textContent = nome;
      const rm = document.createElement('i');
      rm.className = 'bi bi-x rm';
      rm.onclick = () => removerArquivo(i);
      footer.appendChild(span);
      footer.appendChild(rm);
      chip.appendChild(thumb);
      chip.appendChild(footer);
    } else {
      chip.className = 'arquivo-chip';
      chip.innerHTML = `
        <i class="bi bi-file-earmark text-secondary"></i>
        <span title="${escHtml(f.name)}">${escHtml(nome)}</span>
        <i class="bi bi-x rm" onclick="removerArquivo(${i})"></i>`;
    }
    lista.appendChild(chip);
  });
}

function removerArquivo(i) {
  _arquivosAnexos.splice(i, 1);
  renderizarArquivos();
}

function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

// Ctrl+V colar imagem
document.addEventListener('paste', function(e) {
  const ativo = document.activeElement;
  if (ativo && ativo.closest && !ativo.closest('#formResponder')) return;

  const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
  if (!items) return;
  const imagens = [];
  for (const item of items) {
    if (!item.type.startsWith('image/')) continue;
    const blob = item.getAsFile();
    if (!blob) continue;
    const ext = item.type.split('/')[1] || 'png';
    imagens.push(new File([blob], `print_${Date.now()}.${ext}`, { type: item.type }));
  }
  if (!imagens.length) return;
  e.preventDefault();
  adicionarArquivos(imagens);
  const dz = document.getElementById('dropZone');
  dz.classList.add('dragover');
  setTimeout(() => dz.classList.remove('dragover'), 600);
});

// Drag & drop na drop zone
const dropZone = document.getElementById('dropZone');
['dragenter','dragover'].forEach(ev => {
  dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('dragover'); });
});
['dragleave','drop'].forEach(ev => {
  dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('dragover'); });
});
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  if (e.dataTransfer.files.length) adicionarArquivos(e.dataTransfer.files);
});

// Auto-preenche Fim quando Início muda (+15 min)
document.getElementById('resp-start').addEventListener('change', function() {
  const val = this.value;
  if (!val) return;
  const d = new Date(val);
  d.setMinutes(d.getMinutes() + 15);
  const pad = n => String(n).padStart(2, '0');
  document.getElementById('resp-end').value =
    d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' +
    pad(d.getHours()) + ':' + pad(d.getMinutes());
});

// Carrega atendentes no select do formulário de resposta
fetch('agenda/users.php')
  .then(r => r.json())
  .then(lista => {
    const sel = document.getElementById('resp-atendente-select');
    sel.innerHTML = '<option value="">— Sem técnico —</option>';
    lista.forEach(u => {
      const opt = document.createElement('option');
      opt.value = u.id;
      opt.textContent = u.nome;
      if (parseInt(u.id) === CURRENT_ATENDENTE_ID) opt.selected = true;
      sel.appendChild(opt);
    });
  })
  .catch(() => {
    document.getElementById('resp-atendente-select').innerHTML = '<option value="">Erro ao carregar</option>';
  });

// Submit do formulário
document.getElementById('formResponder').addEventListener('submit', async function(e) {
  e.preventDefault();

  // ── Valida campos ──
  const startRaw      = document.getElementById('resp-start').value;
  const endRaw        = document.getElementById('resp-end').value;
  const resposta      = document.getElementById('resp-texto').value.trim();
  const fechar        = document.getElementById('resp-fechar').checked;

  // Atendente selecionado no formulário
  const respAtSel   = document.getElementById('resp-atendente-select');
  const respAtId    = parseInt(respAtSel.value) || 0;
  const respAtFull  = respAtId ? (respAtSel.options[respAtSel.selectedIndex]?.textContent?.trim() || '') : '';
  const respAtNome  = respAtFull.trim() || ''; // nome completo para bater com filtro da agenda

  if (!startRaw || !endRaw) {
    setStatus('⚠️ Preencha data/hora de início e fim.', 'warning');
    return;
  }
  if (!resposta) {
    document.getElementById('resp-texto').focus();
    setStatus('⚠️ Digite uma mensagem.', 'warning');
    return;
  }

  const start = startRaw.replace('T', ' ') + ':00';
  const end   = endRaw.replace('T', ' ') + ':00';

  const btn = document.getElementById('btnEnviarResposta');
  const status = document.getElementById('resp-status');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';
  setStatus('', '');

  try {
    // 0️⃣ Atribuir atendente no GLPI se for diferente do atual
    if (respAtId && respAtId !== CURRENT_ATENDENTE_ID) {
      setStatus('⏳ Atribuindo atendente...', '');
      const bodyAtrib = new URLSearchParams();
      bodyAtrib.append('action', 'editar_campos');
      bodyAtrib.append('ticket_id', TICKET_ID);
      bodyAtrib.append('atendente_id', respAtId);
      await fetch('chamado.php?id=' + TICKET_ID, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyAtrib.toString(),
      });
    }

    // 1️⃣ Enviar resposta
    setStatus('⏳ Enviando resposta...', '');
    const fd = new FormData();
    fd.append('ticket_id', TICKET_ID);
    fd.append('resposta', resposta);
    _arquivosAnexos.forEach(f => fd.append('arquivos[]', f));

    const resResp = await fetch('agenda/responder_ticket.php', { method: 'POST', body: fd });
    const dataResp = await resResp.json();
    if (!dataResp.ok) {
      setStatus('❌ ' + (dataResp.msg || 'Falha ao enviar resposta'), 'danger');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Enviar Resposta';
      return;
    }

    // 2️⃣ Criar evento na agenda com o atendente selecionado
    setStatus('⏳ Inserindo na agenda...', '');
    const resAgenda = await fetch('agenda/eventos.php?action=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        titulo: '#' + TICKET_ID + ' — ' + (document.querySelector('.ticket-title')?.textContent?.trim() || 'Chamado'),
        descricao: resposta,
        start: start,
        end: end,
        atendente: respAtNome || CURRENT_ATENDENTE_NOME,
        atendente_id: respAtId || CURRENT_ATENDENTE_ID,
        atendente_cor: '#1a73e8',
        tipo: 'chamado',
        ticket_id: TICKET_ID,
        concluido: fechar ? 1 : 0,
      }),
    });
    const dataAgenda = await resAgenda.json();
    if (!dataAgenda.ok) {
      console.warn('Agenda não atualizada:', dataAgenda.msg);
    }

    // 3️⃣ Se marcado, fechar chamado no GLPI
    if (fechar) {
      setStatus('⏳ Fechando chamado...', '');
      await fetch('agenda/fechar_ticket.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ticket_id: TICKET_ID }),
      });
    }

    // ✅ Sucesso!
    const msgFinal = fechar
      ? '✅ Resposta enviada, chamado fechado e agendado!'
      : '✅ Resposta enviada e agendada!';
    setStatus('<i class="bi bi-check-circle-fill text-success"></i> ' + msgFinal + ' Redirecionando...', 'success');
    setTimeout(() => { window.location.href = 'historico.php'; }, 1500);

  } catch (err) {
    setStatus('❌ Erro de conexão: ' + err.message, 'danger');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Enviar Resposta';
  }
});

function setStatus(html, type) {
  const el = document.getElementById('resp-status');
  if (type === 'warning') el.style.color = '#d97706';
  else if (type === 'danger') el.style.color = '#dc2626';
  else if (type === 'success') el.style.color = '#16a34a';
  else el.style.color = '#6b7280';
  el.innerHTML = html;
}

function ativarEdicao() {
  document.getElementById('metaGrid').classList.add('edit-mode');
  document.getElementById('edit-acoes').style.display = 'block';
  document.getElementById('btnEditarCampos').style.display = 'none';
  document.getElementById('edit-status').textContent = '';
  carregarSelectsEdicao();
}

function carregarSelectsEdicao() {
  // Carrega entidades
  fetch('agenda/entidades.php')
    .then(r => r.json())
    .then(lista => {
      const sel = document.getElementById('edit-entidade-select');
      sel.innerHTML = '<option value="">— Selecione —</option>';
      lista.forEach(e => {
        const opt = document.createElement('option');
        opt.value = e.id;
        opt.textContent = e.nome;
        if (parseInt(e.id) === CURRENT_ENTIDADE) opt.selected = true;
        sel.appendChild(opt);
      });
    })
    .catch(() => {
      document.getElementById('edit-entidade-select').innerHTML = '<option value="">Erro ao carregar</option>';
    });

  // Carrega categorias
  fetch('agenda/categorias.php')
    .then(r => r.json())
    .then(lista => {
      const sel = document.getElementById('edit-categoria-select');
      sel.innerHTML = '<option value="">— Selecione —</option>';
      lista.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.nome;
        if (parseInt(c.id) === CURRENT_CATEGORIA) opt.selected = true;
        sel.appendChild(opt);
      });
    })
    .catch(() => {
      document.getElementById('edit-categoria-select').innerHTML = '<option value="">Erro ao carregar</option>';
    });

  // Carrega atendentes (técnicos)
  fetch('agenda/users.php')
    .then(r => r.json())
    .then(lista => {
      const sel = document.getElementById('edit-atendente-select');
      sel.innerHTML = '<option value="">— Sem técnico —</option>';
      lista.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.nome;
        if (parseInt(u.id) === CURRENT_ATENDENTE_ID) opt.selected = true;
        sel.appendChild(opt);
      });
    })
    .catch(() => {
      document.getElementById('edit-atendente-select').innerHTML = '<option value="">Erro ao carregar</option>';
    });
}

function salvarEdicao() {
  const entidadeId  = document.getElementById('edit-entidade-select').value;
  const categoriaId = document.getElementById('edit-categoria-select').value;
  const atendenteId = document.getElementById('edit-atendente-select').value;
  const status = document.getElementById('edit-status');

  status.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
  status.style.color = '#6b7280';

  const body = new URLSearchParams();
  body.append('action', 'editar_campos');
  body.append('ticket_id', TICKET_ID);
  body.append('entidade_id', entidadeId || '');
  body.append('categoria_id', categoriaId || '');
  body.append('atendente_id', atendenteId || '');

  fetch('chamado.php?id=' + TICKET_ID, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        status.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Atualizado! Recarregando...';
        status.style.color = '#16a34a';
        setTimeout(() => location.reload(), 1000);
      } else {
        status.innerHTML = '<i class="bi bi-x-circle-fill text-danger me-1"></i> ' + (data.msg || 'Erro');
        status.style.color = '#dc2626';
      }
    })
    .catch(err => {
      status.innerHTML = '<i class="bi bi-x-circle-fill text-danger me-1"></i> Erro de conexão';
      status.style.color = '#dc2626';
    });
}

function cancelarEdicao() {
  document.getElementById('metaGrid').classList.remove('edit-mode');
  document.getElementById('edit-acoes').style.display = 'none';
  document.getElementById('btnEditarCampos').style.display = '';
  document.getElementById('edit-status').textContent = '';
}

// ── Excluir Chamado ──
function abrirModalExcluir() {
  const modal = new bootstrap.Modal(document.getElementById('modalExcluir'));
  document.getElementById('excluir-status').textContent = '';
  document.getElementById('btnConfirmarExcluir').disabled = false;
  document.getElementById('btnConfirmarExcluir').innerHTML = '<i class="bi bi-trash3 me-1"></i>Excluir';
  modal.show();
}

function confirmarExcluir() {
  const btn = document.getElementById('btnConfirmarExcluir');
  const status = document.getElementById('excluir-status');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Excluindo...';
  status.innerHTML = '';
  status.className = 'small mt-2';

  fetch('agenda/excluir_ticket_glpi.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ticket_id: TICKET_ID }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        status.className = 'small mt-2 text-success';
        status.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Chamado excluído! Redirecionando...';
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Excluído';
        setTimeout(() => { window.location.href = 'historico.php'; }, 1200);
      } else {
        status.className = 'small mt-2 text-danger';
        status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> ' + (data.msg || 'Erro ao excluir');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Excluir';
      }
    })
    .catch(err => {
      status.className = 'small mt-2 text-danger';
      status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Erro de conexão';
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Excluir';
    });
}
</script>
</body>
</html>
