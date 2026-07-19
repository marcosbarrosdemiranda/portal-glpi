# Log de Sessão — 18/07/2026
## Limpeza de disco do servidor + início da migração XAMPP → Docker

---

## 1. Limpeza de disco (servidor 192.168.1.198)

**Problema:** pasta `xampp` com mais de 100GB.

**Causa raiz:**
- `mysql/data/mysql/*.BAK` (53GB) — sobras de reparo automático do engine Aria/MariaDB, não usadas pelo MySQL em funcionamento
- `mysql/data - Copia/` (26GB) — backup manual completo esquecido
- `apache/logs/access.log` (12,5GB) — nunca rotacionado

**Correção:**
- Removidos os `.BAK` e a pasta de cópia
- `access.log` truncado e `httpd.conf` configurado com `rotatelogs.exe` (rotação diária) pra `error.log` e `access.log`
- Resultado: pasta caiu de 100GB+ pra ~15GB após reiniciar o Apache

---

## 2. Início da migração Docker (paralelo ao XAMPP)

**Objetivo:** sair do XAMPP pro Docker, no mesmo servidor, sem downtime até o corte final ser explicitamente autorizado.

### Acesso remoto
- SSH habilitado no servidor (usuário `externo`, Administrador). Chave dedicada criada, alias `ssh glpi-server` configurado localmente.
- Docker Desktop 29.5.2 + Compose v5.1.4 já instalados (confirmado — presença do Guacamole em container já indicava isso).

### Armadilha: Docker Desktop + SSH
`docker pull`/`compose up` falhava com "sessão de logon não existe" — o Docker Desktop força o credential helper `wincred`, que precisa de sessão interativa (DPAPI), indisponível via SSH. Resolvido criando um credential helper "dummy" (`docker-credential-none.bat`) que responde "sem credenciais" pro Docker seguir com pull anônimo.

### Levantamento do ambiente atual
- PHP 8.2.12, extensões: bz2, curl, gd, gettext, intl, mbstring, exif, mysqli, pdo_mysql, pdo_sqlite (zip não habilitado — adicionado no Docker)
- MariaDB 10.4.32
- Cron real identificado: Tarefa Agendada `\Automatizações Gmais\Cron` roda `front/cron.php` do GLPI várias vezes ao dia
- Tarefa `\Backup\Glpi` faz backup diário (FreeFileSync espelhando `C:\xampp` inteiro pra `D:\Backup Glpi\Xampp`) — não relacionado ao cron do GLPI
- Achado um `config_db_prod.php` já preparado de uma tentativa anterior (host `glpi-db`, senha `root_password`) — convenção reaproveitada

### Estrutura criada
- `D:\docker\glpi-portal\` (movido de C: pra D: por espaço em disco)
- `app\` — cópia do `htdocs/glpi2` (código GLPI + portal-glpi), **não** bind mount da pasta viva do XAMPP (config_db.php só pode apontar pra um host de banco por vez)
- Config ajustado só nessa cópia: `config_db.php` e `agenda/db.php` apontando pro host `glpi-db`; `GLPI_ABSPATH` ajustado pro path do container
- Dump completo do `glpi2` (5,78GB) restaurado no container `glpi-db` (MariaDB 10.4)
- Imagem `glpi-web`/`glpi-cron` (PHP 8.2 + Apache) buildada
- Porta de teste: **8091** (80/443 são do XAMPP; 8080/8443/8843/8880 já são do Guacamole)

### Arquivos trazidos pro repositório
- `docker/Dockerfile`, `docker/docker-compose.yml`, `docker/php-custom.ini`
- `Portal-Glpi/Arquitetura/PLANO_MIGRACAO_DOCKER.md` atualizado com status e seção de progresso

### Regra confirmada com o usuário
XAMPP **não é desligado** até tudo estar validado no Docker e ordem explícita de corte ser dada.

## Próximos passos
- Subir `glpi-web` na porta 8091 e testar (login, chamados, agenda, anexos, API)
- Só depois planejar a janela de corte definitiva

---

## 3. Corte final (19/07/2026)

Usuário validou o teste na porta 8091 e decidiu cortar direto pra produção na mesma sessão.

- **Porta final: 7412** (não 80 — reservada pro pfSense/DNS que será configurado depois; 8091 também já em uso no pfSense)
- Dump final fresco tirado (`glpi2_final.sql`) antes do XAMPP parar, pra não perder nada feito durante o teste
- Usuário parou Apache + MySQL do XAMPP (processos do `xampp-control.exe`, não serviço do Windows)
- Config da pasta **viva** (`C:\xampp\htdocs\glpi2`) ajustada (mesmo ajuste da cópia de teste): `config_db.php`, `agenda/db.php` → `glpi-db`/`root_password`; `agenda/config.php` → `GLPI_ABSPATH` `/var/www/html/glpi2`
- `docker-compose.yml`: bind mount trocado da cópia de teste pra pasta viva (sync automático existente continua funcionando sem mudança) + porta 8091→7412
- Dump final restaurado por cima do banco de teste
- Tarefa Agendada antiga do cron (`\Automatizações Gmais\Cron`) desativada — `glpi-cron` (container) é o único rodando `front/cron.php` agora
- Testado HTTP 200 em GLPI e portal na porta 7412
- XAMPP **não foi desinstalado** — fica como rede de segurança por alguns dias

### Acesso atual
- GLPI: `http://192.168.1.198:7412/glpi2/`
- Portal: `http://192.168.1.198:7412/glpi2/portal-glpi/`

## Próximos passos (atualizados)
- Validar uso do dia a dia por alguns dias
- Se estável, desinstalar XAMPP e configurar DNS/proxy reverso no pfSense apontando pra porta 7412
