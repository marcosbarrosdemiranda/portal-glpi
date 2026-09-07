====================================================================
 INTEGRACAO WHATSAPP - FASE 1 (EVOLUTION API + PAREAMENTO)
 Portal GLPI - Runbook de deploy e pareamento
====================================================================

Deploy no servidor de producao: ssh glpi-server, stack Docker em
C:\docker\glpi-portal\. Comandos de host sao PowerShell; os comandos
"docker exec ..." rodam dentro dos containers Linux. Leia tudo antes.

O QUE E A FASE 1
--------------------------------------------------------------------
  * Sobe o container evolution-api (gateway WhatsApp, so na rede interna).
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
[ ] 3. Crie o database "evolution" a mao (o compose nao cria mais nada
       automaticamente):
         docker exec glpi-db mariadb -uroot -proot_password -e "CREATE DATABASE IF NOT EXISTS evolution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
[ ] 4. Crie wpp\config.php a partir do exemplo e ajuste:
         Copy-Item C:\docker\glpi-portal\glpi2\portal-glpi\wpp\config.example.php C:\docker\glpi-portal\glpi2\portal-glpi\wpp\config.php
       EVO_API_KEY = a MESMA chave do EVOLUTION_API_KEY do .env.
       EVO_URL e EVO_INSTANCE ja vem certos.
[ ] 5. Suba so a Evolution:
         docker compose up -d evolution-api
[ ] 6. Confira os logs (aguarde ~20s):
         docker compose logs evolution-api
       Erro de Prisma/migration => va em FALLBACK. Sem esse erro = ok.
[ ] 7. Teste a conectividade de dentro do glpi-web (a imagem nao tem
       curl; use o PHP):
         docker exec glpi-web php -r "echo @file_get_contents('http://evolution-api:8080/');"
       Qualquer resposta HTTP (ate 404) serve; "failed to open stream"
       = ainda nao respondendo.

FALLBACK: MARIADB 10.4 NAO ACEITA AS MIGRATIONS
--------------------------------------------------------------------
Se o passo 6 der erro de Prisma/migration, o MariaDB 10.4 do glpi-db
nao serve. Suba um MariaDB 11 dedicado.
[ ] 1. No docker-compose.yml do servidor (mesma pasta do .env,
       C:\docker\glpi-portal\) adicione o servico (o compose so usa
       bind mount; nao ha bloco "volumes:" no topo):

  evolution-db:
    image: mariadb:11
    container_name: evolution-db
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: root_password_evolution
      MARIADB_DATABASE: evolution
    volumes:
      - C:\docker\glpi-portal\evolution-db-data:/var/lib/mysql
    networks:
      - glpi-net
[ ] 2. No servico evolution-api, troque a URI e o depends_on:
      environment:
        DATABASE_CONNECTION_URI: "mysql://root:root_password_evolution@evolution-db:3306/evolution"
      depends_on:
        - evolution-db
[ ] 3. Recrie so o evolution-api (NAO use "docker compose down" - isso
       derruba a stack GLPI inteira):
         docker compose stop evolution-api
         docker compose rm -f evolution-api
         docker compose up -d evolution-db evolution-api
[ ] 4. Confira de novo:  docker compose logs evolution-api

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
R: MariaDB 10.4 nao serve; siga a secao FALLBACK.
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
   3) Pra zerar a Evolution: DROP DATABASE evolution, recrie (passo 3)
      e "docker compose up -d evolution-api".

====================================================================
 FIM DO RUNBOOK
====================================================================
