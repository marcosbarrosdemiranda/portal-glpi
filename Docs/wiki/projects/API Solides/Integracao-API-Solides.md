# Integração — API Sólides / Tangerino

> Conceito · ver [[index]]. Guia exaustivo de endpoints em `C:\Claude Code\API Solides\tangerino-api-reference.md`.

## O que é

API REST de ponto eletrônico da **Sólides DP (ex-Tangerino)**. Cobre cadastros (cargo, local, escala, colaborador, gestor), ajustes (férias, afastamentos, folgas) e batidas/folha de ponto.

## Autenticação

- Token de integração **solicitado ao suporte da Sólides DP**.
- Header em toda requisição: `Authorization: Basic <token>`.
- Teste de conectividade: `GET https://employer.tangerino.com.br/test` → `"Hello, GrupoGmais!"`.

## Os 3 módulos (base URLs)

| Módulo | Base URL | Uso no projeto |
|---|---|---|
| **Employer** | `https://employer.tangerino.com.br` | Cadastros, motivos e lançamentos de ajuste, **`/companies`** (lojas com CNPJ) |
| **Punch** | `https://api.tangerino.com.br/api/punch` | Batidas, resumo diário (`/daily-summary/`), banco de horas (`/hoursBalance`) |
| **Report** | `https://api.tangerino.com.br/api/report` | Emissão de espelho de ponto (PDF base64) |

## Endpoints usados na sincronização

| Entidade | Endpoint | Observação |
|---|---|---|
| Lojas | `GET /companies` | **Não documentado** publicamente; achado no Swagger. Traz CNPJ |
| Setores | `GET /workplace/find-all` | paginado |
| Cargos | `GET /job-role/find-all` | paginado |
| Escalas | `GET /work-schedule` | horários por dia (millis desde 00:00) |
| Colaboradores | `GET /employee/find-all` | ver pegadinha `showFired` |
| Motivos de ajuste | `GET /adjustment-reason/find-all` | férias, afastamento, folga, atestado… |
| Ajustes | `GET /adjustment/find-all` | suporta `lastUpdate` |
| Gestores | `GET /manager/find-all` | + subordinados |
| Justificativas | `GET /manual-editing-justification-punch/` | para edição manual de ponto |
| Batidas | `GET /api/punch/` | filtros `startDate/endDate/lastUpdate` |
| Resumo diário (HE) | `GET /api/punch/daily-summary/` | HE por tipo, noturno, faltas (minutos) |
| Banco de horas | `GET /api/punch/hoursBalance` | saldo em minutos |

## Convenções da API

- **Datas em epoch milissegundos** (exceto ponto em atraso, que usa ISO com timezone).
- Paginação `page`/`size` (ou `pageNumber`/`pageSize` em `/companies`); resposta estilo `Page{content,last,...}`.
- **`externalId`** = matrícula no nosso sistema; pode substituir o id Tangerino.
- **`lastUpdate`** habilita sincronização incremental.
- Fuso da conta: `CUIABA` (America/Campo_Grande, -04:00).

## Pegadinhas

> Aprendidas na implementação — críticas para não quebrar o sync.

1. **`showFired=true` retorna SÓ demitidos** (não "todos"). Para ter ativos + demitidos, busca-se em **2 passadas** (`{}` e `{showFired:true}`) deduplicando por id.
2. **Paginação repete páginas** ocasionalmente → deduplicar por id (o upsert já protege).
3. **`currentWorkSchedule.id` do colaborador é o id do VÍNCULO**, não da escala. A escala real do dia vem em `workScheduleId` da batida e do resumo diário.
4. **Horas extras não devem ser recalculadas** — vêm prontas e oficiais no `resumo_diario` (`overtimeTypeOne..Four`). Ver [[ADR-002-Sync-Local-vs-Tempo-Real]].
5. O resumo diário e o banco de horas são **por colaborador** (1 request cada) → usar limite de concorrência (6) no sync.

## Motivos de ajuste relevantes (exemplos)

`FÉRIAS` (1), `AFASTAMENTO` (2), `FOLGA` (3), `ABONO` (4), `ATESTADO MÉDICO` (5), `FALTA NÃO JUSTIFICADA` (8), `AFASTAMENTO INSS` (9), `LICENÇA MATERNIDADE/PATERNIDADE` (10/11), `ACIDENTE DE TRABALHO` (19)… `accountAsAbsenteeism` marca os que contam como absenteísmo.

Relacionado: [[Modelo-de-Dados]], [[Sincronizacao-de-Dados]].
