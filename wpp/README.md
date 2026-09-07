====================================================================
 INTEGRACAO WHATSAPP - FASE 1 (EVOLUCAO API + PAREAMENTO)
 Portal GLPI - Runbook de Deploy e Pareamento
====================================================================

O QUE E A FASE 1
====================================================================

A Fase 1 implementa:
  * Evolution API (container Docker mariadb + evolution-api)
  * Tela de configuracao config_whatsapp.php com duas abas:
      - Conexao: QR code para parear uma linha WhatsApp dedicada do TI
      - Grupos: cadastro dos grupos de alertas e chamados
  * Instancia Evolution com settings seguros (sem backfill, sem history)
  * Permissao notificacoes_config no perfil para acessar Configuracao de Alertas
  * Card "Notificacoes" no dashboard

O QUE NAO E A FASE 1
====================================================================

Nao esta implementado ainda:
  * Envio automatico de mensagens (Fase 2: webhook + worker)
  * Sincronizacao de status de chamados para WhatsApp (Fase 3+)
  * Backup de historico de mensagens
  * Webhook de mensagens recebidas

LEIA TUDO ANTES DE COMECAER


====================================================================
DEPLOY NO SERVIDOR (PASSO A PASSO)
====================================================================

Acesse o servidor GLPI:
  ssh glpi-server
  cd C:\docker\glpi-portal\

[ ] 1. Gere a chave Evolution API:
       Prompt (PowerShell):
         openssl rand -hex 24
       Exemplo: 7f3a9c2b1e8d4f6a9c2b1e8d4f6a9c2b1e8d4f6

[ ] 2. Edite docker/.env e adicione:
       EVOLUTION_API_KEY=7f3a9c2b1e8d4f6a9c2b1e8d4f6a9c2b1e8d4f6
       (Faca copiar exatamente o valor gerado; guarde para o passo 6)

[ ] 3. Crie o database evolution no MariaDB:
       docker exec glpi-db mariadb -uroot -proot_password \
         -e "CREATE DATABASE IF NOT EXISTS evolution \
             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

[ ] 4. Crie o arquivo glpi2/portal-glpi/wpp/config.php:
       Copie de config.example.php:
         cp wpp/config.example.php wpp/config.php

[ ] 5. Edite wpp/config.php:
       define('EVO_URL', 'http://evolution-api:8080');
       define('EVO_API_KEY', '7f3a9c2b1e8d4f6a9c2b1e8d4f6a9c2b1e8d4f6');
                              ^ MESMA chave do docker/.env
       define('EVO_INSTANCE', 'portal_ti');

[ ] 6. Suba o container Evolution API:
       docker compose up -d evolution-api

[ ] 7. Verifique os logs (aguarde ~15-30 segundos):
       docker compose logs evolution-api
       
       Procure por:
         * "Prisma", "migration", "error": ERRO (ver Fallback abaixo)
         * "listening on 0.0.0.0:8080": OK
         * Sem mensagens de erro aparentes: OK

[ ] 8. Teste a conectividade:
       docker exec glpi-web curl -s http://evolution-api:8080/ | head -c 100
       Deve retornar um "<!DOCTYPE" ou erro HTTP 404 (nao conexao recusada).


====================================================================
FALLBACK: MARIADB 10.4 NAO ACEITA MIGRATIONS
====================================================================

Se os logs do paso 7 mostram erros Prisma/migration, o MariaDB 10.4
nao suporta a versao esperada por Evolution. Use um container
MariaDB 11 dedicado.

[ ] 1. Edite docker/docker-compose.yml e adicione ANTES do
       servico evolution-api:

  evolution-db:
    image: mariadb:11
    container_name: evolution-db
    environment:
      MARIADB_ROOT_PASSWORD: root_password_evolution
      MARIADB_DATABASE: evolution
    volumes:
      - evolution_db_data:/var/lib/mysql
    networks:
      - glpi-net
    restart: unless-stopped

[ ] 2. Mude o servico evolution-api para apontar a este database:

       evolution-api:
         environment:
           DATABASE_CONNECTION_URI: mysql://root:root_password_evolution@evolution-db:3306/evolution
         depends_on:
           - evolution-db
         # ... resto do servico ...

[ ] 3. Adicione o volume evolution_db_data ao final do arquivo:

volumes:
  # ... volumes existentes ...
  evolution_db_data:

[ ] 4. Suba novamente:
       docker compose down evolution-api
       docker compose up -d evolution-db evolution-api
       
       Aguarde ~15s, depois:
       docker compose logs evolution-api
       (deve listar sem erros Prisma)

[ ] 5. Teste:
       docker exec glpi-web curl -s http://evolution-api:8080/ | head -c 100


====================================================================
TESTES UNITARIOS (VALIDACAO IMEDIATA)
====================================================================

Rode a suite de testes da Fase 1:

[ ] 1. Execute:
       docker exec glpi-web php \
         /var/www/html/glpi2/portal-glpi/wpp/tests/run.php

[ ] 2. Resultado esperado:
       Todos os testes sao "ok, 0 falhas" 
       Exemplo:
         test_evo_status_open ... ok
         test_evo_qr_format ... ok
         test_evo_groups_list ... ok
         test_wpp_cfg_save_load ... ok
         4 ok, 0 falhas
       
       Se houver falha: debug na secao FAQ abaixo.


====================================================================
PAREAMENTO DA LINHA WHATSAPP
====================================================================

Use a tela de configuracao para conectar um numero WhatsApp dedicado
do TI (nao use numero pessoal ou compartilhado).

[ ] 1. No navegador, acesse:
       https://192.168.1.198:7412/glpi2/portal-glpi/config_whatsapp.php

[ ] 2. Aba "Conexao" deve estar visivel.

[ ] 3. Clique em "Conectar".
       A tela carregara um QR code.

[ ] 4. Pegue o celular com o numero WhatsApp **DEDICADO DO TI**
       (nao pessoal):
         * Abra WhatsApp
         * Vá a: Configuracoes (ou "Ajustes") -> Dispositivos Vinculados
         * Clique em "Vincular um Dispositivo"
         * Aponte a camera para o QR code da tela
         * Escaneie (o WhatsApp vai reconhecer em ~5 segundos)

[ ] 5. Aguarde a tela processar. O numero no canto superior esquerdo
       (ou area de status) deve mudar de "Desconectado" para
       "Conectado".

[ ] 6. Confirme:
       Status em config_whatsapp.php aba Conexao deve ser
       "Conectado" e mostrar o numero pareado.

       Se mostrar erro ou "Aguardando", aguarde 10s e recarregue
       (F5). Se persistir, ver FAQ.


====================================================================
CADASTRO DOS GRUPOS
====================================================================

Crie dois grupos no WhatsApp e registre as JIDs no Portal.

IMPORTANTE: O numero pareado DEVE ser administrador dos grupos.

[ ] 1. No celular do TI (com o numero pareado), crie 2 grupos:
         * Nome: "TI · Alertas"
           Descrição: "Alertas automaticos do Portal GLPI"
           Membros: adicione o numero da linha do TI (admin dele mesmo)
         
         * Nome: "TI · Chamados"
           Descrição: "Atualizacoes de chamados do Portal GLPI"
           Membros: adicione o numero da linha do TI (admin dele mesmo)
       
       (Pode adicionar mais membros depois se desejar.)

[ ] 2. Volte a tela config_whatsapp.php, aba "Grupos".

[ ] 3. Clique em "Recarregar Grupos".
       A tela vai chamar a Evolution API e listar todos os grupos
       que o numero pareado eh membro.

[ ] 4. Na lista, marque:
         * "TI · Alertas" - selecione e clique em "Salvar"
           (a JID sera guardada em portal_wpp_config.grupo_alertas_jid)
       
       * "TI · Chamados" - selecione e clique em "Salvar"
         (a JID sera guardada em portal_wpp_config.grupo_chamados_jid)

[ ] 5. Recarregue a pagina (F5). Os grupos aparecerao salvos
       ("Grupo Alertas: TI · Alertas", etc.).


====================================================================
VERIFICACAO FINAL (CHECKLIST)
====================================================================

Antes de considerar a Fase 1 pronta, confirme cada item:

[ ] Evolution API esta rodando:
    docker compose logs evolution-api | grep -i "listening\|error"
    Esperado: "listening on 0.0.0.0:8080" sem erros.

[ ] Instancia portal_ti esta criada:
    docker exec glpi-web php -r \
      "require 'glpi2/portal-glpi/wpp/evo_api.php'; \
       \$s=evo_status(); echo 'Estado: '.\$s['estado'];"
    Esperado: "Estado: open"

[ ] Banco de dados evolution existe:
    docker exec glpi-db mariadb -uroot -proot_password \
      -e "SHOW DATABASES LIKE 'evolution';"
    Esperado: uma linha com "evolution"

[ ] Arquivo config.php existe e esta legivel:
    ls -la /var/www/html/glpi2/portal-glpi/wpp/config.php
    Esperado: arquivo com tamanho > 0

[ ] Tabela portal_wpp_config tem registros:
    docker exec glpi-db mariadb -uroot -root_password portal_glpi \
      -e "SELECT chave, valor FROM portal_wpp_config;"
    Esperado: linhas com "grupo_alertas_jid" e "grupo_chamados_jid"
              preenchidas (nao NULL ou vazias).

[ ] Numero esta pareado:
    docker exec glpi-web php -r \
      "require 'glpi2/portal-glpi/wpp/evo_api.php'; \
       \$n=evo_status(); echo 'Numero: '.\$n['estado'];"
    Esperado: um numero pareado, exemplo "+55 11 9999-8888"
    (ou "open" em testes; se "closed" ou erro, volte ao pareamento)

[ ] Permissao notificacoes_config esta configurada:
    Portal -> Perfis -> seu perfil -> Notificacoes = "Ativado"
    Esperado: a permissao aparece marcada.

[ ] **CRITICAMENTE**: nenhuma mensagem foi enviada ainda:
    - Verificar no WhatsApp do numero pareado: nenhuma mensagem
      para "TI · Alertas" ou "TI · Chamados" durante toda a sessao
      de setup.
    - Verificar no banco: portal_wpp_mensagens esta vazio (ou nao existe).
    - Nenhum teste chamou evo_send_message() na execucao.

[ ] **CRITICAMENTE**: Evolution NAO tem webhook de mensagem:
    Portal -> Configuracao -> (se existir aba Webhooks)
    Esperado: nenhuma entrada webhook para "message" ou "incomming_message".
    Estes sao configurados em Fase 2 apenas.

[ ] Testes unitarios passam:
    docker exec glpi-web php \
      /var/www/html/glpi2/portal-glpi/wpp/tests/run.php
    Esperado: "4 ok, 0 falhas" (ou semelhante; 0 falhas).


====================================================================
PERGUNTAS FREQUENTES
====================================================================

P: Depois de pareado, como desparear a linha?
R: Na aba Conexao de config_whatsapp.php, clique em "Desconectar".
   A conexao Evolution fecha e o numero deixa de ser pareado. Pode
   parear outro numero em seguida.

P: O QR code nao apareceu?
R: 1. Confirme Evolution API esta rodando (ver logs do docker).
   2. Confira config.php tem EVO_URL correto ('http://evolution-api:8080').
   3. Recarregue a pagina (F5), nao use cache do navegador (Ctrl+Shift+Del).
   4. Se persistir, ver logs do evolution-api com erros.

P: Erro "database evolution nao existe"?
R: Rode o comando do Paso 3 (CREATE DATABASE) novamente:
   docker exec glpi-db mariadb -uroot -proot_password \
     -e "CREATE DATABASE IF NOT EXISTS evolution \
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

P: Erro "Prisma migration" nos logs de evolution-api?
R: Seu MariaDB nao suporta o schema de Evolution. Siga o Fallback
   (usar MariaDB 11 separado) a partir de "FALLBACK" neste doc.

P: Grupos nao aparecem no dropdown?
R: 1. Confirme que o numero pareado e admin do grupo no WhatsApp.
   2. Clique em "Recarregar Grupos" de novo (leva ~10s).
   3. Se vazio, talvez o numero nao seja membro do grupo; volte ao
      WhatsApp e cheque.

P: "Salvar" no grupo nao faz nada?
R: O arquivo config.php pode nao ter permissao de escrita. Verifique:
   docker exec glpi-web ls -la \
     /var/www/html/glpi2/portal-glpi/wpp/config.php
   Se nao tem "w" para www-data, rode:
   docker exec glpi-web chown www-data:www-data \
     /var/www/html/glpi2/portal-glpi/wpp/

P: E a card "Notificacoes" no dashboard?
R: Aparece automaticamente apos deploy. Se faltar, confirme:
   * Seu perfil tem permissao "notificacoes_config" = ativado
   * Portal foi recarregado (F5)

P: Posso usar um numero pessoal para parear?
R: Tecnicamente sim, mas NAO RECOMENDADO. A linha pareada sera usada
   para enviar alertas automaticamente (Fase 2+). Use um numero
   dedicado do TI, nao pessoal.

P: Ja posso enviar mensagens automaticamente?
R: NAO. Fase 1 apenas conecta a linha e registra os grupos. O envio
   automatico (webhook + worker) chega em Fase 2. Por enquanto, sao
   so testes manuais via API.

P: Como reiniciar a Fase 1 do zero?
R: 1. Desconecte a linha: config_whatsapp.php aba Conexao -> Desconectar
   2. Limpe config.php: remova os valores de grupo_alertas_jid e
      grupo_chamados_jid (deixe = '' ou delete a linha)
   3. Apague o database: docker exec glpi-db mariadb -uroot \
                         -proot_password -e "DROP DATABASE evolution;"
   4. Recrie: docker exec glpi-db mariadb -uroot -proot_password \
              -e "CREATE DATABASE ... evolution ..." (ver Paso 3)
   5. Reinicie os containers: docker compose up -d evolution-api
   6. Espere 15s e rode os testes de novo.


====================================================================
ESTRUTURA DE ARQUIVOS CRIADOS (REFERENCIA)
====================================================================

wpp/README.md                  <- Este arquivo
wpp/config.php                 <- Copiado de config.example.php (gitignored)
wpp/config.example.php         <- Exemplo; nao editar
wpp/evo_api.php                <- Funções Evolution (status, qr, groups, etc)
wpp/db.php                     <- Queries portal_wpp_*
wpp/tests/run.php              <- Suite de testes
wpp/tests/test_evo_api.php     <- Casos de teste
wpp/tests/assert.php           <- Utilidades de teste

docker/docker-compose.yml      <- Servico evolution-api adicionado
docker/.env                    <- EVOLUTION_API_KEY adicionado
docker/.env.example            <- Documentacao da chave
docker/init-evolution-db.sql   <- Script de inicializacao (nao funciona com volume populado)


====================================================================
DICAS DE PRODUCAO
====================================================================

* Backup: o banco evolution e independente; faca backup regularmente
  junto com o volume glpi_db_data.

* Monitoramento: adicione a Health Check em docker-compose.yml ao
  evolution-api se desejar (fora do escopo Fase 1).

* Rotacao de chave API: EVOLUTION_API_KEY em docker/.env pode ser
  rotacionada apos certificar-se de que nenhum cliente antigo a usa.

* Logging: os logs do Evolution crescem; configure logrotate no
  servidor conforme necessario (logs do container vao a stdout).

* Escalabilidade: por enquanto, 1 numero pareado por instancia.
  Multiplas linhas requerem Fase 3+.


====================================================================
PROXIMOS PASSOS (FASE 2/3)
====================================================================

Fase 2 vai implementar:
  * Webhook de mensagens recebidas (evolution-api envia POST para Portal)
  * Worker que consome o webhook e registra em portal_wpp_mensagens
  * Validacao: apenas de grupos registrados ("TI · Alertas", etc.)

Fase 3 vai implementar:
  * Envio automatico de alertas/chamados para WhatsApp
  * Sincronizacao de status

ATÉ LÁ, NAO CONFIGURE WEBHOOK, NAO CRIE WORKER.

====================================================================
 FIM DO RUNBOOK
====================================================================
