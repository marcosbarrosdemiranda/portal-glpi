---
tags:
  - adr
  - agenda
---

# ADR-006 — Pré-chamado na Agenda: ocorrências de `glpi_ticketrecurrents` (Opção A)

**Data:** 2026-10-19
**Status:** ATIVO

---

## Contexto

A Agenda só mostrava um chamado de rotina no dia em que o GLPI de fato o criava
(via `sync_rotinas_ajax.php`, disparado por `fetch()` a cada carregamento da
página — não é cron). Não havia visibilidade das ocorrências *futuras* de uma
rotina recorrente — o técnico só via "hoje" na grade, nunca "a rotina de
Backup roda de novo quinta-feira".

Duas rotas possíveis de recorrência existiam/foram consideradas:

- **Opção A** — `glpi_ticketrecurrents`, o motor nativo do GLPI que já define
  32 rotinas ativas reais em produção (entidade raiz), com `begin_date` +
  `periodicity` + `calendars_id`.
- **Opção B** — `agenda_recorrencias`, a recorrência própria da Agenda (dias da
  semana + data limite), usada hoje só pra eventos/reuniões criados dentro do
  próprio portal, sem relação com o GLPI.

Uma implementação inicial começou pela Opção B (gerar pré-chamados a partir de
`agenda_recorrencias`), mas o usuário corrigiu explicitamente: as rotinas que
importam aqui são as 32 definições já cadastradas **no GLPI**, não uma
recorrência nova inventada na Agenda. ("esses recorrentes q mensionei são
direto do glpi glpi-ticketrecurrents opcao A")

## Decisão

**Opção A.** `agenda/pre_chamado_lib.php` calcula as ocorrências futuras de
cada `glpi_ticketrecurrents` ativa (entidade raiz) a partir de `begin_date` +
`periodicity`, dentro de uma janela de 30 dias. `sync_rotinas_ajax.php` (já o
ponto de sincronização real, rodando a cada carregamento da página) gera um
evento `tipo='pre_chamado'` pra cada ocorrência sem data já ocupada, e
**converte** (em vez de duplicar) o placeholder quando o chamado real
correspondente aparece.

Ligação placeholder↔definição via nova coluna `ticketrecurrents_id` em
`glpi_plugin_agenda_events` (`NULL` = pré-chamado criado manualmente pelo
usuário, nunca converte sozinho por tempo — só pelo botão "Converter em
chamado").

### Achado técnico durante a validação: nome do chamado ≠ nome da definição

`periodicity` tem só 6 formatos reais distintos (segundos puros ou `"NMONTH"`)
— sem surpresa aí. A surpresa foi noutro campo: casar "qual chamado real
corresponde a qual definição" por `tr.name = t.name` (igualdade exata) falhou
silenciosamente pra 2 das 32 definições — `glpi_ticketrecurrents.name` não é
sempre igual ao `glpi_tickets.name` do chamado gerado:

- def `"TVs, Radios e Consulta Preços"` → chamado real
  `"TVs, Radios e Consulta Preços - Rotina Diária"` (sufixo extra)
- def `"Bot Conversa"` → chamado real `"Bot Conversa "` (espaço sobrando)

Sem o link, o dedup de "dia já ocupado" não via o chamado real como ocupando
aquele dia, e o gerador recriava um placeholder duplicado a cada rodada de
sync — um loop de duplicação silenciosa, só visível comparando linha a linha
no banco. Corrigido com `t.name LIKE CONCAT(TRIM(tr.name), '%')` (prefixo, não
igualdade) nas duas queries de `sync_rotinas_ajax.php`. Validado: 3 rodadas de
sync consecutivas depois da correção ficaram idempotentes
(`placeholders_criados=0` nas repetições).

## Impacto

- `agenda/pre_chamado_lib.php` (novo)
- `agenda/sync_rotinas_ajax.php` — geração/conversão de placeholders + match
  por prefixo
- `agenda/eventos.php` — coluna `ticketrecurrents_id` (migração idempotente)
- `agenda/index.php` — tipo "🕓 Pré-chamado" (cor cinza claro), botão
  "Converter em chamado" pra pré-chamados manuais

## Observação

Sem suporte a calendário/feriado na v1 — as 32 definições ativas compartilham
o mesmo `calendars_id`, aberto todos os dias da semana, sem feriado cadastrado.
Limitação conhecida, não bloqueante; registrar se algum dia uma definição usar
um calendário com feriados reais.
