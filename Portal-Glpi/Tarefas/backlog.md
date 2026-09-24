# Backlog Ativo — Portal TI Grupo Gmais

> Tarefas priorizadas para as próximas sprints.
> Ver projeto completo em: [[../projects/portal-glpi-prd]]

---

## 📥 Demandas pendentes (registradas 2026-09-24)

- [ ] **Central de Alertas — monitorar a API Sólides via webhook**
  - Aplicação nossa (projeto API Solides, roda na mesma VM do Dude — 192.168.1.246)
  - Seguir o padrão dos tipos já existentes (back-gmais, The Dude): webhook → ocorrência 🔔/✅/⏰
  - A definir na spec: quais eventos a API envia (queda, erro de sync, sem contato) e se é push (webhook) ou precisa de heartbeat
  - ⚠️ Tipo novo nasce com WhatsApp ativo — inserir `notif_whatsapp=0` ANTES de testar
- [ ] **Chamado diário #11366 — "Backup, Relatórios e Banco de Dados - Rotina Diária"**
  - Colocar o relatório automático igual ao do back-gmais (resumo de ontem por servidor, ✅/❌, tamanho, arquivos)
  - Base existente: `backup_lib.php` (`backup_resumo_texto`), `backup_resumo_ajax.php`, `agenda/index.php` (`abrirModalResposta`)
  - A definir na spec: o resumo hoje só pré-preenche a resposta no card da Agenda — confirmar se #11366 precisa receber o texto no próprio chamado GLPI (followup) e se entra também a parte de Banco de Dados (PostgreSQL, ainda bloqueado por acesso SSH)

## 🔥 Alta Prioridade

- [ ] VNC funcional — instalar noVNC + websockify no servidor XAMPP
- [ ] Relatório por técnico (quantidade de chamados, tempo médio)
- [ ] Filtros avançados no histórico de chamados
- [ ] Área do Conhecimento — CRUD completo
- [ ] Migrar Projetos de localStorage para banco MySQL

## 🟡 Média Prioridade

- [ ] Relatório por período e por loja
- [ ] Alertas de vencimento — contratos e licenças (notificação automática)
- [ ] Inventário — filtro por loja e tipo de equipamento
- [ ] Cofre TI — controle de acesso por nível de usuário
- [ ] Migrar Orçamento e Contratos para banco MySQL

## 🟢 Baixa Prioridade

- [ ] Exportação PDF/Excel em todos os módulos
- [ ] Notificações push de chamados próximos
- [ ] Sincronização Google Calendar bidirecional
- [ ] Dashboard de KPIs na tela inicial
- [ ] SSH via browser (xterm.js)

---

## ✅ Concluído Recentemente

- [x] Duração livre de eventos na agenda (15min → 8h)
- [x] Visual correto de eventos longos na grade
- [x] Validação obrigatória no modal (Entidade, Atendente, Requerente, Descrição)
- [x] Gabarito visual de entidades (Lj 001, Lj 010, etc.)
- [x] Preview de anexos do histórico (filesystem direto)
- [x] Repositório Git publicado no GitHub
- [x] Obsidian configurado como wiki do projeto
