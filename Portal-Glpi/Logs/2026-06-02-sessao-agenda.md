# Log de Sessão — 2026-06-02

## Contexto
Sessão de correção e estabilização da Agenda TI (`agenda/index.php` e PHPs de suporte).

---

## Bugs Corrigidos

| # | Descrição | Arquivo(s) |
|---|-----------|-----------|
| 1 | Chamado arrastado do sidebar virava "evento" (tipo errado) | `index.php` |
| 2 | Race condition: `syncRotinas` corria em paralelo com `verificarAtrasados` | `index.php` |
| 3 | Eventos com `tipo='evento'` salvo incorretamente no DB para chamados | `eventos.php` |
| 4 | Chamados concluídos sumiam da grade após `refetchEvents` | `index.php` |
| 5 | Entidade e Requerente não preenchiam no modal (ID vs. nome) | `index.php` |
| 6 | `snap is not defined` ao responder chamado | `index.php` |
| 7 | Eventos concluídos apareciam em agendas de outros atendentes | `index.php` |
| 8 | Eventos concluídos podiam ser movidos/redimensionados | `index.php` |
| 9 | Qualquer evento podia ser movido para dias passados | `index.php` |
| 10 | `responder_ticket.php` não mostrava detalhe de erro do GLPI | `responder_ticket.php` |

---

## Regras de Negócio Aprovadas e Travadas

Ver ADR-001: `Docs/wiki/decisions/ADR-001-regras-protegidas-agenda.md`

**Resumo das regras IMUTÁVEIS (aprovadas pelo responsável):**
- ❌ Criar evento em data passada → bloqueado
- ❌ Arrastar do sidebar para data passada → bloqueado
- ❌ Mover evento existente para data passada → bloqueado
- ❌ Mover ou redimensionar evento concluído → bloqueado
- ✅ Visibilidade isolada por atendente (concluídos só na agenda de quem atendeu)

---

## Estado Final dos Arquivos Modificados

- `agenda/index.php` — principal (lógica de calendário, drag & drop, modais)
- `agenda/eventos.php` — correção de tipo + `concluir_ticket` com atendente
- `agenda/responder_ticket.php` — mensagem de erro detalhada
- `Docs/wiki/decisions/ADR-001-regras-protegidas-agenda.md` — criado
- `Docs/wiki/logs/2026-06-02-sessao-agenda.md` — este arquivo

---

## Sessão Complementar — 2026-06-02 (tarde)

### Problema Reportado
Chamados recorrentes (rotinas pré-determinadas) estavam sendo atribuídos ao usuário logado no momento da sync, em vez do técnico configurado no GLPI.

### Correções Aplicadas

| Arquivo | Mudança |
|---------|---------|
| `sync_rotinas_ajax.php` | Removido fallback que atribuía ao usuário logado quando ticket sem técnico no GLPI |
| `sync_rotinas_ajax.php` | Loop de correção expandido: antes cobria só eventos **sem** atendente; agora cobre **todos** os chamados ativos de hoje e sincroniza com o técnico do GLPI se houver divergência |
| `index.php` | Removido auto-assign ao usuário logado no modal de resposta para eventos sem atendente |

### Regra Nova
Rotinas pré-determinadas sempre usam o atendente configurado no GLPI para o respectivo ticket. Se o GLPI não tiver técnico, o evento fica sem atendente — nunca é atribuído ao logado.

### Pendente
Validar amanhã (2026-06-03) se os chamados recorrentes estão aparecendo corretamente na agenda de cada atendente ao carregar a página.
