# Portal GLPI — Architecture

> **Nota (2026-10-19):** doc estava congelado em junho/2026 (MVP original — Agenda + RDP/Guacamole).
> Atualizado incrementalmente (Cenário C) pra refletir o sistema real: as seções abaixo com conteúdo
> original de junho foram preservadas; o que foi acrescentado/corrigido está marcado. Ver
> [[../Decisões/ADR-005-architecture-sync-2026-10|ADR-005]].

## Overview
Portal web interno de TI do Grupo Gmais, integrado ao GLPI. Centraliza atendimento (agenda +
chamados), monitoramento de infraestrutura (rede, backup, pfSense, Sólides/Ponto, SEFAZ via Central
de Alertas), gestão financeira de TI (orçamento/despesas por projeto), acessos remotos (RDP/VNC/AnyDesk)
e notificações via WhatsApp — tudo com sincronização bidirecional com a API REST do GLPI onde aplicável.

## Tech Stack
| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | HTML + Bootstrap 5 + FullCalendar + JS vanilla (sem build step) | 5.3.3 / 6.1.11 |
| Backend | PHP, sem framework | 8.x |
| Language | PHP + JavaScript (vanilla) | — |
| ORM | — (PDO direto, sem ORM) | — |
| Database | MySQL/MariaDB — banco do GLPI (`glpi2`) + tabelas próprias (`portal_*`, `glpi_plugin_agenda_events`) | MariaDB 10.4/11 |
| Remote Access | Apache Guacamole (RDP/VNC proxy HTML5) | 1.5.x |
| Mensageria | Evolution API (WhatsApp self-hosted via Baileys/QR — nunca Cloud API Meta) | — |
| Storage | Tabela `glpi_plugin_agenda_events`, `portal_rdp_maquinas`, `portal_vault`, `portal_acessos`, dezenas de `portal_*` próprias | — |
| Container | Docker Desktop (Windows host) — stack migrada de XAMPP em 2026-08/09 | Docker 29.x / Compose v5.x |
| Infra | Servidor Windows `192.168.1.198` (`glpi-server`/`Backup-Arquifunc`), containers `glpi-web`/`glpi-nginx`/`glpi-db`/`glpi-cron`/`portal-wpp-worker`/`monitor-db-worker`/`portal-impressoras-worker`/`evolution-api` | — |
| CI/CD | Nenhum — deploy manual via `scp` + `.new`/`php -l`/`Move-Item` (nunca `git pull` no container) | — |

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
│   └── config.php            # Credenciais da API GLPI + Guacamole
├── rdp_central.php           # Central RDP com CRUD + acordeão + sync Guacamole
├── guacamole_conectar.php    # Wrapper iframe com top bar para acesso Guacamole
├── limpar_rdp.php            # Limpeza total das máquinas + conexões Guacamole
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
┌─────────────────────────────────────────────────────────────────┐
│                        Browser                                  │
│  ┌──────────┐  ┌──────────────────────────────────────────────┐│
│  │ Sidebar  │  │  Central RDP (rdp_central.php)               ││
│  │ Chamados │  │  ┌────────────┐ ┌─────────────────────────┐  ││
│  │  GLPI    │  │  │ Acordeão  │ │ Guacamole iframe        │  ││
│  │          │  │  │ categorias│ │ (guacamole_conectar.php) │  ││
│  │          │  │  └────────────┘ └─────────────────────────┘  ││
│  └──────────┘  └──────────────────────────────────────────────┘│
│                ┌──────────────────────────────────────────────┐│
│                │         Agenda (FullCalendar v6)             ││
│                └──────────────────────────────────────────────┘│
└────────────────────────────────────────────────────────────────┘
         │                    │               │
         ▼                    ▼               ▼
┌────────────────────────────────────────────────────────────────┐
│                    PHP Backend                                  │
│                                                                │
│  ┌─────────────────┐     ┌──────────────────────┐  ┌─────────┐│
│  │  Agenda DB      │     │    GLPI API REST     │  │Guacamole││
│  │  (eventos.php)  │     │                      │  │API REST ││
│  │                 │     │  criar_ticket.php    │  │         ││
│  │  glpi_plugin_   │     │  atualizar_ticket.php│  │ POST    ││
│  │  agenda_events  │     │  atribuir_ticket.php │  │   /token││
│  │                 │     │  responder_ticket.php│  │ GET/POST││
│  │  portal_rdp_    │     │  fechar_ticket.php   │  │  /conn  ││
│  │  maquinas       │     │  resetar_ticket.php  │  │ PUT/DEL ││
│  │                 │     │  ticket_descricao.php│  └─────────┘│
│  │  - guac_id ────────── │  tickets.php         │  │         ││
│  │                 │     │  sync_rotinas_ajax   │  │         ││
│  └─────────────────┘     └──────────────────────┘  └─────────┘│
└────────────────────────────────┬───────────────────────────────┘
                                 │
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                   ▼
  ┌────────────────────┐ ┌────────────────┐ ┌──────────────┐
  │   GLPI Server      │ │ Apache Guacamole│ │ Máquinas RDP │
  │   /apirest.php/    │ │ :8080/guacamole│ │ (RDP nativo) │
  │   /Ticket          │ │                │ │              │
  │   /ITILFollowup    │ │  Proxy HTML5   │ │ 192.168.1.x  │
  │   /Entity          │ │  RDP→WebSocket │ │ Porta 3389   │
  └────────────────────┘ └────────────────┘ └──────────────┘
```

### Domain Modules (acrescentado 2026-10-19 — inventário real, não só o MVP de junho)

| Módulo | Arquivos principais | Responsabilidade |
|--------|---------------------|-------------------|
| Agenda de Atendimento | `agenda/*.php` | Rotina diária técnica, sync bidirecional com chamados GLPI (ver seção original abaixo) |
| Central de Alertas + Monitor de Rede | `alertas*.php`, `monitor_*.php`, `webhook_backup.php`, `webhook_solides.php`, `solides_*.php`, `sefaz_lib.php`, `pfsense_*.php`, `unifi_client.php` | Motor de ocorrência (🔔/✅/⏰) único para rede própria (substituiu o The Dude), backup (back-gmais), Ponto/Sólides, SEFAZ CT-e, saúde pfSense e antenas UniFi |
| Orçamento & Despesas (BI) | `orcamento.php`, `despesas.php`, `agenda/orcamento_db.php`, `agenda/despesas_db.php`, `agenda/projetos_db.php`, `relatorios_projetos.php` | Controle financeiro de TI com catálogo de categoria compartilhado e dimensão Projeto (Projeto→Categoria→Loja→Equipamento) |
| Relatórios / BI | `relatorios*.php` | Painéis de Previsto×Realizado, Monitor SLA, equipamentos, impressões |
| Inventário | `inventario*.php`, `impressoras_lib.php` | Inventário de máquinas via API GLPI, detalhes, impressoras, mapa de rede |
| Cofre de TI | `cofre.php`, `vault_crypto.php` | Senhas/comandos/documentação criptografados (AES-256) |
| Central de Acessos (RDP/VNC/AnyDesk) | `acessos.php`, `rdp_central.php`, `vnc*.php`, `anydesk_central.php` | Acesso remoto via Guacamole com grupos dinâmicos e sync automático |
| Projetos de TI | `projetos.php`, `sync_projetos.php` | Gestão de projetos com export .md/PDF |
| Equipe / Usuários / Entidades | `equipe*.php`, `usuarios.php`, `perfis.php`, `entidade_alias.php`, `gerenciar_entidades.php` | Perfis do portal (admin/portal/ouvinte), gabarito de entidades/lojas |
| Contratos & Licenças | `contratos.php`, `licencas.php` | Vencimentos e alertas de contratos/licenças |
| WhatsApp (Evolution API) | `wpp/*.php` | Notificações de grupo + chatbot (Fluxo A vinculado em produção; Fluxo B pendente) |
| Área do Conhecimento | `conhecimento.php` | Base de conhecimento interna (CRUD ainda incompleto) |
| Chamados / Histórico | `chamado*.php`, `historico.php`, `meus_chamados.php`, `pendencias.php` | Abertura/histórico/pendências de chamados fora da Agenda |
| Monitor DB Central | `db_central_*.php`, `agenda/postgres_status.php`, `agenda/monitor_db_worker.php` | Monitoramento read-only via SSH do PostgreSQL da Central |

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

> Atualizado 2026-10-19. As "Fase 1/2/3" originais de junho (MVP da Agenda) ficaram pequenas demais
> pra descrever o sistema atual — preservadas abaixo como **Fase 0 (histórico)**; a partir da Fase 1
> o roadmap passa a cobrir todos os módulos reais.

### Fase 0 — MVP original da Agenda ✅ (histórico, junho/2026)
- Agenda semanal com FullCalendar
- Drag & drop do sidebar para agenda
- Integração GLPI: criar, editar, fechar, responder chamados
- Filtro por atendente
- Rotinas diárias automáticas

### Fase 1 — Monitoramento unificado ✅ concluído
- Monitor de Rede próprio substituindo o The Dude (7 etapas, concluído 2026-10)
- Central de Alertas fundida ao Monitor de Rede (catálogo único, aba em `monitor_dispositivos.php`)
- Backup código+banco GLPI, back-gmais, Sólides/Ponto, SEFAZ CT-e, saúde pfSense, antenas UniFi — todos via o mesmo motor de ocorrência (🔔/✅/⏰)
- Removidos tipos de alerta duplicados (`monitor_sem_contato`, `monitor_service`) por já estarem cobertos por `monitor_device`
- Fix de falso-positivo em horário de silêncio (PR #5)

### Fase 2 — Gestão financeira de TI ✅ concluído / 🔶 em validação
- Orçamento + Despesas com CRUD manual, filtros, upload de NF, PDF
- Relatórios BI (Previsto×Realizado, Orçamento, Despesas) em `relatorios.php`
- Categoria compartilhada bidirecional entre Orçamento e Despesas
- 🔶 Dimensão Projeto (Projeto→Categoria→Loja→Equipamento) — deployado, **PR #6 aberto sem merge**, pendente só validação visual do usuário no navegador

### Fase 3 — Em andamento / próximo
- [ ] WhatsApp — Fluxo B / pendência para número sem vínculo (Fluxo A já em produção)
- [ ] 2FA para acesso externo (login fora da rede local/VPN)
- [ ] Resumo PostgreSQL automático na Agenda (bloqueado — falta credencial SSH)
- [ ] Card de Instaladores da Equipe TI (Checklist, Backup, Radio, Sorteador Gmais...)
- [ ] Obsidian compartilhado em rede pra todos os técnicos (módulo Projetos)
- [ ] Alerta de banda alta, conflito de IP/loop e CPU/memória MikroTik no Monitor de Rede
- [ ] Vincular chamado a uma ocorrência de alerta sem mudar a auto-resolução
- [ ] VNC funcional de verdade — noVNC + websockify ainda não instalados (pendente desde o MVP, ver nota técnica)

### Fase 4 — Backlog / baixa prioridade
- [ ] Alerta de nota presa no transmissor Flex
- [ ] Sitef Express (só anotado, sem desenho)
- [ ] Relatório por técnico (quantidade de chamados, tempo médio)
- [ ] Notificações push de chamados próximos
- [ ] Sincronização Google Calendar bidirecional
- [ ] SSH via browser (xterm.js)
- [ ] Migrar Orçamento/Contratos de localStorage para MySQL (onde ainda não migrado)
- [ ] Integrar o subsistema de Reconhecimento Facial (hoje standalone, Python + Evolution API) ao Portal TI

### Débito técnico conhecido (não bloqueante)
- `g.max_horas` ausente no monitor — "ligado muito tempo" sempre vazio
- `trim(null)` em dispositivo sem IP gera log sujo em `monitor_lib.php:507` (N+1, sem impacto funcional)

## Technical Notes
- `ticket_id = NULL` → evento interno (reunião/evento) — sem integração GLPI
- `ticket_id IS NOT NULL` → chamado/requisição — TODA operação deve sincronizar com GLPI
- O `eventos.php?action=list` corrige retroativamente tipos errados (tipo='evento' com ticket_id → tipo='chamado')
- `verificarAtrasados` roda em cadeia antes de `syncRotinas` (evita race condition)
- Eventos concluídos têm `editable: false` no FullCalendar (imutáveis no calendário)
