# SPEC: Monitoramento de antenas UniFi via SSH (alertas + inventário)
**Data:** 2026-10-10  **Tipo:** FEAT  **Status:** APROVADA

## Contexto
Hoje existem duas integrações com UniFi totalmente desconectadas:
- `unifi_client.php` + `portal_unifi_controladoras` (spec
  `2026-07-30-inventario-unifi-design.md`): cliente HTTP/cookie da API
  clássica do Controller (porta 8443), usado só pra listar APs na tela
  `inventario_redes.php` (dashboard de leitura, sem cache, sem alerta).
- [[project_monitor-rede-proprio]]: motor próprio de ping/TCP
  (`monitor_lib.php`) que substituiu o Dude e já alimenta a Central de
  Alertas por grupo (`portal_monitor_grupos`/`portal_monitor_dispositivos`),
  mas nunca incluiu antenas.

O usuário avaliou usar o Controller como fonte de monitoramento contínuo
e descartou: a sessão da API clássica não é confiável pra ficar sendo
consultada em loop (diferente de carregar a página do Inventário uma
vez) — "no controller ele fecha, tem a dificuldade de autenticação".
Decisão confirmada: **SSH direto em cada antena é a fonte primária** do
polling periódico, sem depender do Controller pra isso. O usuário
decidiu ir além: o grid de APs que hoje vem do Controller em
`inventario_redes.php` sai de uso, substituído pela lista de antenas
cadastradas via SSH — junto, o cadastro de controladoras (UI) também
sai, por não ter mais consumidor (ver Fase 1). `unifi_client.php` e
`portal_unifi_controladoras` continuam no código, só sem tela.

Levantamento técnico relevante:
- Já existe 1 precedente de SSH em PHP no projeto: `db_central_status()`
  (`db_central_lib.php`), via `exec("sshpass -e ssh ...")`, sem chave
  pública, senha por variável de ambiente. Essa spec reaproveita o mesmo
  padrão (não há `ssh2.so` nem `phpseclib` disponíveis).
- **`sshpass`/cliente `ssh` não estão no `docker/Dockerfile` versionado**
  do `glpi-web`, apesar do comentário em `db_central_lib.php` afirmar que
  o container já tem — provavelmente foi instalado manualmente em
  produção, fora do que o repo reproduz. Esta spec precisa instalar isso
  explicitamente via Dockerfile, não assumir que já existe.
- O container do worker (`portal-wpp-worker`, onde `wpp/worker.php` roda
  o loop que hoje dispara a Central de Alertas) **não tem sshpass/ssh**
  — é por isso que o check de Postgres do DB Central já usa um proxy
  HTTP interno (`agenda/postgres_status.php`) em vez de chamar
  `db_central_status()` direto do worker. Esta spec segue o mesmo padrão
  em vez de instalar cliente SSH em dois containers.
- `vault_encrypt()`/`vault_decrypt()` (`vault_crypto.php`) já são usadas
  pra `portal_unifi_controladoras.senha_enc` e são reaproveitadas aqui
  sem alteração para a senha SSH.

## Objetivo
Cada antena UniFi cadastrada manualmente passa a ser verificada
periodicamente via SSH (offline/online, reinício, nº de clientes
conectados), com:
- Status visível em `inventario_redes.php` (online/offline, modelo,
  firmware atual — só leitura, sem alerta).
- 3 condições de alerta na Central de Alertas (novo grupo/tipo, ligado
  ao catálogo já usado por `sefaz_ms`/`solides_*`/`sitef_dns`): antena
  offline, reinício inesperado, excesso de clientes conectados.
- Resumo na rotina diária da Agenda (ex.: "Antena X caiu N vezes /
  ficou Y min offline ontem"), seguindo o padrão já usado pra
  backup/Postgres ([[agenda-resumo-backup-auto]]).

## Escopo
### Inclui
**Fase 1 — Cadastro + check SSH + status no Inventário**
- `docker/Dockerfile`: adicionar `openssh-client` + `sshpass` na imagem
  do `glpi-web` (não no worker — ver proxy HTTP abaixo).
- Nova tabela `portal_monitor_antenas` (cadastro manual, análoga a
  `portal_unifi_controladoras`): `id, nome, ip, ssh_usuario,
  ssh_senha_enc (nullable — herda credencial global se vazio),
  ativo, status ('online'|'offline'|'desconhecido'), modelo,
  firmware_versao, clientes_conectados, uptime_segundos,
  ultima_verificacao, criado_em`.
- Config de credencial SSH global padrão (chave/valor, mesmo mecanismo
  `wpp_cfg_get()/wpp_cfg_set()` já usado por `solides_lib.php`/
  `sitef_lib.php`): usuário + senha (vault) default para todas as
  antenas; usado quando a antena não tem override próprio. Resolve a
  incerteza "não sei, temos que ver" sobre credencial única x por
  antena sem travar a implementação.
- Config do limite global de clientes conectados (mesmo mecanismo
  key/value), editável por admin.
- `monitor_unifi_ssh_lib.php` (novo, raiz): `monitor_unifi_ssh_check(string $ip, string $usuario, string $senha): array`
  roda `sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=3 ...`
  (timeout explícito — ausente no padrão original de `db_central_lib.php`,
  necessário aqui pra não travar o ciclo quando uma antena não responde)
  chamando `mca-status` (ou equivalente — validar comando exato contra
  uma antena real durante a implementação, modelos podem variar) e
  parseia uptime/clientes/versão de firmware do JSON de saída. Devolve
  `['ok'=>bool,'uptime'=>int|null,'clientes'=>int|null,'firmware'=>string|null]`.
- Endpoint interno `antenas_unifi_status.php` (padrão
  `agenda/postgres_status.php`): roda o check SSH pra todas as antenas
  ativas e persiste em `portal_monitor_antenas`; é o que o worker chama
  via HTTP (sem precisar de sshpass no container do worker).
- `inventario_redes.php`: o grid de APs que hoje vem da API do
  Controller (por controladora, ao vivo) é **substituído** pela lista
  de antenas cadastradas manualmente via SSH — card/modal de cadastro
  (nome, IP, usuário/senha SSH opcionais — vazio = usa credencial
  global, botão "Testar" antes de salvar, mesmo padrão UX do modal de
  controladoras que está saindo) + grid com status online/offline,
  modelo, firmware atual (só leitura, sem alerta de "desatualizado" —
  decisão do usuário: "só mostra a versão").
- **Remoção do cadastro de controladoras** (card/modal
  "+ Adicionar"/engrenagem/Testar/Excluir de `portal_unifi_controladoras`
  em `inventario_redes.php`): decisão do usuário — como o grid que esse
  cadastro alimentava deixa de existir, a UI de controladoras sai da
  página. `portal_unifi_controladoras` (tabela) e `unifi_client.php`
  (arquivo) **continuam existindo no código**, só sem UI — não é
  exclusão de dado/arquivo, é remoção de uma tela sem consumidor depois
  da troca. Antes de remover, grep por outras referências a
  `abrirModalControladora`/`editarControladora`/`testarControladora`/
  `unifi_listar_aps`/`unifi_login` fora de `inventario_redes.php`
  (nenhuma esperada, mas confirmar — mesmo cuidado de
  [[incident-alertas-whatsapp-quebrados-pos-deploy-1004]] com código
  removido sem grep completo).

**Fase 2 — Alertas na Central**
- `monitor_antenas_lib.php` (novo): 3 funções de check pro catálogo de
  `alertas_tipos.php`, lendo o estado já persistido em
  `portal_monitor_antenas` pelo endpoint da Fase 1 (não faz SSH direto
  — reaproveita o dado já coletado):
  - `antena_offline`: dispara se `status='offline'`; sem janela de
    silêncio (decisão do usuário: antenas ficam ligadas 24/7, qualquer
    queda deve notificar).
  - `antena_reinicio`: compara `uptime_segundos` da leitura atual com a
    anterior; se o uptime atual for menor (antena reiniciou), dispara.
    Sem janela de silêncio, pelo mesmo motivo acima.
  - `antena_clientes_excesso`: dispara se `clientes_conectados` >
    limite global configurado (Fase 1).
- Entradas no catálogo de `alertas_tipos.php` (ícone/cor a definir na
  implementação, seguindo o padrão de `sitef_dns`/`solides_*`).
- Seguir [[feedback_mutar-whatsapp-antes-de-testar]]: os 3 tipos nascem
  com `notif_whatsapp=0`; só ligar depois de confirmar que não dispara
  falso positivo.

**Fase 3 — Resumo na Agenda**
- Extensão da rotina diária 7h (mesmo bloco de
  [[agenda-resumo-backup-auto]]): por antena, nº de quedas e minutos
  offline no dia anterior, a partir do histórico de ocorrências da
  Central de Alertas (não precisa de tabela nova).

### Exclui
- Apagar `unifi_client.php` ou a tabela `portal_unifi_controladoras` —
  só a UI de cadastro/grid sai de `inventario_redes.php`; arquivo e
  tabela continuam no código (ver Fase 1), sem uso por enquanto.
- Descoberta automática de antenas via Controller — cadastro é 100%
  manual (decisão do usuário).
- Alerta de "firmware desatualizado" — via SSH a antena só informa a
  versão que tem, não há "mais recente" sem consultar a nuvem Ubiquiti
  ou o Controller; fica só como campo informativo. Pode voltar como
  spec separada se o usuário decidir fonte pra isso no futuro.
- Janela de silêncio/horário pros alertas de reinício e offline.
- Autenticação SSH por chave pública — segue o padrão já usado
  (senha via `sshpass`), trocar pra chave é uma melhoria futura, não
  bloqueante.
- Qualquer verificação de banda, RSSI ou outros dados de rádio da
  antena — fora do que foi pedido (3 condições listadas acima).
- Alterar `wpp/worker.php` pra ganhar cliente SSH — resolvido via proxy
  HTTP (endpoint novo em `glpi-web`), não instalação de ssh no worker.

## Comportamento Esperado
- Antena responde SSH normalmente: `status='online'`, card do
  Inventário mostra modelo/firmware/clientes atuais; nenhuma ocorrência
  na Central.
- Antena para de responder SSH: `status='offline'` na próxima
  verificação; ocorrência `antena_offline` criada (notificação do
  portal + WhatsApp, se `notif_whatsapp=1`); card do Inventário mostra
  "offline" com horário da última resposta.
- Antena volta a responder depois de offline: ocorrência resolvida
  (✅), card volta a "online".
- Uptime da antena cai entre duas leituras (reiniciou mesmo respondendo
  normalmente): ocorrência `antena_reinicio` criada, mesmo se o status
  nunca virou "offline" (reinício pode ser rápido o bastante pra não
  ser pego como queda).
- Clientes conectados numa antena passam do limite global configurado:
  ocorrência `antena_clientes_excesso` criada; volta a fechar quando
  cair abaixo do limite.
- Rotina diária da Agenda (7h): resumo por antena de quedas/minutos
  offline do dia anterior, junto do resumo de backup já existente.

## Critérios de Aceite
- [ ] `docker/Dockerfile` do `glpi-web` com `openssh-client`+`sshpass`
  instalados; `docker exec glpi-web which sshpass ssh` confirma.
- [ ] Cadastro de antena em `inventario_redes.php` (modal): salvar sem
  credencial própria usa a credencial global; "Testar" bloqueia o save
  se a conexão SSH falhar.
- [ ] Card/modal de controladoras (+ grid de APs via Controller) não
  aparece mais em `inventario_redes.php`; grep confirma que nenhuma
  outra página referencia `abrirModalControladora`/`unifi_listar_aps`/
  `unifi_login` fora do arquivo (que continua existindo, só sem uso).
- [ ] `monitor_unifi_ssh_check()` com antena real respondendo: devolve
  `ok=true` + uptime/clientes/firmware não nulos.
- [ ] `monitor_unifi_ssh_check()` com IP inválido/antena desligada:
  devolve `ok=false` dentro do timeout configurado (não trava o
  endpoint acima de ~5s por antena).
- [ ] Endpoint `antenas_unifi_status.php` chamado pelo worker atualiza
  `portal_monitor_antenas` pra todas as antenas ativas numa única
  chamada HTTP.
- [ ] Antena marcada `offline` gera 1 ocorrência `antena_offline`;
  volta a `online` resolve a ocorrência.
- [ ] Uptime atual < uptime da leitura anterior gera 1 ocorrência
  `antena_reinicio` (teste com mock, sem precisar reiniciar antena
  real).
- [ ] `clientes_conectados` > limite configurado gera 1 ocorrência
  `antena_clientes_excesso`; abaixo do limite não gera.
- [ ] Os 3 tipos aparecem em "Configurar Alertas" com `notif_whatsapp=0`
  por padrão.
- [ ] Resumo da Agenda (Fase 3) lista quedas/minutos offline por antena
  do dia anterior, sem quebrar o resumo de backup existente.
- [ ] `php -l` limpo no container pra todos os arquivos novos; testes
  novos em `wpp/tests/` passam (arquivo mexido, não a suíte geral —
  [[feedback_suite-testes-producao]]).
- [ ] Pós-deploy: `notif_whatsapp=0` confirmado ANTES de qualquer teste
  real com antena de produção.

## Riscos e Dependências
- **Comando SSH exato não validado ainda:** `mca-status` é o candidato
  mais provável pra firmware UniFi atual, mas pode variar por
  modelo/versão — validar contra uma antena real na implementação antes
  de fechar o parsing do JSON. Se não existir, alternativa é `info`
  (formato mais antigo, texto solto em vez de JSON).
- **Timeout/paralelismo:** `exec()`/`sshpass` é bloqueante por natureza
  (sem o paralelismo que `monitor_ping_lote()` tem via `proc_open`); com
  N antenas, o endpoint `antenas_unifi_status.php` soma N×timeout no
  pior caso. Se o número de antenas crescer, pode precisar paralelizar
  (múltiplos `proc_open` símultaneos, mesmo padrão do ping) — não
  bloqueante pra v1, mas registrar como ponto de atenção.
- **Credencial SSH ainda não padronizada nas antenas** (confirmado pelo
  usuário: "preciso habilitar/padronizar antes") — a Fase 1 cadastra
  campos pra credencial, mas a antena em si pode precisar de
  configuração manual (habilitar SSH, definir senha) antes do primeiro
  teste real. Não bloqueia a implementação do código, bloqueia o teste
  em produção.
- **`sshpass`/`ssh` no `glpi-web` de produção hoje é instalação manual,
  não reproduzível pelo Dockerfile versionado** — ao subir a imagem
  nova (com a instalação explícita), confirmar que não há conflito/
  dependência de versão com o que já está rodando manualmente.
- **Reinício "silencioso" (antena nunca reporta offline, só uptime
  menor):** depende de duas leituras consecutivas bem-sucedidas pra
  comparar uptime — se a antena reiniciar exatamente entre duas
  verificações E voltar rápido, o alerta ainda pega pela queda de
  uptime; mas se duas leituras seguidas falharem por outro motivo
  (rede instável) antes de uma leitura "boa" depois do reinício, pode
  perder a comparação — aceito como limitação de v1.
