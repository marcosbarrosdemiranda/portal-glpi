# Backlog — Tópicos do Projeto

> ✅ feito · 🔄 em andamento · 🔜 próximo · 🔮 futuro · ver [[index]].

## ✅ Fase 1 — MVP (concluída · 2026-06-12)

### Integração e descoberta
- [x] Estudar e mapear a [[Integracao-API-Solides|API Sólides/Tangerino]] (3 módulos, auth, endpoints)
- [x] Documentar a API em `tangerino-api-reference.md`
- [x] Validar token e extrair lojas (6) e colaboradores (~218 ativos)
- [x] Descobrir endpoint não documentado `/companies` (lojas com CNPJ)

### Banco de dados
- [x] Definir stack e escolher **MySQL/MariaDB** ([[ADR-001-Banco-MySQL]])
- [x] Instalar MariaDB 12.3 (serviço Windows) + database `ponto_gmais` + usuário `ponto`
- [x] Modelar [[Modelo-de-Dados|16 tabelas]] (`schema.sql` idempotente)

### Sincronização
- [x] Serviço [[Sincronizacao-de-Dados|tangerino-sync]] (Node + mysql2)
- [x] Modos full / inc / rápido com `lastUpdate` e upsert em lote
- [x] Espelhar batidas, ajustes, **resumo diário (HE)**, banco de horas, gestores, justificativas
- [x] Agendamento Task Scheduler: rápido (10 min) e horário (1 h)
- [x] Tratar pegadinhas (`showFired`, paginação repetida, vínculo de escala)

### Painel
- [x] API REST (Express) + [[Seguranca-e-Perfis|login JWT, bcrypt, perfis]]
- [x] Escopo por loja para GESTOR (forçado no backend, testado)
- [x] [[Dashboards-e-Relatorios|PWA responsivo]]: Hoje, Pontos, H. Extras, Ausências, Banco de Horas
- [x] Filtros dinâmicos data/loja/setor; auto-refresh
- [x] Criar usuários iniciais (ADMIN/RH/GESTOR)
- [x] Verificação visual e dos endpoints com dados reais

### Documentação
- [x] Vault Obsidian (este conjunto) seguindo o framework Antigravity

---

## 🔜 Fase 2 — Próximos

### Visualizações (pedido do usuário: "ver tudo dinamicamente")
- [x] **BI Horas Extras** réplica do Power BI (matriz, pizza por loja, barras por depto, top colaborador) — `bi.html`
- [ ] Departamento como **multi-seleção** (checkboxes, como no Power BI original)
- [ ] BI para outros indicadores (faltas, banco de horas, absenteísmo)
- [ ] Heatmap de presença por hora/dia
- [ ] Exportar visões do BI (PDF/imagem)

### Produção / acesso em rede
- [x] **Kit de deploy engatilhado** (`deploy\`): exportador de pacote + instaladores Linux (systemd + HTTPS/Caddy) e Windows Server
- [x] **Backup** automático do MySQL no PC (tarefa diária 22h, 30 dias de retenção)
- [x] Painel sobe sozinho no logon do PC (pasta Inicializar) + `Iniciar Painel.cmd` + atalho na área de trabalho
- [ ] Executar o deploy no servidor definitivo (quando contratado)
- [ ] **HTTPS** ativo (no Linux o script já faz com domínio; no Windows requer proxy reverso)
- [ ] Liberar porta 3000 no firewall do PC p/ acesso pela rede local (se quiser usar antes do servidor)
- [ ] Rotacionar `JWT_SECRET` e trocar senhas padrão

### Refinos funcionais
- [ ] "Sem registro" considerar a **escala** do colaborador (não marcar quem ainda não entrou)
- [ ] Alertas: faltas, batidas pendentes, HE acima de limite
- [ ] Exportações sob demanda (CSV/PDF) a partir do banco
- [ ] Tela de gestão de usuários no próprio painel (hoje via CLI)
- [ ] Vincular gestores reais às lojas e usar `gestor_subordinados`

---

## 🔮 Fase 3 — Futuro

- [ ] **App nativo** (React Native) sobre a mesma API REST
- [ ] Notificações push (gestor: equipe incompleta, pendências)
- [ ] Relatórios gerenciais / BI (séries históricas multi-mês)
- [ ] Escrita de volta na Sólides (lançar ajustes/abonos pela API) — hoje é só leitura
- [ ] Multi-empresa / multi-CNPJ se o grupo crescer

---

## 🧱 Dívida técnica / atenção

- [ ] Senhas e token ainda em `.env` em texto — mover para cofre/variáveis de ambiente do servidor
- [ ] Rate limiting de login é em memória (reinicia com o processo) — avaliar persistência
- [ ] Sem testes automatizados ainda
- [ ] `gestor_subordinados` carregado mas sem uso no painel (0 vínculos hoje)

Decisões em `Docs/wiki/decisions/` · histórico em `Docs/wiki/logs/`.
