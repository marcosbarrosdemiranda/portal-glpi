# ADR-002 — Espelho local em vez de consultar a API em tempo real

> Status: **Aceito** · 2026-06-12 · ver [[index]], [[Sincronizacao-de-Dados]].

## Contexto

O painel precisa de dados quase em tempo real para ~218 colaboradores em 6 lojas, com agregações (HE do mês por loja/setor). Duas opções: consultar a API Sólides a cada tela, ou manter um **espelho local** sincronizado.

## Decisão

Manter um **banco MySQL local** alimentado por sincronização incremental (`lastUpdate`); o painel lê **somente do banco**. Sync **rápido a cada 10 min** (batidas) e **completo a cada 1 h**.

## Justificativa

- A própria API foi desenhada para isso (parâmetro `lastUpdate`).
- Agregações (HE, ranking, absenteísmo) são triviais em SQL e caras via API paginada.
- **Resiliência**: se a Sólides cair, o painel segue com o último espelho.
- Histórico fica sob nosso controle.

## Consequências

- Defasagem máxima ~10 min (aceitável para o caso de uso).
- Necessário agendamento confiável (Task Scheduler) e monitoramento de falhas.

## Regra associada — Horas extras

**Não recalcular horas extras.** O cálculo trabalhista (faixas, noturno, faltas) vem pronto e oficial no `resumo_diario` da Sólides. Recalcular traria risco jurídico e divergência. O painel apenas **soma** o que a Sólides calculou.

Relacionado: [[Integracao-API-Solides#Pegadinhas]], [[Modelo-de-Dados]].
