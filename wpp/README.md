====================================================================
 INTEGRACAO WHATSAPP - FASE 1 (EVOLUTION API + PAREAMENTO)
 Portal GLPI - Runbook de deploy e pareamento
====================================================================

Deploy no servidor de producao: ssh glpi-server, stack Docker em
C:\docker\glpi-portal\. Comandos de host sao PowerShell; os comandos
"docker exec ..." rodam dentro dos containers Linux. Leia tudo antes.

O QUE E A FASE 1
--------------------------------------------------------------------
  * Sobe os containers evolution-db (MariaDB 11 dedicado) e evolution-api
    (gateway WhatsApp, so na rede interna).
  * Tela config_whatsapp.php, duas abas:
      - Conexao: parear a linha WhatsApp dedicada do TI via QR code.
      - Grupos:  escolher o grupo de Alertas e o de Chamados.
  * Instancia unica "portal_ti" (historico/sync desligados).
  * Card "Notificacoes" na Configuracao (permissao notificacoes_config).

O QUE A FASE 1 NAO FAZ: nao envia mensagem nenhuma, nao configura
webhook, nao tem worker. Isso e Fase 2/3.

DEPLOY NO SERVIDOR
--------------------------------------------------------------------
  ssh glpi-server
  cd C:\docker\glpi-portal\
[ ] 1. Gere a chave da Evolution API, numa maquina com openssl:
         openssl rand -hex 24
       Sem openssl: qualquer string aleatoria de 48 digitos hex.
[ ] 2. Crie o arquivo .env na MESMA pasta do docker-compose.yml do
       servidor - ou seja C:\docker\glpi-portal\.env (o "docker compose"
       le ".env" do diretorio de onde e chamado, nunca de subpasta).
       Modelo em docker\.env.example do repo. Conteudo:
         EVOLUTION_API_KEY=<chave-gerada>
[ ] 3. Crie wpp\config.php a partir do exemplo e ajuste:
         Copy-Item C:\docker\glpi-portal\glpi2\portal-glpi\wpp\config.example.php C:\docker\glpi-portal\glpi2\portal-glpi\wpp\config.php
       EVO_API_KEY = a MESMA chave do EVOLUTION_API_KEY do .env.
       EVO_URL e EVO_INSTANCE ja vem certos.
[ ] 4. Suba a Evolution (o database "evolution" e criado sozinho pelo
       container evolution-db):
         docker compose up -d evolution-db evolution-api
[ ] 5. Confira os logs (o evolution-api reinicia algumas vezes enquanto
       o evolution-db termina de subir - normal; aguarde ~40s):
         docker compose logs evolution-api
       Deve terminar em "start:prod" / "node dist/main", sem erro de
       Prisma/migration.
[ ] 6. Teste a conectividade de dentro do glpi-web (a imagem nao tem
       curl; use o PHP):
         docker exec glpi-web php -r "echo @file_get_contents('http://evolution-api:8080/');"
       Deve devolver um JSON "Welcome to the Evolution API...".

POR QUE UM BANCO DEDICADO (evolution-db)
--------------------------------------------------------------------
O MariaDB 10.4 do glpi-db NAO roda as migrations do Prisma da Evolution
(a migration 20240813153900_add_unique_index_for_remoted_jid... derruba
a conexao - erro P1017). MariaDB 11 aplica as 18 migrations sem
problema. Por isso o compose ja traz o servico evolution-db separado
(mariadb:11, senha root "evolution_pw", volume
C:\docker\glpi-portal\evolution-db-data). Fica isolado do banco do GLPI.
Confirmado no deploy da Fase 1 (2026-09-07).

TESTES
--------------------------------------------------------------------
[ ] O compose monta C:\docker\glpi-portal\glpi2 em /var/www/html/glpi2;
    o portal fica em .../glpi2/portal-glpi (ajuste o caminho se a
    montagem for outra):
      docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
    Espera-se terminar com "0 falhas" (hoje: "4 ok, 0 falhas").

PAREAR A LINHA DO TI
--------------------------------------------------------------------
Use um numero WhatsApp DEDICADO do TI (nunca pessoal ou compartilhado).
[ ] 1. Abra:
       https://192.168.1.198:7412/glpi2/portal-glpi/config_whatsapp.php
[ ] 2. Aba "Conexao": o status vai de "Verificando..." para "Desconectado".
[ ] 3. Clique em "Conectar / mostrar QR"; aparece o QR code.
[ ] 4. Celular da linha do TI: WhatsApp -> Aparelhos conectados ->
       Conectar um aparelho -> aponte para o QR.
[ ] 5. A tela atualiza sozinha: "Conectando..." e depois "Conectado".
       Travou? aguarde ~10s e recarregue (F5).

CADASTRAR OS 2 GRUPOS
--------------------------------------------------------------------
[ ] 1. No celular da linha do TI, crie 2 grupos e adicione a propria
       linha: "TI · Alertas" e "TI · Chamados".
[ ] 2. Aba "Grupos" -> "Recarregar lista de grupos".
[ ] 3. Escolha um grupo em "Grupo de Alertas" e outro em "Grupo de
       Chamados".
[ ] 4. Clique em "Salvar" (um so botao, salva os dois selects). Os JIDs
       vao para a tabela portal_wpp_config (chaves grupo_alertas_jid e
       grupo_chamados_jid), gravados pela propria tela.
[ ] 5. Recarregue (F5): os grupos escolhidos continuam selecionados.

VERIFICACAO FINAL (CHECKLIST)
--------------------------------------------------------------------
[ ] evolution-api de pe, sem erro de migration (docker compose logs).
[ ] Instancia portal_ti conectada (estado "open"):
      docker exec glpi-web php -r 'require "/var/www/html/glpi2/portal-glpi/wpp/evo_api.php"; echo evo_status()["estado"], "\n";'
[ ] portal_wpp_config com as duas chaves preenchidas (JID @g.us):
      docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "SELECT chave,valor FROM portal_wpp_config;"
[ ] NENHUM webhook de mensagem configurado na Evolution (isso e Fase 2).
[ ] NENHUMA mensagem enviada a ninguem durante o setup (confira as
    conversas dos 2 grupos no celular da linha do TI).
[ ] Testes passam ("0 falhas").

Nao faca ainda: webhook de mensagem, worker de envio. Fase 2/3.

FAQ
--------------------------------------------------------------------
P: O QR code nao aparece.
R: evolution-api rodando? wpp\config.php com EVO_URL
   "http://evolution-api:8080" e a chave igual a do .env do servidor?
   Recarregue a tela (F5).
P: Erro de Prisma/migration nos logs do evolution-api.
R: Confirme que a URI aponta pro evolution-db (mariadb:11), nao pro
   glpi-db. Se o database ficou sujo de uma tentativa anterior:
   docker compose stop evolution-api && docker exec evolution-db mariadb
   -uroot -pevolution_pw -e "DROP DATABASE evolution; CREATE DATABASE
   evolution;" && docker compose up -d evolution-api
P: A lista de grupos vem vazia.
R: A linha pareada precisa ser MEMBRO dos grupos. Confira no WhatsApp e
   clique de novo em "Recarregar lista de grupos".
P: Cliquei em "Salvar" e nada acontece.
R: Os grupos sao gravados no banco (portal_wpp_config) pela tela, via
   AJAX. O config.php NAO e escrito pelo app - so tem define()s. Veja a
   mensagem de feedback abaixo do botao: erro comum e JID invalido, o
   valor precisa terminar em "@g.us" (vem certo se voce escolher pelo
   select apos "Recarregar lista de grupos").
P: Como reiniciar a Fase 1 do zero?
R: 1) Aba Conexao -> "Desconectar".
   2) Zere os grupos: deixe os dois selects vazios e "Salvar", ou:
        docker exec glpi-db mariadb -uroot -proot_password glpi2 -e "DELETE FROM portal_wpp_config WHERE chave IN ('grupo_alertas_jid','grupo_chamados_jid');"
   3) Pra zerar a Evolution:
      docker compose stop evolution-api
      docker exec evolution-db mariadb -uroot -pevolution_pw -e "DROP DATABASE evolution; CREATE DATABASE evolution;"
      docker compose up -d evolution-api

====================================================================
 FIM DO RUNBOOK
====================================================================


====================================================================
 FASE 2 - WORKER DE NOTIFICACOES
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Servico portal-wpp-worker no docker-compose: um loop que roda
    "php .../wpp/worker.php" a cada 30s (while true; sleep 30).
  * Cada passada do worker.php:
      1. Confere se a instancia portal_ti esta conectada (estado
         "open"). Se nao, so registra "skip" no log e sai.
      2. Se ficou mais de cfg_offline_reset_min (30) minutos sem
         rodar com sucesso, re-semeia a baseline.
      3. Primeira vez (ou apos reset): SEMEIA A BASELINE e sai sem
         disparar nada.
      4. Roda os 4 gatilhos (gat_novo, gat_atribuido, gat_alertas,
         gat_sla), cada um isolado num try/catch. Nesta fase sao
         stubs - implementados nas proximas tasks.
      5. Grava wpp_last_ok = NOW() do banco.

REGRA DE OURO: PRIMEIRA SUBIDA = BASELINE, NAO MANDA NADA
--------------------------------------------------------------------
Ao subir o worker pela primeira vez ele marca TODO o estado atual
(chamados abertos, atribuicoes, alertas do parque) como "ja
notificado" SEM ENVIAR mensagem nenhuma, e grava um watermark de
tempo. So eventos que acontecerem DEPOIS disso geram notificacao.
Isso vale tambem depois de um reset por offline longo. Ou seja:
subir/reiniciar o container nunca despeja historico nos grupos/DMs.

COMO SUBIR
--------------------------------------------------------------------
  ssh glpi-server
  cd C:\docker\glpi-portal\
[ ] 1. Pre-requisitos da Fase 1 ok (evolution-api de pe, instancia
       portal_ti conectada, grupos cadastrados).
[ ] 2. Suba o worker:
         docker compose up -d portal-wpp-worker
[ ] 3. Confira que a baseline foi semeada (primeira passada):
         docker compose logs -f portal-wpp-worker
       e/ou a aba "Log" da tela de Notificacoes - deve aparecer
       "baseline semeada: N chamados" (status "baseline").
[ ] 4. Confirme no celular da linha do TI: NENHUMA mensagem foi
       enviada aos grupos durante a subida.

COMO VER O LOG
--------------------------------------------------------------------
  * Container:  docker compose logs -f portal-wpp-worker
  * Aplicacao:  aba "Log" da tela de Notificacoes (le portal_wpp_log)
                ou:
         docker exec glpi-db mariadb -uroot -proot_password glpi2 \
           -e "SELECT criado_em,direcao,status,resumo FROM portal_wpp_log ORDER BY id DESC LIMIT 30;"

TESTES
--------------------------------------------------------------------
AVISO: rode os testes com o worker PARADO. test_worker_baseline.php e
destrutivo (apaga/re-semeia portal_wpp_notificados) e so roda quando
WPP_TEST_DESTRUTIVO=1. Se um ciclo do worker cair nessa janela com a
tabela zerada, gat_sla/gat_atribuido disparam o backlog inteiro.

[ ] docker compose stop portal-wpp-worker
[ ] docker exec -e WPP_TEST_DESTRUTIVO=1 glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
    Espera-se "0 falhas". test_worker_baseline.php cobre
    wpp_semear_baseline (marca chamados/atribuicoes, grava snapshot
    e watermark, idempotente).
[ ] docker compose start portal-wpp-worker

DEPLOY DA FASE 2
--------------------------------------------------------------------

Pre-requisito: FASE 1 funcionando (evolution-api de pe, instancia
portal_ti conectada, grupos cadastrados). Se estiver refazendo do
zero, veja "COMO ZERAR A FASE 2" ao final.

ARQUIVOS A SINCRONIZAR
----
Usar scp para copiar os seguintes arquivos/pastas para o servidor em
C:\docker\glpi-portal\glpi2\portal-glpi\ :
  - wpp/db.php
  - wpp/guardrails.php
  - wpp/evo_api.php
  - wpp/worker.php
  - wpp/gatilhos.php
  - wpp/renotificar.php
  - wpp/tests/ (pasta inteira, arquivos novos)
  - alertas_lib.php
  - alertas.php
  - config_whatsapp.php
  - chamado.php

Exemplo scp (executar do seu PC):
  scp -r "C:\caminho\local\*" glpi-server:C:\docker\glpi-portal\glpi2\portal-glpi\
  (adapte o caminho conforme o seu ambiente local)

ADICIONAR O SERVICO WORKER AO COMPOSE
----
[ ] 1. ssh glpi-server
[ ] 2. Edite C:\docker\glpi-portal\docker-compose.yml (manualmente, em
       editor de texto, NAO via git). Copie EXATAMENTE o trecho abaixo
       ao final do arquivo docker-compose.yml do servidor (antes da
       ultima linha, se houver):

       portal-wpp-worker:
         build: .
         container_name: portal-wpp-worker
         restart: unless-stopped
         depends_on:
           - glpi-db
           - evolution-api
         volumes:
           - C:\docker\glpi-portal\glpi2:/var/www/html/glpi2
         entrypoint: ["sh", "-c", "while true; do php /var/www/html/glpi2/portal-glpi/wpp/worker.php; sleep 30; done"]
         networks:
           - glpi-net

SUBIR O WORKER
----
[ ] 1. No servidor (ssh glpi-server, cd C:\docker\glpi-portal\):
         docker compose up -d portal-wpp-worker
[ ] 2. Aguarde ~5-10s e confira os logs:
         docker compose logs portal-wpp-worker
       Procure por linhas contendo "baseline semeada: N chamados" ou
       mensagens de erro. Se houver erro (ex: "division by zero"), ha
       um bug no worker.php ou no banco - pare e investigue.

PRIMEIRA SUBIDA = BASELINE
----
A primeira execucao do worker nao envia NADA - apenas marca todo o
estado atual como "ja notificado", preenchendo a tabela
portal_wpp_notificados com hashes de todos os chamados abertos,
atribuicoes, alertas. Isso evita despejo de historico nos grupos.

[ ] 1. Confira no log do container:
         docker compose logs -f portal-wpp-worker
       (pressione Ctrl+C para sair)
       Deve haver uma linha: "baseline semeada: N chamados"
       (N = numero de chamados abertos no banco)
[ ] 2. Confira na aba "Log" do portal (config_whatsapp.php ->
       abas Contatos/Gatilhos/Log):
       - Deve haver EXATAMENTE 1 linha com status "baseline" e
         direcao "in" (entrada de dados).
       - NAO deve haver linhas com status "out / ok" (enviadas).
[ ] 3. Confira no celular da linha do TI:
       - Abra os 2 grupos ("TI · Alertas" e "TI · Chamados").
       - Deve estar VAZIO o historico de mensagens do portal durante
         o deploy (pode haver msgs de outras pessoas, mas zero do
         robô WhatsApp do portal).

CONFIGURE CONTATOS E GATILHOS
----
[ ] 1. Aba "Contatos" (config_whatsapp.php):
       Cadastre ao menos 1 tecnico com telefone (GLPI users table,
       coluna user_phone). O numero deve estar em formato apenas
       digitos com DDI (ex: 5567999998888 para +55 67 99999-8888).
       Se nao houver, crie um user de teste no GLPI e preencha o campo
       phone.
[ ] 2. Aba "Gatilhos" (config_whatsapp.php):
       - Certifique-se de que "Chamar novo" esta LIGADO (toggle on).
       - Se desejar DMs por atribuicao: deixe "Atribuido" ligado, ajuste
         cfg_delay_dm_min conforme necessario (recomendado >= 2 min).
       - Se desejar digest de alertas: deixe "Alertas (digest)" ligado.
       - Se desejar avisos de SLA/parado: deixe "SLA / Parado" ligado.

TESTE REAL
----
[ ] 1. Abra uma aba de navegador com o GLPI:
         https://192.168.1.198:8080 (ou o URL correto)
[ ] 2. Crie um chamado de teste (ex: titulo "TESTE FASE 2 WHATSAPP",
       descricao rapida).
[ ] 3. Aguarde 30-60 segundos (e.g., contando lentamente, dois ciclos
       do worker: 30s + 30s).
[ ] 4. Verifique no celular da linha do TI:
       - Abra o grupo "TI · Chamados".
       - Deve haver 1 UNICA mensagem novo chamado (ex: "[CHAMADO #123]
         TESTE FASE 2 WHATSAPP...").
       - Se nao chegou, confira: wpp_baseline_ok = 1 no banco
         (portal_wpp_config), e se ha erro nos logs do worker.
[ ] 5. Volte ao GLPI, abra o chamado criado.
[ ] 6. Atribua o chamado ao tecnico que voce cadastrou na aba
       Contatos (aquele com telefone).
[ ] 7. Aguarde cfg_delay_dm_min (recomendado: 2 min, portanto 120s).
[ ] 8. Confira no WhatsApp da linha do TI:
       - Deve haver 1 mensagem PRIVADA (DM, nao grupo) para o tecnico
         atribuido (ex: "[ATRIBUIDO] Chamado #123 de voce" ou similar).
       - Confira a aba "Log" da portal: deve haver linhas com status
         "out / ok" para o numero do tecnico.

RODAR OS TESTES
----
AVISO: o teste TEM QUE rodar com o worker PARADO. test_worker_baseline.php
apaga e re-semeia portal_wpp_notificados; com a tabela zerada, um ciclo do
worker nessa janela faz gat_sla/gat_atribuido despejarem o backlog inteiro
nos grupos + DM pra todo tecnico. Por isso ele so roda com
WPP_TEST_DESTRUTIVO=1.

[ ] No servidor (ssh glpi-server, cd C:\docker\glpi-portal\):
         docker compose stop portal-wpp-worker
         docker exec -e WPP_TEST_DESTRUTIVO=1 glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
         docker compose start portal-wpp-worker
       Espera-se "0 falhas". Erros tipo "undefined function" ou
       "division by zero" indicam problema na sincronizacao dos
       arquivos ou falta de dependencia.

VERIFICACAO FINAL (CHECKLIST)
----
[ ] Worker de pé:
      docker compose ps | grep portal-wpp-worker
    Deve estar com status "Up" (verde).
[ ] Baseline foi semeada:
      docker exec glpi-db mariadb -uroot -proot_password glpi2 \
        -e "SELECT COUNT(*) FROM portal_wpp_notificados;"
    Deve retornar um numero > 0.
[ ] Banco configurado:
      docker exec glpi-db mariadb -uroot -proot_password glpi2 \
        -e "SELECT chave,valor FROM portal_wpp_config WHERE chave IN
             ('wpp_baseline_ok','wm_novo','wm_alertas_digest');"
      Deve haver: wpp_baseline_ok = 1, wm_novo = 1 (ou ajuste conforme
      gatilhos ligados).
[ ] Nenhum webhook de mensagem na Evolution (isso e Fase 3):
      docker exec glpi-db mariadb -uroot -pevolution_pw evolution \
        -e "SELECT COUNT(*) FROM webhooks WHERE enabled = 1;"
      Deve retornar 0 (nenhum webhook ativo).
      (DESATUALIZADO: a Fase 2 esperava 0 aqui. A partir da FASE 3 -
      ETAPA 1 espera-se 1 - o webhook do portal-glpi. Veja a secao
      "FASE 3 - ETAPA 1" abaixo.)
[ ] Testes verdes ("0 falhas").
[ ] Nenhuma mensagem nao autorizada nos grupos durante o deploy.

ROLLBACK RAPIDO
----
Se precisar parar o worker IMEDIATAMENTE (ex: bug, envio errado):
  docker compose stop portal-wpp-worker
  docker compose rm -f portal-wpp-worker

NUNCA use "docker compose down" - isso mata TODOS os containers,
incluindo glpi-db e evolution-api. O comando acima so para o worker.

Apos o rollback, o proximo "docker compose up -d portal-wpp-worker" ira
re-semear a baseline (watermark zerado) - logo, o deploy seguinte deve
novamente marcar TUDO sem enviar nada.

====================================================================
 COMO ZERAR A FASE 2
====================================================================

Se precisar resetar completamente a Fase 2 (limpar notificacoes,
agendamentos, config, e re-semear baseline na proxima subida):

[ ] 1. Parar o worker:
         docker compose stop portal-wpp-worker
[ ] 2. Limpar as tabelas:
         docker exec glpi-db mariadb -uroot -proot_password glpi2 \
           -e "DELETE FROM portal_wpp_notificados; \
               DELETE FROM portal_wpp_dm_agendado; \
               DELETE FROM portal_wpp_config WHERE chave IN \
               ('wpp_baseline_ok','wpp_last_ok','wpp_snap_alertas', \
                'wm_novo','wm_alertas_digest');"
[ ] 3. Subir novamente:
         docker compose up -d portal-wpp-worker
[ ] 4. Aguarde a baseline ser semeada (veja os logs):
         docker compose logs portal-wpp-worker
       A proxima subida NAO enviara mensagem nenhuma - apenas marcara
       tudo como "ja notificado" novamente.

Este procedimento deixa os grupos intactos (nenhuma mensagem removida).
Usa-se quando ha bug no worker, ou para testar a baseline do zero.

====================================================================
 FIM - FASE 2
====================================================================


====================================================================
 FASE 3 - ETAPA 1 - INFRA DE ENTRADA (WEBHOOK)
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * wpp/webhook.php recebe eventos da Evolution (MESSAGES_UPSERT,
    CONNECTION_UPDATE). Nesta etapa NAO tem chatbot: so confirma que
    a mensagem passou pelo guardrail de origem e loga. Nada responde
    ainda - isso e Etapa 2.
  * Toggle "Chatbot de entrada" na aba Gatilhos (on_chatbot) - precisa
    estar LIGADO pro webhook processar qualquer coisa.
  * Botao "Ativar/desativar" na aba Conexao registra/remove o webhook
    na Evolution (evo_set_webhook).

ARQUIVOS A SINCRONIZAR
----
  - wpp/db.php
  - wpp/guardrails.php
  - wpp/webhook_parse.php (novo)
  - wpp/webhook.php (novo)
  - wpp/evo_api.php
  - wpp/config.example.php
  - config_whatsapp.php
  - wpp/tests/ (pasta inteira, arquivos novos)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] wpp/config.php do servidor tem WPP_WEBHOOK_URL definida (copie de
    config.example.php; ajuste o path se a montagem do portal dentro
    do container evolution-api/glpi-web for diferente de
    "http://glpi-web/glpi2/portal-glpi/wpp/webhook.php").
[ ] wpp/config.php do servidor tem WPP_WEBHOOK_SECRET com um valor
    aleatorio (openssl rand -hex 24). O webhook e um endpoint publico:
    esse segredo vai como header "X-Wpp-Secret" no registro feito na
    Evolution, ela reenvia em todo delivery e o webhook.php so processa
    o que bater. Sem a constante definida a checagem e PULADA (pra nao
    derrubar trafego de um servidor com config antigo) - ou seja,
    enquanto ela nao existir o endpoint segue aceitando POST anonimo.
    Se trocar o valor depois, reregistre o webhook (aba Conexao ->
    "Ativar/desativar" duas vezes), senao a Evolution continua mandando
    o segredo antigo e tudo passa a ser descartado em silencio.
[ ] Confirme que nenhuma outra app registrou webhook na instancia
    portal_ti (Reconhecimento-facial e checklist-gmais devem ter
    instancia/numero proprios, nunca portal_ti):
      docker exec evolution-db mariadb -uroot -pevolution_pw evolution \
        -e "SELECT instanceId, url, enabled FROM webhooks;"
    Deve haver NO MAXIMO 1 linha (a do portal-glpi), com a URL do
    portal-glpi. Se houver outra, pare e alinhe com quem mantem a outra
    app antes de continuar.

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima pro servidor.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas".
[ ] 3. Aba Gatilhos -> ligar "Chatbot de entrada" -> Salvar.
[ ] 4. Aba Conexao -> "Ativar/desativar" -> confirma "ativo".

Nao ha migration manual: a tabela nova portal_wpp_msgs_vistas (dedup de
message.id) e criada sozinha por CREATE TABLE IF NOT EXISTS na primeira
vez que wpp/db.php e incluido - mesmo padrao de todas as outras tabelas
portal_wpp_*.

Sem wpp/config.php no servidor, o webhook.php responde 200 mas nao
processa nada (boot falha de proposito, registro no error_log do PHP:
"wpp/webhook: boot falhou"). O mesmo vale se o glpi-db estiver fora do
ar: sempre 200, nunca 500 - se devolvesse 500 a Evolution entraria em
loop de reentrega.

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. De um numero QUALQUER (nao precisa estar cadastrado em nada),
       manda uma mensagem privada pra linha do TI: "oi".
[ ] 2. Confere o log:
         docker exec glpi-db mariadb -uroot -proot_password glpi2 \
           -e "SELECT criado_em,direcao,destino,resumo,status FROM portal_wpp_log ORDER BY id DESC LIMIT 5;"
       Espera: 1 linha direcao='in', status='ok', resumo contendo
       "recebido (chatbot ainda nao implementado)".
[ ] 3. CONFIRME QUE O PAYLOAD BATEU COM O ESPERADO: se a linha do
       passo 2 NAO aparecer (nada foi logado), o parser
       (wpp_extrair_msg) provavelmente nao reconheceu o formato real
       do payload da Evolution. Adicione um log temporario em
       wpp/webhook.php logo apos "$payload = json_decode(...)":
         wpp_log('sys', 'webhook-debug', substr($raw, 0, 500), 'raw');
       Rode o teste de novo, veja o payload real em portal_wpp_log, e
       ajuste wpp_extrair_msg() (wpp/webhook_parse.php) pra bater com
       o formato encontrado. Remova o log de debug depois.
[ ] 4. Repete o passo 1 de dentro de um GRUPO (ex: TI - Chamados),
       mandando de um APARELHO DE VERDADE -> deve aparecer EXATAMENTE
       1 linha nova com status 'bloqueado' (grupo e sempre bloqueado;
       essa linha e o guardrail funcionando, nao um bug).
       Ja o que o PROPRIO portal posta nos grupos (mensagens do worker)
       volta pela Evolution como fromMe=true e NAO pode gerar linha
       nenhuma no log - fromMe e sempre silencioso. Mensagem de status
       de contato (status@broadcast) tambem nao loga (ruido de alto
       volume).
[ ] 5. Manda a MESMA mensagem 2x rapido (reentrega) -> so 1 linha no
       log (dedup por message.id).
[ ] 6. Aba Gatilhos -> desligar "Chatbot de entrada" -> Salvar. Manda
       "oi" de novo -> NADA no log (on_chatbot=0 corta tudo).

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desligar "Chatbot de entrada" (para o processamento
na hora, sem precisar reverter arquivo). Pra tirar o webhook da
Evolution: aba Conexao -> "Ativar/desativar" ate mostrar "desativado".

====================================================================
 FIM - FASE 3 ETAPA 1
====================================================================


====================================================================
 FASE 3 - ETAPA 2 - FLUXO A (ABRIR CHAMADO, NUMERO VINCULADO)
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Numero vinculado (aba Vinculos OU telefone cadastrado no GLPI) que
    manda qualquer mensagem privada recebe: "Abrir chamado para
    [Nome]. Qual o titulo?" -> titulo -> descricao (com loop
    "adicionar mais?") -> cria o chamado de verdade no GLPI.
  * Numero NAO vinculado recebe 1 mensagem ("ainda nao disponivel") e
    NAO fica com conversa presa - fluxo completo (loja/usuario/
    pendencia) e Etapa 3.
  * Worker (a cada passada) encerra conversas paradas ha mais de
    chatbot_timeout_min (default 5min): avisa se for recente, apaga
    calado se for > 30min.

ARQUIVOS A SINCRONIZAR
----
  - wpp/db.php
  - wpp/guardrails.php
  - wpp/chatbot.php (novo)
  - wpp/glpi_bot.php (novo)
  - wpp/webhook.php
  - wpp/worker.php
  - config_whatsapp.php
  - wpp/tests/ (arquivos novos)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] Etapa 1 em produção (webhook ativo, on_chatbot ligavel).
[ ] Cadastrar pelo menos 1 numero na aba Vinculos (ou confirmar que
    algum usuario GLPI ja tem celular/telefone cadastrado) - senao
    nao tem como testar o Fluxo A de ponta a ponta.

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas" (ignorando falhas pre-existentes de outras
       features nao relacionadas a esta etapa, se houver).
[ ] 3. Aba Vinculos -> cadastra o numero de teste.
[ ] 4. on_chatbot ja deve estar ligado (Etapa 1). Se nao, aba
       Gatilhos -> liga.

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. Do numero cadastrado na aba Vinculos, manda "oi" pro WhatsApp
       do TI.
[ ] 2. Deve receber: "Abrir chamado para [Nome]. Qual o titulo?"
[ ] 3. Responde com um titulo de teste.
[ ] 4. Deve receber: "Obrigado! Agora descreva o problema."
[ ] 5. Responde com uma descricao de teste.
[ ] 6. Deve receber o menu "Adicionar mais? 1/2/3".
[ ] 7. Responde "2".
[ ] 8. Deve receber "Chamado #N criado." - confere no GLPI que o
       chamado existe, com o titulo/descricao certos, requerente =
       o usuario vinculado.
[ ] 9. De um numero QUALQUER (nao vinculado), manda "oi" -> deve
       receber a mensagem de "ainda nao disponivel" e NADA mais (sem
       ficar esperando resposta).
[ ] 10. Comeca um fluxo de novo e espera mais de chatbot_timeout_min
        minutos sem responder -> deve receber "Tempo esgotado".

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desliga "Chatbot de entrada" (on_chatbot=0). A partir
dai:
  * O webhook continua respondendo 200 pra Evolution, mas NAO processa
    mensagem nenhuma e NAO grava linha de entrada no portal_wpp_log -
    o gate de on_chatbot envolve todo o tratamento de MESSAGES_UPSERT
    (mesmo comportamento da Etapa 1: com o toggle desligado, nada no
    log). Eventos de conexao (CONNECTION_UPDATE) seguem normais.
  * Nenhuma conversa nova comeca e nenhuma mensagem de saida do
    chatbot e enviada - inclusive o aviso "Tempo esgotado" do sweep do
    worker fica desligado, entao ninguem recebe mensagem tardia depois
    do rollback.
  * O sweep do worker CONTINUA rodando a limpeza silenciosa: conversa
    parada ha mais de 30 min e apagada de portal_wpp_conversas sem
    avisar ninguem. Isso e de proposito - essa linha e tambem o que
    autoriza o guardrail de saida a falar com um numero so-vinculado,
    entao ela nao pode ficar pra tras.
Conversa ja em andamento no momento do desligamento simplesmente para
de responder e expira sozinha.

Com on_chatbot LIGADO, toda mensagem aceita pelo guardrail de origem
gera 1 linha 'in' no portal_wpp_log (texto truncado em 80 chars) antes
de entrar na FSM - e por ai que se diagnostica "mandei mensagem e nao
aconteceu nada".

====================================================================
 FIM - FASE 3 ETAPA 2
====================================================================


====================================================================
 FASE 3 - ETAPA 3 - MENU + LOJA/SETOR SEMPRE + CONFIRMACAO DE VINCULO
====================================================================

O QUE FAZ
--------------------------------------------------------------------
  * Toda conversa nova comeca com o menu:
      1 - Abrir chamado
      2 - Consultar chamado (em breve)
      3 - Sair
  * "1" com numero vinculado a um TECNICO (profiles_id=4 no GLPI):
    sempre escolhe loja -> escolhe usuario da loja -> titulo ->
    descricao. NUNCA pula mais pro titulo direto.
  * "1" com numero vinculado a um usuario QUALQUER OUTRO PERFIL
    (ex: "SAC Santos Bonito"): pergunta "Quer atendimento pra
    <loja>, departamento <nome>? 1 Sim / 2 Nao". Sim pula pro
    titulo com esse vinculo; Nao cai no mesmo picker manual do
    tecnico.
  * "1" sem vinculo nenhum: mensagem de "ainda nao disponivel" -
    SEM MUDANCA da Etapa 2 (nao entra no picker - protecao contra
    abuso, ate a pendencia de aprovacao existir numa etapa futura).
  * "2"/"3" no menu: responde e nao fica com conversa presa.
  * Lista de lojas e lista de usuarios da loja sao limitadas a 30
    itens cada (WPP_CHATBOT_PICKER_MAX); se a loja/instancia tiver
    mais que isso, a mensagem avisa "fale direto com o TI" em vez
    de listar tudo (sem busca por nome).
  * Sweep de timeout do worker: conversa parada no passo 'menu'
    (numero mandou "oi" mas nunca respondeu 1/2/3) e limpa em
    SILENCIO, sem o aviso de "Tempo esgotado" - evitar mandar
    mensagem nao solicitada pra numero errado/spam que nunca
    interagiu de verdade.
  * Lista interativa (sendList) NAO e usada - o Spike 0 (rodado em
    2026-09-11) deu erro interno na Evolution atual. So menu
    numerado por agora.

ARQUIVOS A SINCRONIZAR
----
  - wpp/glpi_bot.php
  - wpp/chatbot.php
  - wpp/tests/ (arquivos novos: test_glpi_bot_lojas.php, test_chatbot_menu.php)
  - wpp/tests/test_chatbot_fsm.php (ajustado pra nova navegação do menu)
  - wpp/tests/test_chatbot_timeout.php (ganhou teste novo da exceção de sweep)

PRE-REQUISITO
--------------------------------------------------------------------
[ ] Etapa 2 em produção.
[ ] Confirme que o perfil "tecnico" no GLPI e mesmo profiles_id=4
    (mesmo criterio ja usado na aba Contatos) - se a instalacao usar
    outro id, ajustar bot_perfil_tecnico() em wpp/glpi_bot.php antes
    de sincronizar.

DEPLOY
--------------------------------------------------------------------
[ ] 1. scp dos arquivos listados acima.
[ ] 2. Testes: docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
       Espera-se "0 falhas" (ignorando a falha pre-existente e nao
       relacionada da Central de Alertas Etapa 2, se ainda presente).

VERIFICACAO FIM-A-FIM
--------------------------------------------------------------------
[ ] 1. De um numero vinculado a um TECNICO, manda "oi" -> recebe o
       menu -> "1" -> recebe lista de lojas (nunca pula pro titulo).
[ ] 2. Escolhe uma loja (numero) -> recebe lista de usuarios daquela
       loja.
[ ] 3. Escolhe um usuario -> recebe "Abrir chamado para <nome>. Qual
       o titulo?" -> segue o fluxo normal (titulo/descricao/2) ->
       confere no GLPI que o requerente e o USUARIO ESCOLHIDO, nao
       o tecnico.
[ ] 4. De um numero vinculado a um usuario QUE NAO E TECNICO, manda
       "oi" -> "1" -> recebe a pergunta de confirmacao com o nome da
       loja e do vinculo certos -> "1" (sim) -> pula pro titulo.
[ ] 5. Repete o passo 4 mas responde "2" (nao) na confirmacao ->
       cai no mesmo picker de loja do passo 1.
[ ] 6. De um numero SEM vinculo nenhum, "1" -> mensagem de
       indisponivel, sem picker.
[ ] 7. No menu, "2" -> mensagem de em breve, sem ficar preso.
[ ] 8. No menu, "3" -> mensagem de saida, sem ficar preso.

ROLLBACK
--------------------------------------------------------------------
Aba Gatilhos -> desliga "Chatbot de entrada" (mesmo da Etapa 1/2).

====================================================================
 FIM - FASE 3 ETAPA 3
====================================================================
