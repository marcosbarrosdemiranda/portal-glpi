# Integração WhatsApp do Portal — Fase 1 (Infra + Conexão) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Subir a Evolution API no stack do portal e ter uma tela onde o TI pareia a linha de WhatsApp (QR) e cadastra os grupos de Alertas e Chamados — sem nenhum envio automático ainda.

**Architecture:** Container `evolution-api` novo no `docker-compose.yml`, usando o MariaDB existente (`glpi-db`, database `evolution`). Um diretório `wpp/` no portal com o cliente REST da Evolution (`wpp/evo_api.php`, funções puras). Uma página `config_whatsapp.php` (padrão das outras telas do portal: PHP + PDO + AJAX `?action=`) com abas Conexão e Grupos. Card novo na seção Configuração do dashboard, atrás de uma permissão de perfil nova.

**Tech Stack:** PHP 8.2 (sem framework), PDO/MariaDB 10.4, Docker Compose, Evolution API v2 (`evoapicloud/evolution-api`, Baileys), Bootstrap 5 + bootstrap-icons (CDN), cURL.

**Spec:** `Docs/superpowers/specs/2026-09-07-whatsapp-portal-design.md`

## Global Constraints

- Container `evolution-api`: imagem `evoapicloud/evolution-api:latest`, **somente na rede `glpi-net`**, **sem `ports:`** (nunca exposto pra fora).
- Banco da Evolution: `DATABASE_PROVIDER=mysql`, `DATABASE_CONNECTION_URI=mysql://root:root_password@glpi-db:3306/evolution`. Fallback se as migrations não subirem no MariaDB 10.4: container `evolution-db` (`mariadb:11`) dedicado, ainda sem Postgres.
- Instância única: nome `portal_ti`. Settings obrigatórios na criação: `syncFullHistory=false`, `groupsIgnore=true`, `readMessages=false`, `readStatus=false`, `alwaysOnline=false`.
- **Fase 1 não configura webhook de mensagem** (`MESSAGES_UPSERT`). Nada de chatbot, nada de polling, nada de envio.
- `wpp/config.php` é gitignored; `wpp/config.example.php` é versionado.
- Arquivos `wpp/*.php` que falam com a Evolution: sem HTML, sem `session_start`, retornam array, **nunca lançam exceção** (padrão de `github_client.php` / `agenda/glpi_api.php`).
- Toda página `.php` de tela começa com `require_once __DIR__ . '/auth_guard.php'`, checa `$_SESSION['autenticado']`, e redireciona `self-service` pro dashboard (copiar de `manutencao.php`).
- Tabelas próprias: prefixo `portal_wpp_`, criadas com `CREATE TABLE IF NOT EXISTS` no topo da página que as usa. Sem sistema de migrations.
- Commits: Conventional Commits em inglês (`feat:`, `fix:`, `chore:`, `docs:`). Comentários no código em português.
- Trailer de todo commit:
  ```
  Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TeiLgTAYjLg3dtuSq78Bo8
  ```
- Branch de trabalho: `infra/migracao-docker-glpi` (NÃO `main`). Commitar direto nesse branch.
- Ambiente: Windows + PowerShell; o servidor de produção é `192.168.1.198` (Docker), acesso via `ssh glpi-server`, stack em `C:\docker\glpi-portal\`.

## File Structure

| Arquivo | Responsabilidade | Ação |
|---|---|---|
| `docker/docker-compose.yml` | + serviço `evolution-api` (e talvez `evolution-db`) | Modify |
| `docker/.env.example` | documenta `EVOLUTION_API_KEY` | Create |
| `.gitignore` | + `wpp/config.php`, `docker/.env` | Modify |
| `wpp/config.example.php` | template: `EVO_URL`, `EVO_API_KEY`, `EVO_INSTANCE` | Create |
| `wpp/config.php` | valores reais (gitignored) | Create (local/servidor, não commitado) |
| `wpp/db.php` | `$pdo` + `CREATE TABLE IF NOT EXISTS portal_wpp_config` | Create |
| `wpp/evo_api.php` | cliente REST da Evolution: `evo_request`, `evo_status`, `evo_qr`, `evo_groups`, `evo_logout`, `evo_ensure_instance` | Create |
| `wpp/tests/assert.php` | mini-harness de teste (`t_ok`, `t_eq`, `t_report`) | Create |
| `wpp/tests/test_evo_api.php` | testes das funções puras de `evo_api.php` | Create |
| `wpp/tests/run.php` | roda todos os `test_*.php` da pasta | Create |
| `config_whatsapp.php` | tela: abas Conexão + Grupos; AJAX `?action=status\|qr\|groups\|logout\|save_groups` | Create |
| `config_notificacoes.php` | hub de sub-cards de notificação (só WhatsApp por enquanto) | Create |
| `dashboard.php` | + card "Notificações" na seção Configuração | Modify |
| `perfis.php` | + `notificacoes_config` no catálogo `$SECOES['Configuração']` | Modify |
| `wpp/README.md` | runbook: criar instância, parear, cadastrar grupos, fallback do banco | Create |

---

## Task 1: Container Evolution API no stack

**Files:**
- Modify: `docker/docker-compose.yml`
- Create: `docker/.env.example`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: nada.
- Produces: Evolution API respondendo em `http://evolution-api:8080` dentro da rede `glpi-net`. API key em `docker/.env` como `EVOLUTION_API_KEY`. Database `evolution` no `glpi-db` (ou container `evolution-db`).

- [ ] **Step 1: Criar `docker/.env.example`**

```env
# Chave da Evolution API (gera uma aleatória, ex: openssl rand -hex 24).
# Copie este arquivo para docker/.env e preencha. docker/.env é gitignored.
EVOLUTION_API_KEY=troque-por-uma-chave-aleatoria
```

- [ ] **Step 2: Adicionar `docker/.env` e `wpp/config.php` ao `.gitignore`**

No bloco "Credenciais e configurações sensíveis" do `.gitignore`, adicionar:

```
docker/.env
wpp/config.php
```

- [ ] **Step 3: Criar o database `evolution` no MariaDB**

O container `glpi-db` é `mariadb:10.4`. A Evolution não cria o database sozinha, só as tabelas. Adicionar um init script one-shot. No `docker/docker-compose.yml`, no serviço `glpi-db`, montar um init:

```yaml
  glpi-db:
    # ... (mantém o resto)
    volumes:
      - C:\docker\glpi-portal\mysql-data:/var/lib/mysql
      - ./init-evolution-db.sql:/docker-entrypoint-initdb.d/init-evolution-db.sql:ro
```

`docker/init-evolution-db.sql` (Create):
```sql
-- Só roda em bancos novos (initdb). Como o mysql-data já existe em produção,
-- em prod o database será criado à mão (ver wpp/README.md).
CREATE DATABASE IF NOT EXISTS evolution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> Nota pro executor: `docker-entrypoint-initdb.d` só roda quando o volume do
> MySQL está vazio. Em produção o volume já tem dados, então o database
> `evolution` precisa ser criado manualmente uma vez (está no README, Task 5).
> O init script serve pra ambientes limpos / novos devs.

- [ ] **Step 4: Adicionar o serviço `evolution-api` ao `docker/docker-compose.yml`**

Depois do serviço `glpi-cron`, antes de `networks:`:

```yaml
  evolution-api:
    image: evoapicloud/evolution-api:latest
    container_name: evolution-api
    restart: unless-stopped
    depends_on:
      - glpi-db
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
      # Log enxuto
      LOG_LEVEL: "ERROR"
      LOG_BAILEYS: "error"
    volumes:
      - C:\docker\glpi-portal\evolution-instances:/evolution/instances
    networks:
      - glpi-net
```

Sem `ports:` — a Evolution só é acessível de dentro de `glpi-net` (pelo `glpi-web`).

- [ ] **Step 5: Subir e verificar as migrations**

Localmente (ou no servidor):
```bash
cd docker
# criar docker/.env com EVOLUTION_API_KEY
docker compose up -d glpi-db
docker exec glpi-db mariadb -uroot -proot_password -e "CREATE DATABASE IF NOT EXISTS evolution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker compose up -d evolution-api
docker compose logs -f evolution-api
```

Esperado: log termina com a Evolution ouvindo na porta 8080, **sem erro de Prisma/migration**.

- [ ] **Step 6: Ponto de decisão — MariaDB 10.4 funcionou?**

Testar a API:
```bash
docker exec glpi-web curl -s -H "apikey: <EVOLUTION_API_KEY>" http://evolution-api:8080/instance/fetchInstances
```
Esperado: JSON (lista vazia `[]` ou array). Se responder 200 com JSON → **10.4 OK, seguir**.

**SE** os logs mostrarem erro de migration/Prisma incompatível com MariaDB 10.4:
adicionar um container dedicado e trocar a URI:

```yaml
  evolution-db:
    image: mariadb:11
    container_name: evolution-db
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: evolution_pw
      MARIADB_DATABASE: evolution
    volumes:
      - C:\docker\glpi-portal\evolution-db-data:/var/lib/mysql
    networks:
      - glpi-net
```
E em `evolution-api`: `DATABASE_CONNECTION_URI: "mysql://root:evolution_pw@evolution-db:3306/evolution"`, `depends_on: [evolution-db]`. Repetir Step 5.

- [ ] **Step 7: Commit**

```bash
git add docker/docker-compose.yml docker/.env.example docker/init-evolution-db.sql .gitignore
git commit -m "feat: evolution-api container no stack do portal (MariaDB)"
```

---

## Task 2: Fundação do módulo `wpp/` — config + cliente REST da Evolution

**Files:**
- Create: `wpp/config.example.php`
- Create: `wpp/config.php` (local, não commitado)
- Create: `wpp/db.php`
- Create: `wpp/evo_api.php`
- Create: `wpp/tests/assert.php`
- Create: `wpp/tests/test_evo_api.php`
- Create: `wpp/tests/run.php`

**Interfaces:**
- Consumes: Evolution rodando em `http://evolution-api:8080` (Task 1). `$pdo` de `agenda/db.php`.
- Produces:
  - Constantes `EVO_URL` (string), `EVO_API_KEY` (string), `EVO_INSTANCE` (string).
  - `wpp/db.php` → garante tabela `portal_wpp_config (chave VARCHAR(64) PK, valor TEXT)` e expõe `wpp_cfg_get(string $chave, ?string $default=null): ?string` e `wpp_cfg_set(string $chave, string $valor): void` usando `$pdo`.
  - `wpp/evo_api.php`:
    - `evo_url(string $path): string` — junta `EVO_URL` + `$path` normalizando barras.
    - `evo_request(string $method, string $path, ?array $json=null, int $timeout=20): array` — cURL com header `apikey`; retorna `['ok'=>bool, 'status'=>int, 'data'=>mixed, 'erro'=>?string]`; nunca lança.
    - `evo_status(): array` — GET `/instance/connectionState/{EVO_INSTANCE}`; retorna `['ok'=>bool, 'estado'=>string]` onde estado ∈ `open|connecting|close|desconhecido`.
    - `evo_qr(): array` — GET `/instance/connect/{EVO_INSTANCE}`; retorna `['ok'=>bool, 'base64'=>?string, 'erro'=>?string]`.
    - `evo_groups(): array` — GET `/group/fetchAllGroups/{EVO_INSTANCE}?getParticipants=false`; retorna `['ok'=>bool, 'grupos'=>[['jid'=>string,'nome'=>string,'tamanho'=>int], ...]]`.
    - `evo_logout(): array` — DELETE `/instance/logout/{EVO_INSTANCE}`; retorna `['ok'=>bool]`.
    - `evo_ensure_instance(): array` — GET `/instance/fetchInstances`; se `EVO_INSTANCE` não existe, POST `/instance/create` com o payload de settings obrigatório (ver Global Constraints); retorna `['ok'=>bool, 'criada'=>bool, 'erro'=>?string]`.
  - `wpp/tests/assert.php` → `t_ok(bool $cond, string $msg)`, `t_eq($a, $b, string $msg)`, `t_report(): int` (retorna nº de falhas).

- [ ] **Step 1: Criar `wpp/config.example.php`**

```php
<?php
// Copie para wpp/config.php e preencha. wpp/config.php é gitignored.

// URL interna da Evolution API (nome do container na rede glpi-net).
define('EVO_URL', 'http://evolution-api:8080');

// = EVOLUTION_API_KEY do docker/.env
define('EVO_API_KEY', 'troque-por-uma-chave-aleatoria');

// Nome da instância única do portal.
define('EVO_INSTANCE', 'portal_ti');
```

- [ ] **Step 2: Criar `wpp/config.php` local (cópia do example) pra rodar os testes**

Copiar `wpp/config.example.php` → `wpp/config.php`. Em dev sem Docker, ajustar `EVO_URL` pra `http://localhost:8080` se estiver testando fora da rede. (Não commitar.)

- [ ] **Step 3: Criar `wpp/db.php`**

```php
<?php
// Acesso ao banco pro módulo WhatsApp. Reusa o $pdo do portal.
require_once __DIR__ . '/../agenda/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_wpp_config (
    chave VARCHAR(64) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function wpp_cfg_get(string $chave, ?string $default = null): ?string {
    global $pdo;
    $st = $pdo->prepare("SELECT valor FROM portal_wpp_config WHERE chave = ?");
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function wpp_cfg_set(string $chave, string $valor): void {
    global $pdo;
    $st = $pdo->prepare(
        "INSERT INTO portal_wpp_config (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    $st->execute([$chave, $valor]);
}
```

- [ ] **Step 4: Criar o mini-harness `wpp/tests/assert.php`**

```php
<?php
// Mini-harness: o portal não tem PHPUnit. Roda com `php wpp/tests/run.php`.
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_pass'] = 0;

function t_ok(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['__t_pass']++; echo "  ok  $msg\n"; }
    else       { $GLOBALS['__t_fail']++; echo "  FAIL $msg\n"; }
}

function t_eq($a, $b, string $msg): void {
    t_ok($a === $b, $msg . "  (esperado " . var_export($b, true) . ", veio " . var_export($a, true) . ")");
}

function t_report(): int {
    echo "\n{$GLOBALS['__t_pass']} ok, {$GLOBALS['__t_fail']} falhas\n";
    return $GLOBALS['__t_fail'];
}
```

- [ ] **Step 5: Criar `wpp/tests/run.php`**

```php
<?php
require __DIR__ . '/assert.php';
foreach (glob(__DIR__ . '/test_*.php') as $f) {
    echo "\n== " . basename($f) . " ==\n";
    require $f;
}
exit(t_report() === 0 ? 0 : 1);
```

- [ ] **Step 6: Escrever o teste que falha — `wpp/tests/test_evo_api.php`**

```php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../evo_api.php';

// evo_url normaliza barras
t_eq(evo_url('/instance/connect/x'), rtrim(EVO_URL, '/') . '/instance/connect/x', 'evo_url junta sem barra dupla');
t_eq(evo_url('instance/x'),          rtrim(EVO_URL, '/') . '/instance/x',          'evo_url adiciona a barra que falta');

// evo_request nunca lança, mesmo com host inválido
$r = evo_request('GET', '/nada', null, 2);
t_ok(is_array($r) && array_key_exists('ok', $r) && array_key_exists('status', $r), 'evo_request sempre retorna array com ok/status');

// evo_status normaliza o estado
t_ok(is_array(evo_status()) && array_key_exists('estado', evo_status()), 'evo_status retorna estado');
```

- [ ] **Step 7: Rodar e ver falhar**

Run: `php wpp/tests/run.php`
Expected: FAIL — `evo_api.php` não existe (`require` quebra) ou funções indefinidas.

- [ ] **Step 8: Implementar `wpp/evo_api.php`**

```php
<?php
// Cliente REST da Evolution API. Sem HTML, sem sessão. Nunca lança.
require_once __DIR__ . '/config.php';

function evo_url(string $path): string {
    return rtrim(EVO_URL, '/') . '/' . ltrim($path, '/');
}

function evo_request(string $method, string $path, ?array $json = null, int $timeout = 20): array {
    $ch = curl_init(evo_url($path));
    $headers = ['apikey: ' . EVO_API_KEY];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'erro' => "cURL: $err"];
    }
    $data = json_decode((string) $body, true);
    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => $data,
        'erro'   => ($status >= 400) ? (is_array($data) ? json_encode($data) : (string) $body) : null,
    ];
}

function evo_status(): array {
    $r = evo_request('GET', '/instance/connectionState/' . EVO_INSTANCE, null, 10);
    $estado = $r['data']['instance']['state'] ?? ($r['data']['state'] ?? 'desconhecido');
    return ['ok' => $r['ok'], 'estado' => is_string($estado) ? $estado : 'desconhecido'];
}

function evo_qr(): array {
    $r = evo_request('GET', '/instance/connect/' . EVO_INSTANCE, null, 20);
    $b64 = $r['data']['base64'] ?? ($r['data']['qrcode']['base64'] ?? null);
    return ['ok' => $r['ok'] && $b64 !== null, 'base64' => $b64, 'erro' => $r['erro']];
}

function evo_groups(): array {
    $r = evo_request('GET', '/group/fetchAllGroups/' . EVO_INSTANCE . '?getParticipants=false', null, 30);
    $lista = [];
    foreach ((is_array($r['data']) ? $r['data'] : []) as $g) {
        if (!isset($g['id'])) continue;
        $lista[] = [
            'jid'      => $g['id'],
            'nome'     => $g['subject'] ?? '(sem nome)',
            'tamanho'  => (int) ($g['size'] ?? 0),
        ];
    }
    usort($lista, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return ['ok' => $r['ok'], 'grupos' => $lista, 'erro' => $r['erro']];
}

function evo_logout(): array {
    $r = evo_request('DELETE', '/instance/logout/' . EVO_INSTANCE, null, 15);
    return ['ok' => $r['ok'], 'erro' => $r['erro']];
}

function evo_ensure_instance(): array {
    $r = evo_request('GET', '/instance/fetchInstances', null, 15);
    if (!$r['ok']) return ['ok' => false, 'criada' => false, 'erro' => $r['erro'] ?? 'falha ao listar instâncias'];

    foreach ((is_array($r['data']) ? $r['data'] : []) as $inst) {
        $nome = $inst['name'] ?? ($inst['instance']['instanceName'] ?? null);
        if ($nome === EVO_INSTANCE) return ['ok' => true, 'criada' => false, 'erro' => null];
    }

    $c = evo_request('POST', '/instance/create', [
        'instanceName'    => EVO_INSTANCE,
        'qrcode'          => true,
        'integration'     => 'WHATSAPP-BAILEYS',
        'groupsIgnore'    => true,
        'alwaysOnline'    => false,
        'readMessages'    => false,
        'readStatus'      => false,
        'syncFullHistory' => false,
    ], 30);
    return ['ok' => $c['ok'], 'criada' => $c['ok'], 'erro' => $c['erro']];
}
```

- [ ] **Step 9: Rodar os testes e ver passar**

Run: `php wpp/tests/run.php`
Expected: PASS — `evo_url` normaliza; `evo_request`/`evo_status` retornam array mesmo sem Evolution acessível.

- [ ] **Step 10: (integração, opcional se a Evolution estiver de pé) checar `evo_ensure_instance`**

Run: `docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php` e um script ad-hoc chamando `evo_ensure_instance()` — esperado `['ok'=>true, 'criada'=>true]` na 1ª vez, `criada=>false` depois.

- [ ] **Step 11: Commit**

```bash
git add wpp/config.example.php wpp/db.php wpp/evo_api.php wpp/tests/
git commit -m "feat: wpp/ - cliente REST da Evolution API + mini-harness de teste"
```

---

## Task 3: Tela `config_whatsapp.php` — abas Conexão e Grupos

**Files:**
- Create: `config_whatsapp.php`

**Interfaces:**
- Consumes: `wpp/db.php` (`wpp_cfg_get`/`wpp_cfg_set`), `wpp/evo_api.php` (`evo_status`, `evo_qr`, `evo_groups`, `evo_logout`, `evo_ensure_instance`), `auth_guard.php`.
- Produces: chaves em `portal_wpp_config`: `grupo_alertas_jid`, `grupo_chamados_jid`. Endpoints AJAX (mesma página, `?action=`):
  - `?action=status` → `{ok, estado}`
  - `?action=ensure` → `{ok, criada, erro}` (cria a instância se faltar)
  - `?action=qr` → `{ok, base64, erro}`
  - `?action=logout` → `{ok}`
  - `?action=groups` → `{ok, grupos:[{jid,nome,tamanho}], erro}`
  - `?action=save_groups` (POST `alertas_jid`, `chamados_jid`) → `{ok}`

- [ ] **Step 1: Cabeçalho + guarda de sessão (copiar de `manutencao.php`)**

```php
<?php
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['autenticado'])) { header('Location: auth.php'); exit; }
if (($_SESSION['perfil'] ?? '') === 'self-service') { header('Location: dashboard.php'); exit; }

require_once __DIR__ . '/wpp/db.php';
require_once __DIR__ . '/wpp/evo_api.php';

$H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
```

- [ ] **Step 2: Bloco de handlers AJAX (antes de qualquer HTML)**

```php
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    switch ($action) {
        case 'status':
            echo json_encode(evo_status());
            break;
        case 'ensure':
            echo json_encode(evo_ensure_instance());
            break;
        case 'qr':
            echo json_encode(evo_qr());
            break;
        case 'logout':
            echo json_encode(evo_logout());
            break;
        case 'groups':
            echo json_encode(evo_groups());
            break;
        case 'save_groups':
            $a = trim($_POST['alertas_jid'] ?? '');
            $c = trim($_POST['chamados_jid'] ?? '');
            // aceita só JID de grupo (@g.us) ou vazio
            foreach (['alertas' => $a, 'chamados' => $c] as $k => $v) {
                if ($v !== '' && !str_ends_with($v, '@g.us')) {
                    echo json_encode(['ok' => false, 'erro' => "JID de $k inválido"]);
                    exit;
                }
            }
            wpp_cfg_set('grupo_alertas_jid', $a);
            wpp_cfg_set('grupo_chamados_jid', $c);
            echo json_encode(['ok' => true]);
            break;
        default:
            echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    }
    exit;
}

$alertas_jid  = wpp_cfg_get('grupo_alertas_jid', '');
$chamados_jid = wpp_cfg_get('grupo_chamados_jid', '');
```

- [ ] **Step 3: HTML — topbar + abas (Bootstrap 5, padrão do `alertas.php`)**

Estrutura mínima (o executor pode espelhar o visual de `alertas.php`):
- `<head>`: Bootstrap 5.3.3 + bootstrap-icons 1.11.3 via CDN (mesmas URLs de `alertas.php`).
- Topbar com link "← Configuração" (`config_notificacoes.php`) e "Dashboard".
- `<ul class="nav nav-tabs">`: **Conexão** | **Grupos**.
- Aba **Conexão**:
  - `<div id="wpp-status">` — pinta o estado (`open` = verde "Conectado", `connecting` = amarelo, `close`/outro = vermelho "Desconectado").
  - Botão **Conectar / mostrar QR** → chama `?action=ensure` depois `?action=qr`, mostra `<img src="data:image/png;base64,...">`. Faz polling de `?action=status` a cada 3s enquanto o QR está na tela; quando virar `open`, esconde o QR e mostra "Conectado".
  - Botão **Desconectar** (só quando `open`) → `?action=logout` com `confirm()`.
- Aba **Grupos**:
  - Botão **Recarregar lista** → `?action=groups`.
  - Aviso "Conecte primeiro na aba Conexão" se `estado !== 'open'`.
  - Dois `<select>`: "Grupo de Alertas" e "Grupo de Chamados", populados com os grupos (`option value=jid`), pré-selecionando `$alertas_jid` / `$chamados_jid`.
  - Botão **Salvar** → POST `?action=save_groups`.

- [ ] **Step 4: JS inline — sem framework, `fetch()`**

Implementar as funções: `carregarStatus()`, `conectar()`, `pollStatus()`, `desconectar()`, `carregarGrupos()`, `salvarGrupos()`. Toast/alert simples de sucesso/erro (pode usar `alert()` como as telas mais simples do portal, ou um `<div>` de feedback).

- [ ] **Step 5: Verificação manual**

1. `docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/config_whatsapp.php` → "No syntax errors".
2. Abrir `https://192.168.1.198:7412/glpi2/portal-glpi/config_whatsapp.php` logado.
3. Aba Conexão → **Conectar** → QR aparece. Escanear com a linha do TI. Status vira "Conectado" em até ~10s.
4. Aba Grupos → **Recarregar** → grupos do WhatsApp aparecem. Escolher Alertas e Chamados → **Salvar** → recarregar a página, seleção persiste.
5. Conferir no banco: `docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "SELECT * FROM portal_wpp_config;"`.

- [ ] **Step 6: Commit**

```bash
git add config_whatsapp.php
git commit -m "feat: tela config_whatsapp - conexao (QR) e cadastro de grupos"
```

---

## Task 4: Hub de Notificações + card no dashboard + permissão de perfil

**Files:**
- Create: `config_notificacoes.php`
- Modify: `perfis.php` (catálogo `$SECOES['Configuração']`)
- Modify: `dashboard.php` (seção Configuração)

**Interfaces:**
- Consumes: `pode_ver('notificacoes_config', $perfil_cards)` de `dashboard.php`.
- Produces: página `config_notificacoes.php` acessível a quem tem a permissão; card "Notificações" na seção Configuração linkando pra ela; sub-card "WhatsApp" linkando pra `config_whatsapp.php`.

- [ ] **Step 1: Criar `config_notificacoes.php`**

Cabeçalho igual ao `config_whatsapp.php` (auth_guard + checagem de sessão + redirect self-service). Sem AJAX. Corpo: topbar ("← Dashboard"), título "Notificações", um grid de cards (padrão `.dash-card` de `dashboard.php` — copiar o CSS necessário ou linkar `assets/` se houver um comum). Só um card por enquanto:

```html
<a href="config_whatsapp.php" class="dash-card">
  <div class="card-icon"><i class="bi bi-whatsapp"></i></div>
  <h5>WhatsApp</h5>
  <p>Conexão da linha do TI e grupos de Alertas e Chamados.</p>
</a>
```

Adicionar também a checagem de permissão no topo (defensiva, além do card do dashboard):
```php
require_once __DIR__ . '/agenda/db.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
$cards = null;
try {
    $st = $pdo->prepare("SELECT pp.cards FROM portal_perfil_usuarios pu JOIN portal_perfis pp ON pp.id=pu.perfil_id WHERE pu.user_id=?");
    $st->execute([$uid]);
    $row = $st->fetch();
    if ($row) $cards = json_decode($row['cards'] ?? '{}', true) ?: [];
} catch (Exception $e) {}
if ($cards !== null && !isset($cards['notificacoes_config'])) { header('Location: dashboard.php'); exit; }
```

- [ ] **Step 2: Registrar a permissão em `perfis.php`**

No array `$SECOES`, dentro de `'Configuração' => [ ... ]`, adicionar:

```php
        'notificacoes_config' => ['label' => 'Notificações',  'icon' => 'bi-bell-fill',  'css' => 'card-logs'],
```

- [ ] **Step 3: Adicionar o card em `dashboard.php`**

Na condição do `section-label` "Configuração" (hoje em `dashboard.php:470`), adicionar `|| pode_ver('notificacoes_config',$perfil_cards)`.

Depois do bloco do card "Manutenção" (antes do `<?php endif; ?>` que fecha a seção Configuração, ~linha 528), adicionar:

```php
  <?php if (pode_ver('notificacoes_config', $perfil_cards)): ?>
  <a href="config_notificacoes.php" class="dash-card card-logs">
    <div class="card-icon"><i class="bi bi-bell-fill"></i></div>
    <h5>Notificações</h5>
    <p>WhatsApp — conexão da linha do TI, grupos de Alertas e Chamados.</p>
  </a>
  <?php endif; ?>
```

- [ ] **Step 4: Verificação manual**

1. `php -l` nos 3 arquivos.
2. Logar com um usuário **sem** perfil restrito (vê tudo) → card "Notificações" aparece na seção Configuração → clica → hub → clica "WhatsApp" → `config_whatsapp.php`.
3. Em `perfis.php`, criar/editar um perfil, marcar "Notificações", atribuir a um usuário de teste → logar como ele → card aparece. Desmarcar → card some e acessar `config_notificacoes.php` direto redireciona pro dashboard.

- [ ] **Step 5: Commit**

```bash
git add config_notificacoes.php perfis.php dashboard.php
git commit -m "feat: card Notificacoes na Configuracao + hub + permissao de perfil"
```

---

## Task 5: Runbook de pareamento + verificação ponta-a-ponta

**Files:**
- Create: `wpp/README.md`

**Interfaces:**
- Consumes: tudo das Tasks 1–4.
- Produces: documento com o passo a passo de produção; confirmação de que a Fase 1 está de pé.

- [ ] **Step 1: Escrever `wpp/README.md`**

Conteúdo (seções):
1. **O que é a Fase 1** — Evolution API + tela de conexão. Sem envio automático.
2. **Deploy no servidor** (`ssh glpi-server`, stack em `C:\docker\glpi-portal\`):
   - criar `docker\.env` com `EVOLUTION_API_KEY` (gerar: `openssl rand -hex 24`)
   - criar `glpi2`... não: criar o database: `docker exec glpi-db mariadb -uroot -proot_password -e "CREATE DATABASE IF NOT EXISTS evolution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
   - criar `portal-glpi\wpp\config.php` a partir do `config.example.php` com a mesma `EVO_API_KEY`
   - `docker compose up -d evolution-api`
   - `docker compose logs evolution-api` — sem erro de migration
3. **Se o MariaDB 10.4 não aceitar** — subir o `evolution-db` (`mariadb:11`), trocar a URI, `up -d` de novo. (passo do Task 1 Step 6)
4. **Parear a linha:**
   - abrir `config_whatsapp.php` → aba Conexão → Conectar → escanear o QR com o WhatsApp da **linha dedicada do TI**
   - status vira "Conectado"
5. **Cadastrar os grupos:**
   - criar (no WhatsApp) os grupos "TI · Alertas" e "TI · Chamados", adicionar a linha do TI
   - aba Grupos → Recarregar → selecionar cada um → Salvar
6. **Verificação final (checklist):**
   - [ ] `evo_status()` retorna `open`
   - [ ] `portal_wpp_config` tem `grupo_alertas_jid` e `grupo_chamados_jid` preenchidos
   - [ ] Evolution **não** tem webhook de mensagem configurado (Fase 1)
   - [ ] nenhuma mensagem foi enviada a ninguém durante todo o processo
7. **O que NÃO fazer ainda** — não configurar webhook, não criar o worker; isso é Fase 2/3.

- [ ] **Step 2: Executar o runbook em produção (ou em ambiente de staging Docker) e marcar o checklist**

- [ ] **Step 3: Commit**

```bash
git add wpp/README.md
git commit -m "docs: runbook da Fase 1 da integracao WhatsApp"
```

---

## Self-Review

**1. Spec coverage (Fase 1):**
- Evolution container + MariaDB + fallback → Task 1 ✅
- Instância `portal_ti` com settings de segurança → Task 2 (`evo_ensure_instance`) ✅
- `wpp/config` + `wpp/evo_api` (status/qr/groups/logout) → Task 2 ✅
- `config_whatsapp.php` abas Conexão + Grupos → Task 3 ✅
- `config_notificacoes.php` hub + card no dashboard + permissão `notificacoes_config` → Task 4 ✅
- Parear linha + cadastrar 2 grupos → Task 5 ✅
- "Sem envio automático, sem webhook de mensagem" → constraint global + Task 5 checklist ✅
- Cold-start/backfill → **não se aplica à Fase 1** (não há worker nem webhook); a regra dura entra na Fase 2/3.

**2. Placeholder scan:** sem "TBD"/"handle edge cases" soltos. Os blocos de código estão completos. O HTML da Task 3 Step 3 é descrito por estrutura (não código literal) porque é layout Bootstrap espelhando `alertas.php` — aceitável, mas o executor deve produzir o arquivo completo, não um esqueleto.

**3. Type consistency:**
- `evo_status()` retorna `['ok','estado']` — usado assim na Task 3 (`estado`) e Task 5. ✅
- `evo_qr()` retorna `['ok','base64','erro']` — Task 3 usa `base64`. ✅
- `evo_groups()` retorna `['ok','grupos'=>[['jid','nome','tamanho']],'erro']` — Task 3 popula selects com `jid`/`nome`. ✅
- `wpp_cfg_get/set` assinaturas idênticas entre Task 2 (def) e Task 3/4 (uso). ✅
- Chaves `grupo_alertas_jid` / `grupo_chamados_jid` — mesmas em Task 3 Step 2 e Task 5. ✅
- Permissão `notificacoes_config` — mesma string em `perfis.php`, `dashboard.php`, `config_notificacoes.php` (Task 4). ✅

## Execution Handoff

Plan complete and saved to `Docs/superpowers/plans/2026-09-07-whatsapp-portal-fase1.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
