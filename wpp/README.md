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
[ ] docker exec glpi-web php /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
    Espera-se "0 falhas". test_worker_baseline.php cobre
    wpp_semear_baseline (marca chamados/atribuicoes, grava snapshot
    e watermark, idempotente).

====================================================================
 FIM - FASE 2
====================================================================
