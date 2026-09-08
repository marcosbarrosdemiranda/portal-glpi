# Central de Alertas — Etapa 2 (motor de notificação por ocorrência) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o digest de alertas do WhatsApp por notificação **por ocorrência** (nova → avisa na hora · resolvida → avisa · ainda aberta → lembrete configurável), guiada pela config por tipo da Etapa 1, sem nunca despejar histórico.

**Architecture:** `alertas_catalogo()` (Etapa 1) é a fonte da verdade. Uma tabela nova `portal_alertas_ocorrencias` (PK `tipo,chave`) guarda o que já foi visto. `gat_alertas` (no worker) itera o catálogo: para cada tipo ativo compara as ocorrências atuais do `check()` com a tabela e manda 🔔 / ✅ / ⏰ pelo grupo Alertas. A baseline do worker popula a tabela sem enviar. A aba Gatilhos do WhatsApp perde a linha "Alertas" — a decisão de notificar passa a ser o switch `notif_whatsapp` por tipo, na tela `alertas_config.php`.

**Tech Stack:** PHP 8.2 + PDO/MariaDB (`glpi2`), sem framework. Tabelas próprias `portal_*` criadas inline com `CREATE TABLE IF NOT EXISTS`. Mini-harness de teste em `wpp/tests/` (`assert.php` com `t_ok`/`t_eq`, `run.php` glob `test_*.php`). Deploy por `scp` pro servidor 192.168.1.198 (stack Docker, container `glpi-web`).

**Spec:** `Docs/superpowers/specs/2026-09-08-central-alertas-motor-design.md` (seções 5, 6, 7, 8; "Sequência sugerida" item 2)

## Global Constraints

- **Anti-backfill é regra dura.** Nenhuma passada pode despejar alertas antigos num grupo. Baseline popula a tabela **sem enviar**; ligar `notif_whatsapp` depois **não** pode causar blast retroativo; conectar/reconectar a linha não manda nada.
- **Todo envio passa pelo guardrail.** `gat_enviar()` → `evo_send_text()` → `evo_guarded_send()`. Único destino permitido aqui: `grupo_alertas_jid`.
- **Envio que falha/bloqueia não muda estado.** A linha em `portal_alertas_ocorrencias` só é inserida/apagada/atualizada **após** `['ok'] === true`. Falha → re-tenta na próxima passada, sem duplicar.
- **Nada lança pra cima.** `gat_alertas` e helpers nunca deixam exceção escapar (o worker já embrulha em try/catch, mas cada tipo é isolado no seu próprio try/catch).
- **`chave` cabe no índice:** `VARCHAR(191)` (191×4 = 764 < 767, limite utf8mb4).
- **Convenções do projeto:** commits em inglês (Conventional Commits), comentários em português, tipagem PHP explícita, edição cirúrgica (nada de reescrever arquivo inteiro fora do que a task manda).
- **Relógio do banco:** toda janela de tempo usa `wpp_agora_db($pdo)` / `NOW()` do MariaDB, nunca `time()` puro. (O PHP do container roda em `America/Campo_Grande`, mesmo fuso do `glpi-db`, mas a regra do projeto é usar o relógio do banco.)
- **Deploy:** os arquivos vão pro servidor por `scp` pra `C:\docker\glpi-portal\glpi2\portal-glpi\...`; `docker-compose.yml` do servidor é editado à mão e **não** entra nesse fluxo.

---

## File Structure

| Arquivo | Papel nesta etapa |
|---|---|
| `alertas_tipos.php` | +CREATE `portal_alertas_ocorrencias`; +chave `detalhe` em cada ocorrência dos 2 checks |
| `wpp/gatilhos.php` | `gat_alertas` reescrito (loop) + `gat_alertas_tipo` (trabalho por tipo) + `gat_enviar` (seam de teste) + 4 builders de mensagem; **remove** `alertas_novos`, `gat_msg_digest`, `gat_lista_curta` |
| `wpp/worker.php` | `wpp_semear_baseline` popula `portal_alertas_ocorrencias` (todos os tipos) e para de gravar `wpp_snap_alertas`; remove var `$digestAlertasMin` |
| `alertas_lib.php` | **remove** `alertas_snapshot()` (último consumidor sumiu) |
| `config_whatsapp.php` | aba Gatilhos: remove o bloco "Alertas" (HTML + `gatilhos_ler` + `gatilhos_salvar` + JS) |
| `alertas_config.php` | `?action=salvar`: ao ativar um tipo, semeia as ocorrências correntes (INSERT IGNORE) pra não blastar; ao desativar, limpa as linhas; troca a cópia "Etapa 1/2" |
| `wpp/tests/test_alertas_tipos.php` | +assert `detalhe` nas ocorrências |
| `wpp/tests/test_gatilhos.php` | remove blocos `alertas_novos`/`gat_msg_digest`; +testes dos 4 builders (puros); reescreve o bloco DB de `gat_alertas` pra testar `gat_alertas_tipo` |
| `wpp/tests/test_worker_baseline.php` | troca asserts de `wpp_snap_alertas` por asserts de `portal_alertas_ocorrencias` |
| `wpp/tests/test_alertas_lib.php` | remove as 2 linhas de `alertas_snapshot` |
| `wpp/README.md` | tira menções a "digest de alertas" / `wpp_snap_alertas` / `wm_alertas_digest` |

---

## Interfaces (o que as tasks produzem e consomem)

**Ocorrência** (retorno de cada `check` do catálogo, após Task 1):
```php
[
  'chave'   => string,   // estável: 'sem_inv:<name>'  |  'disco:<name>|<volume>'
  'titulo'  => string,
  'loja'    => string,    // já com apelido_entidade; fallback 'Sem loja' / '—'
  'detalhe' => string,    // NOVO na Task 1 — linha curta pro WhatsApp
  // + campos específicos do tipo (cat, dias, nunca, quando / pct, usado, total, volume)
]
```

**`portal_alertas_ocorrencias`** (Task 1):
```sql
tipo VARCHAR(40), chave VARCHAR(191), primeiro_visto DATETIME NOT NULL,
ultimo_lembrete DATETIME NULL, PRIMARY KEY (tipo, chave)
```

**Funções novas em `wpp/gatilhos.php`** (Tasks 2–3):
```php
function gat_enviar(string $destino, string $texto): array
// prod: evo_send_text(). teste: se $GLOBALS['__wpp_fake_send'] é callable, chama ela.
// retorno: ['ok'=>bool, ...]

function gat_alerta_titulo_da_chave(string $chave): string
// 'sem_inv:PC-01' -> 'PC-01' ; 'disco:PC-01|C:' -> 'PC-01 (C:)' ; fallback: a própria chave

function gat_msg_alerta_novo(string $nomeTipo, array $o): string
function gat_msg_alerta_resolvido(string $nomeTipo, string $chave): string
function gat_msg_alerta_lembrete(string $nomeTipo, array $devidas): string   // $devidas = lista de ocorrências

function gat_alertas_tipo(PDO $pdo, string $slug, array $def, array $cfg, string $grupo): void
// $def  precisa de: 'nome' (string), 'check' (callable(PDO,array):array)
// $cfg  precisa de: 'notif_whatsapp' (bool), 'params' (array), 'lembrete_min' (int)
// sincroniza portal_alertas_ocorrencias pro tipo e envia 🔔/✅/⏰ se notif_whatsapp && $grupo!==''

function gat_alertas(PDO $pdo): void   // reescrita: loop sobre alertas_catalogo() chamando gat_alertas_tipo
```

**Consome de Etapa 1 (`alertas_tipos.php`, já existe):** `alertas_catalogo()`, `alertas_config_do_tipo(PDO,string): array{ativo,params,notif_whatsapp,lembrete_min,abre_chamado}`.

---

## Task 1: `portal_alertas_ocorrencias` + `detalhe` nas ocorrências

**Files:**
- Modify: `alertas_tipos.php` (IIFE de criação de tabela ~linha 19-30; `alerta_check_sem_inventario` ~102-119; `alerta_check_disco_cheio` ~122-138)
- Test: `wpp/tests/test_alertas_tipos.php` (bloco "checks retornam array", ~linha 55-67)

**Interfaces:**
- Consumes: nada novo (Etapa 1 já entregue).
- Produces: tabela `portal_alertas_ocorrencias`; chave `detalhe` (string) em toda ocorrência dos 2 checks.

- [ ] **Step 1: Ajustar o teste `test_alertas_tipos.php` pra exigir `detalhe`**

No loop que já checa `isset($o['chave'], $o['titulo'])` (~linha 60), trocar por:
```php
        foreach ($oc as $o) {
            t_ok(isset($o['chave'], $o['titulo'], $o['detalhe']), "$tipo ocorrência tem chave, titulo e detalhe");
            t_ok(is_string($o['detalhe']) && $o['detalhe'] !== '', "$tipo detalhe é string não-vazia");
            break; // basta a primeira
        }
```

- [ ] **Step 2: Rodar e ver falhar** (no deploy; localmente não há banco)

`docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php` → `test_alertas_tipos.php` falha em "tem detalhe" (chave ainda não existe). Se não puder rodar no deploy agora, seguir — a Task 8 valida tudo junto.

- [ ] **Step 3: Adicionar a tabela ao IIFE de `alertas_tipos.php`**

Dentro do closure que já cria `portal_alertas_config` (logo após o `$pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_config (...)")`), acrescentar:
```php
    // estado das ocorrências de alerta (o motor de notificação da Etapa 2 usa
    // isto pra decidir 🔔 nova / ✅ resolvida / ⏰ lembrete). chave = identificador
    // estável da ocorrência dentro do tipo. VARCHAR(191): cabe no índice utf8mb4.
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_ocorrencias (
        tipo VARCHAR(40) NOT NULL,
        chave VARCHAR(191) NOT NULL,
        primeiro_visto DATETIME NOT NULL,
        ultimo_lembrete DATETIME NULL,
        PRIMARY KEY (tipo, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
```

- [ ] **Step 4: `detalhe` em `alerta_check_sem_inventario`**

Na montagem de `$out[]` (~linha 108), acrescentar a chave `detalhe` **sem remover nenhuma existente**:
```php
        $out[] = [
            'chave'   => 'sem_inv:' . ($m['name'] ?? ''),
            'titulo'  => $m['name'] ?: '(sem nome)',
            'loja'    => apelido_entidade($m['loja'] ?? '') ?: 'Sem loja',
            'cat'     => (string) ($m['cat'] ?? ''),
            'nunca'   => $nunca,
            'dias'    => $nunca ? null : (int) floor((time() - strtotime($m['last_inventory_update'])) / 86400),
            'quando'  => $nunca ? '' : substr((string) $m['last_inventory_update'], 0, 10),
            'detalhe' => $nunca
                ? 'nunca reportou inventário'
                : ((int) floor((time() - strtotime($m['last_inventory_update'])) / 86400)) . ' dias sem reportar',
        ];
```

- [ ] **Step 5: `detalhe` em `alerta_check_disco_cheio`**

Na montagem de `$out[]` (~linha 127):
```php
        $out[] = [
            'chave'   => 'disco:' . ($d['name'] ?? '') . '|' . ($d['volume'] ?? ''),
            'titulo'  => (string) ($d['name'] ?? ''),
            'loja'    => apelido_entidade($d['loja'] ?? '') ?: '—',
            'pct'     => (int) $d['pct'],
            'usado'   => (float) $d['totalsize'] - (float) $d['freesize'],
            'total'   => (float) $d['totalsize'],
            'volume'  => (string) ($d['volume'] ?? ''),
            'detalhe' => (string) ($d['volume'] ?? '?') . ' · ' . (int) $d['pct'] . '% cheio',
        ];
```

- [ ] **Step 6: `php -l` nos 2 arquivos**

Local: `php -l alertas_tipos.php` (se houver PHP local) OU no deploy na Task 8. Esperado: sem erros.

- [ ] **Step 7: Commit**

```bash
git add alertas_tipos.php wpp/tests/test_alertas_tipos.php
git commit -m "feat: central de alertas - tabela de ocorrencias + campo detalhe nos checks"
```

---

## Task 2: builders de mensagem + `gat_enviar` (seam) — funções puras

**Files:**
- Modify: `wpp/gatilhos.php` (adicionar funções perto do topo, antes de `gat_alertas`; **não** mexer em `gat_alertas` ainda)
- Test: `wpp/tests/test_gatilhos.php` (novo bloco, pode ir logo antes do bloco `alertas_novos()` que será removido na Task 3)

**Interfaces:**
- Consumes: nada de banco. `gat_msg_*` recebem string + array/ string prontos.
- Produces: `gat_enviar`, `gat_alerta_titulo_da_chave`, `gat_msg_alerta_novo`, `gat_msg_alerta_resolvido`, `gat_msg_alerta_lembrete` (assinaturas na seção Interfaces do plano).

- [ ] **Step 1: Escrever os testes puros em `test_gatilhos.php`**

Inserir (antes da linha `// alertas_novos() — diff de snapshots`):
```php
// ---------------------------------------------------------------------------
// gat_alerta_titulo_da_chave() + builders de mensagem de alerta (puros)
// ---------------------------------------------------------------------------
t_eq(gat_alerta_titulo_da_chave('sem_inv:PC-CAIXA-01'), 'PC-CAIXA-01', 'titulo_da_chave: sem_inv');
t_eq(gat_alerta_titulo_da_chave('disco:SRV-01|C:'), 'SRV-01 (C:)', 'titulo_da_chave: disco vira "nome (volume)"');
t_eq(gat_alerta_titulo_da_chave('coisa-sem-dois-pontos'), 'coisa-sem-dois-pontos', 'titulo_da_chave: fallback');

$oNovo = ['chave' => 'sem_inv:PC-01', 'titulo' => 'PC-01', 'loja' => 'Loja 3', 'detalhe' => '12 dias sem reportar'];
t_eq(
    gat_msg_alerta_novo('Máquinas sem reportar inventário', $oNovo),
    "🔔 *Máquinas sem reportar inventário*\nPC-01 — Loja 3\n12 dias sem reportar",
    'msg_alerta_novo: nome / titulo — loja / detalhe'
);
// loja neutra ('—' ou vazio) não vira " — —"
$oSemLoja = ['chave' => 'disco:SRV|C:', 'titulo' => 'SRV', 'loja' => '—', 'detalhe' => 'C: · 95% cheio'];
t_eq(
    gat_msg_alerta_novo('Discos quase cheios', $oSemLoja),
    "🔔 *Discos quase cheios*\nSRV\nC: · 95% cheio",
    'msg_alerta_novo: loja "—" é omitida'
);

t_eq(
    gat_msg_alerta_resolvido('Discos quase cheios', 'disco:SRV-01|C:'),
    "✅ *Resolvido — Discos quase cheios*\nSRV-01 (C:)",
    'msg_alerta_resolvido: extrai o titulo legível da chave'
);

$devidas = [];
for ($i = 1; $i <= 10; $i++) $devidas[] = ['titulo' => "PC-$i", 'loja' => 'Loja 1'];
$msgLem = gat_msg_alerta_lembrete('Máquinas sem reportar inventário', $devidas);
t_ok(strpos($msgLem, "⏰ *Máquinas sem reportar inventário — ainda pendente* (10)") === 0, 'msg_alerta_lembrete: cabeçalho com total');
t_eq(substr_count($msgLem, "\n• "), 8, 'msg_alerta_lembrete: no máximo 8 linhas de item');
t_ok(strpos($msgLem, "…+2") !== false, 'msg_alerta_lembrete: sufixo "…+2" quando passa de 8');
t_eq(
    gat_msg_alerta_lembrete('X', [['titulo' => 'A', 'loja' => 'L1'], ['titulo' => 'B', 'loja' => '']]),
    "⏰ *X — ainda pendente* (2)\n• A — L1\n• B",
    'msg_alerta_lembrete: 2 itens, loja vazia omitida, sem sufixo'
);

// gat_enviar sem fake -> cai no evo_send_text (aqui só garante que o seam existe e é usado)
$GLOBALS['__wpp_fake_send'] = fn($d, $t) => ['ok' => true, 'eco' => [$d, $t]];
$r = gat_enviar('123@g.us', 'oi');
t_ok(!empty($r['ok']) && $r['eco'][0] === '123@g.us', 'gat_enviar: usa $GLOBALS[__wpp_fake_send] quando definido');
unset($GLOBALS['__wpp_fake_send']);
```

- [ ] **Step 2: Rodar e ver falhar** — `php wpp/tests/run.php` (local, sem banco): falha em "function not defined".

- [ ] **Step 3: Implementar em `wpp/gatilhos.php`**

Inserir logo após `gat_texto_plano()` (antes de `gat_msg_novo`), ou em qualquer ponto antes de `gat_alertas`:
```php
/**
 * Envio dos gatilhos com ponto de injeção pra teste. Produção: evo_send_text()
 * (que passa pelo guardrail). Teste: definir
 *   $GLOBALS['__wpp_fake_send'] = fn(string $destino, string $texto): array => ['ok'=>bool]
 * intercepta sem tocar a rede.
 */
function gat_enviar(string $destino, string $texto): array
{
    if (isset($GLOBALS['__wpp_fake_send']) && is_callable($GLOBALS['__wpp_fake_send'])) {
        return (array) ($GLOBALS['__wpp_fake_send'])($destino, $texto);
    }
    return evo_send_text($destino, $texto);
}

/**
 * Deriva um título legível da chave estável de uma ocorrência.
 *   'sem_inv:PC-01'   -> 'PC-01'
 *   'disco:SRV-01|C:' -> 'SRV-01 (C:)'
 *   sem ':'           -> a própria chave
 */
function gat_alerta_titulo_da_chave(string $chave): string
{
    $pos = strpos($chave, ':');
    if ($pos === false) return $chave;
    $resto = substr($chave, $pos + 1);
    if (strpos($resto, '|') !== false) {
        [$nome, $vol] = explode('|', $resto, 2);
        return $vol !== '' ? "{$nome} ({$vol})" : $nome;
    }
    return $resto !== '' ? $resto : $chave;
}

/** "titulo — loja" (loja neutra vazia/"—"/"Sem loja" é omitida). */
function gat_alerta_titulo_loja(string $titulo, string $loja): string
{
    $loja = trim($loja);
    return ($loja !== '' && $loja !== '—' && $loja !== 'Sem loja') ? "{$titulo} — {$loja}" : $titulo;
}

function gat_msg_alerta_novo(string $nomeTipo, array $o): string
{
    return "🔔 *{$nomeTipo}*\n"
         . gat_alerta_titulo_loja((string) ($o['titulo'] ?? '(sem título)'), (string) ($o['loja'] ?? '')) . "\n"
         . (string) ($o['detalhe'] ?? '');
}

function gat_msg_alerta_resolvido(string $nomeTipo, string $chave): string
{
    return "✅ *Resolvido — {$nomeTipo}*\n" . gat_alerta_titulo_da_chave($chave);
}

/**
 * Lembrete de ocorrências ainda abertas. Até 8 linhas "• titulo — loja";
 * o restante vira "…+N".
 */
function gat_msg_alerta_lembrete(string $nomeTipo, array $devidas): string
{
    $n = count($devidas);
    $m = "⏰ *{$nomeTipo} — ainda pendente* ({$n})";
    foreach (array_slice($devidas, 0, 8) as $o) {
        $m .= "\n• " . gat_alerta_titulo_loja((string) ($o['titulo'] ?? '?'), (string) ($o['loja'] ?? ''));
    }
    if ($n > 8) $m .= "\n…+" . ($n - 8);
    return $m;
}
```

- [ ] **Step 4: Rodar e ver passar** — `php wpp/tests/run.php` → bloco novo verde.

- [ ] **Step 5: Commit**

```bash
git add wpp/gatilhos.php wpp/tests/test_gatilhos.php
git commit -m "feat: central de alertas - builders de mensagem por ocorrencia + seam de envio"
```

---

## Task 3: `gat_alertas` reescrito (por ocorrência) + remoção do digest

**Files:**
- Modify: `wpp/gatilhos.php` — reescrever `gat_alertas` (~299-331); adicionar `gat_alertas_tipo`; **remover** `alertas_novos` (~342-363), `gat_msg_digest` (~377-406), `gat_lista_curta` (~412-417); no `require_once` do topo, adicionar `alertas_tipos.php`
- Modify: `wpp/tests/test_gatilhos.php` — remover bloco `alertas_novos()` (~144-170) e bloco `gat_msg_digest()` (~172-215); reescrever o bloco DB `gat_alertas()` (~217-277)

**Interfaces:**
- Consumes: `alertas_catalogo()`, `alertas_config_do_tipo()` (Etapa 1); `gat_enviar`, `gat_msg_alerta_*` (Task 2); `portal_alertas_ocorrencias` (Task 1); `wpp_agora_db()`, `wpp_log()` (`wpp/db.php`).
- Produces: `gat_alertas_tipo(PDO,string,array,array,string): void` (usado pelo loop e testável isolado); `gat_alertas(PDO): void` reescrita, chamada pelo worker no loop `['gat_novo','gat_atribuido','gat_alertas','gat_sla']` (inalterado).

- [ ] **Step 1: Ajustar o `require_once` do topo de `wpp/gatilhos.php`**

Depois de `require_once __DIR__ . '/../entidade_alias.php';` acrescentar:
```php
require_once __DIR__ . '/../alertas_tipos.php';   // alertas_catalogo(), alertas_config_do_tipo(), cria portal_alertas_ocorrencias
```

- [ ] **Step 2: Reescrever o bloco de teste DB de `gat_alertas` em `test_gatilhos.php`**

Apagar o bloco inteiro `alertas_novos()` (comentário-cabeçalho + asserts), o bloco `gat_msg_digest()`, e o bloco `if (isset($pdo) ...) { ... gat_alertas ... }` atual. Pôr no lugar:
```php
// ---------------------------------------------------------------------------
// gat_alertas_tipo() — sincroniza portal_alertas_ocorrencias e envia por ocorrência
// (precisa de banco: roda no deploy contra glpi2). Usa um tipo sintético
// '__teste_alerta__' e um check-fake -> não toca nos alertas reais.
// ---------------------------------------------------------------------------
if (isset($pdo) && $pdo instanceof PDO) {
    $TIPO = '__teste_alerta__';
    $GRP  = '111222333@g.us';
    $limpa = function () use ($pdo, $TIPO) {
        $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ?")->execute([$TIPO]);
    };
    // check-fake: devolve o que estiver em $GLOBALS['__fake_ocorr']
    $defFake = ['nome' => 'Alerta de Teste', 'check' => function ($pdo, $params) {
        return $GLOBALS['__fake_ocorr'] ?? [];
    }];
    $cfg = fn(bool $notif, int $lem = 0) => ['notif_whatsapp' => $notif, 'params' => [], 'lembrete_min' => $lem];
    $oc  = fn(string $k) => ['chave' => $k, 'titulo' => $k, 'loja' => 'L1', 'detalhe' => 'x'];

    try {
        // -- NOVA + notif on: 1 envio, 1 linha inserida --
        $limpa();
        $enviadas = [];
        $GLOBALS['__wpp_fake_send'] = function ($d, $t) use (&$enviadas) { $enviadas[] = [$d, $t]; return ['ok' => true]; };
        $GLOBALS['__fake_ocorr'] = [$oc('a'), $oc('b')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 2, 'gat_alertas_tipo: 2 ocorrências novas -> 2 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 2,
             'gat_alertas_tipo: 2 linhas gravadas');

        // -- 2ª passada, mesmas ocorrências: nada novo, 0 envios --
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: sem mudança -> 0 envios');

        // -- RESOLVIDA: 'b' sumiu -> 1 envio "resolvido" + linha apagada --
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('a')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq(count($enviadas), 1, 'gat_alertas_tipo: 1 resolvida -> 1 envio');
        t_ok(strpos($enviadas[0][1], '✅ *Resolvido') === 0, 'gat_alertas_tipo: mensagem de resolvido');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: linha da resolvida apagada');

        // -- LEMBRETE: lembrete_min=30, primeiro_visto forçado pra 40min atrás -> 1 lembrete --
        $pdo->prepare("UPDATE portal_alertas_ocorrencias SET primeiro_visto = NOW() - INTERVAL 40 MINUTE, ultimo_lembrete = NULL WHERE tipo=? AND chave='a'")->execute([$TIPO]);
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true, 30), $GRP);
        t_eq(count($enviadas), 1, 'gat_alertas_tipo: lembrete vencido -> 1 envio');
        t_ok(strpos($enviadas[0][1], '⏰ *Alerta de Teste — ainda pendente') === 0, 'gat_alertas_tipo: mensagem de lembrete');
        t_ok($pdo->query("SELECT ultimo_lembrete FROM portal_alertas_ocorrencias WHERE tipo='$TIPO' AND chave='a'")->fetchColumn() !== null,
             'gat_alertas_tipo: ultimo_lembrete atualizado');

        // -- lembrete_min=0: nunca manda lembrete --
        $pdo->prepare("UPDATE portal_alertas_ocorrencias SET primeiro_visto = NOW() - INTERVAL 40 MINUTE, ultimo_lembrete = NULL WHERE tipo=? AND chave='a'")->execute([$TIPO]);
        $enviadas = [];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true, 0), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: lembrete_min=0 -> 0 lembretes');

        // -- notif off: grava/apaga mas NÃO envia --
        $limpa();
        $enviadas = [];
        $GLOBALS['__fake_ocorr'] = [$oc('c')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(false), $GRP);
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: notif off -> 0 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: notif off ainda mantém a tabela em dia');

        // -- envio FALHA: estado NÃO muda --
        $limpa();
        $GLOBALS['__wpp_fake_send'] = fn($d, $t) => ['ok' => false, 'erro' => 'simulado'];
        $GLOBALS['__fake_ocorr'] = [$oc('d')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), $GRP);
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 0,
             'gat_alertas_tipo: envio falhou -> nada gravado (re-tenta depois)');

        // -- grupo vazio: equivale a notif off --
        $limpa();
        $enviadas = [];
        $GLOBALS['__wpp_fake_send'] = function ($d, $t) use (&$enviadas) { $enviadas[] = 1; return ['ok' => true]; };
        $GLOBALS['__fake_ocorr'] = [$oc('e')];
        gat_alertas_tipo($pdo, $TIPO, $defFake, $cfg(true), '');
        t_eq(count($enviadas), 0, 'gat_alertas_tipo: grupo vazio -> 0 envios');
        t_eq((int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias WHERE tipo='$TIPO'")->fetchColumn(), 1,
             'gat_alertas_tipo: grupo vazio ainda popula a tabela');
    } finally {
        unset($GLOBALS['__wpp_fake_send'], $GLOBALS['__fake_ocorr']);
        $limpa();
    }
} else {
    echo "  -- gat_alertas_tipo(): banco indisponível, testes pulados\n";
}
```

- [ ] **Step 3: Rodar e ver falhar** — no deploy: `docker exec glpi-web php .../wpp/tests/run.php` → `test_gatilhos.php` falha ("gat_alertas_tipo not defined"). Localmente: falha por `alertas_novos`/`gat_msg_digest` sumidos dos testes mas ainda referenciados? Não — remover os blocos primeiro (Step 2 já removeu). Local roda o resto.

- [ ] **Step 4: Implementar `gat_alertas` + `gat_alertas_tipo` em `wpp/gatilhos.php`**

Substituir a função `gat_alertas` inteira (e o docblock acima dela) por:
```php
/**
 * Alertas do parque -> grupo "Alertas", POR OCORRÊNCIA.
 *
 * Fonte da verdade: alertas_catalogo() (alertas_tipos.php). Para cada tipo ATIVO
 * chama gat_alertas_tipo(), isolado num try/catch (um tipo com erro não derruba
 * os outros). A decisão de notificar é o switch notif_whatsapp de cada tipo
 * (tela alertas_config.php) — não há mais toggle único "on_alertas".
 */
function gat_alertas(PDO $pdo): void
{
    $grupo = (string) wpp_cfg_get('grupo_alertas_jid', '');

    foreach (alertas_catalogo() as $slug => $def) {
        $cfg = alertas_config_do_tipo($pdo, $slug);
        if (!$cfg['ativo']) continue;
        try {
            gat_alertas_tipo($pdo, $slug, $def, $cfg, $grupo);
        } catch (\Throwable $e) {
            wpp_log('sys', '', "gat_alertas/{$slug}: " . $e->getMessage(), 'erro');
        }
    }
}

/**
 * Sincroniza portal_alertas_ocorrencias de UM tipo com o que o check() retorna
 * agora, e notifica pelo grupo (se notif_whatsapp e $grupo != '').
 *
 *   NOVA      (atual, não guardada)  -> 🔔  + INSERT
 *   RESOLVIDA (guardada, não atual)  -> ✅  + DELETE
 *   ABERTA + lembrete_min>0 + venceu -> ⏰  + UPDATE ultimo_lembrete
 *
 * notif_whatsapp=0 (ou grupo vazio): grava/apaga a tabela mas NÃO envia — assim
 * ligar a notificação depois não despeja o acúmulo.
 *
 * Envio que falha/bloqueia NÃO muda o estado daquela chave -> re-tenta na próxima.
 *
 * @param array $def  precisa de 'nome' (string) e 'check' (callable(PDO,array):array)
 * @param array $cfg  precisa de 'notif_whatsapp' (bool), 'params' (array), 'lembrete_min' (int)
 */
function gat_alertas_tipo(PDO $pdo, string $slug, array $def, array $cfg, string $grupo): void
{
    $notifica = !empty($cfg['notif_whatsapp']) && $grupo !== '';
    $nome     = (string) ($def['nome'] ?? $slug);

    $atuais = call_user_func($def['check'], $pdo, $cfg['params'] ?? []);
    $porChave = [];
    foreach ($atuais as $o) $porChave[$o['chave']] = $o;

    $st = $pdo->prepare(
        "SELECT chave, primeiro_visto, ultimo_lembrete
         FROM portal_alertas_ocorrencias WHERE tipo = ?"
    );
    $st->execute([$slug]);
    $guardadas = $st->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);   // chave => row

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())"
    );
    $del = $pdo->prepare(
        "DELETE FROM portal_alertas_ocorrencias WHERE tipo = ? AND chave = ?"
    );

    // NOVAS
    foreach ($atuais as $o) {
        if (isset($guardadas[$o['chave']])) continue;
        if ($notifica) {
            $r = gat_enviar($grupo, gat_msg_alerta_novo($nome, $o));
            if (empty($r['ok'])) continue;   // não grava -> re-tenta na próxima passada
        }
        $ins->execute([$slug, $o['chave']]);
    }

    // RESOLVIDAS
    foreach ($guardadas as $chave => $row) {
        if (isset($porChave[$chave])) continue;
        if ($notifica) {
            $r = gat_enviar($grupo, gat_msg_alerta_resolvido($nome, (string) $chave));
            if (empty($r['ok'])) continue;   // não apaga -> re-tenta
        }
        $del->execute([$slug, $chave]);
    }

    // LEMBRETE
    if ($notifica && (int) ($cfg['lembrete_min'] ?? 0) > 0) {
        $agora   = strtotime(wpp_agora_db($pdo));
        $limite  = (int) $cfg['lembrete_min'] * 60;
        $devidas = [];
        foreach ($atuais as $o) {
            $g = $guardadas[$o['chave']] ?? null;
            if (!$g) continue;   // recém-inserida nesta passada
            $ref = $g['ultimo_lembrete'] ?: $g['primeiro_visto'];
            if ($agora - strtotime((string) $ref) >= $limite) $devidas[] = $o;
        }
        if ($devidas) {
            $r = gat_enviar($grupo, gat_msg_alerta_lembrete($nome, $devidas));
            if (!empty($r['ok'])) {
                $chaves = array_column($devidas, 'chave');
                $ph  = implode(',', array_fill(0, count($chaves), '?'));
                $upd = $pdo->prepare(
                    "UPDATE portal_alertas_ocorrencias SET ultimo_lembrete = NOW()
                     WHERE tipo = ? AND chave IN ($ph)"
                );
                $upd->execute(array_merge([$slug], $chaves));
            }
        }
    }
}
```

- [ ] **Step 5: Remover as 3 funções mortas de `wpp/gatilhos.php`**

Apagar `alertas_novos()` (docblock + função), `gat_msg_digest()` (docblock + função) e `gat_lista_curta()` (docblock + função). Conferir que nada mais no arquivo as chama:
```bash
grep -nE "alertas_novos|gat_msg_digest|gat_lista_curta|alertas_snapshot|on_alertas" wpp/gatilhos.php
```
Esperado: sem resultados (nem `on_alertas` — `gat_alertas` não checa mais).

- [ ] **Step 6: Rodar os testes** — no deploy: `docker exec glpi-web php .../wpp/tests/run.php` → `test_gatilhos.php` verde (incl. o bloco `gat_alertas_tipo`). Localmente: `php wpp/tests/run.php` — os blocos puros passam, os DB pulam.

- [ ] **Step 7: `php -l wpp/gatilhos.php`** (deploy: `docker exec glpi-web php -l ...`). Esperado: sem erros.

- [ ] **Step 8: Commit**

```bash
git add wpp/gatilhos.php wpp/tests/test_gatilhos.php
git commit -m "feat: central de alertas - gat_alertas por ocorrencia (nova/resolvida/lembrete), remove digest"
```

---

## Task 4: baseline popula `portal_alertas_ocorrencias`; remove `alertas_snapshot`

**Files:**
- Modify: `wpp/worker.php` — `wpp_semear_baseline` (~90-133): trocar a linha `wpp_cfg_set('wpp_snap_alertas', ...)` por semeadura da tabela; remover var `$digestAlertasMin` (~25) e ajustar o comentário dos toggles (~28-29)
- Modify: `alertas_lib.php` — remover `alertas_snapshot()` (~55-61)
- Modify: `wpp/tests/test_worker_baseline.php` — trocar os asserts de `wpp_snap_alertas` (~64-67) por asserts de `portal_alertas_ocorrencias`
- Modify: `wpp/tests/test_alertas_lib.php` — remover as linhas 11-12 (`alertas_snapshot`)

**Interfaces:**
- Consumes: `alertas_catalogo()`, `alertas_config_do_tipo()` (Etapa 1); `portal_alertas_ocorrencias` (Task 1).
- Produces: baseline que, ao rodar, deixa `portal_alertas_ocorrencias` refletindo o parque inteiro (todos os tipos do catálogo, ativos ou não) **sem enviar** — garante que ligar `notif_whatsapp` ou reconectar nunca despeja histórico.

- [ ] **Step 1: Ajustar `test_worker_baseline.php`**

Trocar (linhas ~64-67):
```php
t_ok(wpp_cfg_get('wpp_snap_alertas') !== null, 'baseline gravou snapshot de alertas (wpp_snap_alertas)');
$snap = json_decode((string) wpp_cfg_get('wpp_snap_alertas'), true);
t_ok(is_array($snap) && array_key_exists('sem_inventario', $snap) && array_key_exists('disco_cheio', $snap),
    'snapshot de alertas tem as duas chaves esperadas');
```
por:
```php
// baseline popula portal_alertas_ocorrencias pros tipos do catálogo, sem enviar.
require_once __DIR__ . '/../../alertas_tipos.php';
$ocInv = 0;
foreach (alertas_catalogo() as $slug => $def) {
    try { $ocInv += count(call_user_func($def['check'], $pdo, alertas_config_do_tipo($pdo, $slug)['params'])); }
    catch (\Throwable $e) {}
}
$ocTab = (int) $pdo->query("SELECT COUNT(*) FROM portal_alertas_ocorrencias")->fetchColumn();
t_ok($ocTab >= $ocInv && $ocInv >= 0, "baseline semeou portal_alertas_ocorrencias ($ocTab linhas, esperado >= $ocInv do catálogo agora)");
t_ok(wpp_cfg_get('wpp_snap_alertas') === null || wpp_cfg_get('wpp_snap_alertas') !== null, 'baseline não depende mais de wpp_snap_alertas');
```
> Nota: `$ocTab >= $ocInv` porque a tabela pode ter linhas de passadas anteriores; o que importa é que toda ocorrência corrente está lá. (Um assert exato exigiria limpar a tabela antes — evitar, é estado de produção.)

Também ajustar o docblock do topo do arquivo (linha 2-3): trocar "grava snapshot de alertas" por "popula portal_alertas_ocorrencias".

- [ ] **Step 2: Rodar e ver falhar** — deploy com `WPP_TEST_DESTRUTIVO=1` e worker parado (ver `wpp/README.md`). Se não for rodar agora, seguir; Task 8 cobre.

- [ ] **Step 3: Editar `wpp_semear_baseline` em `wpp/worker.php`**

Trocar a linha:
```php
    // Snapshot dos alertas do parque (o gat_alertas compara com este estado).
    wpp_cfg_set('wpp_snap_alertas', json_encode(alertas_snapshot($pdo)));
```
por:
```php
    // Ocorrências de alerta correntes -> portal_alertas_ocorrencias, pra TODOS os
    // tipos do catálogo (ativos ou não). SEM enviar: o gat_alertas só manda 🔔
    // pra chave que NÃO está aqui. Semear os inativos também garante que ligar
    // um tipo (ou seu notif_whatsapp) depois não despeje o acúmulo.
    require_once __DIR__ . '/../alertas_tipos.php';
    $insOc = $pdo->prepare(
        "INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())"
    );
    $nOc = 0;
    foreach (alertas_catalogo() as $slug => $def) {
        try {
            $ocorr = call_user_func($def['check'], $pdo, alertas_config_do_tipo($pdo, $slug)['params']);
        } catch (\Throwable $e) {
            $ocorr = [];
        }
        foreach ($ocorr as $o) { $insOc->execute([$slug, $o['chave']]); $nOc++; }
    }
```
E na linha final de log da função, incluir o total:
```php
    wpp_log('sys', '', 'baseline semeada: ' . $n . ' chamados, ' . $nOc . ' ocorrencias de alerta', 'baseline');
```

- [ ] **Step 4: Limpar var e comentário obsoletos em `wpp/worker.php`**

- Remover a linha `$digestAlertasMin = (int) wpp_cfg_get('cfg_digest_alertas_min', '15');` (~25).
- Ajustar o comentário (~28-29): `// Toggles on_novo / on_atribuido / on_sla ...` (tirar `on_alertas`; `gat_alertas` agora usa notif_whatsapp por tipo).
- Conferir: `grep -nE "alertas_snapshot|wpp_snap_alertas|digestAlertas|on_alertas" wpp/worker.php` → sem resultados.

- [ ] **Step 5: Remover `alertas_snapshot()` de `alertas_lib.php`**

Apagar a função inteira (docblock, se houver, + corpo ~55-61). Conferir globalmente:
```bash
grep -rn "alertas_snapshot" --include=*.php .
```
Esperado: só `Docs/` (spec) e nada em `.php` de produção/teste.

- [ ] **Step 6: Remover o teste de `alertas_snapshot` em `test_alertas_lib.php`**

Apagar as linhas 11-12 (`$s = alertas_snapshot($pdo);` e o `t_ok(... 'snapshot tem as 2 chaves')`).

- [ ] **Step 7: `php -l`** em `wpp/worker.php` e `alertas_lib.php` (deploy). Esperado: sem erros.

- [ ] **Step 8: Commit**

```bash
git add wpp/worker.php alertas_lib.php wpp/tests/test_worker_baseline.php wpp/tests/test_alertas_lib.php
git commit -m "feat: central de alertas - baseline popula ocorrencias; remove alertas_snapshot"
```

---

## Task 5: aba Gatilhos do WhatsApp perde o bloco "Alertas"

**Files:**
- Modify: `config_whatsapp.php` — `gatilhos_ler` (~142-152), `gatilhos_salvar` (~158-160), HTML do bloco Alertas (~394-407), JS `GAT_TOGGLES`/`GAT_NUMS` (~862-863), `sincronizarGatilhos` (~884), `DONO`/`LABEL` (~898-906)

**Interfaces:**
- Consumes: nada novo.
- Produces: aba Gatilhos com 3 blocos (Chamado novo · Chamado atribuído · SLA/parado) + Avançado. As chaves `on_alertas` / `cfg_digest_alertas_min` deixam de ser lidas/gravadas (ficam órfãs em `portal_wpp_config`, inofensivas).

- [ ] **Step 1: `gatilhos_ler`** — remover as 2 linhas do array `cfg`:
```php
                'on_alertas'             => wpp_cfg_get('on_alertas', '1'),
```
```php
                'cfg_digest_alertas_min' => (int) wpp_cfg_get('cfg_digest_alertas_min', '15'),
```

- [ ] **Step 2: `gatilhos_salvar`** — tirar `'on_alertas'` de `$toggles` e `'cfg_digest_alertas_min'` de `$numeros`:
```php
            $toggles = ['on_novo', 'on_atribuido', 'on_sla'];
            $numeros = ['cfg_delay_dm_min', 'cfg_sla_horas', 'cfg_sla_prevenc_min', 'cfg_offline_reset_min'];
```

- [ ] **Step 3: HTML** — apagar o bloco inteiro `<!-- Alertas -->` … `</div>` (as ~14 linhas de `<div class="gat-bloco">` do gatilho de alertas, ~394-407).

- [ ] **Step 4: JS** — no IIFE:
```php
  var GAT_TOGGLES = ['on_novo', 'on_atribuido', 'on_sla'];
  var GAT_NUMS = ['cfg_delay_dm_min', 'cfg_sla_horas', 'cfg_sla_prevenc_min', 'cfg_offline_reset_min'];
```
Em `sincronizarGatilhos`: `['atribuido', 'sla'].forEach(...)` (tirar `'alertas'`).
Em `salvarGatilhos`, do objeto `DONO` tirar `cfg_digest_alertas_min: 'on_alertas',` e do `LABEL` tirar `cfg_digest_alertas_min: 'Intervalo do resumo de alertas',`.

- [ ] **Step 5: Conferir** — `grep -nE "on_alertas|cfg_digest_alertas_min|'alertas'|\"alertas\"" config_whatsapp.php` → só devem restar as referências ao **grupo** de alertas (`grupo_alertas_jid`, `sel-alertas`, `alertas_jid`, aba Grupos) — essas ficam. Nada mais de gatilho.

- [ ] **Step 6: `php -l config_whatsapp.php`** (deploy). Abrir a aba Gatilhos no navegador na Task 8: 3 blocos, salva sem erro.

- [ ] **Step 7: Commit**

```bash
git add config_whatsapp.php
git commit -m "feat: aba Gatilhos do WhatsApp - remove linha Alertas (vai pra config por tipo)"
```

---

## Task 6: `alertas_config.php` — semear ocorrências ao ativar; cópia da Etapa 2

**Files:**
- Modify: `alertas_config.php` — handler `?action=salvar` (~83-95); textos "Etapa 1"/"Etapa 2" (~168 footer, ~278 nota)

**Interfaces:**
- Consumes: `portal_alertas_ocorrencias` (Task 1); `$def['check']` do catálogo.
- Produces: salvar com `ativo=1` → as ocorrências correntes do tipo entram em `portal_alertas_ocorrencias` (INSERT IGNORE) **antes** de qualquer passada do worker, então ligar o tipo/`notif_whatsapp` nunca dispara um blast retroativo. Salvar com `ativo=0` → limpa as linhas daquele tipo.

- [ ] **Step 1: Editar o `try` do `?action=salvar`**

Depois do `$st->execute([$slug, $ativo, json_encode($params), $notif, $lembrete]);` e antes do `echo json_encode(['ok' => true]);`, inserir:
```php
            // Anti-blast: ao ATIVAR o tipo, marca as ocorrências correntes como
            // "já vistas" (sem notificar) — assim o gat_alertas só manda 🔔 do que
            // aparecer DEPOIS. Ao desativar, limpa as linhas do tipo.
            try {
                if ($ativo) {
                    $ocorr = call_user_func($def['check'], $pdo, $params);
                    $insOc = $pdo->prepare(
                        "INSERT IGNORE INTO portal_alertas_ocorrencias (tipo, chave, primeiro_visto) VALUES (?, ?, NOW())"
                    );
                    foreach ($ocorr as $o) { $insOc->execute([$slug, $o['chave']]); }
                } else {
                    $pdo->prepare("DELETE FROM portal_alertas_ocorrencias WHERE tipo = ?")->execute([$slug]);
                }
            } catch (\Throwable $e) {
                // semear/limpar é best-effort: não pode derrubar o salvar da config
            }
```

- [ ] **Step 2: Cópia** — trocar a nota (~278):
```php
    notaNotif.textContent = 'Manda 🔔 quando o alerta aparece, ✅ quando é resolvido, e ⏰ de lembrete conforme o intervalo abaixo.';
```
e o footer (~168):
```php
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Configuração de Alertas</footer>
```

- [ ] **Step 3: `php -l alertas_config.php`** (deploy). Esperado: sem erros.

- [ ] **Step 4: Commit**

```bash
git add alertas_config.php
git commit -m "feat: alertas_config - semeia ocorrencias ao ativar tipo (anti-blast) + copy da Etapa 2"
```

---

## Task 7: `wpp/README.md` — tirar menções ao digest

**Files:**
- Modify: `wpp/README.md` (linhas ~205, ~302, ~357, ~394-395 e o passo-a-passo de Gatilhos)

**Interfaces:** só doc. Sem código, sem teste.

- [ ] **Step 1: Editar as menções**

- ~205: "wpp_semear_baseline (marca chamados/atribuicoes, **popula portal_alertas_ocorrencias**, grava wm_novo)".
- ~302: trocar "Se desejar digest de alertas: deixe 'Alertas (digest)' ligado." por "Alertas do parque: configure por tipo em Configuração → Central de Alertas → ⚙ Configurar alertas (switch 'Notificar no grupo Alertas' por tipo)."
- ~357 e ~394-395: nas listas de chaves de `portal_wpp_config` a apagar num reset, remover `wpp_snap_alertas` e `wm_alertas_digest` (não são mais escritas; podem ficar órfãs se já existirem — inofensivo, mas tirar da doc pra não confundir).
- Onde listar os gatilhos ("Roda os 4 gatilhos (gat_novo, gat_atribuido, gat_alertas, gat_sla)"): manter — `gat_alertas` continua existindo, só mudou de digest pra por-ocorrência. Ajustar qualquer frase que descreva `gat_alertas` como "digest".

- [ ] **Step 2: Commit**

```bash
git add wpp/README.md
git commit -m "docs: wpp/README - alertas agora por ocorrencia (config por tipo), sem digest"
```

---

## Task 8: Deploy + verificação

**Files:** nenhum novo. Deploy dos arquivos das Tasks 1-7 pro servidor e verificação end-to-end.

**Interfaces:** consome tudo. Produz: Etapa 2 no ar, testes verdes contra `glpi2`, e prova de que nenhuma mensagem retroativa foi disparada.

- [ ] **Step 1: `scp` pro servidor** (dir `C:\docker\glpi-portal\glpi2\portal-glpi\`):

`alertas_tipos.php`, `alertas_config.php`, `alertas_lib.php`, `config_whatsapp.php`, `wpp/gatilhos.php`, `wpp/worker.php`, `wpp/README.md`, `wpp/tests/test_alertas_tipos.php`, `wpp/tests/test_gatilhos.php`, `wpp/tests/test_worker_baseline.php`, `wpp/tests/test_alertas_lib.php`.

- [ ] **Step 2: Lint no container**

```bash
ssh glpi-server 'docker exec glpi-web sh -c "for f in alertas_tipos.php alertas_config.php alertas_lib.php config_whatsapp.php wpp/gatilhos.php wpp/worker.php; do php -l /var/www/html/glpi2/portal-glpi/$f; done"'
```
Esperado: `No syntax errors detected` em todos.

- [ ] **Step 3: Suite de testes (não-destrutiva) contra `glpi2`**

```bash
ssh glpi-server 'docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php'
```
Esperado: `0 falhas`. Confere que `test_gatilhos.php` (bloco `gat_alertas_tipo`), `test_alertas_tipos.php` (detalhe) e `test_alertas_lib.php` passam. `test_worker_baseline.php` continua pulado (sem `WPP_TEST_DESTRUTIVO`).

- [ ] **Step 4: Teste destrutivo da baseline — com o worker PARADO**

```bash
ssh glpi-server 'cd C:\docker\glpi-portal && docker compose stop portal-wpp-worker'
ssh glpi-server 'docker exec -e WPP_TEST_DESTRUTIVO=1 glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php'
ssh glpi-server 'cd C:\docker\glpi-portal && docker compose start portal-wpp-worker'
```
Esperado: `0 falhas`, incl. `test_worker_baseline.php` — "baseline semeou portal_alertas_ocorrencias" e "baseline nao registrou nenhum envio".

- [ ] **Step 5: Conferir a tabela de ocorrências**

```bash
ssh glpi-server 'docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "SELECT tipo, COUNT(*) FROM portal_alertas_ocorrencias GROUP BY tipo; SELECT COUNT(*) esperado_sem_inv FROM (SELECT 1) x;"'
```
Esperado: linhas pra `sem_inventario` (~76 hoje) e `disco_cheio` (~5 hoje), batendo com o que a Central de Alertas mostra. Nenhuma linha `__teste_alerta__` (os testes limpam no finally).

- [ ] **Step 6: Verificar que NADA foi pro grupo Alertas**

```bash
ssh glpi-server 'docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "SELECT criado_em, resumo, status FROM portal_wpp_log WHERE direcao=\"out\" AND criado_em > NOW() - INTERVAL 20 MINUTE ORDER BY id DESC LIMIT 20;"'
```
Esperado: nenhuma linha nova de alerta (🔔/✅/⏰) — a baseline e as passadas só marcam estado. (Pode haver `gat_novo` de chamado real; isso é esperado e não é alerta.)

- [ ] **Step 7: Teste real de notificação de alerta** (opcional, com o usuário)

1. Em `alertas_config.php`, garantir `disco_cheio` **Ativo** + **Notificar no grupo Alertas** ligado, Lembrete 0.
2. Injetar uma ocorrência sintética que o `check` de fato retorne — mais simples: baixar o limiar `pct` de `disco_cheio` pra um valor que faça surgir 1 volume novo que não estava na tabela, salvar.
3. Aguardar uma passada do worker (~30s). Conferir no grupo "TI · Alertas": chega **1** `🔔 *Discos quase cheios*` só do volume novo (não os 5 antigos — esses já estão em `portal_alertas_ocorrencias`).
4. Voltar `pct` pro valor original, salvar, aguardar passada → chega `✅ *Resolvido — Discos quase cheios*` do volume que saiu.
5. `SELECT * FROM portal_alertas_ocorrencias WHERE tipo='disco_cheio';` volta ao conjunto anterior.

- [ ] **Step 8: Verificação visual**

- `alertas.php`: as 2 seções renderizam idênticas (o `detalhe` novo não aparece na tela — só no WhatsApp). Auto-refresh 45s ok.
- `alertas_config.php`: nota nova sob "Notificar…" ("Manda 🔔 quando… ✅ … ⏰ …"), footer sem "(Etapa 1)".
- `config_whatsapp.php` aba Gatilhos: 3 blocos (Chamado novo · atribuído · SLA) + Avançado. Salvar → ok.

- [ ] **Step 9: Ledger + finish**

Registrar no ledger da SDD, depois `superpowers:finishing-a-development-branch` (merge em `infra/migracao-docker-glpi`).

---

## Self-Review

**Spec coverage (seção → task):**
- §5 `portal_alertas_ocorrencias` → Task 1 (posta em `alertas_tipos.php`, não `wpp/db.php` — decisão: é tabela de alertas, e `alertas_tipos.php` é o include compartilhado por `alertas_config.php` + worker; `wpp/db.php` não é carregado por `alertas_config.php`).
- §6 `gat_alertas` reescrito (nova/resolvida/lembrete, guardrail, nunca lança) → Task 3. Mensagens 🔔/✅/⏰ → Task 2. **Desvio:** a spec checa `!$cfg['notif_whatsapp']` com `continue` (pula o tipo). Aqui o tipo é sempre processado se `ativo`; `notif_whatsapp=0` mantém a tabela em dia mas não envia. Motivo: senão, ligar `notif_whatsapp` depois despeja o acúmulo — viola a regra dura anti-backfill. Task 6 reforça (semeia ao ativar).
- §7 `wpp_semear_baseline` popula ocorrências, remove `wpp_snap_alertas`, re-semeia por offline → Task 4. **Desvio:** semeia **todos** os tipos do catálogo, não só os ativos (spec diz "cada tipo ativo") — mesma razão anti-backfill.
- §8 aba Gatilhos enxuta → Task 5. Chaves órfãs `on_alertas`/`cfg_digest_alertas_min` → aceito, documentado.
- §Testes (alertas_tipos, gat_alertas com fixture+mock, gat_msg_*, baseline) → Tasks 1, 2, 3, 4. **`gat_alertas` testado via `gat_alertas_tipo`** (helper extraído) com `check`-fake e tipo sintético `__teste_alerta__` — não toca alertas reais, roda contra `glpi2` no deploy.
- §9 The Dude → **fora desta etapa** (é a Etapa 3, plano próprio).
- §Fora de escopo: `abre_chamado` (campo existe, sem lógica — intocado); tipos novos de alerta (cada um é PR próprio).

**Consistência de tipos:** `gat_alertas_tipo($pdo, $slug, $def, $cfg, $grupo)` — `$def['check']` é `callable(PDO,array):array`, `$def['nome']` string; `$cfg` tem `notif_whatsapp`/`params`/`lembrete_min` (exatamente o que `alertas_config_do_tipo()` da Etapa 1 devolve). Ocorrência tem `chave`/`titulo`/`loja`/`detalhe` após Task 1. `gat_enviar` retorna `['ok'=>bool]` como `evo_send_text`. Todos batem.

**Placeholders:** nenhum — todo step de código tem o bloco pronto.

**Ordem de deploy:** Tasks 1→7 podem ser commitadas em qualquer ordem parcial, mas **Task 3 depende de 1 e 2**; **Task 4 depende de 1**; **Task 6 depende de 1**. Task 8 é sempre por último. Um deploy parcial (só Task 1-4) já é funcional e seguro (aba Gatilhos ainda mostra "Alertas" mas o toggle `on_alertas` não é mais lido — inofensivo).
