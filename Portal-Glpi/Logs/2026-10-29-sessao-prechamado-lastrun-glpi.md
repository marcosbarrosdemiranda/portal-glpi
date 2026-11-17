---
date: 2026-10-29
status: monitorando
author: AI Agent
tags:
  - agenda
  - infra
  - log-sessao
---

# Log de Sessão: Pré-chamados "nunca convertem" todo dia — causa real era horário de criação do GLPI, não o pipeline

## Contexto e Objetivo

Usuário reportou que pré-chamados das rotinas recorrentes do GLPI continuam
não virando chamados reais, e que é "todo dia a mesma coisa" há mais de uma
semana, apesar dos fixes anteriores (ver
[[2026-10-27-sessao-prechamado-cron-06h30]] e causas #2/#3 registradas na
memória `fix_prechamado-sem-cron-06h30`, sem log próprio no vault).

## Diagnóstico

- As 3 causas corrigidas em 27/28-10 (Tarefa Agendada do `sync_rotinas.php`,
  cron nativo do GLPI via `\Portal\Glpi-Cron`, matching por
  `tickettemplatepredefinedfields.num=1`) continuam ativas e funcionando —
  auditoria completa dos 79 chamados de rotina de hoje não achou nenhum sem
  técnico nem sem match de nome.
- Nenhum pré-chamado de dia anterior (26, 27, 28/10) ficou travado — todos
  convergiram em algum momento do dia. O pipeline de conversão em si nunca
  esteve quebrado nesses dias.
- Causa real: `glpi_crontasks.RecurrentItems` só cria os chamados reais das
  rotinas diárias principais (`Importação de Vendas`, `Firewal/Unifi`,
  `TVs/Radios`, `Backup`, `Flex/Wrpdv` — ids 1/2/3/5/13) por volta de
  **07:55-08:00** (horário auto-perpetuado pelo próprio `lastrun`), enquanto
  os placeholders de pré-chamado nascem às **07:00-07:30**. Todo dia, nessa
  janela de ~30-60min, o chamado real simplesmente ainda não existe no GLPI
  — não é bug de conversão. O usuário bate o olho na Agenda bem no início do
  turno, dentro dessa janela, todo dia — por isso a sensação de "nunca
  resolve" apesar do pipeline funcionar horas depois.
- Confirmado ao vivo: `date_creation` dos tickets #12030/12031/12032/12034/
  12038 (as 5 rotinas citadas) mostrou **2026-10-29 07:38:23** — ou seja,
  antes da intervenção desta sessão eles realmente não existiam ainda hoje.

## Correção Realizada

- Forçado disparo imediato do `RecurrentItems` hoje: `glpi_crontasks.lastrun`
  reescrito temporariamente pra `2026-10-28 06:00:00` (ficou due) +
  `front/cron.php` executado manualmente → chamados de hoje criados às
  07:38 em vez de esperar ~08:00.
- `agenda/sync_rotinas.php` executado manualmente em seguida — **8
  pré-chamados convertidos imediatamente**, confirmando que o pipeline
  funciona assim que o chamado real existe.
- Ancorado o próximo disparo natural: `lastrun` reescrito de novo pra
  `2026-10-29 06:00:00` (sem causar novo disparo hoje — `lastrun+frequency`
  cai em 2026-10-30 06:00:00, no futuro) — a partir de amanhã o `RecurrentItems`
  deve disparar perto de **06:00** em vez de ~07:55-08:00, dentro da janela
  de 06:30-09:30 do `\Portal\Agenda-Sync-Rotinas` já desde o primeiro ciclo.
- `frequency=86400` não foi alterado, só o ponteiro `lastrun`.

## Arquivos Modificados

- Nenhum em código — só este log. A correção foi 100% infraestrutura
  (`glpi_crontasks.lastrun` em produção).

## Infraestrutura (fora do git)

- `glpi_crontasks.lastrun` do task `RecurrentItems` ajustado direto na base
  de produção (não é algo deployável via scp/`.new`).

## Pendências

- **Monitorar nos próximos dias** se `RecurrentItems.lastrun` segue
  ancorado perto de 06:00 (não driftar de volta pra tarde) e se os
  pré-chamados das 5 rotinas pararem de aparecer pendentes quando o usuário
  olha a Agenda no início do turno. Se driftar de novo, investigar
  `hourmin`/`hourmax` (hoje em branco, nunca testado em produção por
  múltiplos dias).
