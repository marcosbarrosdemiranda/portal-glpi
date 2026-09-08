# Central de Alertas — motor configurável

**Data:** 2026-09-08
**Status:** Em revisão

## Contexto

`alertas.php` (Central de Alertas) hoje tem **2 checagens fixas no código**:
`alertas_sem_inventario()` e `alertas_disco_cheio()` em `alertas_lib.php` (SQL
direto no `glpi2`). A tela agrupa e renderiza; o auto-refresh de 45s
(`?action=dados`) já existe. O bloco "Em construção" da tela lista ~15 tipos
futuros de alerta de fontes bem diferentes (ping, pfSense, UniFi, Home
Assistant, painel de LED…).

Do lado do WhatsApp (Fase 2 da integração), o gatilho `gat_alertas` em
`wpp/gatilhos.php` manda um **digest** dos alertas pro grupo "Alertas": a cada
passada compara `alertas_snapshot()` com o snapshot anterior (`wpp_snap_alertas`
em `portal_wpp_config`), e se há item novo E passou `cfg_digest_alertas_min`
(default 15) manda um resumo. A aba Gatilhos de `config_whatsapp.php` tem o
toggle `on_alertas` + o campo `cfg_digest_alertas_min`.

O usuário quer **cadastrar/configurar alertas**: ligar/desligar cada tipo,
ajustar limiar, decidir se notifica no WhatsApp, e — o principal — decidir se o
alerta **fica lembrando** enquanto não é resolvido, ou só avisa uma vez.

## Decisões (do brainstorming)

| Tema | Decisão |
|---|---|
| Natureza do "cadastro" | Configurar tipos **que o sistema já conhece** (catálogo no código). Tipo novo = dev adiciona a checagem. NÃO é o usuário escrever regra/SQL. |
| Onde fica a guia | Dentro da Central de Alertas — botão "⚙ Configurar alertas" na topbar → tela própria |
| SLA / chamado parado | **Fica na aba Gatilhos do WhatsApp** (não vira alerta). A Central é **só alertas** de parque/infra |
| Chamado novo / atribuído | Intocados, ficam no WhatsApp |
| Comportamento de notificação | Substitui o digest atual. Por ocorrência: **nova** → avisa na hora · **resolvida** → avisa na hora · **ainda aberta** → lembrete a cada `lembrete_min` (0 = sem lembrete) |

## Componentes

### 1. `alertas_tipos.php` (novo — catálogo)

Funções puras, sem HTML de página, sem sessão. Requer `alertas_lib.php` e
`entidade_alias.php`. **Cria** `portal_alertas_config` (CREATE IF NOT EXISTS) ao
ser incluído — assim tanto `alertas.php` quanto `alertas_config.php` quanto
`gat_alertas` têm a tabela. `alertas_config_do_tipo()` (ver seção 2) mora aqui.

```php
function alertas_catalogo(): array
// slug => [
//   'nome'      => 'Máquina sem reportar inventário',
//   'descricao' => 'Computador que não envia inventário há X dias (ou nunca)',
//   'params'    => [
//       'dias' => ['label'=>'Dias sem reportar', 'default'=>7, 'min'=>1, 'max'=>90],
//   ],
//   'check'     => 'alerta_check_sem_inventario',   // callable(PDO, array $params): array
//   'render'    => 'alerta_render_sem_inventario',  // callable(array $ocorrencias): string (HTML do corpo da seção)
//   'icone'     => 'bi-wifi-off',
//   'cor'       => 'danger',
// ]

function alerta_check_sem_inventario(PDO $pdo, array $p): array
// Reusa alertas_sem_inventario($pdo, (int)($p['dias'] ?? 7)).
// Retorna lista de ocorrências, cada uma:
//   ['chave' => 'sem_inv:<name>', 'titulo' => <name>, 'detalhe' => '<N dias / nunca>', 'loja' => <apelido>]

function alerta_check_disco_cheio(PDO $pdo, array $p): array
// Reusa alertas_disco_cheio($pdo, (int)($p['pct'] ?? 90)).
//   ['chave' => 'disco:<name>|<volume>', 'titulo' => <name>, 'detalhe' => '<pct>% · <usado>/<total>', 'loja' => <apelido>]

function alerta_render_sem_inventario(array $ocorr): string   // agrupado por loja, mesmo visual de hoje
function alerta_render_disco_cheio(array $ocorr): string      // tabela, mesmo visual de hoje
```

**Chave estável:** identifica a ocorrência entre passadas. Máquina que continua
sem inventário mantém a mesma chave → não re-avisa; máquina que volta e cai de
novo = mesma chave, mas como já foi removida da tabela de ocorrências quando
resolveu, conta como nova. Correto.

### 2. `portal_alertas_config` (tabela — CREATE IF NOT EXISTS)

```sql
CREATE TABLE IF NOT EXISTS portal_alertas_config (
    tipo           VARCHAR(40) PRIMARY KEY,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    params         JSON,
    notif_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
    lembrete_min   INT NOT NULL DEFAULT 0,   -- 0 = avisa quando aparece/resolve e para
    abre_chamado   TINYINT(1) NOT NULL DEFAULT 0,   -- reservado, sem lógica nesta fase
    atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Linha ausente → tipo usa `ativo=1`, `params` = defaults do catálogo,
`notif_whatsapp=1`, `lembrete_min=0`. Helper:

```php
function alertas_config_do_tipo(PDO $pdo, string $tipo): array
// junta o catálogo[$tipo] com a linha de portal_alertas_config (ou defaults)
// -> ['ativo'=>bool, 'params'=>array, 'notif_whatsapp'=>bool, 'lembrete_min'=>int, 'abre_chamado'=>bool]
```

### 3. `alertas_config.php` (nova tela)

Guard no topo igual ao `alertas.php`: `auth_guard` + `$_SESSION['autenticado']`
+ redireciona `self-service`.

`?action=` (antes de qualquer HTML, JSON):
- `ler` (GET) → `{ok, tipos:[{slug, nome, descricao, params_schema, ativo, params, notif_whatsapp, lembrete_min}]}`
- `salvar` (POST: `tipo`, `ativo`, `params` (campos por nome), `notif_whatsapp`, `lembrete_min`) → valida (`lembrete_min` 0..10080; cada param no seu `min`/`max`) → `INSERT ... ON DUPLICATE KEY UPDATE` → `{ok}`. POST-only.

UI: um bloco por tipo do catálogo — switch **Ativo** · campos numéricos dos
`params` · switch **Notificar no grupo Alertas** · campo **Lembrete (min, 0 =
sem)**. Botão salvar por bloco. Bootstrap 5, mesmo estilo de `alertas.php`.

### 4. `alertas.php` (modificado)

- Novo: itera `alertas_catalogo()`; para cada tipo com `alertas_config_do_tipo()['ativo']`,
  roda o `check` e usa o `render` pra montar a seção. Os stats no topo passam a
  ser a soma das ocorrências dos tipos ativos + total de máquinas.
- Botão **"⚙ Configurar alertas"** na topbar (ao lado de "Atualizar" / "Início").
- O endpoint `?action=dados` do auto-refresh passa a devolver as seções de todos
  os tipos ativos (um `<div class="sec">` por tipo, com `id` estável pro swap).
- `alertas_lib.php` **não muda** — o catálogo chama `alertas_sem_inventario()` /
  `alertas_disco_cheio()`. `alertas_snapshot()` fica (ou é removida se ninguém
  mais usa — o `gat_alertas` novo não usa).

### 5. `portal_alertas_ocorrencias` (tabela — criada por `wpp/db.php`)

```sql
CREATE TABLE IF NOT EXISTS portal_alertas_ocorrencias (
    tipo           VARCHAR(40) NOT NULL,
    chave          VARCHAR(191) NOT NULL,
    primeiro_visto DATETIME NOT NULL,
    ultimo_lembrete DATETIME NULL,
    PRIMARY KEY (tipo, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 6. `gat_alertas` (reescrito em `wpp/gatilhos.php`)

```php
function gat_alertas(PDO $pdo): void {
    $grupo = (string) wpp_cfg_get('grupo_alertas_jid', '');
    if ($grupo === '') return;

    require_once __DIR__ . '/../alertas_tipos.php';
    foreach (alertas_catalogo() as $slug => $def) {
        $cfg = alertas_config_do_tipo($pdo, $slug);
        if (!$cfg['ativo'] || !$cfg['notif_whatsapp']) continue;

        $atuais = call_user_func($def['check'], $pdo, $cfg['params']);   // [{chave, titulo, detalhe, loja}]
        $atuaisPorChave = []; foreach ($atuais as $o) $atuaisPorChave[$o['chave']] = $o;

        // estado guardado
        $st = $pdo->prepare("SELECT chave, ultimo_lembrete, primeiro_visto FROM portal_alertas_ocorrencias WHERE tipo = ?");
        $st->execute([$slug]);
        $guardadas = $st->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);  // chave => row

        // NOVAS: nas atuais, não nas guardadas -> avisa na hora
        foreach ($atuais as $o) {
            if (isset($guardadas[$o['chave']])) continue;
            $r = evo_send_text($grupo, gat_msg_alerta_novo($def['nome'], $o));
            if (!empty($r['ok'])) {
                $ins = $pdo->prepare("INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())");
                $ins->execute([$slug, $o['chave']]);
            }
            // envio falhou: não grava -> tenta de novo na próxima passada
        }

        // RESOLVIDAS: nas guardadas, não nas atuais -> avisa e apaga
        foreach ($guardadas as $chave => $row) {
            if (isset($atuaisPorChave[$chave])) continue;
            $r = evo_send_text($grupo, gat_msg_alerta_resolvido($def['nome'], $chave));
            if (!empty($r['ok'])) {
                $del = $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ? AND chave = ?");
                $del->execute([$slug, $chave]);
            }
        }

        // LEMBRETE: ainda abertas, lembrete_min > 0, passou o intervalo
        if ($cfg['lembrete_min'] > 0) {
            $devidas = [];
            foreach ($atuais as $o) {
                $g = $guardadas[$o['chave']] ?? null;
                if (!$g) continue;   // acabou de ser inserida nesta passada
                $ref = $g['ultimo_lembrete'] ?: $g['primeiro_visto'];
                if (strtotime(wpp_agora_db($pdo)) - strtotime($ref) >= $cfg['lembrete_min'] * 60) {
                    $devidas[] = $o;
                }
            }
            if ($devidas) {
                $r = evo_send_text($grupo, gat_msg_alerta_lembrete($def['nome'], $devidas));
                if (!empty($r['ok'])) {
                    $chaves = array_column($devidas, 'chave');
                    $ph = implode(',', array_fill(0, count($chaves), '?'));
                    $upd = $pdo->prepare("UPDATE portal_alertas_ocorrencias SET ultimo_lembrete = NOW() WHERE tipo = ? AND chave IN ($ph)");
                    $upd->execute(array_merge([$slug], $chaves));
                }
            }
        }
    }
}
```

Mensagens:
```
gat_msg_alerta_novo($nome, $o):
  "🔔 *<nome>*\n<o[titulo]> — <o[loja]>\n<o[detalhe]>"

gat_msg_alerta_resolvido($nome, $chave):
  "✅ *Resolvido — <nome>*\n<parte legível da chave>"
  (a chave carrega o titulo depois do ':' — extrair; se não der, mostra a chave)

gat_msg_alerta_lembrete($nome, $devidas):
  "⏰ *<nome> — ainda pendente* (<N>)\n" . <até 8 linhas "• <titulo> — <loja>"> . (N>8 ? "\n…+<N-8>" : "")
```

Guardrail: `evo_send_text` → `evo_guarded_send`, só o `grupo_alertas_jid`.
Nunca lança (worker envolve em try/catch).

### 7. `wpp_semear_baseline` (em `wpp/worker.php`)

- Remove `wpp_cfg_set('wpp_snap_alertas', ...)`.
- Adiciona: para cada tipo ativo do catálogo, roda o `check` e
  `INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())`
  pra cada ocorrência. **Sem enviar.**
- Reset por offline (`wpp_baseline_ok` limpo) → re-semeia igual hoje.

### 8. `config_whatsapp.php` — aba Gatilhos enxuta

- Remove o bloco "Alertas do parque → grupo Alertas" e o campo "Intervalo do resumo de alertas".
- `gatilhos_ler`: tira `on_alertas` e `cfg_digest_alertas_min` do JSON.
- `gatilhos_salvar`: tira `on_alertas` da lista `$toggles` e `cfg_digest_alertas_min` de `$numeros`.
- JS: tira `on_alertas` de `GAT_TOGGLES`, `cfg_digest_alertas_min` de `GAT_NUMS`, e `alertas` do `sincronizarGatilhos`.
- As chaves `on_alertas` / `cfg_digest_alertas_min` ficam órfãs em `portal_wpp_config` (inofensivo; ninguém lê).

## Guardrails / anti-backfill

- `evo_send_text` sempre via `evo_guarded_send` (só o grupo Alertas).
- Baseline popula `portal_alertas_ocorrencias`; **primeira passada do worker não manda 🔔 nem ⏰**.
- Envio que falha não grava/apaga o estado → re-tenta na próxima passada (sem duplicar, porque o estado ainda reflete o não-enviado).
- `chave` VARCHAR(191) pra caber no índice utf8mb4 (191*4 = 764 < 767).

## Testes

- **`alertas_tipos.php`**: `alertas_catalogo()` tem os 2 slugs; `alerta_check_*`
  retorna array de ocorrências com as 4 chaves; `alerta_render_*` retorna string
  HTML não-vazia pra uma lista de exemplo e a mensagem "tudo ok" pra lista vazia.
- **`alertas_config_do_tipo`**: sem linha → defaults do catálogo; com linha →
  mescla; `params` inválido no JSON → cai nos defaults.
- **`gat_alertas`** (com `$pdo` no deploy): fixture de `portal_alertas_ocorrencias`
  + mock de `evo_send_text` via flag global —
  - ocorrência nova → 1 envio + linha inserida
  - ocorrência sumiu → 1 envio "resolvido" + linha apagada
  - ocorrência aberta, `lembrete_min=0` → 0 envios
  - ocorrência aberta, `lembrete_min=30`, `ultimo_lembrete` = 40min atrás → 1 lembrete + `ultimo_lembrete` atualizado
  - envio falha → estado NÃO muda
- **`gat_msg_alerta_*`**: formato exato das 3 strings.
- **baseline**: com `portal_alertas_ocorrencias` vazia e alertas vigentes →
  `wpp_semear_baseline` insere N linhas, 0 envios (`portal_wpp_log` direcao='out' inalterado).
- **`alertas.php`**: renderiza idêntico ao de hoje pros 2 tipos ativos com params default.

## Fora de escopo

- `abre_chamado` — o campo existe, a lógica de abrir chamado automático é fase futura.
- Tipos de alerta novos (host offline, pfSense, UniFi, Home Assistant, painel de
  LED…) — cada um é um PR próprio adicionando `check`/`render` ao catálogo. O
  catálogo e o motor só precisam aguentar novos slugs sem mudança estrutural.
- Reordenar / esconder tipos na tela além do `ativo`.
- Notificar alerta por outro canal que não o grupo Alertas do WhatsApp.
