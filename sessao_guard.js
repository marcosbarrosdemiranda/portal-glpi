/**
 * sessao_guard.js — detecta sessão expirada em QUALQUER fetch() da página
 * atual e manda pro login automaticamente, em vez de cada endpoint falhar
 * calado ou exigir F5 manual do usuário.
 *
 * auth_guard.php sinaliza sessão morta de 2 jeitos:
 *   1. Requisição normal (sem ?bg=1): 302 → auth.php. O fetch() segue esse
 *      redirect sozinho e entrega o HTML da tela de login pro código que
 *      esperava JSON — quebra com "Unexpected token '<' ... is not valid
 *      JSON" sem avisar ninguém (só aparece no console). O usuário via
 *      "Salvar" não fazer nada e clicava várias vezes seguidas.
 *   2. Polling de fundo (?bg=1): HTTP 440 + {timeout:true}.
 *
 * Incluir com <script src="sessao_guard.js"></script> (ajustar caminho
 * conforme a profundidade da pasta) em toda página autenticada, antes de
 * qualquer fetch() próprio da página rodar.
 */
(function () {
    if (window.__sessaoGuardAtivo) return; // não duplica se a página incluir 2x
    window.__sessaoGuardAtivo = true;

    // Resolve o caminho de auth.php a partir da própria URL (funciona em
    // qualquer profundidade — raiz, agenda/, portal/, wpp/ — sem depender
    // do Location relativo que o auth_guard.php manda, que só resolve
    // certo pra páginas na raiz do portal).
    function urlAuth() {
        const m = location.pathname.match(/^(.*\/portal-glpi)(\/|$)/);
        const base = m ? m[1] : '';
        return base + '/auth.php?timeout=1';
    }

    let jaAvisou = false;
    function sessaoExpirou() {
        if (jaAvisou) return;
        jaAvisou = true;
        alert('Sua sessão expirou. A página vai recarregar para você entrar de novo — confira se a última ação foi salva.');
        location.href = urlAuth();
    }

    const _fetchOriginal = window.fetch;
    window.fetch = async function (...args) {
        const r = await _fetchOriginal(...args);
        if (r.status === 440 || (r.redirected && /\/auth\.php(\?|$)/.test(r.url))) {
            sessaoExpirou();
            throw new Error('Sessão expirada');
        }
        return r;
    };
})();
