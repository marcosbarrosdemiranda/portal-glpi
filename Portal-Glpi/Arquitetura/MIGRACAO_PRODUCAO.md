# Guia de Migração para Produção — Portal GLPI + Agenda TI

## O que foi criado

### 1. Portal de abertura de chamados
- Localização: `/var/www/html/glpi/portal/`
- Arquivos: `index.php`, `api.php`, `config.php`
- Acesso: `http://seu-servidor/portal/`

### 2. Agenda de TI para atendentes
- Localização: `/var/www/html/glpi/agenda/`
- Arquivos: `index.php`, `eventos.php`, `tickets.php`, `users.php`, `glpi_api.php`, `db.php`, `config.php`
- Acesso: `http://seu-servidor/agenda/`

---

## Passo a Passo para Produção

### Passo 1 — Criar a tabela no banco de dados

Execute no servidor de produção (ajuste usuário/senha/banco conforme seu `config_db.php`):

```sql
CREATE TABLE IF NOT EXISTS `glpi_plugin_agenda_events` (
  `id`            VARCHAR(60)   NOT NULL,
  `titulo`        VARCHAR(255)  NOT NULL DEFAULT '',
  `descricao`     TEXT,
  `start`         DATETIME      NOT NULL,
  `end`           DATETIME      NOT NULL,
  `atendente`     VARCHAR(100)  DEFAULT NULL,
  `atendente_id`  INT           DEFAULT NULL,
  `atendente_cor` VARCHAR(10)   DEFAULT '#1a73e8',
  `prioridade`    ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  `setor`         VARCHAR(100)  DEFAULT NULL,
  `ticket_id`     INT           DEFAULT NULL,
  `tipo`          ENUM('evento','chamado','manutencao','reuniao') NOT NULL DEFAULT 'evento',
  `criado_em`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id`    (`ticket_id`),
  KEY `idx_atendente_id` (`atendente_id`),
  KEY `idx_start`        (`start`),
  KEY `idx_prioridade`   (`prioridade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos da agenda de TI — portal de atendentes';
```

### Passo 2 — Copiar os arquivos

Copie as pastas `portal/` e `agenda/` para dentro do DocumentRoot do GLPI no servidor de produção.

Se o GLPI estiver em `/var/www/html/glpi/`:
```bash
cp -r portal/ /var/www/html/glpi/portal/
cp -r agenda/ /var/www/html/glpi/agenda/
```

### Passo 3 — Configurar credenciais da API (portal de chamados)

Edite `/var/www/html/glpi/portal/config.php`:
```php
define('GLPI_URL', 'http://SEU-SERVIDOR-PRODUCAO/glpi-ou-raiz');
define('GLPI_APP_TOKEN', 'APP_TOKEN_DO_GLPI_PRODUCAO');
define('GLPI_USER', 'usuario_servico');
define('GLPI_PASS', 'senha_servico');
```

> O `config.php` da agenda (`agenda/config.php`) não precisa de ajuste — ele lê as credenciais automaticamente do `config_db.php` do GLPI.

### Passo 4 — Gerar App Token no GLPI de produção

1. Acesse: **Configuração → Geral → API**
2. Ative a API REST
3. Gere um **App Token**
4. Cole no `portal/config.php`

### Passo 5 — Verificar permissões de pasta

```bash
chown -R www-data:www-data /var/www/html/glpi/portal/
chown -R www-data:www-data /var/www/html/glpi/agenda/
chmod -R 755 /var/www/html/glpi/portal/
chmod -R 755 /var/www/html/glpi/agenda/
```

---

## Estrutura de Arquivos

```
glpi/
├── portal/
│   ├── index.php       ← Formulário de abertura de chamados (usuários finais)
│   ├── api.php         ← Proxy para a API REST do GLPI
│   └── config.php      ← URL, App Token e credenciais do GLPI
│
└── agenda/
    ├── index.php       ← Interface da agenda (FullCalendar)
    ├── eventos.php     ← API CRUD de eventos (lê/grava no banco)
    ├── tickets.php     ← Lista chamados abertos do GLPI (exclui fechados/agendados)
    ├── users.php       ← Lista usuários ativos do GLPI como atendentes
    ├── glpi_api.php    ← Funções de integração com a API REST do GLPI
    ├── db.php          ← Conexão PDO (lê credenciais do config_db.php do GLPI)
    └── config.php      ← Configurações da agenda (não precisa editar em produção)
```

---

## Banco de Dados — Tabela `glpi_plugin_agenda_events`

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | VARCHAR(60) | ID único do evento (gerado pelo PHP) |
| `titulo` | VARCHAR(255) | Título do evento ou chamado |
| `descricao` | TEXT | Detalhes do evento |
| `start` | DATETIME | Data/hora de início |
| `end` | DATETIME | Data/hora de fim |
| `atendente` | VARCHAR(100) | Nome do atendente responsável |
| `atendente_id` | INT | ID do usuário no GLPI |
| `atendente_cor` | VARCHAR(10) | Cor hex para exibição na agenda |
| `prioridade` | ENUM | baixa / media / alta / critica |
| `setor` | VARCHAR(100) | Setor ou loja |
| `ticket_id` | INT | ID do chamado no GLPI (se vier da sidebar) |
| `tipo` | ENUM | evento / chamado / manutencao / reuniao |
| `criado_em` | DATETIME | Data de criação (automático) |
| `atualizado_em` | DATETIME | Última atualização (automático) |

---

## Exportar dados de teste para migrar para produção

Se quiser levar os eventos do ambiente de teste para produção:

```bash
# No servidor de teste — exporta só a tabela da agenda
mysqldump -u glpi_user -p glpi glpi_plugin_agenda_events > agenda_eventos_backup.sql

# No servidor de produção — importa
mysql -u glpi_user -p glpi < agenda_eventos_backup.sql
```

---

## Tecnologias utilizadas

- **PHP 8+** com PDO (MariaDB)
- **Bootstrap 5.3** — layout e componentes visuais
- **Bootstrap Icons 1.11** — ícones
- **FullCalendar 6.1** — calendário interativo com drag & drop
- **GLPI REST API** — integração de chamados e usuários
