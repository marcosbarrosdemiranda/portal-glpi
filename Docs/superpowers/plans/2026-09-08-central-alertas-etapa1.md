# Central de Alertas — Etapa 1 (motor base) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Central de Alertas passa a montar as seções a partir de um **catálogo de tipos** (código) + uma **config por tipo** (banco), com uma tela pra ligar/desligar cada alerta e ajustar limiar / lembrete / "notificar no WhatsApp". Nada de WhatsApp nem `gat_alertas` nesta etapa — só o motor e a tela.

**Architecture:** `alertas_tipos.php` (novo) tem o catálogo: cada tipo é `[nome, descricao, params, check(), render(), icone, cor]`. Os 2 alertas atuais (sem inventário, disco cheio) viram entradas do catálogo reusando as queries de `alertas_lib.php`. `portal_alertas_config` guarda o que o usuário mexeu (linha ausente = defaults do catálogo). `alertas_config.php` (novo) é a tela de config. `alertas.php` deixa de ter 2 seções fixas e passa a iterar o catálogo pelos tipos ativos — o auto-refresh de 45s que já existe continua funcionando, agora genérico.

**Tech Stack:** PHP 8.2 (sem framework), PDO/MariaDB 10.4 (banco `glpi2`), Bootstrap 5.3 + bootstrap-icons (CDN), vanilla JS.

**Spec:** `Docs/superpowers/specs/2026-09-08-central-alertas-motor-design.md` (a Etapa 1 é a "Sequência sugerida de implementação" item 1)

## Global Constraints

- `alertas_tipos.php`: funções puras, **sem HTML de página inteira, sem `session_start`, sem `header()`** (só devolve strings/arrays). Pode e deve criar `portal_alertas_config` via `CREATE TABLE IF NOT EXISTS` ao ser incluído (padrão do portal).
- Não lançar exceção não tratada em código chamado pelo `?action=dados` (a tela já degrada, mas o endpoint tem que devolver JSON válido).
- `portal_alertas_config`: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. Colunas EXATAS da spec seção 2.
- Contrato do `check($pdo, $params)`: retorna `array` de ocorrências. Chaves **obrigatórias** em cada ocorrência: `chave` (string estável), `titulo` (string). Chaves livres por tipo (o `render` daquele tipo sabe quais existem).
- Contrato do `render(array $ocorrencias): string`: devolve o **innerHTML** do corpo da seção (`.sec-b`). Lista vazia → a mensagem verde "tudo ok" daquele tipo.
- `alertas.php` tem que renderizar **visualmente idêntico** ao de hoje pros 2 tipos com params default. Comparar antes/depois.
- Guard de sessão: `alertas.php` fica como está (auth + `!self-service`). `alertas_config.php` = auth + `!self-service` + **checagem defensiva de `notificacoes_config`** (espelha `config_notificacoes.php`) — quem configura notificação configura alerta.
- O botão "⚙ Configurar alertas" em `alertas.php` só aparece se `$_SESSION['portal_perfil_cards']` for `null` (sem perfil, vê tudo) ou contiver `notificacoes_config`.
- `alertas_lib.php` **não muda** nesta etapa.
- Testes: harness `wpp/tests/` (`assert.php` = `t_ok`/`t_eq`, `run.php` globa `test_*.php`). Testes que dependem de `$pdo` rodam no deploy (`docker exec glpi-web php .../wpp/tests/run.php`); no dev roda o que der com `docker run --rm -v "C:/claude code/portal-glpi:/app" -w //app php:8.2-cli` (usar `MSYS_NO_PATHCONV=1` no Git Bash).
- `php -l` em todo arquivo alterado via a imagem `php:8.2-cli`.
- Deploy: `scp` pro servidor em `C:\docker\glpi-portal\glpi2\portal-glpi\<caminho>`. Servidor: `ssh glpi-server`. Portal em `https://192.168.1.198:7412/glpi2/portal-glpi/`.
- Commits: subject em português, Conventional Commits (`feat:`/`refactor:`/`fix:`/`docs:`). Comentários em português. Trailer:
  ```
  Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TeiLgTAYjLg3dtuSq78Bo8
  ```
- Branch: `feat/central-alertas-etapa1`, saído de `infra/migracao-docker-glpi`. Merge no fim (`--no-ff`).

## Referência — o `alertas.php` de hoje

- `alertas_carregar(PDO): array` roda `alertas_sem_inventario($pdo, 7)` + `alertas_disco_cheio($pdo, 90)`, agrupa sem-inv por loja, calcula `totalAtivos`/`pctSemInv`, e já devolve `sem_inv_html`/`disco_html` renderizados por `render_sem_inv_body()`/`render_disco_body()`.
- `?action=dados` devolve `{ok, hora, stats:{sem_inv,disco,total,pct}, sem_inv_html, disco_html}`.
- HTML: 3 `.stat` fixos + `.att-linha` + 2 `.sec` fixos (`#body-sem-inv`, `#body-disco`, `#badge-sem-inv`, `#badge-disco`) + bloco `.futuro` "Em construção".
- JS (IIFE): `atualizarAlertas(manual)` faz fetch de `?action=dados` (`&bg=1` no auto), troca os 2 innerHTML, `setNum()` pisca os contadores. `setInterval` 45s, pausa em `document.hidden`, para em 440/401.
- Funções `h()` e `gb()` são globais em `alertas.php`.

## File Structure

| Arquivo | Responsabilidade | Ação |
|---|---|---|
| `alertas_tipos.php` | catálogo, `alertas_config_do_tipo()`, CREATE `portal_alertas_config`, os 2 `check`/`render` do GLPI | Create |
| `alertas.php` | iterar o catálogo (tipos ativos), botão "Configurar alertas", endpoint `?action=dados` genérico | Modify |
| `alertas_config.php` | tela de config (lista tipos, salva por bloco) | Create |
| `wpp/tests/test_alertas_tipos.php` | testes do catálogo + config helper + checks/renders | Create |

---

## Task 1: `alertas_tipos.php` — catálogo + config + os 2 tipos do GLPI

**Files:**
- Create: `alertas_tipos.php`
- Create: `wpp/tests/test_alertas_tipos.php`

**Interfaces:**
- Consumes: `alertas_lib.php` (`alertas_sem_inventario`, `alertas_disco_cheio`), `entidade_alias.php` (`apelido_entidade`), `$pdo`.
- Produces:

```php
// alertas_tipos.php
require_once __DIR__ . '/alertas_lib.php';
require_once __DIR__ . '/entidade_alias.php';

// cria a tabela ao incluir (padrão do portal)
(function () {
    require_once __DIR__ . '/agenda/db.php';
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_alertas_config (
        tipo VARCHAR(40) PRIMARY KEY,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        params JSON,
        notif_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
        lembrete_min INT NOT NULL DEFAULT 0,
        abre_chamado TINYINT(1) NOT NULL DEFAULT 0,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

function alertas_catalogo(): array
{
    return [
        'sem_inventario' => [
            'nome'      => 'Máquina sem reportar inventário',
            'descricao' => 'Computador do GLPI que não envia inventário há X dias (ou nunca).',
            'params'    => [
                'dias' => ['label' => 'Dias sem reportar', 'default' => 7, 'min' => 1, 'max' => 90],
            ],
            'check'  => 'alerta_check_sem_inventario',
            'render' => 'alerta_render_sem_inventario',
            'icone'  => 'bi-wifi-off',
            'cor'    => 'danger',
        ],
        'disco_cheio' => [
            'nome'      => 'Disco quase cheio',
            'descricao' => 'Volume de dados (> 30 GB) acima do limiar de uso. Ignora partições de recuperação/sistema.',
            'params'    => [
                'pct' => ['label' => 'Uso mínimo (%)', 'default' => 90, 'min' => 50, 'max' => 99],
            ],
            'check'  => 'alerta_check_disco_cheio',
            'render' => 'alerta_render_disco_cheio',
            'icone'  => 'bi-hdd-fill',
            'cor'    => 'warning',
        ],
    ];
}

/**
 * Mescla o catálogo com portal_alertas_config. Linha ausente = defaults.
 * @return array{ativo:bool, params:array<string,int>, notif_whatsapp:bool, lembrete_min:int, abre_chamado:bool}
 */
function alertas_config_do_tipo(PDO $pdo, string $tipo): array
{
    $cat = alertas_catalogo()[$tipo] ?? null;
    $defParams = [];
    foreach (($cat['params'] ?? []) as $k => $meta) $defParams[$k] = (int) $meta['default'];

    $st = $pdo->prepare("SELECT ativo, params, notif_whatsapp, lembrete_min, abre_chamado
                         FROM portal_alertas_config WHERE tipo = ?");
    $st->execute([$tipo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ativo' => true, 'params' => $defParams, 'notif_whatsapp' => true,
                'lembrete_min' => 0, 'abre_chamado' => false];
    }
    $p = json_decode((string) $row['params'], true);
    if (!is_array($p)) $p = [];
    // só aceita as chaves conhecidas do catálogo, cai no default se faltar/inválido
    $params = [];
    foreach ($defParams as $k => $def) {
        $v = isset($p[$k]) ? (int) $p[$k] : $def;
        $min = (int) ($cat['params'][$k]['min'] ?? 1);
        $max = (int) ($cat['params'][$k]['max'] ?? 100000);
        $params[$k] = max($min, min($max, $v));
    }
    return [
        'ativo'          => (bool) $row['ativo'],
        'params'         => $params,
        'notif_whatsapp' => (bool) $row['notif_whatsapp'],
        'lembrete_min'   => max(0, (int) $row['lembrete_min']),
        'abre_chamado'   => (bool) $row['abre_chamado'],
    ];
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','cat','dias','nunca'] */
function alerta_check_sem_inventario(PDO $pdo, array $p): array
{
    $rows = alertas_sem_inventario($pdo, (int) ($p['dias'] ?? 7));
    $out = [];
    foreach ($rows as $m) {
        $nunca = empty($m['last_inventory_update']) || $m['last_inventory_update'][0] === '0';
        $out[] = [
            'chave'  => 'sem_inv:' . ($m['name'] ?? ''),
            'titulo' => $m['name'] ?: '(sem nome)',
            'loja'   => apelido_entidade($m['loja'] ?? '') ?: 'Sem loja',
            'cat'    => (string) ($m['cat'] ?? ''),
            'nunca'  => $nunca,
            'dias'   => $nunca ? null : (int) floor((time() - strtotime($m['last_inventory_update'])) / 86400),
            'quando' => $nunca ? '' : substr((string) $m['last_inventory_update'], 0, 10),
        ];
    }
    return $out;
}

/** @return array ocorrências: cada uma ['chave','titulo','loja','pct','usado','total','volume'] */
function alerta_check_disco_cheio(PDO $pdo, array $p): array
{
    $rows = alertas_disco_cheio($pdo, (int) ($p['pct'] ?? 90));
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'chave'  => 'disco:' . ($d['name'] ?? '') . '|' . ($d['volume'] ?? ''),
            'titulo' => (string) ($d['name'] ?? ''),
            'loja'   => apelido_entidade($d['loja'] ?? '') ?: '—',
            'pct'    => (int) $d['pct'],
            'usado'  => (float) $d['totalsize'] - (float) $d['freesize'],
            'total'  => (float) $d['totalsize'],
            'volume' => (string) ($d['volume'] ?? ''),
        ];
    }
    return $out;
}

/** innerHTML do corpo — agrupado por loja. Move o HTML do render_sem_inv_body de hoje. */
function alerta_render_sem_inventario(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Todo o parque reportou.</div>';
    }
    $porLoja = [];
    foreach ($ocorr as $o) $porLoja[$o['loja']][] = $o;
    ksort($porLoja, SORT_NATURAL | SORT_FLAG_CASE);

    $H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '';
    foreach ($porLoja as $loja => $maquinas) {
        $out .= '<div class="loja-h"><i class="bi bi-shop"></i> ' . $H($loja)
              . ' <span style="color:#9ca3af;font-weight:400">(' . count($maquinas) . ')</span></div><table><tbody>';
        foreach ($maquinas as $m) {
            $pill = $m['nunca']
                ? '<span class="pill pill-red">nunca reportou</span>'
                : '<span class="pill pill-amber">' . (int) $m['dias'] . ' dias (' . $H($m['quando']) . ')</span>';
            $out .= '<tr><td style="font-weight:600">' . $H($m['titulo']) . '</td>'
                  . '<td style="color:#6b7280">' . $H($m['cat']) . '</td>'
                  . '<td style="text-align:right">' . $pill . '</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out;
}

/** innerHTML do corpo — tabela com barra. Move o HTML do render_disco_body de hoje. */
function alerta_render_disco_cheio(array $ocorr): string
{
    if (!$ocorr) {
        return '<div class="vazio"><i class="bi bi-check-circle-fill me-1"></i>Nenhum volume acima do limiar.</div>';
    }
    $H  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $gb = fn($mb) => ($n = (float) $mb) >= 1024 ? round($n / 1024, $n >= 10240 ? 0 : 1) . ' GB' : round($n) . ' MB';
    $out = '<table><thead><tr><th>Máquina</th><th>Loja</th><th>Volume</th><th>Uso</th></tr></thead><tbody>';
    foreach ($ocorr as $d) {
        $out .= '<tr><td style="font-weight:600">' . $H($d['titulo']) . '</td>'
              . '<td style="color:#6b7280">' . $H($d['loja']) . '</td>'
              . '<td>' . $H($d['volume']) . '</td>'
              . '<td><span class="bar"><span style="width:' . (int) $d['pct'] . '%"></span></span>'
              . (int) $d['pct'] . '% · ' . $gb($d['usado']) . ' / ' . $gb($d['total']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
```

- [ ] **Step 1: Escrever `wpp/tests/test_alertas_tipos.php`**

```php
<?php
require_once __DIR__ . '/../../alertas_tipos.php';
global $pdo;

// catálogo
$cat = alertas_catalogo();
t_ok(isset($cat['sem_inventario'], $cat['disco_cheio']), 'catálogo tem os 2 tipos do GLPI');
t_ok(is_callable($cat['sem_inventario']['check']) && is_callable($cat['sem_inventario']['render']),
     'sem_inventario tem check e render chamáveis');

// tabela criada
t_ok((bool) $pdo->query("SHOW TABLES LIKE 'portal_alertas_config'")->fetch(), 'portal_alertas_config existe');

// config sem linha = defaults do catálogo
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");
$c = alertas_config_do_tipo($pdo, 'sem_inventario');
t_eq($c['ativo'], true, 'sem linha: ativo=true');
t_eq($c['params']['dias'], 7, 'sem linha: params.dias = default 7');
t_eq($c['lembrete_min'], 0, 'sem linha: lembrete_min = 0');

// config com linha = mescla + clamp
$pdo->prepare("INSERT INTO portal_alertas_config (tipo,ativo,params,notif_whatsapp,lembrete_min)
               VALUES ('sem_inventario',0,'{\"dias\":999}',0,120)")->execute();
$c = alertas_config_do_tipo($pdo, 'sem_inventario');
t_eq($c['ativo'], false, 'com linha: ativo=false');
t_eq($c['params']['dias'], 90, 'com linha: params.dias clampado no max 90');
t_eq($c['notif_whatsapp'], false, 'com linha: notif_whatsapp=false');
t_eq($c['lembrete_min'], 120, 'com linha: lembrete_min=120');
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'sem_inventario'");

// params JSON inválido -> defaults
$pdo->prepare("INSERT INTO portal_alertas_config (tipo,params) VALUES ('disco_cheio','xxx-nao-json')")->execute();
$c = alertas_config_do_tipo($pdo, 'disco_cheio');
t_eq($c['params']['pct'], 90, 'params JSON inválido cai no default');
$pdo->exec("DELETE FROM portal_alertas_config WHERE tipo = 'disco_cheio'");

// checks retornam array de ocorrências com as chaves obrigatórias
foreach (['sem_inventario', 'disco_cheio'] as $tipo) {
    $oc = call_user_func($cat[$tipo]['check'], $pdo, alertas_config_do_tipo($pdo, $tipo)['params']);
    t_ok(is_array($oc), "$tipo check retorna array");
    foreach ($oc as $o) {
        t_ok(isset($o['chave'], $o['titulo']), "$tipo ocorrência tem chave e titulo");
        break; // basta a primeira
    }
    // render de lista vazia -> mensagem "ok"
    t_ok(strpos(call_user_func($cat[$tipo]['render'], []), 'vazio') !== false, "$tipo render([]) tem a msg vazia");
    // render com dados -> string não vazia
    if ($oc) t_ok(strlen(call_user_func($cat[$tipo]['render'], $oc)) > 20, "$tipo render(ocorr) devolve HTML");
}
```

- [ ] **Step 2: Rodar e ver falhar** — `docker exec glpi-web php .../wpp/tests/run.php` (deploy) ou local: falha porque `alertas_tipos.php` não existe.

- [ ] **Step 3: Criar `alertas_tipos.php`** com o conteúdo do bloco Interfaces acima. `php -l` limpo via `php:8.2-cli`.

- [ ] **Step 4: Rodar os testes** — no deploy: `docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php` → tudo verde.

- [ ] **Step 5: Commit**

```bash
git add alertas_tipos.php wpp/tests/test_alertas_tipos.php
git commit -m "feat: alertas_tipos.php - catálogo de tipos de alerta + config por tipo"
```

---

## Task 2: `alertas_config.php` — tela de configuração

**Files:**
- Create: `alertas_config.php`

**Interfaces:**
- Consumes: `alertas_tipos.php` (`alertas_catalogo`, `alertas_config_do_tipo`), `$pdo`, `auth_guard.php`.
- Produces: nova tela + handlers `?action=`:
  - `?action=ler` (GET) → `{ok, tipos: [{slug, nome, descricao, params_schema: {k:{label,min,max}}, ativo, params:{k:int}, notif_whatsapp, lembrete_min}]}`
  - `?action=salvar` (POST: `tipo`, `ativo`(0/1), `notif_whatsapp`(0/1), `lembrete_min`(int), e um campo por param `p_<nome>`(int)) → valida → `INSERT ... ON DUPLICATE KEY UPDATE` → `{ok}`. POST-only (senão `{ok:false,erro:'método inválido'}`).

- [ ] **Step 1: Cabeçalho + guarda (copiar de `config_notificacoes.php`)**

```php
<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/alertas_tipos.php';

// checagem defensiva: perfil restrito sem 'notificacoes_config' -> volta pro dashboard
$cards = $_SESSION['portal_perfil_cards'] ?? null;
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
```

- [ ] **Step 2: Handlers AJAX (antes do HTML)**

```php
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'ler') {
        $tipos = [];
        foreach (alertas_catalogo() as $slug => $def) {
            $cfg = alertas_config_do_tipo($pdo, $slug);
            $schema = [];
            foreach ($def['params'] as $k => $meta) {
                $schema[$k] = ['label' => $meta['label'], 'min' => $meta['min'], 'max' => $meta['max']];
            }
            $tipos[] = [
                'slug' => $slug, 'nome' => $def['nome'], 'descricao' => $def['descricao'],
                'icone' => $def['icone'], 'cor' => $def['cor'],
                'params_schema' => $schema,
                'ativo' => $cfg['ativo'] ? 1 : 0,
                'params' => $cfg['params'],
                'notif_whatsapp' => $cfg['notif_whatsapp'] ? 1 : 0,
                'lembrete_min' => $cfg['lembrete_min'],
            ];
        }
        echo json_encode(['ok' => true, 'tipos' => $tipos]);
        exit;
    }

    if ($action === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'método inválido']); exit; }
        $slug = $_POST['tipo'] ?? '';
        $def  = alertas_catalogo()[$slug] ?? null;
        if (!$def) { echo json_encode(['ok' => false, 'erro' => 'tipo desconhecido']); exit; }

        $lembrete = (int) ($_POST['lembrete_min'] ?? 0);
        if ($lembrete < 0 || $lembrete > 10080) { echo json_encode(['ok' => false, 'erro' => 'lembrete deve ser de 0 a 10080 min']); exit; }

        $params = [];
        foreach ($def['params'] as $k => $meta) {
            $v = (int) ($_POST['p_' . $k] ?? $meta['default']);
            if ($v < $meta['min'] || $v > $meta['max']) {
                echo json_encode(['ok' => false, 'erro' => "{$meta['label']}: informe de {$meta['min']} a {$meta['max']}"]);
                exit;
            }
            $params[$k] = $v;
        }
        $ativo = (($_POST['ativo'] ?? '') === '1') ? 1 : 0;
        $notif = (($_POST['notif_whatsapp'] ?? '') === '1') ? 1 : 0;

        try {
            $st = $pdo->prepare(
                "INSERT INTO portal_alertas_config (tipo, ativo, params, notif_whatsapp, lembrete_min)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ativo=VALUES(ativo), params=VALUES(params),
                     notif_whatsapp=VALUES(notif_whatsapp), lembrete_min=VALUES(lembrete_min)"
            );
            $st->execute([$slug, $ativo, json_encode($params), $notif, $lembrete]);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao salvar']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    exit;
}
```

- [ ] **Step 3: HTML + JS** — página no estilo de `alertas.php`/`config_notificacoes.php` (Bootstrap 5.3, mesma topbar/hero). Topbar: link "← Central de Alertas" (`alertas.php`). Corpo: `<div id="lista">` preenchido por JS. Um bloco por tipo:
  - título com `icone`/`nome` + `descricao`
  - switch **Ativo**
  - um `<input type="number">` por param (`p_<k>`, com `min`/`max` do schema, label do schema)
  - switch **Notificar no grupo Alertas do WhatsApp**
  - `<input type="number" min="0" max="10080">` **Lembrete (min, 0 = sem lembrete)**
  - botão **Salvar** por bloco + feedback inline
  - Switch "Ativo" desmarcado → os outros campos do bloco ficam apagados/desabilitados (`opacity` + `disabled`), igual fizemos na aba Gatilhos.
  JS: `carregar()` (fetch `?action=ler`, monta os blocos), `salvar(slug, btn)` (monta URLSearchParams, POST `?action=salvar`), `sincronizarBloco(slug)`.

- [ ] **Step 4: `php -l alertas_config.php`** via `php:8.2-cli`.

- [ ] **Step 5: Verificação manual (deploy)** — abrir `alertas_config.php`, mudar `dias` de "sem inventário" pra 3 e Salvar; conferir `SELECT * FROM portal_alertas_config` e que a Central passa a listar mais máquinas; desmarcar "Ativo" de disco cheio e Salvar → some da Central (Task 3).

- [ ] **Step 6: Commit**

```bash
git add alertas_config.php
git commit -m "feat: alertas_config.php - tela de configuração dos tipos de alerta"
```

---

## Task 3: `alertas.php` — iterar o catálogo + botão + endpoint genérico

**Files:**
- Modify: `alertas.php`

**Interfaces:**
- Consumes: `alertas_tipos.php`.
- Produces: `alertas.php` monta as seções dos **tipos ativos** do catálogo. `?action=dados` devolve `{ok, hora, total, secoes: [{slug, nome, icone, cor, n, html}]}`.

- [ ] **Step 1: Trocar `require_once __DIR__ . '/alertas_lib.php';` por `require_once __DIR__ . '/alertas_tipos.php';`** (o catálogo já puxa `alertas_lib.php` e `entidade_alias.php`).

- [ ] **Step 2: Substituir `alertas_carregar()` + `render_sem_inv_body()` + `render_disco_body()`** por:

```php
/**
 * Roda os tipos ATIVOS do catálogo e devolve, por tipo, o count e o innerHTML
 * da seção — pra carga inicial e pro ?action=dados ficarem idênticos.
 */
function alertas_carregar(PDO $pdo): array
{
    $secoes = [];
    foreach (alertas_catalogo() as $slug => $def) {
        $cfg = alertas_config_do_tipo($pdo, $slug);
        if (!$cfg['ativo']) continue;
        try {
            $ocorr = call_user_func($def['check'], $pdo, $cfg['params']);
        } catch (\Throwable $e) {
            $ocorr = [];
        }
        $secoes[] = [
            'slug'  => $slug,
            'nome'  => $def['nome'],
            'icone' => $def['icone'],
            'cor'   => $def['cor'],
            'n'     => count($ocorr),
            'html'  => call_user_func($def['render'], $ocorr),
        ];
    }
    $total = (int) $pdo->query("SELECT COUNT(*) FROM glpi_computers WHERE is_deleted=0 AND is_template=0")->fetchColumn();
    return ['secoes' => $secoes, 'total' => $total];
}
```

  Manter `h()` e `gb()` (o `alerta_render_*` do catálogo usa closures próprias, mas outras partes do arquivo ainda podem usar `h()`).

- [ ] **Step 3: Endpoint `?action=dados`**

```php
$dados = alertas_carregar($pdo);
if (($_GET['action'] ?? '') === 'dados') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'     => true,
        'hora'   => date('H:i:s'),
        'total'  => $dados['total'],
        'secoes' => array_map(fn($s) => [
            'slug' => $s['slug'], 'nome' => $s['nome'], 'icone' => $s['icone'],
            'cor' => $s['cor'], 'n' => $s['n'], 'html' => $s['html'],
        ], $dados['secoes']),
    ]);
    exit;
}
```

- [ ] **Step 4: HTML — stats dinâmicos + seções dinâmicas + botão**

  - Topbar: adicionar, antes do "Atualizar", quando permitido:
    ```php
    <?php $podeConfig = !isset($_SESSION['portal_perfil_cards']) || $_SESSION['portal_perfil_cards'] === null
                        || isset($_SESSION['portal_perfil_cards']['notificacoes_config']); ?>
    <?php if ($podeConfig): ?>
      <a href="alertas_config.php"><i class="bi bi-gear me-1"></i>Configurar alertas</a>
    <?php endif; ?>
    ```
  - `.stats`: uma `.stat` por seção (`id="n-<slug>"`) + a de "Total de máquinas" (`id="n-total"`), gerada no PHP a partir de `$dados['secoes']`.
  - Trocar as 2 `.sec` fixas por um loop:
    ```php
    <?php foreach ($dados['secoes'] as $s): ?>
    <div class="sec" id="sec-<?= $H($s['slug']) ?>">
      <div class="sec-h"><i class="bi <?= $H($s['icone']) ?> text-<?= $H($s['cor']) ?>"></i> <?= $H($s['nome']) ?>
        <span class="badge bg-<?= $H($s['cor'] === 'warning' ? 'warning text-dark' : $s['cor']) ?>" id="badge-<?= $H($s['slug']) ?>"><?= $s['n'] ?></span></div>
      <div class="sec-b" id="body-<?= $H($s['slug']) ?>"><?= $s['html'] ?></div>
    </div>
    <?php endforeach; ?>
    ```
  - Manter o bloco `.futuro` "Em construção" (tirar da lista os itens que já viraram tipo — deixar como está por ora, é só texto).
  - `.att-linha` fica.

- [ ] **Step 5: JS — genérico sobre `d.secoes`**

  No `atualizarAlertas`, trocar os 2 `innerHTML` fixos por:
  ```js
  (d.secoes || []).forEach(function (s) {
    var body = document.getElementById('body-' + s.slug);
    if (body) body.innerHTML = s.html;
    setNum('badge-' + s.slug, s.n);
    setNum('n-' + s.slug, s.n);
  });
  setNum('n-total', d.total);
  attTxt.textContent = 'atualizado às ' + d.hora;
  ```
  (uma seção que foi desativada some no F5, não no auto-refresh — aceitável; documentar.)

- [ ] **Step 6: Verificação visual (deploy)** — abrir `alertas.php` **antes** e **depois** do deploy (usar o commit anterior). As 2 seções (sem inventário, disco cheio) têm que ficar **pixel-idênticas** com params default. O auto-refresh de 45s continua trocando os dados. O botão "Configurar alertas" aparece.

- [ ] **Step 7: `php -l alertas.php`** via `php:8.2-cli`.

- [ ] **Step 8: Commit**

```bash
git add alertas.php
git commit -m "refactor: alertas.php monta as seções a partir do catálogo de tipos"
```

---

## Task 4: Deploy da Etapa 1 + verificação

**Files:** nenhum (ou um parágrafo no topo do futuro runbook).

- [ ] **Step 1: `scp` pro servidor**: `alertas_tipos.php`, `alertas_config.php`, `alertas.php`, `wpp/tests/test_alertas_tipos.php`.
- [ ] **Step 2: `docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php`** → "0 falhas" (inclui o `test_alertas_tipos.php` novo + os da Fase 2 do WhatsApp, todos verdes).
- [ ] **Step 3: Central de Alertas** — abre igual, 2 seções idênticas, auto-refresh 45s ok, botão "Configurar alertas" aparece.
- [ ] **Step 4: `alertas_config.php`** — lista os 2 tipos. Mudar `dias` pra 3 + Salvar → Central mostra mais máquinas (F5 ou aguarda 45s). Desativar disco cheio + Salvar → some da Central no F5. Reativar, voltar `dias` pra 7.
- [ ] **Step 5: Conferir tabela** — `docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "SELECT * FROM portal_alertas_config;"`.
- [ ] **Step 6:** Nenhuma mensagem de WhatsApp foi enviada (esta etapa não mexe no worker).

---

## Self-Review

**1. Spec coverage (Etapa 1):**
- `alertas_tipos.php` catálogo + `alertas_config_do_tipo()` + CREATE `portal_alertas_config` → Task 1 ✅
- 2 tipos GLPI migrados reusando `alertas_lib.php` → Task 1 (`alerta_check_*` / `alerta_render_*`) ✅
- `alertas_config.php` (ler/salvar, POST-only, guard `notificacoes_config`) → Task 2 ✅
- `alertas.php` itera catálogo, botão "Configurar alertas", `?action=dados` genérico, visual idêntico → Task 3 ✅
- `gat_alertas` / `portal_alertas_ocorrencias` / baseline / aba Gatilhos → **Etapa 2, fora deste plano** ✅
- The Dude → **Etapa 3, fora deste plano** ✅

**2. Placeholder scan:** Task 1 traz o código completo do `alertas_tipos.php`. Task 2 traz os handlers completos; o HTML/JS é descrito por estrutura (o executor produz o arquivo inteiro no estilo de `config_notificacoes.php`/`config_whatsapp.php`, que existem como referência). Task 3 traz os trechos exatos a substituir. Sem "TODO"/"tratar edge cases" solto.

**3. Type consistency:**
- `alertas_catalogo()[$slug]` tem `check`/`render` como **string** (nome de função) — `call_user_func($def['check'], $pdo, $params)` em Task 1 (contrato), Task 2 (`ler`), Task 3 (`alertas_carregar`). ✅
- Ocorrência: `chave`+`titulo` obrigatórios; `alerta_render_sem_inventario` usa `loja/nunca/dias/quando/cat/titulo`; `alerta_render_disco_cheio` usa `titulo/loja/volume/pct/usado/total` — os mesmos que o `check` correspondente produz. ✅
- `alertas_config_do_tipo()` retorna `{ativo:bool, params:array, notif_whatsapp:bool, lembrete_min:int, abre_chamado:bool}` — consumido assim em Task 2 e Task 3. ✅
- `?action=dados` novo formato `{ok,hora,total,secoes:[...]}` — Task 3 Step 3 (produz) e Step 5 (JS consome). O formato ANTIGO (`stats`, `sem_inv_html`, `disco_html`) deixa de existir; o JS é reescrito junto. ✅
- `portal_alertas_config` colunas (Task 1 CREATE) vs INSERT/SELECT (Task 2) — `tipo, ativo, params, notif_whatsapp, lembrete_min, abre_chamado`. ✅

## Execution Handoff

Plan complete and saved to `Docs/superpowers/plans/2026-09-08-central-alertas-etapa1.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
