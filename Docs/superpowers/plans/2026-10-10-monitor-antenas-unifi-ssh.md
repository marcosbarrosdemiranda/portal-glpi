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

- [x] 1.1 `docker/Dockerfile`: adicionar `openssh-client` e `sshpass` à
  lista de `apt-get install -y` (junto de `iputils-ping`, antes do
  `docker-php-ext-configure`). Rebuild local não é possível nesta sessão
  (sem Docker local) — validar no host (`docker exec glpi-web which
  sshpass ssh`) só depois do deploy da imagem nova. **Commit `57091cc`.**
  Pendente: rebuild/deploy da imagem em produção (Fase 4).
- [x] 1.2 Criar `monitor_antenas_lib.php` (raiz):
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
- [x] 1.3 Criar `antenas_unifi_status.php` (raiz): `require agenda/db.php`
  + `require monitor_antenas_lib.php`; `header('Content-Type:
  application/json'); echo json_encode(monitor_antenas_varrer($pdo));`
  — mesmo formato minimalista de `agenda/postgres_status.php`.
- [x] 1.4 `inventario_redes.php`:
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
  **Bloqueado: credencial SSH ainda não padronizada nas antenas reais —
  não bloqueia o código, só este teste.**
- [x] 1.6 `php -l` no container pra todos os arquivos novos/editados desta
  fase. Deploy incremental (scp + `.new` + `Move-Item`), antes de avançar
  pra Fase 2. **Feito — `php -l` limpo nos 3 arquivos; smoke test de
  `antenas_unifi_status.php` em produção devolveu `200 []` (tabela
  vazia); `inventario_redes.php` devolveu `302` (redirect de login,
  esperado sem sessão). Commit `275427c`.**

### Fase 2 — Alertas na Central

- [x] 2.1 `alertas_tipos.php`: `require_once __DIR__ .
  '/monitor_antenas_lib.php';` (junto dos outros `require_once` de lib,
  linha ~21).
- [x] 2.2 3 funções de check, cada uma chamando `alerta_antenas_status()`
  (fetch via `file_get_contents('http://glpi-web/glpi2/portal-glpi/antenas_unifi_status.php')`,
  mesmo padrão de `alerta_check_db_central`) e delegando pra 3 funções
  **puras** (`antena_ocorrencias_offline/reinicio/clientes_excesso` —
  extraídas assim justamente pra serem testáveis sem rede real, mesmo
  padrão de `sefaz_parsear_html_ms` puro vs. `alerta_check_sefaz_ms`
  fetch+delega):
  - `antena_ocorrencias_offline`: antenas com `status='offline'` →
    1 ocorrência por antena (`chave = 'antena_offline:' . $id`).
  - `antena_ocorrencias_reinicio`: antenas cujo `uptime_anterior` é maior
    que o `uptime` atual → 1 ocorrência por antena (`chave =
    'antena_reinicio:' . $id . ':' . $ultima_verificacao`).
  - `antena_ocorrencias_clientes_excesso`: antenas com
    `clientes_conectados > $limite` (`$limite` lido de `$p['limite']`,
    default `wpp_cfg_get('antena_limite_clientes', '30')`).
- [x] 2.3 1 função de render compartilhada (`alerta_render_antena`,
  tabela título/detalhe/botão dispensar).
- [x] 2.4 3 entradas no catálogo (`alertas_catalogo()`), ícone
  `bi-wifi-off`/`bi-arrow-repeat`/`bi-people-fill`, cor `danger`/`warning`/
  `warning`. **Confirmado:** linha ausente em `portal_alertas_config`
  default pra `notif_whatsapp => true` (não `0` como se esperava) — por
  isso as 3 linhas foram inseridas explicitamente no deploy (2.6), igual
  ao fluxo de `sitef_dns`.
- [x] 2.5 Testes em `wpp/tests/test_monitor_antenas_lib.php`: mock de
  `monitor_antena_ssh_check` via seam `$GLOBALS['__monitor_antena_ssh_fake']`
  (mesmo padrão de `$GLOBALS['__monitor_ping_fake']` em `monitor_lib.php`,
  sem rede real) cobrindo online→offline (ocorrência offline), uptime
  caindo entre duas varreduras online (ocorrência reinício), throttle
  (não roda SSH de novo dentro do intervalo), clientes acima/dentro do
  limite, e CRUD básico (salvar/excluir). **32 testes, 0 falhas**
  (`php wpp/tests/run_scoped.php wpp/tests/test_monitor_antenas_lib.php`
  em produção).
- [x] 2.6 `php -l` + deploy incremental (scp `.new` → lint → confirmação →
  `Move-Item`, confirmado via `Get-ChildItem`). **Imediatamente após**,
  inseridas as 3 linhas em `portal_alertas_config` com `ativo=1,
  notif_whatsapp=0` em produção, confirmado por SELECT antes de qualquer
  teste real com antena de verdade ([[feedback_mutar-whatsapp-antes-de-testar]]).

### Fase 3 — Resumo na Agenda

- [x] 3.1 Localizado: o resumo de backup não é montado por um cron às 7h
  — é calculado on-demand, via AJAX, quando o técnico abre o modal de
  resposta (`abrirModalResposta()` em `agenda/index.php`), condicionado a
  `titulo.includes('...')` no título do chamado recorrente. O chamado
  "Firewal, Unifi e Comunicação Lojas" (não o de Backup) já contém
  "Unifi" no título — escolhido como o lugar correto pra injetar o bloco
  de antenas (desvio da leitura literal do task, que citava o bloco de
  backup só como *padrão* a seguir, não como o chamado exato de destino).
- [x] 3.2 Implementado em `monitor_antenas_lib.php`:
  `antena_resumo_quedas_dia(PDO $pdo, string $data): array` — lê
  `portal_alertas_historico` (tipo `antena_offline`, escrita por
  `alertas_historico_registrar()`/`gat_alertas_tipo()`), empareia
  eventos `nova`→`resolvida` por `chave`, soma minutos offline por
  antena (queda ainda aberta no fim do dia soma parcial até 23:59:59);
  `antena_resumo_texto(array $resumo, string $data): string` — texto
  pronto no mesmo padrão de `backup_resumo_texto()`. Novo endpoint
  `antena_resumo_ajax.php` (raiz, autenticado via `auth_guard.php`,
  requer `alertas_tipos.php` — que já traz `monitor_antenas_lib.php`,
  evita require circular) expõe isso como JSON. `agenda/index.php`:
  bloco `#resp-antenas` renderizado quando `mostrarFirewall` é true,
  buscado via `fetch('../antena_resumo_ajax.php')` em
  `abrirModalResposta()`, e seu texto é incluído nos `blocos` enviados
  por `enviarResposta()`.
- [x] 3.3 Teste manual: bloco de backup (`#resp-firewall`) intacto, sem
  interferência — a edição foi só uma adição ao lado dele, sem tocar seu
  bloco. Caso "0 quedas" cobre por teste automatizado (ver 3.4) o texto
  "Antenas UniFi: sem quedas em DD/MM/YYYY." (nunca ausência silenciosa).
- [x] 3.4 12 novos testes em `wpp/tests/test_monitor_antenas_lib.php`
  (chaves sentinela `antena_offline:9999901`/`9999902`, isoladas de
  qualquer antena real): dia sem eventos → array vazio + texto "sem
  quedas"; antena sem linha em `portal_monitor_antenas` → nome de
  fallback `"Antena #<id>"`; 1 par nova/resolvida → 1 queda/15min; 2
  pares no mesmo dia → soma quedas e minutos; queda ainda aberta no fim
  do dia → conta 1 queda + minutos parciais; texto final cita o nome de
  fallback e pluraliza "queda"/"quedas" corretamente. `php -l` limpo +
  deploy incremental (scp `.new` → lint → confirmação → `Move-Item`,
  confirmado via `Get-ChildItem`) dos 4 arquivos
  (`monitor_antenas_lib.php`, `antena_resumo_ajax.php` novo,
  `agenda/index.php`, `wpp/tests/test_monitor_antenas_lib.php`).
  **Resultado: 44 testes, 0 falhas** (32 da Fase 2 + 12 novos,
  `php wpp/tests/run_scoped.php wpp/tests/test_monitor_antenas_lib.php`
  em produção). Confirmado por SELECT: 0 linhas residuais em
  `portal_alertas_historico`/`portal_monitor_antenas` após o teste.

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

## Status: Fase 1 implementada e deployada (1.1-1.4, 1.6); 1.5 pendente de credencial SSH real. Fase 2 implementada, testada (32/0) e deployada (2.1-2.6), com notif_whatsapp=0 confirmado em produção. Fase 3 implementada, testada (44/0) e deployada (3.1-3.4). Fase 4: 4.2 (grep de referências órfãs) já confirmado limpo; 4.1 (rebuild da imagem Docker) e 4.4 (relatório final) pendentes.
