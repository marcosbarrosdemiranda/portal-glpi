<?php
// categorias_modal.php
// Partial compartilhado — modal "Gerenciar categorias" (criar/renomear),
// incluído tanto em orcamento.php quanto em despesas.php. As duas telas
// leem/escrevem a mesma tabela (glpi_portal_despesas_tipos), então uma
// categoria criada ou renomeada aqui reflete nos dois lados por desenho
// (mesma linha, não cópia) — não precisa de sincronização em tempo real
// entre abas abertas simultaneamente.
//
// Não excluir nada por aqui: exclusão de categoria está fora de escopo
// (decidir o que fazer com itens já vinculados é pergunta em aberto).
?>
<div class="modal fade" id="modalCategorias" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Gerenciar categorias</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Categoria é o mesmo catálogo usado em Orçamento e Gestão de Despesas — criar ou renomear aqui reflete nos dois.</p>
        <div id="cat-lista" class="mb-3" style="max-height:260px;overflow-y:auto"></div>
        <div class="input-group">
          <input type="text" id="cat-nova-nome" class="form-control" placeholder="Nova categoria...">
          <button type="button" class="btn btn-primary" onclick="salvarNovaCategoria()"><i class="bi bi-plus-lg"></i> Adicionar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let _modalCategorias = null;

function abrirGerenciarCategorias() {
  if (!_modalCategorias) _modalCategorias = new bootstrap.Modal(document.getElementById('modalCategorias'));
  carregarListaCategorias();
  _modalCategorias.show();
}

async function carregarListaCategorias() {
  const el = document.getElementById('cat-lista');
  el.innerHTML = '<div class="text-muted small">Carregando...</div>';
  try {
    const res = await fetch('agenda/tipos_db.php?list_tipos=1');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const tipos = await res.json();
    el.innerHTML = tipos.length
      ? tipos.map(t => `
        <div class="d-flex align-items-center gap-2 mb-1" id="cat-row-${t.id}">
          <span class="flex-grow-1 cat-nome-${t.id}">${escHtmlCat(t.nome)}</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="iniciarEdicaoCategoria(${t.id}, ${JSON.stringify(t.nome)})"><i class="bi bi-pencil"></i></button>
        </div>`).join('')
      : '<div class="text-muted small">Nenhuma categoria ainda.</div>';
  } catch (e) {
    el.innerHTML = '<div class="text-danger small">Erro ao carregar categorias.</div>';
  }
}

function iniciarEdicaoCategoria(id, nomeAtual) {
  const row = document.getElementById('cat-row-' + id);
  if (!row) return;
  row.innerHTML = `
    <input type="text" class="form-control form-control-sm flex-grow-1" id="cat-edit-input-${id}" value="${escHtmlCat(nomeAtual).replace(/"/g, '&quot;')}">
    <button type="button" class="btn btn-sm btn-success" onclick="salvarEdicaoCategoria(${id})"><i class="bi bi-check-lg"></i></button>`;
}

async function salvarEdicaoCategoria(id) {
  const nome = document.getElementById('cat-edit-input-' + id).value.trim();
  if (!nome) return;
  await postCategoria({ action_tipo: 'edit', id, nome });
  await carregarListaCategorias();
  atualizarSelectsCategoria();
}

async function salvarNovaCategoria() {
  const input = document.getElementById('cat-nova-nome');
  const nome = input.value.trim();
  if (!nome) return;
  await postCategoria({ action_tipo: 'add', nome });
  input.value = '';
  await carregarListaCategorias();
  atualizarSelectsCategoria();
}

function postCategoria(campos) {
  const body = new URLSearchParams({ ajax: '1', ...campos });
  return fetch('agenda/tipos_db.php', { method: 'POST', body });
}

// Recarrega o(s) <select> de categoria da própria página (sem reload) —
// cada página que inclui este modal expõe o(s) id(s) dos seus selects em
// window.SELECTS_CATEGORIA antes de incluir este partial.
async function atualizarSelectsCategoria() {
  const ids = window.SELECTS_CATEGORIA || [];
  if (!ids.length) return;
  const res = await fetch('agenda/tipos_db.php?list_tipos=1');
  const tipos = await res.json();
  ids.forEach(selId => {
    const sel = document.getElementById(selId);
    if (!sel) return;
    const atual = sel.value;
    const temPlaceholder = sel.querySelector('option[value=""]');
    sel.innerHTML = (temPlaceholder ? '<option value="">Selecione...</option>' : '') +
      tipos.map(t => `<option value="${t.id}">${escHtmlCat(t.nome)}</option>`).join('');
    if (atual) sel.value = atual;
  });
}

function escHtmlCat(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
</script>
