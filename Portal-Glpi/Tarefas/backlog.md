# Backlog Ativo — Portal TI Grupo Gmais

> Tarefas priorizadas para as próximas sprints.
> Ver projeto completo em: [[../projects/portal-glpi-prd]]

---

## 📥 Demandas pendentes (registradas 2026-09-24)

### API Sólides (Ponto) — as duas demandas são da mesma ferramenta, no estilo back-gmais

Autenticação nas duas chamadas: header `X-Token-Saude: <token>`. O token fica no painel do Ponto → Saúde do sistema → Monitoramento externo (Mostrar/Copiar). Sem token ou token errado → 401. **Token não vai no git.**

O que o Ponto conta como anormalidade: sync com erro · falha parcial na API da Sólides · sync parado além do limite (30 min rápido, 2h horário) · WhatsApp com falha.

- [x] **1. Central de Alertas — alerta em tempo real da API Sólides** (feito 2026-09-24, webhook_solides.php + solides_config.php)
  - Mesmo padrão de ocorrência do back-gmais (🔔/✅/⏰)
  - Duas opções (decidir na spec):
    - Pull: portal consulta `GET https://ponto.grupogmais.com:7413/api/saude` → campos `status` (ok / alerta / falha) e `resumo` (uma linha)
    - Push: webhook já pronto no painel do Ponto — colar a URL do portal, marcar Ativo; o Ponto faz POST com o mesmo JSON a cada 5 min
  - Nos primeiros dias: até as 08:00 de 24/09 aparece "Sync horário: ainda sem execuções registradas" (some sozinho)
  - ⚠️ Tipo novo nasce com WhatsApp ativo — inserir `notif_whatsapp=0` ANTES de testar
- [x] **2. Chamado diário das 07:00 (#11366) — relatório da API Sólides** (feito 2026-09-24, solides_resumo_ajax.php + caixa na Agenda)
  - Mesma forma do resumo do back-gmais (base: `backup_lib.php`/`backup_resumo_texto`, `backup_resumo_ajax.php`, `agenda/index.php`/`abrirModalResposta`)
  - Fonte: `GET https://ponto.grupogmais.com:7413/api/saude/relatorio`
    - `resumo`: uma linha, ex. "Ontem (23/09): sem anormalidades · Hoje (24/09): 1 anormalidade(s), todas resolvidas"
    - `dias[]`: ontem e hoje; cada anormalidade tem `titulo`, `inicio`, `fim`, `resolvida` e `texto` pronto (ex. "Sync rápido: erro às 03:00 — resolvido às 03:10 · HTTP 500 em /punch/")
  - Nos primeiros dias: "ontem" vem como "sem histórico registrado" (registro começou 24/09 07:08); a partir de 25/09 vem completo

### Monitor de rede próprio (substitui o The Dude) — em andamento por etapas

Roteiro completo: `Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md`

- [ ] Etapa 1 — Equipamentos do inventário + chave "Monitorar" + config por grupo
- [ ] Etapa 2 — Motor de ping em paralelo, modo sombra (sem alertar)
- [ ] Etapa 3 — Virada: monitor passa a alimentar a Central de Alertas
- [ ] Etapa 4 — Mapa da rede no Inventário (loja → grupos → equipamentos com status, nome e IP)
- [ ] Etapa 5 — Notificação por grupo (WhatsApp, lembrete, aviso de reinício)
- [ ] Etapa 6 — Latência, renomear "The Dude" → "Monitor de rede"
- [ ] Etapa 7 — Remover tudo do Dude (portal, nomes internos, container)

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
