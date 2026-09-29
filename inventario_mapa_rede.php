<?php
/**
 * inventario_mapa_rede.php — Mapa de rede do portal (etapa 4).
 *
 * Mostra todos os equipamentos monitorados agrupados por loja e categoria,
 * com status em tempo real (atualiza via AJAX a cada 30 s).
 * Substitui o mapa do The Dude.
 *
 * Roteiro: Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md §Etapa 4
 */

require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Mapa de rede</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root {
      --primary: #1a237e;
      --accent:  #0097a7;
      --up:      #16a34a;
      --down:    #dc2626;
      --recent:  #d97706;
      --unknown: #9ca3af;
    }
    * { box-sizing: border-box; }
    body { background: #f0f4f9; font-family: 'Segoe UI', sans-serif; min-height: 100vh; margin: 0; }

    /* ── Topbar ── */
    .topbar {
      background: #1a237e; color: #fff;
      padding: .75rem 1.5rem;
      display: flex; align-items: center; gap: 1rem;
      box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }
    .topbar .brand { font-weight: 700; font-size: 1.05rem; display: flex; align-items: center; gap: .5rem; }
    .topbar .spacer { flex: 1; }
    .topbar a {
      color: rgba(255,255,255,.8); text-decoration: none; font-size: .85rem;
      display: flex; align-items: center; gap: .35rem;
      padding: .3rem .7rem; border-radius: 6px; transition: .15s;
    }
    .topbar a:hover { background: rgba(255,255,255,.15); color: #fff; }

    /* ── Cabeçalho da página ── */
    .page-header {
      padding: 1.5rem 1.5rem .5rem;
      max-width: 1400px; margin: 0 auto;
      display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    }
    .page-header h1 { font-size: 1.4rem; font-weight: 800; color: #1a237e; margin: 0; }
    .heartbeat { font-size: .75rem; color: #6b7280; display: flex; align-items: center; gap: .35rem; }
    .heartbeat .dot-live { width: 8px; height: 8px; border-radius: 50%; background: #16a34a;
                           animation: pulse-live 2s infinite; }
    @keyframes pulse-live {
      0%, 100% { opacity: 1; }
      50%       { opacity: .3; }
    }

    /* ── Filtros ── */
    .filtros {
      padding: .5rem 1.5rem 1rem;
      max-width: 1400px; margin: 0 auto;
      display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;
    }
    .filtros select, .filtros input { font-size: .85rem; }
    .btn-toggle-problemas {
      font-size: .8rem; padding: .3rem .85rem;
      border-radius: 20px; border: 1.5px solid var(--down);
      background: transparent; color: var(--down); cursor: pointer; transition: .15s;
    }
    .btn-toggle-problemas.ativo { background: var(--down); color: #fff; }

    /* ── Resumo global ── */
    .resumo-global {
      max-width: 1400px; margin: 0 auto .75rem;
      padding: 0 1.5rem;
      display: flex; gap: 1.25rem; flex-wrap: wrap;
    }
    .resumo-chip {
      display: flex; align-items: center; gap: .4rem;
      background: #fff; border-radius: 20px;
      padding: .3rem .9rem; font-size: .82rem; font-weight: 600;
      box-shadow: 0 1px 3px rgba(0,0,0,.08);
    }
    .resumo-chip .dot { width: 10px; height: 10px; border-radius: 50%; }
    .dot-up      { background: var(--up);      box-shadow: 0 0 5px var(--up); }
    .dot-down    { background: var(--down);    box-shadow: 0 0 5px var(--down); }
    .dot-recent  { background: var(--recent);  }
    .dot-unknown { background: var(--unknown); }

    /* ── Seção de loja ── */
    .loja-section {
      max-width: 1400px; margin: 0 auto 1.5rem;
      padding: 0 1.5rem;
    }
    .loja-header {
      display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
      margin-bottom: .5rem;
    }
    .loja-titulo {
      font-size: 1rem; font-weight: 700; color: #1a237e;
      display: flex; align-items: center; gap: .4rem;
    }
    .loja-resumo { font-size: .8rem; color: #5f6368; }
    .loja-links { display: flex; gap: .5rem; flex-wrap: wrap; }
    .link-badge {
      font-size: .72rem; font-weight: 600; padding: .2rem .65rem;
      border-radius: 20px; display: flex; align-items: center; gap: .3rem;
    }
    .link-up      { background: #dcfce7; color: #15803d; }
    .link-down    { background: #fee2e2; color: #b91c1c; }
    .link-alerta  { background: #fef9c3; color: #b45309; }
    .link-padrao  { outline: 1.5px solid currentColor; }
    .vpn-badge    { background: #fee2e2; color: #b91c1c; font-size: .72rem; font-weight: 700;
                    padding: .2rem .65rem; border-radius: 20px; }

    /* ── Grupo de equipamentos ── */
    .grupo-bloco { margin-bottom: 1rem; }
    .grupo-nome {
      font-size: .78rem; font-weight: 700; text-transform: uppercase;
      color: #6b7280; letter-spacing: .05em; margin-bottom: .4rem;
    }
    .equipamentos-grid {
      display: flex; flex-wrap: wrap; gap: .5rem;
    }

    /* ── Quadradinho do equipamento ── */
    .eq-card {
      width: 120px; background: #fff;
      border-radius: 8px; padding: .5rem .6rem;
      box-shadow: 0 1px 3px rgba(0,0,0,.1);
      border-left: 4px solid var(--unknown);
      cursor: pointer; transition: .15s;
      position: relative;
      display: flex; flex-direction: column; gap: .2rem;
    }
    .eq-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,.18); transform: translateY(-1px); }
    .eq-card.st-up      { border-left-color: var(--up);     }
    .eq-card.st-down    { border-left-color: var(--down);   }
    .eq-card.st-recent  { border-left-color: var(--recent); }
    .eq-card.st-unknown { border-left-color: var(--unknown);}

    .eq-dot {
      position: absolute; top: 6px; right: 6px;
      width: 9px; height: 9px; border-radius: 50%;
      background: var(--unknown);
    }
    .eq-card.st-up    .eq-dot { background: var(--up);    box-shadow: 0 0 5px var(--up); }
    .eq-card.st-down  .eq-dot { background: var(--down);  box-shadow: 0 0 5px var(--down); }
    .eq-card.st-recent .eq-dot{ background: var(--recent);}

    .eq-nome {
      font-size: .73rem; font-weight: 700; color: #1f2937;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 95px;
    }
    .eq-ip {
      font-size: .68rem; color: #6b7280; font-family: monospace;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .eq-desde {
      font-size: .63rem; color: #9ca3af;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .eq-card.st-down .eq-desde { color: #ef4444; font-weight: 600; }

    /* ── Modal de detalhe ── */
    .modal-header-mapa { background: #1a237e; color: #fff; }
    .modal-header-mapa .btn-close { filter: invert(1); }
    .detail-row { display: flex; gap: .5rem; align-items: baseline; margin-bottom: .25rem; font-size: .88rem; }
    .detail-label { font-weight: 600; color: #374151; min-width: 90px; }
    .badge-st-up      { background: #dcfce7; color: #15803d; }
    .badge-st-down    { background: #fee2e2; color: #b91c1c; }
    .badge-st-recent  { background: #fef3c7; color: #92400e; }
    .badge-st-unknown { background: #f3f4f6; color: #6b7280; }

    .quedas-lista { font-size: .8rem; }
    .quedas-lista li { padding: .2rem 0; border-bottom: 1px solid #f3f4f6; }

    /* ── Sem dados ── */
    .vazio { text-align: center; padding: 3rem; color: #9ca3af; }

    /* ── Spinner de carregamento ── */
    .loading-overlay {
      position: fixed; inset: 0; background: rgba(240,244,249,.8);
      display: flex; align-items: center; justify-content: center;
      z-index: 9999; font-size: 1rem; color: #1a237e; gap: .75rem;
    }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand"><i class="bi bi-diagram-3-fill"></i> Mapa de Rede</div>
  <span class="spacer"></span>
  <a href="monitor_dispositivos.php"><i class="bi bi-list-ul"></i> Dispositivos</a>
  <a href="inventario.php"><i class="bi bi-box-seam"></i> Inventário</a>
  <a href="dashboard.php"><i class="bi bi-grid"></i> Início</a>
</div>

<!-- Cabeçalho -->
<div class="page-header">
  <div>
    <h1><i class="bi bi-diagram-3-fill me-2"></i>Mapa de Rede</h1>
  </div>
  <div class="heartbeat ms-auto">
    <span class="dot-live"></span>
    <span id="txt-rodada">Carregando...</span>
    <span id="txt-proxima" style="margin-left:.5rem"></span>
  </div>
</div>

<!-- Filtros -->
<div class="filtros">
  <select id="f-loja" class="form-select form-select-sm" style="width:150px" onchange="aplicarFiltros()">
    <option value="">Todas as lojas</option>
  </select>
  <select id="f-grupo" class="form-select form-select-sm" style="width:170px" onchange="aplicarFiltros()">
    <option value="">Todos os grupos</option>
  </select>
  <button class="btn-toggle-problemas" id="btn-problemas" onclick="toggleProblemas()">
    <i class="bi bi-exclamation-triangle me-1"></i>Só problemas
  </button>
  <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="carregarDados()">
    <i class="bi bi-arrow-clockwise"></i>
  </button>
</div>

<!-- Resumo global -->
<div class="resumo-global" id="resumo-global"></div>

<!-- Mapa -->
<div id="mapa-container"></div>

<!-- Modal de detalhe do equipamento -->
<div class="modal fade" id="modalEq" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-mapa">
        <div>
          <h5 class="modal-title mb-0" id="modal-eq-nome"></h5>
          <small id="modal-eq-sub" style="opacity:.8"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="modal-eq-body"></div>
      <div class="modal-footer">
        <a id="modal-eq-link" href="#" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-list-ul me-1"></i>Ver em Dispositivos
        </a>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Estado global ──────────────────────────────────────────────
let dados        = null; // resposta completa da API
let modalEq      = null;
let soProblemas   = false;
let timerProxima = null;
let segsProxima  = 30;

// ── Inicialização ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  modalEq = new bootstrap.Modal(document.getElementById('modalEq'));
  carregarDados();
});

// ── Carregamento de dados via API ──────────────────────────────
function carregarDados() {
  clearInterval(timerProxima);
  fetch('monitor_mapa_api.php?bg=1')
    .then(r => r.json())
    .then(d => {
      dados = d;
      renderizar();
      iniciarContagemRegressiva();
    })
    .catch(err => {
      document.getElementById('txt-rodada').textContent = '⚠️ Erro ao carregar';
      console.error('Erro mapa API:', err);
    });
}

function iniciarContagemRegressiva() {
  segsProxima = 30;
  clearInterval(timerProxima);
  timerProxima = setInterval(() => {
    segsProxima--;
    const el = document.getElementById('txt-proxima');
    if (el) el.textContent = `· atualiza em ${segsProxima}s`;
    if (segsProxima <= 0) {
      clearInterval(timerProxima);
      carregarDados();
    }
  }, 1000);
}

// ── Renderização principal ─────────────────────────────────────
function renderizar() {
  if (!dados) return;

  const disps   = dados.dispositivos || [];
  const links   = dados.links         || [];
  const vpnFora = dados.vpn_fora      || {};
  const rodada  = dados.rodada        || {};

  // Heartbeat
  const elRodada = document.getElementById('txt-rodada');
  if (rodada.ultima) {
    const dt = new Date(rodada.ultima.replace(' ', 'T'));
    const hm = dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    elRodada.textContent = `Última rodada: ${hm} · ${rodada.qtd} equipamentos · ${rodada.ms} ms`;
  } else {
    elRodada.textContent = 'Aguardando 1ª rodada…';
  }

  // Atualizar selects de filtro
  popularFiltros(disps);

  // Agrupar: loja → grupo → equipamentos
  const porLoja = {};
  for (const d of disps) {
    const loja  = d.loja  || '—';
    const grupo = d.grupo_nome || d.grupo || '—';
    if (!porLoja[loja]) porLoja[loja] = {};
    if (!porLoja[loja][grupo]) porLoja[loja][grupo] = [];
    porLoja[loja][grupo].push(d);
  }

  // Links por loja
  const linksPorLoja = {};
  for (const l of links) {
    if (!linksPorLoja[l.loja]) linksPorLoja[l.loja] = [];
    linksPorLoja[l.loja].push(l);
  }

  // Contadores globais
  let gUp = 0, gDown = 0, gRecente = 0, gUnknown = 0;
  for (const d of disps) {
    const cls = classeStatus(d);
    if (cls === 'st-up')      gUp++;
    else if (cls === 'st-down')   gDown++;
    else if (cls === 'st-recent') gRecente++;
    else                          gUnknown++;
  }
  document.getElementById('resumo-global').innerHTML = `
    <div class="resumo-chip"><span class="dot dot-up"></span> ${gUp} no ar</div>
    <div class="resumo-chip"><span class="dot dot-down"></span> ${gDown} fora</div>
    <div class="resumo-chip"><span class="dot dot-recent"></span> ${gRecente} c/ reinício recente</div>
    <div class="resumo-chip"><span class="dot dot-unknown"></span> ${gUnknown} sem dados</div>
  `;

  // Ordenar lojas: Lj 001, Lj 003, …, Lj 030, sem loja por último
  const lojaOrdem = lojas => lojas.sort((a, b) => {
    const n = s => parseInt(s.replace(/\D/g, '')) || 9999;
    return n(a) - n(b);
  });

  const lojaFiltro = document.getElementById('f-loja').value;
  const grupoFiltro = document.getElementById('f-grupo').value;

  let html = '';
  for (const loja of lojaOrdem(Object.keys(porLoja))) {
    // Filtro de loja
    if (lojaFiltro && loja !== lojaFiltro) continue;

    const grupos = porLoja[loja];
    let lojaUp = 0, lojaDown = 0, lojaRecente = 0;

    // Contar por loja antecipado (para sumarizar)
    for (const grupo of Object.keys(grupos)) {
      for (const d of grupos[grupo]) {
        const c = classeStatus(d);
        if (c === 'st-up')       lojaUp++;
        else if (c === 'st-down')    lojaDown++;
        else if (c === 'st-recent')  lojaRecente++;
      }
    }

    // Links de internet desta loja
    const llinks = linksPorLoja[loja] || [];
    const linksHtml = llinks.map(l => {
      const cls   = l.status === 'up' ? 'link-up' : l.status === 'down' ? 'link-down' : 'link-alerta';
      const icone = l.status === 'up' ? '🟢' : l.status === 'down' ? '🔴' : '🟡';
      const pad   = l.padrao  ? ' link-padrao' : '';
      const label = l.descricao || l.nome;
      return `<span class="link-badge ${cls}${pad}">${icone} ${escHtml(label)}</span>`;
    }).join('');

    // VPN fora
    const vpnHtml = vpnFora[loja]
      ? `<span class="vpn-badge"><i class="bi bi-exclamation-triangle-fill me-1"></i>VPN fora</span>`
      : '';

    // Resumo da loja
    const partes = [];
    if (lojaDown > 0)    partes.push(`${lojaDown} 🔴`);
    if (lojaRecente > 0) partes.push(`${lojaRecente} 🟡`);
    if (lojaUp > 0)      partes.push(`${lojaUp} 🟢`);
    const resumoLojaHtml = partes.length ? `<span class="loja-resumo">${partes.join(' · ')}</span>` : '';

    // Blocos de grupos
    let gruposHtml = '';
    for (const grupo of Object.keys(grupos).sort()) {
      if (grupoFiltro && grupo !== grupoFiltro) continue;

      const equipamentos = grupos[grupo];
      const cardsHtml = equipamentos.map(d => renderCard(d)).join('');
      gruposHtml += `
        <div class="grupo-bloco">
          <div class="grupo-nome">${escHtml(grupo)}</div>
          <div class="equipamentos-grid eq-grid" data-loja="${escHtml(loja)}" data-grupo="${escHtml(grupo)}">
            ${cardsHtml}
          </div>
        </div>`;
    }

    if (!gruposHtml) continue; // filtro de grupo excluiu tudo desta loja

    html += `
      <div class="loja-section" data-loja-up="${lojaUp}" data-loja-down="${lojaDown}">
        <div class="loja-header">
          <span class="loja-titulo"><i class="bi bi-building me-1"></i>${escHtml(loja)}</span>
          ${resumoLojaHtml}
          <div class="loja-links">${linksHtml}${vpnHtml}</div>
        </div>
        ${gruposHtml}
      </div>`;
  }

  if (!html) {
    html = '<div class="vazio"><i class="bi bi-diagram-3 fs-1 mb-2 d-block"></i>Nenhum equipamento encontrado.</div>';
  }

  document.getElementById('mapa-container').innerHTML = html;
  aplicarFiltroProblemas();
}

// ── Renderiza 1 quadradinho ────────────────────────────────────
function renderCard(d) {
  const cls = classeStatus(d);
  const ip  = d.ip_efetivo || d.ip || '';
  const desde = textoDesde(d);
  return `
    <div class="eq-card ${cls}"
         data-id="${d.id}"
         onclick="abrirDetalhe(${d.id})"
         title="${escHtml(d.nome)} — ${escHtml(ip)}">
      <span class="eq-dot"></span>
      <div class="eq-nome">${escHtml(d.nome)}</div>
      <div class="eq-ip">${escHtml(ip)}</div>
      <div class="eq-desde">${desde}</div>
    </div>`;
}

// ── Classe de status para um dispositivo ──────────────────────
function classeStatus(d) {
  if (!d.monitorar) return 'st-unknown';
  if (d.status === 'up' && parseInt(d.quedas_24h) > 0) return 'st-recent';
  if (d.status === 'up')          return 'st-up';
  if (d.status === 'down')        return 'st-down';
  return 'st-unknown';
}

// ── Texto "desde HH:MM" ou "fora há Xmin" ─────────────────────
function textoDesde(d) {
  if (!d.status_desde) return '—';
  const dt      = new Date(d.status_desde.replace(' ', 'T'));
  const agora   = new Date();
  const diffMin = Math.round((agora - dt) / 60000);

  if (d.status === 'down') {
    if (diffMin < 60) return `fora há ${diffMin}min`;
    const h = Math.floor(diffMin / 60);
    const m = diffMin % 60;
    return `fora há ${h}h${m > 0 ? m + 'min' : ''}`;
  }
  return `desde ${dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
}

// ── Modal de detalhe ──────────────────────────────────────────
function abrirDetalhe(id) {
  if (!dados) return;
  const d = dados.dispositivos.find(x => x.id == id);
  if (!d) return;

  document.getElementById('modal-eq-nome').textContent = d.nome;
  document.getElementById('modal-eq-sub').textContent  = `${d.loja || '—'} · ${d.grupo_nome || d.grupo || '—'}`;

  const cls        = classeStatus(d);
  const labelSt    = { 'st-up': 'No ar', 'st-down': 'Fora', 'st-recent': 'No ar (reinício recente)', 'st-unknown': 'Desconhecido' };
  const badgeSt    = { 'st-up': 'badge-st-up', 'st-down': 'badge-st-down', 'st-recent': 'badge-st-recent', 'st-unknown': 'badge-st-unknown' };
  const ip         = d.ip_efetivo || d.ip || '—';
  const latencia   = d.latencia_ms != null ? `${parseFloat(d.latencia_ms).toFixed(1)} ms` : '—';
  const monitorar  = d.monitorar ? '<span class="text-success">Sim</span>' : '<span class="text-secondary">Não</span>';
  const origem     = d.origem || '—';
  const quedasN    = parseInt(d.quedas_24h) || 0;

  // Quedas curtas: buscar da API se precisar — por ora mostramos só o contador
  // (detalhe completo fica no monitor_dispositivos.php)
  let quedasHtml = quedasN > 0
    ? `<div class="mt-1 text-warning"><i class="bi bi-lightning-charge me-1"></i>${quedasN} queda(s) curta(s) nas últimas 24h</div>`
    : '';

  document.getElementById('modal-eq-body').innerHTML = `
    <div class="detail-row"><span class="detail-label">Status</span>
      <span class="badge ${badgeSt[cls]}">${labelSt[cls]}</span>
    </div>
    ${d.status_desde ? `<div class="detail-row"><span class="detail-label">Desde</span><span>${textoDesde(d)} (${d.status_desde.substring(0, 16)})</span></div>` : ''}
    <div class="detail-row"><span class="detail-label">IP</span><code>${escHtml(ip)}</code></div>
    <div class="detail-row"><span class="detail-label">Latência</span><span>${latencia}</span></div>
    <div class="detail-row"><span class="detail-label">Monitorar</span><span>${monitorar}</span></div>
    <div class="detail-row"><span class="detail-label">Origem</span><span>${escHtml(origem)}</span></div>
    ${quedasHtml}
  `;

  document.getElementById('modal-eq-link').href = `monitor_dispositivos.php#disp-${id}`;
  modalEq.show();
}

// ── Filtros ───────────────────────────────────────────────────
function popularFiltros(disps) {
  // Loja
  const lojas = [...new Set(disps.map(d => d.loja || '—'))].sort((a, b) => {
    const n = s => parseInt(s.replace(/\D/g,'')) || 9999;
    return n(a) - n(b);
  });
  const selLoja = document.getElementById('f-loja');
  const lojaAtual = selLoja.value;
  selLoja.innerHTML = '<option value="">Todas as lojas</option>' +
    lojas.map(l => `<option value="${escHtml(l)}" ${l === lojaAtual ? 'selected' : ''}>${escHtml(l)}</option>`).join('');

  // Grupo
  const grupos = [...new Set(disps.map(d => d.grupo_nome || d.grupo || '—'))].sort();
  const selGrupo = document.getElementById('f-grupo');
  const grupoAtual = selGrupo.value;
  selGrupo.innerHTML = '<option value="">Todos os grupos</option>' +
    grupos.map(g => `<option value="${escHtml(g)}" ${g === grupoAtual ? 'selected' : ''}>${escHtml(g)}</option>`).join('');
}

function aplicarFiltros() {
  if (dados) renderizar();
}

function toggleProblemas() {
  soProblemas = !soProblemas;
  document.getElementById('btn-problemas').classList.toggle('ativo', soProblemas);
  aplicarFiltroProblemas();
}

function aplicarFiltroProblemas() {
  if (!soProblemas) {
    document.querySelectorAll('.eq-card').forEach(c => c.style.display = '');
    document.querySelectorAll('.loja-section').forEach(s => s.style.display = '');
    document.querySelectorAll('.grupo-bloco').forEach(b => b.style.display = '');
    return;
  }
  // Mostrar só equipamentos com problema (down ou recente)
  document.querySelectorAll('.eq-card').forEach(c => {
    const ok = c.classList.contains('st-down') || c.classList.contains('st-recent');
    c.style.display = ok ? '' : 'none';
  });
  // Esconder grupos sem nenhum card visível
  document.querySelectorAll('.grupo-bloco').forEach(b => {
    const tem = [...b.querySelectorAll('.eq-card')].some(c => c.style.display !== 'none');
    b.style.display = tem ? '' : 'none';
  });
  // Esconder lojas sem nenhum grupo visível
  document.querySelectorAll('.loja-section').forEach(s => {
    const tem = [...s.querySelectorAll('.grupo-bloco')].some(b => b.style.display !== 'none');
    s.style.display = tem ? '' : 'none';
  });
}

// ── Utilitário ────────────────────────────────────────────────
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
