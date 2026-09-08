# CLAUDE.md — Universal Engineering Context & Audit Framework

## REGRA #0 — Protocolo de Sincronizacao e Execucao
Antes de propor ou redigir codigo, o agente DEVE executar silenciosamente:
1. Ler ./Portal-Glpi/Arquitetura/CONTRIBUTING.md para garantir conformidade arquitetural e seguranca.
2. Ler ./Portal-Glpi/Arquitetura/ARCHITECTURE.md para entender a arquitetura, principios e decisoes do sistema.
3. Localizar o log mais recente em ./Portal-Glpi/Logs/ para continuar o contexto exato da sessao anterior.
4. Validar o plano arquitetural antes de reescrever arquivos core do sistema.
5. Ler ./Portal-Glpi/Decisões/ para identificar regras protegidas antes de qualquer alteracao.
6. Ler ./Docs/wiki/projects/ para verificar o PRD atual, status dos modulos e pendencias registradas.

## REGRA #1 — Regras Protegidas (IMUTAVEIS sem aprovacao explicita)
O arquivo Portal-Glpi/Decisões/ADR-001-regras-protegidas-agenda.md define comportamentos
que NAO podem ser alterados sem permissao direta do responsavel.
Qualquer codigo marcado com o comentario abaixo e INTOCAVEL sem aprovacao:
  // ⚠️ REGRA PROTEGIDA — NÃO ALTERAR SEM PERMISSÃO DO RESPONSÁVEL ⚠️

Resumo das regras travadas (agenda/index.php):
- Criacao de eventos em datas passadas: BLOQUEADO
- Arrastar chamados do sidebar para datas passadas: BLOQUEADO
- Mover qualquer evento para data passada: BLOQUEADO
- Mover ou redimensionar eventos concluidos (verde): BLOQUEADO
- Visibilidade por atendente: eventos so aparecem na agenda do atendente que os possui

REGRA EXTRA — fechar_ticket.php (CONGELADO):
- Um unico PUT status=6. NAO fazer dois passos (status=5 depois 6): quebra neste GLPI.
- Sempre retornar ok=true. NAO validar resposta do GLPI: formato varia por versao.
- Ordem OBRIGATORIA: atualizar_ticket.php → .then() → fechar_ticket.php (nunca paralelo).
  Race condition confirmada: PUT paralelo de campos reabre o chamado recem fechado.

## REGRA #2 — Integracao GLPI Obrigatoria para Chamados (ADR-002)
Ver Docs/wiki/decisions/ADR-002-integracao-glpi-obrigatoria.md

TODA operacao sobre chamado (tipo=chamado) ou requisicao (tipo=requisicao) na Agenda TI
DEVE ter integracao direta e imediata com o GLPI. A agenda e uma INTERFACE — o GLPI e a fonte da verdade.

Operacoes obrigatorias:
- CRIAR chamado → criar_ticket.php → POST /Ticket com TODOS os campos do modal
- EDITAR chamado → atualizar_ticket.php → PUT /Ticket com TODOS os campos alterados
- ARRASTAR do sidebar → atribuir_ticket.php → atribui tecnico no GLPI
- RESPONDER → responder_ticket.php → POST /ITILFollowup no GLPI
- FECHAR → fechar_ticket.php → PUT status=6 no GLPI
- EXCLUIR da agenda → resetar_ticket.php → PUT status=1, remove tecnico no GLPI

NUNCA salvar apenas na agenda sem refletir no GLPI para chamados/requisicoes.
Reuniao e Evento sao internos — NAO tem integracao GLPI e nao precisam ter.

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

`markdown
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
`

### After creating/updating ARCHITECTURE.md:
1. Register the creation/update in Docs/wiki/decisions/ as an ADR
2. Register in the session log at Docs/wiki/logs/
