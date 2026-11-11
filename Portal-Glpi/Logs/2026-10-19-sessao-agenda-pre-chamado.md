---
date: 2026-10-19
status: concluida
author: AI Agent
tags:
  - agenda
  - log-sessao
---

# Log de Sessão: Pré-chamado na Agenda (rotinas GLPI)

## Contexto e Objetivo

Criar um novo tipo de evento "Pré-chamado" — uma reserva na grade da Agenda
pra uma ocorrência futura de uma rotina do GLPI, antes de existir um chamado
de verdade. Confirmado com o usuário: a fonte é `glpi_ticketrecurrents`
(motor nativo do GLPI, 32 definições ativas em produção), não a recorrência
própria da Agenda (`agenda_recorrencias`). Ver [[../Decisões/ADR-006-agenda-pre-chamado-ticketrecurrents|ADR-006]].

## Implementações Realizadas (SoC)

- **Apresentação / UI**: opção "🕓 Pré-chamado" no seletor de tipo do modal,
  cor cinza claro (`#bdbdbd`/`#9e9e9e`) no calendário, campos de
  entidade/requerente/categoria/origem liberados pra pré-preencher antes de
  converter, botão "Converter em chamado" visível só pra pré-chamados
  manuais (sem `ticketrecurrents_id`).
- **Domínio / Regras de Negócio**: `agenda/pre_chamado_lib.php` calcula
  ocorrências futuras a partir de `begin_date` + `periodicity` (segundos ou
  `"NMONTH"`); `sync_rotinas_ajax.php` gera os placeholders dos próximos 30
  dias e converte (não duplica) quando o chamado real aparece.
- **Infraestrutura / Banco de Dados**: coluna `ticketrecurrents_id` em
  `glpi_plugin_agenda_events` (migração idempotente em `eventos.php`).

## Validação em produção

- Migração da coluna confirmada via SQL direto.
- 334 placeholders dos próximos 30 dias gerados, zero duplicata.
- **Bug achado e corrigido durante a validação**: match por igualdade exata
  de nome (`t.name = tr.name`) falhava pra 2 das 32 definições (nome do
  chamado real com sufixo extra ou espaço sobrando em relação ao nome da
  definição) — gerava duplicata a cada rodada de sync. Corrigido com match
  por prefixo (`LIKE CONCAT(TRIM(tr.name), '%')`). Backfill retroativo em
  chamados antigos já mirrorados (1080 + 128 linhas).
- Conversão de placeholder→chamado simulada (ticket fake, depois desfeito) —
  confirmado que converte a linha em vez de duplicar.
- 3 rodadas de sync consecutivas depois da correção: idempotentes
  (`placeholders_criados=0` nas repetições).
- Scripts de diagnóstico temporários, deletados local e remotamente após uso.

## Arquivos Modificados

- `agenda/pre_chamado_lib.php` (novo)
- `agenda/sync_rotinas_ajax.php`
- `agenda/eventos.php`
- `agenda/index.php`

## Decisões Técnicas (ADRs)

- [[../Decisões/ADR-006-agenda-pre-chamado-ticketrecurrents|ADR-006]] — Opção A
  (`glpi_ticketrecurrents`) em vez da recorrência própria da Agenda; match por
  prefixo de nome em vez de igualdade exata.

## Pendências

- PR #7 aberto (base `feat/orcamento-projeto`, mesmo padrão de empilhamento do
  PR #6), sem merge — falta validação visual do usuário no navegador.
