# CRITICAL FACTS — Contexto Estático Imutável

> Corrigido 2026-10-19 — valores abaixo eram o boilerplate genérico do instalador
> `claude-init`/`antigravity-init`, nunca preenchido com os fatos reais deste projeto.

**Responsável:** Marcos (usuário git do repositório)
**Projeto:** Portal GLPI (portal-glpi) — portal interno de TI do Grupo Gmais integrado ao GLPI
**Stack real:** PHP sem framework + MySQL/MariaDB, JS vanilla no front (sem build step, sem React/TypeScript/Next.js)
**Infra:** Servidor Windows `192.168.1.198` (`glpi-server`), Docker Desktop, deploy manual via SCP (nunca `git pull` no container — ver CLAUDE.md da raiz)
**Branch de integração:** `infra/migracao-docker-glpi` (main está muito atrás) — trabalho novo em branch própria empilhada
**Project Initialized:** 2026-05 (início real do portal); pasta `Docs/` desta vault criada 2026-06-06
