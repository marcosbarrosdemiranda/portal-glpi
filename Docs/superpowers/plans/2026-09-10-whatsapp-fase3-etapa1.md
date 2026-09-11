# WhatsApp Fase 3 — Etapa 1 (infra de entrada) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer a Evolution conseguir entregar mensagens recebidas pro portal com segurança (grupo/broadcast/replay/retry nunca passam), sem ainda ter um chatbot — só confirma no log que a origem foi aceita. É o alicerce das Etapas 2–5 (FSM, fluxos, consulta, imagem), que ganham planos próprios depois que esta aqui estiver em produção.

**Architecture:** Webhook HTTP (`wpp/webhook.php`) recebido da Evolution, parsing puro e testável (`wpp/webhook_parse.php`), dedup por `message.id` e guardrail de origem (`wpp_origem_permitida`) — ambos com tabela/lógica nova em `wpp/db.php`/`wpp/guardrails.php`. Ativação/desativação do webhook fica numa tela nova (`config_whatsapp.php`, aba Conexão) que chama `evo_set_webhook()`. Um toggle `on_chatbot` (aba Gatilhos) corta tudo com 1 clique.

**Tech Stack:** PHP 8.2 + PDO (MariaDB `glpi2`), mini-harness de testes próprio (`wpp/tests/`, sem PHPUnit), Bootstrap 5 (tela já existente), Evolution API (Baileys) como gateway WhatsApp.

**Spec:** `Docs/superpowers/specs/2026-09-10-whatsapp-fase3-chatbot-design.md`

## Global Constraints

- Todo arquivo em `wpp/*.php` (exceto `webhook.php`, que é o entrypoint HTTP): sem HTML, funções isoladas, retorno em array, **nunca lança exceção**.
- Comentários no código em português (convenção do projeto).
- `webhook.php` responde **200 sempre**, mesmo com payload inválido ou erro interno — nunca deixa a Evolution reentregando em loop. Todo o corpo roda dentro de `try/catch`.
- Nunca processar mensagem de grupo (`@g.us`), `@broadcast`/`status@broadcast`, ou mensagem própria (`fromMe`).
- Nunca despejar histórico: mensagem com `messageTimestamp` mais velho que 120s é descartada.
- Testes rodam com `php wpp/tests/run.php` (harness em `wpp/tests/assert.php`: `t_ok`/`t_eq`/`t_report`), **contra o banco de produção** — toda fixture que muta uma tabela compartilhada (`portal_wpp_config`, etc.) roda em `try/finally` restaurando o valor original, igual `wpp/tests/test_guardrails.php`.
- Tabelas novas: `CREATE TABLE IF NOT EXISTS` no topo de `wpp/db.php`, mesmo padrão das existentes (InnoDB, utf8mb4).
- No servidor os testes rodam via `docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php`.

---

## Task 0: Spike — a lista interativa do WhatsApp renderiza?

Não é código de produção — é uma checagem manual antes de comprometer o desenho das Etapas 2+ (fallback numerado vs. lista). Não segue TDD; é um checklist.

**Arquivos:** nenhum arquivo de produção. Script descartável em `wpp/tests/manual/spike_sendlist.php` (não entra no `run.php` — fora do padrão `test_*.php`).

- [ ] **Passo 1: Escrever o script descartável**

```php
<?php
// Spike descartável — NÃO faz parte da suíte de testes (glob de run.php só pega test_*.php).
// Roda uma vez, manualmente: php wpp/tests/manual/spike_sendlist.php <telefone_so_digitos>
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../evo_api.php';

$destino = $argv[1] ?? '';
if ($destino === '') { fwrite(STDERR, "uso: php spike_sendlist.php <telefone>\n"); exit(1); }

// Número de teste precisa estar em portal_wpp_contatos ou portal_wpp_autorizados
// (ativo=1) pra passar no guardrail de saída — cadastre antes de rodar.
$r = evo_request('POST', '/message/sendList/' . EVO_INSTANCE, [
    'number'  => $destino . '@s.whatsapp.net',
    'title'   => 'Escolha a loja',
    'description' => 'Spike Fase 3 — teste de lista interativa',
    'buttonText' => 'Ver opções',
    'sections' => [[
        'title' => 'Lojas',
        'rows'  => [
            ['title' => 'Loja Teste A', 'rowId' => 'loja_1'],
            ['title' => 'Loja Teste B', 'rowId' => 'loja_2'],
        ],
    ]],
]);
echo json_encode($r, JSON_PRETTY_PRINT), "\n";
```

- [ ] **Passo 2: Cadastrar um número de teste real** (seu celular, ou o de teste do TI) em `portal_wpp_contatos` ou na aba Contatos do portal, ativo=1.

- [ ] **Passo 3: Rodar no servidor e observar o celular**

```
docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/manual/spike_sendlist.php 55679XXXXXXXX
```

Testar em pelo menos 1 Android e 1 iPhone, WhatsApp atualizado.

- [ ] **Passo 4: Registrar o resultado** — anotar no topo do spec (`2026-09-10-whatsapp-fase3-chatbot-design.md`, seção "Decisão #4") se a lista renderizou em ambos, só um, ou nenhum. Essa nota decide se `evo_send_list` (Etapa 2+) é o caminho padrão ou fica só como tentativa com fallback imediato.

- [ ] **Passo 5: Apagar o script** (`rm wpp/tests/manual/spike_sendlist.php`) — é descartável, não commita.

---

## Task 1: Dedup de webhook — tabela + funções

**Files:**
- Modify: `wpp/db.php` (fim do arquivo)
- Test: Create `wpp/tests/test_msgs_vistas.php`

**Interfaces:**
- Produces: `wpp_msg_ja_vista(string $message_id): bool`, `wpp_marcar_msg_vista(string $message_id): void` — usadas por `wpp/webhook.php` (Task 5).

- [ ] **Step 1: Escrever o teste (vai falhar — função não existe)**

Criar `wpp/tests/test_msgs_vistas.php`:

```php
<?php
// Testes de dedup de mensagens recebidas. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
global $pdo;

$idTeste = 'TESTE_MSG_' . bin2hex(random_bytes(6));

try {
    t_ok(!wpp_msg_ja_vista($idTeste), 'mensagem nova: ainda nao vista');

    wpp_marcar_msg_vista($idTeste);
    t_ok(wpp_msg_ja_vista($idTeste), 'apos marcar: ja vista');

    // idempotente: marcar 2x nao lanca (INSERT IGNORE / ON DUPLICATE)
    wpp_marcar_msg_vista($idTeste);
    t_ok(wpp_msg_ja_vista($idTeste), 'marcar 2x nao quebra');
} finally {
    $pdo->exec("DELETE FROM portal_wpp_msgs_vistas WHERE message_id = " . $pdo->quote($idTeste));
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php wpp/tests/run.php`
Expected: erro fatal `Call to undefined function wpp_msg_ja_vista()` (ou `wpp_marcar_msg_vista`).

- [ ] **Step 3: Implementar** — no fim de `wpp/db.php`, depois de `wpp_marcar_notificado()`:

```php
// --- Fase 3: dedup de mensagens recebidas via webhook ---

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_msgs_vistas (
    message_id VARCHAR(128) PRIMARY KEY,
    visto_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// True se esse message.id da Evolution já foi processado (webhook reentregue).
function wpp_msg_ja_vista(string $message_id): bool {
    global $pdo;
    $st = $pdo->prepare("SELECT 1 FROM portal_wpp_msgs_vistas WHERE message_id = ? LIMIT 1");
    $st->execute([$message_id]);
    return (bool) $st->fetchColumn();
}

// Marca o message_id como visto. INSERT IGNORE — chamar 2x não quebra.
// Poda oportunista (1 em 20 chamadas): apaga vistos com mais de 7 dias, sem
// precisar de cron dedicado pra uma tabela que só cresce.
function wpp_marcar_msg_vista(string $message_id): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT IGNORE INTO portal_wpp_msgs_vistas (message_id, visto_em) VALUES (?, NOW())"
    );
    $st->execute([$message_id]);
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM portal_wpp_msgs_vistas WHERE visto_em < NOW() - INTERVAL 7 DAY");
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php wpp/tests/run.php`
Expected: `3 ok, 0 falhas` (mais os testes já existentes acumulados).

- [ ] **Step 5: Commit**

```bash
git add wpp/db.php wpp/tests/test_msgs_vistas.php
git commit -m "feat: dedup de mensagens recebidas (portal_wpp_msgs_vistas)"
```

---

## Task 2: `wpp_origem_permitida()` — guardrail de entrada

**Files:**
- Modify: `wpp/guardrails.php` (fim do arquivo)
- Test: Create `wpp/tests/test_origem_permitida.php`

**Interfaces:**
- Consumes: `wpp_log()` de `wpp/db.php` (já existe).
- Produces: `wpp_origem_permitida(array $msg): bool`, onde `$msg` é o formato normalizado que `wpp_extrair_msg()` (Task 4) produz: `['id'=>string,'remoteJid'=>string,'fromMe'=>bool,'timestamp'=>int, ...]`. Consumida por `wpp/webhook.php` (Task 5).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_origem_permitida.php`:

```php
<?php
// Testes do guardrail de ENTRADA. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guardrails.php';

function _msg(array $over = []): array {
    return array_merge([
        'id'        => 'MSGID1',
        'remoteJid' => '556799998888@s.whatsapp.net',
        'fromMe'    => false,
        'timestamp' => time(),
    ], $over);
}

t_ok(wpp_origem_permitida(_msg()), 'privado, recente, nao fromMe: permitida');

t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '120363412180101593@g.us'])), 'grupo: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => 'status@broadcast'])), 'status broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '123@broadcast'])), 'broadcast: BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['fromMe' => true])), 'mensagem propria (fromMe): BLOQUEADA');
t_ok(!wpp_origem_permitida(_msg(['timestamp' => time() - 121])), 'timestamp com 121s: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg(['timestamp' => time() - 119])), 'timestamp com 119s: permitida (dentro da janela)');
t_ok(!wpp_origem_permitida(_msg(['remoteJid' => '556799998888@newsletter'])), 'sufixo nao suportado: BLOQUEADA');
t_ok(wpp_origem_permitida(_msg(['remoteJid' => '556799998888@c.us'])), 'sufixo @c.us (legado): permitida');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php wpp/tests/run.php`
Expected: erro fatal `Call to undefined function wpp_origem_permitida()`.

- [ ] **Step 3: Implementar** — no fim de `wpp/guardrails.php`:

```php
// Guardrail de ENTRADA: decide se uma mensagem recebida pode acionar o
// chatbot. $msg é o array normalizado de wpp_extrair_msg() (webhook_parse.php).
// Nunca lança — qualquer falha aqui tem que fechar (bloquear), nunca abrir.
function wpp_origem_permitida(array $msg): bool {
    try {
        $jid = (string) ($msg['remoteJid'] ?? '');

        // só chat privado — @g.us (grupo), @broadcast e qualquer outro sufixo
        // (ex. @newsletter, @lid) ficam de fora por não estarem na allowlist abaixo
        $privado = str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us');
        if (!$privado) {
            wpp_log('in', $jid, 'origem nao privada (grupo/broadcast/outro)', 'bloqueado');
            return false;
        }

        if (!empty($msg['fromMe'])) {
            // mensagem do próprio bot ecoada de volta — não loga, é ruído normal
            return false;
        }

        $ts = (int) ($msg['timestamp'] ?? 0);
        if ($ts < time() - 120) {
            wpp_log('in', $jid, 'timestamp antigo (replay/history-sync)', 'bloqueado');
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        wpp_log('in', (string) ($msg['remoteJid'] ?? '?'), 'erro na verificacao: ' . $e->getMessage(), 'bloqueado');
        return false;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php wpp/tests/run.php`
Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/guardrails.php wpp/tests/test_origem_permitida.php
git commit -m "feat: guardrail de entrada wpp_origem_permitida"
```

---

## Task 3: Parser puro do payload da Evolution

**Files:**
- Create: `wpp/webhook_parse.php`
- Test: Create `wpp/tests/test_webhook_parse.php`

**Interfaces:**
- Produces: `wpp_extrair_msg(array $payload): ?array` — retorna `null` se o payload não tem uma mensagem reconhecível, senão `['id','remoteJid','fromMe','timestamp','texto','temMidia']`. Consumida por `wpp/webhook.php` (Task 5) e por `wpp_origem_permitida()` (Task 2, já escrita — o formato bate).

> O shape exato do webhook da Evolution API (v2, Baileys) não foi confirmado contra tráfego real ainda. Este parser cobre o formato documentado (`data` = objeto da mensagem, com `key.remoteJid/fromMe/id` e `messageTimestamp`) e um fallback pro formato `data.messages[0]`. **Task 7 (deploy) inclui conferir o payload real e ajustar se necessário** — o resto do pipeline (dedup + origem_permitida) já protege contra parsing incorreto: se o parser não reconhecer, `wpp_extrair_msg` devolve `null` e o webhook só ignora.

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_webhook_parse.php`:

```php
<?php
// Testes do parser do payload da Evolution. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../webhook_parse.php';

// formato v2: data = objeto da mensagem direto
$payloadV2 = [
    'event' => 'messages.upsert',
    'data'  => [
        'key' => ['remoteJid' => '556799998888@s.whatsapp.net', 'fromMe' => false, 'id' => 'ABC123'],
        'message' => ['conversation' => 'oi'],
        'messageTimestamp' => 1736450000,
    ],
];
$m = wpp_extrair_msg($payloadV2);
t_ok($m !== null, 'payload v2: reconhece');
t_eq($m['id'], 'ABC123', 'payload v2: id');
t_eq($m['remoteJid'], '556799998888@s.whatsapp.net', 'payload v2: remoteJid');
t_eq($m['fromMe'], false, 'payload v2: fromMe');
t_eq($m['timestamp'], 1736450000, 'payload v2: timestamp');
t_eq($m['texto'], 'oi', 'payload v2: texto (conversation)');
t_eq($m['temMidia'], false, 'payload v2: sem midia');

// timestamp como protobuf Long ({low: n})
$payloadLong = $payloadV2;
$payloadLong['data']['messageTimestamp'] = ['low' => 1736450001, 'high' => 0];
$m2 = wpp_extrair_msg($payloadLong);
t_eq($m2['timestamp'], 1736450001, 'timestamp formato Long: extrai o low');

// fallback: data.messages[0] (formato Baileys puro)
$payloadArr = [
    'event' => 'messages.upsert',
    'data'  => ['messages' => [[
        'key' => ['remoteJid' => '120363@g.us', 'fromMe' => false, 'id' => 'XYZ'],
        'message' => ['extendedTextMessage' => ['text' => 'resposta longa']],
        'messageTimestamp' => 1736450002,
    ]]],
];
$m3 = wpp_extrair_msg($payloadArr);
t_ok($m3 !== null, 'payload com messages[]: reconhece');
t_eq($m3['remoteJid'], '120363@g.us', 'payload com messages[]: remoteJid');
t_eq($m3['texto'], 'resposta longa', 'extendedTextMessage: texto');

// imagem
$payloadImg = $payloadV2;
$payloadImg['data']['message'] = ['imageMessage' => ['caption' => 'foto']];
$m4 = wpp_extrair_msg($payloadImg);
t_eq($m4['temMidia'], true, 'imageMessage: temMidia=true');

// payload sem key -> null
t_ok(wpp_extrair_msg(['event' => 'connection.update', 'data' => ['state' => 'open']]) === null, 'payload sem key: null');
// payload vazio -> null
t_ok(wpp_extrair_msg([]) === null, 'payload vazio: null');
// timestamp ausente -> usa "agora" (nunca deixa passar como "antigo")
$payloadSemTs = $payloadV2;
unset($payloadSemTs['data']['messageTimestamp']);
$m5 = wpp_extrair_msg($payloadSemTs);
t_ok($m5['timestamp'] >= time() - 2, 'sem timestamp: usa agora');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php wpp/tests/run.php`
Expected: erro fatal `Failed opening required '.../wpp/webhook_parse.php'` (arquivo ainda não existe).

- [ ] **Step 3: Implementar** — criar `wpp/webhook_parse.php`:

```php
<?php
// Parser puro do payload de webhook da Evolution API. Sem HTML, sem banco,
// nunca lança — payload não reconhecido devolve null, quem chama decide o
// que fazer (webhook.php simplesmente ignora).

// Normaliza o payload de um evento MESSAGES_UPSERT pro formato que
// wpp_origem_permitida() e o chatbot esperam. null se não reconhecer.
function wpp_extrair_msg(array $payload): ?array {
    $data = $payload['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }

    // fallback: alguns eventos vêm como data.messages[0] (formato Baileys puro)
    // em vez do objeto de mensagem direto (formato Evolution v2).
    if (isset($data['messages']) && is_array($data['messages'])) {
        $data = $data['messages'][0] ?? null;
        if (!is_array($data)) {
            return null;
        }
    }

    $key = $data['key'] ?? null;
    if (!is_array($key)) {
        return null;
    }

    $remoteJid = (string) ($key['remoteJid'] ?? '');
    $id        = (string) ($key['id'] ?? '');
    if ($remoteJid === '' || $id === '') {
        return null;
    }

    $ts = $data['messageTimestamp'] ?? null;
    if (is_array($ts)) {
        // protobuf Long serializado ({low, high, unsigned})
        $ts = $ts['low'] ?? 0;
    }
    $ts = (int) $ts;
    if ($ts <= 0) {
        // sem timestamp confiável: trata como agora, nunca como "antigo"
        // (senão wpp_origem_permitida descartaria por engano)
        $ts = time();
    }

    $message = is_array($data['message'] ?? null) ? $data['message'] : [];

    return [
        'id'        => $id,
        'remoteJid' => $remoteJid,
        'fromMe'    => (bool) ($key['fromMe'] ?? false),
        'timestamp' => $ts,
        'texto'     => wpp_extrair_texto($message),
        'temMidia'  => isset($message['imageMessage']),
    ];
}

// Extrai o texto de qualquer um dos formatos de mensagem que o bot precisa
// entender: texto simples, texto citado/longo, e a resposta de uma lista
// interativa ou de botões.
function wpp_extrair_texto(array $message): string {
    return (string) (
        $message['conversation']
        ?? $message['extendedTextMessage']['text']
        ?? $message['listResponseMessage']['title']
        ?? $message['buttonsResponseMessage']['selectedDisplayText']
        ?? ''
    );
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php wpp/tests/run.php`
Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add wpp/webhook_parse.php wpp/tests/test_webhook_parse.php
git commit -m "feat: parser do payload de webhook da Evolution (wpp_extrair_msg)"
```

---

## Task 4: Toggle `on_chatbot` (aba Gatilhos)

**Files:**
- Modify: `config_whatsapp.php` (case `gatilhos_ler`/`gatilhos_salvar`, HTML da aba Gatilhos, JS `GAT_TOGGLES`)

**Interfaces:**
- Consumes: `wpp_cfg_get`/`wpp_cfg_set` (já existem).
- Produces: chave de config `on_chatbot` ('0'/'1'), lida por `wpp/webhook.php` (Task 5) via `wpp_cfg_get('on_chatbot', '0')`.

Sem teste novo dedicado — é fiação de UI sobre `wpp_cfg_get/set`, já cobertos indiretamente pelos testes existentes de config. Verificação é manual (Step 4).

- [ ] **Step 1: Adicionar ao `gatilhos_ler`** — em `config_whatsapp.php`, dentro do array retornado pelo `case 'gatilhos_ler':` (arquivo atual, por volta da linha 142):

```php
        case 'gatilhos_ler':
            // Lê on/off dos 4 gatilhos + parâmetros de tempo, cada um com default.
            echo json_encode(['ok' => true, 'cfg' => [
                'on_novo'                => wpp_cfg_get('on_novo', '1'),
                'on_atribuido'           => wpp_cfg_get('on_atribuido', '1'),
                'on_alertas'             => wpp_cfg_get('on_alertas', '1'),
                'on_sla'                 => wpp_cfg_get('on_sla', '1'),
                'on_chatbot'             => wpp_cfg_get('on_chatbot', '0'),
                'cfg_delay_dm_min'       => (int) wpp_cfg_get('cfg_delay_dm_min', '5'),
                'cfg_digest_alertas_min' => (int) wpp_cfg_get('cfg_digest_alertas_min', '15'),
                'cfg_sla_horas'          => (int) wpp_cfg_get('cfg_sla_horas', '4'),
                'cfg_sla_prevenc_min'    => (int) wpp_cfg_get('cfg_sla_prevenc_min', '30'),
                'cfg_offline_reset_min'  => (int) wpp_cfg_get('cfg_offline_reset_min', '30'),
            ]]);
            break;
```

(único acréscimo: a linha `'on_chatbot' => ...`)

- [ ] **Step 2: Adicionar ao `gatilhos_salvar`** — mesmo case, um pouco abaixo (linha ~158):

```php
            // toggles: qualquer valor diferente de '1' vira '0'
            $toggles = ['on_novo', 'on_atribuido', 'on_alertas', 'on_sla', 'on_chatbot'];
```

(único acréscimo: `'on_chatbot'` na lista `$toggles` — o resto do case já grava qualquer chave dessa lista, sem mudança extra)

- [ ] **Step 3: HTML + JS da aba Gatilhos**

HTML — logo antes do bloco `<!-- SLA / parado -->` (por volta da linha 393), adicionar:

```html
      <!-- Chatbot de entrada (Fase 3) -->
      <div class="gat-bloco">
        <div class="form-check">
          <input type="checkbox" class="form-check-input gat-toggle" id="g-on_chatbot">
          <label class="form-check-label fw-semibold" for="g-on_chatbot">Chatbot de entrada (abrir/consultar chamado pelo WhatsApp)</label>
        </div>
        <span class="small text-muted">responde DM de quem escrever pro número do TI</span>
      </div>
```

JS — em `GAT_TOGGLES` (linha 862):

```js
  var GAT_TOGGLES = ['on_novo', 'on_atribuido', 'on_alertas', 'on_sla', 'on_chatbot'];
```

(`carregarGatilhos`/`salvarGatilhos` já iteram `GAT_TOGGLES` genericamente — nenhuma outra mudança de JS necessária. `on_chatbot` não tem `data-dep`/`gat-sub`, então `sincronizarGatilhos()` não precisa saber dele.)

- [ ] **Step 4: Verificar manualmente**

1. Abrir `config_whatsapp.php` → aba Gatilhos → confirmar que aparece "Chatbot de entrada", desmarcado por padrão.
2. Marcar → Salvar → recarregar a página → confirmar que continua marcado.
3. `SELECT valor FROM portal_wpp_config WHERE chave='on_chatbot'` → `1`.

- [ ] **Step 5: Commit**

```bash
git add config_whatsapp.php
git commit -m "feat: toggle on_chatbot na aba Gatilhos"
```

---

## Task 5: `wpp/webhook.php` — endpoint da Evolution

**Files:**
- Create: `wpp/webhook.php`

**Interfaces:**
- Consumes: `wpp_extrair_msg()` (Task 3), `wpp_msg_ja_vista()`/`wpp_marcar_msg_vista()` (Task 1), `wpp_origem_permitida()` (Task 2), `wpp_cfg_get()`/`wpp_cfg_set()`/`wpp_log()`/`wpp_agora_db()` (já existem em `wpp/db.php`).
- Produces: endpoint HTTP `wpp/webhook.php`. Etapa 2 (chatbot) troca o `wpp_log(...'chatbot ainda não implementado'...)` por uma chamada a `wpp_chatbot_processar()`.

Endpoint HTTP puro — sem teste automatizado (o mini-harness do projeto não cobre entrypoints HTTP, só funções `wpp/*.php`; ver `config_whatsapp.php`, que também não tem teste pros seus `case`). Verificação é manual, no Task 7.

- [ ] **Step 1: Criar `wpp/webhook.php`**

```php
<?php
// Endpoint chamado pela Evolution API (webhook da instância portal_ti).
// Responde 200 SEMPRE e rápido — a Evolution reentrega em loop se não for 2xx.
// Fase 3 Etapa 1: só valida a origem e loga. O chatbot em si (FSM, fluxos)
// entra na Etapa 2 — troca o wpp_log() do bloco "recebido" por
// wpp_chatbot_processar($msg['remoteJid'], $msg).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guardrails.php';
require_once __DIR__ . '/webhook_parse.php';

header('Content-Type: application/json');

$raw     = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);

try {
    if (is_array($payload)) {
        $evento = (string) ($payload['event'] ?? '');

        if ($evento === 'connection.update' || $evento === 'CONNECTION_UPDATE') {
            $estado = $payload['data']['state'] ?? null;
            if (is_string($estado) && $estado !== '') {
                global $pdo;
                wpp_cfg_set('conn_status', $estado);
                wpp_cfg_set('conn_ultima', wpp_agora_db($pdo));
            }
        } elseif ($evento === 'messages.upsert' || $evento === 'MESSAGES_UPSERT') {
            if (wpp_cfg_get('on_chatbot', '0') === '1') {
                $msg = wpp_extrair_msg($payload);
                if ($msg !== null && !wpp_msg_ja_vista($msg['id'])) {
                    wpp_marcar_msg_vista($msg['id']);
                    if (wpp_origem_permitida($msg)) {
                        // Etapa 1: chatbot ainda não existe. Etapa 2 troca esta
                        // linha por: wpp_chatbot_processar($msg['remoteJid'], $msg);
                        wpp_log('in', $msg['remoteJid'], 'recebido (chatbot ainda nao implementado)', 'ok');
                    }
                    // origem não permitida: wpp_origem_permitida() já logou o bloqueio
                }
                // já visto (reentrega) ou payload não reconhecido: silencioso, de propósito
            }
        }
    }
} catch (\Throwable $e) {
    // nunca deixa o webhook cair em erro pra Evolution — só registra
    wpp_log('sys', 'webhook', 'erro: ' . $e->getMessage(), 'erro');
}

http_response_code(200);
echo json_encode(['ok' => true]);
```

- [ ] **Step 2: Verificar sintaxe**

Run: `php -l wpp/webhook.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Teste manual local (sem Evolution)** — simula um POST:

```bash
php -r '
$payload = json_encode(["event"=>"messages.upsert","data"=>["key"=>["remoteJid"=>"556799998888@s.whatsapp.net","fromMe"=>false,"id"=>"TESTE1"],"message"=>["conversation"=>"oi"],"messageTimestamp"=>time()]]);
file_put_contents("php://stdin", $payload);
' # confirma que o JSON é válido; o teste de verdade é via curl no Task 7 (servidor)
```

(A checagem completa fim-a-fim — `on_chatbot=1`, POST real, log gravado — é o Task 7, contra o banco/servidor de verdade.)

- [ ] **Step 4: Commit**

```bash
git add wpp/webhook.php
git commit -m "feat: wpp/webhook.php - endpoint de entrada da Evolution (Etapa 1: so loga)"
```

---

## Task 6: `evo_set_webhook()` + botão "Ativar/desativar chatbot"

**Files:**
- Modify: `wpp/evo_api.php` (novas funções)
- Modify: `wpp/config.example.php` (nova constante)
- Modify: `config_whatsapp.php` (novo `case`, HTML e JS na aba Conexão)
- Test: Create `wpp/tests/test_evo_webhook.php`

**Interfaces:**
- Produces: `evo_set_webhook(bool $ligar): array` (`['ok','erro']`), `evo_webhook_status(): array` (`['ok','ativo','erro']`).

- [ ] **Step 1: Escrever o teste (só a parte testável sem rede — a guarda de config ausente)**

Criar `wpp/tests/test_evo_webhook.php`:

```php
<?php
// Teste do guard de evo_set_webhook (não faz chamada de rede real — só
// confirma que falha limpo sem WPP_WEBHOOK_URL configurada).
require_once __DIR__ . '/../evo_api.php';

if (!defined('WPP_WEBHOOK_URL') || WPP_WEBHOOK_URL === '') {
    $r = evo_set_webhook(true);
    t_ok($r['ok'] === false, 'sem WPP_WEBHOOK_URL: evo_set_webhook(true) falha limpo');
    t_ok(!empty($r['erro']), 'sem WPP_WEBHOOK_URL: erro explica o motivo');
} else {
    // ambiente já tem a constante (ex: servidor de produção) — pula, sem rede real no teste
    t_ok(true, 'WPP_WEBHOOK_URL configurada neste ambiente — guard não testável aqui, pulado');
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php wpp/tests/run.php`
Expected: erro fatal `Call to undefined function evo_set_webhook()`.

- [ ] **Step 3: Implementar** — em `wpp/config.example.php`, adicionar (junto das outras `define()`):

```php
// URL do webhook, alcançável DE DENTRO do container evolution-api até o
// glpi-web. Ajuste o path se a montagem do portal no container for outra.
define('WPP_WEBHOOK_URL', 'http://glpi-web/glpi2/portal-glpi/wpp/webhook.php');
```

Em `wpp/evo_api.php`, no fim do arquivo:

```php
// Liga/desliga o webhook de mensagens da instância. Sem WPP_WEBHOOK_URL
// definida (config.php desatualizado), falha limpo sem tentar a rede.
function evo_set_webhook(bool $ligar): array {
    if ($ligar && (!defined('WPP_WEBHOOK_URL') || WPP_WEBHOOK_URL === '')) {
        return ['ok' => false, 'erro' => 'WPP_WEBHOOK_URL não configurada em wpp/config.php'];
    }
    $body = $ligar
        ? ['webhook' => [
              'enabled'         => true,
              'url'             => WPP_WEBHOOK_URL,
              'webhookByEvents' => false,
              'events'          => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
          ]]
        : ['webhook' => ['enabled' => false]];
    $r = evo_request('POST', '/webhook/set/' . EVO_INSTANCE, $body, 15);
    return ['ok' => $r['ok'], 'erro' => $r['erro']];
}

// Estado atual do webhook na Evolution (pra pintar o botão na tela).
function evo_webhook_status(): array {
    $r = evo_request('GET', '/webhook/find/' . EVO_INSTANCE, null, 10);
    $ativo = is_array($r['data']) && !empty($r['data']['enabled']);
    return ['ok' => $r['ok'], 'ativo' => $ativo, 'erro' => $r['erro']];
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php wpp/tests/run.php`
Expected: `0 falhas`.

- [ ] **Step 5: UI — `case` novo em `config_whatsapp.php`**, junto dos outros da aba Conexão (depois de `case 'logout':`, por volta da linha 65):

```php
        case 'chatbot_webhook_status':
            $st = evo_webhook_status();
            echo json_encode(['ok' => $st['ok'], 'ativo' => $st['ativo'], 'erro' => $st['erro']]);
            break;
        case 'chatbot_webhook_toggle':
            // ação que liga/desliga recepção de mensagem: exige POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
            $ligar = (($_POST['ligar'] ?? '') === '1');
            echo json_encode(evo_set_webhook($ligar));
            break;
```

- [ ] **Step 6: UI — HTML na aba Conexão** (depois do bloco `#qr-wrap`, antes de `<div class="feedback" id="fb-conexao">`, por volta da linha 291):

```html
      <hr class="my-3">
      <div class="d-flex align-items-center gap-2">
        <span class="small text-muted">Chatbot de entrada:</span>
        <span id="chatbot-webhook-estado" class="small fw-semibold text-muted">verificando…</span>
        <button class="btn btn-outline-primary btn-sm" id="btn-chatbot-webhook-toggle">Ativar/desativar</button>
      </div>
      <p class="small text-muted mt-1">Precisa do toggle "Chatbot de entrada" ligado na aba Gatilhos também.</p>
```

- [ ] **Step 7: UI — JS**, no bloco de funções de Conexão (perto de `desconectar()`, por volta da linha 656):

```js
  function carregarChatbotWebhook() {
    fetch(PAGE + '?action=chatbot_webhook_status')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        $('chatbot-webhook-estado').textContent = d.ok ? (d.ativo ? 'ativo' : 'desativado') : 'erro ao verificar';
      })
      .catch(function () { $('chatbot-webhook-estado').textContent = 'erro ao verificar'; });
  }

  function alternarChatbotWebhook() {
    var btn = $('btn-chatbot-webhook-toggle');
    var ligarAgora = $('chatbot-webhook-estado').textContent.trim() !== 'ativo';
    btn.disabled = true;
    fetch(PAGE + '?action=chatbot_webhook_toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ligar=' + (ligarAgora ? '1' : '0')
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { feedback($('fb-conexao'), 'err', 'Erro: ' + (d.erro || 'falha ao alternar')); return; }
        carregarChatbotWebhook();
      })
      .catch(function (err) {
        btn.disabled = false;
        feedback($('fb-conexao'), 'err', 'Erro de conexão: ' + (err.message || err));
      });
  }

  $('btn-chatbot-webhook-toggle').addEventListener('click', alternarChatbotWebhook);
  carregarChatbotWebhook();
```

(o `carregarChatbotWebhook()` no fim roda 1x ao carregar a página, junto dos outros `carregarStatus()` já existentes)

- [ ] **Step 8: Verificar manualmente**

1. Servidor com `WPP_WEBHOOK_URL` configurada em `wpp/config.php` (copiar de `config.example.php` e ajustar se o path da montagem for diferente).
2. Aba Conexão → "Ativar/desativar" → estado vira "ativo".
3. `docker exec glpi-web php -r 'require "/var/www/html/glpi2/portal-glpi/wpp/evo_api.php"; var_dump(evo_webhook_status());'` → `ativo: true`.
4. Clicar de novo → "desativado".

- [ ] **Step 9: Commit**

```bash
git add wpp/evo_api.php wpp/config.example.php config_whatsapp.php wpp/tests/test_evo_webhook.php
git commit -m "feat: evo_set_webhook + botao ativar/desativar chatbot na aba Conexao"
```

---

## Task 7: Deploy + runbook + verificação fim-a-fim

**Files:**
- Modify: `wpp/README.md` (nova seção "FASE 3 - ETAPA 1")

Sem TDD — é runbook + checklist de deploy, mesmo formato das seções Fase 1/Fase 2 já existentes no arquivo.

- [ ] **Step 1: Escrever a seção no README**

Adicionar ao fim de `wpp/README.md`:

```
====================================================================
 FASE 3 - ETAPA 1 - INFRA DE ENTRADA (WEBHOOK)
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * wpp/webhook.php recebe eventos da Evolution (MESSAGES_UPSERT,
    CONNECTION_UPDATE). Nesta etapa NAO tem chatbot: so confirma que
    a mensagem passou pelo guardrail de origem e loga. Nada responde
    ainda - isso e Etapa 2.
  * Toggle "Chatbot de entrada" na aba Gatilhos (on_chatbot) - precisa
    estar LIGADO pro webhook processar qualquer coisa.
  * Botao "Ativar/desativar" na aba Conexao registra/remove o webhook
    na Evolution (evo_set_webhook).

ARQUIVOS A SINCRONIZAR
----
  - wpp/db.php
  - wpp/guardrails.php
  - wpp/webhook_parse.php (novo)
  - wpp/webhook.php (novo)
  - wpp/evo_api.php
  - wpp/config.example.php
  - config_whatsapp.php
  - wpp/tests/ (pasta inteira, arquivos novos)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] wpp/config.php do servidor tem WPP_WEBHOOK_URL definida (copie de
    config.example.php; ajuste o path se a montagem do portal dentro
    do container evolution-api/glpi-web for diferente de
    "http://glpi-web/glpi2/portal-glpi/wpp/webhook.php").

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima pro servidor.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas".
[ ] 3. Aba Gatilhos -> ligar "Chatbot de entrada" -> Salvar.
[ ] 4. Aba Conexao -> "Ativar/desativar" -> confirma "ativo".

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. De um numero QUALQUER (nao precisa estar cadastrado em nada),
       manda uma mensagem privada pra linha do TI: "oi".
[ ] 2. Confere o log:
         docker exec glpi-db mariadb -uroot -proot_password glpi2 \
           -e "SELECT criado_em,direcao,destino,resumo,status FROM portal_wpp_log ORDER BY id DESC LIMIT 5;"
       Espera: 1 linha direcao='in', status='ok', resumo contendo
       "recebido (chatbot ainda nao implementado)".
[ ] 3. CONFIRME QUE O PAYLOAD BATEU COM O ESPERADO: se a linha do
       passo 2 NAO aparecer (nada foi logado), o parser
       (wpp_extrair_msg) provavelmente nao reconheceu o formato real
       do payload da Evolution. Adicione um log temporario em
       wpp/webhook.php logo apos "$payload = json_decode(...)":
         wpp_log('sys', 'webhook-debug', substr($raw, 0, 500), 'raw');
       Rode o teste de novo, veja o payload real em portal_wpp_log, e
       ajuste wpp_extrair_msg() (wpp/webhook_parse.php) pra bater com
       o formato encontrado. Remova o log de debug depois.
[ ] 4. Repete o passo 1 de dentro de um GRUPO (ex: TI - Chamados) ->
       NAO deve aparecer nenhuma linha nova em portal_wpp_log (grupo
       e sempre ignorado, nem loga bloqueio de proposito - ruido
       esperado).
[ ] 5. Manda a MESMA mensagem 2x rapido (reentrega) -> so 1 linha no
       log (dedup por message.id).
[ ] 6. Aba Gatilhos -> desligar "Chatbot de entrada" -> Salvar. Manda
       "oi" de novo -> NADA no log (on_chatbot=0 corta tudo).

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desligar "Chatbot de entrada" (para o processamento
na hora, sem precisar reverter arquivo). Pra tirar o webhook da
Evolution: aba Conexao -> "Ativar/desativar" ate mostrar "desativado".

====================================================================
 FIM - FASE 3 ETAPA 1
====================================================================
```

- [ ] **Step 2: Rodar a checklist de deploy inteira no servidor** (todos os `[ ]` acima).

- [ ] **Step 3: Commit**

```bash
git add wpp/README.md
git commit -m "docs: runbook Fase 3 Etapa 1 (webhook de entrada)"
```

---

## Depois desta etapa

Com Etapa 1 em produção e o Spike 0 respondido, a Etapa 2 (FSM + Fluxo A/vinculado + aba Vínculos) ganha seu próprio plano — `docs/superpowers/plans/YYYY-MM-DD-whatsapp-fase3-etapa2.md` — escrito depois que esta estiver deployada e verificada, já incorporando o resultado real do spike e qualquer ajuste de `wpp_extrair_msg` feito no Task 7.
