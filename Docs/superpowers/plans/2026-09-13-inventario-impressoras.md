# Inventário — Impressoras (SNMP) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Habilitar o card "Impressoras" do Inventário — cadastro manual de impressoras por IP, consulta via SNMP (dados técnicos, consumíveis, contador de páginas), histórico de volume de impressão e integração com a Central de Alertas (offline / toner baixo).

**Architecture:** Mesmo padrão de `dude_lib.php`/`backup_lib.php`: uma lib pura (`impressoras_lib.php`) sem HTML/sessão, que cria suas próprias tabelas ao ser incluída. Um worker dedicado (`impressoras_worker.php`, container `portal-impressoras-worker`, mesmo molde do `portal-wpp-worker`) consulta cada impressora via SNMP a cada 20 min e grava snapshot + histórico. A consulta SNMP e o parsing da resposta são funções separadas (`impressora_snmp_consultar` faz I/O de rede; `impressora_snmp_parsear` só transforma dados, testável sem rede). Tela `inventario_impressoras.php` segue o padrão visual de `inventario_redes.php`. Dois tipos novos entram no catálogo existente da Central de Alertas (`alertas_tipos.php`), mesmo padrão de `alerta_check_dude_device`.

**Tech Stack:** PHP 8.2 + PDO (MariaDB `glpi2`), extensão `php-snmp` (nova), ApexCharts (já usado em `relatorios.php`) pro gráfico de histórico, mini-harness de testes próprio (`wpp/tests/`).

**Spec:** `Docs/superpowers/specs/2026-09-13-inventario-impressoras-design.md`

## Global Constraints

- Funções de lib: sem HTML, sem `session_start()`, sem `header()`, nunca lançam exceção pro chamador de fora (worker/tela) — erro vira `online => false` ou log, nunca fatal.
- Comentários em português. Tipagem PHP explícita em toda função nova.
- Testes: `wpp/tests/assert.php` (`t_ok`/`t_eq`), sem PHPUnit — mesmo padrão de `test_dude_lib.php`. Rodar local primeiro contra o banco descartável (`wpp-sdd-glpi-db` / `wpp-sdd-php:local`, rede `wpp-sdd-net`), depois sincronizar e rodar contra produção (`docker exec glpi-web php wpp/tests/run.php`).
- O host do servidor roda outros projetos não relacionados (`checklist-gmais-*`, `ponto-gmais-*`) em containers/compose separados — nenhum passo deste plano usa `docker compose down`; só `build`/`up -d` do(s) serviço(s) deste projeto.
- Deploy é por SCP manual pro servidor (`C:\docker\glpi-portal\glpi2\portal-glpi\`), nunca `git pull` no container — ver `docker/README` / memória do projeto.

---

## Task 1: Extensão SNMP no container `glpi-web`

**Files:**
- Modify: `docker/Dockerfile`

**Interfaces:**
- Produces: função PHP nativa `snmpget()`/`snmp2_walk()` disponível em qualquer script do portal (usada pela Task 3).

- [ ] **Step 1: Adicionar a extensão ao Dockerfile**

Em `docker/Dockerfile`, acrescentar `libsnmp-dev` à lista de pacotes `apt-get install` e `snmp` à lista de `docker-php-ext-install`:

```dockerfile
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libbz2-dev \
        libonig-dev \
        libsqlite3-dev \
        libsnmp-dev \
        iputils-ping \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        intl \
        bz2 \
        exif \
        zip \
        opcache \
        snmp \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php-custom.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html
```

- [ ] **Step 2: Commit**

```bash
git add docker/Dockerfile
git commit -m "feat: adiciona extensao snmp ao container glpi-web"
```

- [ ] **Step 3: Rebuild e verificação no servidor (deploy manual, fora do git)**

Runbook a seguir no deploy real (documentar em `wpp/README.md` ou similar na Task 8 — aqui é só a checagem técnica):

```bash
# no servidor, via ssh glpi-server
cd C:\docker\glpi-portal
docker compose build glpi-web
docker compose up -d glpi-web
docker compose build portal-wpp-worker   # mesma imagem (build: .) - reconstruir tambem
docker compose up -d portal-wpp-worker
docker exec glpi-web php -m | findstr -i snmp   # espera "snmp" na lista
docker ps --format "{{.Names}} | {{.Status}}"   # confirma checklist-gmais-*/ponto-gmais-* continuam "Up", sem restart
```

Expected: `snmp` aparece em `php -m`; todos os containers de outros projetos continuam com o mesmo tempo de "Up" (não reiniciaram).

---

## Task 2: Tabelas + CRUD de impressoras (`impressoras_lib.php`)

**Files:**
- Create: `impressoras_lib.php`
- Test: Create `wpp/tests/test_impressoras_lib.php`

**Interfaces:**
- Produces: `impressora_cadastrar(PDO,string,string,string,string=''public''):int`, `impressora_listar(PDO):array`, `impressora_buscar(PDO,int):?array`, `impressora_editar(PDO,int,string,string,string,string):void`, `impressora_excluir(PDO,int):void`. Consumidas pela Task 6 (tela) e Task 5 (worker).

- [ ] **Step 1: Escrever o teste**

Criar `wpp/tests/test_impressoras_lib.php`:

```php
<?php
// Testes de impressoras_lib.php — CRUD de cadastro. Roda com `php wpp/tests/run.php`.
require_once __DIR__ . '/../../impressoras_lib.php';
global $pdo;

$APELIDO_TESTE = '__teste_imp_hp__';

$pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();

try {
    $id = impressora_cadastrar($pdo, '10.0.9.50', $APELIDO_TESTE, 'Loja 05', 'public');
    t_ok($id > 0, 'impressora_cadastrar: devolve id > 0');

    $lista = impressora_listar($pdo);
    $achou = array_values(array_filter($lista, fn($i) => $i['apelido'] === $APELIDO_TESTE));
    t_eq(count($achou), 1, 'impressora_listar: cadastro aparece na lista');
    t_eq($achou[0]['ip'], '10.0.9.50', 'impressora_listar: ip salvo corretamente');
    t_eq($achou[0]['comunidade'], 'public', 'impressora_listar: comunidade default');

    $buscada = impressora_buscar($pdo, $id);
    t_ok($buscada !== null, 'impressora_buscar: acha pelo id');
    t_eq($buscada['loja'], 'Loja 05', 'impressora_buscar: loja salva corretamente');

    impressora_editar($pdo, $id, '10.0.9.51', $APELIDO_TESTE, 'Loja 06', 'privada123');
    $editada = impressora_buscar($pdo, $id);
    t_eq($editada['ip'], '10.0.9.51', 'impressora_editar: ip atualizado');
    t_eq($editada['loja'], 'Loja 06', 'impressora_editar: loja atualizada');
    t_eq($editada['comunidade'], 'privada123', 'impressora_editar: comunidade atualizada');

    impressora_excluir($pdo, $id);
    t_ok(impressora_buscar($pdo, $id) === null, 'impressora_excluir: some da base');
} finally {
    $pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```
php /scratch/run_scoped.php wpp/tests/test_impressoras_lib.php
```
Expected: `Call to undefined function impressora_cadastrar()` (ou erro de tabela inexistente).

- [ ] **Step 3: Implementar `impressoras_lib.php`**

```php
<?php
/**
 * impressoras_lib.php — cadastro e consulta de impressoras de rede via SNMP
 * pra Central de Alertas / Inventário.
 *
 * Funções puras: sem HTML de página, sem session_start, sem header().
 * Cria as tabelas ao ser incluído (padrão do portal, igual dude_lib.php).
 */

require_once __DIR__ . '/agenda/db.php';

(function () {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45)  NOT NULL,
        apelido      VARCHAR(120) NOT NULL,
        loja         VARCHAR(120) NOT NULL DEFAULT '',
        comunidade   VARCHAR(60)  NOT NULL DEFAULT 'public',
        ativo        TINYINT(1)   NOT NULL DEFAULT 1,
        criado_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_status (
        impressora_id    INT PRIMARY KEY,
        online           TINYINT(1) NOT NULL,
        modelo           VARCHAR(255) NULL,
        serial           VARCHAR(120) NULL,
        firmware         VARCHAR(120) NULL,
        paginas_total    INT NULL,
        consumiveis_json MEDIUMTEXT NULL,
        atualizado_em    DATETIME NOT NULL,
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_impressoras_historico (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        impressora_id INT NOT NULL,
        paginas_total INT NOT NULL,
        registrado_em DATETIME NOT NULL,
        KEY (impressora_id, registrado_em),
        FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

/* ───────────────────────────── CRUD ───────────────────────────── */

function impressora_cadastrar(PDO $pdo, string $ip, string $apelido, string $loja, string $comunidade = 'public'): int
{
    $pdo->prepare(
        "INSERT INTO portal_impressoras (ip, apelido, loja, comunidade) VALUES (?, ?, ?, ?)"
    )->execute([$ip, $apelido, $loja, $comunidade]);
    return (int) $pdo->lastInsertId();
}

/** Só as ativas — mesmo padrão de dude_categorias_vistas (config só existe pra quem está em uso). */
function impressora_listar(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_impressoras WHERE ativo = 1 ORDER BY loja, apelido")->fetchAll(PDO::FETCH_ASSOC);
}

function impressora_buscar(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_impressoras WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function impressora_editar(PDO $pdo, int $id, string $ip, string $apelido, string $loja, string $comunidade): void
{
    $pdo->prepare(
        "UPDATE portal_impressoras SET ip=?, apelido=?, loja=?, comunidade=? WHERE id=?"
    )->execute([$ip, $apelido, $loja, $comunidade, $id]);
}

function impressora_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM portal_impressoras WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add impressoras_lib.php wpp/tests/test_impressoras_lib.php
git commit -m "feat: impressoras_lib - tabelas + CRUD de cadastro"
```

---

## Task 3: Consulta e parsing SNMP

**Files:**
- Modify: `impressoras_lib.php` (fim do arquivo)
- Test: Modify `wpp/tests/test_impressoras_lib.php`

**Interfaces:**
- Consumes: nenhuma interna (usa a extensão `snmp` do PHP diretamente).
- Produces: `impressora_snmp_parsear(array):array` (testável sem rede), `impressora_snmp_consultar(string,string,int=2500):array` (faz a query de verdade — usada só pelo worker, Task 5). Formato de retorno de ambas: `['online'=>bool,'modelo'=>?string,'serial'=>?string,'firmware'=>?string,'paginas_total'=>?int,'consumiveis'=>[['nome'=>string,'nivel'=>?int,'max'=>?int],...]]`.

- [ ] **Step 1: Escrever o teste**

Acrescentar ao fim de `wpp/tests/test_impressoras_lib.php` (antes do `finally`, ou como bloco próprio já que não toca banco — pode ficar fora do `try/finally` do CRUD, no fim do arquivo):

```php
// --- impressora_snmp_parsear: puro, sem rede ---
$offline = impressora_snmp_parsear(['sysDescr' => false]);
t_ok($offline['online'] === false, 'snmp_parsear: sysDescr=false (timeout) -> online false');
t_ok($offline['modelo'] === null, 'snmp_parsear: offline -> modelo null');

$bruto = [
    'sysDescr'      => 'HP LaserJet Pro M404dn',
    'serial'        => 'VNC1234567',
    'paginas_total' => '48213',
    'consumiveis_descricoes' => ['Black Toner Cartridge', 'Imaging Drum'],
    'consumiveis_niveis'     => ['62', '-2'],
    'consumiveis_maximos'    => ['100', '100'],
];
$parseado = impressora_snmp_parsear($bruto);
t_ok($parseado['online'], 'snmp_parsear: com sysDescr -> online true');
t_eq($parseado['modelo'], 'HP LaserJet Pro M404dn', 'snmp_parsear: modelo = sysDescr');
t_eq($parseado['serial'], 'VNC1234567', 'snmp_parsear: serial');
t_eq($parseado['paginas_total'], 48213, 'snmp_parsear: paginas_total vira int');
t_eq(count($parseado['consumiveis']), 2, 'snmp_parsear: 2 consumiveis');
t_eq($parseado['consumiveis'][0]['nome'], 'Black Toner Cartridge', 'snmp_parsear: nome do consumivel 1');
t_eq($parseado['consumiveis'][0]['nivel'], 62, 'snmp_parsear: nivel do consumivel 1');
t_eq($parseado['consumiveis'][0]['max'], 100, 'snmp_parsear: max do consumivel 1');
t_ok($parseado['consumiveis'][1]['nivel'] === null, 'snmp_parsear: nivel -2 (sem percentual) vira null, nao erro');
t_ok($parseado['consumiveis'][1]['max'] === null, 'snmp_parsear: max tambem null quando nivel e -2');

$semConsumiveis = impressora_snmp_parsear(['sysDescr' => 'Brother HL-L2350DW', 'serial' => false, 'paginas_total' => null]);
t_ok($semConsumiveis['online'], 'snmp_parsear: online mesmo sem serial/paginas');
t_ok($semConsumiveis['serial'] === null, 'snmp_parsear: serial=false -> null');
t_ok($semConsumiveis['paginas_total'] === null, 'snmp_parsear: paginas_total=null -> null');
t_eq(count($semConsumiveis['consumiveis']), 0, 'snmp_parsear: sem consumiveis -> array vazio');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Call to undefined function impressora_snmp_parsear()`.

- [ ] **Step 3: Implementar** — no fim de `impressoras_lib.php`:

```php
/* ───────────────────────────── SNMP ───────────────────────────── */

const IMPRESSORA_OID_SYSDESCR   = '1.3.6.1.2.1.1.1.0';
const IMPRESSORA_OID_SERIAL     = '1.3.6.1.2.1.43.5.1.1.17.1';
const IMPRESSORA_OID_PAGINAS    = '1.3.6.1.2.1.43.10.2.1.4.1.1';
const IMPRESSORA_OID_SUP_DESC   = '1.3.6.1.2.1.43.11.1.1.6.1';
const IMPRESSORA_OID_SUP_NIVEL  = '1.3.6.1.2.1.43.11.1.1.9.1';
const IMPRESSORA_OID_SUP_MAX    = '1.3.6.1.2.1.43.11.1.1.8.1';

/**
 * Consulta SNMP de verdade (I/O de rede) — separada de impressora_snmp_parsear()
 * só pra essa poder ser testada sem rede/hardware físico.
 * Nunca lança: timeout/erro de rede vira ['online' => false, ...].
 */
function impressora_snmp_consultar(string $ip, string $comunidade, int $timeoutMs = 2500): array
{
    $timeoutUs = $timeoutMs * 1000;
    snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    snmp_set_quick_print(true);

    $bruto = [
        'sysDescr'      => @snmpget($ip, $comunidade, IMPRESSORA_OID_SYSDESCR, $timeoutUs, 1),
        'serial'        => @snmpget($ip, $comunidade, IMPRESSORA_OID_SERIAL, $timeoutUs, 1),
        'paginas_total' => @snmpget($ip, $comunidade, IMPRESSORA_OID_PAGINAS, $timeoutUs, 1),
        'consumiveis_descricoes' => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_DESC, $timeoutUs, 1) ?: [],
        'consumiveis_niveis'     => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_NIVEL, $timeoutUs, 1) ?: [],
        'consumiveis_maximos'    => @snmp2_walk($ip, $comunidade, IMPRESSORA_OID_SUP_MAX, $timeoutUs, 1) ?: [],
    ];

    return impressora_snmp_parsear($bruto);
}

/**
 * Transforma a resposta bruta do SNMP (ou um array simulado, nos testes) no
 * formato usado pelo resto do sistema. Nunca lança.
 *
 * $bruto: ['sysDescr'=>string|false, 'serial'=>string|false|null,
 *          'paginas_total'=>string|int|null|false,
 *          'consumiveis_descricoes'=>array, 'consumiveis_niveis'=>array, 'consumiveis_maximos'=>array]
 */
function impressora_snmp_parsear(array $bruto): array
{
    $sysDescr = $bruto['sysDescr'] ?? false;
    if ($sysDescr === false || $sysDescr === null || $sysDescr === '') {
        return ['online' => false, 'modelo' => null, 'serial' => null, 'firmware' => null, 'paginas_total' => null, 'consumiveis' => []];
    }

    $serial = $bruto['serial'] ?? null;
    $serial = (is_string($serial) && $serial !== '') ? trim($serial) : null;

    $paginasRaw = $bruto['paginas_total'] ?? null;
    $paginas    = (is_numeric($paginasRaw)) ? (int) $paginasRaw : null;

    $consumiveis = [];
    $descricoes = $bruto['consumiveis_descricoes'] ?? [];
    $niveis     = array_values($bruto['consumiveis_niveis'] ?? []);
    $maximos    = array_values($bruto['consumiveis_maximos'] ?? []);
    $i = 0;
    foreach (array_values($descricoes) as $nome) {
        $nivelRaw = $niveis[$i] ?? null;
        $maxRaw   = $maximos[$i] ?? null;
        $nivel = is_numeric($nivelRaw) ? (int) $nivelRaw : null;
        $max   = is_numeric($maxRaw) ? (int) $maxRaw : null;
        // -2 = "nao reporta percentual" (RFC 3805 prtMarkerSuppliesLevel) - trata como sem dado, nao erro.
        if ($nivel === -2 || $max === null || $max <= 0) {
            $nivel = null;
            $max   = null;
        }
        $consumiveis[] = ['nome' => (string) $nome, 'nivel' => $nivel, 'max' => $max];
        $i++;
    }

    return [
        'online'        => true,
        'modelo'        => trim((string) $sysDescr),
        'serial'        => $serial,
        'firmware'      => null, // sysDescr costuma trazer versao junto do modelo, sem OID separado universal
        'paginas_total' => $paginas,
        'consumiveis'   => $consumiveis,
    ];
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas` (o ambiente de teste não precisa da extensão `snmp` — só `impressora_snmp_parsear` é testada, que não chama nenhuma função `snmp*`).

- [ ] **Step 5: Commit**

```bash
git add impressoras_lib.php wpp/tests/test_impressoras_lib.php
git commit -m "feat: impressoras_lib - consulta e parsing SNMP (Printer-MIB)"
```

---

## Task 4: Status atual + histórico de páginas

**Files:**
- Modify: `impressoras_lib.php` (fim do arquivo)
- Test: Modify `wpp/tests/test_impressoras_lib.php`

**Interfaces:**
- Consumes: formato de retorno de `impressora_snmp_parsear`/`impressora_snmp_consultar` (Task 3).
- Produces: `impressora_status_salvar(PDO,int,array):void`, `impressora_status_atual(PDO,int):?array`, `impressora_historico_paginas(PDO,int,int=90):array`. Consumidas pelo worker (Task 5) e pela tela (Task 6).

- [ ] **Step 1: Escrever o teste**

Acrescentar a `wpp/tests/test_impressoras_lib.php`, dentro de um novo bloco `try/finally` próprio (precisa de uma impressora cadastrada):

```php
// --- status + historico (precisa de uma impressora cadastrada) ---
$pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
try {
    $idImp = impressora_cadastrar($pdo, '10.0.9.60', '__teste_imp_status__', 'Loja 07', 'public');

    $consulta1 = [
        'online' => true, 'modelo' => 'Konica bizhub C227', 'serial' => 'SN001', 'firmware' => null,
        'paginas_total' => 1000, 'consumiveis' => [['nome' => 'Toner Preto', 'nivel' => 80, 'max' => 100]],
    ];
    impressora_status_salvar($pdo, $idImp, $consulta1);

    $status = impressora_status_atual($pdo, $idImp);
    t_ok($status !== null, 'status_atual: existe depois de salvar');
    t_eq($status['modelo'], 'Konica bizhub C227', 'status_atual: modelo salvo');
    t_eq($status['paginas_total'], 1000, 'status_atual: paginas_total salvo');
    t_eq($status['consumiveis'][0]['nome'], 'Toner Preto', 'status_atual: consumiveis_json decodificado');

    $hist1 = impressora_historico_paginas($pdo, $idImp);
    t_eq(count($hist1), 1, 'historico_paginas: 1a leitura grava 1 linha');

    // 2a leitura com o MESMO contador -> nao duplica no historico
    impressora_status_salvar($pdo, $idImp, $consulta1);
    $hist2 = impressora_historico_paginas($pdo, $idImp);
    t_eq(count($hist2), 1, 'historico_paginas: contador igual -> nao acrescenta linha nova');

    // 3a leitura com contador MAIOR -> acrescenta
    $consulta2 = $consulta1;
    $consulta2['paginas_total'] = 1050;
    impressora_status_salvar($pdo, $idImp, $consulta2);
    $hist3 = impressora_historico_paginas($pdo, $idImp);
    t_eq(count($hist3), 2, 'historico_paginas: contador mudou -> acrescenta linha');
    t_eq($hist3[1]['paginas_total'], 1050, 'historico_paginas: ordenado por data, ultimo valor certo');

    // offline: online=false ainda atualiza o status (fica sabendo que caiu), sem novo historico se paginas_total=null
    $offline = ['online' => false, 'modelo' => null, 'serial' => null, 'firmware' => null, 'paginas_total' => null, 'consumiveis' => []];
    impressora_status_salvar($pdo, $idImp, $offline);
    $statusOffline = impressora_status_atual($pdo, $idImp);
    t_eq((int) $statusOffline['online'], 0, 'status_salvar: online=false atualiza o status');
    $histOffline = impressora_historico_paginas($pdo, $idImp);
    t_eq(count($histOffline), 2, 'status_salvar: offline (paginas_total null) nao acrescenta linha no historico');
} finally {
    $pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Expected: `Call to undefined function impressora_status_salvar()`.

- [ ] **Step 3: Implementar** — no fim de `impressoras_lib.php`:

```php
/* ───────────────────────────── Status + histórico ───────────────────────────── */

function impressora_status_salvar(PDO $pdo, int $impressoraId, array $consulta): void
{
    $pdo->prepare(
        "INSERT INTO portal_impressoras_status
            (impressora_id, online, modelo, serial, firmware, paginas_total, consumiveis_json, atualizado_em)
         VALUES (?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE online=VALUES(online), modelo=VALUES(modelo), serial=VALUES(serial),
             firmware=VALUES(firmware), paginas_total=VALUES(paginas_total),
             consumiveis_json=VALUES(consumiveis_json), atualizado_em=NOW()"
    )->execute([
        $impressoraId,
        $consulta['online'] ? 1 : 0,
        $consulta['modelo'] ?? null,
        $consulta['serial'] ?? null,
        $consulta['firmware'] ?? null,
        $consulta['paginas_total'] ?? null,
        !empty($consulta['consumiveis']) ? json_encode($consulta['consumiveis']) : null,
    ]);

    $paginas = $consulta['paginas_total'] ?? null;
    if ($paginas === null) {
        return; // offline ou modelo nao reporta contador - nao ha o que gravar no historico
    }

    $ultimo = $pdo->prepare(
        "SELECT paginas_total FROM portal_impressoras_historico WHERE impressora_id = ? ORDER BY registrado_em DESC, id DESC LIMIT 1"
    );
    $ultimo->execute([$impressoraId]);
    $anterior = $ultimo->fetchColumn();

    if ($anterior === false || (int) $anterior !== (int) $paginas) {
        $pdo->prepare(
            "INSERT INTO portal_impressoras_historico (impressora_id, paginas_total, registrado_em) VALUES (?, ?, NOW())"
        )->execute([$impressoraId, $paginas]);
    }
}

function impressora_status_atual(PDO $pdo, int $impressoraId): ?array
{
    $st = $pdo->prepare("SELECT * FROM portal_impressoras_status WHERE impressora_id = ?");
    $st->execute([$impressoraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    $row['consumiveis'] = $row['consumiveis_json'] ? json_decode($row['consumiveis_json'], true) : [];
    return $row;
}

/** @return array linhas ['paginas_total'=>int,'registrado_em'=>string], mais antiga primeiro. */
function impressora_historico_paginas(PDO $pdo, int $impressoraId, int $dias = 90): array
{
    $st = $pdo->prepare(
        "SELECT paginas_total, registrado_em FROM portal_impressoras_historico
         WHERE impressora_id = ? AND registrado_em >= NOW() - INTERVAL ? DAY
         ORDER BY registrado_em, id"
    );
    $st->execute([$impressoraId, $dias]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 5: Commit**

```bash
git add impressoras_lib.php wpp/tests/test_impressoras_lib.php
git commit -m "feat: impressoras_lib - status atual e historico de paginas"
```

---

## Task 5: Worker de coleta (`impressoras_worker.php` + container)

**Files:**
- Create: `impressoras_worker.php`
- Modify: `docker/docker-compose.yml`

**Interfaces:**
- Consumes: `impressora_listar` (Task 2), `impressora_snmp_consultar` (Task 3), `impressora_status_salvar` (Task 4).

- [ ] **Step 1: Criar `impressoras_worker.php`**

```php
<?php
// impressoras_worker.php — consulta SNMP de cada impressora ativa e grava
// status + histórico. Roda em loop pelo container portal-impressoras-worker
// (20 min entre execuções — página/toner não muda rápido).
require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/impressoras_lib.php';

global $pdo;

foreach (impressora_listar($pdo) as $imp) {
    try {
        $consulta = impressora_snmp_consultar($imp['ip'], $imp['comunidade']);
        impressora_status_salvar($pdo, (int) $imp['id'], $consulta);
    } catch (\Throwable $e) {
        // 1 impressora falhando (rede fora, IP mudou etc.) nao pode
        // impedir a consulta das outras.
        error_log('impressoras_worker: falha ao consultar ' . $imp['ip'] . ': ' . $e->getMessage());
    }
}
```

- [ ] **Step 2: Acrescentar o serviço no `docker/docker-compose.yml`**

Logo depois do bloco `portal-wpp-worker:` (mesmo padrão — `build: .`, mesma imagem do `glpi-web`, já com a extensão `snmp` da Task 1):

```yaml
  # Consulta SNMP de cada impressora cadastrada a cada 20 min, grava status
  # + histórico de páginas. Mesma imagem do glpi-web (precisa da extensão snmp).
  portal-impressoras-worker:
    build: .
    container_name: portal-impressoras-worker
    restart: unless-stopped
    depends_on:
      - glpi-db
    volumes:
      - C:\docker\glpi-portal\glpi2:/var/www/html/glpi2
    networks:
      - glpi-net
    entrypoint: ["sh", "-c", "while true; do php /var/www/html/glpi2/portal-glpi/impressoras_worker.php; sleep 1200; done"]
```

(Confirmado contra o bloco real de `portal-wpp-worker` no `docker/docker-compose.yml`, linhas 63-73: mesma rede `glpi-net`, mesmo volume. `depends_on` aqui só lista `glpi-db` — não `evolution-api`, que o worker de impressoras não usa.)

- [ ] **Step 3: Commit**

```bash
git add impressoras_worker.php docker/docker-compose.yml
git commit -m "feat: worker de coleta SNMP das impressoras (container dedicado)"
```

- [ ] **Step 4: Deploy manual (fora do git) — documentar junto da Task 8**

```bash
# no servidor
scp impressoras_worker.php glpi-server:C:/docker/glpi-portal/glpi2/portal-glpi/impressoras_worker.php
scp impressoras_lib.php glpi-server:C:/docker/glpi-portal/glpi2/portal-glpi/impressoras_lib.php
# editar C:\docker\glpi-portal\docker-compose.yml no servidor a mao (nao sincroniza por git - ver Global Constraints)
ssh glpi-server "cd C:\docker\glpi-portal && docker compose up -d portal-impressoras-worker"
ssh glpi-server "docker logs portal-impressoras-worker --tail 20"
```

Expected: sem erro fatal no log (lista vazia de impressoras no primeiro deploy = loop não faz nada, o que é esperado até a Task 6 permitir cadastrar a primeira).

---

## Task 6: Tela `inventario_impressoras.php`

**Files:**
- Create: `inventario_impressoras.php`
- Modify: `inventario.php` (habilita o card, feito na Task 8 — aqui só a página nova)

**Interfaces:**
- Consumes: `impressora_listar`, `impressora_buscar`, `impressora_cadastrar`, `impressora_editar`, `impressora_excluir` (Task 2), `impressora_status_atual`, `impressora_historico_paginas` (Task 4).

- [ ] **Step 1: Criar a página** — segue o padrão de `inventario_redes.php` (auth_guard, checagem de admin, handlers AJAX no topo, HTML depois):

```php
<?php
/**
 * inventario_impressoras.php — cadastro e status de impressoras de rede
 * (SNMP). Mesmo padrão visual/estrutural de inventario_redes.php.
 */
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/agenda/db.php';
require_once __DIR__ . '/impressoras_lib.php';

$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin', 'super-admin', 'tecnico']);
$H = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* ─────────── Handlers AJAX ─────────── */
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');

    if ($action === 'listar') {
        $lista = [];
        foreach (impressora_listar($pdo) as $imp) {
            $status = impressora_status_atual($pdo, (int) $imp['id']);
            $lista[] = ['impressora' => $imp, 'status' => $status];
        }
        echo json_encode(['ok' => true, 'lista' => $lista]);
        exit;
    }

    if ($action === 'historico') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id invalido']); exit; }
        echo json_encode(['ok' => true, 'historico' => impressora_historico_paginas($pdo, $id, 90)]);
        exit;
    }

    if (!$is_admin) { echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }

    if ($action === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $id        = (int) ($_POST['id'] ?? 0);
        $ip        = trim((string) ($_POST['ip'] ?? ''));
        $apelido   = trim((string) ($_POST['apelido'] ?? ''));
        $loja      = trim((string) ($_POST['loja'] ?? ''));
        $comunidade = trim((string) ($_POST['comunidade'] ?? '')) ?: 'public';
        if ($ip === '' || $apelido === '') { echo json_encode(['ok' => false, 'erro' => 'ip e apelido sao obrigatorios']); exit; }
        try {
            if ($id > 0) {
                impressora_editar($pdo, $id, $ip, $apelido, $loja, $comunidade);
            } else {
                impressora_cadastrar($pdo, $ip, $apelido, $loja, $comunidade);
            }
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'falha ao salvar']);
        }
        exit;
    }

    if ($action === 'excluir') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'erro' => 'id invalido']); exit; }
        impressora_excluir($pdo, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'acao desconhecida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventário — Impressoras</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
  <style>
    :root { --primary:#e91e63; }
    body { background:#f0f4f9; font-family:'Segoe UI',sans-serif; margin:0; }
    .topbar { background:linear-gradient(135deg,var(--primary),#ad1457); color:#fff; padding:.75rem 1.5rem;
              display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,.25); }
    .topbar .brand { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .topbar a { color:#fff; text-decoration:none; font-size:.82rem; background:rgba(255,255,255,.15); border-radius:6px; padding:.3rem .75rem; }
    .wrap { max-width:1100px; margin:1.5rem auto 3rem; padding:0 1rem; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:1.1rem 1.25rem; margin-bottom:1.25rem; }
    .imp-card { border:1px solid #e5e7eb; border-radius:10px; padding:.9rem 1rem; margin-bottom:.75rem; }
    .imp-card .status-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:.4rem; }
    .status-on  { background:#22c55e; }
    .status-off { background:#ef4444; }
    .consumivel-bar { background:#e5e7eb; border-radius:6px; height:8px; overflow:hidden; width:120px; display:inline-block; vertical-align:middle; }
    .consumivel-fill { height:100%; background:#e91e63; }
    footer { text-align:center; color:#bbb; font-size:.78rem; padding:2rem; }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="bi bi-printer"></i> Inventário — Impressoras</div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="inventario.php"><i class="bi bi-arrow-left me-1"></i>Inventário</a>
    <a href="dashboard.php"><i class="bi bi-grid me-1"></i>Início</a>
  </div>
</div>

<div class="wrap">
  <?php if ($is_admin): ?>
  <div class="card-box">
    <h6 class="mb-2">Cadastrar impressora</h6>
    <div class="d-flex gap-2 flex-wrap align-items-end">
      <div><label class="form-label small mb-0">IP</label>
        <input type="text" id="f-ip" class="form-control form-control-sm" style="width:140px" placeholder="10.0.0.50"></div>
      <div><label class="form-label small mb-0">Apelido</label>
        <input type="text" id="f-apelido" class="form-control form-control-sm" style="width:180px"></div>
      <div><label class="form-label small mb-0">Loja</label>
        <input type="text" id="f-loja" class="form-control form-control-sm" style="width:140px"></div>
      <div><label class="form-label small mb-0">Comunidade SNMP</label>
        <input type="text" id="f-comunidade" class="form-control form-control-sm" style="width:130px" value="public"></div>
      <input type="hidden" id="f-id" value="0">
      <button type="button" class="btn btn-primary btn-sm" onclick="salvarImpressora()">Salvar</button>
    </div>
    <div id="fb-form" class="small mt-2"></div>
  </div>
  <?php endif; ?>

  <div class="card-box">
    <h6 class="mb-2">Impressoras</h6>
    <div id="lista">Carregando…</div>
  </div>

  <div class="card-box d-none" id="card-historico">
    <h6 class="mb-2">Histórico de páginas — <span id="hist-nome"></span></h6>
    <div id="chart-historico"></div>
  </div>
</div>
<footer><i class="bi bi-shield-lock me-1"></i>Central de TI — Impressoras</footer>

<script>
function $(id) { return document.getElementById(id); }
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

function barraConsumivel(c) {
  if (c.nivel === null) return c.nome + ': <span class="text-muted">sem dado</span>';
  const pct = Math.max(0, Math.min(100, Math.round((c.nivel / c.max) * 100)));
  const cor = pct < 15 ? '#ef4444' : (pct < 30 ? '#f59e0b' : '#e91e63');
  return c.nome + ': <span class="consumivel-bar"><span class="consumivel-fill" style="width:' + pct + '%;background:' + cor + '"></span></span> ' + pct + '%';
}

function linhaImpressora(item) {
  const imp = item.impressora, st = item.status;
  const div = document.createElement('div');
  div.className = 'imp-card';
  const online = st && st.online == 1;
  let html = '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">';
  html += '<div><span class="status-dot ' + (online ? 'status-on' : 'status-off') + '"></span>';
  html += '<b>' + imp.apelido + '</b> <span class="text-muted small">(' + imp.ip + ' · ' + (imp.loja || 'sem loja') + ')</span><br>';
  if (st) {
    html += '<span class="small text-muted">' + (st.modelo || 'modelo desconhecido') + (st.serial ? ' · S/N ' + st.serial : '') + '</span><br>';
    html += '<span class="small">Páginas: ' + (st.paginas_total !== null ? st.paginas_total : '—') + '</span><br>';
    (st.consumiveis || []).forEach(function (c) { html += '<div class="small mt-1">' + barraConsumivel(c) + '</div>'; });
  } else {
    html += '<span class="small text-muted">ainda sem leitura</span>';
  }
  html += '</div><div class="d-flex gap-2">';
  html += '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="verHistorico(' + imp.id + ', \'' + imp.apelido.replace(/'/g, "\\'") + '\')">Histórico</button>';
  if (IS_ADMIN) {
    html += '<button type="button" class="btn btn-outline-primary btn-sm" onclick="editarImpressora(' + imp.id + ', \'' + imp.ip + '\', \'' + imp.apelido.replace(/'/g, "\\'") + '\', \'' + (imp.loja || '').replace(/'/g, "\\'") + '\', \'' + imp.comunidade + '\')">Editar</button>';
    html += '<button type="button" class="btn btn-outline-danger btn-sm" onclick="excluirImpressora(' + imp.id + ')">Excluir</button>';
  }
  html += '</div></div>';
  div.innerHTML = html;
  return div;
}

function carregarLista() {
  fetch('inventario_impressoras.php?action=listar').then(function (r) { return r.json(); }).then(function (d) {
    const lista = $('lista');
    lista.innerHTML = '';
    if (!d.ok || !d.lista.length) { lista.innerHTML = '<div class="text-muted small">Nenhuma impressora cadastrada.</div>'; return; }
    d.lista.forEach(function (item) { lista.appendChild(linhaImpressora(item)); });
  });
}

function editarImpressora(id, ip, apelido, loja, comunidade) {
  $('f-id').value = id; $('f-ip').value = ip; $('f-apelido').value = apelido;
  $('f-loja').value = loja; $('f-comunidade').value = comunidade;
}

function salvarImpressora() {
  const params = new URLSearchParams({
    id: $('f-id').value, ip: $('f-ip').value, apelido: $('f-apelido').value,
    loja: $('f-loja').value, comunidade: $('f-comunidade').value,
  });
  fetch('inventario_impressoras.php?action=salvar', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      const fb = $('fb-form');
      if (!d.ok) { fb.className = 'small mt-2 text-danger'; fb.textContent = d.erro || 'erro ao salvar'; return; }
      fb.className = 'small mt-2 text-success'; fb.textContent = 'salvo.';
      $('f-id').value = 0; $('f-ip').value = ''; $('f-apelido').value = ''; $('f-loja').value = ''; $('f-comunidade').value = 'public';
      carregarLista();
    });
}

function excluirImpressora(id) {
  if (!confirm('Excluir esta impressora do cadastro?')) return;
  const params = new URLSearchParams({ id: id });
  fetch('inventario_impressoras.php?action=excluir', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function (r) { return r.json(); }).then(function () { carregarLista(); });
}

let chartHistorico = null;
function verHistorico(id, apelido) {
  fetch('inventario_impressoras.php?action=historico&id=' + id).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) return;
    $('card-historico').classList.remove('d-none');
    $('hist-nome').textContent = apelido;
    const categorias = d.historico.map(function (h) { return h.registrado_em; });
    const valores = d.historico.map(function (h) { return h.paginas_total; });
    if (chartHistorico) chartHistorico.destroy();
    chartHistorico = new ApexCharts($('chart-historico'), {
      chart: { type: 'area', height: 260 },
      series: [{ name: 'Páginas (contador total)', data: valores }],
      xaxis: { categories: categorias, labels: { rotate: -45 } },
      colors: ['#e91e63'],
    });
    chartHistorico.render();
  });
}

carregarLista();
</script>
</body>
</html>
```

- [ ] **Step 2: Verificação manual (sem teste automatizado — página com sessão/HTML)**

Abrir `inventario_impressoras.php` logado: cadastrar 1 impressora de teste, confirmar que aparece na lista "ainda sem leitura" (worker ainda não rodou), editar, excluir. Sem impressora física disponível, isso é o suficiente pra validar a tela — o dado real vem depois que o worker (Task 5) já estiver de pé e tiver rodado pelo menos 1 ciclo.

- [ ] **Step 3: Commit**

```bash
git add inventario_impressoras.php
git commit -m "feat: tela de impressoras - cadastro, status e historico"
```

---

## Task 7: Integração com a Central de Alertas

**Files:**
- Modify: `alertas_tipos.php`
- Test: Modify `wpp/tests/test_impressoras_lib.php`

**Interfaces:**
- Consumes: `portal_impressoras`/`portal_impressoras_status` (Tasks 2 e 4).
- Produces: `alerta_check_impressora_offline(PDO,array):array`, `alerta_check_impressora_toner_baixo(PDO,array):array` — registradas no catálogo (`alertas_catalogo()`), mesmo contrato de `alerta_check_dude_device` (array de `['chave','titulo','loja','categoria','detalhe']`).

- [ ] **Step 1: Ler `alertas_tipos.php` inteiro antes de editar**

Confirmar que nada mudou no formato de `alertas_catalogo()` desde a escrita deste plano (o array termina em `sefaz_ms`, linha ~221, seguido de `];` fechando a função) antes de acrescentar as novas entradas do Step 4.

- [ ] **Step 2: Escrever o teste**

Acrescentar a `wpp/tests/test_impressoras_lib.php`:

```php
// --- integracao com a Central de Alertas ---
require_once __DIR__ . '/../../alertas_tipos.php';

$pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
try {
    $idOn  = impressora_cadastrar($pdo, '10.0.9.70', '__teste_imp_online__', 'Loja 08', 'public');
    $idOff = impressora_cadastrar($pdo, '10.0.9.71', '__teste_imp_offline__', 'Loja 08', 'public');
    $idTonerBaixo = impressora_cadastrar($pdo, '10.0.9.72', '__teste_imp_toner__', 'Loja 09', 'public');

    impressora_status_salvar($pdo, $idOn, ['online' => true, 'modelo' => 'M1', 'serial' => null, 'firmware' => null, 'paginas_total' => 10, 'consumiveis' => [['nome' => 'Toner', 'nivel' => 90, 'max' => 100]]]);
    impressora_status_salvar($pdo, $idOff, ['online' => false, 'modelo' => null, 'serial' => null, 'firmware' => null, 'paginas_total' => null, 'consumiveis' => []]);
    impressora_status_salvar($pdo, $idTonerBaixo, ['online' => true, 'modelo' => 'M3', 'serial' => null, 'firmware' => null, 'paginas_total' => 20, 'consumiveis' => [['nome' => 'Toner Preto', 'nivel' => 5, 'max' => 100]]]);

    $ocOffline = alerta_check_impressora_offline($pdo, []);
    $achouOff = array_filter($ocOffline, fn($o) => str_contains($o['chave'], (string) $idOff));
    t_ok((bool) $achouOff, 'check_impressora_offline: impressora offline aparece');
    $achouOn = array_filter($ocOffline, fn($o) => str_contains($o['chave'], (string) $idOn));
    t_ok(!$achouOn, 'check_impressora_offline: impressora online nao aparece');

    $ocToner = alerta_check_impressora_toner_baixo($pdo, ['limiar' => 10]);
    $achouToner = array_filter($ocToner, fn($o) => str_contains($o['chave'], (string) $idTonerBaixo));
    t_ok((bool) $achouToner, 'check_impressora_toner_baixo: consumivel abaixo do limiar aparece');
    $achouOnToner = array_filter($ocToner, fn($o) => str_contains($o['chave'], (string) $idOn));
    t_ok(!$achouOnToner, 'check_impressora_toner_baixo: consumivel acima do limiar nao aparece');
} finally {
    $pdo->prepare("DELETE FROM portal_impressoras WHERE apelido LIKE '__teste_imp_%'")->execute();
}
```

- [ ] **Step 3: Rodar e confirmar que falha**

Expected: `Call to undefined function alerta_check_impressora_offline()`.

- [ ] **Step 4: Implementar**

Acrescentar ao topo de `alertas_tipos.php`, junto dos outros `require_once` (linha ~19, depois de `sefaz_lib.php`):

```php
require_once __DIR__ . '/impressoras_lib.php';
```

Acrescentar as funções de check (fora de `alertas_catalogo()`, junto das outras funções `alerta_check_*` do arquivo):

```php
/** @return array ocorrências: impressora cadastrada, sem resposta SNMP no último poll. */
function alerta_check_impressora_offline(PDO $pdo, array $p): array
{
    $st = $pdo->query("
        SELECT i.id, i.apelido, i.loja, s.atualizado_em
        FROM portal_impressoras i
        JOIN portal_impressoras_status s ON s.impressora_id = i.id
        WHERE i.ativo = 1 AND s.online = 0
        ORDER BY i.loja, i.apelido
    ");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'chave'     => 'impressora:offline:' . $r['id'],
            'titulo'    => $r['apelido'],
            'loja'      => (string) $r['loja'],
            'categoria' => 'Impressora',
            'detalhe'   => 'sem resposta SNMP (desde ' . date('d/m H:i', strtotime($r['atualizado_em'])) . ')',
        ];
    }
    return $out;
}

/**
 * @return array ocorrências: algum consumível (toner/drum/etc.) abaixo do
 * limiar configurado (default 10%). $p['limiar'] em porcentagem inteira.
 */
function alerta_check_impressora_toner_baixo(PDO $pdo, array $p): array
{
    $limiar = (int) ($p['limiar'] ?? 10);
    $st = $pdo->query("
        SELECT i.id, i.apelido, i.loja, s.consumiveis_json
        FROM portal_impressoras i
        JOIN portal_impressoras_status s ON s.impressora_id = i.id
        WHERE i.ativo = 1 AND s.online = 1 AND s.consumiveis_json IS NOT NULL
        ORDER BY i.loja, i.apelido
    ");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $consumiveis = json_decode($r['consumiveis_json'], true) ?: [];
        $baixos = [];
        foreach ($consumiveis as $c) {
            if ($c['nivel'] === null || $c['max'] === null || $c['max'] <= 0) continue;
            $pct = ($c['nivel'] / $c['max']) * 100;
            if ($pct < $limiar) $baixos[] = $c['nome'] . ' (' . round($pct) . '%)';
        }
        if (!$baixos) continue;
        $out[] = [
            'chave'     => 'impressora:toner:' . $r['id'],
            'titulo'    => $r['apelido'],
            'loja'      => (string) $r['loja'],
            'categoria' => 'Impressora',
            'detalhe'   => implode(', ', $baixos),
        ];
    }
    return $out;
}
```

Registrar as 2 entradas em `alertas_catalogo()`, logo antes do `];` que fecha o array (depois de `sefaz_ms`, ~linha 221). Reaproveita `alerta_render_dude` — mesmo formato de ocorrência (`titulo`/`loja`/`categoria`/`detalhe`), já compartilhado pelos 6 tipos `dude_*`, sem precisar de renderer próprio:

```php
        'impressora_offline' => [
            'nome'      => 'Impressora offline',
            'descricao' => 'Impressora cadastrada sem resposta SNMP no último ciclo do worker.',
            'params'    => [],
            'check'  => 'alerta_check_impressora_offline',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-printer',
            'cor'    => 'danger',
        ],
        'impressora_toner_baixo' => [
            'nome'      => 'Toner/consumível baixo',
            'descricao' => 'Consumível (toner, drum, coletor) de uma impressora abaixo do limiar configurado.',
            'params'    => [
                'limiar' => ['label' => 'Nível mínimo (%)', 'default' => 10, 'min' => 1, 'max' => 50],
            ],
            'check'  => 'alerta_check_impressora_toner_baixo',
            'render' => 'alerta_render_dude',
            'icone'  => 'bi-droplet-half',
            'cor'    => 'warning',
        ],
```

- [ ] **Step 5: Rodar e confirmar que passa**

Expected: `0 falhas`.

- [ ] **Step 6: Commit**

```bash
git add alertas_tipos.php wpp/tests/test_impressoras_lib.php
git commit -m "feat: alertas - impressora offline e toner baixo no catalogo"
```

---

## Task 8: Habilitar o card + runbook de deploy

**Files:**
- Modify: `inventario.php`
- Modify: `wpp/README.md` (ou arquivo de runbook equivalente do projeto)

**Interfaces:**
- Nenhuma nova — só liga o link já existente.

- [ ] **Step 1: Habilitar o card em `inventario.php`**

Trocar o card desabilitado de Impressoras (linhas ~156-161, ver `inventario.php` atual) por um link ativo, no mesmo molde do card "Redes":

```php
  <a href="inventario_impressoras.php" class="cat-card" style="border-top-color:#e91e63">
    <div class="cat-icon printer-icon"><i class="bi bi-printer"></i></div>
    <h3>Impressoras</h3>
    <p>Impressoras de rede — status, consumíveis e histórico (SNMP)</p>
  </a>
```

- [ ] **Step 2: Escrever o runbook de deploy**

Seção nova em `wpp/README.md` (ou criar `impressoras_README.md` na raiz, seguindo o padrão local de runbooks por feature):

```
====================================================================
 INVENTARIO - IMPRESSORAS (SNMP)
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Cadastro manual de impressoras (IP + apelido + loja + comunidade
    SNMP) em inventario_impressoras.php.
  * Worker dedicado (portal-impressoras-worker) consulta cada
    impressora via SNMP a cada 20 min: modelo, serial, contador de
    paginas, niveis de consumivel (toner/drum/etc).
  * Historico de paginas por impressora (grafico), so grava linha
    nova quando o contador muda.
  * 2 tipos novos na Central de Alertas: impressora offline e toner
    abaixo de 10%.

PRE-REQUISITO
--------------------------------------------------------------------
[ ] Extensao snmp instalada no container glpi-web (Task 1 deste
    plano) - confirmar com `docker exec glpi-web php -m` antes de
    seguir.
[ ] Impressoras respondem SNMP na rede (comunidade default "public"
    - confirmar com o time de rede se alguma usa comunidade
    diferente ou tem SNMP desabilitado por politica de seguranca).

ARQUIVOS A SINCRONIZAR
----
  - docker/Dockerfile (rebuild, nao e so scp)
  - impressoras_lib.php
  - impressoras_worker.php
  - inventario_impressoras.php
  - inventario.php
  - alertas_tipos.php
  - wpp/tests/test_impressoras_lib.php

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos PHP listados acima.
[ ] 2. docker compose build glpi-web && docker compose up -d glpi-web
[ ] 3. docker compose build portal-wpp-worker && docker compose up -d portal-wpp-worker
       (mesma imagem do glpi-web - precisa reconstruir tambem)
[ ] 4. Editar C:\docker\glpi-portal\docker-compose.yml no servidor a
       mao (nao sincroniza por git) - acrescentar o servico
       portal-impressoras-worker.
[ ] 5. docker compose up -d portal-impressoras-worker
[ ] 6. Confirmar que checklist-gmais-*/ponto-gmais-* continuam "Up"
       sem reiniciar: docker ps --format "{{.Names}} | {{.Status}}"
[ ] 7. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas".

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. Abrir Inventario -> card Impressoras nao esta mais "Em breve".
[ ] 2. Cadastrar 1 impressora real (IP de uma Brother/HP/Konica da
       rede).
[ ] 3. Esperar ate 20 min (ou rodar `docker exec glpi-web php
       /var/www/html/glpi2/portal-glpi/impressoras_worker.php` na
       mao pra nao esperar) -> recarregar a tela -> confere modelo,
       serial, paginas e consumiveis aparecendo.
[ ] 4. Clicar em "Historico" -> grafico aparece (mesmo que com 1
       ponto so, na primeira leitura).
[ ] 5. Desligar/desconectar a impressora de teste da rede -> proximo
       ciclo do worker -> confere que ela aparece em "offline" na
       tela e (se a Central de Alertas estiver configurada pra esse
       tipo) dispara aviso.

ROLLBACK
--------------------------------------------------------------------
docker compose stop portal-impressoras-worker && docker compose rm -f portal-impressoras-worker
(o card de Impressoras continua habilitado, so para de coletar dado novo - reverter inventario.php separadamente se precisar esconder o card de novo)

====================================================================
 FIM - INVENTARIO IMPRESSORAS
====================================================================
```

- [ ] **Step 3: Commit**

```bash
git add inventario.php wpp/README.md
git commit -m "feat: inventario - habilita card de impressoras + runbook de deploy"
```

---

## Depois deste plano

- Alertas configuráveis por tipo de impressora (hoje o limiar de toner é fixo em 10% via `$p['limiar']`, mas não há tela pra mudar isso — se precisar, vira uma config na Central de Alertas igual `dude_categoria_config`).
- Firmware não vem de um OID separado universal — hoje fica `null`; se precisar de verdade, teria que ser parseado do texto de `sysDescr`, que varia por marca (fica pra quando houver um caso real que precise disso).
- Consumo real da funcionalidade em produção deve confirmar se a comunidade SNMP `public` funciona nas impressoras do parque, ou se precisa de senha customizada por loja/marca — o campo já existe (`comunidade` por impressora), só falta a configuração real de cada uma.
