# CLAUDE.md — Universal Engineering Context & Audit Framework

## REGRA #0 — Protocolo de Sincronizacao e Execucao
Antes de propor ou redigir codigo, o agente DEVE executar silenciosamente:
1. Ler ./CONTRIBUTING.md para garantir conformidade arquitetural e seguranca.
2. Ler ./ARCHITECTURE.md para entender a arquitetura, principios e decisoes do sistema.
3. Localizar o log mais recente em ./Docs/wiki/logs/ para continuar o contexto exato da sessao anterior.
4. Validar o plano arquitetural antes de reescrever arquivos core do sistema.

## MANDATORY: Create or Update ARCHITECTURE.md

### Rule
If the project does not have an ARCHITECTURE.md at the root, the AI agent MUST create one following the template below.

### Creation Flow

**Scenario A — Greenfield project (no code yet):**
1. Run a structured brainstorm with the user to understand:
   - Project type (web, API, mobile, game, CLI, etc.)
   - Desired tech stack
   - Core business domain and rules
   - Data entities and relationships
   - Infrastructure requirements (Docker, K8s, Serverless)
2. Present an architecture summary for approval
3. Only then write the complete ARCHITECTURE.md

**Scenario B — Existing project without ARCHITECTURE.md:**
1. Explore the full project structure (directories, packages, configs)
2. Read source code to identify: stack, patterns, modules, endpoints
3. Analyze key files: package.json, tsconfig, Dockerfile, CI/CD, DB schema
4. Interview the user about: domain, target audience, roadmap
5. Synthesize everything into the ARCHITECTURE.md template
6. Present for validation before finalizing

**Scenario C — Existing project WITH ARCHITECTURE.md:**
1. Read the current document
2. Compare against the actual codebase — identify drifts (obsolete sections, outdated stack, missing modules)
3. Preserve ALL existing content, only add missing sections and fix inconsistencies
4. NEVER rewrite from scratch — update incrementally

### Required ARCHITECTURE.md Template

```markdown
# [Project Name] — Architecture

## Overview
[2-3 lines describing the system purpose]

## Tech Stack
| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | | |
| Backend | | |
| Language | | |
| ORM | | |
| Database | | |
| Cache | | |
| Search | | |
| Storage | | |
| Monorepo | | |
| Container | | |
| Infra | | |
| CI/CD | | |

## Project Structure
[Directory tree with descriptions]

## System Architecture
[ASCII diagram of layered architecture]

### Domain Modules
| Module | Responsibility |
|--------|---------------|

## Main Flows
[ASCII diagram of main flows]

## Data Model
[Main entities and relationships]

## Permission System (if applicable)
| Role | Permissions |

## Security
[Key measures: auth, validation, rate limiting, etc.]

## Cache Strategy
[Cache levels: browser, edge, app, database]

## Deploy
[Deploy strategy: Docker, K8s, cloud, etc.]

## Roadmap
### Phase 1 — MVP
### Phase 2 — Next
### Phase 3 — Future

## Technical Notes
[Important architectural decisions, conventions, patterns]
```

### After creating/updating ARCHITECTURE.md:
1. Register the creation/update in Docs/wiki/decisions/ as an ADR
2. Register in the session log at Docs/wiki/logs/

## Source of Truth: Docs/wiki/
All project documentation lives under Docs/wiki/. Always consult it before making decisions.
- Docs/wiki/projects/ — PRDs and MVP scopes
- Docs/wiki/concepts/ — Design and architecture decisions
- Docs/wiki/tasks/ — Active backlog and technical debt
- Docs/wiki/logs/ — Session logs
- Docs/wiki/decisions/ — ADRs
