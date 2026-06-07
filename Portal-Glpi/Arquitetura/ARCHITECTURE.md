# Portal GLPI — Architecture

## Overview
Portal web interno de suporte TI integrado ao GLPI. Fornece dashboard, agenda de atendimento e gestão de chamados com sincronização bidirecional com a API REST do GLPI.

## Tech Stack
| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | HTML + Bootstrap 5 + FullCalendar | 5.3.3 / 6.1.11 |
| Backend | PHP | 8.x |
| Language | JavaScript (vanilla) + PHP | — |
| Database | MySQL (banco do GLPI) | — |
| Storage | Tabela `glpi_plugin_agenda_events` | — |
| Container | — | — |
| CI/CD | — | — |

## Project Structure
```
portal-glpi/
├── auth.php                  # Autenticação via GLPI API
├── dashboard.php             # Dashboard principal
├── agenda/
│   ├── index.php             # Agenda TI (FullCalendar + toda a lógica JS)
│   ├── eventos.php           # CRUD da tabela glpi_plugin_agenda_events
│   ├── tickets.php           # Lista chamados abertos do GLPI (sidebar)
│   ├── criar_ticket.php      # POST /Ticket — cria chamado no GLPI
│   ├── atualizar_ticket.php  # PUT /Ticket — sincroniza todos os campos com GLPI
│   ├── atribuir_ticket.php   # Atribui técnico ao chamado no GLPI
│   ├── responder_ticket.php  # Adiciona followup + anexos ao chamado no GLPI
│   ├── fechar_ticket.php     # Fecha chamado no GLPI (status=6)
│   ├── resetar_ticket.php    # Devolve chamado para fila (status=1, remove técnico)
│   ├── ticket_descricao.php  # Busca dados completos do ticket no GLPI
│   ├── verificar_atrasados.php # Remove chamados não concluídos da semana passada
│   ├── sync_rotinas_ajax.php # Sincroniza chamados criados hoje como rotinas
│   ├── users.php             # Lista atendentes e usuários do GLPI
│   ├── entidades.php         # Lista entidades/lojas do GLPI
│   ├── categorias.php        # Lista categorias de tickets do GLPI
│   ├── google_calendar.php   # Configuração de sync com Google Calendar
│   ├── google_eventos.php    # Importa eventos do Google Calendar (iCal)
│   ├── db.php                # Conexão PDO com banco do GLPI
│   └── config.php            # Credenciais da API GLPI
├── Docs/
│   └── wiki/
│       ├── decisions/
│       │   ├── ADR-001-regras-protegidas-agenda.md
│       │   └── ADR-002-integracao-glpi-obrigatoria.md
│       └── logs/
│           └── 2026-06-02-sessao-agenda.md
└── assets/
    └── notificacoes.js
```

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Browser                              │
│  ┌──────────┐  ┌──────────────────────────────────────────┐│
│  │ Sidebar  │  │         Agenda (FullCalendar v6)         ││
│  │ Chamados │  │  ┌─────────────┐  ┌────────────────────┐ ││
│  │  GLPI    │  │  │ Drag & Drop │  │  Modal Evento      │ ││
│  │          │  │  │ (externo +  │  │  (criar/editar     │ ││
│  │          │  │  │  interno)   │  │   chamados)        │ ││
│  └──────────┘  │  └─────────────┘  └────────────────────┘ ││
└────────────────┴──────────────────────────────────────────┘│
         │                    │                              │
         ▼                    ▼                              │
┌────────────────────────────────────────────────────────────┐
│                    PHP Backend                              │
│                                                            │
│  ┌─────────────────┐     ┌──────────────────────────────┐  │
│  │  Agenda DB      │     │    GLPI API REST             │  │
│  │  (eventos.php)  │     │                              │  │
│  │                 │     │  criar_ticket.php            │  │
│  │  glpi_plugin_   │     │  atualizar_ticket.php        │  │
│  │  agenda_events  │     │  atribuir_ticket.php         │  │
│  │                 │     │  responder_ticket.php        │  │
│  │  - id           │     │  fechar_ticket.php           │  │
│  │  - titulo       │     │  resetar_ticket.php          │  │
│  │  - start/end    │     │  ticket_descricao.php        │  │
│  │  - tipo         │     │  tickets.php                 │  │
│  │  - ticket_id ──────── │  sync_rotinas_ajax.php       │  │
│  │  - atendente    │     │  verificar_atrasados.php     │  │
│  │  - concluido    │     └──────────────────────────────┘  │
│  └─────────────────┘               │                       │
└────────────────────────────────────┼───────────────────────┘
                                     ▼
                         ┌───────────────────────┐
                         │      GLPI Server       │
                         │   /apirest.php/...     │
                         │                        │
                         │   /Ticket              │
                         │   /Ticket_User         │
                         │   /ITILFollowup        │
                         │   /Document            │
                         │   /Entity              │
                         │   /ITILCategory        │
                         │   /User                │
                         └───────────────────────┘
```

## Regra Fundamental de Integração (ADR-002)

> **TODA operação sobre chamado/requisição na agenda DEVE refletir imediatamente no GLPI.**
> A agenda é uma interface — o GLPI é a fonte da verdade.

| Operação | Integração GLPI | Arquivo |
|----------|----------------|---------|
| Criar chamado | POST /Ticket (todos os campos) | `criar_ticket.php` |
| Editar chamado | PUT /Ticket + PUT Ticket_User | `atualizar_ticket.php` |
| Arrastar chamado | Atribui técnico | `atribuir_ticket.php` |
| Responder chamado | POST ITILFollowup + Documents | `responder_ticket.php` |
| Fechar chamado | PUT status=6 | `fechar_ticket.php` |
| Excluir da agenda | PUT status=1, remove técnico | `resetar_ticket.php` |
| **Reunião / Evento** | **SEM integração GLPI** | apenas `eventos.php` |

## Tipos de Evento na Agenda

| tipo | Cor | GLPI? | Descrição |
|------|-----|-------|-----------|
| `chamado` | Vermelho | ✅ SIM | Chamado do GLPI (Incidente) |
| `requisicao` | Laranja | ✅ SIM | Requisição do GLPI |
| `reuniao` | Roxo | ❌ NÃO | Reunião interna |
| `evento` | Azul | ❌ NÃO | Evento genérico |
| concluido | Verde | ✅ SIM | Qualquer tipo concluído |
| atrasado | Amarelo | — | Não concluído fora do prazo |

## Regras de Negócio Protegidas (ADR-001)

Ver `Portal-Glpi/Decisões/ADR-001-regras-protegidas-agenda.md`

- ❌ Criar evento em data passada → bloqueado
- ❌ Arrastar do sidebar para data passada → bloqueado
- ❌ Mover qualquer evento para data passada → bloqueado
- ❌ Mover ou redimensionar evento concluído → bloqueado
- ✅ Visibilidade isolada por atendente

## Data Model

### `glpi_plugin_agenda_events`
| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | VARCHAR | ID único do evento (ev_xxx / rot_xxx) |
| titulo | VARCHAR | Título (= name do ticket GLPI) |
| descricao | TEXT | Descrição |
| start | DATETIME | Início do agendamento |
| end | DATETIME | Fim do agendamento |
| atendente | VARCHAR | Nome do técnico |
| atendente_id | INT | ID do usuário no GLPI |
| atendente_cor | VARCHAR | Cor hex do atendente |
| prioridade | ENUM | baixa/media/alta/critica |
| setor | VARCHAR | Entidade/loja (nome) |
| ticket_id | INT | ID do ticket no GLPI (NULL para reunião/evento) |
| tipo | ENUM | chamado/requisicao/reuniao/evento |
| concluido | TINYINT | 0=ativo, 1=concluído |

## Roadmap
### Fase 1 — MVP ✅
- Agenda semanal com FullCalendar
- Drag & drop do sidebar para agenda
- Integração GLPI: criar, editar, fechar, responder chamados
- Filtro por atendente
- Rotinas diárias automáticas

### Fase 2 — Next
- Relatórios de atendimento por técnico
- SLA e alertas de prazo
- Notificações push

### Fase 3 — Future
- App mobile
- Integração Google Calendar bidirecional

## Technical Notes
- `ticket_id = NULL` → evento interno (reunião/evento) — sem integração GLPI
- `ticket_id IS NOT NULL` → chamado/requisição — TODA operação deve sincronizar com GLPI
- O `eventos.php?action=list` corrige retroativamente tipos errados (tipo='evento' com ticket_id → tipo='chamado')
- `verificarAtrasados` roda em cadeia antes de `syncRotinas` (evita race condition)
- Eventos concluídos têm `editable: false` no FullCalendar (imutáveis no calendário)
