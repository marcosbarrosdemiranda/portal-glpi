---
date: 2026-06-08
status: concluida
author: Claude
tags:
  - agenda
  - recorrencia
  - bugfix
  - backend
  - frontend
---

# Log de Sessão: Fix Recorrência Semanal — Bugs e Geração em Massa

## Objetivo
Corrigir bugs na funcionalidade de recorrência semanal da Agenda TI: erro 500, instâncias não geradas, e data limite padrão.

## Problemas Identificados

1. **`recorrencia_id` não enviado no save** — Ao editar um evento com recorrência, o frontend não enviava `recorrencia_id` no payload. O PHP criava um NOVO registro de recorrência a cada edição, gerando órfãos e instâncias duplicadas.

2. **Data limite padrão 364 dias** — Quando nenhuma data limite era especificada, o backend usava `+364 days` em vez de `31/12/2026`, contrariando a expectativa do usuário.

3. **`gerar_semana` redundante** — O callback `finalizarSalvar` chamava `gerar_semana` via AJAX após o save, mas o PHP já gerava as instâncias dentro do próprio `save`. Resultava em dupla geração (embora o `existentesDatas` prevenisse duplicatas).

## Correções Realizadas

### `agenda/eventos.php`
- `$recorrencia_id` agora valida null vindo do JSON corretamente (evita `(int)null = 0`)
- Data limite padrão alterada para `new DateTime('2026-12-31')`

### `agenda/index.php`
- Adicionado `recorrencia_id: _dadosModal?.recorrencia_id || null` ao `dadosBase` no `salvarEvento()`
- Removido o `fetch('eventos.php?action=gerar_semana')` redundante do `finalizarSalvar`

## Arquivos modificados
- `agenda/eventos.php` — correção do `$recorrencia_id` + data limite
- `agenda/index.php` — `dadosBase.recorrencia_id` + `finalizarSalvar` simplificado

## Pendente
- [ ] Testar criação de Evento com recorrência (Seg, Qua, Sex) até 31/12/2026
- [ ] Testar edição de evento com recorrência (confirmar que não recria)
- [ ] Testar desativação de recorrência no editar
- [ ] Central AnyDesk (próximo passo)
- [ ] SSH via browser (xterm.js)
