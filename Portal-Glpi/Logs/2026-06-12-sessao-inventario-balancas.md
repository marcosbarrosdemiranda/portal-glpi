# Log de Sessão — 12/06/2026

## Resumo
Módulo de inventário de balanças completo + sync de projetos compartilhado da rede.

---

## Inventário — Balanças

### Mudança
- `inventario_balancas.php` — **novo** — página completa de inventário de balanças com:
  - CRUD de servidores MGV (nome, IP, conexão Firebird)
  - CRUD de balanças (identificação, modelo, série, loja, departamento)
  - Sync automático via PDO_Firebird ou ODBC Firebird
  - Status online/offline via ping nos servidores MGV
  - Layout acordeão por servidor (mesmo padrão do inventário_pcs)
- `inventario.php` — card "Balanças" ativado (antes era placeholder "Em breve")

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `inventario_balancas.php` | Novo — página completa com CRUD + sync Firebird |
| `inventario.php` | Card Balanças ativado (link para novo módulo) |

### Arquitetura
- Tabelas `portal_servidores_mgv` e `portal_balancas` criadas no MySQL (CREATE TABLE IF NOT EXISTS)
- Possui sync opcional com Firebird do MGV 6 — tenta PDO_Firebird, fallback ODBC
- Compatível com futura migração para consulta direta ao banco do MGV

## Commits
- `feat: inventário de balanças com CRUD + sync MGV Firebird`

---

## Segunda parte — Migração SQL Server

### Mudança
- `sync_balanca.ps1` — Adicionado path específico para `tbBalanca` com JOINs (`tbTipoBalanca` e `tbDepartamento`), extração de IP (`BAL_ENDERECO_IP` / `BAL_DMP_ENDERECO_IP`)
- `sync_balanca.config.example.json` — `tbBalanca` adicionado à lista padrão
- `inventario_balancas.php`:
  - Colunas `db_type`, `sql_host`, `sql_port`, `sql_database`, `sql_user`, `sql_pass` adicionadas ao servidor (migração automática)
  - `sync_mgv` agora suporta SQL Server path (PDO sqlsrv → ODBC fallback) com JOINs tbBalanca/tbTipoBalanca/tbDepartamento
  - `sync_remoto` agora aceita e persiste campo `ip`
  - Formulário do servidor com seletor "Tipo de banco" (SQL Server / Firebird)

### Detalhamento do schema `tbBalanca` (MGV6 SQL Server)
| Campo | Origem |
|-------|--------|
| identificação | `tbBalanca.BAL_CODIGO` |
| modelo | `tbTipoBalanca.TPB_NOME` (via JOIN) |
| série | `tbBalanca.BAL_NUMERO_SERIE` |
| departamento | `tbDepartamento.DPT_NOME` (via JOIN) |
| IP | `tbBalanca.BAL_ENDERECO_IP` (fallback BAL_DMP_ENDERECO_IP) |
| versão firmware | `tbBalanca.BAL_VERSAO` (fallback BAL_DMP_VERSAO_BALANCA) |
| carga atual | `tbBalanca.BAL_NUMERO_CARGA_ATUAL` |
| carga programada | `tbBalanca.BAL_NUMERO_CARGA_PROG` |
| última cx GW | `tbBalanca.BAL_ULTIMA_CX_GW` |
| lote fabricação | `tbBalanca.BAL_LOTE_FABRICACAO` |

---

## Terceira parte — Cards + firmware + status online/offline por balança

### Mudança
- `inventario_balancas.php` — **frontend refatorado** de tabela para **cards** (igual inventario_pcs.php):
  - Layout grid `col-6 col-md-4 col-lg-3` com cards `.bl-card`
  - Status online/offline por balança via ping no IP individual
  - Badge verde/vermelho + dot animado no card
  - Card info: modelo, departamento, IP, firmware (FW:)
  - Carga atual exibida no rodapé do card
  - Modal detalhes com spec-grid (identificação, modelo, série, IP, loja, departamento, firmware, carga atual, carga programada, lote)
  - Botão "Verificar Status" na toolbar que re-pinga todas as balanças
  - Modal CRUD com campos firmware, carga atual, carga programada, lote fabricação
  - Firebird INSERT atualizado com todos os novos campos
- `sync_balanca.ps1` — Envio dos novos campos:
  - MGV6 path: `versao_firmware`, `carga_atual`, `carga_programada`, `ultima_comunicacao`, `lote_fabricacao`
  - Generic path: colunas auto-detectadas via `Get-Col` para `BAL_VERSAO`, `BAL_NUMERO_CARGA_ATUAL`, `BAL_NUMERO_CARGA_PROG`, `BAL_ULTIMA_CX_GW`, `BAL_LOTE_FABRICACAO`

### Campos adicionados ao `portal_balancas`
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `versao_firmware` | VARCHAR(50) | Versão do firmware da balança |
| `carga_atual` | INT | Número da carga atual |
| `carga_programada` | INT | Número da carga programada |
| `ultima_comunicacao` | VARCHAR(100) | Última cx GW / comunicação |
| `lote_fabricacao` | VARCHAR(50) | Lote de fabricação |

### Commits
- `feat: inventario de balancas com CRUD + sync remoto via PowerShell Agent`
- `fix: sync MGV agora usa SQL Server (era Firebird)`
- `feat: layout cards + firmware + status online/offline por balança`

---

## Quarta parte — Sync de projetos da rede compartilhada

### Mudança
- `sync_projetos.php` — **novo** — script standalone que lê projetos .md de uma pasta de rede e copia para `Docs/wiki/projects/`, organizado por subpastas:
  - Varre subpastas da ORIGEM_PROJETOS (cada subpasta = um projeto)
  - Copia apenas arquivos mais recentes (preserva timestamp)
  - Remove .md obsoletos do destino se não existirem mais na origem
  - Gera log em `Portal-Glpi/Logs/sync-projetos.log`
  - Salva `.last_sync` com timestamp do último sync
  - Executável via CLI ou Task Scheduler
- `sync_projetos.bat` — **novo** — batch para agendar no Windows Task Scheduler
- `config_projetos.local.php` — **novo** — config ignorada pelo git com o caminho da rede
- `projetos.php` — **alterado**:
  - Agora lê .md de subpastas dentro de `Docs/wiki/projects/`
  - Compatível com arquivos .md na raiz (legado)
  - Download .md corrigido para suportar subpastas (path traversal seguro)
  - Exibe "Último sync" no rodapé do detalhe do projeto
- `.gitignore` — adicionado `config_projetos.local.php`
- `Docs/projetos-compartilhados/` — pasta de origem com README e exemplo

### Estrutura esperada na rede
```
\\192.168.1.198\xampp\projetos-ti\
  ├── README.md
  ├── portal-glpi/
  │   └── portal-glpi-prd.md
  └── (outros projetos como subpastas)
```

### Instalação do Task Scheduler (servidor 192.168.1.198)
```
Programa:     C:\xampp\php\php.exe
Argumentos:   -f C:\xampp\htdocs\glpi2\portal-glpi\sync_projetos.php
Frequência:   A cada 60 minutos (repetir indefinidamente)
Iniciar em:   C:\xampp\htdocs\glpi2\portal-glpi\
```

### Pendente
- [ ] Criar o compartilhamento de rede `\\192.168.1.198\projetos-ti` (via propriedades da pasta no servidor)
- [ ] Organizar os projetos na rede com subpastas por projeto
- [ ] Agendar a tarefa no Windows Task Scheduler do servidor
