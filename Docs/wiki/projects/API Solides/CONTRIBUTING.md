# CONTRIBUTING — Dashboard de Ponto Grupo G+

> Convenções, segredos e como rodar. Arquitetura em [[ARCHITECTURE]]; índice em [[index]].

## Como abrir no dia a dia (PC local)

- **Clique 2x** em `C:\Claude Code\API Solides\Iniciar Painel.cmd` (sobe o servidor se necessário e abre o navegador), ou
- Atalho **"Painel Ponto G+"** na área de trabalho → http://localhost:3000
- O painel também **sobe sozinho no logon** (atalho na pasta Inicializar do Windows)
- Backup diário do banco às 22h (tarefa "Tangerino Backup" → `backups\`)

## Deploy em servidor (engatilhado)

Kit completo em `deploy\`: gere o pacote com `exportar-para-servidor.ps1` (código + dump do banco + scripts) e rode no servidor `instalar-servidor-linux.sh` (Ubuntu/Debian, com HTTPS automático via Caddy se informar domínio) ou `instalar-servidor-windows.ps1`. Passo a passo no `deploy\LEIA-ME-DEPLOY.md`.

## Cópia da documentação na rede

A documentação (este vault) é **espelhada num compartilhamento de rede**:
`\\192.168.1.51\arquifunc\Ti\PROJETOS E DOCUMENTAÇÕES\API Solides\Documentacao`
(+ `tangerino-api-reference.md` na raiz da pasta API Solides).

- Script: `C:\Claude Code\API Solides\sincronizar-docs.ps1` (robocopy `/MIR`, log em `logs-sync-docs.log`).
- Automático: tarefa agendada **"Tangerino Docs Sync"** roda a cada **30 min**. Pode rodar na hora com `sincronizar-docs.cmd`.
- Regra: **toda vez que a documentação local mudar, ela é (re)enviada para esse caminho** — manter os dois sempre iguais.

## Versionamento (git)

**Decisão (12/06/2026): o projeto fica FORA do git.** Não versionar/commitar — o controle de mudanças é feito por esta documentação (vault Obsidian). Existe um `.gitignore` e `*.env.example` prontos caso a decisão mude no futuro (protegem `.env`, dumps `*.sql`, JSON com PII, `backups/`, `node_modules/`, `*.db`, `*.zip`). Se um dia for versionar, usar repositório **PRIVADO** (o código referencia a empresa).

## Pré-requisitos

- **Node.js 20+**
- **MariaDB/MySQL** rodando (serviço `MariaDB` no host MARCOS)
- Token de integração da **Sólides DP** (solicitado ao suporte)

## Como rodar

### 1. Banco
O schema é idempotente e aplicado automaticamente pelo sync. Para criar manualmente:
```powershell
& "C:\Program Files\MariaDB 12.3\bin\mariadb.exe" -uponto -pPontoG2026! ponto_gmais < "C:\Claude Code\API Solides\tangerino-sync\schema.sql"
```

### 2. Sincronização (tangerino-sync)
```powershell
cd "C:\Claude Code\API Solides\tangerino-sync"
npm install
node src/sync.js full     # carga inicial completa (~8 min)
node src/sync.js inc      # incremental (cadastros + ajustes + batidas + resumo)
node src/sync.js rapido   # só batidas novas + resumo de quem bateu
node src/resetar-estado.js <entidade|tudo>   # forçar recarga
```

### 3. Painel (dashboard)
```powershell
cd "C:\Claude Code\API Solides\dashboard"
npm install
npm start                 # http://localhost:3000
node src/criar-usuario.js "Nome" email senha ADMIN|RH|GESTOR [lojaId]
```

## Agendamento (produção local)

Tarefas no Windows Task Scheduler (host MARCOS):

| Tarefa | Intervalo | Comando |
|---|---|---|
| `Tangerino Sync Rapido` | 10 min | `tangerino-sync\sync-rapido.cmd` → `sync.js rapido` |
| `Tangerino Sync Horario` | 1 h | `tangerino-sync\sync-horario.cmd` → `sync.js inc` |

Logs em `tangerino-sync\logs\`. Ver [[Sincronizacao-de-Dados]].

## Convenções de código

- **JavaScript ESM** (`import`/`export`, `"type": "module"`).
- **Sem ORM** — SQL direto, parametrizado (`named placeholders` no painel; `?` no sync).
- **Datas em epoch millis** (BIGINT). Conversão para data/hora local sempre via `Intl` no fuso **`America/Campo_Grande` (UTC-4, Cuiabá/MS)** — confirmado com batida real em 12/06/2026 — **nunca** dependa do fuso do servidor MySQL.
- **Durações em minutos** (formatadas como `XhYY` na interface).
- **Horas extras**: usar exclusivamente `resumo_diario` (cálculo oficial da Sólides). Ver [[ADR-002-Sync-Local-vs-Tempo-Real]].
- Upserts idempotentes (`INSERT ... ON DUPLICATE KEY UPDATE`) — sync pode rodar a qualquer momento.
- Comentários e nomes de domínio em **português**.

## Segredos e credenciais

> ⚠️ Estão em arquivos `.env` (não versionar). Trocar as senhas padrão antes de ir a produção.

| Item | Onde | Valor inicial |
|---|---|---|
| Token API Sólides | `tangerino-sync/.env` → `TANGERINO_TOKEN` | (Basic …) |
| MySQL root | serviço MariaDB | `GmaisDB2026!` |
| MySQL app | `*/.env` → `MYSQL_USER/PASSWORD` | `ponto` / `PontoG2026!` |
| JWT secret | `dashboard/.env` → `JWT_SECRET` | (hex 64) |
| Admin painel | tabela `usuarios` | `ti1@grupogmais.com` / `Gmais@2026!` |
| RH painel | tabela `usuarios` | `rh@grupogmais.com` / `GmaisRH@2026!` |
| Gestor exemplo | tabela `usuarios` | `gestor001@grupogmais.com` / `Loja001@2026!` |

## Checklist de mudança

1. Alterou o schema? Atualize `schema.sql` **e** [[Modelo-de-Dados]].
2. Novo endpoint? Documente em [[Dashboards-e-Relatorios]].
3. Decisão arquitetural? Crie um ADR em `Docs/wiki/decisions/`.
4. Fim de sessão? Registre log em `Docs/wiki/logs/`.
