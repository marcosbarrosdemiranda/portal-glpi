# Plano de Migração — XAMPP → Docker (mesmo servidor)

> **Status (2026-07-19):** **corte concluído.** XAMPP (Apache + MySQL) parado definitivamente; Docker é o ambiente real, servindo na porta **7412** (não 80 — reservado pro pfSense/DNS que será configurado depois). Ver seção 8 (Progresso). Ver [[MIGRACAO_PRODUCAO.md]] para o guia de migração de arquivos original (Linux genérico) — este documento é específico para sair do XAMPP e ir para Docker **na mesma máquina** (`192.168.1.198`, hostname `Backup-Arquifunc`).

---

## 1. Estado atual (levantado em 2026-07)

| Item | Valor hoje |
|---|---|
| Servidor | `192.168.1.198` (Windows, hostname `MARCOS`) |
| Stack | XAMPP (Apache + PHP 8.2.12 + MySQL/MariaDB) |
| GLPI | `C:\xampp\htdocs\glpi2` |
| Portal (este projeto) | `C:\xampp\htdocs\glpi2\portal-glpi` (dentro do próprio glpi2) |
| Banco | MySQL local, database `glpi2`, user `root`, sem senha |
| `GLPI_URL` | `http://localhost/glpi2` (também acessível via `http://192.168.1.198/glpi2`) |
| Tamanho do banco | ~15-20 GB (glpi_logs sozinho tinha ~9,8 GB antes da limpeza de retenção) |
| Pasta `files/` do GLPI | ~700 MB+ (anexos, prints de chamado — ver otimização de PNG já implementada) |
| Cron | Existe algum mecanismo funcionando (confirmado pelo `PurgeLogs` rodando semanalmente e pelos chamados recorrentes sendo gerados todo dia) — **local exato não identificado ainda**, precisa investigar antes da migração (ver seção 6) |
| Guacamole | Já roda em container na porta 8080 nessa mesma máquina — indício de que Docker já pode estar instalado no servidor |

---

## 2. Por que isso não é "trocar uma pasta"

O código PHP em si (toda a lógica de negócio do portal) **não muda**. O que muda são só pontos de configuração:

1. **Caminhos de arquivo absolutos.** `agenda/config.php` define `GLPI_ABSPATH = 'C:/xampp/htdocs/glpi2'` — usado pra ler anexos direto do disco (proxy de documentos). Dentro de um container Linux isso vira algo como `/var/www/html/glpi2`.
2. **Host do banco.** Hoje é `localhost` (mesma máquina). Em Docker Compose, PHP e MySQL normalmente são containers separados — o host passa a ser o nome do serviço no `docker-compose.yml` (ex: `db`), não mais `localhost`. Isso aparece em pelo menos dois lugares: `agenda/db.php` (conexão própria do portal) e o `config_db.php` do próprio GLPI (core, gerenciado pelo GLPI).
3. **Persistência de dados.** Containers são efêmeros por padrão — banco de dados e a pasta `files/` do GLPI **precisam** estar em volumes Docker nomeados (ou bind mounts), senão qualquer `docker compose down` apaga tudo.
4. **Cron.** Hoje depende de algum mecanismo no Windows (não totalmente mapeado — ver seção 6). Em Docker/Linux isso vira um `cron` de verdade dentro do container ou um serviço `docker-compose` dedicado só pra isso.
5. **Portas.** XAMPP hoje ocupa a porta 80 (Apache) e 3306 (MySQL) nessa máquina. Os containers Docker vão disputar as mesmas portas — não dá pra rodar os dois ao mesmo tempo nas mesmas portas (ver seção 5, estratégia de corte).

---

## 3. Arquitetura alvo (proposta)

```
docker-compose.yml
├── web        → PHP 8.2 + Apache (imagem custom, Dockerfile próprio)
│                 monta o código (GLPI + portal-glpi) via volume/bind mount
├── db         → MySQL/MariaDB (imagem oficial), volume nomeado pros dados
├── cron       → mesma imagem do "web", mas só roda o cron.php do GLPI em loop
└── (redes)    → rede interna Docker; web exposto na porta 80/443 do host
```

- **web**: Dockerfile baseado em `php:8.2-apache`, com as extensões que o GLPI/portal usam hoje (gd, pdo_mysql, mbstring, zip, curl, intl, etc. — checar `phpinfo()` do XAMPP atual pra listar todas antes de montar a imagem).
- **db**: `mariadb:10.11` (ou versão equivalente à do XAMPP atual, pra evitar surpresa de collation/engine na importação do dump).
- **cron**: evita depender de Task Scheduler do Windows — um container simples rodando `while true; do php /var/www/html/glpi2/front/cron.php; sleep 60; done` (ou `cron` de verdade com crontab).
- Volumes nomeados: `glpi_db_data` (MySQL) e bind mount pro código-fonte (pra continuar editando local + sincronizando como hoje).

---

## 4. Passo a passo da migração

### Preparação (sem impacto, pode ser feito com XAMPP rodando)
1. Confirmar todas as extensões PHP habilitadas no XAMPP atual (`phpinfo()`), pra replicar no Dockerfile.
2. Levantar a versão exata do MySQL/MariaDB do XAMPP (`SELECT VERSION()`).
3. Escrever o `Dockerfile` da imagem `web` e o `docker-compose.yml`.
4. Rodar os containers em **portas alternativas** (ex: 8081 em vez de 80, 3307 em vez de 3306) pra testar em paralelo sem derrubar o XAMPP.
5. Restaurar um dump do banco atual dentro do container de teste (`mysqldump` do XAMPP → `mysql` no container).
6. Copiar a pasta `files/` do GLPI pro volume do container de teste.
7. Ajustar `GLPI_ABSPATH`, host do banco (`agenda/db.php` + `config_db.php` do GLPI) só na cópia de teste.
8. Testar tudo funcionando nas portas alternativas: login, abertura de chamado, agenda, relatórios, anexos/imagens, API REST.

### Corte (janela de manutenção — precisa de indisponibilidade curta)
9. Aviso prévio pro time de TI sobre o horário do corte.
10. Backup final do banco (`mysqldump` completo) e da pasta `files/` do XAMPP em produção.
11. Parar o Apache/MySQL do XAMPP.
12. Subir os containers Docker já nas portas 80/3306 (as portas de produção, agora livres).
13. Restaurar o backup final (passo 10) no container — captura qualquer mudança feita entre o teste e o corte.
14. Testar rapidamente os fluxos críticos (login, abrir chamado, agenda).
15. Confirmar que o cron dentro do container está rodando (`PurgeLogs`, sync de rotinas).

### Pós-corte
16. Deixar o XAMPP antigo **desligado mas não desinstalado** por um tempo (rede de segurança pra rollback rápido).
17. Depois de alguns dias estável, desinstalar o XAMPP e liberar o espaço em disco.

---

## 5. Estratégia de corte (evitar downtime longo)

Como o XAMPP e os containers Docker vão disputar as mesmas portas (80 e 3306), a virada não dá pra ser gradual nessas portas — mas o trabalho pesado (testar tudo, validar dados) acontece **antes** do corte, em portas alternativas. O corte em si (passos 9-15 acima) deve ser rápido — a maior parte do tempo é o `mysqldump`/restore final, que depende do tamanho do banco (hoje ~15-20 GB pode levar de 10 a 30 minutos dependendo do disco).

Recomendo agendar isso fora do horário comercial.

---

## 6. Pontos em aberto — investigar antes de migrar

- ~~Onde está o cron de verdade rodando hoje~~ **Resolvido (2026-07-18):** Tarefa Agendada `\Automatizações Gmais\Cron` roda `C:\xampp\php\php.exe C:\xampp\htdocs\glpi2\front\cron.php` várias vezes ao dia. Existe também `\Backup\Glpi` (FreeFileSync espelhando `C:\xampp` inteiro pra `D:\Backup Glpi\Xampp` diariamente — não é cron do GLPI, é backup).
- ~~Se o Docker Desktop já está instalado~~ **Confirmado:** Docker Desktop 29.5.2 + Compose v5.1.4 já instalados. Guacamole roda via `docker run` direto (sem compose, sem bind mounts).
- ~~Versão exata do MySQL/MariaDB e extensões PHP~~ **Confirmado:** MariaDB **10.4.32**; PHP **8.2.12** com bz2, curl, gd, gettext, intl, mbstring, exif, mysqli, pdo_mysql, pdo_sqlite (zip **não** habilitado no XAMPP — adicionado no Dockerfile por segurança/compatibilidade com marketplace do GLPI, sem downside).
- ~~Certificado/HTTPS / config custom do Apache~~ **Confirmado:** `.htaccess` do GLPI é o padrão de instalação (rewrite da API comentado, não ativo). Sem vhost custom pra `glpi2`. Limites do `php.ini`: `memory_limit=512M`, `post_max_size=40M`, `upload_max_filesize=40M`, `max_execution_time=120` — replicados no `docker/php-custom.ini`.

---

## 8. Progresso (2026-07-18)

- **Acesso remoto:** OpenSSH Server habilitado no servidor (já estava, de trabalho anterior). Chave dedicada configurada, alias `ssh glpi-server` (ver memória `ssh-servidor-glpi`). Usuário `externo` (Administrador).
- **Limpeza de disco prévia (mesma sessão):** pasta `xampp` caiu de 100GB+ pra ~15GB (lixo de reparo do MariaDB + backup manual esquecido + log do Apache sem rotação — não relacionado ao Docker, mas destravou espaço em disco pra essa migração).
- **Armadilha resolvida — credential helper do Docker Desktop via SSH:** `docker pull`/`compose up` falhava com "sessão de logon não existe" porque o Docker Desktop força o `wincred` (Credential Manager), que exige sessão interativa. Corrigido com um credential helper "dummy" (`docker-credential-none.bat` em `resources\bin\`, `credsStore: "none"` no `config.json`) que responde "sem credenciais" e permite pull anônimo. Detalhes na memória `docker-credential-helper-ssh-windows`.
- **Portas escolhidas pro teste em paralelo:** `glpi-web` → **8091** (host `80`/`443` já são do XAMPP; `8080`/`8443`/`8843`/`8880` já são do Guacamole/outros). Banco `glpi-db` não exposto ao host (só rede interna do compose).
- **Estrutura no servidor:** `D:\docker\glpi-portal\` (movido de `C:\` a pedido — D: tem ~11TB livres vs ~123GB no C:). Cópia do código em `app\` (não bind mount da pasta viva do XAMPP — ver nota no `docker/docker-compose.yml`).
- **Config ajustado só na cópia de teste** (produção/XAMPP intocada):
  - `config/config_db.php`: `dbhost` `localhost`→`glpi-db`, senha `''`→`root_password`
  - `portal-glpi/agenda/db.php`: mesmo ajuste de host/senha
  - `portal-glpi/agenda/config.php`: `GLPI_ABSPATH` `C:/xampp/htdocs/glpi2`→`/var/www/html/glpi2` (`GLPI_URL` não precisou mudar — continua `http://localhost/glpi2`, resolve corretamente dentro do próprio container)
- **Banco:** dump completo (`mysqldump --single-transaction --routines --triggers --events`, 5,78GB) do `glpi2` de produção restaurado no container `glpi-db`.
- **Imagem `glpi-web`/`glpi-cron`:** buildada com sucesso (`php:8.2-apache` + extensões).
- **Regra confirmada com o usuário:** XAMPP **não é desligado** em nenhuma hipótese até tudo estar validado no Docker e ele dar a ordem explícita do corte.

## 9. Corte final (2026-07-19)

- Dump final (`glpi2_final.sql`, fresco, tirado minutos antes do corte) restaurado por cima do banco de teste — captura tudo que foi feito enquanto o XAMPP ainda estava no ar.
- XAMPP (Apache + MySQL, rodavam como processos do `xampp-control.exe`, não como serviço do Windows) parado pelo usuário.
- Configs da pasta **viva** (`C:\xampp\htdocs\glpi2`) ajustadas (mesmo ajuste antes feito só na cópia de teste): `config/config_db.php` e `portal-glpi/agenda/db.php` → host `glpi-db`/senha `root_password`; `portal-glpi/agenda/config.php` → `GLPI_ABSPATH` `/var/www/html/glpi2`.
- `docker-compose.yml` atualizado: bind mount trocado da cópia de teste pra pasta viva (`C:\xampp\htdocs\glpi2`) — o sync automático existente pro servidor continua funcionando sem nenhuma mudança. Porta trocada de 8091 pra **7412** (a pedido do usuário — 80 e 8091 já reservadas/usadas no pfSense; 7412 escolhida por não ter nenhum uso conhecido nesse servidor).
- Tarefa Agendada antiga do Windows (`\Automatizações Gmais\Cron`) **desativada** — o container `glpi-cron` agora é o único rodando `front/cron.php`.
- Containers recriados apontando pra config nova, testado HTTP 200 em GLPI e portal na porta 7412.
- XAMPP **não foi desinstalado** — continua no disco como rede de segurança pra rollback rápido (ver seção 4, passo 16).

Próximos passos: usuário validar o dia a dia (chamados, agenda, anexos, API) por alguns dias; se estável, desinstalar o XAMPP (seção 4, passo 17) e configurar DNS/proxy reverso no pfSense apontando pra porta 7412.

---

## 7. Estimativa

Não é um trabalho de um dia, mas também não é grande — a maior parte é configuração e migração de dados, não reescrita de código:

- Escrever Dockerfile + compose + testar em paralelo: **1-2 dias de trabalho**.
- Janela de corte em si: **30-60 minutos** de indisponibilidade, fora do horário comercial.
- Total, sem pressa: **pode ser feito em uma semana**, testando com calma antes do corte.
