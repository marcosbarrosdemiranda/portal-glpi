---
date: 2026-11-04
status: monitorando
author: AI Agent
tags:
  - agenda
  - infra
  - log-sessao
---

# Log de Sessão: Rotinas diárias criando ~16min depois do horário nominal — frequency do RecurrentItems trocado de 1x/dia pra 30min

## Contexto e Objetivo

Usuário notou a notificação de WhatsApp de chamado de rotina diária (nominal
07:00) chegando às 07:17 hoje, e perguntou se o chamado real no GLPI também
estava convertendo fora de horário.

## Diagnóstico

- Confirmado via query direta em produção: o chamado real nasceu em
  `glpi_tickets.date_creation = 2026-11-04 07:16:22`, batendo exatamente com
  `glpi_crontasks.lastrun` do task `RecurrentItems`, pinado em `07:16:00`
  desde a regressão de 30/10 (ver
  [[2026-10-29-sessao-prechamado-lastrun-glpi]] e causa #5 na memória
  `fix_prechamado-sem-cron-06h30`, sem log próprio no vault). A notificação
  de WhatsApp (`wpp/gatilhos.php`, polling a cada 30s via `wpp/worker.php`)
  só espelhou fielmente o horário real de criação — não havia atraso
  independente na notificação nem bug de conversão.
- `RecurrentItems` tem `frequency=86400` (dispara só 1x/dia) e depende de um
  único `lastrun` pra decidir "está devido". Levantamento completo de
  `glpi_ticketrecurrents` ativos mostrou **27 definições** com horários
  nominais de **04:00 a 14:30**, não só 07:00/07:30 como a investigação de
  30/10 tinha assumido — qualquer `lastrun` fixo escolhido a dedo atropela
  pelo menos uma janela (foi exatamente a causa #5 do incidente anterior:
  ancorar pra 06:00 deixou as rotinas de 07:00 sem criar o dia todo).

## Correção Realizada

- Em vez de reancorar `lastrun` (ponteiro único, já se mostrou frágil 2x),
  reduzido o `frequency` do crontask: `UPDATE glpi_crontasks SET frequency =
  1800 WHERE name = 'RecurrentItems'` (86400s → 1800s/30min). `lastrun` não
  foi tocado.
- Com isso o crontask reavalia "o que está devido" a cada 30min em vez de
  1x/dia — a Tarefa Agendada `\Portal\Glpi-Cron` (já chama `front/cron.php`
  a cada 5min) respeita o novo intervalo automaticamente, sem precisar de
  ajuste próprio. Cada rotina passa a ser criada no máximo ~30min depois do
  seu próprio horário nominal, sem depender de um ponto único que sirva pra
  todas as 27 janelas.

## Risco Conhecido

Esse mesmo crontask já duplicou 8 chamados reais em 22/10/2026 quando
`frequency` ficou em `60` (60s) — 2 execuções com só 71s de diferença viram
"ocorrência ainda não criada" cada uma e criaram separado (ver
[[2026-10-22]] / memória `incident-glpi-recurrentitems-duplicou-chamados-1022`,
sem log próprio no vault). `1800s` é ~25x esse intervalo — margem grande,
mas inferida, não testada: não há confirmação de qual é o limite seguro
real entre 71s (quebrou) e 86400s (não dava atraso tolerável).

## Arquivos Modificados

- Nenhum em código — só este log. A correção foi 100% infraestrutura
  (`glpi_crontasks.frequency` em produção).

## Infraestrutura (fora do git)

- `glpi_crontasks.frequency` do task `RecurrentItems` ajustado direto na
  base de produção (não é algo deployável via scp/`.new`).

## Pendências

- **Monitorar nos próximos dias** `glpi_crontasklogs` do `RecurrentItems`
  pra confirmar que não passou a rodar 2x criando o mesmo lote (mesmo padrão
  do incidente de 22/10) e que o atraso de cada rotina caiu de ~16min fixo
  pra algo menor, sem duplicata. Se duplicar de novo: reverter `frequency`
  pra `86400` e aceitar o atraso fixo de novo.
