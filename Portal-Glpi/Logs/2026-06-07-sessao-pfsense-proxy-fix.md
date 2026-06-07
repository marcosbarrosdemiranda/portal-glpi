---
date: 2026-06-07
status: concluida
author: Claude
tags:
  - pfsense
  - proxy
  - infraestrutura
---

# Log de Sessão: Fix Proxy pfSense — Auto-login + Topbar + CSS Rewrite

## Problema

O proxy pfSense (`pfsense_proxy.php`) não funcionava. Três queixas do usuário:
1. ❌ Abria nova janela em vez da mesma página
2. ❌ Cert warning + site não seguro
3. ❌ Precisava digitar usuário e senha manualmente

## Causas Raiz

Debug revelou dois problemas técnicos:

### 1. Frame-busting JS
```javascript
if (top != self) {top.location.href = self.location.href;}
```
pfSense injeta isso na página de login, quebrando o iframe e redirecionando o navegador para o pfSense diretamente.

### 2. CSRF no JS, não no HTML (pfSense 2.7+)
A versão do pfSense dessa loja define o CSRF token numa variável JavaScript:
```javascript
var csrfMagicToken = "sid:...,1780844117;ip:...,1780844117";
```
Em vez de um `<input>` escondido. O regex antigo só procurava no HTML.

## Solução: Same-Tab Proxy (sem iframe)

Estratégia: o navegador inteiro navega para `pfsense_proxy.php?loja=1&path=/`, eliminando o iframe completamente.

### Arquivo modificado: `pfsense_proxy.php`

**Login (2-step cURL):**
- GET `/` → extrai CSRF de `csrfMagicToken` (JS) ou `<input>` (fallback)
- POST `/index.php` com `usernamefld`, `passwordfld`, `__csrf_magic`
- Verifica saída: se NÃO contém `usernamefld`, login OK
- Mantém cookie jar em `sys_get_temp_dir()`
- Fallback HTTP se HTTPS falhar

**Proxy de páginas:**
- Busca página via cURL com cookie jar
- **HTML:** rewrite de URLs absolutas (`/path` → `pfsense_proxy.php?loja=1&path=/path`) e relativas (sem `http/https/#/pfsense_proxy.php`)
- **CSS:** callback com resolução de `url('../fonts/foo.woff2')` contra o diretório do CSS
- Remove frame-busting JS (`if (top != self)`)
- Injeta `var events=[]` no head (pfSense espera variável global)
- Injeta topbar no body (Voltar, nome loja, IP, Recarregar, Externo)

**Front-end:**
- `abrirPlayer()` agora faz `window.location.href` em vez de iframe
- Removeu player view (iframe + sandbox)
- Mantém list view com CRUD admin

### Modo debug
- `?debug=1` — mostra resposta HTTS/HTTP do pfSense
- `?debuglogin=1` — mostra processo completo: GET → CSRF → POST → verificação

## Resultado
- ✅ Mesma aba (sem iframe)
- ✅ Sem cert warning (browser só fala com nosso servidor)
- ✅ Auto-login via cURL
- ✅ pfSense funcional: navegação, ícones, CSS, fontes
- ✅ Topbar visível com controles
- ✅ Snort e outros widgets funcionando
