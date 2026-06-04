# Log de Sessão — 2026-06-03

## Contexto
Sessão de correção de múltiplos bugs: modal "Responder Chamado" (upload/preview de imagens),
visibilidade de Eventos por criador, e preview de anexos no histórico de chamados.

---

## Bugs Corrigidos

| # | Descrição | Arquivo(s) | Status |
|---|-----------|-----------|--------|
| 1 | Preview de imagens não aparecia no modal de resposta | `agenda/index.php` | ✅ CONGELADO |
| 2 | Colar imagem (Ctrl+V) duplicava o anexo | `agenda/index.php` | ✅ CONGELADO |
| 3 | Clicar na miniatura (lupa/zoom) não abria o lightbox | `agenda/index.php` | ✅ CONGELADO |
| 4 | Evento criado sem atendente aparecia em "Todos" em vez do criador | `agenda/index.php` | ✅ CONGELADO |
| 5 | Preview de anexos no histórico: imagem aparecia vazia e sumia após 2s | `glpi_doc_proxy.php`, `agenda/config.php`, `debug_proxy.php` | ✅ CONGELADO |
| 6 | Campos obrigatórios sem validação — entidade-raiz podia ser selecionada | `agenda/index.php` | ✅ CONGELADO |
| 7 | Gabarito visual de entidades (aliases no select) | `agenda/index.php` | ✅ CONGELADO |

---

## Causa Raiz e Correções

### Bug 1 e 3 — `chip.innerHTML +=` destruía o nó `<img>`
**Causa:** `renderizarArquivos()` fazia `chip.appendChild(thumb)` (sícrono) e depois
`chip.innerHTML += ...` (antes do FileReader terminar). O `innerHTML +=` serializa todo o
DOM atual e reescreve do zero — o nó `thumb` original era destruído junto com seu `src`
(ainda vazio) e seu `onclick` (que seria definido no `onload`).

**Correção:** O bloco `chip.innerHTML +=` foi substituído por criação DOM pura
(`createElement + appendChild`) para o `chip-footer`. O nó `thumb` nunca mais é tocado
após ser inserido.

### Bug 2 — Paste duplicando imagem
**Causa:** Dois listeners de `paste` simultâneos:
- `modalResposta.addEventListener('paste', ...)` — ouve o modal inteiro
- `resp-texto.addEventListener('paste', ...)` — ouve o textarea

Ao colar no textarea, o evento subia por bubbling até o modal, chamando
`colarImagemClipboard` duas vezes.

**Correção:** Adicionado `e.stopPropagation()` no listener do `resp-texto` quando a
imagem for capturada, impedindo o bubbling.

### Bug 4 — Evento criado sem atendente aparecia em "Todos" e não para o criador
**Causa:** `ajustarCamposPorTipo()` oculta o campo de atendentes quando `tipo === 'evento'`
(linha ~1498). Com o campo oculto, nenhum atendente era selecionado e o evento era salvo com
`atendente = null`. A regra protegida de visibilidade (linhas 1000-1007) exclui eventos sem
atendente de qualquer filtro individual — só aparecem em "Todos os Atendentes".

**Correção:** Em `salvarEvento()`, logo após construir `dadosBase`, bloco adicionado para
`tipo === 'evento'`: se `atendente` está vazio, busca o usuário logado no array `atendentes`
(por `id` ou por `nome`) e preenche `atendente/atendente_id/atendente_cor` automaticamente.
O campo continua oculto no modal — o criador é atribuído sem precisar interagir.

**Arquivo:** `agenda/index.php` — função `salvarEvento()`, após linha que monta `dadosBase`.

### Bug 5 — Preview de anexos no histórico de chamados: imagem aparece vazia e some após 2s
**Sintoma:** Ao abrir um chamado com anexos de imagem, o `<img class="anexo-thumb">` aparecia
como um quadradinho vazio com bordas (80×60px via CSS), e após ~2 segundos desaparecia.

**Causa raiz:** `glpi_doc_proxy.php` tentava servir o arquivo via sessão web cURL
(`/front/document.send.php`). O login cURL para `localhost` falha silenciosamente por
bug conhecido de cookie domain matching em ambientes Windows — o cURL não envia o cookie
de sessão de volta para `localhost`, fazendo `document.send.php` retornar HTML (página de
login). O proxy detecta o HTML, retorna 404 → `img.onerror` → `img.remove()`.

**Correção em `glpi_doc_proxy.php` — dois métodos em cascata:**

- **Método 1 (primário — filesystem):** após obter o `filepath` via REST API, o proxy
  lê o arquivo direto do disco usando `readfile()`. Prioriza a constante `GLPI_ABSPATH`
  definida em `agenda/config.php`; como fallback tenta auto-detecção via
  `$_SERVER['DOCUMENT_ROOT']` + path da URL. Sem cookies, sem login web, sem latência extra.
- **Método 2 (fallback — sessão web, melhorado):** mantido para GLPI remoto;
  adicionado `CURLOPT_USERAGENT` em todas as chamadas curl; regex de CSRF expandida
  para suportar `value=` antes de `name=`.

**Configuração aplicada em `agenda/config.php`:**
```php
define('GLPI_ABSPATH', 'C:/xampp/htdocs/glpi2');
```
XAMPP confirmado pelo responsável. Método 1 ativo e funcionando.

**`debug_proxy.php` atualizado:** testa e reporta ambos os métodos com ✅/❌,
mostrando o caminho completo, `file_exists` e `is_readable` para diagnóstico futuro.

---

## Arquivos Modificados (sessão completa)

| Arquivo | Alteração | Status |
|---------|-----------|--------|
| `agenda/index.php` | `renderizarArquivos()` — DOM puro no lugar de innerHTML+= | ✅ CONGELADO |
| `agenda/index.php` | listener `paste` em `resp-texto` — adicionado stopPropagation | ✅ CONGELADO |
| `agenda/index.php` | `salvarEvento()` — auto-atribui criador como atendente de Eventos | ✅ CONGELADO |
| `glpi_doc_proxy.php` | Método 1 filesystem + Método 2 sessão web melhorado | ✅ CONGELADO |
| `agenda/config.php` | Adicionado `GLPI_ABSPATH = 'C:/xampp/htdocs/glpi2'` | ✅ CONGELADO |
| `debug_proxy.php` | Diagnóstico expandido para ambos os métodos | ✅ CONGELADO |
| `agenda/index.php` | Validação obrigatória: Título, Descrição, Entidade, Atendente, Requerente | ✅ CONGELADO |
| `agenda/index.php` | Gabarito `ALIAS_ENTIDADES` + função `apelidoEntidade()` | ✅ CONGELADO |

---

## Bug 6 — Campos obrigatórios sem validação / entidade-raiz selecionável

**Campos obrigatórios implementados para Chamado e Requisição:**
Título, Descrição, Entidade (filha — raiz já bloqueada em `entidades.php`), Atendente (≥1 chip),
Requerente. Validação visual com `is-invalid` (borda vermelha) + banner de erro no topo do modal.
Helpers: `limparValidacao()`, `marcarInvalido(id)`, `mostrarErroModal(erros[])`.
Asterisco `*` nos labels; o `*` de Descrição aparece só para Chamado/Requisição.

## Bug 7 — Gabarito visual de entidades

**Mapeamento `ALIAS_ENTIDADES`** em `agenda/index.php` (bloco de constantes):
- `value` do `<option>` mantém o nome completo (enviado ao GLPI)
- Texto exibido usa o alias via `apelidoEntidade(nome)`

| Nome GLPI (completename) | Alias exibido |
|--------------------------|---------------|
| Entidade raiz > Grupo Gmais | Grupo Gmais |
| Entidade raiz > Grupo Gmais > Gmais ADM | Lj 101 |
| Entidade raiz > Grupo Gmais > Rincão Atacadista - BTO | Lj 030 |
| Entidade raiz > Grupo Gmais > Supermercado Express - BTO | Lj 010 |
| Entidade raiz > Grupo Gmais > Supermercado Santos - BTO | Lj 001 |
| Entidade raiz > Grupo Gmais > Supermercado Santos - JDM | Lj 003 |

Para adicionar ou alterar: editar `ALIAS_ENTIDADES` em `agenda/index.php`.

---

## Regra de Colaboração Estabelecida (2026-06-03)
- Tudo que o responsável confirmar como "ok" / "ficou pronto" é **CONGELADO** imediatamente.
- Documentar no log e **não alterar mais** sem necessidade explícita.
- Sempre **avisar o responsável** antes de qualquer alteração em código congelado.
