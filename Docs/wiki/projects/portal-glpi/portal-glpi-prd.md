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
| 📋 Histórico de Chamados | 🟢 Concluído | 100% |
| 📊 Painel de Relatórios | 🟡 Em andamento | ~50% |
| 🖥️ Inventário | 🟢 Quase concluído | ~95% |
| 📚 Área do Conhecimento | 🔴 Pendente | ~20% |
| 🔧 Infraestrutura | 🟢 Concluído | 100% |
| 👁️ Central VNC | 🟢 Concluído | 100% |
| 📂 Grupos Dinâmicos (RDP/VNC) | 🟢 Concluído | 100% |
| 🖥️ Central AnyDesk | 🟢 Concluído | 100% |
| 🏭 Ferramentas ERP | 🟡 Em andamento | ~50% |
| 📁 Projetos | 🟡 Em andamento | ~80% |
| 👥 Equipe | 🟡 Em andamento | ~50% |
| 💰 Orçamento | 🟡 Em andamento | ~40% |
| 📄 Contratos | 🟡 Em andamento | ~50% |
| 🔑 Licenças de Software | 🟡 Em andamento | ~50% |
| 🔒 Cofre TI | ✅ Concluído | 100% |
| 📱 Acesso Mobile | 🔴 Pendente | 0% |

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

### 🚀 Futuras Inovações — Canal WhatsApp
> **Voltar a ter** o WhatsApp como canal (já existiu; a infra Evolution API já roda em container).
> Dois lados: entrada (abrir chamado) e saída (disparos).

**Entrada — abrir chamado pelo WhatsApp**
- [ ] Número dedicado do TI; usuário manda mensagem → bot cria o chamado no GLPI
- [ ] Identificar o requerente pelo telefone (cruzar com `glpi_users` / cadastro por loja)
- [ ] Fluxo guiado: loja → tipo/categoria → descrição → anexos (foto do problema)
- [ ] Aceitar foto/áudio/documento como anexo do chamado
- [ ] Responder no WhatsApp com o nº do chamado aberto
- [ ] Acompanhamento: usuário manda "status 10830" → bot responde a situação
- [ ] Followup: resposta do técnico no GLPI chega como mensagem pro requerente

**Saída — disparos**
- [ ] **Grupo TI (canal central):** recebe TUDO —
      • todo chamado aberto (qualquer canal): nº, loja, requerente, categoria, resumo, link
      • todos os alertas do inventário/monitoramento (blocos 1, 5, 14-16): disco cheio, falha de HW,
        máquina/serviço fora, HD de DVR falhando, temperatura de câmara fria, device de rede caiu, etc.
      • chamado automático gerado a partir de alerta (bloco 5) também avisa no grupo
- [ ] **Individual pro atendente:** quando o chamado é atribuído a um técnico, ele recebe DM
      no WhatsApp com os dados + link
- [ ] Notificar o **requerente** em cada mudança do chamado (atribuído, respondido, resolvido, fechado)
- [ ] Disparo em massa (aviso de manutenção programada, instabilidade, comunicado) por loja/grupo
- [ ] Lembrete de chamado parado / SLA estourando pro técnico responsável
- [ ] Resumo diário pro encarregado (abertos, fechados, pendências)
- [ ] Config de quem/qual grupo recebe cada tipo de disparo (mapa telefone/grupo ↔ evento)

**Infra**
- [ ] Reusar Evolution API (`evolution_api` / `evolution_postgres` já em container)
- [ ] Endpoint de webhook no portal recebendo mensagens do Evolution
- [ ] Tabela de sessões de conversa (estado do fluxo por telefone) + log de disparos
- [ ] Painel de disparos no portal (enviar, agendar, ver entregues/lidos)

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
- [x] Filtros por status, tipo e busca textual
- [x] Filtros por data e loja/entidade
- [x] Exportação do histórico (CSV)
- [x] Paginação (200 por página)
- [x] Histórico de chamados do requerente (meus_chamados.php com filtros, paginação e CSV)

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
- [x] Balanças — servidores MGV 6 com CRUD, sync SQL Server + Firebird, cards com status online/offline, firmware e carga
- [x] Filtro por loja/entidade (chips) e por tipo (subcategorias/abas)
- [x] Cards por categoria: Celulares (3 subgrupos), PCs Retaguarda, Notebooks, PDVs, Servidores/VMs, + 13 cards de periféricos
- [x] Campos personalizados por card (ex: Cargo, Departamento nos Celulares)
- [x] Histórico de chamados por equipamento (glpi_items_tickets) — no Inventário, no chamado e na Agenda
- [x] Relatório "Histórico de chamados por equipamento" no Painel BI (filtro por categoria e loja)
- [x] Botão "ver configuração completa" (CPU/RAM/disco/volumes/rede) nos PCs/PDVs
- [x] Marca máquina sem reportar inventário ao GLPI há +7 dias (badge + filtro)
- [x] Baixa/desativação de equipamento com motivo + data; view "Baixados"; PDF por card

### Pendente
- [ ] Alertas de garantia próxima do vencimento
- [ ] QR Code por equipamento
- [ ] Exportação do inventário (Excel)
- [ ] SMART / saúde do disco quando o GLPI coletar

### 🚀 Futuras Inovações — Gestão da Frota
> Aproveitar os dados de inventário do GLPI (já coletados por 122 agentes) para
> transformar o Inventário de um cadastro passivo numa ferramenta de gestão.

**1. Monitoramento e alertas (saúde do parque)**
- [ ] Alerta de disco quase cheio (volume > 90 %) — por máquina e agregado por loja
- [ ] Alerta de máquina sem reportar inventário há +X dias (evoluir o badge atual para notificação/e-mail)
- [ ] Alerta de configuração abaixo do mínimo para a função (ex: PDV com < 4 GB de RAM, retaguarda com HD e não SSD)
- [ ] Alerta de SO fora de suporte (Windows 10 EOL, Windows 7, etc.)
- [ ] Alerta de equipamento acima da vida útil da categoria
- [ ] Painel único de alertas do parque + histórico

**1b. Status ligado/desligado na própria listagem** ✅ FEITO (commit `b0a8594`)
- [x] Bolinha por equipamento na lista de PCs/PDVs: 🟢 ligado / 🔴 desligado / cinza sem IP (ping.php, TCP 445/3389/135/139)
- [x] Botão "Verificar agora" — re-testa tudo na hora (8 em paralelo, escalonado)
- [x] Contador no topo: X ligados / Y desligados / sem IP
- [x] Também na visão de servidores/VMs
- [x] ICMP funcionando (iputils-ping no Dockerfile; ICMP primeiro, TCP fallback) — commit `5a9e18c`
- [ ] Contador quebrado por loja (hoje é geral)

**2. KPIs de Inventário (painel próprio no BI)**
- [ ] Saúde da coleta: % de máquinas reportando / atrasadas / nunca reportaram
- [ ] Distribuição da frota por idade, por SO, por categoria, por loja
- [ ] Nº de máquinas candidatas a troca ou upgrade (e custo estimado)
- [ ] Evolução mensal (entradas, baixas, upgrades)
- [ ] Ranking dos equipamentos mais problemáticos (cruzar com histórico de chamados)

**3. Idade do equipamento**
- [ ] Calcular idade por data de compra / 1ª aparição no inventário / ano do modelo
- [ ] Vida útil de referência por categoria (ex: PDV 5 anos, retaguarda 4, notebook 3, servidor 6)
- [ ] Coluna "idade" e "situação" (novo / ok / renovar / vencido) na listagem
- [ ] Ranking dos mais velhos por loja

**4. IA — avaliação de configuração e plano de renovação**
- [ ] Analisar a config de cada PC vs. o papel dele (PDV, retaguarda, servidor)
- [ ] Sugerir o que atualizar (RAM, SSD, SO) e a prioridade de cada um
- [ ] Estimar impacto e custo aproximado de cada upgrade
- [ ] Gerar relatório executivo "estado da frota" (para diretoria): o que está ok,
      o que precisa de atenção, plano de investimento sugerido
- [ ] Chat: "quais PCs da Lj 030 precisam de upgrade?" respondido com base no inventário

**5. Chamado automático a partir de alerta**
> Quando um alerta é gerado, abrir um chamado no GLPI sozinho (com regra por tipo de alerta:
> abre / só notifica / abre se persistir X dias), evitando duplicado para o mesmo equipamento+problema.
- [ ] Disco cheio → chamado "Disco cheio em <máquina> (<loja>)" com categoria e prioridade
- [ ] Falha de hardware detectada comparando inventários (ex: caiu de 2 para 1 pente de RAM,
      sumiu um disco, GPU trocou) → chamado "Possível falha de hardware em <máquina>"
- [ ] Máquina que deveria ficar ligada 24h (servidor, PDV de plantão, câmera/DVR) desligando
      sem motivo → chamado + registro de quantas vezes/quando caiu
- [ ] SO fora de suporte / config abaixo do mínimo → chamado de planejamento (baixa prioridade)
- [ ] Marcar no equipamento quais alertas geram chamado e qual a regra (configurável por card/categoria)
- [ ] Fechar/atualizar o chamado automático quando o alerta se resolve sozinho (disco liberou, etc.)

**6. Área de Análise / Relatórios por Equipamento**
> Além dos alertas, uma aba dentro de cada equipamento só para consultar os dados e a evolução dele.
- [ ] Aba "Análise" no detalhe do equipamento: linha do tempo de mudanças (RAM, disco, SO, software, IP)
- [ ] Uso do disco ao longo do tempo (gráfico) — prever quando enche
- [ ] Histórico de uptime / quedas / reinícios
- [ ] Software instalado/removido com data
- [ ] Comparar este equipamento com a média da categoria (está abaixo? acima?)
- [ ] Botão "gerar relatório PDF do equipamento" (ficha técnica + histórico + chamados)
- [ ] KPIs no topo do detalhe: idade, saúde (disco/RAM/SO), nº de chamados, dias sem reportar

**7. Compliance de software e licenças**
- [ ] Inventário de todo software instalado na frota, com busca ("quem tem X instalado?")
- [ ] Software não autorizado (jogos, torrent, acesso remoto extra tipo RustDesk/TeamViewer free em uso comercial)
- [ ] Contagem real de licenças em uso vs. contratado (Office, cliente ERP, antivírus) — alimenta o Módulo 13
- [ ] Apps obrigatórios faltando por função (PC sem antivírus, PDV sem software da balança/PDV, retaguarda sem cliente ERP)
- [ ] Software fim de vida (Java/Chrome/.NET antigos)

**8. Segurança — postura da frota**
- [ ] Antivírus: presente + ligado + assinatura atualizada (por máquina e por loja)
- [ ] Windows Update: máquinas sem patch crítico há +X dias
- [ ] Firewall ligado/desligado
- [ ] Auditoria de contas de admin local (quem tem admin onde)
- [ ] BitLocker / criptografia de disco
- [ ] USB de armazenamento conectado em PDV (vetor de malware / vazamento)
- [ ] Múltiplas ferramentas de acesso remoto num mesmo PC (risco)

**9. Padronização e provisionamento**
- [ ] Nome fora do padrão (ex: PDV### esperado, nome aleatório)
- [ ] PC fora do domínio que deveria estar
- [ ] Fuso horário / idioma errado
- [ ] Monitores: quantidade, tamanhos, quais PDVs têm monitor duplo

**10. Detecção de mudança / furto de peça**
- [ ] SSD trocado por HD entre dois PDVs; disco que sumiu
- [ ] RAM "andou" (2 pentes → 1)
- [ ] Monitor movido entre lojas (rastreio por serial)
- [ ] Aparelho novo inesperado na rede

**11. Garantia, nota fiscal e contabilidade**
- [ ] Vincular serial → NF / fornecedor / data de compra (campos de Contrato do GLPI)
- [ ] Garantias vencendo nos próximos 90 dias
- [ ] Depreciação para contabilidade

**12. Priorização de investimento por loja**
- [ ] Ranking das lojas com o parque mais velho / fraco → onde investir primeiro
- [ ] Relatório "estado da frota" por loja num documento só

**13. Energia e custo operacional**
- [ ] Estimativa de consumo de energia da frota (PCs antigos gastam mais)
- [ ] Máquinas ligadas 24h que não precisam

**14. Inventário de Redes — integração com o The Dude**
> Decisão do usuário (set/2026): **manter o The Dude standalone (Windows, v4.0 beta 3)** — já
> está funcionando com as redes configuradas e pré-nomeadas, muito trabalho investido. Só será
> movido do PC atual para um servidor. NÃO migrar pro pacote do RouterOS.
> Hoje o Inventário de Redes (`inventario_redes.php`) só integra com UniFi (APs).
- [ ] Confirmar o formato do `dude.db` da v4.0 beta 3 (SQLite ou binário proprietário)
- [ ] Se SQLite: **Pull** — script no servidor GLPI lê o `dude.db` (cópia read-only) a cada X min
      → cada device vira linha no Inventário de Redes com status 🟢/🔴/🟡, tipo, IP, "pai"
- [ ] Se binário: alternativa = ligar o servidor web embutido do Dude e raspar as páginas de status
- [ ] **Push:** notificação do Dude (tipo "Execute" / HTTP) → endpoint `dude_evento.php` no portal
      → registra queda/volta, linha do tempo, e alimenta o chamado automático (item 5)
- [ ] Tabela `portal_dude_devices` (cache) + `portal_dude_eventos` (histórico de quedas)
- [ ] Reaproveitar a UI de grupos + bolinha de status que já existe no `inventario_redes.php`
- [ ] Pré-requisito: mover o Dude pro servidor (idealmente onde o portal consiga ler o `dude.db`,
      ex: o próprio host do Docker/GLPI ou um share SMB)

**15. Integrações externas — Intelbras**
> CFTV da Intelbras é OEM Dahua → API HTTP/CGI + ONVIF + SDK.
- [ ] **CFTV (DVR/NVR):** status do gravador online/offline, **saúde do HD de gravação** (crítico —
      DVR com disco falhando = supermercado sem imagem), canais/câmeras online, perda de vídeo, últimas gravações
- [ ] Eventos do DVR (movimento, video loss, erro de HD) → alerta + chamado automático (item 5)
- [ ] Card "CFTV" no Inventário de Redes com bolinha de status por gravador e por loja
- [ ] **Controle de acesso** (catracas/fechaduras Intelbras): status de porta, eventos de acesso
- [ ] **Central de alarme** (AMT): armado/desarmado, disparos, status de setores
- [ ] **PABX** (Impacta/UnniTI): status de ramais, registro de chamadas

**16. Integrações externas — Home Assistant**
> HA expõe API REST (`/api/states`, token de longa duração) + webhooks + MQTT.
- [ ] **Temperatura de câmara fria / freezer** — leitura contínua; alerta se passar do limite
      (freezer caindo de madrugada = milhares em produto perdido) → chamado + notificação
- [ ] **Monitoramento de energia** por circuito/loja — consumo, quedas de energia, retorno
- [ ] Sensores de porta (câmara, sala de servidor, cofre), vazamento de água, UPS/nobreak
- [ ] Temperatura da sala de servidores / rack
- [ ] Webhook HA → portal em qualquer automação disparada → linha do tempo + chamado automático
- [ ] KPIs ambientais no BI (temperatura média, nº de alertas, tempo fora da faixa)
- [ ] Tabela `portal_ha_sensores` (config: entidade HA, tipo, limite, loja) + `portal_ha_leituras`

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
- [x] **Central RDP** — página dedicada com servidores, coletores e PCs estratégicos (acordeão)
- [x] RDP — gera arquivo .rdp individual com IP/hostname configurado
- [x] **Apache Guacamole** — acesso RDP no browser com iframe + top bar + auto-login
- [x] **Auto-sync Guacamole** — criar/editar/excluir conexões via API REST automaticamente
- [x] **VNC funcional via Guacamole** — acesso VNC no browser com mesma top bar e auto-login
- [x] **Central VNC** — página dedicada com CRUD de máquinas VNC + Guacamole
- [x] **Grupos Dinâmicos** — CRUD de grupos cadastráveis/editáveis/excluíveis (RDP e VNC)
- [x] **Central AnyDesk** — página dedicada com CRUD de máquinas, grupos dinâmicos e conexão via protocolo `anydesk://`
- [x] AnyDesk — link configurável
- [ ] SSH via browser (xterm.js)

### Monitoramento
- [ ] Status dos servidores (uptime, CPU, memória)
- [ ] Alertas de equipamento offline
- [ ] Log de acessos remotos

### Gestão de Rede
- [x] Link para pfSense configurável
- [x] **Central pfSense** — proxy com auto-login, topbar, rewrite URLs/CSS, lista de lojas
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
- [x] **Exportação**: Download .md seletivo (seções) + Imprimir/PDF com `window.print()`

### Pendente
- [ ] Responsável e co-responsáveis por projeto (campo no markdown)
- [ ] Vinculação de chamados GLPI a projetos
- [ ] Filtro por módulo / status na view do portal
- [ ] Exportação do projeto como PDF (server-side)

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
- [x] Busca server-side por título, tags e notas
- [x] Filtro por categoria na toolbar
- [x] Formulário dinâmico por categoria (Link = título/link/notas; campos ocultos são limpos)
- [x] Categoria 📞 Contatos Úteis (pessoa de referência + telefone/ramal visível)
- [x] Auditoria: quem revelou/copiou/criou/editou/excluiu/compartilhou e quando (modal na toolbar)
- [x] PIN por usuário — cadastro na 1ª vez, desbloqueio por sessão, reset ("esqueci o PIN")
- [x] Compartilhamento por link temporário (token + expiração + limite de visualizações; `compartilhar.php` público)

> ✅ **Concluído (10/06/2026)** — módulo completo e em uso.

### Backlog futuro (opcional)
- [ ] Controle de acesso por nível técnico x encarregado (descartado por ora — segurança via auditoria + PIN)

---

## 📱 Módulo 15 — Acesso Mobile
> Portal acessível em celulares e tablets Android/iOS via navegador, sem instalação.
> **Prazo:** 03/07/2026

### Estratégia — Fase 1: PWA (Prioritário)
> Decisão registrada em ADR-004 — o PWA será implementado primeiro por ser mais rápido,
> não depender de loja e funcionar em Android e iOS simultaneamente.
> O APK nativo fica como Fase 2 para funcionalidades avançadas.

#### Fase 1 — PWA (Progressive Web App)
- [ ] Criar `manifest.json` (nome, ícone, cor, modo standalone)
- [ ] Ícone do portal para tela inicial do celular
- [ ] Service worker básico (cache offline das páginas principais)
- [ ] Botão "Instalar app" no banner do portal
- [ ] Notificações push para novos chamados

#### Fase 2 — APK (via Capacitor / PWA2APK)
> Planejado após PWA consolidado. O mesmo PWA será empacotado como APK.
- [ ] Compilar APK com Capacitor
- [ ] Biometria (digital/face) para desbloqueio do cofre
- [ ] QR Code para escanear equipamentos no inventário
- [ ] Publicação interna (fora da Play Store)

### Acesso na Rede
- [ ] Documentar IP do servidor para acesso via Wi-Fi interno
- [ ] Testar acesso em Android (Chrome) e iOS (Safari) na rede local
- [ ] Avaliar solução de acesso externo (VPN ou Cloudflare Tunnel)

### Responsividade CSS
- [ ] Menu hambúrguer no mobile (topbar colapsável)
- [ ] Ajustes de padding/fonte em telas pequenas (< 768px)
- [ ] Modais de chamado adaptados para tela pequena
- [ ] Cards do dashboard empilhados corretamente no mobile
- [ ] Tabelas com scroll horizontal em telas pequenas

### Agenda no Mobile
- [ ] Visualização mensal funcional no touch
- [ ] Botão "Novo Chamado" acessível no mobile
- [ ] Drag & drop desativado no touch (substituir por tap para mover)

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
