---
date: 2026-06-07
status: concluida
author: Claude
tags:
  - rdp
  - acesso-remoto
  - guacamole
  - infraestrutura
  - api
---

# Log de Sessão: Central RDP — Guacamole + CRUD Completo

## Objetivo
Criar Central RDP integrada ao Apache Guacamole para acesso RDP no browser, com sincronia automática entre portal e Guacamole via API REST.

## Realizado

### Criação: `rdp_central.php`
- **Tabela `portal_rdp_maquinas`**: (id, nome, ip, descricao, usuario, senha criptografada, categoria ENUM servidor/coletor/pc, guac_id, ativo, ordem)
- **Lista agrupada por categoria** com **acordeão** (fechado por padrão): Servidores (azul), Coletores (verde), PCs Estratégicos (roxo)
- **Filtro por categoria**: pills na toolbar com contagem
- **Integração automática com Guacamole**:
  - **Adicionar**: busca conexão por nome no Guacamole — se existir, atualiza parâmetros (PUT); se não, cria nova (POST)
  - **Editar**: mesma lógica — search-by-name + update-or-create
  - **Excluir**: DELETE no Guacamole via API antes de remover do banco
  - **Payload padronizado** via closure `$guac_payload()` reutilizado em POST e PUT
- **Botão "Conectar"** verde (Guacamole) ou cinza (.rdp/.bat fallback quando sem guac_id)
- **Criptografia AES-256-CBC** para senhas armazenadas (VAULT_KEY derivada de GLPI_APP_TOKEN)
- **CRUD admin**: modal Bootstrap para adicionar/editar/excluir máquinas
- **Ordem configurável**: campo `ordem` + endpoint batch
- **Limpar tudo** (`limpar_rdp.php`): limpa banco + remove todas conexões do Guacamole

### Criação: `guacamole_conectar.php`
- Página wrapper com **top bar** estilizada (gradiente azul) com nome/IP da máquina e links de navegação
- **Iframe** fullscreen carregando a URL do Guacamole com token de autenticação
- Login na API Guacamole (token) + montagem do clientId: `base64_encode(id + "\0" + 'c' + "\0" + 'mysql')`

### Melhorias e Fixes
- **Root cause HTTP 400**: A API do Guacamole rejeita POST para nome duplicado. Solução: buscar por nome primeiro, atualizar se existir
- **Preservação de hash URL**: PHP `Location:` perde `#/client/...` — substituído por JavaScript `location.href`
- **ClientId correto**: descoberto que `base64_encode(id + "\0" + 'c' + "\0" + 'mysql')` é o formato correto (com "c" de connection type)
- **Token de autenticação** incluído na URL (`?token=...`) para evitar redirect sem auth

### Arquivos removidos
- `fix_guac.php`, `guac_api.php`, `test_criar_guac.php`, `test_clientid.php` — diagnósticos não mais necessários

## Detalhes Técnicos

### Fluxo de criação de conexão Guacamole
1. Usuário cadastra máquina com nome/IP/usuário/senha, deixa "ID Guacamole" em 0
2. PHP faz login na API: `POST /api/tokens` → obtém `authToken`
3. Busca conexões existentes: `GET /api/session/data/mysql/connections`
4. Se encontrou pelo nome: `PUT` para atualizar parâmetros
5. Se não: `POST` para criar nova conexão RDP
6. `guac_id` salvo na tabela para referência futura

### API Guacamole utilizada
- `POST /api/tokens` — autenticação (form-urlencoded)
- `GET /api/session/data/{ds}/connections` — listar conexões
- `POST /api/session/data/{ds}/connections` — criar conexão
- `PUT /api/session/data/{ds}/connections/{id}` — atualizar conexão
- `DELETE /api/session/data/{ds}/connections/{id}` — remover conexão

## Pendente
- [ ] Status online/offline com ping (igual ao inventário)
- [ ] Grupos de Conexão no Guacamole para melhor organização
