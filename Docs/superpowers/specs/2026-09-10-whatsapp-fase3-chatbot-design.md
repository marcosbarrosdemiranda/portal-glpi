# WhatsApp Fase 3 — Chatbot de entrada (abrir / consultar chamado por WhatsApp)

**Data:** 2026-09-10
**Status:** Em revisão
**Depende de:** Fase 1 (infra + conexão) e Fase 2 (worker de notificações), ambas em produção desde 2026-09-07.
**Atualiza:** a seção "Fase 3" de `2026-09-07-whatsapp-portal-design.md`, que ficou desatualizada (pré Central de Alertas Etapa 2 e pré este brainstorming).

## Contexto

As Fases 1 e 2 entregaram: containers `evolution-db`/`evolution-api`, instância `portal_ti` pareada, módulo `wpp/` (cliente REST, guardrails de saída, worker de notificações rodando de 30 em 30s no container `portal-wpp-worker`), telas em `config_whatsapp.php`. Hoje o fluxo é **só de saída** — nenhum webhook de mensagem, o bot não lê nada.

A Fase 3 traz de volta o que o `ws_glpi.js` aposentado (`APAGAR/ws_glpi_180126.js`) fazia: um chatbot por DM onde a pessoa abre ou consulta chamado. O legado tinha ~45 `user_token` hardcoded, apontava pro GLPI velho do XAMPP e rodava fora do portal. Refazemos dentro do portal, reusando o padrão Basic-auth de `agenda/criar_ticket.php` (sem token por usuário).

Entrada + resposta é superfície de risco nova. O princípio das Fases 1–2 continua: **nunca ler grupo, nunca escrever pra quem não falou, nunca despejar backlog ao (re)conectar.**

## Objetivo

1. Abrir chamado por DM no WhatsApp — com paridade ao legado: escolha de loja/usuário, título, descrição em várias mensagens, e uma imagem opcional anexada ao chamado.
2. Consultar um chamado por número — status + histórico + imagens dos follow-ups.
3. Reduzir atrito pra quem o TI já conhece: número vinculado a um usuário GLPI pula direto pro título.
4. Barrar abuso: número desconhecido abre, mas o chamado só entra no GLPI depois de um técnico aprovar.

## Coexistência com outras apps na Evolution API

Duas outras aplicações vão usar a mesma Evolution API self-hosted: o
**Reconhecimento-facial** (standalone Python) e o **checklist-gmais**
(containers `checklist-gmais-*` já no servidor). Webhook na Evolution é
**1 URL por instância** — se duas apps registrassem webhook na mesma
instância, a última a chamar `/webhook/set` apagaria a da outra,
silenciosamente. Decisão (2026-09-10): **cada app pareia seu próprio
número/instância** — separado por ora, unificação é trabalho futuro (ver
memória `project_integrar_reconhecimento_facial`). `portal_ti` é exclusiva
do portal-glpi; `evo_set_webhook()` e todo o cliente REST
(`wpp/evo_api.php`) já são escopados só por `EVO_INSTANCE` ('portal_ti'),
então nenhuma mudança de código foi necessária — só confirmar que as
outras duas apps não reusam esse nome de instância.

## Decisões travadas (do brainstorming 2026-09-10)

| # | Tema | Decisão |
|---|---|---|
| 1 | Escopo | Paridade total: abrir + consultar + imagem |
| 2 | Requerente | **Vinculado** (telefone → usuário GLPI): pula pro título, requerente = usuário vinculado. **Não vinculado**: bot lista loja → usuários da loja; requerente = usuário escolhido |
| 3 | Anti-abuso | Não vinculado gera **pendência** em `portal_wpp_pendencias`; técnico aprova → aí sim cria no GLPI |
| 4 | Seleção de loja/usuário | **Lista interativa** do WhatsApp (`sendList`) é a preferência; **fallback** automático pra menu numerado (`1 - Loja A`) se o número não suportar ou responder em texto |
| 5 | Vínculo telefone→usuário | Aba manual no portal (**N números por usuário** — ex. "SAC Santos Bonito" com várias linhas) **+** match automático por `glpi_users.phone`/`mobile` na 1ª mensagem |
| 6 | Aprovação de pendência | Aba **Pendências** em `config_whatsapp.php` + 1 aviso no grupo TI·Chamados (via worker) |
| 7 | Consulta | Número só consulta os chamados que **ele mesmo** abriu pelo bot (`portal_wpp_chamados`) |
| 8 | Desfecho pós-timeout | Se a conversa expirou antes da decisão do técnico, o bot ainda manda **1** mensagem de resultado por pendência (`resultado_enviado`) |
| 9 | Aba Autorizados | **Removida** do plano original — o gate virou a pendência, não uma allowlist prévia |

## Componentes

Tudo em `wpp/`, mesmo padrão dos arquivos existentes: sem HTML, funções isoladas, retorno em array, nunca lançam exceção.

### `wpp/webhook.php` (novo) — endpoint que a Evolution chama

- Recebe POST da Evolution nos eventos `MESSAGES_UPSERT` e `CONNECTION_UPDATE`.
- URL interna: `http://glpi-web/glpi2/portal-glpi/wpp/webhook.php` (a confirmar o path exato da montagem no deploy).
- **Responde 200 sempre e rápido.** Processamento do chatbot é síncrono aqui (a conversa precisa de resposta em segundos), mas com timeouts curtos nas chamadas à API do GLPI; se estourar, o bot pede pra repetir.
- `CONNECTION_UPDATE` → atualiza `conn_status`/`conn_ultima` em `portal_wpp_config`. Zero mensagem.
- `MESSAGES_UPSERT`:
  1. `on_chatbot` = 1? senão descarta.
  2. dedup: `message.id` já em `portal_wpp_msgs_vistas`? descarta. Senão insere.
  3. `wpp_origem_permitida($msg)`? senão loga e descarta.
  4. `wpp_chatbot_processar($telefone, $entrada)`.

### `wpp/chatbot.php` (novo) — máquina de estados

- Estado por telefone em `portal_wpp_conversas` (`telefone` PK, `estado` JSON, `updated_at`).
- `estado` JSON: `{ passo, requerente_id?, entities_id?, opcoes?, titulo?, descricao?, midia?, criando? }`.
- Entrada normalizada: texto puro, ou a seleção de uma lista interativa (`listResponseMessage` → id da opção), ou mídia (imagem).
- Passos: `menu` → (`abrir` | `consultar` | `sair`).
  - **abrir**: se vinculado → `titulo`; senão → `escolhe_loja` → `escolhe_usuario` → `titulo` → `descricao` (loop "adicionar mais? 1/2/3") → cria ou pendência.
  - **consultar**: `pede_numero` → valida em `portal_wpp_chamados` → mostra → volta a `menu`.
- Toda resposta do bot sai por `evo_send_text` / `evo_send_list` / `evo_send_media` → guardrail de saída.
- Timeout: o **worker** varre `portal_wpp_conversas` (`updated_at` < agora − `chatbot_timeout_min`). > timeout e < 30 min → manda "⏳ tempo esgotado" e apaga. > 30 min → apaga calado.

### `wpp/glpi_bot.php` (novo) — fininho sobre a API do GLPI, pro bot

Reusa `agenda/config.php` (`GLPI_URL`, `GLPI_APP_TOKEN`, `GLPI_USER`, `GLPI_PASS`) e o padrão de `agenda/criar_ticket.php` (initSession Basic → opera → killSession).

```php
function bot_lojas(): array           // entidades filhas (level > 1, exclui a raiz "Grupo Gmais")
function bot_usuarios_loja(int $entities_id): array   // glpi_users ativos daquela entidade
function bot_criar_chamado(int $requerente_id, int $entities_id, string $titulo,
                           string $descricao, ?array $midia): array   // -> ['ok','ticket_id']
function bot_consultar_chamado(int $ticket_id): array // titulo, status, datas, followups[], imagens[]
```

`bot_lojas` / `bot_usuarios_loja` podem ir por SQL direto no `glpi2` (mais rápido que a REST e o portal já faz isso em `notificacoes.php`/`alertas.php`) — decisão de implementação, não trava aqui.

### `wpp/evo_api.php` (estende) — 2 funções novas

```php
function evo_send_list(string $destino, string $titulo, string $texto,
                       string $botao, array $secoes): array
// POST /message/sendList/{inst}. Passa pelo evo_guarded_send como os outros.
// Se a Evolution devolver erro de recurso não suportado, o chamador cai no menu numerado.

function evo_download_media(array $msg): array
// baixa a mídia de uma mensagem recebida -> ['ok','base64','mime','tamanho']
// POST /chat/getBase64FromMediaMessage/{inst}
```

### `wpp/guardrails.php` (estende) — `wpp_origem_permitida` de fato

```php
function wpp_origem_permitida(array $msg): bool
// true SOMENTE se:
//   - remoteJid termina em @s.whatsapp.net ou @c.us  (privado)
//   - NÃO termina em @g.us  (grupo)  ← qualquer grupo, inclusive os 2 cadastrados
//   - NÃO é @broadcast / status@broadcast
//   - !fromMe
//   - messageTimestamp >= (agora - 120s)
// Loga a recusa em portal_wpp_log (direcao 'in', status 'bloqueado').
```

Extensão do guardrail de **saída** (`wpp_destino_permitido`, já existe): passa a aceitar também um `@s.whatsapp.net` que tenha linha ativa em `portal_wpp_conversas` **ou** uma `portal_wpp_pendencias` com `resultado_enviado = 0`. Sem isso o guardrail barra a própria resposta do bot.

### `evo_set_webhook()` — registra/remove o webhook na Evolution

Chamada pelo botão "Ativar/desativar chatbot" na aba Conexão, e/ou quando `on_chatbot` vira 1.
`POST /webhook/set/{inst}` com `url`, `webhookByEvents: false` (nome do campo na Evolution API v2 — camelCase, não `webhook_by_events`), `headers: {"X-Wpp-Secret": WPP_WEBHOOK_SECRET}` (segredo compartilhado que a Evolution reenvia em todo delivery e o `webhook.php` confere), `events: ["MESSAGES_UPSERT","CONNECTION_UPDATE"]`. Desativar → `enabled: false`.

## Tabelas novas

`CREATE TABLE IF NOT EXISTS` no topo de `wpp/db.php` e/ou `config_whatsapp.php`, padrão do portal.

```
portal_wpp_conversas   (telefone VARCHAR(32) PRIMARY KEY,
                        estado JSON NOT NULL,
                        updated_at DATETIME NOT NULL)

portal_wpp_vinculos    (id INT AI PK,
                        telefone VARCHAR(32) NOT NULL UNIQUE,
                        glpi_user_id INT NOT NULL,
                        rotulo VARCHAR(120) NULL,
                        ativo TINYINT NOT NULL DEFAULT 1,
                        KEY (glpi_user_id))

portal_wpp_pendencias  (id INT AI PK,
                        telefone VARCHAR(32) NOT NULL,
                        entities_id INT NOT NULL,
                        glpi_user_id INT NOT NULL,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT NOT NULL,
                        midia_json MEDIUMTEXT NULL,
                        status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
                        ticket_id INT NULL,
                        motivo_recusa VARCHAR(255) NULL,
                        resultado_enviado TINYINT NOT NULL DEFAULT 0,
                        criado_em DATETIME NOT NULL,
                        decidido_em DATETIME NULL,
                        decidido_por INT NULL,
                        KEY (status), KEY (criado_em))

portal_wpp_chamados    (id INT AI PK,
                        telefone VARCHAR(32) NOT NULL,
                        ticket_id INT NOT NULL,
                        origem ENUM('vinculado','pendencia') NOT NULL,
                        criado_em DATETIME NOT NULL,
                        UNIQUE (telefone, ticket_id))

portal_wpp_msgs_vistas (message_id VARCHAR(128) PRIMARY KEY,
                        visto_em DATETIME NOT NULL)
```

Reusa: `portal_wpp_log`, `portal_wpp_config`, `portal_wpp_contatos`.

Novas chaves em `portal_wpp_config`: `on_chatbot` ('0'), `chatbot_timeout_min` ('5'), `wm_chatbot_pend` (watermark do aviso de pendência no grupo).

## Telas — `config_whatsapp.php`

| Aba | Fase | Conteúdo |
|---|---|---|
| **Vínculos** (nova) | 3 | CRUD: número + busca de usuário GLPI + rótulo + ativo. Lista agrupada por usuário: "SAC Santos Bonito → 3 números". |
| **Pendências** (nova) | 3 | Fila `status = pendente`: telefone, loja, usuário, título, descrição, thumb da imagem. Botões **Aprovar** / **Recusar** (+ motivo). Badge com contador no nome da aba. Bloco recolhido com as decididas. |
| **Gatilhos** (estende) | 3 | + toggle "Chatbot de entrada" → `on_chatbot` |
| **Conexão** (estende) | 3 | + botão "Ativar/desativar chatbot" → `evo_set_webhook()` |

Ações da aba Pendências:
- **Aprovar**: `bot_criar_chamado(glpi_user_id, entities_id, titulo, descricao, midia)` → em caso de sucesso grava `ticket_id`, `status='aprovado'`, `decidido_em/por`, insere em `portal_wpp_chamados` (origem `pendencia`), e marca pra o desfecho ser enviado. Idempotente: `status != pendente` → não recria, devolve o `ticket_id` gravado. Falha no POST → continua `pendente`, retentável.
- **Recusar**: `status='recusado'`, `motivo_recusa`, `decidido_em/por`, marca desfecho pra enviar.

Envio do desfecho: o **worker** (ou a própria ação, se a conversa ainda estiver viva) manda 1 mensagem — "✅ Chamado #123 criado" / "❌ Solicitação recusada: [motivo]" — só se `resultado_enviado = 0`, e seta `= 1` na mesma transação.

## Fluxos de conversa

**Menu** (qualquer entrada sem conversa ativa, `on_chatbot=1`, origem permitida):
> *O que você precisa?*
> 1 – Abrir chamado
> 2 – Consultar chamado
> 3 – Sair

### Fluxo A — Abrir, número VINCULADO
1. Resolve o número: `portal_wpp_vinculos` ativo → senão `glpi_users.phone`/`mobile` com **exatamente 1** match. 2+ matches ou nenhum → Fluxo B.
2. "Abrir chamado para **[Nome]**. Qual o título?"
3. título → "Descreva o problema."
4. descrição → "Adicionar mais? 1 Sim · 2 Não · 3 Cancelar" (1 volta a acumular; 2 finaliza; 3 aborta e limpa)
5. imagem em qualquer ponto após o título → `evo_download_media` → guarda no estado
6. finaliza → `estado.criando = true` → `bot_criar_chamado(requerente_id, entities_id do usuário, titulo, descricao, midia)` → "✅ Chamado #123 criado" → grava `portal_wpp_chamados` (origem `vinculado`) → limpa a conversa

### Fluxo B — Abrir, número NÃO vinculado
1. "Escolha a loja:" → `evo_send_list` com as lojas / fallback numerado
2. "Escolha o usuário:" → usuários da loja / fallback numerado
3. título → descrição (loop) → imagem opcional (igual A)
4. finaliza → grava `portal_wpp_pendencias` (`pendente`) → "Recebido. Sua solicitação está em análise pelo TI." → limpa a conversa
5. worker: 1 aviso no grupo TI·Chamados ("nova solicitação via WhatsApp aguardando aprovação"), dedup por `wm_chatbot_pend`
6. técnico decide na aba Pendências → bot manda o desfecho (ver Telas)

### Fluxo C — Consultar
1. "Digite o número do chamado."
2. `SELECT 1 FROM portal_wpp_chamados WHERE telefone=? AND ticket_id=?` — não achou → "Não encontrei esse chamado no seu histórico." → volta ao menu
3. `bot_consultar_chamado` → título, status, data de criação, últimas N atualizações, imagens dos follow-ups (`evo_send_media`) → volta ao menu

## Guardrails, cold-start e idempotência

| Risco | Proteção |
|---|---|
| Bot lê / responde em grupo | `wpp_origem_permitida`: qualquer `@g.us` → descarta. Respostas só pra `@s.whatsapp.net` individual. |
| Mensagem pra número frio / lista / broadcast | Chatbot só escreve pra número com conversa ativa; guardrail de saída rejeita o resto. Exceção única e explícita: 1 desfecho por pendência. |
| Evolution reenvia histórico ao reparear | webhook descarta `messageTimestamp < agora − 120s` |
| Webhook reentregue (retry) | `portal_wpp_msgs_vistas` — `message.id` visto → descarta antes de processar. Poda > 7 dias. |
| Worker religa com conversas velhas | sweep de timeout: 1 "tempo esgotado" por conversa, e só se `updated_at` > agora − 30 min; mais velho → apaga calado |
| `on_chatbot` 0→1 com pendências antigas | aviso no grupo só pra `criado_em > wm_chatbot_pend`; na 1ª ativação `wm_chatbot_pend = NOW()` (semeia sem avisar) |
| Chamado duplicado no Fluxo A | `estado.criando = true` antes do POST; webhook reentrante vê a flag e responde "processando…" |
| Aprovação clicada 2× | `status != pendente` → devolve o `ticket_id` existente, não recria; botão desabilita no clique |
| GLPI fora do ar (Fluxo A) | "Sistema indisponível, tente em alguns minutos. Seus dados foram guardados." — mantém o estado 15 min pra repetir sem redigitar |
| GLPI fora do ar (Fluxo B) | a pendência já está salva; só o `Aprovar` falha e fica retentável |
| 2 avisos de desfecho | `resultado_enviado` TINYINT, checado e setado na mesma transação |
| Instância desconectada | sem conexão não chega webhook de mensagem; `CONNECTION_UPDATE` atualiza a tela; conversas no meio expiram no timeout |

## Imagem

- `evo_download_media` na hora que a imagem chega.
- Fluxo A: anexa no GLPI logo após criar o chamado (`POST /Document` com o base64 + `Document_Item` ligando ao ticket), padrão do legado.
- Fluxo B: base64 fica em `portal_wpp_pendencias.midia_json` até a aprovação; anexa no ato de aprovar.
- Limites: 1ª imagem só (paridade com o legado), `image/*` apenas, máx ~5 MB. Outro tipo → "Só consigo anexar imagem."

## Testes

`wpp/tests/`, rodados com o worker **parado**, contra o banco `glpi2`.

| Arquivo | Cobre |
|---|---|
| `test_origem_permitida.php` | grupo / broadcast / fromMe / timestamp velho → rejeita; privado recente → aceita |
| `test_dedup.php` | `message_id` repetido não reprocessa |
| `test_chatbot_fsm.php` | menu→abrir→loja→usuário→título→descrição→cria; cancelar; opção inválida; loop "adicionar mais" |
| `test_vinculo.php` | resolve telefone: aba → `glpi_users.phone` → 2+ matches vira não-vinculado |
| `test_pendencia.php` | criar; aprovar idempotente; recusar; `resultado_enviado` trava o 2º envio |
| `test_consulta.php` | número só vê chamado que abriu; de terceiro → negado |
| `test_cold_start.php` | subir com conversas/pendências antigas → **0 envio** |
| `test_evo_list.php` | `evo_send_list` monta o payload certo; caminho de fallback quando a resposta vem como texto |

## Ordem de entrega

Branch `feat/wpp-fase3-chatbot` a partir de `infra/migracao-docker-glpi`. Cada etapa faz deploy independente e é cold-start-safe.

| Passo | Entrega | Deploy |
|---|---|---|
| **Spike 0** | webhook temporário + `evo_send_list` num número real → a lista interativa renderiza no WhatsApp das lojas (Android e iPhone)? Resultado decide se a lista é o caminho padrão ou fica só o fallback. ~1h. | não |
| **Etapa 1** | `webhook.php` + `wpp_origem_permitida` + dedup + `evo_set_webhook` + toggle `on_chatbot`. Bot ainda mudo (só loga "recebido de X"). Confirmar que grupo/broadcast/replay não passam. | sim |
| **Etapa 2** | `chatbot.php` (FSM) + Fluxo A (vinculado) + aba Vínculos + `glpi_bot.php` (criar). Abre chamado ponta a ponta pra número cadastrado. | sim |
| **Etapa 3** | Fluxo B + `portal_wpp_pendencias` + aba Pendências + aviso no grupo + desfecho | sim |
| **Etapa 4** | Fluxo C (consulta) + `portal_wpp_chamados` + imagens dos follow-ups | sim |
| **Etapa 5** | imagem na abertura (download + anexo no GLPI) + lista interativa habilitada (se o Spike 0 aprovou) + polimento de textos | sim |

## Fora de escopo

- Trazer o subsistema `Reconhecimento-facial` pro portal.
- Chatbot em grupo, ou qualquer leitura de grupo.
- Envio pra requerentes/clientes que não iniciaram conversa (além do 1 desfecho por pendência).
- Fluxo de mais de uma imagem por chamado.
- Vínculo automático de número após aprovação (fica manual na aba Vínculos).
- Reabertura / follow-up de chamado existente pelo bot (só abrir e consultar).
