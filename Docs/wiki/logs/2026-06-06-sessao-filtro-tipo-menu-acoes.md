# Log de Sessão — 06/06/2026 (Parte 4)

## Resumo
Implementação de duas funcionalidades na Agenda TI:
1. **Filtro por tipo de evento** — dropdown ao lado do filtro de atendente
2. **Menu ⋮ de ações nos eventos** — Excluir/Reabrir chamado com integração GLPI

---

## Funcionalidade 1: Filtro por Tipo

### O que foi feito
Dropdown "Todos os tipos" com opções: Chamado, Requisição, Reunião, Evento (com emojis).

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | HTML, CSS e JS do filtro |

### Detalhes Técnicos
- **HTML**: novo `<select id="filtro-tipo">` ao lado do `filtro-atendente` (navbar)
- **CSS**: classe `.tipo-filtro` — mirror de `.atendente-filtro`
- **JS**: 
  - Variável `filtroTipo`
  - Função `filtrarPorTipo()` — seta filtro e chama `calendar.refetchEvents()`
  - Filtro aplicado em `eventosFiltrados()` nos dois fluxos (atendente e "Todos")
- **Emojis**: 🎫 Chamado, 📋 Requisição, 👥 Reunião, 📅 Evento

---

## Funcionalidade 2: Menu ⋮ nos Eventos

### O que foi feito
Botão "⋮" no canto superior direito dos eventos (visível ao hover). Dropdown com ações:
- 🗑️ **Excluir chamado** (apenas chamados em aberto) — exclui permanentemente do GLPI
- 🔄 **Reabrir chamado** (apenas chamados fechados) — reabre como "Atribuído"

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | CSS + JS do menu + botão no eventContent |
| `agenda/excluir_ticket_glpi.php` | Novo — DELETE /Ticket/{id} no GLPI |
| `agenda/reabrir_ticket.php` | Novo — PUT status=2 no GLPI |

### Detalhes Técnicos
- **Menu** aparece apenas em eventos tipo `chamado`/`requisicao` com `ticket_id`
- **Botão ⋮**: `position: absolute; top: 1px; right: 1px`, visível apenas no hover do evento
- **Dropdown**: absolute, z-index 5002, com header "Chamado" e dois botões
- **Excluir**: verifica status=1 no GLPI antes de excluir. Após excluir, remove eventos da agenda local via `deleteByTicket`
- **Reabrir**: PUT status=2 (Atribuído), mantém técnicos atribuídos. Atualiza `concluido=0` nos eventos locais
- Desabilitado visualmente com `opacity: .4` e `cursor: not-allowed`

### Fluxo de Exclusão
1. Clique ⋮ → dropdown → "Excluir chamado"
2. Confirm dialog → fetch `excluir_ticket_glpi.php` (DELETE /Ticket)
3. Se OK → `eventos.php?action=deleteByTicket` + refetch

### Fluxo de Reabertura
1. Clique ⋮ → dropdown → "Reabrir chamado"
2. Confirm dialog → fetch `reabrir_ticket.php` (PUT status=2)
3. Se OK → atualiza `concluido=0` no banco local + atualiza cor no calendário

---

## ADR
`Docs/wiki/decisions/ADR-003-filtro-tipo-menu-tres-pontos.md`

## Commits
- Pendente (incluir no próximo push)
