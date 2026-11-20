# Monitoramento de Reconhecimento Facial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement robust monitoring for the Face Recognition application (coletor `FaceEventsIntelbras`) via webhook payload ingestion, database storage, Central de Alertas integration, and configuration UI in Portal GLPI, mirroring the Sólides API pattern.

**Architecture:** A standalone library `reconhecimento_lib.php` handles database tables (`portal_reconhecimento_saude`), token management via `portal_wpp_config`, and pure alert rule evaluation. `webhook_reconhecimento.php` ingests secure webhook POST requests with token authentication. `reconhecimento_config.php` provides administrative UI. `alertas_tipos.php` registers the new check and down alert types.

**Tech Stack:** PHP (native), MySQL (InnoDB with JSON support), Bootstrap 5, JavaScript (Vanilla).

**Spec:** `docs/superpowers/specs/2026-11-20-reconhecimento-facial-monitor-design.md`

## Global Constraints
- PHP version compatibility with existing portal files.
- Token validation must use `hash_equals()`.
- Database table uses InnoDB with UTF8mb4 charset.
- No direct commit to main; follow local branch workflow (`feat/orcamento-projeto` or stack branch).

## Review Focus
1. **Invalid Token:** POST request with missing or incorrect token must return HTTP 401.
2. **Malformed JSON / Non-Array Payload:** Must return HTTP 400.
3. **Missing Diagnostic (Sem Contato):** If no diagnostic arrives within the configured minutes threshold, `reconhecimento_ocorrencias_sem_contato` returns an alert.
4. **Failing Checks in Payload:** Individual check failures in `checagens[]` generate specific alerts with appropriate error text.
5. **Database Upsert Consistency:** Re-delivering the same origin replaces the previous diagnostic without creating duplicate rows.

---

### Task 1: Create `reconhecimento_lib.php`

**Files:**
- Create: `reconhecimento_lib.php`
- Test: `wpp/tests/test_reconhecimento_lib.php`

**Interfaces:**
- Produces: `reconhecimento_webhook_token()`, `reconhecimento_gerar_webhook_token()`, `reconhecimento_registrar_payload()`, `reconhecimento_ultimo_diagnostico()`, `reconhecimento_ocorrencias_checagem()`, `reconhecimento_ocorrencias_sem_contato()`, `alerta_check_reconhecimento_checagem()`, `alerta_check_reconhecimento_sem_contato()`, `alerta_render_reconhecimento()`

- [ ] **Step 1: Write unit test `wpp/tests/test_reconhecimento_lib.php`**

```php
<?php
require_once __DIR__ . '/../agenda/db.php';
require_once __DIR__ . '/../reconhecimento_lib.php';

global $pdo;
echo "Testando reconhecimento_lib...\n";

// Test registrar payload & ultimo diagnostico
$payload = [
    'origem' => 'test-facial',
    'evento' => 'saude',
    'status' => 'alerta',
    'resumo' => 'Camera 1 com falha de conexao',
    'gerado_em' => date('Y-m-d H:i:s'),
    'host' => 'srv-facial-01',
    'checagens' => [
        ['id' => 'defense', 'nome' => 'Hikvision Defense', 'status' => 'falha', 'detalhe' => 'Timeout ao conectar']
    ]
];

reconhecimento_registrar_payload($pdo, $payload, 'test-facial');
$ultimo = reconhecimento_ultimo_diagnostico($pdo, 'test-facial');
assert($ultimo !== null, 'ultimo diagnostico nao pode ser null');
assert($ultimo['status'] === 'alerta', 'status deve ser alerta');

// Test ocorrencias checagem
$ocorr = reconhecimento_ocorrencias_checagem($ultimo, 15, time());
assert(count($ocorr) === 1, 'deve retornar 1 ocorrencia de checagem');
assert($ocorr[0]['chave'] === 'reconhecimento:defense', 'chave da ocorrencia incorreta');

// Test ocorrencias sem contato
$semContato = reconhecimento_ocorrencias_sem_contato($ultimo, 15, time() + 1000);
assert(count($semContato) === 1, 'deve retornar sem contato apos 15 min');

echo "Todos os testes de reconhecimento_lib passaram!\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php wpp/tests/test_reconhecimento_lib.php`
Expected: FAIL (file not found or functions undefined)

- [ ] **Step 3: Implement `reconhecimento_lib.php`**

Create `reconhecimento_lib.php` mirroring `solides_lib.php`:
- Auto-create `portal_reconhecimento_saude` table on inclusion.
- Implement token helper functions (`reconhecimento_webhook_token`, `reconhecimento_gerar_webhook_token`) using `wpp_cfg_get`/`wpp_cfg_set`.
- Implement `reconhecimento_registrar_payload`, `reconhecimento_ultimo_diagnostico`, `reconhecimento_minutos_desde`.
- Implement pure alert rule functions `reconhecimento_ocorrencias_checagem` and `reconhecimento_ocorrencias_sem_contato`.
- Implement Central de Alertas callbacks: `alerta_check_reconhecimento_checagem`, `alerta_check_reconhecimento_sem_contato`, `alerta_render_reconhecimento`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php wpp/tests/test_reconhecimento_lib.php`
Expected: "Todos os testes de reconhecimento_lib passaram!"

- [ ] **Step 5: Commit**

```bash
git add reconhecimento_lib.php wpp/tests/test_reconhecimento_lib.php
git commit -m "feat(reconhecimento): add reconhecimento_lib and unit tests"
```

---

### Task 2: Create `webhook_reconhecimento.php`

**Files:**
- Create: `webhook_reconhecimento.php`

**Interfaces:**
- Consumes: `reconhecimento_webhook_token()`, `reconhecimento_registrar_payload()` from `reconhecimento_lib.php`

- [ ] **Step 1: Implement `webhook_reconhecimento.php`**

```php
<?php
// Endpoint chamado pela aplicacao de Reconhecimento Facial com o diagnostico de saude.
// Autenticacao via token ?token=<token> validado com hash_equals.
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/agenda/db.php';
    require_once __DIR__ . '/reconhecimento_lib.php';
} catch (\Throwable $e) {
    error_log('webhook_reconhecimento: boot falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

global $pdo;
$token = (string) ($_GET['token'] ?? '');
$atual = reconhecimento_webhook_token();
if ($atual === '' || !hash_equals($atual, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'token invalido']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'json invalido']);
    exit;
}

try {
    reconhecimento_registrar_payload($pdo, $payload);
} catch (\Throwable $e) {
    error_log('webhook_reconhecimento: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true]);
```

- [ ] **Step 2: Verify syntax via php -l**

Run: `php -l webhook_reconhecimento.php`
Expected: No syntax errors detected

- [ ] **Step 3: Commit**

```bash
git add webhook_reconhecimento.php
git commit -m "feat(reconhecimento): add webhook_reconhecimento.php endpoint"
```

---

### Task 3: Create `reconhecimento_config.php` and register alert types in `alertas_tipos.php`

**Files:**
- Create: `reconhecimento_config.php`
- Modify: `alertas_tipos.php`

**Interfaces:**
- Consumes: `reconhecimento_lib.php` functions

- [ ] **Step 1: Implement `reconhecimento_config.php`**

Mirror `solides_config.php`:
- Require `auth_guard.php` and admin session checks.
- Handle AJAX actions (`status`, `gerar_token`).
- Render HTML interface with webhook URL copy helper, token generation button, and last diagnostic status viewer.

- [ ] **Step 2: Modify `alertas_tipos.php`**

Add:
`require_once __DIR__ . '/reconhecimento_lib.php';`
And register alert types `'reconhecimento_checagem'` and `'reconhecimento_sem_contato'`.

- [ ] **Step 3: Verify syntax**

Run: `php -l reconhecimento_config.php` and `php -l alertas_tipos.php`
Expected: No syntax errors detected

- [ ] **Step 4: Commit**

```bash
git add reconhecimento_config.php alertas_tipos.php
git commit -m "feat(reconhecimento): add reconhecimento_config.php and register alert types"
```
