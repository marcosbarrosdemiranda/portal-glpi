# Log de Sessão — 15/06/2026

## Resumo
Correção do link "Abrir chamado" na agenda que estava quebrado (404) — caminho relativo apontava para `agenda/chamado.php` em vez de `chamado.php` na raiz.

---

## Problema

### Relatado
Ao clicar no badge "Abrir chamado" no tooltip de preview de um chamado na agenda, o navegador abria:
```
http://192.168.1.198/glpi2/portal-glpi/agenda/chamado.php?id=9219
→ 404 Not Found
```

### Causa
O template literal em `agenda/index.php:1788` usava caminho relativo:
```js
window.open('chamado.php?id=${id}','_blank')
```
Como o `index.php` está dentro da pasta `agenda/`, o caminho resolvia para `agenda/chamado.php` — arquivo que nunca existiu.

### Correção
```js
window.open('../chamado.php?id=${id}','_blank')
```
Agora resolve para `chamado.php` na raiz, onde o arquivo já existe completo.

### Verificação de outros links
| Arquivo | Link | Resolve | Status |
|---------|------|---------|--------|
| `historico.php:419` | `chamado.php?id=X` | Raiz ✅ | OK |
| `meus_chamados.php:348` | `chamado.php?id=X` | Raiz ✅ | OK |
| `equipe.php:995` | `chamado.php?id=X` | Raiz ✅ | OK |
| `notificacoes.js:197` | `BASE_URL + 'chamado.php?id=X'` | Raiz ou `../` ✅ | OK |
| `agenda/index.php:1788` | ~~`chamado.php?id=X`~~ | `agenda/chamado.php` ❌ | **Corrigido** |

---

## Arquivos alterados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | Linha 1788: `'chamado.php'` → `'../chamado.php'` |

## Commits
- `91acae4` — fix: link 'Abrir chamado' na agenda agora aponta para ../chamado.php (raiz)

---

## Ações no servidor (192.168.1.198)
- [x] Verificado: link já corrigido (`../chamado.php`) — servidor já estava atualizado
- [x] Limpeza de arquivos duplicados colados acidentalmente em `C:\xampp\`:
  - Removidos: `agenda/`, `assets/`, `Docs/`, `Portal-Glpi/`, `Documentação e Backup do Projeto Data 01-06-26/`
  - Removidos: todos os `.php`, `.js`, `.md`, `.json`, `.ps1`, `.bak` do portal-glpi
  - Mantidos: todos os arquivos originais do XAMPP
  - Mantidos: `portal/`, `hotspot/` (projetos separados)
  - Mantido: `projetos-ti/` (pasta de sync de projetos)
- [ ] O usuário precisa testar se o link "Abrir chamado" no preview da sidebar agora funciona corretamente
