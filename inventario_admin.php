<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/inventario_lib.php';
inv_bootstrap($pdo);

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}
function jout($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d); exit; }

$action = $_POST['action'] ?? '';

/* ───────── CARDS ───────── */
if ($action === 'card_save') {
    $id     = (int)($_POST['id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    if ($titulo === '') jout(['ok' => false, 'erro' => 'Título é obrigatório']);
    $slug = slugify($_POST['slug'] ?? '') ?: slugify($titulo);
    $dados = [
        'slug'      => $slug,
        'titulo'    => $titulo,
        'descricao' => trim($_POST['descricao'] ?? ''),
        'icone'     => trim($_POST['icone'] ?? 'bi-box') ?: 'bi-box',
        'cor'       => trim($_POST['cor'] ?? '#0097a7') ?: '#0097a7',
        'fonte'     => ($_POST['fonte'] ?? 'peripheral') === 'phone' ? 'phone' : 'peripheral',
        'ordem'     => (int)($_POST['ordem'] ?? 100),
        'ativo'     => isset($_POST['ativo']) && $_POST['ativo'] !== '0' ? 1 : 0,
    ];
    // slug único
    $chk = $pdo->prepare("SELECT id FROM portal_inv_cards WHERE slug = ? AND id <> ?");
    $chk->execute([$slug, $id]);
    if ($chk->fetch()) jout(['ok' => false, 'erro' => "Já existe um card com o slug \"$slug\""]);

    if ($id) {
        $dados['id'] = $id;
        $pdo->prepare("UPDATE portal_inv_cards SET slug=:slug,titulo=:titulo,descricao=:descricao,icone=:icone,cor=:cor,fonte=:fonte,ordem=:ordem,ativo=:ativo WHERE id=:id")->execute($dados);
    } else {
        $pdo->prepare("INSERT INTO portal_inv_cards (slug,titulo,descricao,icone,cor,fonte,ordem,ativo) VALUES (:slug,:titulo,:descricao,:icone,:cor,:fonte,:ordem,:ativo)")->execute($dados);
        $id = (int)$pdo->lastInsertId();
    }
    jout(['ok' => true, 'id' => $id]);
}

if ($action === 'card_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM portal_inv_cards WHERE id = ?")->execute([$id]);   // subcats caem por FK
    $pdo->prepare("DELETE FROM portal_inv_fields WHERE card_id = ?")->execute([$id]);
    jout(['ok' => true]);
}

/* ───────── SUBCATEGORIAS ───────── */
if ($action === 'sub_save') {
    $id      = (int)($_POST['id'] ?? 0);
    $cardId  = (int)($_POST['card_id'] ?? 0);
    $nome    = trim($_POST['nome'] ?? '');
    $ordem   = (int)($_POST['ordem'] ?? 100);
    if ($nome === '' || !$cardId) jout(['ok' => false, 'erro' => 'Nome e card são obrigatórios']);

    if ($id) {
        $pdo->prepare("UPDATE portal_inv_subcats SET nome=?,ordem=? WHERE id=?")->execute([$nome, $ordem, $id]);
    } else {
        $pdo->prepare("INSERT INTO portal_inv_subcats (card_id,nome,ordem) VALUES (?,?,?)")->execute([$cardId, $nome, $ordem]);
        $id = (int)$pdo->lastInsertId();
    }
    // tenta criar/vincular o tipo no GLPI (não fatal)
    $glpiId = null;
    try {
        $card = $pdo->query("SELECT fonte FROM portal_inv_cards WHERE id = " . $cardId)->fetchColumn();
        $tk = glpi_session();
        if ($tk) {
            $glpiId = glpi_ensure_type($pdo, $card ?: 'peripheral', $nome, $tk);
            glpi_kill($tk);
            if ($glpiId) $pdo->prepare("UPDATE portal_inv_subcats SET glpi_type_id = ? WHERE id = ?")->execute([$glpiId, $id]);
        }
    } catch (Throwable $e) { /* segue */ }
    jout(['ok' => true, 'id' => $id, 'glpi_type_id' => $glpiId]);
}

if ($action === 'sub_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM portal_inv_subcats WHERE id = ?")->execute([$id]);  // o tipo no GLPI fica (pode ter ativos)
    jout(['ok' => true]);
}

/* ───────── CAMPOS PERSONALIZADOS ───────── */
if ($action === 'field_save') {
    $id      = (int)($_POST['id'] ?? 0);
    $cardId  = ($_POST['card_id'] ?? '') === '' ? null : (int)$_POST['card_id'];
    $label   = trim($_POST['label'] ?? '');
    if ($label === '') jout(['ok' => false, 'erro' => 'Rótulo é obrigatório']);
    $chave   = slugify($_POST['chave'] ?? '') ?: slugify($label);
    $tipo    = in_array($_POST['tipo'] ?? '', ['text','number','date','select','textarea','checkbox'], true) ? $_POST['tipo'] : 'text';
    $dados = [
        'card_id'     => $cardId,
        'chave'       => $chave,
        'label'       => $label,
        'tipo'        => $tipo,
        'opcoes'      => trim($_POST['opcoes'] ?? '') ?: null,
        'obrigatorio' => isset($_POST['obrigatorio']) && $_POST['obrigatorio'] !== '0' ? 1 : 0,
        'na_lista'    => isset($_POST['na_lista']) && $_POST['na_lista'] !== '0' ? 1 : 0,
        'ordem'       => (int)($_POST['ordem'] ?? 100),
    ];
    if ($id) {
        $dados['id'] = $id;
        $pdo->prepare("UPDATE portal_inv_fields SET card_id=:card_id,chave=:chave,label=:label,tipo=:tipo,opcoes=:opcoes,obrigatorio=:obrigatorio,na_lista=:na_lista,ordem=:ordem WHERE id=:id")->execute($dados);
    } else {
        $pdo->prepare("INSERT INTO portal_inv_fields (card_id,chave,label,tipo,opcoes,obrigatorio,na_lista,ordem) VALUES (:card_id,:chave,:label,:tipo,:opcoes,:obrigatorio,:na_lista,:ordem)")->execute($dados);
        $id = (int)$pdo->lastInsertId();
    }
    jout(['ok' => true, 'id' => $id]);
}

if ($action === 'field_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM portal_inv_fields WHERE id = ?")->execute([$id]);  // valores caem por FK
    jout(['ok' => true]);
}

/* ───────── PÁGINA ───────── */
$cards = inv_cards($pdo, true);
$subsBy = []; $fieldsBy = [];
foreach ($cards as $c) {
    $subsBy[$c['id']]   = inv_subcats($pdo, (int)$c['id']);
    $fieldsBy[$c['id']] = inv_fields($pdo, (int)$c['id']);
}
$H = 'inv_h';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Configuração</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:#1a237e; color:#fff; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar .spacer { flex:1; }
    .topbar a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.85rem; padding:.3rem .7rem; border-radius:6px; }
    .topbar a:hover { background:rgba(255,255,255,.15); color:#fff; }
    .wrap { max-width:1000px; margin:1.5rem auto 3rem; padding:0 1.5rem; }
    h1 { font-size:1.35rem; font-weight:800; color:#1a237e; }
    .muted { color:#5f6368; font-size:.85rem; }
    .card-box { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:1rem; overflow:hidden; }
    .card-hd { display:flex; align-items:center; gap:.75rem; padding:.85rem 1.1rem; cursor:pointer; }
    .card-hd .ic { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; color:#fff; flex-shrink:0; }
    .card-hd h3 { margin:0; font-size:1rem; }
    .card-hd .slug { color:#9aa0a6; font-size:.76rem; }
    .card-hd .fonte { font-size:.7rem; background:#eef1f5; color:#5f6368; border-radius:10px; padding:.1rem .5rem; }
    .card-hd .inativo { font-size:.7rem; background:#fff3e0; color:#e65100; border-radius:10px; padding:.1rem .5rem; }
    .card-hd .chev { margin-left:auto; color:#9aa0a6; }
    .card-body { display:none; padding:0 1.1rem 1.1rem; border-top:1px solid #eef1f5; }
    .card-box.open .card-body { display:block; }
    .card-box.open .chev { transform:rotate(90deg); }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; margin:.9rem 0; }
    .fld label { display:block; font-size:.76rem; font-weight:600; color:#5f6368; margin-bottom:.2rem; }
    .fld input, .fld select, .fld textarea { width:100%; border:1px solid #d5dae2; border-radius:8px; padding:.38rem .55rem; font-size:.85rem; }
    .fld.full { grid-column:1/-1; }
    .sec { margin-top:1rem; }
    .sec h4 { font-size:.8rem; text-transform:uppercase; letter-spacing:.5px; color:#80868b; margin:0 0 .5rem; }
    .lin { display:flex; align-items:center; gap:.5rem; padding:.4rem 0; border-bottom:1px dashed #eef1f5; }
    .lin input { border:1px solid #e0e4ea; border-radius:7px; padding:.3rem .5rem; font-size:.82rem; }
    .lin .nome { flex:1; }
    .lin .ord { width:64px; }
    .lin .gid { font-size:.72rem; color:#9aa0a6; white-space:nowrap; }
    .btn { border:none; border-radius:8px; padding:.4rem .85rem; font-size:.82rem; cursor:pointer; }
    .btn-primary { background:#1a237e; color:#fff; }
    .btn-ghost { background:#f1f3f4; color:#3c4043; }
    .btn-sm { padding:.25rem .5rem; font-size:.78rem; }
    .btn-del { background:#fff; border:1px solid #e0e4ea; color:#9aa0a6; }
    .btn-del:hover { border-color:#c62828; color:#c62828; }
    .row-actions { display:flex; gap:.5rem; margin-top:.7rem; }
    .fbox { border:1px solid #e0e4ea; border-radius:10px; padding:.85rem 1rem; margin-bottom:.7rem; background:#fafbfc; }
    #novoCardBox { background:#fff; border:2px dashed #c5cae9; border-radius:12px; padding:1rem 1.1rem; margin-bottom:1.5rem; }
    #msg { position:fixed; bottom:1.2rem; right:1.2rem; z-index:1100; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="bi bi-sliders"></i> Inventário — Configuração</div>
  <span class="spacer"></span>
  <a href="inventario.php"><i class="bi bi-grid-3x3-gap"></i> Ver inventário</a>
  <a href="dashboard.php"><i class="bi bi-house"></i> Início</a>
</div>

<div class="wrap">
  <h1>Cards e subcategorias</h1>
  <p class="muted">Cada card lê de <code>glpi_peripherals</code> ou <code>glpi_phones</code>. Cada subcategoria vira um "tipo" no GLPI (criado automaticamente ao salvar).</p>

  <div id="novoCardBox">
    <h4 style="margin:0 0 .6rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;color:#80868b">Novo card</h4>
    <div class="grid2">
      <div class="fld"><label>Título</label><input id="nc-titulo"/></div>
      <div class="fld"><label>Slug (opcional)</label><input id="nc-slug" placeholder="gerado do título"/></div>
      <div class="fld full"><label>Descrição</label><input id="nc-descricao"/></div>
      <div class="fld"><label>Ícone (Bootstrap Icons)</label><input id="nc-icone" value="bi-box"/></div>
      <div class="fld"><label>Cor</label><input type="color" id="nc-cor" value="#0097a7"/></div>
      <div class="fld"><label>Fonte no GLPI</label>
        <select id="nc-fonte"><option value="peripheral">Periférico</option><option value="phone">Telefone</option></select>
      </div>
      <div class="fld"><label>Ordem</label><input type="number" id="nc-ordem" value="100"/></div>
    </div>
    <button class="btn btn-primary" onclick="salvarCard(0)"><i class="bi bi-plus-lg"></i> Criar card</button>
  </div>

  <?php foreach ($cards as $c): $cid = (int)$c['id']; ?>
  <div class="card-box" data-card="<?= $cid ?>">
    <div class="card-hd" onclick="this.parentElement.classList.toggle('open')">
      <div class="ic" style="background:<?= $H($c['cor']) ?>"><i class="bi <?= $H($c['icone']) ?>"></i></div>
      <div>
        <h3><?= $H($c['titulo']) ?> <span class="slug"><?= $H($c['slug']) ?></span></h3>
      </div>
      <span class="fonte"><?= $c['fonte'] === 'phone' ? 'telefone' : 'periférico' ?></span>
      <?php if (!$c['ativo']): ?><span class="inativo">inativo</span><?php endif; ?>
      <i class="bi bi-chevron-right chev"></i>
    </div>
    <div class="card-body">
      <div class="grid2">
        <div class="fld"><label>Título</label><input id="c<?= $cid ?>-titulo" value="<?= $H($c['titulo']) ?>"/></div>
        <div class="fld"><label>Slug</label><input id="c<?= $cid ?>-slug" value="<?= $H($c['slug']) ?>"/></div>
        <div class="fld full"><label>Descrição</label><input id="c<?= $cid ?>-descricao" value="<?= $H($c['descricao']) ?>"/></div>
        <div class="fld"><label>Ícone</label><input id="c<?= $cid ?>-icone" value="<?= $H($c['icone']) ?>"/></div>
        <div class="fld"><label>Cor</label><input type="color" id="c<?= $cid ?>-cor" value="<?= $H($c['cor']) ?>"/></div>
        <div class="fld"><label>Fonte</label>
          <select id="c<?= $cid ?>-fonte">
            <option value="peripheral" <?= $c['fonte'] === 'peripheral' ? 'selected' : '' ?>>Periférico</option>
            <option value="phone" <?= $c['fonte'] === 'phone' ? 'selected' : '' ?>>Telefone</option>
          </select>
        </div>
        <div class="fld"><label>Ordem</label><input type="number" id="c<?= $cid ?>-ordem" value="<?= (int)$c['ordem'] ?>"/></div>
        <div class="fld"><label>Ativo</label>
          <select id="c<?= $cid ?>-ativo"><option value="1" <?= $c['ativo'] ? 'selected' : '' ?>>Sim</option><option value="0" <?= !$c['ativo'] ? 'selected' : '' ?>>Não</option></select>
        </div>
      </div>
      <div class="row-actions">
        <button class="btn btn-primary btn-sm" onclick="salvarCard(<?= $cid ?>)">Salvar card</button>
        <button class="btn btn-del btn-sm" onclick="excluirCard(<?= $cid ?>, '<?= $H(addslashes($c['titulo'])) ?>')">Excluir card</button>
      </div>

      <div class="sec">
        <h4>Subcategorias</h4>
        <div id="subs-<?= $cid ?>">
          <?php foreach ($subsBy[$cid] as $s): ?>
          <div class="lin" data-sub="<?= (int)$s['id'] ?>">
            <input class="nome" value="<?= $H($s['nome']) ?>"/>
            <input class="ord" type="number" value="<?= (int)$s['ordem'] ?>"/>
            <span class="gid">GLPI #<?= $s['glpi_type_id'] ? (int)$s['glpi_type_id'] : '—' ?></span>
            <button class="btn btn-ghost btn-sm" onclick="salvarSub(<?= $cid ?>, <?= (int)$s['id'] ?>)">Salvar</button>
            <button class="btn btn-del btn-sm" onclick="excluirSub(<?= (int)$s['id'] ?>)"><i class="bi bi-trash3"></i></button>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="lin">
          <input class="nome" id="ns-<?= $cid ?>-nome" placeholder="Nova subcategoria..."/>
          <input class="ord" id="ns-<?= $cid ?>-ord" type="number" value="100"/>
          <button class="btn btn-primary btn-sm" onclick="salvarSub(<?= $cid ?>, 0)"><i class="bi bi-plus-lg"></i> Adicionar</button>
        </div>
      </div>

      <div class="sec">
        <h4>Características do item (campos personalizados)</h4>
        <p class="muted" style="margin:.2rem 0 .7rem;font-size:.8rem">Aparecem no formulário de cada ativo deste card. Marque "na lista" para virar coluna na listagem.</p>
        <?php foreach ($fieldsBy[$cid] as $f): $global = $f['card_id'] === null; ?>
        <div class="fbox" data-field="<?= (int)$f['id'] ?>">
          <div class="grid2">
            <div class="fld"><label>Rótulo</label><input class="f-label" value="<?= $H($f['label']) ?>" <?= $global ? 'disabled' : '' ?>/></div>
            <div class="fld"><label>Tipo</label>
              <select class="f-tipo" <?= $global ? 'disabled' : '' ?>>
                <?php foreach (['text'=>'Texto','number'=>'Número','date'=>'Data','select'=>'Lista','textarea'=>'Texto longo','checkbox'=>'Sim/Não'] as $tv => $tl): ?>
                  <option value="<?= $tv ?>" <?= $f['tipo'] === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fld full"><label>Opções da lista (uma por linha)</label><textarea class="f-opcoes" rows="2" <?= $global ? 'disabled' : '' ?>><?= $H($f['opcoes']) ?></textarea></div>
            <div class="fld"><label>Obrigatório</label><select class="f-obrig" <?= $global ? 'disabled' : '' ?>><option value="0">Não</option><option value="1" <?= $f['obrigatorio'] ? 'selected' : '' ?>>Sim</option></select></div>
            <div class="fld"><label>Mostrar na lista</label><select class="f-lista" <?= $global ? 'disabled' : '' ?>><option value="0">Não</option><option value="1" <?= !empty($f['na_lista']) ? 'selected' : '' ?>>Sim</option></select></div>
            <div class="fld"><label>Ordem</label><input class="f-ordem" type="number" value="<?= (int)$f['ordem'] ?>" <?= $global ? 'disabled' : '' ?>/></div>
          </div>
          <?php if (!$global): ?>
          <div class="row-actions">
            <button class="btn btn-primary btn-sm" onclick="salvarField(<?= $cid ?>, <?= (int)$f['id'] ?>)">Salvar campo</button>
            <button class="btn btn-del btn-sm" onclick="excluirField(<?= (int)$f['id'] ?>)"><i class="bi bi-trash3"></i> Excluir</button>
          </div>
          <?php else: ?><p class="muted" style="font-size:.76rem;margin:.3rem 0 0">Campo global — editável em outro lugar.</p><?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="fbox" style="border-style:dashed">
          <div class="grid2">
            <div class="fld"><label>Rótulo</label><input id="nf-<?= $cid ?>-label" placeholder="Ex: PDV vinculado"/></div>
            <div class="fld"><label>Tipo</label>
              <select id="nf-<?= $cid ?>-tipo">
                <option value="text">Texto</option><option value="number">Número</option><option value="date">Data</option>
                <option value="select">Lista</option><option value="textarea">Texto longo</option><option value="checkbox">Sim/Não</option>
              </select>
            </div>
            <div class="fld full"><label>Opções da lista (uma por linha — só p/ tipo Lista)</label><textarea id="nf-<?= $cid ?>-opcoes" rows="2"></textarea></div>
            <div class="fld"><label>Obrigatório</label><select id="nf-<?= $cid ?>-obrig"><option value="0">Não</option><option value="1">Sim</option></select></div>
            <div class="fld"><label>Mostrar na lista</label><select id="nf-<?= $cid ?>-lista"><option value="0">Não</option><option value="1">Sim</option></select></div>
            <div class="fld"><label>Ordem</label><input type="number" id="nf-<?= $cid ?>-ordem" value="100"/></div>
          </div>
          <button class="btn btn-primary btn-sm" onclick="salvarField(<?= $cid ?>, 0)"><i class="bi bi-plus-lg"></i> Adicionar característica</button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div id="msg"></div>

<script>
const $ = s => document.querySelector(s);
function toast(t, ok = true) {
  const d = document.createElement('div');
  d.className = 'alert alert-' + (ok ? 'success' : 'danger') + ' py-2 px-3 mb-2 shadow-sm';
  d.textContent = t; $('#msg').appendChild(d);
  setTimeout(() => d.remove(), 4000);
}
function post(data) {
  const fd = new FormData();
  for (const k in data) fd.set(k, data[k]);
  return fetch('inventario_admin.php', { method: 'POST', body: fd }).then(r => r.json());
}
function reload() { setTimeout(() => location.reload(), 600); }

function salvarCard(id) {
  const p = id ? 'c' + id + '-' : 'nc-';
  post({
    action: 'card_save', id,
    titulo: $('#' + p + 'titulo').value, slug: $('#' + p + 'slug').value,
    descricao: $('#' + p + 'descricao').value, icone: $('#' + p + 'icone').value,
    cor: $('#' + p + 'cor').value, fonte: $('#' + p + 'fonte').value,
    ordem: $('#' + p + 'ordem').value, ativo: id ? $('#c' + id + '-ativo').value : 1,
  }).then(d => { if (d.ok) { toast('Card salvo'); reload(); } else toast(d.erro, false); });
}
function excluirCard(id, nome) {
  if (!confirm(`Excluir o card "${nome}"?\n\nRemove as subcategorias e campos personalizados dele. Os ativos no GLPI não são apagados.`)) return;
  post({ action: 'card_delete', id }).then(d => { if (d.ok) { toast('Card excluído'); reload(); } });
}

function salvarSub(cardId, id) {
  const el = id ? document.querySelector(`.lin[data-sub="${id}"]`) : null;
  const nome = id ? el.querySelector('.nome').value : $(`#ns-${cardId}-nome`).value;
  const ordem = id ? el.querySelector('.ord').value : $(`#ns-${cardId}-ord`).value;
  if (!nome.trim()) { toast('Nome da subcategoria vazio', false); return; }
  post({ action: 'sub_save', id, card_id: cardId, nome, ordem })
    .then(d => { if (d.ok) { toast('Subcategoria salva' + (d.glpi_type_id ? ' (GLPI #' + d.glpi_type_id + ')' : '')); reload(); } else toast(d.erro, false); });
}
function excluirSub(id) {
  if (!confirm('Remover esta subcategoria?\n\nO tipo correspondente no GLPI continua lá (pode ter ativos vinculados).')) return;
  post({ action: 'sub_delete', id }).then(d => { if (d.ok) { toast('Removida'); reload(); } });
}

function salvarField(cardId, id) {
  let data;
  if (id) {
    const el = document.querySelector(`.fbox[data-field="${id}"]`);
    data = {
      action: 'field_save', id, card_id: cardId,
      label: el.querySelector('.f-label').value, tipo: el.querySelector('.f-tipo').value,
      opcoes: el.querySelector('.f-opcoes').value, obrigatorio: el.querySelector('.f-obrig').value,
      na_lista: el.querySelector('.f-lista').value, ordem: el.querySelector('.f-ordem').value,
    };
  } else {
    data = {
      action: 'field_save', id: 0, card_id: cardId,
      label: $(`#nf-${cardId}-label`).value, tipo: $(`#nf-${cardId}-tipo`).value,
      opcoes: $(`#nf-${cardId}-opcoes`).value, obrigatorio: $(`#nf-${cardId}-obrig`).value,
      na_lista: $(`#nf-${cardId}-lista`).value, ordem: $(`#nf-${cardId}-ordem`).value,
    };
  }
  if (!data.label.trim()) { toast('Rótulo do campo vazio', false); return; }
  post(data).then(d => { if (d.ok) { toast('Característica salva'); reload(); } else toast(d.erro, false); });
}
function excluirField(id) {
  if (!confirm('Excluir este campo?\n\nOs valores já preenchidos nos ativos serão perdidos.')) return;
  post({ action: 'field_delete', id }).then(d => { if (d.ok) { toast('Campo excluído'); reload(); } });
}
</script>
</body>
</html>
