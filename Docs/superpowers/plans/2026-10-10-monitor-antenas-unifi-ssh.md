# Monitoramento de Antenas UniFi via SSH — Implementation Plan

**Spec base:** `Docs/superpowers/specs/2026-10-10-monitor-antenas-unifi-ssh-design.md`
— aprovada 2026-10-10. Decisão de arquitetura central: SSH direto em cada
antena é a fonte **primária** do polling (não o Controller); o grid de APs
via Controller em `inventario_redes.php` é substituído pela lista SSH, e o
cadastro de controladoras (UI) sai da página (arquivo/tabela continuam no
código, só sem tela).

**Goal:** cada antena cadastrada manualmente é verificada por SSH
(offline/online, reinício, nº de clientes); status visível em
`inventario_redes.php`; 3 tipos de alerta na Central (offline, reinício,
excesso de clientes); resumo diário na Agenda.

**Architecture:**
- Precedente seguido à risca: `db_central_lib.php` +
  `agenda/postgres_status.php` + `alerta_check_db_central()`
  (`alertas_tipos.php:590-621`) — único lugar do projeto que já faz SSH a
  partir do PHP (`exec("sshpass -e ssh ...")`) e já resolve o problema de
  "worker sem sshpass" via endpoint HTTP interno no `glpi-web`. Esta spec
  generaliza o mesmo padrão pra N antenas (em vez de 1 host fixo) e
  acrescenta persistência (necessária pra detectar reinício comparando
  uptime entre duas leituras — o padrão do DB Central é sem estado).
- `monitor_antenas_lib.php` (novo): tabela `portal_monitor_antenas`
  (cadastro + último snapshot) + `monitor_antena_ssh_check()` (1 antena) +
  `monitor_antenas_varrer()` (todas as ativas, com **auto-throttle**: só
  roda SSH de novo numa antena se `ultima_verificacao` for mais antiga que
  um intervalo mínimo — evita triplicar carga de SSH quando os 3 tipos de
  alerta da Fase 2 chamam o endpoint no mesmo ciclo do worker).
- `antenas_unifi_status.php` (novo, raiz, sem sessão — mesmo padrão
  desprotegido de `agenda/postgres_status.php`, aceito pelo mesmo motivo:
  só alcançável de dentro da rede docker interna): chama
  `monitor_antenas_varrer()` e devolve JSON.
- `alertas_tipos.php`: 3 entradas de catálogo novas, cada `check` chama o
  endpoint acima via `file_get_contents('http://glpi-web/...')` (mesmo
  padrão de `alerta_check_db_central`), não SSH direto — o worker
  continua sem precisar de `sshpass`.
- `inventario_redes.php`: a página passa a **ler o snapshot já persistido**
  em `portal_monitor_antenas` (não dispara SSH no load da página) — desvio
  consciente do comportamento atual do grid do Controller ("ao vivo sem
  cache"): N antenas × SSH síncrono por page view seria lento/frágil; o
  snapshot já é mantido fresco pelo ciclo da Central de Alertas. Mais
  consistente, inclusive, com o resto do app (PDVs/firewalls em
  `monitor_lib.php` também são lidos do último snapshot, nunca ping-ao-vivo
  no page load). **Ponto de revisão no PLAN GATE** — avisar se isso não é
  aceitável.

**Tech Stack:** PHP 8.2 + PDO/MariaDB, `exec()`+`sshpass` (sem `ssh2.so`/
`phpseclib`, nenhum disponível hoje), `vault_crypto.php` reaproveitado sem
alteração, `wpp/db.php` (`wpp_cfg_get/set`) reaproveitado pra credencial
global default + limite de clientes. Deploy por scp + `.new` + `php -l` no
container + confirmação + `Move-Item` (mesmo fluxo de sempre).

---

## Global Constraints

- **Não apagar `unifi_client.php` nem a tabela `portal_unifi_controladoras`**
  — só a UI (modal/card/grid) sai de `inventario_redes.php`.
- **Worker (`portal-wpp-worker`) não ganha cliente SSH.** Toda chamada SSH
  acontece só dentro do `glpi-web` (via `monitor_antenas_lib.php`, chamado
  só por `antenas_unifi_status.php`). O worker só consome o endpoint HTTP.
- **Timeout explícito obrigatório** em todo comando SSH novo
  (`-o ConnectTimeout=3`, ausente no padrão original de
  `db_central_lib.php`) — uma antena travada não pode travar o ciclo.
- **`notif_whatsapp=0` ao nascer** nos 3 tipos novos
  ([[feedback_mutar-whatsapp-antes-de-testar]]) — só ligar depois de
  confirmar que não dispara falso positivo.
- **Sem janela de silêncio/horário** nos 3 alertas (decisão do usuário:
  antenas ficam ligadas 24/7).
- **Sem alerta de firmware desatualizado** — só campo informativo.
- **Credencial SSH:** global default (`wpp_cfg` com senha via
  `vault_encrypt`) + override opcional por antena; nunca senha em texto
  puro fora do vault.
- **Cuidado com `run.php` em produção** — testes novos rodam isolados
  (arquivo mexido), nunca a suíte geral.

---

## File Structure

| Arquivo | Mudança | Papel |
|---|---|---|
| `docker/Dockerfile` | +2 pacotes | `openssh-client` + `sshpass` na imagem do `glpi-web` |
| `monitor_antenas_lib.php` | **novo** | tabela `portal_monitor_antenas` + CRUD + check SSH + varredura com throttle |
| `antenas_unifi_status.php` | **novo** | endpoint JSON (sem sessão) que roda a varredura, consumido pelo worker |
| `inventario_redes.php` | edição ampla | troca card/modal de controladoras + grid do Controller pela UI de antenas SSH (lê snapshot persistido) |
| `alertas_tipos.php` | +require, +3 catálogo, +3 check, +1 render | `antena_offline`, `antena_reinicio`, `antena_clientes_excesso` |
| rotina diária da Agenda (arquivo a localizar na Fase 3 — mesmo bloco de `[[agenda-resumo-backup-auto]]`) | edição | +resumo de quedas/minutos offline por antena |
| `wpp/tests/test_monitor_antenas_lib.php` | **novo** | testes com mock de SSH (sem rede real) |

---

## Tasks

### Fase 1 — Cadastro + check SSH + status no Inventário

- [ ] 1.1 `docker/Dockerfile`: adicionar `openssh-client` e `sshpass` à
  lista de `apt-get install -y` (junto de `iputils-ping`, antes do
  `docker-php-ext-configure`). Rebuild local não é possível nesta sessão
  (sem Docker local) — validar no host (`docker exec glpi-web which
  sshpass ssh`) só depois do deploy da imagem nova.
- [ ] 1.2 Criar `monitor_antenas_lib.php` (raiz):
  - `CREATE TABLE IF NOT EXISTS portal_monitor_antenas (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL, ip VARCHAR(45) NOT NULL, ssh_usuario VARCHAR(100) NULL, ssh_senha_enc TEXT NULL, ativo TINYINT(1) DEFAULT 1, status ENUM('online','offline','desconhecido') DEFAULT 'desconhecido', modelo VARCHAR(80) NULL, firmware_versao VARCHAR(40) NULL, clientes_conectados INT NULL, uptime_segundos INT NULL, ultima_verificacao DATETIME NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)` (mesmo padrão `CREATE TABLE IF NOT EXISTS` ao incluir o arquivo).
  - `monitor_antena_credencial(array $antena): array` — resolve
    `[usuario, senha]`: usa `ssh_usuario`/`ssh_senha_enc` da própria antena
    se preenchidos, senão cai no default global
    (`wpp_cfg_get('antena_ssh_usuario')`/`wpp_cfg_get('antena_ssh_senha_enc')`,
    decodificado com `vault_decrypt`).
  - `monitor_antena_ssh_check(string $ip, string $usuario, string $senha): array`
    — `putenv("SSHPASS=...")` + `exec("sshpass -e ssh -o
    StrictHostKeyChecking=no -o ConnectTimeout=3 ...")` rodando `mca-status`
    (validar comando exato contra antena real antes de fechar o parsing —
    risco já registrado na spec); parseia JSON pra
    `['ok'=>bool,'uptime'=>?int,'clientes'=>?int,'firmware'=>?string,'modelo'=>?string,'erro'=>?string]`.
  - `monitor_antenas_varrer(PDO $pdo, int $minIntervaloSeg = 20): array` —
    para cada antena `ativo=1`: se `ultima_verificacao` for mais recente
    que `$minIntervaloSeg` atrás, **reaproveita o snapshot já persistido**
    (não SSH de novo); senão roda `monitor_antena_ssh_check()`, grava
    `status/modelo/firmware_versao/clientes_conectados/uptime_segundos/ultima_verificacao`
    e devolve também `uptime_anterior` (valor antes do UPDATE, pra Fase 2
    comparar sem precisar de outra leitura).
  - CRUD de cadastro (`monitor_antena_salvar/excluir/listar`), mesmo
    contrato de `monitor_manual_criar/atualizar/excluir` (`monitor_lib.php:545-574`).
- [ ] 1.3 Criar `antenas_unifi_status.php` (raiz): `require agenda/db.php`
  + `require monitor_antenas_lib.php`; `header('Content-Type:
  application/json'); echo json_encode(monitor_antenas_varrer($pdo));`
  — mesmo formato minimalista de `agenda/postgres_status.php`.
- [ ] 1.4 `inventario_redes.php`:
  - Remover `require_once unifi_client.php` (linha 9).
  - Remover o bloco de AJAX de controladoras (linhas 44-132: `add/save`,
    `testar`, `delete`) e substituir por `antena_add/antena_save`,
    `antena_testar` (chama `monitor_antena_ssh_check` com os dados do
    formulário antes de salvar — mesma regra UX: bloqueia save se falhar),
    `antena_delete`.
  - Remover `$controladoras = $pdo->query(...)` (linha 135) e o fetch ao
    vivo (linhas 254-273: `$errosControladoras`/`$apsPorControladora`/
    `unifi_listar_aps`) — trocar por
    `$antenas = $pdo->query("SELECT * FROM portal_monitor_antenas ORDER BY nome")->fetchAll(...)`
    (leitura simples, sem SSH no load — ver Architecture acima).
  - Remover CSS/HTML de `.ctrl-*`/`.unifi-grupo-*`/`.unifi-ap-*` (linhas
    166-204, 223-316) e o modal de controladora (linhas 320-360) — refazer
    com os mesmos nomes de classe renomeados pra `.antena-*` (reaproveitar
    o visual: card com bolinha verde/vermelha, grid responsivo).
  - Refazer o JS (linhas 364-472): `abrirModalAntena()`,
    `editarAntena()`, `testarAntena()` (chama `antena_testar` antes de
    salvar), `salvarAntena()`, `excluirAntena()` — mesmo fluxo de
    `abrirModalControladora`/`editarControladora`/`testarControladora`/
    `salvarControladora`/`excluirControladora`, campos trocados por
    nome/IP/usuário SSH (opcional)/senha SSH (opcional).
  - Adicionar mini-formulário admin (fora do modal, 1x na página):
    credencial SSH global default (usuário/senha) + limite de clientes
    conectados — grava via `wpp_cfg_set` (senha com `vault_encrypt`
    antes de gravar).
  - Grid de antenas: bolinha online/offline (reaproveita `.unifi-ap-dot`
    renomeada), nome, IP, modelo, firmware, nº de clientes, "última
    verificação há Xmin".
- [ ] 1.5 Teste manual: cadastrar 1 antena real (quando a credencial SSH
  estiver padronizada — pendência do usuário), confirmar que "Testar"
  bloqueia save em caso de falha e que o grid mostra o snapshot depois de
  pelo menos 1 ciclo do worker (ou de uma chamada manual ao endpoint).
- [ ] 1.6 `php -l` no container pra todos os arquivos novos/editados desta
  fase. Deploy incremental (scp + `.new` + `Move-Item`), antes de avançar
  pra Fase 2.

### Fase 2 — Alertas na Central

- [ ] 2.1 `alertas_tipos.php`: `require_once __DIR__ .
  '/monitor_antenas_lib.php';` (junto dos outros `require_once` de lib,
  linha ~21).
- [ ] 2.2 3 funções de check (mesmo arquivo ou `monitor_antenas_lib.php`,
  decidir na implementação pelo padrão mais próximo — `solides_lib.php`
  mistura check+lib no mesmo arquivo, `sitef_lib.php` planejado também
  assim): cada uma chama
  `@file_get_contents('http://glpi-web/glpi2/portal-glpi/antenas_unifi_status.php')`
  (mesmo padrão de `alerta_check_db_central`, `alertas_tipos.php:607`),
  decodifica o JSON (array de antenas) e filtra:
  - `alerta_check_antena_offline`: antenas com `status='offline'` →
    1 ocorrência por antena (`chave = 'antena_offline:' . $id`).
  - `alerta_check_antena_reinicio`: antenas cujo `uptime_anterior` (vindo
    do JSON) é maior que o `uptime` atual → 1 ocorrência por antena
    (`chave = 'antena_reinicio:' . $id . ':' . $ultima_verificacao` —
    inclui timestamp na chave pra não ficar "presa" numa única ocorrência
    eternamente aberta a cada reinício novo).
  - `alerta_check_antena_clientes_excesso`: antenas com
    `clientes_conectados > $p['limite']` (`$p['limite']` com default lido
    de `wpp_cfg_get('antena_limite_clientes', '30')` — parâmetro do
    catálogo, editável em "Configurar Alertas" como os outros tipos).
- [ ] 2.3 1 função de render compartilhada (`alerta_render_antena`,
  mesmo padrão simples de `alerta_render_solides`/`alerta_render_db_central`:
  tabela título/detalhe/botão dispensar).
- [ ] 2.4 3 entradas no catálogo (`alertas_catalogo()`), ícone
  `bi-wifi-off`/`bi-arrow-repeat`/`bi-people-fill`, cor `danger`/`warning`/
  `warning`, `notif_whatsapp` nasce implícito em `0` (comportamento padrão
  de linha ausente em `portal_alertas_config` — confirmar, se não for o
  caso, inserir explicitamente as 3 linhas com `notif_whatsapp=0` no
  deploy, igual ao fluxo de `sitef_dns`).
- [ ] 2.5 Testes em `wpp/tests/test_monitor_antenas_lib.php`: mock de
  `monitor_antena_ssh_check` (sem rede real) simulando online→offline
  (gera ocorrência offline), uptime caindo entre duas varreduras (gera
  ocorrência reinício), clientes acima do limite (gera ocorrência),
  dentro do limite (não gera).
- [ ] 2.6 `php -l` + deploy incremental. **Imediatamente após**, confirmar
  `notif_whatsapp=0` nos 3 tipos em produção antes de qualquer teste real
  com antena de verdade ([[feedback_mutar-whatsapp-antes-de-testar]]).

### Fase 3 — Resumo na Agenda

- [ ] 3.1 Localizar o bloco da rotina diária 7h que hoje monta o resumo de
  backup ([[agenda-resumo-backup-auto]]) e entender o padrão de "resumo
  pronto, pré-preenchendo a resposta".
- [ ] 3.2 Adicionar bloco análogo pra antenas: por antena, contar
  ocorrências `antena_offline` abertas/fechadas no dia anterior
  (`portal_alertas_ocorrencias` ou equivalente) e somar minutos entre
  abertura/fechamento → "Antena X: N queda(s), Y min offline".
- [ ] 3.3 Teste manual: confirmar que o resumo de backup existente não
  quebra com a adição, e que o bloco de antenas aparece mesmo com 0
  quedas (texto "sem quedas ontem", não ausência silenciosa).
- [ ] 3.4 `php -l` + deploy incremental.

### Fase 4 — Deploy final e verificação

- [ ] 4.1 Confirmar que a imagem nova do `glpi-web` (com `sshpass`) foi
  de fato rebuildada e está rodando em produção (`docker exec glpi-web
  which sshpass ssh` — não assumir, [[fix-alertas-500-drift-producao]]
  já mostrou que isso falha silenciosamente).
- [ ] 4.2 Grep final por `abrirModalControladora|editarControladora|testarControladora|unifi_listar_aps|unifi_login|unifi_testar_login`
  fora de `inventario_redes.php`/`unifi_client.php` — confirmar que nada
  mais referencia o fluxo removido (mesmo cuidado do incidente de
  2026-10-05, [[incident-alertas-whatsapp-quebrados-pos-deploy-1004]]).
- [ ] 4.3 Commit por fase concluída, push após cada um, branch nova
  (`feat/monitor-antenas-unifi-ssh`, a partir de
  [[branch-integracao-infra-docker]] `infra/migracao-docker-glpi`, não de
  `main`).
- [ ] 4.4 Reportar pendências reais ao usuário: (a) credencial SSH ainda
  precisa ser padronizada nas antenas (bloqueante pro teste real, não pro
  código); (b) comando `mca-status` validado contra pelo menos 1 antena
  real.

---

## Rollback

- Fase 1 isolada: se o grid SSH não funcionar bem, dá pra reverter só
  `inventario_redes.php` pro commit anterior (volta o grid do Controller)
  sem afetar Fases 2/3, já que `portal_unifi_controladoras`/
  `unifi_client.php` não foram apagados.
- Fase 2 isolada: desligar os 3 tipos em "Configurar Alertas"
  (`ativo=0`) não exige rollback de código.
- Nenhuma migração destrutiva em nenhuma fase (tudo `CREATE TABLE IF NOT
  EXISTS`/aditivo) — reverter é sempre voltar o(s) arquivo(s) PHP, nunca
  precisa de `DROP`/migração reversa de schema.

---

## Status: planejado (não iniciado)
