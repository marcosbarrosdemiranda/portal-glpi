# Portal Central TI — Grupo Gmais
## Documentação Completa do Projeto

**Última atualização:** 2026-06-02
**Stack:** PHP, MySQL/MariaDB, Bootstrap 5, FullCalendar 6, Chart.js, Docker / XAMPP
**GLPI:** v10.0.17 — API REST integrada

---

## 📁 Estrutura de Arquivos

```
portal-glpi/
│
├── auth.php                    # Login / autenticação via API GLPI
├── dashboard.php               # Painel principal (cards por perfil, dividido em seções)
├── chamado.php                 # Detalhes completos de um chamado
├── historico.php               # Listagem paginada de chamados (técnicos)
├── meus_chamados.php           # Chamados do usuário logado (self-service)
├── relatorios.php              # Painéis dark + aba Monitor SLA
├── inventario.php              # Inventário de máquinas por entidade
├── inventario_detalhes.php     # Hardware + SO + programas instalados (cURL multi)
├── ping.php                    # Ping via TCP 445 + ICMP (online/offline)
├── notificacoes.php            # Polling de chamados novos
├── conhecimento.php            # Área do conhecimento (em construção)
├── config.php                  # Redireciona para agenda/config.php
├── config_db_prod.php          # Config do banco de produção (glpi2)
│
│── MÓDULOS DE GESTÃO ──────────────────────────────────────────
├── projetos.php                # Gestão de projetos de TI (localStorage)
├── equipe.php                  # Visão da equipe via API GLPI
├── orcamento.php               # Controle de orçamento (localStorage)
├── contratos.php               # Contratos com alertas de vencimento (localStorage)
├── licencas.php                # Licenças de software via API GLPI
│
│── MÓDULOS DE ACESSO ──────────────────────────────────────────
├── acessos.php                 # Central de acessos (RDP, VNC, pfSense, VMware...)
├── vnc.php                     # Gerenciador VNC + viewer noVNC ⚠️ PENDENTE instalação
│
│── MÓDULO DE SEGURANÇA ────────────────────────────────────────
├── cofre.php                   # Cofre de senhas/comandos (AES-256, banco glpi2)
│
├── agenda/
│   ├── index.php               # Agenda principal (FullCalendar 6.1)
│   ├── config.php              # Credenciais GLPI e config global
│   ├── db.php                  # Conexão PDO (banco glpi2)
│   ├── glpi_api.php            # Busca chamados abertos da API GLPI
│   ├── tickets.php             # Endpoint: lista chamados para sidebar
│   ├── eventos.php             # CRUD de eventos da agenda (banco local)
│   ├── ticket_descricao.php    # Busca descrição + followups de um chamado
│   ├── users.php               # Lista atendentes e requerentes
│   ├── entidades.php           # Lista entidades do GLPI
│   ├── categorias.php          # Lista categorias do GLPI
│   ├── atribuir_ticket.php     # Atribui técnico ao chamado no GLPI
│   ├── criar_ticket.php        # Cria chamado no GLPI (agenda)
│   ├── responder_ticket.php    # Adiciona acompanhamento no chamado
│   ├── fechar_ticket.php       # Fecha/resolve chamado no GLPI
│   ├── resetar_ticket.php      # Reseta status do chamado
│   ├── verificar_atrasados.php # Remove eventos atrasados não concluídos
│   ├── sync_rotinas.php        # Cron 06:30 — sync chamados rotina→agenda
│   ├── sync_rotinas_ajax.php   # Endpoint AJAX para sync manual de rotinas
│   ├── google_calendar.php     # Salva/retorna URL iCal Google por usuário
│   └── google_eventos.php      # Busca e converte iCal Google → JSON
│
├── portal/
│   ├── atendente.php           # Formulário abertura de chamado (self-service)
│   ├── index.php               # Formulário abertura de chamado (técnico)
│   ├── api_atendente.php       # API: cria chamado + requerente + anexos
│   └── dados_glpi.php          # Retorna entidades, usuários e categorias
│
└── assets/
    ├── notificacoes.js         # Sistema de notificações em tempo real
    ├── Logo GMAIS b.jpg        # Logo do Grupo Gmais
    ├── G.ico                   # Favicon
    └── tema.css                # Variáveis de tema (reservado)
```

---

## 🗄️ Tabelas criadas pelo portal (banco glpi2)

| Tabela | Descrição |
|--------|-----------|
| `glpi_plugin_agenda_events` | Eventos da agenda (chamados, reuniões, eventos) |
| `glpi_agenda_gcal` | Links iCal do Google Calendar por usuário |
| `portal_vault` | Cofre de senhas/comandos (conteúdo criptografado AES-256) |
| `portal_acessos` | Ferramentas da Central de Acessos (URLs configuráveis) |
| `portal_vnc` | Máquinas VNC cadastradas (senhas criptografadas) |

---

## 👤 Perfis de Acesso

| Perfil | Acesso |
|--------|--------|
| **Self-Service** | Abrir Chamado, Meus Chamados, Área do Conhecimento |
| **Técnico/Admin/Super-Admin** | Todos os módulos |

---

## 🏠 Dashboard — Seções

| Seção | Módulos |
|-------|---------|
| 📞 **Atendimento** | Agenda · Abrir Chamado · Histórico de Chamados |
| 📊 **KPIs** | Painel de Relatórios (+ Monitor SLA) · Inventário |
| 📚 **Recursos** | Área do Conhecimento · Cofre TI |
| 🖥️ **Acessos** | Acesso Remoto · Infraestrutura · Ferramentas ERP |
| 💼 **Gestão de TI** | Projetos · Equipe · Orçamento · Contratos · Licenças |

---

## 📋 Módulos

### 1. Login (`auth.php`)
- Autentica via API REST do GLPI (Basic Auth)
- Detecta perfil (self-service ou técnico)
- Busca user_id via sessão admin
- Salva na sessão: `usuario`, `nome`, `user_id`, `perfil`, `autenticado`

### 2. Agenda (`agenda/index.php`)
- FullCalendar 6.1 com visões: Semana, Mês, Dia
- Sidebar com chamados abertos (status 1,2,3,4) ordenados por última atualização
- Drag & drop da sidebar → agenda com atribuição de atendente
- Tipos: Chamado (vermelho), Requisição (laranja), Reunião (roxo), Evento (azul), Concluído (verde)
- Filtro de atendente na navbar (pré-seleciona o logado)
- Sync de rotinas via cron 06:30
- Google Calendar: integração via iCal
- **Fix 02/06:** flag `_inEventReceive` + `_dropCache` para preservar tipo/atendente no resize

### 3. Inventário (`inventario.php` + `inventario_detalhes.php`)
- Grid de máquinas por entidade (4 colunas)
- IPs buscados via cadeia `NetworkPort → NetworkName → IPAddress` (3 chamadas bulk)
- `ping.php`: verifica online/offline via TCP porta 445 + ICMP fallback
- Modal de detalhes: SO, CPU, RAM, disco, MAC, programas instalados
- Programas instalados via `SoftwareVersion/{id}?expand_dropdowns` em lotes paralelos de 30

### 4. Relatórios (`relatorios.php`)
- Tema dark (estilo Power BI)
- 6 abas: Atendimentos · Lojas · Categorias · Monitor Hora/Dia · Evolução Mensal · 🚦 Monitor SLA
- Monitor SLA: semáforo por urgência (Alta=4h, Média=8h, Baixa=12h)
- Acesso direto ao SLA: `relatorios.php?tab=sla`

### 5. Cofre TI (`cofre.php`)
- Tabela `portal_vault` no banco `glpi2`
- Criptografia AES-256-CBC com chave derivada do `GLPI_APP_TOKEN`
- Categorias: 🔑 Senha · 💻 Comando · 📋 Documentação · 🔗 Link · 📦 Outro
- Conteúdo mascarado; botão 👁️ para revelar, 📋 para copiar sem revelar
- Busca por título, tag e nota

### 6. Central de Acessos (`acessos.php`)
- Tabela `portal_acessos` com defaults pré-populados na primeira execução
- 3 grupos: **Acesso Remoto** · **Infraestrutura** · **Ferramentas ERP**
- Ferramentas padrão: Remote Desktop (RDP) · VNC · AnyDesk · pfSense · VMware · Mikrotik · UniFi
- Admin configura URL via ⚙️ em cada card
- RDP: gera e baixa arquivo `.rdp` com hostname configurado
- VNC: card aponta para `vnc.php`
- Admin pode adicionar novas ferramentas com ícone, cor e grupo personalizados

### 7. VNC (`vnc.php`) ⚠️ PENDENTE
- Gerencia máquinas VNC (tabela `portal_vnc`, senhas criptografadas AES-256)
- Gera `tokens.cfg` para websockify com 1 clique
- Viewer noVNC embutido em overlay full-screen no portal
- **Dependência:** noVNC + websockify instalados no servidor XAMPP
- Ver seção "⚠️ Pendente — VNC" abaixo

### 8. Módulos de Gestão

| Módulo | Arquivo | Dados |
|--------|---------|-------|
| Projetos | `projetos.php` | localStorage — CRUD com prazo, progresso, prioridade |
| Orçamento | `orcamento.php` | localStorage — planejado vs realizado por categoria |
| Contratos | `contratos.php` | localStorage — alertas automáticos de vencimento |
| Equipe | `equipe.php` | API GLPI — técnicos com carga de chamados |
| Licenças | `licencas.php` | API GLPI — `SoftwareLicense` com vencimento e uso |

> **Nota:** Projetos, Orçamento e Contratos usam `localStorage` do browser.
> Os dados ficam no computador do usuário. Para compartilhar entre a equipe,
> migrar para banco de dados (solicitar quando necessário).

---

## ⚠️ PENDENTE — VNC

**O que falta para funcionar:**

1. **Baixar noVNC**
   - Acesse: https://github.com/novnc/noVNC/releases
   - Extraia em: `C:\xampp\htdocs\novnc\`

2. **Baixar websockify**
   - Acesse: https://github.com/novnc/websockify/releases
   - Salve `websockify.exe` em: `C:\xampp\htdocs\novnc\utils\`

3. **Gerar tokens.cfg**
   - Cadastre as máquinas em `vnc.php`
   - Clique em "tokens.cfg" na barra para baixar
   - Salve em: `C:\xampp\htdocs\novnc\tokens.cfg`

4. **Rodar websockify** (Prompt como Administrador):
   ```cmd
   cd C:\xampp\htdocs\novnc\utils
   websockify.exe 6080 --web ..\ --token-plugin TokenFile --token-source ..\tokens.cfg
   ```

5. **Configurar RealVNC em cada máquina:**
   - Security → Encryption: `Prefer off`
   - Security → Authentication: `VNC password`

6. **Opcional:** registrar websockify como serviço Windows via **NSSM**

**Constantes em `vnc.php` (linha ~10):**
```php
define('VNC_PROXY_HOST', '192.168.1.198');
define('VNC_PROXY_PORT', '6080');
define('NOVNC_PATH',     '/novnc/vnc.html');
```

---

## 🔧 Configuração

### `agenda/config.php`
```php
define('GLPI_URL',       'http://192.168.1.198/glpi2');
define('GLPI_APP_TOKEN', 'TOKEN_DO_SERVIDOR');
define('GLPI_USER',      'glpi');
define('GLPI_PASS',      'SENHA');
```

### `agenda/db.php` (produção XAMPP)
```php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root_password';
$db_name = 'glpi2';
```

---

## 🐳 Docker (desenvolvimento)

| Container | Imagem | Função |
|-----------|--------|--------|
| `glpi-app` | rosnertech1/glpi:10.0.17 | Apache + PHP + GLPI |
| `glpi-db` | mariadb:latest | Banco de dados |

**Cron:** `30 6 * * * php /var/www/html/glpi/agenda/sync_rotinas.php`

---

## 🚀 Deploy para Produção (192.168.1.198)
1. Copiar pasta `portal-glpi/` para `C:\xampp\htdocs\glpi2\`
2. Editar `agenda/config.php` com credenciais do servidor
3. Editar `agenda/db.php` com credenciais do MySQL
4. Acessar `http://192.168.1.198/glpi2/portal-glpi/auth.php`

---

## ⚠️ Pontos de Atenção

1. **Self-service** busca `user_id` via sessão admin (sem permissão na API `/User`)
2. **Requerente** deve ser adicionado via `Ticket_User` (POST) após criar o ticket
3. **Ping** só funciona se o servidor tiver acesso de rede aos IPs das lojas (via VPN)
4. **Google Calendar iCal** — cada atendente configura o próprio link na agenda
5. **Chamados de rotina** = identificados pela **Entidade raiz** no campo `entities_id`
6. **Nomes GLPI:** `realname` = sobrenome, `firstname` = nome
7. **Cofre:** chave derivada do `GLPI_APP_TOKEN` — não alterar o token sem migrar os dados
8. **VNC:** RealVNC 6+ usa criptografia proprietária — desabilitar em cada máquina ⚠️
9. **Módulos localStorage** (Projetos, Orçamento, Contratos): dados ficam no browser — migrar para banco quando quiser compartilhar entre a equipe
