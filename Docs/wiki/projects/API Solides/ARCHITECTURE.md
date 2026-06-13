# Dashboard de Ponto — Grupo G+ — Architecture

> Documentação navegável no [[index|Mapa de Conteúdo]].

## Overview

Sistema de **controle de ponto eletrônico** do Grupo G+ que integra a **API Sólides/Tangerino**, espelha os dados em um **banco MySQL local** via sincronização incremental e expõe um **painel web/mobile** (PWA) com login seguro. Atende RH (visão global) e gestores de loja (visão restrita à própria unidade), com foco em entrada/saída ao vivo, **horas extras** e ausências. (Banco de horas removido em 12/06/2026 — empresa não usa.)

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | HTML + CSS + JavaScript puro (PWA / SPA leve) | — |
| Backend | Node.js + Express | Node 20 / Express 4 |
| Language | JavaScript (ESM) | ES2022 |
| ORM | Sem ORM — SQL direto via driver | — |
| Database | MySQL / MariaDB | MariaDB 12.3 |
| Driver | mysql2 (pool + named placeholders) | 3.x |
| Auth | JWT (jsonwebtoken) + bcryptjs | 9.x / 2.x |
| Sync runtime | Node.js + mysql2 + fetch nativo | Node 20 |
| Agendamento | Windows Task Scheduler | — |
| Fonte de dados | API Sólides/Tangerino (REST) | — |

## Project Structure

```
C:\Claude Code\API Solides\
├── tangerino-api-reference.md      # Guia completo da API (referência)
├── lojas.json / colaboradores.json # Extrações brutas iniciais
│
├── tangerino-sync\                 # SERVIÇO 1 — sincronização API → MySQL
│   ├── .env                        # token da API + credenciais MySQL
│   ├── schema.sql                  # DDL das 16 tabelas (idempotente)
│   ├── sync-rapido.cmd             # wrapper p/ tarefa de 10 min
│   ├── sync-horario.cmd            # wrapper p/ tarefa de 1 h
│   └── src\
│       ├── env.js                  # carrega .env pelo caminho do projeto
│       ├── api.js                  # cliente HTTP (retry, paginação, concorrência)
│       ├── db.js                   # pool mysql2, schema, upsert em lote
│       ├── sync.js                 # orquestração (full | inc | rapido)
│       └── resetar-estado.js       # força recarga de uma entidade
│
└── dashboard\                      # SERVIÇO 2 — API REST + painel web/mobile
    ├── .env                        # JWT_SECRET + credenciais MySQL
    ├── src\
    │   ├── db.js                   # pool mysql2 (helpers q / um)
    │   ├── auth.js                 # login, JWT, perfis, escopo por loja
    │   ├── server.js               # endpoints REST
    │   └── criar-usuario.js        # CLI p/ criar usuários do painel
    └── public\                     # frontend
        ├── index.html  app.js  style.css  manifest.json
```

## System Architecture

```
┌─────────────────────────┐
│  API Sólides/Tangerino  │  (employer / punch / report)
└───────────┬─────────────┘
            │ HTTPS  Authorization: Basic <token>
            │ sync incremental (lastUpdate)
┌───────────▼─────────────┐
│   tangerino-sync (Node) │  full | inc (1h) | rapido (10min)
│   upsert em lote        │
└───────────┬─────────────┘
            │ INSERT ... ON DUPLICATE KEY UPDATE
┌───────────▼─────────────┐
│   MySQL  ponto_gmais    │  16 tabelas (espelho + usuarios)
└───────────┬─────────────┘
            │ SELECT (pool mysql2)
┌───────────▼─────────────┐
│   dashboard (Express)   │  REST + JWT + perfis
└───────────┬─────────────┘
            │ JSON / fetch  (Bearer token)
┌───────────▼─────────────┐
│  Painel Web/Mobile PWA  │  RH / Gestor / Admin
└─────────────────────────┘
```

### Domain Modules

| Module | Responsibility |
|--------|---------------|
| `tangerino-sync/api.js` | Comunicação com a API (retry, paginação, limite de concorrência) |
| `tangerino-sync/sync.js` | Espelhar cadastros, batidas, ajustes, resumo diário e banco de horas |
| `dashboard/auth.js` | Autenticação, emissão/verificação de JWT, escopo por perfil/loja |
| `dashboard/server.js` | Endpoints de resumo, pontos, horas extras, ausências, banco de horas, usuários |
| `dashboard/public` | Interface PWA responsiva (abas e filtros dinâmicos) |

Detalhes em [[Integracao-API-Solides]], [[Sincronizacao-de-Dados]] e [[Seguranca-e-Perfis]].

## Main Flows

**Fluxo de sincronização (contínuo):**
```
Task Scheduler ─▶ sync rapido (10min) ─▶ GET /punch?lastUpdate ─▶ upsert batidas ─▶ resumo do dia de quem bateu
Task Scheduler ─▶ sync inc   (1h)    ─▶ cadastros + ajustes + batidas + resumo
```

**Fluxo de consulta (painel):**
```
Login (email+senha) ─▶ bcrypt compara ─▶ JWT (perfil, lojaId) ─▶ requests com Bearer
                                          GESTOR: lojaId forçado no backend
```

## Data Model

16 tabelas em `ponto_gmais`. Núcleo: `lojas`, `setores`, `cargos`, `escalas`(+`escala_horarios`), `colaboradores`, `motivos_ajuste`, `ajustes`, `batidas`, `resumo_diario`, `banco_horas`, `gestores`(+`gestor_subordinados`), `justificativas_ponto`, `sync_estado`, `usuarios`. Detalhe completo em [[Modelo-de-Dados]].

Princípios:
- Datas/horas em **epoch millis** (BIGINT); coluna `data` em `'YYYY-MM-DD'` já calculada no fuso da empresa.
- Durações (horas extras, faltas) em **minutos**.
- Horas extras **nunca são recalculadas** — usamos o cálculo oficial da Sólides (`resumo_diario`).

## Permission System

| Role | Permissions |
|------|-------------|
| ADMIN | Tudo + gestão de usuários |
| RH | Todas as lojas (leitura completa) |
| GESTOR | Apenas a própria loja — escopo imposto no backend |

Ver [[Seguranca-e-Perfis]].

## Security

- Senhas com **bcrypt** (hash + salt); nunca em texto puro.
- Sessão via **JWT** assinado (expiração 12h).
- **Rate limiting** de login (5 tentativas / 15 min por e-mail).
- Escopo de loja do GESTOR forçado no servidor (não confia em parâmetro do cliente).
- Token da API e segredos em arquivos `.env` (fora do versionamento). Ver [[CONTRIBUTING#Segredos e credenciais]].
- Pendência para produção: **HTTPS** obrigatório fora da rede local.

## Cache Strategy

- O **banco local é o cache** da API: o painel nunca consulta a Sólides em tempo real.
- Sincronização incremental via `lastUpdate` mantém o espelho atualizado a baixo custo.
- Frontend recarrega a aba "Hoje" a cada 60s.

## Deploy

Atual: máquina Windows (host **MARCOS**), MariaDB como serviço, dois processos Node, agendamento via Task Scheduler. Roadmap de produção (HTTPS, servidor dedicado, backups) em [[Backlog]] e [[ADR-001-Banco-MySQL]].

## Roadmap

### Phase 1 — MVP ✅ (concluído)
Integração com a API, banco MySQL, sincronização, API REST com login/perfis e painel web/mobile funcional.

### Phase 2 — Next 🔜
Visualizações gráficas, exposição em rede com HTTPS/login em servidor, refino de "sem registro" considerando a escala, alertas e exportações.

### Phase 3 — Future 🔮
App nativo (React Native) sobre a mesma API, notificações push, relatórios gerenciais e BI.

Backlog detalhado em [[Backlog]].

## Technical Notes

- **Pegadinha mysql2:** `namedPlaceholders: true` trava com SQL multi-statement (schema) e com strings que contenham `:` (ex.: `SET time_zone '-04:00'`). Solução: rodar o schema em conexão dedicada sem `namedPlaceholders` e **não** usar `SET time_zone` (datas tratadas em JS).
- **Pegadinhas da API Sólides:** `showFired=true` retorna **só** demitidos (buscar em 2 passadas); a paginação às vezes repete páginas (deduplicar por id); `currentWorkSchedule.id` do colaborador é o id do **vínculo**, não da escala. Ver [[Integracao-API-Solides#Pegadinhas]].
- **Fuso:** `America/Campo_Grande` (**UTC-4**, horário de Cuiabá/MS) — **confirmado pelo usuário em 12/06/2026 com batida real** (Robert Willian, 10:07). Centralizado nas conversões millis→hora/data em JS (constante `FUSO` no frontend e nos `db.js`; `OFFSET_MS` no `server.js`). Nunca usar funções de fuso do MySQL.
