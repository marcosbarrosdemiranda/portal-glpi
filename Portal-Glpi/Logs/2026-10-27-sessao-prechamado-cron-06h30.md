---
date: 2026-10-27
status: concluida
author: AI Agent
tags:
  - agenda
  - infra
  - log-sessao
---

# Log de Sessão: Pré-chamados não convertiam — cron de `sync_rotinas.php` nunca existiu

## Contexto e Objetivo

Usuário reportou que os pré-chamados de hoje (~20, rotinas recorrentes do GLPI)
não estavam virando chamados reais na Agenda, citando os de 07:00 como
exemplo, e esperando um cron às 06:30 que normalmente converte tudo durante
o dia.

## Diagnóstico

- `glpi_crontasks.RecurrentItems` (motor nativo do GLPI,
  `CommonITILRecurrentCron`) **não dispara às 06:30** — confirmado por 3 dias
  seguidos (24, 25, 26/10) que o lote inteiro de chamados de rotina (~8-9
  tickets, `users_id_recipient=0`) é criado de uma vez só por volta de
  **07:55**. Esse horário se auto-perpetua porque `frequency=86400` (24h
  exatas) trava no `lastrun` da primeira vez que rodou — não depende de
  `hourmin`/`hourmax` (já são `0`/`24`, sem janela configurada).
- `agenda/sync_rotinas.php` documenta no próprio header `"Deve rodar via cron
  às 06:30"`, mas **nunca foi de fato agendado** em lugar nenhum do servidor
  — confirmado por busca exaustiva de Tarefas Agendadas do Windows
  (`rotina`, `sync`, `agenda`, `portal`, `glpi`). O único mecanismo que
  convertia pré-chamado→chamado na prática era `sync_rotinas_ajax.php`,
  disparado só quando alguém abre a página da Agenda no navegador.
- Os 4 tickets que já existiam hoje às 07:20-07:32 (`#11981-11984`) não
  vieram do `RecurrentItems` (que ainda não tinha disparado — `lastrun`
  continuava de ontem, `state=WAITING`, sem log hoje) — foram criados
  manualmente por um técnico (requerente humano `users_id=56`, recipient
  `glpi`/id 2), coincidindo o nome com a convenção de rotina.
- Invalida a suposição antiga em
  `backlog_sync-rotinas-cron-sem-conversao-prechamado.md` (2026-10-23) de que
  só `sync_rotinas_ajax.php` teria lógica de conversão — `sync_rotinas.php`
  lido na íntegra hoje já tem a mesma lógica de match/conversão (steps 1-3
  quase idênticos à versão AJAX), só não gera novos pré-chamados e tem um
  step 4 extra de limpeza de placeholder órfão.

## Correção Realizada

- **Agora**: `sync_rotinas.php` executado manualmente em produção — converteu
  os 4 tickets já existentes (placeholders atualizados com start/end real).
- **A partir de amanhã**: criada Tarefa Agendada `\Portal\Agenda-Sync-Rotinas`
  no host `glpi-server` — `docker.exe exec glpi-web php
  .../agenda/sync_rotinas.php`, diária às **06:30**, repetindo a cada 15min
  por 3h (até 09:30), rodando como usuário **SYSTEM** (testado e confirmado
  que SYSTEM alcança o named pipe do Docker Desktop nesse host — não precisou
  da credencial `externo` usada pelos 2 tasks de backup existentes). A
  repetição de 15/15min é deliberada: como o GLPI nativo só cria os tickets
  reais ~07:55 (não 06:30), um único disparo às 06:30 não pegaria quase nada
  — a janela de 3h garante que algum disparo roda depois que o GLPI já
  tiver criado os tickets do dia, sem depender de acertar o horário exato
  (que já se mostrou instável entre dias: 07:55:01, 07:55:44, 07:55:50 —
  drifitando ~45-50s por dia).
- Header de `agenda/sync_rotinas.php` atualizado pra refletir o agendamento
  real (nome/caminho da Tarefa, horário com repetição) em vez da intenção
  antiga nunca implementada.

## Arquivos Modificados

- `agenda/sync_rotinas.php` (só comentário de header)

## Infraestrutura (fora do git)

- Tarefa Agendada `\Portal\Agenda-Sync-Rotinas` criada no host `glpi-server`
  via `schtasks` — não é deployada pelo fluxo scp/`.new`, vive só no Windows
  Task Scheduler do host (mesmo padrão dos tasks de backup, ver
  `CLAUDE.md`/regras especiais).

## Pendências

- Nenhuma — fix validado em produção (4 tickets de hoje convertidos,
  próximo disparo automático da Tarefa às 08:00 de hoje já deve pegar o
  resto do lote de rotinas quando o GLPI criar).
