# Spec: Monitoramento de Reconhecimento Facial via Webhook (Padrão Sólides)

## 1. Contexto e Objetivo
Implementar no Portal GLPI o monitoramento da aplicação de **Reconhecimento Facial** (coletor `FaceEventsIntelbras`), espelhando exatamente o padrão arquitetural já utilizado na API Sólides (`solides_lib.php`, `webhook_solides.php`, `solides_config.php` e `alertas_tipos.php`).

## 2. Componentes a Criar / Modificar

### 2.1. Banco de Dados e Biblioteca (`reconhecimento_lib.php`)
- Criação automática da tabela `portal_reconhecimento_saude`:
  - `origem` VARCHAR(40) PRIMARY KEY (padrão `'reconhecimento'`)
  - `status` ENUM('ok','alerta','falha') NOT NULL
  - `resumo` VARCHAR(1000) NOT NULL DEFAULT ''
  - `checagens` JSON
  - `evento` VARCHAR(20) NOT NULL DEFAULT 'saude'
  - `recebido_em` DATETIME NOT NULL
- Funções de token:
  - `reconhecimento_webhook_token()` (armazena em `portal_wpp_config` via `wpp_cfg_get`/`wpp_cfg_set`)
  - `reconhecimento_gerar_webhook_token()`
- Funções de diagnóstico:
  - `reconhecimento_registrar_payload(PDO $pdo, array $payload, string $origem = 'reconhecimento')`
  - `reconhecimento_ultimo_diagnostico(PDO $pdo, string $origem = 'reconhecimento')`
- Regras de Alerta (puras):
  - `reconhecimento_ocorrencias_checagem(?array $linha, int $minSemContato, int $agoraTs)`
  - `reconhecimento_ocorrencias_sem_contato(?array $linha, int $minSemContato, int $agoraTs)`
- Integração com Central de Alertas:
  - `alerta_check_reconhecimento_checagem(PDO $pdo, array $p)`
  - `alerta_check_reconhecimento_sem_contato(PDO $pdo, array $p)`
  - `alerta_render_reconhecimento(array $ocorr, string $tipo = '')`

### 2.2. Endpoint Receptor (`webhook_reconhecimento.php`)
- Endpoint HTTP público acessado via POST pelo coletor de reconhecimento facial:
  - `https://ti.grupogmais.com:7412/glpi2/portal-glpi/webhook_reconhecimento.php?token=<token>`
- Validação do token com `hash_equals()`. Retorna `401` se inválido.
- Lê o payload JSON enviado pelo coletor e chama `reconhecimento_registrar_payload()`.

### 2.3. Tela de Configuração (`reconhecimento_config.php`)
- Interface cirúrgica no padrão do portal (`auth_guard.php`, Bootstrap 5, tema padrão):
  - Exibe a URL completa do webhook para colar no `.env` do coletor de reconhecimento facial.
  - Botão para gerar novo token.
  - Exibição do último diagnóstico recebido (status, resumo, horário).
  - Link de retorno para Configurar Alertas e Início.

### 2.4. Registro na Central de Alertas (`alertas_tipos.php`)
- Adicionar os tipos:
  - `reconhecimento_checagem`: "Reconhecimento Facial com problema"
  - `reconhecimento_sem_contato`: "Reconhecimento Facial sem contato"
- Incluir `require_once __DIR__ . '/reconhecimento_lib.php';` em `alertas_tipos.php`.

## 3. Critérios de Aceite
1. `webhook_reconhecimento.php` recebe POST com token correto, grava em `portal_reconhecimento_saude` e retorna `{ "ok": true }`.
2. Token incorreto retorna HTTP 401.
3. Central de Alertas lista os dois novos tipos e permite configurar WhatsApp / lembrete / ativo.
4. `reconhecimento_config.php` exibe corretamente a URL do webhook e o último diagnóstico.
