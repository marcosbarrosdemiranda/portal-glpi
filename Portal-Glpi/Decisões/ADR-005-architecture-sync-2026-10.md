# ADR-005 — Sincronização do ARCHITECTURE.md com o estado real do sistema

**Data:** 2026-10-19
**Status:** ATIVO

---

## Contexto

`Arquitetura/ARCHITECTURE.md` descrevia só o MVP original da Agenda (junho/2026) —
Agenda + Central RDP + Guacamole. Desde então o portal cresceu bastante sem que o
doc acompanhasse: Monitor de Rede próprio (substituiu o The Dude, 7 etapas),
Central de Alertas fundida a ele, Orçamento/Despesas com BI e dimensão Projeto,
WhatsApp via Evolution API, SEFAZ, Sólides/Ponto, pfSense, backup, Cofre, Central
de Acessos RDP/VNC/AnyDesk, etc. — nada disso estava na Tech Stack, na tabela de
módulos (que não existia) nem no Roadmap.

Esse drift ficou visível ao tentar montar um cronograma do projeto: a vault
Obsidian (`Documentação/`, `Tarefas/backlog.md`) estava presa em 24-25/09, e o
próprio `ARCHITECTURE.md` em junho — meses de trabalho real só existiam em
commits git e na memória de sessão, não documentados aqui.

Também identificado: `Documentação/CRITICAL_FACTS.md` e `SOUL.md` continham o
boilerplate genérico do instalador `claude-init`/`antigravity-init` (manager
"Celso/Meuphilim", stack "React/TypeScript/Next.js") nunca preenchido com fatos
reais deste projeto.

## Decisão

Seguir o protocolo de "Cenário C" (projeto com ARCHITECTURE.md existente):
**nunca reescrever do zero, só atualizar incrementalmente.**

1. `ARCHITECTURE.md`: Tech Stack corrigida pra stack real (PHP sem framework,
   MySQL/MariaDB, Docker, Evolution API, sem CI/CD); seção nova **Domain
   Modules** com inventário completo de módulos; **Roadmap** reescrito —
   MVP original preservado como "Fase 0 (histórico)", fases novas (1-4)
   cobrindo o estado real (concluído / em validação / em andamento / backlog).
   Todo o conteúdo original da Agenda (data model, diagrama, ADR-001/002) foi
   preservado sem alteração.
2. `CRITICAL_FACTS.md`: corrigido pros fatos reais (responsável, stack, infra,
   branch de integração). `SOUL.md` não foi tocado — é config de persona de
   outra ferramenta (Antigravity/Gemini), fora do escopo desta sessão.
3. Este ADR + entrada no `Documentação/log.md` registram a atualização, como o
   protocolo exige.

## Impacto

- `Portal-Glpi/Arquitetura/ARCHITECTURE.md` — Tech Stack, Domain Modules, Roadmap
- `Portal-Glpi/Documentação/CRITICAL_FACTS.md` — fatos corrigidos
- `Portal-Glpi/Documentação/log.md` — entrada nova
- `Portal-Glpi/Decisões/ADR-005-architecture-sync-2026-10.md` — este documento

## Observação

A vault Obsidian (`Tarefas/backlog.md`, `Logs/`) continua desatualizada desde
set/2026 e **não foi reescrita nesta sessão** — ficou fora do escopo pedido
(cronograma via ARCHITECTURE.md). Fica registrado como pendência separada se
quiser sincronizar também.
