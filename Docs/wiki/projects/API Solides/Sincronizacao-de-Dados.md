# Sincronização de Dados

> Conceito · ver [[index]]. Código em `tangerino-sync/src/sync.js`.

## Estratégia

A API **não é consultada em tempo real** pelo painel. Um serviço Node espelha os dados para o MySQL e o painel lê só do banco. Ver [[ADR-002-Sync-Local-vs-Tempo-Real]].

## Modos

| Modo | Comando | O que faz | Quando |
|---|---|---|---|
| **full** | `node src/sync.js full` | Limpa `sync_estado` e recarrega tudo (35 dias de batidas/resumo) | Carga inicial / recuperação |
| **inc** | `node src/sync.js inc` | Cadastros + ajustes + batidas + resumo (3 dias), incremental | A cada 1 h |
| **rapido** | `node src/sync.js rapido` | Só batidas novas (via `lastUpdate`) + resumo do dia de **quem bateu** | A cada 10 min |

## Sincronização incremental

- `sync_estado` guarda `ultimo_update` por entidade.
- Batidas e ajustes usam `lastUpdate` → a API devolve só o que mudou.
- O modo `rapido` é **leve**: 1 request de batidas + resumo apenas dos colaboradores afetados.

## Gravação

- **Upsert em lote**: `INSERT ... ON DUPLICATE KEY UPDATE`, blocos de 500 linhas (`upsertLote` em `db.js`).
- Idempotente: rodar a qualquer momento não duplica nem corrompe.

## Cliente HTTP (`api.js`)

- **Retry** com backoff em 429/5xx.
- **Paginação** automática (`paginas()`), com suporte a `pageNumber/pageSize`.
- **Concorrência limitada** (`mapLimite`, 6 simultâneas) para resumo diário e banco de horas (1 request por colaborador).

## Agendamento (Windows Task Scheduler — host MARCOS)

| Tarefa | Intervalo | Wrapper |
|---|---|---|
| `Tangerino Sync Rapido` | 10 min | `sync-rapido.cmd` → `sync.js rapido` |
| `Tangerino Sync Horario` | 1 h | `sync-horario.cmd` → `sync.js inc` |

Logs em `tangerino-sync\logs\`. Recriar/checar:
```powershell
schtasks /Query /TN "Tangerino Sync Rapido" /FO LIST
schtasks /Change /TN "Tangerino Sync Rapido" /ENABLE
```

## Desempenho observado (2026-06-12)

- Carga **full**: ~495 s (37 mil ajustes, ~9,3 mil batidas, 7.441 dias-colaborador de resumo).
- Sync **rapido** ocioso: ~80 s (a maior parte é a consulta paginada de batidas).

## Pegadinhas tratadas no sync

- `showFired` em 2 passadas (ativos + demitidos).
- Deduplicação por id (paginação repetida).
- **mysql2 `namedPlaceholders`** trava com schema multi-statement e strings com `:` → schema roda em conexão dedicada; sem `SET time_zone` (datas em JS). Ver [[ADR-001-Banco-MySQL]] e [[ARCHITECTURE#Technical Notes]].

Relacionado: [[Integracao-API-Solides]], [[Modelo-de-Dados]].
