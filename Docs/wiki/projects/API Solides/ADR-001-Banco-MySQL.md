# ADR-001 — Banco de dados MySQL/MariaDB

> Status: **Aceito** · 2026-06-12 · ver [[index]], [[Modelo-de-Dados]].

## Contexto

O dashboard precisa de um banco **robusto** para servidor, suportando web + app e histórico de anos. A primeira versão (protótipo) usou SQLite. O usuário pediu explicitamente "banco de dados robusto, pode ser MySQL".

## Decisão

Adotar **MariaDB 12.3** (compatível com MySQL), database `ponto_gmais`, InnoDB/utf8mb4. Migrar `tangerino-sync` e `dashboard` de `better-sqlite3` para o driver **`mysql2`** (pool de conexões, async).

## Consequências

**Positivas**
- Concorrência real (sync escrevendo enquanto o painel lê), pool de conexões.
- Pronto para servidor, backups, replicação e crescimento.
- Mesmo banco atende sync e painel (tabela `usuarios` junto).

**Negativas / cuidados**
- Migração de API síncrona (SQLite) para assíncrona (mysql2) — handlers viraram `async`.
- **Pegadinha `mysql2 namedPlaceholders`**: trava com SQL multi-statement (schema) e com strings contendo `:` (ex.: `SET time_zone '-04:00'`). Solução adotada: schema em **conexão dedicada sem namedPlaceholders** e **não** usar `SET time_zone` (datas tratadas em JS via `Intl`).

## Alternativas consideradas

- **Manter SQLite**: simples, mas fraco para concorrência e acesso multiusuário em servidor.
- **PostgreSQL**: excelente, mas MySQL foi a preferência explícita do usuário e suficiente para o volume.

Relacionado: [[ADR-003-Stack-NodeJS]], [[Sincronizacao-de-Dados]].
