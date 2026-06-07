# ADR-003: Filtro por Tipo + Menu de Ações (⋮) na Agenda

## Status
Aprovado — 06/06/2026

## Contexto
A agenda de atendimentos precisa de duas funcionalidades:
1. Filtrar eventos por tipo (Chamado, Requisição, Reunião, Evento) para melhor visualização
2. Menu de ações rápidas nos eventos da grade sem precisar abrir o modal de edição

## Decisão

### Filtro por tipo
- Dropdown fixo ao lado do filtro de atendente na navbar da agenda
- Opções: Todos, Chamado, Requisição, Reunião, Evento (com emojis)
- Filtra client-side via `eventosFiltrados()` — mesmo padrão do filtro de atendente
- Não requer chamada ao servidor — apenas re-renderiza o calendário

### Menu de ações (⋮)
- Botão "⋮" (vertical three-dots) no canto superior direito de cada evento
- Visível apenas em eventos tipo `chamado` e `requisicao`
- Dropdown com submenu "Chamados":
  - **Excluir chamado**: visible se não concluído. DELETE permanente no GLPI.
  - **Reabrir chamado**: visible se concluído. PUT status=2 (Atribuído) no GLPI.

### Integração GLPI
- `excluir_ticket_glpi.php`: DELETE /api/Ticket/{id} — permanent do GLPI
- `reabrir_ticket.php`: PUT /api/Ticket/{id} — status=2 (reabrir)
- Ambos seguem o padrão existente (curl, auth, killSession)

## Consequências
- Eventos sem `ticket_id` (tipo "evento" ou "reuniao") não mostram o menu
- Exclusão é irreversível no GLPI — confirm dialog antes de executar
- Reabertura mantém o técnico atribuído (não reseta para Novo)
