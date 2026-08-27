# Log de Sessão — 16/06/2026

## Resumo
Correção definitiva do filtro "Em atendimento" no sidebar da agenda — o problema era cache do navegador servindo HTML+JS antigo, não o código em si.

---

## Problema

### Relatado
Após todas as correções de código (valores do dropdown, lógica de filtro com `status_n`, ordenação), o filtro "Em atendimento" ainda mostrava chamados "Em espera" (Pendente) misturados.

### Causa
O `index.php` tem todo o JavaScript inline no HTML. O navegador estava servindo a página **do cache** — código antigo que não tinha a lógica `t.status_n === 2 || t.status_n === 3`.

**Evidência:** Os arquivos no servidor foram confirmados idênticos aos locais via md5sum. O código correto estava lá, mas não era executado.

### Correções
1. **Meta tags anti-cache no `<head>`**:
```html
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate"/>
<meta http-equiv="Pragma" content="no-cache"/>
<meta http-equiv="Expires" content="0"/>
```

2. **Cache-buster no fetch do tickets.php**:
```js
fetch('tickets.php?_=' + Date.now())
```
Isso garante que cada requisição XHR seja única, sem cache.

---

## Arquivos alterados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | +3 meta tags anti-cache no `<head>` |
| `agenda/index.php` | `fetch('tickets.php')` → `fetch('tickets.php?_=' + Date.now())` (2 ocorrências) |

## Commits
- `85d597a` — fix: anti-cache meta tags + cache-buster no fetch tickets.php

## Status
- [x] Meta tags anti-cache adicionadas
- [x] Cache-buster no fetch
- [x] Arquivos copiados para o servidor
- [ ] Pendente: usuário testar com Ctrl+Shift+R ou nova janela anônima
