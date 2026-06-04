<?php
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) { header('Location: historico.php'); exit; }

require_once __DIR__ . '/agenda/config.php';

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
$users_req = glpi_req(GLPI_URL.'/apirest.php/Ticket/'.$ticket_id.'/Ticket_User?expand_dropdowns=true', $token);

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Chamado #<?= $ticket_id ?> — <?= htmlspecialchars($ticket['name'] ?? '') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
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
    </div>

    <div class="meta-grid">
      <div class="meta-item">
        <div class="label">Entidade</div>
        <div class="val"><?= htmlspecialchars($ticket['entities_id'] ?? '') ?></div>
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

    <!-- Atores -->
    <div class="mt-3 d-flex flex-wrap gap-3">
      <?php if (!empty($requerentes)): ?>
      <div>
        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:.2rem">Requerente</div>
        <?php foreach ($requerentes as $u): ?>
          <span class="ator-chip"><i class="bi bi-person-fill"></i><?= htmlspecialchars($u['users_id'] ?? '') ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($atribuidos)): ?>
      <div>
        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:.2rem">Atribuído</div>
        <?php foreach ($atribuidos as $u): ?>
          <span class="ator-chip"><i class="bi bi-headset"></i><?= htmlspecialchars($u['users_id'] ?? '') ?></span>
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
            <span class="fu-author"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($fu['users_id'] ?? 'Sistema') ?></span>
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
</script>
</body>
</html>
