# Backlog Ativo — Portal TI Grupo Gmais

> Tarefas priorizadas para as próximas sprints.
> Ver cronograma macro em: [[../Arquitetura/ARCHITECTURE|ARCHITECTURE.md → Roadmap]]
> Sincronizado 2026-10-19 — estava parado desde 24-25/09, faltava ~1 mês de trabalho real.

---

## 📥 API Sólides (Ponto) — concluído

- [x] **1. Central de Alertas — alerta em tempo real da API Sólides** (feito 2026-09-24, `webhook_solides.php` + `solides_config.php`)
- [x] **2. Chamado diário das 07:00 (#11366) — relatório da API Sólides** (feito 2026-09-24, `solides_resumo_ajax.php` + caixa na Agenda)

## 📡 Monitor de rede próprio (substituiu o The Dude) — concluído

Roteiro completo: `Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md`

- [x] Etapa 1 — Equipamentos do inventário + chave "Monitorar" + config por grupo
- [x] Etapa 2 — Motor de ping em paralelo
- [x] Etapa 3 — Virada: monitor alimenta a Central de Alertas
- [x] Etapa 4 — Mapa da rede no Inventário (`inventario_mapa_rede.php`, `monitor_mapa_api.php`)
- [x] Etapa 5 — Notificação por grupo (WhatsApp, lembrete, aviso de reinício)
- [x] Etapa 6 — Latência; renomeado "The Dude" → "Monitor de rede"
- [x] Etapa 7 — Removido tudo do Dude (`dude_lib.php`/`dude_config.php` apagados do servidor, sem link morto)
- [x] Central de Alertas fundida ao Monitor de Rede — catálogo único em `monitor_dispositivos.php`, `alertas_config.php` virou redirect
- [x] Removidos tipos de alerta duplicados (`monitor_sem_contato`, `monitor_service`) — já cobertos por `monitor_device`
- [x] Fix de falso-positivo de ✅ ao bater horário de silêncio (PR #5)

## 💰 Orçamento & Despesas (BI) — concluído / 🔶 em validação

- [x] CRUD manual, filtros avançados e export PDF em Despesas
- [x] Upload de NF com pré-visualização
- [x] Relatórios BI (Previsto×Realizado, Orçamento, Despesas) em `relatorios.php`
- [x] Categoria compartilhada bidirecional Orçamento↔Despesas
- [x] Ver detalhes + suspender item sem sumir da lista
- [x] Salvar/excluir/suspender item sem reload de página
- [x] Migrado para MySQL (`agenda/orcamento_db.php`, `agenda/despesas_db.php`)
- [ ] 🔶 **Dimensão Projeto (Projeto→Categoria→Loja→Equipamento)** — deployado, **PR #6 aberto sem merge**, falta só validação visual do usuário no navegador

## 📅 Agenda — Pré-chamado (rotinas GLPI) — 🔶 em validação

- [ ] 🔶 **Novo tipo de evento "Pré-chamado"** — placeholder na grade pra uma ocorrência futura de `glpi_ticketrecurrents` (Opção A nativa do GLPI, não a recorrência interna da Agenda); converte sozinho quando o GLPI cria o chamado real, ou manualmente via botão "Converter em chamado" pra pré-chamados sem vínculo de recorrência — deployado, **PR #7 aberto sem merge**, falta só validação visual do usuário no navegador

## 🔗 Integrações de monitoramento — concluído

- [x] Backup código+banco GLPI (webhook + card de status)
- [x] SEFAZ MS (CT-e) por serviço — scraping, cache 5min
- [x] pfSense — saúde (mem/carga, pfBlockerNG, Snort) via HTTPS scraping
- [x] Agente GLPI cadastrando Docker como PC — corrigido no card "Total de máquinas"

---

## 🔥 Alta Prioridade

- [ ] WhatsApp — Fluxo B / pendência para número sem vínculo (Fluxo A já em produção)
- [ ] VNC funcional de verdade — noVNC + websockify ainda não instalados (pendente desde o MVP)
- [ ] 2FA para acesso externo (login fora da rede local/VPN)
- [ ] Área do Conhecimento — CRUD completo
- [ ] Resumo PostgreSQL automático na Agenda (bloqueado — falta credencial SSH)

## 🟡 Média Prioridade

- [ ] Card de Instaladores da Equipe TI (Checklist, Backup, Radio, Sorteador Gmais...)
- [ ] Obsidian compartilhado em rede pra todos os técnicos (módulo Projetos)
- [ ] Alerta de banda alta no Monitor de Rede
- [ ] Alerta de conflito de IP / loop no Monitor de Rede
- [ ] Alerta de CPU/memória alta via MikroTik no Monitor de Rede
- [ ] Vincular chamado a uma ocorrência de alerta sem mudar a auto-resolução
- [ ] Migrar Contratos para banco MySQL (Orçamento já migrado)
- [ ] Cofre TI — controle de acesso por nível de usuário
- [ ] Inventário — filtro por loja e tipo de equipamento

## 🟢 Baixa Prioridade

- [ ] Alerta de nota presa no transmissor Flex
- [ ] Sitef Express (só anotado, sem desenho)
- [ ] Relatório por técnico (quantidade de chamados, tempo médio)
- [ ] Notificações push de chamados próximos
- [ ] Sincronização Google Calendar bidirecional
- [ ] SSH via browser (xterm.js)
- [ ] Dashboard de KPIs na tela inicial
- [ ] Integrar o subsistema de Reconhecimento Facial (hoje standalone, Python + Evolution API) ao Portal TI

## 🧹 Débito técnico conhecido (não bloqueante)

- [ ] `g.max_horas` ausente no monitor — "ligado muito tempo" sempre vazio
- [ ] `trim(null)` em dispositivo sem IP gera log sujo em `monitor_lib.php:507` (N+1, sem impacto funcional)

## 🚫 Fechado / não investigável

- back-gmais não notifica erro de 2 políticas — causa é no back-gmais (fora do portal), não investigável sem o repo Go
- Ponto (Sólides) falha de conexão porta 7413 — fechado 2026-10-13 como transitório, sem causa encontrada

---

## ✅ Concluído Recentemente (resumo — ver [[../Arquitetura/ARCHITECTURE|ARCHITECTURE.md → Roadmap]] para o detalhe completo)

- [x] Monitor de Rede próprio (7 etapas) substituindo o The Dude
- [x] Central de Alertas fundida ao Monitor de Rede
- [x] Orçamento/Despesas com BI e dimensão Projeto (PR #6, pendente merge)
- [x] Agenda — Pré-chamado como placeholder de rotinas GLPI (PR #7, pendente merge)
- [x] SEFAZ, Sólides/Ponto, pfSense, backup — todos via Central de Alertas
- [x] Grupos dinâmicos RDP/VNC + sync automático Guacamole
- [x] Logout por inatividade + perfil ouvinte na Agenda
- [x] Recorrência semanal + reunião com co-atendentes na Agenda
- [x] Excluir chamado do histórico (lixeira)
- [x] Duração livre de eventos na agenda (15min → 8h)
- [x] Visual correto de eventos longos na grade
- [x] Validação obrigatória no modal (Entidade, Atendente, Requerente, Descrição)
- [x] Gabarito visual de entidades (Lj 001, Lj 010, etc.)
- [x] Preview de anexos do histórico (filesystem direto)
- [x] Repositório Git publicado no GitHub
- [x] Obsidian configurado como wiki do projeto
