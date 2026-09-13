# Central de Alertas — monitoramento de backups (back-gmais)

**Data:** 2026-09-12
**Status:** Aprovado (2026-09-12)

## Contexto

O usuário roda o **back-gmais** (repo próprio no GitHub, Go) em 2 servidores de
backup. Cada política de backup lá dentro já sabe mandar um **webhook JSON**
por resultado de job (sucesso/aviso/erro) — ver `internal/notifier/notifier.go`
e `internal/engine/runner.go` do back-gmais:

```json
{"subject": "[BackupGMAIS] <política>: <status>", "message": "Política: ...\nStatus: ...\nModo: ...\nJob: ...\nInício: ...\nErro: ...", "timestamp": "...", "source": "back-gmais"}
```

O payload **não identifica o servidor de origem** (sem hostname/IP). A Central
de Alertas do portal já tem um motor genérico e testado (Etapa 1+2, rodando em
produção desde 2026-09-08): `alertas_catalogo()` define tipos (check + render),
`portal_alertas_config` liga/desliga e configura cada tipo, e
`gat_alertas_tipo()` (worker WhatsApp) manda 🔔 nova / ✅ resolvida / ⏰
lembrete pro grupo "Alertas", tudo por ocorrência, sem blast retroativo.

**Decisões já confirmadas com o usuário:**
- Identificação da máquina: **URL de webhook própria por máquina** (token),
  cadastrada num admin novo — sem mexer no código Go do back-gmais.
- Quer **os dois** tipos de alerta: erro explícito de job E silêncio (máquina
  cadastrada que para de mandar qualquer sinal).
- Rede: os 2 servidores de backup alcançam o portal (mesma LAN/alcance).
- Host do webhook: **IP direto** `192.168.1.198:7412` — `ti.grupogmais.com` é
  alias que só resolve dentro do próprio servidor do portal, os servidores de
  backup não o enxergam.

## Restrição inegociável

**Nada do que já funciona pode parar de funcionar.** Este trabalho é **só
aditivo**:
- Não editar `alerta_check_sem_inventario`, `alerta_check_disco_cheio`, nem
  seus renders.
- Não editar `gat_alertas`, `gat_alertas_tipo`, `gat_msg_alerta_*` (o motor já
  é genérico o bastante pra um tipo novo só entrar no catálogo).
- `portal_alertas_config` não muda de schema — os campos `ativo`,
  `notif_whatsapp`, `lembrete_min` já servem pros tipos novos.
- Toda mudança em arquivo compartilhado (`alertas_tipos.php`) é
  **acréscimo puro** (novo `require`, novas entradas no catálogo) — sem tocar
  nas entradas existentes.
- Deploy incremental com `php -l` + suíte de testes (`wpp/tests/run.php`)
  verde antes e depois, igual ao padrão já usado no fix do card Rádios e no
  merge da Etapa 2.

## Componentes novos

### 1. `backup_lib.php` (novo, paralelo a `alertas_lib.php`)

Funções puras de leitura, sem HTML:
```php
function backup_maquinas_cadastradas(PDO $pdo): array
function backup_falhas_abertas(PDO $pdo): array
// 1 linha por (maquina, política) cuja ÚLTIMA execução recebida foi erro
function backup_maquinas_silenciosas(PDO $pdo, int $horasPadrao): array
// máquinas ativas cujo ultimo_contato é NULL ou mais velho que
// (silencio_horas da máquina, ou $horasPadrao se não configurado)
```

### 2. Tabelas novas

```sql
CREATE TABLE portal_backup_maquinas (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(80) NOT NULL,
    token          VARCHAR(40) NOT NULL UNIQUE,   -- vai na URL do webhook
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    silencio_horas INT DEFAULT NULL,               -- NULL = usa o default do tipo 'backup_silencio'
    ultimo_contato DATETIME NULL,
    ultima_politica VARCHAR(120) DEFAULT NULL,
    ultimo_status  ENUM('success','warning','error') DEFAULT NULL,
    criado_em      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE portal_backup_execucoes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    maquina_id  INT NOT NULL,
    politica    VARCHAR(120) NOT NULL,
    status      ENUM('success','warning','error') NOT NULL,
    mensagem    TEXT,                              -- corpo cru do webhook, pra depurar
    recebido_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (maquina_id) REFERENCES portal_backup_maquinas(id) ON DELETE CASCADE,
    INDEX (maquina_id, politica, recebido_em)
);
```
"Falha aberta" = a última linha de `portal_backup_execucoes` pra aquele par
`(maquina_id, politica)` tem `status='error'`. Assim que chegar um
sucesso/aviso pra esse mesmo par, some da lista — o motor genérico já manda
✅ sozinho, sem precisar de coluna "resolvido".

### 3. `webhook_backup.php` (novo, raiz do portal — sem sessão/login)

Segue o padrão já usado em `wpp/webhook.php` (responde 200 sempre, boot em
try/catch próprio, nunca deixa exceção escapar, rejeita silenciosamente
token inválido):
- `POST /webhook_backup.php?m=<token>`
- Valida `token` contra `portal_backup_maquinas.ativo=1`; inválido → 200 vazio
  (não entrega pista a quem estiver testando URLs).
- Faz `json_decode` do body; extrai `Política:` e `Status:` da chave
  `message` (regex simples por linha — é texto fixo gerado pelo
  `runner.go` do back-gmais, não JSON aninhado).
- Grava em `portal_backup_execucoes` + atualiza `ultimo_contato` /
  `ultimo_status` / `ultima_politica` em `portal_backup_maquinas`.

### 4. Catálogo (`alertas_tipos.php` — só acréscimo)

```php
require_once __DIR__ . '/backup_lib.php';   // topo, junto dos outros requires

// dentro de alertas_catalogo(), 2 entradas novas:
'backup_erro' => [
    'nome' => 'Falha de backup', 'params' => [], // sem parâmetro configurável
    'check' => 'alerta_check_backup_erro', 'render' => 'alerta_render_backup_erro',
    'icone' => 'bi-hdd-network-fill', 'cor' => 'danger',
],
'backup_silencio' => [
    'nome' => 'Backup sem contato',
    'params' => ['horas' => ['label'=>'Horas sem contato', 'default'=>26, 'min'=>2, 'max'=>168]],
    'check' => 'alerta_check_backup_silencio', 'render' => 'alerta_render_backup_silencio',
    'icone' => 'bi-wifi-off', 'cor' => 'warning',
],
```
`chave` de cada ocorrência: `'backup_erro:<maquina>:<politica>'` e
`'backup_silencio:<maquina>'` — mesmo esquema estável dos tipos existentes.

### 5. Admin de máquinas — `backup_maquinas.php` (novo, tela própria)

Link a partir de `alertas.php` (perto do "⚙ Configurar alertas"). CRUD
simples: nome, ativo, horas de silêncio (opcional). Ao criar, gera `token`
(32 hex, `random_bytes`) e mostra a **URL pronta pra colar** no
`config.yaml` de cada servidor:
```
http://192.168.1.198:7412/glpi2/portal-glpi/webhook_backup.php?m=<token>
```
(HTTP, não HTTPS — ver risco abaixo.) Tela lista último contato/status de
cada máquina (visual rápido de "tá viva ou não").

## Painel (`alertas.php`)

Nada muda na tela em si — ela já itera `alertas_catalogo()` genericamente
(confirmar isso lendo `alertas.php` na fase de plano; se hoje as 2 seções
ainda estiverem hard-coded em vez de vir do loop do catálogo, essa é uma
correção a fazer ali, sem mexer no conteúdo das 2 seções existentes).

## WhatsApp

Nenhuma mudança em `wpp/gatilhos.php` — o motor já é genérico. Os 2 tipos
novos só precisam estar **ativos** e com **notif_whatsapp** ligado em
`alertas_config.php` (tela que já existe, sem mudança de schema).

## Riscos / a validar no plano

1. **TLS — CORRIGIDO em 2026-09-13, era o oposto do previsto.** O nginx do
   `:7412` só escuta `ssl` (sem fallback HTTP) — `http://` dá **400** direto
   ("plain HTTP request sent to HTTPS port"), confirmado no back-gmais real
   ("Enviar teste" → `webhook: unexpected status 400`). E o certificado
   **não é autoassinado** — é Let's Encrypt válido pra `ti.grupogmais.com`.
   O `--no-ssl-check` do GLPI Agent é por causa do **hostname não bater**
   quando se conecta pela URL com IP (cert é só pra `ti.grupogmais.com`,
   não pra `192.168.1.198`), não por desconfiança do CA. Fix: usar
   **`https://ti.grupogmais.com:7412/...`** (não IP) e, no servidor de
   backup, adicionar uma linha no hosts (`C:\Windows\System32\drivers\etc\hosts`):
   `192.168.1.198   ti.grupogmais.com` — resolve o nome sem depender de DNS
   real, e a verificação TLS passa porque a URL e o certificado batem.
   `backup_maquinas.php` já gera a URL certa (`BACKUP_WEBHOOK_BASE`).
2. **Config nos 2 servidores de backup**: fora do escopo deste código — o
   usuário edita o `config.yaml` de cada um manualmente (webhook.enabled=true,
   url=a gerada) e ajusta `NotifyOn` da política pra incluir pelo menos
   `error` (e idealmente `success`, pra alimentar o "último contato" e não
   depender só de erro pra saber que a máquina tá viva).
3. **`portal_backup_execucoes` cresce sem limite** — considerar poda simples
   (manter últimos N dias) no plano, mesmo padrão de `pruneLocalLogs` que já
   existe noutros lugares do projeto.

## Fora de escopo (por agora)

- Abrir chamado automático no GLPI pra falha de backup (`abre_chamado` já
  existe na config genérica, mas está intocado/sem lógica em produção).
- Editar o back-gmais (Go) — tudo é aditivo do lado do portal.
- Autenticação forte no webhook além do token na URL (aceitável pra tráfego
  interno de LAN; reforçar se algum dia isso sair pra internet).
