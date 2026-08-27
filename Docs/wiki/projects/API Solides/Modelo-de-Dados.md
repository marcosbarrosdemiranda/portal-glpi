# Modelo de Dados — `ponto_gmais` (MySQL)

> Conceito · ver [[index]] e [[ARCHITECTURE]]. DDL completo em `tangerino-sync/schema.sql`.

Banco **MySQL/MariaDB** `ponto_gmais`, charset `utf8mb4`, engine InnoDB. 16 tabelas.

## Princípios

- **Datas/horas**: `BIGINT` em epoch **millis**. Coluna `data` em `CHAR(10)` `'YYYY-MM-DD'` já calculada no fuso da empresa (para agrupamento rápido).
- **Durações**: `INT` em **minutos** (resumo diário, banco de horas).
- **Booleans**: `TINYINT(1)`.
- Ids vindos da API são PK (não há auto-increment exceto `usuarios`).
- O banco é um **espelho** da API + a tabela de `usuarios` do painel.

## Tabelas

### Cadastros
| Tabela | Conteúdo | Chaves |
|---|---|---|
| `lojas` | 6 lojas + matriz; CNPJ, razão social, fantasia | `id` |
| `setores` | 24 setores (workplace) | `id` |
| `cargos` | cargos (job role) | `id` |
| `escalas` | escalas de trabalho | `id` |
| `escala_horarios` | turnos por dia da semana (min desde 00:00) | `id`, idx `escala_id` |
| `colaboradores` | dados, vínculo a loja/setor/cargo/escala, `matricula`, `demitido` | `id`, idx loja/setor/demitido |

### Ponto e ajustes
| Tabela | Conteúdo | Chaves |
|---|---|---|
| `motivos_ajuste` | férias, afastamento, folga…; flags `abona`, `absenteismo`, `conta_como_falta` | `id` |
| `ajustes` | lançamentos (período em millis, status) | `id`, idx colaborador/motivo/período |
| `batidas` | pares entrada/saída por dia; status, GPS, NSR, plataforma | `id`, idx colaborador+data, data, atualizado |
| `resumo_diario` | **HE por tipo**, noturno, faltas, trabalhado/previsto (minutos) | `id`, unique (colaborador,data) |
| `banco_horas` | saldo atual em minutos | `colaborador_id` |

### Gestão e controle
| Tabela | Conteúdo | Chaves |
|---|---|---|
| `gestores` | registro de gestor (colaborador + flags de permissão) | `id` |
| `gestor_subordinados` | vínculo gestor → colaborador | (`gestor_id`,`colaborador_id`) |
| `justificativas_ponto` | motivos de edição manual de ponto | `id` |
| `sync_estado` | `ultimo_update`/`executado_em` por entidade (sync incremental) | `entidade` |
| `usuarios` | login do painel: `senha_hash` (bcrypt), `perfil`, `loja_id` | `id` (auto-increment) |

## Tabela `resumo_diario` (coração das horas extras)

| Coluna | Origem API | Significado |
|---|---|---|
| `minutos_trabalhados` / `minutos_previstos` | workedHours / estimatedHours | em minutos |
| `he_tipo1..4` | overtimeTypeOne..Four | HE por faixa (ex.: 50%, 100%) |
| `minutos_noturnos` | nightHours | adicional noturno |
| `faltou` / `minutos_falta` | missed / dayMissing | falta no dia |
| `saldo_dia` | hoursBalance | saldo do dia |
| `feriado` / `minutos_pagos` | isHoliday / paidHours | feriado, DSR etc. |

> Total de HE = `he_tipo1 + he_tipo2 + he_tipo3 + he_tipo4`. **Nunca recalcular** — ver [[ADR-002-Sync-Local-vs-Tempo-Real]].

## Relacionamentos (lógicos)

```
lojas 1───* colaboradores *───1 setores
                 │  *───1 cargos
                 │  *───1 escalas ──* escala_horarios
                 ├──* batidas
                 ├──* ajustes *───1 motivos_ajuste
                 ├──* resumo_diario
                 └──1 banco_horas
gestores *───* colaboradores (via gestor_subordinados)
usuarios *───? lojas (loja_id; GESTOR)
```

> Sem FOREIGN KEYs físicas: ids vêm da API e podem referenciar registros fora das janelas sincronizadas. Integridade é garantida no sync.

Relacionado: [[Integracao-API-Solides]], [[Sincronizacao-de-Dados]], [[Dashboards-e-Relatorios]].
