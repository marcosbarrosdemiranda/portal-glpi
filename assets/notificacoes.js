/**
 * Sistema de notificações de novos chamados
 * Inclua este script em todas as páginas dos técnicos
 */
(function() {
  'use strict';

  const INTERVALO    = 30000; // 30 segundos
  const BASE_URL     = document.querySelector('meta[name="base-url"]')?.content || '';
  let ultimoCheck    = new Date().toISOString().slice(0, 19);
  let badgeContador  = 0;
  let audioCtx       = null;

  // ── Cria container de notificações ──────────────────────────
  const container = document.createElement('div');
  container.id    = 'notif-container';
  container.style.cssText = `
    position: fixed;
    top: 70px;
    right: 16px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 340px;
  `;
  document.body.appendChild(container);

  // ── Cria badge no topbar ─────────────────────────────────────
  const badge = document.createElement('div');
  badge.id    = 'notif-badge';
  badge.style.cssText = `
    position: fixed;
    top: 12px;
    right: 16px;
    background: #e8001c;
    color: white;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    font-size: 11px;
    font-weight: 700;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    border: 2px solid white;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
    cursor: pointer;
    animation: pulse-badge 1.5s infinite;
  `;
  badge.onclick = () => { badgeContador = 0; badge.style.display = 'none'; };
  document.body.appendChild(badge);

  // ── CSS animações ────────────────────────────────────────────
  const style = document.createElement('style');
  style.textContent = `
    @keyframes pulse-badge {
      0%,100% { transform:scale(1); }
      50%      { transform:scale(1.2); }
    }
    @keyframes slide-in {
      from { transform:translateX(120%); opacity:0; }
      to   { transform:translateX(0);   opacity:1; }
    }
    @keyframes slide-out {
      from { transform:translateX(0);   opacity:1; }
      to   { transform:translateX(120%); opacity:0; }
    }
    .notif-card {
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,.2);
      border-left: 5px solid #e8001c;
      padding: 12px 14px;
      animation: slide-in .35s ease;
      cursor: pointer;
      position: relative;
    }
    .notif-card:hover { background: #fff8f8; }
    .notif-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 4px;
    }
    .notif-icon {
      width: 32px; height: 32px;
      background: #fee2e2;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: #e8001c; font-size: 1rem;
      flex-shrink: 0;
    }
    .notif-titulo {
      font-weight: 700;
      font-size: .82rem;
      color: #111;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .notif-sub {
      font-size: .73rem;
      color: #6b7280;
      margin-top: 2px;
    }
    .notif-close {
      position: absolute;
      top: 6px; right: 8px;
      color: #aaa;
      cursor: pointer;
      font-size: .9rem;
      line-height: 1;
    }
    .notif-close:hover { color: #e8001c; }
    .notif-badge-label {
      background: #fee2e2;
      color: #e8001c;
      border-radius: 20px;
      padding: 1px 7px;
      font-size: .68rem;
      font-weight: 700;
    }
  `;
  document.head.appendChild(style);

  // ── Som de notificação (Web Audio API) ───────────────────────
  function tocarSom() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      // Retoma se estiver suspenso (autoplay policy do navegador)
      if (audioCtx.state === 'suspended') audioCtx.resume();

      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      osc.type      = 'sine';
      gain.gain.setValueAtTime(0, audioCtx.currentTime);
      gain.gain.linearRampToValueAtTime(0.4, audioCtx.currentTime + 0.05);
      gain.gain.linearRampToValueAtTime(0,   audioCtx.currentTime + 0.4);

      osc.frequency.setValueAtTime(880, audioCtx.currentTime);
      osc.frequency.setValueAtTime(660, audioCtx.currentTime + 0.15);

      osc.start(audioCtx.currentTime);
      osc.stop(audioCtx.currentTime + 0.4);

      // Segundo bip
      setTimeout(() => {
        try {
          const o2 = audioCtx.createOscillator();
          const g2 = audioCtx.createGain();
          o2.connect(g2); g2.connect(audioCtx.destination);
          o2.type = 'sine';
          g2.gain.setValueAtTime(0, audioCtx.currentTime);
          g2.gain.linearRampToValueAtTime(0.35, audioCtx.currentTime + 0.05);
          g2.gain.linearRampToValueAtTime(0,    audioCtx.currentTime + 0.35);
          o2.frequency.setValueAtTime(1100, audioCtx.currentTime);
          o2.start(audioCtx.currentTime);
          o2.stop(audioCtx.currentTime + 0.35);
        } catch(e2) { /* bip ignorado */ }
      }, 250);

    } catch(e) { /* sem suporte a audio */ }
  }

  // ── Mostra notificação ───────────────────────────────────────
  function mostrarNotificacao(ticket) {
    const card = document.createElement('div');
    card.className = 'notif-card';
    card.innerHTML = `
      <span class="notif-close" onclick="this.parentElement.remove()">✕</span>
      <div class="notif-header">
        <div class="notif-icon">🎫</div>
        <div style="min-width:0;flex:1">
          <div class="notif-titulo">#${ticket.id} — ${ticket.titulo}</div>
          <div class="notif-sub">
            <span class="notif-badge-label">${ticket.tipo}</span>
            ${ticket.entidade ? ` · ${ticket.entidade}` : ''}
          </div>
        </div>
      </div>
      <div style="font-size:.72rem;color:#9ca3af;margin-top:2px">
        🕐 ${new Date(ticket.data).toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'})}
        · Clique para abrir
      </div>
    `;

    card.addEventListener('click', (e) => {
      if (e.target.classList.contains('notif-close')) return;
      window.location.href = (BASE_URL || '') + 'chamado.php?id=' + ticket.id;
    });

    container.prepend(card);

    // Remove automaticamente após 8 segundos
    setTimeout(() => {
      card.style.animation = 'slide-out .3s ease forwards';
      setTimeout(() => card.remove(), 300);
    }, 8000);

    // Badge contador
    badgeContador++;
    badge.textContent     = badgeContador > 9 ? '9+' : badgeContador;
    badge.style.display   = 'flex';
  }

  // ── Polling ──────────────────────────────────────────────────
  function verificar() {
    const params = new URLSearchParams({ ultimo: ultimoCheck });
    fetch(`${BASE_URL}notificacoes.php?${params}`)
      .then(r => r.ok ? r.json() : [])
      .then(novos => {
        ultimoCheck = new Date().toISOString().slice(0, 19);
        if (!Array.isArray(novos) || novos.length === 0) return;

        // Toca som uma vez para todos os novos
        tocarSom();

        // Mostra notificação para cada chamado novo
        novos.forEach((t, i) => {
          setTimeout(() => mostrarNotificacao(t), i * 300);
        });
      })
      .catch(() => {});
  }

  // Inicia após 5 segundos (aguarda página carregar) e depois a cada 30s
  setTimeout(() => {
    verificar();
    setInterval(verificar, INTERVALO);
  }, 5000);

  // Inicializa AudioContext no primeiro clique do usuário (requerido pelos browsers)
  document.addEventListener('click', () => {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
  }, { once: true });

})();
