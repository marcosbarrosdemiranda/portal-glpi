# Log de Sessão — 22/06/2026

## Resumo
Implementação do botão 🗑️ excluir chamado no histórico de chamados (historico.php), com toast de fallback próprio e sincronização para o servidor.

---

## 1. Botão 🗑️ no histórico de chamados

**Problema:** Não havia como excluir chamados diretamente da tabela de histórico — precisava abrir o chamado e usar o botão interno.

**Solução:** Adicionada coluna com ícone 🗑️ `(bi bi-trash)` em cada linha da tabela de histórico. O botão:
- Confirma com `confirm()` antes de excluir
- Chama `agenda/excluir_ticket_glpi.php` via `fetch POST` com `{ticket_id}`
- Mostra toast de sucesso/erro
- Remove a linha da tabela sem recarregar página (em caso de sucesso)

**Arquivo alterado:** `historico.php`

## 2. Toast de fallback independente

**Problema:** `toast()` do `notificacoes.js` causava `ReferenceError: toast is not defined` porque o script nem sempre carregava.

**Solução:** Criada função `toastFallback()` inline que:
- Cria um elemento `div#toast-excluir` flutuante no canto inferior direito
- Exibe a mensagem por 3 segundos e some com fade
- Não depende de nenhum script externo
- A função `toast()` global chama `toastFallback()` diretamente

**Arquivo alterado:** `historico.php` (linhas 514-521)

## 3. Substituição de alert() por toast()

Todos os `alert()` no fluxo de exclusão foram substituídos por `toast()`:
- Sucesso: "Chamado #N enviado para a lixeira do GLPI."
- Erro do servidor: mensagem retornada pela API
- Erro de conexão: "Erro de conexão. Veja o console (F12)."
- JSON inválido: "Erro no servidor. Veja o console (F12)."

## 4. Sincronização com servidor

- `historico.php` copiado para `\\192.168.1.198\xampp\htdocs\glpi2\portal-glpi\historico.php`

## Commits
- Pendente: branch `feat/cofre-seguranca-inventario-2026-06-11`

## Status
- [x] Botão 🗑️ em cada linha do histórico
- [x] Confirmação antes de excluir
- [x] Chamada à API de exclusão (lixeira GLPI)
- [x] Toast de notificação independente (sem dependência externa)
- [x] Remoção da linha sem reload
- [x] Sincronizado para o servidor
