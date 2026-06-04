# Portal TI — Grupo Gmais
## Project Requirements Document

> **Objetivo:** Centralizar todas as ferramentas de TI em um único portal integrado ao GLPI.
> **Equipe:** 1 Encarregado + 2 Técnicos
> **Prazo:** 30 dias — 03/06/2026 → 03/07/2026
> **Repositório:** https://github.com/marcosbarrosdemiranda/portal-glpi

---

## Progresso Geral

| Módulo | Status | Conclusão |
|--------|--------|-----------|
| 📅 Agenda de Atendimentos | 🟡 Em andamento | ~80% |
| 🎫 Abertura de Chamado | 🟢 Concluído | 100% |
| 📋 Histórico de Chamados | 🟡 Em andamento | ~70% |
| 📊 Painel de Relatórios | 🟡 Em andamento | ~50% |
| 🖥️ Inventário | 🟡 Em andamento | ~75% |
| 📚 Área do Conhecimento | 🔴 Pendente | ~20% |
| 🔧 Infraestrutura | 🟡 Em andamento | ~60% |
| 🏭 Ferramentas ERP | 🟡 Em andamento | ~50% |
| 📁 Projetos | 🟡 Em andamento | ~75% |
| 👥 Equipe | 🟡 Em andamento | ~50% |
| 💰 Orçamento | 🟡 Em andamento | ~40% |
| 📄 Contratos | 🟡 Em andamento | ~50% |
| 🔑 Licenças de Software | 🟡 Em andamento | ~50% |
| 🔒 Cofre TI | 🟡 Em andamento | ~70% |

---

## 📅 Módulo 1 — Agenda de Atendimentos
> Agenda semanal com integração total ao GLPI. Técnicos agendam, arrastam e fecham chamados diretamente.
> **Prazo:** 10/06/2026

### Funcionalidades Core
- [x] Visualização semanal e mensal
- [x] Criar chamado direto na agenda (modal completo)
- [x] Arrastar chamados do sidebar para a grade
- [x] Drag & drop para mover entre datas/horários
- [x] Redimensionar evento para alterar duração
- [x] Filtro por atendente
- [x] Duração configurável (15min → 8h)
- [x] Eventos ocupam espaço visual correto na grade
- [x] Campos obrigatórios com validação (Entidade, Atendente, Requerente, Descrição)
- [x] Entidade-raiz bloqueada — só entidades filhas
- [x] Aliases visuais de entidades (Lj 001, Lj 010, etc.)
- [x] Rotinas diárias automáticas (sync com GLPI)
- [x] Eventos concluídos ficam verdes e bloqueados
- [x] Evento auto-atribuído ao criador

### Integração GLPI
- [x] Criar chamado → POST /Ticket
- [x] Editar chamado → PUT /Ticket
- [x] Arrastar do sidebar → atribuir técnico no GLPI
- [x] Responder → POST /ITILFollowup
- [x] Fechar → PUT status=6
- [x] Excluir da agenda → PUT status=1, remove técnico

### Anexos e Mídia
- [x] Upload de imagens na resposta
- [x] Colar imagem (Ctrl+V)
- [x] Preview de anexos no histórico do chamado
- [x] Lightbox para visualização em tamanho real

### Pendente
- [ ] Notificações de chamados próximos do vencimento
- [ ] Sincronização com Google Calendar (bidirecional)
- [ ] Exportar agenda como PDF/imagem

---

## 🎫 Módulo 2 — Abertura de Chamado
> Formulário completo de abertura de chamado com integração direta ao GLPI.
> **Prazo:** 10/06/2026

- [x] Modal de criação no portal
- [x] Seleção de entidade/loja (aliases visuais)
- [x] Seleção de atendente responsável
- [x] Categoria, origem e prioridade
- [x] Requerente obrigatório
- [x] Descrição obrigatória
- [x] Criação imediata no GLPI (POST /Ticket)
- [x] Feedback visual de sucesso/erro

### Pendente
- [ ] Abertura de chamado pelo próprio usuário (portal do requerente)
- [ ] Anexar arquivos na abertura

---

## 📋 Módulo 3 — Histórico de Chamados
> Visualização completa do histórico de um chamado: followups, anexos e status.
> **Prazo:** 10/06/2026

- [x] Listagem de chamados abertos
- [x] Detalhe do chamado (descrição, entidade, categoria, requerente)
- [x] Followups/acompanhamentos com autor e data
- [x] Preview de imagens anexadas
- [x] Download de anexos não-imagem
- [x] Status visual (aberto, em andamento, concluído)

### Pendente
- [ ] Filtros avançados (por data, status, técnico, loja)
- [ ] Busca por texto no histórico
- [ ] Paginação de chamados
- [ ] Exportação do histórico (PDF/Excel)
- [ ] Histórico de chamados do requerente

---

## 📊 Módulo 4 — Painel de Relatórios
> Indicadores de desempenho da equipe de TI com foco em SLA e produtividade.
> **Prazo:** 17/06/2026

- [x] Aba Monitor SLA (semáforo por urgência)
- [x] Chamados em atraso destacados em vermelho

### Pendente
- [ ] Relatório de atendimento por técnico (quantidade, tempo médio)
- [ ] Relatório por período (diário, semanal, mensal)
- [ ] Relatório por loja/entidade
- [ ] Gráfico de chamados abertos vs fechados
- [ ] Gráfico de distribuição por categoria
- [ ] Tempo médio de resolução (TMR)
- [ ] Exportação de relatório (PDF / Excel)
- [ ] Dashboard de KPIs na tela inicial

---

## 🖥️ Módulo 5 — Inventário
> Inventário de equipamentos da rede com dados detalhados via GLPI e status online/offline.
> **Prazo:** 17/06/2026

- [x] Listagem de todos os equipamentos
- [x] Status online/offline em tempo real (ping TCP/ICMP)
- [x] Detalhes: hardware, processador, memória, disco
- [x] Sistema operacional (versão, arquitetura, kernel)
- [x] Programas instalados (via GLPI)
- [x] IPs via NetworkPort → NetworkName → IPAddress

### Pendente
- [ ] Filtro por loja/entidade
- [ ] Filtro por tipo de equipamento
- [ ] Histórico de manutenções do equipamento
- [ ] Alertas de garantia próxima do vencimento
- [ ] QR Code por equipamento
- [ ] Exportação do inventário (Excel)

---

## 📚 Módulo 6 — Área do Conhecimento
> Base de conhecimento interna da equipe com artigos, procedimentos e tutoriais.
> **Prazo:** 01/07/2026

- [ ] Listagem de artigos por categoria
- [ ] Busca por palavra-chave
- [ ] Criação de artigo (título, conteúdo, categoria, tags)
- [ ] Edição e exclusão de artigos
- [ ] Integração com base de conhecimento do GLPI
- [ ] Visualização por técnico (quem criou, última atualização)
- [ ] Artigos favoritos
- [ ] Exportar artigo como PDF

---

## 🔧 Módulo 7 — Infraestrutura
> Acesso centralizado às ferramentas de infraestrutura de rede e servidores.
> **Prazo:** 01/07/2026

### Acesso Remoto
- [x] RDP — gera arquivo .rdp com hostname configurado
- [x] VNC — estrutura criada (portal + banco)
- [x] AnyDesk — link configurável
- [ ] VNC funcional (instalar noVNC + websockify no servidor)
- [ ] SSH via browser (xterm.js)

### Monitoramento
- [ ] Status dos servidores (uptime, CPU, memória)
- [ ] Alertas de equipamento offline
- [ ] Log de acessos remotos

### Gestão de Rede
- [x] Link para pfSense configurável
- [x] Link para VMware configurável
- [x] Link para Mikrotik configurável
- [x] Link para UniFi configurável
- [ ] Dashboard de status dos links configuráveis

---

## 🏭 Módulo 8 — Ferramentas ERP
> Acesso rápido às ferramentas de gestão do grupo (SAP, Totvs, etc.).
> **Prazo:** 01/07/2026

- [x] Cards configuráveis por URL
- [x] Agrupamento por categoria
- [ ] Definir ferramentas ERP usadas pelo Grupo Gmais
- [ ] Autenticação SSO (se aplicável)
- [ ] Favoritos por técnico

---

## 📁 Módulo 9 — Projetos
> Gestão de projetos de TI com visualização de timeline e progresso por módulo.
> **Prazo:** 24/06/2026

- [x] CRUD básico de projetos
- [x] Status (planejado, em andamento, concluído)
- [x] Portal lê arquivos `.md` do Obsidian automaticamente (`Docs/wiki/projects/`)
- [x] Parser PHP de markdown — extrai módulos, tarefas e cronograma
- [x] Timeline Gantt semanal com barra de "hoje" e cores por etapa
- [x] Progresso calculado em tempo real dos checkboxes do markdown
- [x] Módulos colapsáveis com lista de tarefas (✅ concluída / ⭕ pendente)
- [x] Badge Obsidian com link para o arquivo fonte e horário de leitura
- [x] Suporte a múltiplos projetos (nav por abas)

### Pendente
- [ ] Responsável e co-responsáveis por projeto (campo no markdown)
- [ ] Vinculação de chamados GLPI a projetos
- [ ] Filtro por módulo / status na view do portal
- [ ] Exportação do projeto como PDF

---

## 👥 Módulo 10 — Equipe
> Visão da equipe de TI com carga de trabalho e disponibilidade.
> **Prazo:** 24/06/2026

- [x] Listagem dos técnicos via GLPI API
- [x] Foto e informações básicas

### Pendente
- [ ] Chamados abertos por técnico (carga atual)
- [ ] Disponibilidade (férias, folga, afastamento)
- [ ] Metas de atendimento por técnico
- [ ] Histórico de desempenho

---

## 💰 Módulo 11 — Orçamento
> Controle de orçamento do departamento de TI.
> **Prazo:** 24/06/2026

- [x] CRUD básico de itens de orçamento

### Pendente
- [ ] Migrar de localStorage para banco MySQL
- [ ] Categorias de gasto (hardware, software, serviços, manutenção)
- [ ] Ano fiscal e período
- [ ] Gráfico de gastos por categoria
- [ ] Aprovação de gastos
- [ ] Exportação (PDF/Excel)

---

## 📄 Módulo 12 — Contratos
> Gestão de contratos de TI com alertas de vencimento.
> **Prazo:** 24/06/2026

- [x] CRUD de contratos
- [x] Alertas visuais de vencimento

### Pendente
- [ ] Migrar de localStorage para banco MySQL
- [ ] Upload do contrato (PDF)
- [ ] Notificação automática 30/60/90 dias antes do vencimento
- [ ] Histórico de renovações
- [ ] Vinculação com fornecedor

---

## 🔑 Módulo 13 — Licenças de Software
> Controle de licenças de software instaladas via GLPI.
> **Prazo:** 01/07/2026

- [x] Listagem de licenças via GLPI API

### Pendente
- [ ] Data de vencimento por licença
- [ ] Alertas de licença próxima do vencimento
- [ ] Controle de quantidade usada vs contratada
- [ ] Licenças adquiridas manualmente (fora do GLPI)
- [ ] Exportação do relatório de licenças

---

## 🔒 Módulo 14 — Cofre TI
> Cofre seguro para senhas, comandos, links e documentações críticas da TI.
> **Prazo:** 01/07/2026

- [x] CRUD completo com criptografia AES-256-CBC
- [x] Categorias (Senha, Comando, Documentação, Link, Outro)
- [x] Conteúdo mascarado na listagem
- [x] Reveal e copy individuais
- [x] Tags e notas

### Pendente
- [ ] Controle de acesso por nível (técnico x encarregado)
- [ ] Auditoria: quem acessou e quando
- [ ] Busca por tag e categoria
- [ ] Compartilhamento seguro de senha (link com expiração)
- [ ] Senha mestra para acesso ao cofre

---

## 🗓️ Cronograma — 30 dias

```
Semana 1 (03–10/06)   → Agenda (refinamentos) + Histórico de Chamados
Semana 2 (11–17/06)   → Relatórios + Inventário
Semana 3 (18–24/06)   → Projetos + Equipe + Orçamento + Contratos + Licenças
Semana 4 (25/06–01/07)→ Infraestrutura + Cofre + Conhecimento + ERP
Semana 5 (02–03/07)   → Testes, ajustes finais, documentação
```

---

## 📌 Regras do Projeto

- **GLPI é a fonte da verdade** — toda operação de chamado reflete no GLPI
- **Dados sensíveis nunca no repositório** — usar `agenda/config.php` (no .gitignore)
- **Código congelado após aprovação** — não alterar sem aviso
- **Commit a cada funcionalidade concluída** — mensagem descritiva
