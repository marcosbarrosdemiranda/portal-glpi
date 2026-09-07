# Integração WhatsApp do Portal — notificações de chamados/alertas + abertura por WhatsApp

**Data:** 2026-09-07
**Status:** Em revisão
**Substitui:** a integração antiga `ws_glpi.js` (standalone `whatsapp-web.js`, hoje em `APAGAR/`)

## Contexto

O portal roda em Docker no servidor `192.168.1.198` (containers `glpi-db` MariaDB
10.4, `glpi-web` PHP 8.2, `glpi-nginx`, `glpi-cron`). É PHP puro + PDO no banco
`glpi2`, com tabelas próprias `portal_*` criadas inline via
`CREATE TABLE IF NOT EXISTS`. Integração com o GLPI de dois jeitos: SQL direto
(ex. `notificacoes.php`, `alertas.php`) e REST API via `agenda/glpi_api.php`
(`GLPI_URL`/`GLPI_APP_TOKEN`/`GLPI_USER`/`GLPI_PASS` no gitignored
`agenda/config.php`).

Existia uma integração GLPI↔WhatsApp (`ws_glpi.js`): um processo Node com
`whatsapp-web.js` que a cada 60s consultava a API do GLPI e avisava supervisores
no WhatsApp sobre chamados novos, além de um chatbot de abertura de chamado por
DM. Apontava pro GLPI velho do XAMPP, tinha ~45 tokens de API hardcoded e foi
aposentada. Queremos refazer isso **dentro do portal**, mais organizado e com
guardrails.

O projeto `Reconhecimento-facial` (standalone, Python) já usa **Evolution API**
(self-hosted, Docker, `evoapicloud/evolution-api` + Baileys, pareamento por QR)
como gateway de WhatsApp. Vamos usar a mesma tecnologia. Trazer aquele
subsistema pro portal é trabalho futuro separado — fora do escopo desta spec.

## Objetivo

1. **Notificar** no WhatsApp: chamado novo, chamado atribuído, alertas
   operacionais (`alertas.php`), chamado parado/estourando SLA.
2. **Manter** a abertura de chamado por WhatsApp (chatbot loja→usuário→título→descrição).
3. **Guardrails**: nunca mandar mensagem pra grupo não cadastrado, nunca
   interagir com contato fora do fluxo de chamados, nunca vazar nada no status,
   e **nunca** disparar backlog ao conectar/reconectar.

## Decisões travadas (do brainstorming)

| Tema | Decisão |
|---|---|
| Gateway | Evolution API, **container novo no stack do portal** |
| Banco da Evolution | MariaDB existente (`glpi-db`), database `evolution`, `DATABASE_PROVIDER=mysql`. Sem Postgres. |
| Instância | `portal_ti` |
| Número | Linha dedicada do TI (não pessoal) |
| Chamado atribuído | DM pro técnico com atraso configurável (default 5 min), cancelado se o chamado sair de "atribuído a ele" antes |
| Telefone dos técnicos | Tabela `portal_wpp_contatos`, gerenciada na tela de config, prefill do celular do GLPI |
| Chatbot | Fluxo antigo: lista lojas → lista usuários da loja → título → descrição |
| Gatilhos | Os 4 (novo, atribuído, alertas, SLA/parado) |

## Componentes

### 1. `evolution-api` (container novo, `docker/docker-compose.yml`)

```yaml
  evolution-api:
    image: evoapicloud/evolution-api:latest
    container_name: evolution-api
    restart: unless-stopped
    depends_on: [glpi-db]
    environment:
      AUTHENTICATION_API_KEY: ${EVOLUTION_API_KEY}
      DATABASE_ENABLED: "true"
      DATABASE_PROVIDER: mysql
      DATABASE_CONNECTION_URI: "mysql://root:root_password@glpi-db:3306/evolution"
      DATABASE_SAVE_DATA_INSTANCE: "true"
      DATABASE_SAVE_DATA_NEW_MESSAGE: "false"
      DATABASE_SAVE_MESSAGE_UPDATE: "false"
      DATABASE_SAVE_DATA_CONTACTS: "false"
      DATABASE_SAVE_DATA_CHATS: "false"
      CACHE_REDIS_ENABLED: "false"
      CACHE_LOCAL_ENABLED: "true"
      CONFIG_SESSION_PHONE_CLIENT: "Portal TI"
      DEL_INSTANCE: "false"
    volumes:
      - C:\docker\glpi-portal\evolution-instances:/evolution/instances
    networks: [glpi-net]
    # SEM ports: — só acessível de dentro da rede glpi-net
```

- O database `evolution` é criado uma vez (`CREATE DATABASE IF NOT EXISTS evolution`
  via init ou passo manual documentado). A Evolution roda as próprias migrations.
- **Risco a validar antes de fechar a Fase 1:** Evolution API v2 + Prisma no
  MariaDB **10.4**. Prisma suporta MariaDB 10.2+, mas confirmar que as migrations
  do provider `mysql` sobem limpas nessa versão. Se não subir: subir um
  `mariadb:11` dedicado como `evolution-db` (fallback), ainda sem Postgres.
- `EVOLUTION_API_KEY` entra no `.env` do compose (gitignored) e no
  `wpp/config.php`.

### 2. Instância `portal_ti` — configuração de segurança

Criada uma vez (pela tela de config ou script). Settings aplicados na criação e
reforçados pelo portal:

```
{
  "instanceName": "portal_ti",
  "qrcode": true,
  "integration": "WHATSAPP-BAILEYS",
  "groupsIgnore": true,       // ignora mensagens de grupo por padrão
  "alwaysOnline": false,
  "readMessages": false,      // sem confirmação de leitura
  "readStatus": false,        // não lê status
  "syncFullHistory": false    // NÃO baixa histórico ao parear
}
```

Webhook da instância aponta pra `http://glpi-web:80/wpp/webhook.php`, só nos
eventos `MESSAGES_UPSERT` e `CONNECTION_UPDATE` (interno, nunca público).

### 3. `wpp/` (novo diretório no portal)

Todos os arquivos sem HTML, funções isoladas, retorno em array, nunca lançam
exceção — padrão de `github_client.php` / `unifi_client.php`.

#### `wpp/config.php`
```php
define('EVO_URL',      'http://evolution-api:8080');
define('EVO_API_KEY',  '...');   // = EVOLUTION_API_KEY
define('EVO_INSTANCE', 'portal_ti');
```
Fica gitignored (adicionar ao `.gitignore`), com `wpp/config.example.php` versionado.

#### `wpp/evo_api.php` — cliente REST da Evolution
```php
function evo_status(): array           // GET /instance/connectionState/{inst}
function evo_qr(): array                // GET /instance/connect/{inst} -> ['base64'=>...]
function evo_logout(): array            // DELETE /instance/logout/{inst}
function evo_groups(): array            // GET /group/fetchAllGroups/{inst}?getParticipants=false
function evo_send_text(string $jid, string $texto): array   // -> evo_guarded_send
function evo_send_media(string $jid, string $b64, string $caption, string $mime, string $nome): array
```
`evo_send_text`/`evo_send_media` **não falam direto com a Evolution** — chamam
`evo_guarded_send()`.

#### `wpp/guardrails.php`
```php
function wpp_destino_permitido(string $jid): bool
// true SOMENTE se $jid for:
//   1. exatamente o JID do grupo Alertas OU do grupo Chamados (portal_wpp_config), ou
//   2. número ativo em portal_wpp_contatos, ou
//   3. número ativo em portal_wpp_autorizados
// Rejeita explicitamente: qualquer coisa terminando em @broadcast,
//   'status@broadcast', qualquer @g.us que não seja um dos 2 grupos,
//   número não cadastrado. Loga a recusa em portal_wpp_log.

function wpp_origem_permitida(array $msg): bool
// Para o webhook. true SOMENTE se:
//   - chat privado (jid @s.whatsapp.net / @c.us), NÃO grupo, NÃO @broadcast
//   - !fromMe
//   - remetente ∈ portal_wpp_autorizados ativo, OU tem linha em portal_wpp_conversas
//   - timestamp da msg >= (agora - 120s)   ← descarta history sync / replay

function evo_guarded_send(string $jid, callable $envio): array
// wpp_destino_permitido($jid) ? $envio() : ['ok'=>false,'bloqueado'=>true]
// grava tudo (enviado ou bloqueado) em portal_wpp_log
```

#### `wpp/worker.php` — loop de polling (rodado pelo container `portal-wpp-worker`)

Uma passada faz, em ordem:

1. **Se a instância não está `open`** (desconectada): não faz nada, sai.
2. **Cold-start / baseline** (`portal_wpp_notificados` vazia OU watermark ausente):
   - grava watermark de cada gatilho = `NOW()`
   - insere em `portal_wpp_notificados` todos os chamados abertos, alertas
     vigentes e atribuições atuais **como já notificados**, sem enviar nada
   - loga "baseline semeada, N itens" e sai. A próxima passada já opera normal.
3. **Chamado novo** — `SELECT ... FROM glpi_tickets WHERE date_creation > :watermark_novo`
   → mensagem no grupo Chamados → marca em `notificados` → avança watermark.
4. **Chamado atribuído** — novo vínculo em `glpi_tickets_users` (type=2) desde o
   watermark → agenda DM (grava linha `portal_wpp_dm_agendado` com `enviar_em =
   now + delay`). Passada seguinte: se `enviar_em` chegou E o ticket ainda está
   atribuído ao mesmo técnico E não foi resolvido → envia DM; senão → cancela.
5. **Alertas** — roda as queries do `alertas.php` (extrair pra `alertas_lib.php`
   reutilizável). Compara com o último snapshot; se há item novo E passou o
   intervalo do digest → manda **um** resumo no grupo Alertas.
6. **SLA / parado** — ticket em status Novo/Atribuído sem follow-up há > X horas,
   ou com SLA a < Y minutos de vencer (reusa `sla.php`) → uma cutucada por
   ticket no grupo Chamados, deduped em `notificados`.

Watermarks e snapshots persistem em `portal_wpp_config`. O worker **nunca** olha
pra trás do watermark.

#### `wpp/webhook.php` — recebe eventos da Evolution
- `CONNECTION_UPDATE` → atualiza status em `portal_wpp_config` (pra tela). Zero mensagem.
- `MESSAGES_UPSERT` → `wpp_origem_permitida()` ? `wpp_chatbot_processar()` : descarta (loga).
- Responde 200 sempre e rápido (processamento pesado fica pro worker se precisar).

#### `wpp/chatbot.php` — máquina de estados (porta do `ws_glpi.js`)
Estado por telefone em `portal_wpp_conversas` (`telefone, estado JSON, updated_at`).
Fluxo: menu → escolhe loja (entidades filhas do GLPI) → escolhe usuário da loja →
título → descrição (com "adicionar mais?") → cria o chamado reusando a lógica de
`agenda/criar_ticket.php` (via REST, pra disparar regras/notificações do GLPI) →
confirma o ID. Timeout de 5 min zera a conversa. Também aceita "2" no menu pra
**consultar** um chamado (status + histórico), como no bot antigo.
Toda resposta do bot vai por `evo_send_text` (→ guardrail; o número já está em
`conversas`, então passa).

### 4. Tela de configuração

**Card novo** na seção **Configuração** do `dashboard.php` → `config_notificacoes.php`
(permissão nova `notificacoes_config` em `perfis.php` / `portal_perfil_cards`).

`config_notificacoes.php` = grid de sub-cards (hoje só um). Sub-card **WhatsApp**
→ `config_whatsapp.php`, com abas:

| Aba | Conteúdo | Fase |
|---|---|---|
| **Conexão** | status da instância (`evo_status`), botão "Conectar" mostra QR (`evo_qr`, base64 `<img>`), botão "Desconectar" (`evo_logout`), última conexão | 1 |
| **Grupos** | lista de grupos do WhatsApp (`evo_groups`), selects "Grupo de Alertas" e "Grupo de Chamados" → grava JID em `portal_wpp_config` | 1 |
| **Contatos** | tabela técnicos (`glpi_users` que são técnicos) × telefone × ativo → `portal_wpp_contatos`; botão "puxar celular do GLPI" | 2 |
| **Gatilhos** | toggles: chamado novo, atribuído (+ campo delay min), alertas (+ intervalo digest min), SLA (+ horas parado / minutos pré-vencimento) → `portal_wpp_config` | 2 |
| **Autorizados** | tabela telefone × entidade × nome × ativo → `portal_wpp_autorizados` (quem pode falar com o bot) | 3 |
| **Log** | últimas N linhas de `portal_wpp_log` (entrada/saída, destino, status, bloqueios) — read-only, pra depurar | 2 |

### 5. Tabelas (`portal_wpp_*`, CREATE IF NOT EXISTS no topo de `config_whatsapp.php` e `wpp/db.php`)

```
portal_wpp_config      (chave VARCHAR PK, valor TEXT)
portal_wpp_contatos    (id, glpi_user_id INT, telefone VARCHAR, ativo TINYINT, UNIQUE(glpi_user_id))
portal_wpp_autorizados (id, telefone VARCHAR UNIQUE, entities_id INT, nome VARCHAR, ativo TINYINT)
portal_wpp_notificados (id, tipo VARCHAR, ref_id VARCHAR, hash VARCHAR, enviado_em DATETIME,
                        UNIQUE(tipo, ref_id, hash))
portal_wpp_dm_agendado (id, ticket_id INT, glpi_user_id INT, telefone VARCHAR,
                        enviar_em DATETIME, status ENUM('pendente','enviado','cancelado'))
portal_wpp_conversas   (telefone VARCHAR PK, estado JSON, updated_at DATETIME)
portal_wpp_log         (id, direcao ENUM('in','out'), destino VARCHAR, resumo VARCHAR(255),
                        status VARCHAR, criado_em DATETIME)
```

Chaves em `portal_wpp_config`: `grupo_alertas_jid`, `grupo_chamados_jid`,
`conn_status`, `conn_ultima`, `wm_novo`, `wm_atribuido`, `wm_sla`,
`snap_alertas`, `delay_dm_min` (5), `digest_alertas_min` (15), `sla_horas` (4),
`sla_prevenc_min` (30), `on_novo`/`on_atribuido`/`on_alertas`/`on_sla` (toggles).

## Guardrails — resumo consolidado

**Saída** (`evo_guarded_send`): destino ∈ {grupo Alertas, grupo Chamados,
contato ativo, autorizado ativo}. Todo o resto → recusa + log. Nunca
`@broadcast`, nunca grupo fora dos 2, nunca número não cadastrado.

**Entrada** (`wpp_origem_permitida`): só chat privado, não `fromMe`, remetente
autorizado ou já em conversa, msg com < 120s de idade. Ignora todo grupo,
`@broadcast`, status.

**Cold-start / reconexão:**
1. Watermark persistente por gatilho; primeira execução → watermark = `NOW()`, envia zero.
2. Baseline: `portal_wpp_notificados` vazia → semeia tudo que está aberto/vigente como "já notificado", sem enviar.
3. Instância: `syncFullHistory=false`, `groupsIgnore=true`, `readMessages=false`.
4. Webhook descarta msg com timestamp anterior a `now - 120s`.
5. Conectar/reconectar só mexe no status da tela. Nenhuma mensagem.
6. `DATABASE_SAVE_DATA_NEW_MESSAGE=false` — Evolution não acumula histórico de mensagem.

## Fases

### Fase 1 — Infra + Conexão
- `evolution-api` no compose + database `evolution` + validar MariaDB 10.4
- `wpp/config.php`, `wpp/evo_api.php` (status/qr/groups/logout)
- `config_notificacoes.php` + `config_whatsapp.php` (abas Conexão e Grupos)
- permissão `notificacoes_config`, card no dashboard
- Parear a linha do TI, cadastrar os 2 grupos
- **Sem envio automático, sem webhook de mensagem**

### Fase 2 — Notificações (saída) + guardrails
- Tabelas `portal_wpp_*`
- `wpp/guardrails.php`, `evo_guarded_send`
- `alertas_lib.php` (extrai as queries de `alertas.php`)
- `wpp/worker.php` + container `portal-wpp-worker` no compose (com baseline/watermark)
- Abas Contatos, Gatilhos, Log
- Gatilhos: novo, atribuído (+delay), alertas (digest), SLA

### Fase 3 — Chatbot (entrada)
- Webhook `MESSAGES_UPSERT` na instância → `wpp/webhook.php`
- `wpp/chatbot.php` (fluxo loja→usuário→título→descrição + consulta)
- Aba Autorizados
- `wpp_origem_permitida` completa

## Testes

- **`wpp/guardrails.php`**: unit — `wpp_destino_permitido` rejeita `status@broadcast`,
  grupo aleatório `@g.us`, número não cadastrado; aceita os 2 grupos e um contato ativo.
  `wpp_origem_permitida` rejeita grupo, `fromMe`, msg antiga, remetente desconhecido.
- **Cold-start**: com `portal_wpp_notificados` vazia e 20 chamados abertos, uma
  passada do worker → 0 envios, 20+ linhas de baseline, watermarks preenchidos.
- **DM atrasado**: atribui ticket → linha `pendente`; reatribui antes do prazo →
  `cancelado`, 0 envios.
- **Evo_api**: mock do HTTP; `evo_status` trata instância inexistente sem lançar.
- **Chatbot**: simula sequência de mensagens, verifica transições de estado e o
  payload de criação do chamado (sem chamar o GLPI real).

## Fora de escopo

- Trazer o Reconhecimento Facial pro portal (spec própria, futura).
- Notificação por outros canais (e-mail, Telegram).
- Responder/atualizar chamado por WhatsApp além de abrir/consultar.
- Envio pra clientes finais / requerentes que não sejam os autorizados.
