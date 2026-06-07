# Apache Guacamole — Gateway RDP via Browser

## Visão Geral

O Apache Guacamole transforma qualquer protocolo remoto (RDP, VNC, SSH) em HTML5.
O usuário acessa o desktop remoto **dentro do navegador**, sem instalar nada no PC.

### Como funciona no Portal TI

```
┌─────────────────────────────┐
│   Navegador (Chrome/Edge)   │  ← Usuário abre o portal
│   Portal TI / Central RDP   │     clica "Conectar"
└──────────┬──────────────────┘
           │ HTTPS (mesma URL do portal)
           ▼
┌─────────────────────────────┐
│   Servidor do Portal        │  ← Mesma máquina do GLPI
│   ├── XAMPP (Apache/PHP)    │     faz redirect com token
│   ├── Tomcat (porta 8080)   │     recebe o token
│   └── guacd (porta 4822)    │     conecta RDP na máquina alvo
└──────────┬──────────────────┘
           │ RDP (protocolo nativo)
           ▼
┌─────────────────────────────┐
│   Máquina Alvo              │  ← TS-Marcos, servidores, etc.
│   (192.168.1.x)             │     Só precisa ter RDP ligado
└─────────────────────────────┘
```

## O que precisa ser instalado no servidor do Portal

### Componentes

| Componente | Função | Onde roda | Porta |
|-----------|--------|-----------|-------|
| **XAMPP** (já tem) | Apache + PHP + MySQL | Servidor Portal | 80/443 |
| **Docker Desktop** | Containeriza o Guacamole | Servidor Portal | — |
| **guacamole/guacd** | Proxy RDP (container) | Docker | 4822 |
| **guacamole/guacamole** | Interface web (container) | Docker | 8080 |
| **MySQL** (compartilhado) | Config dos connections | Docker ou XAMPP | 3306 |

### Pré-requisitos do servidor

- Windows 10/11 Pro ou Server 2019+
- 4 GB RAM livre (Docker + Guacamole)
- Virtualização habilitada na BIOS (VT-x/AMD-V)
- Acesso administrativo no Windows

---

## Rota A — Docker Desktop (RECOMENDADO)

Mais fácil de instalar, migrar e manter.

### Passo 1: Instalar Docker Desktop

1. Baixar de https://docs.docker.com/desktop/setup/install/windows-install/
2. Executar instalador — marcar "Use WSL 2 instead of Hyper-V"
3. Finalizar e reiniciar o PC
4. Abrir Docker Desktop → esperar status "Running"

### Passo 2: Criar rede Docker

```powershell
docker network create guac-network
```

### Passo 3: Subir MySQL para o Guacamole

```powershell
docker run --name guac-mysql ^
  --network guac-network ^
  -e MYSQL_ROOT_PASSWORD=guacamole ^
  -e MYSQL_DATABASE=guacamole_db ^
  -e MYSQL_USER=guacamole ^
  -e MYSQL_PASSWORD=guacamole ^
  -v guac-mysql-data:/var/lib/mysql ^
  -d mysql:8
```

Aguardar 10 segundos, depois inicializar o banco:

```powershell
docker run --rm guacamole/guacamole ^
  /opt/guacamole/bin/initdb.sh --mysql ^
  > C:\xampp\htdocs\portal-glpi\Docs\guacamole-init.sql

docker cp C:\xampp\htdocs\portal-glpi\Docs\guacamole-init.sql guac-mysql:/init.sql

docker exec -i guac-mysql mysql -u root -pguacamole guacamole_db < init.sql
```

### Passo 4: Subir guacd (proxy RDP)

```powershell
docker run --name guacd ^
  --network guac-network ^
  -d guacamole/guacd
```

### Passo 5: Subir Guacamole web

```powershell
docker run --name guacamole ^
  --network guac-network ^
  -e MYSQL_HOSTNAME=guac-mysql ^
  -e MYSQL_DATABASE=guacamole_db ^
  -e MYSQL_USER=guacamole ^
  -e MYSQL_PASSWORD=guacamole ^
  -p 8080:8080 ^
  -d guacamole/guacamole
```

### Passo 6: Verificar

Abrir http://servidor-portal:8080/guacamole

Login padrão: `guacadmin` / `guacadmin`

---

## Rota B — Instalação nativa (sem Docker)

> A rota nativa no Windows é mais complexa. O guacd não tem binário oficial para Windows.
> **Alternativa:** Usar WSL2 (Windows Subsystem for Linux) para rodar guacd.

### Passo 1: Instalar Tomcat

1. Baixar Tomcat 10 de https://tomcat.apache.org/
2. Extrair para `C:\xampp\tomcat`
3. Configurar porta 8080 no `C:\xampp\tomcat\conf\server.xml`

### Passo 2: Instalar WSL2 + guacd

```powershell
wsl --install -d Ubuntu
```

Dentro do WSL Ubuntu:
```bash
sudo apt update
sudo apt install -y build-essential libcairo2-dev libjpeg-turbo8-dev libpng-dev \
  libossp-uuid-dev libavcodec-dev libavutil-dev libswscale-dev libfreerdp-dev \
  libpango1.0-dev libssh2-1-dev libtelnet-dev libvncserver-dev libpulse-dev \
  libssl-dev libvorbis-dev libwebp-dev

wget https://apache.org/dyn/closer.lua/guacamole/1.5.5/source/guacamole-server-1.5.5.tar.gz
tar -xzf guacamole-server-1.5.5.tar.gz
cd guacamole-server-1.5.5
./configure --with-init-dir=/etc/init.d
make
sudo make install
sudo ldconfig
sudo /etc/init.d/guacd start
```

### Passo 3: Deploy Guacamole no Tomcat

1. Baixar `guacamole-1.5.5.war` de https://guacamole.apache.org/
2. Copiar para `C:\xampp\tomcat\webapps\guacamole.war`
3. Criar `C:\xampp\tomcat\conf\Catalina\localhost\guacamole.xml`
4. Configurar `guacamole.properties`

---

## Integração com o Portal TI

Depois do Guacamole instalado, o portal precisa de 2 coisas:

### 1. Configuração no config.php

```php
// Em agenda/config.php
define('GUACAMOLE_URL', 'http://localhost:8080/guacamole');
define('GUACAMOLE_USER', 'guacadmin');
define('GUACAMOLE_PASS', 'guacadmin');
```

### 2. Tabela de conexões no banco

O Guacamole já tem tabelas próprias no banco `guacamole_db`. As conexões RDP são registradas lá (connection_name, hostname, port, username, password).

### 3. Auto-login via REST API

O portal usa a REST API do Guacamole para gerar um token de acesso:

```
POST /guacamole/api/tokens
  → username + password
  → retorna authToken

POST /guacamole/api/session/data/mysql/connections
  → cria connection se não existir

GET /guacamole/#/client/ID_CONEXAO?token=AUTH_TOKEN
  → redirect do navegador → RDP aberto!
```

Esse fluxo será implementado em `rdp_central.php` depois do Guacamole instalado.

---

## Migração do Servidor

Para migrar o servidor do portal (incluindo Guacamole):

### Docker (recomendado)

```powershell
# 1. Parar containers no servidor antigo
docker stop guacamole guacd guac-mysql

# 2. Exportar volumes
docker run --rm -v guac-mysql-data:/data -v %CD%:/backup alpine tar czf /backup/guac-mysql.tar.gz -C /data .

# 3. Copiar o backup .tar.gz pro novo servidor

# 4. No novo servidor, restaurar
docker run --rm -v guac-mysql-data:/data -v %CD%:/backup alpine sh -c "rm -rf /data/* /data/..?* /data/.[!.]* && tar xzf /backup/guac-mysql.tar.gz -C /data"

# 5. Recriar containers (mesmos comandos da instalação)
docker network create guac-network
docker run --name guac-mysql ... (mesmo comando do passo 3)
docker run --name guacd ...
docker run --name guacamole ...
```

### Migração completa do servidor

Lista de tudo que precisa ser migrado:

| Item | Localização | Backup |
|------|------------|--------|
| Código do portal | `C:\xampp\htdocs\portal-glpi\` | Git (clone) |
| Banco GLPI | MySQL | mysqldump |
| Config | `agenda/config.php` | Cópia manual |
| Docker volumes | `guac-mysql-data` | docker export |
| Docker Desktop | Instalar no novo servidor | Baixar novamente |

---

## Arquivos modificados/afetados

| Arquivo | O que muda |
|---------|-----------|
| `agenda/config.php` | + constantes GUACAMOLE_URL, GUACAMOLE_USER, GUACAMOLE_PASS |
| `rdp_central.php` | Botão "Conectar" fará redirect Guacamole em vez de download |
| `Portal-Glpi/Logs/` | Log da instalação |

---

## Verificação pós-instalação

- [ ] http://localhost:8080/guacamole abre e faz login
- [ ] Criar connection manual: nome=TS-Marcos, host=192.168.1.116, protocol=RDP
- [ ] Conectar pelo Guacamole direto — deve funcionar
- [ ] `rdp_central.php` mostra botão "Conectar" que redireciona pro Guacamole

---

## Links úteis

- Documentação oficial: https://guacamole.apache.org/doc/gug/
- Docker images: https://hub.docker.com/r/guacamole/guacamole
- REST API: https://guacamole.apache.org/doc/gug/guacamole-protocol.html
