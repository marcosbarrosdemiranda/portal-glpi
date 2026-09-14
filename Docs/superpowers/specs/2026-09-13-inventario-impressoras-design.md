# Inventário — Impressoras (SNMP)

**Data:** 2026-09-13
**Status:** Aprovado (brainstorming), aguardando plano de implementação

## Contexto

O módulo Inventário (`inventario.php`) tem um card "Impressoras" desabilitado ("Em breve") desde sempre — nenhum código por trás. O parque é majoritariamente Brother/HP/Konica, a maioria acessível por IP. O usuário quer: dados técnicos (modelo, serial, firmware, status), consumíveis (toner/drum/cilindro) e histórico de impressão (volume ao longo do tempo).

## Decisões travadas (brainstorming 2026-09-13)

| # | Tema | Decisão |
|---|---|---|
| 1 | Protocolo | **SNMP** (Printer-MIB, RFC 3805) — padronizado entre marcas, é como a maioria das ferramentas de monitoramento de impressora funciona. O container `glpi-web` não tem extensão SNMP hoje — precisa mudar o `docker/Dockerfile` e reconstruir só esse serviço. |
| 2 | Cadastro | **Manual**, mesmo padrão de `portal_unifi_controladoras` (IP + apelido + loja, tela própria) — sem auto-discovery de rede. |
| 3 | Histórico | SNMP só dá o **contador total de páginas** (não quem imprimiu o quê — isso exigiria integrar com o spooler do Windows, fora de escopo). Serve pra volume/tendência (gráfico por dia/semana/mês), não log de trabalhos individuais. |
| 4 | Alertas | **Integra com a Central de Alertas já existente** (mesmo catálogo do Dude/backup/SEFAZ): impressora offline e toner baixo viram ocorrência, notificam WhatsApp igual o resto. |
| 5 | Frequência de coleta | **15-30 min** — página/toner não muda rápido, intervalo menor só gera carga à toa. |
| 6 | Isolamento de infra | O host roda outros projetos não relacionados (`checklist-gmais-*`, `ponto-gmais-*`, etc., compose separado). O rebuild do `glpi-web` não pode afetá-los — não usar `docker compose down`, só rebuild + recreate do serviço `glpi-web` (mesmo padrão já usado hoje pro Evolution API). |

## Componentes

### `docker/Dockerfile` (estende)

Acrescenta ao `apt-get install`: `libsnmp-dev`. Acrescenta ao `docker-php-ext-install`: `snmp`. Rebuild só do serviço `glpi-web` (`docker compose build glpi-web && docker compose up -d glpi-web`) — confirmar antes e depois que os outros containers do host (principalmente `checklist-gmais-*`) continuam de pé.

### `impressoras_lib.php` (novo) — mesmo padrão de `dude_lib.php`/`backup_lib.php`

Funções puras, cria as tabelas ao incluir, sem HTML/sessão.

```php
function impressora_cadastrar(PDO $pdo, string $ip, string $apelido, string $loja, string $comunidade = 'public'): int
function impressora_listar(PDO $pdo): array
function impressora_editar(PDO $pdo, int $id, string $ip, string $apelido, string $loja, string $comunidade): void
function impressora_excluir(PDO $pdo, int $id): void

// SNMP puro — sem tocar banco, devolve array ou lança em caso de timeout/erro (chamador decide o que fazer)
function impressora_snmp_consultar(string $ip, string $comunidade, int $timeoutMs = 2500): array
// -> ['online' => bool, 'modelo' => ?string, 'serial' => ?string, 'firmware' => ?string,
//     'paginas_total' => ?int, 'consumiveis' => [['nome'=>'Toner Preto','nivel'=>45,'max'=>100], ...]]

function impressora_status_salvar(PDO $pdo, int $impressoraId, array $consulta): void
function impressora_status_atual(PDO $pdo, int $impressoraId): ?array
function impressora_historico_paginas(PDO $pdo, int $impressoraId, int $dias = 90): array // pra montar o gráfico
```

OIDs (Printer-MIB, `1.3.6.1.2.1.43`, padrão RFC 3805 — mesmo caminho em qualquer marca compatível):
- `1.3.6.1.2.1.1.1.0` — sysDescr (modelo/firmware, texto livre por marca)
- `1.3.6.1.2.1.43.5.1.1.17.1` — prtGeneralSerialNumber
- `1.3.6.1.2.1.43.10.2.1.4.1.1` — prtMarkerLifeCount (contador total de páginas)
- `1.3.6.1.2.1.43.11.1.1.6.1.*` — prtMarkerSuppliesDescription (walk — 1 linha por consumível: toner K/C/M/Y, drum, coletor de resíduo)
- `1.3.6.1.2.1.43.11.1.1.9.1.*` — prtMarkerSuppliesLevel (nível atual)
- `1.3.6.1.2.1.43.11.1.1.8.1.*` — prtMarkerSuppliesMaxCapacity (capacidade máxima — nível é `-2` quando o modelo não reporta percentual, tratar como "sem dado", não erro)

Sem resposta dentro do timeout = `online => false`, resto null — mesmo espírito do timeout de link do Dude.

### `impressoras_worker.php` (novo) + container `portal-impressoras-worker`

Mesmo padrão do `portal-wpp-worker`: `while true; do php impressoras_worker.php; sleep 1200; done` (20 min, dentro da janela 15-30 decidida). Para cada impressora ativa: `impressora_snmp_consultar()` → `impressora_status_salvar()` (grava snapshot atual + acrescenta 1 linha no histórico se o contador de páginas mudou desde a última leitura — evita histórico com linha idêntica repetida). Nunca lança — 1 impressora falhando não pode derrubar o loop nem impedir a consulta das outras.

### Tabelas novas

```sql
CREATE TABLE IF NOT EXISTS portal_impressoras (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    apelido      VARCHAR(120) NOT NULL,
    loja         VARCHAR(120) NOT NULL DEFAULT '',
    comunidade   VARCHAR(60)  NOT NULL DEFAULT 'public',
    ativo        TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_impressoras_status (
    impressora_id   INT PRIMARY KEY,
    online          TINYINT(1) NOT NULL,
    modelo          VARCHAR(255) NULL,
    serial          VARCHAR(120) NULL,
    firmware        VARCHAR(120) NULL,
    paginas_total   INT NULL,
    consumiveis_json MEDIUMTEXT NULL,
    atualizado_em   DATETIME NOT NULL,
    FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_impressoras_historico (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    impressora_id INT NOT NULL,
    paginas_total INT NOT NULL,
    registrado_em DATETIME NOT NULL,
    KEY (impressora_id, registrado_em),
    FOREIGN KEY (impressora_id) REFERENCES portal_impressoras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Central de Alertas — 2 tipos novos no catálogo (`alertas_tipos.php`, mesmo padrão de `alerta_check_dude_device`)

```php
function alerta_check_impressora_offline(PDO $pdo, array $p): array
// portal_impressoras_status.online = 0 (impressora ativa, sem resposta SNMP no último poll)

function alerta_check_impressora_toner_baixo(PDO $pdo, array $p): array
// consumiveis_json com algum item onde nivel/max < limiar (%p['limiar'] ?? 10)
```

### `inventario_impressoras.php` (novo) — mesmo padrão visual de `inventario_redes.php`

- Lista de impressoras (apelido, loja, status online/offline, modelo, contador de páginas, nível de cada consumível com barra de progresso).
- CRUD de cadastro (IP, apelido, loja, comunidade) — só admin/técnico, mesma checagem de `$is_admin` de `inventario_redes.php`.
- Clique numa impressora → gráfico de histórico de páginas (linha, por dia, últimos 30/90 dias) — reaproveita Chart.js se já estiver em uso em algum lugar do portal, senão biblioteca leve equivalente.
- Habilita o card em `inventario.php` (tira o `disabled`/`Em breve`, linka pra essa página).

## Testes

`wpp/tests/test_impressoras_lib.php` (mesmo harness `t_ok`/`t_eq`, `wpp/tests/run.php`):
- CRUD de `portal_impressoras`
- `impressora_snmp_consultar`: como SNMP real depende de rede/hardware físico, os testes de parsing usam um payload SNMP fake/mockado (separar "parsear resposta SNMP" de "fazer a query de rede" em 2 funções pra poder testar o parsing sem rede) — ex.: `impressora_snmp_parsear(array $respostaBruta): array` testável sem tocar rede.
- `impressora_status_salvar`: grava snapshot; só acrescenta linha no histórico quando o contador mudou.
- `alerta_check_impressora_offline` / `alerta_check_impressora_toner_baixo`: mesmos moldes dos testes de `alerta_check_dude_device`.

Teste de integração real (1 impressora física respondendo SNMP de verdade) fica pra verificação manual no deploy, não no CI — mesmo espírito do "Spike 0" das etapas do WhatsApp.

## Ordem de entrega

| Etapa | Entrega |
|---|---|
| 1 | Dockerfile + rebuild do `glpi-web` (confirmar SNMP funcionando + outros containers do host intactos) |
| 2 | `impressoras_lib.php` (CRUD + parsing SNMP testável) + tabelas |
| 3 | `impressoras_worker.php` + container `portal-impressoras-worker` |
| 4 | `inventario_impressoras.php` (tela) + habilita o card |
| 5 | Integração com a Central de Alertas (2 tipos novos) |

Cada etapa testável e "cold-start-safe" isoladamente, mesmo padrão das fases do WhatsApp — etapa 1 sozinha já é verificável (SNMP funciona) antes de qualquer código de negócio.

## Fora de escopo

- Histórico por usuário/trabalho de impressão (exigiria integração com spooler do Windows).
- Auto-discovery de impressoras na rede.
- Configuração remota da impressora (só leitura, nunca escrita via SNMP).
- Impressoras não compatíveis com Printer-MIB (raras, mas existem modelos muito antigos/genéricos) — ficam com "sem dados", não travam o resto.
