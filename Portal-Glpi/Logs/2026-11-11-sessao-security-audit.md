---
date: 2026-11-11
status: achados-documentados
author: AI Agent
tags:
  - security
  - audit
  - log-sessao
---

# Log de Sessão: Auditoria de Segurança (repo completo, modo simples)

## Contexto e Objetivo

Usuário pediu uma security audit do repositório `portal-glpi` inteiro.
A skill `security-audit` tem um modo completo (multi-agente) que travou
numa tentativa anterior; o usuário optou pelo **modo simples**: revisão
direta de código por mim, sem subagentes, com o usuário decidindo
prioridade/ação em cada achado. Esta sessão cobre a revisão de código
(leitura, sem execução dinâmica — nenhum teste rodou contra produção) e
a consolidação dos achados em documentação + backlog.

Branch no momento da revisão: `feat/orcamento-projeto`
(commit `3524871fd843561af37314162b72a51e640e389c`).

Esta revisão **não é exaustiva** — ver seção "Não revisado ainda".

## Modelo de confiança (resumo)

Anônimo → self-service (requerente GLPI) → staff/técnico do portal →
`$is_admin` (`perfil` ∈ `admin`/`super-admin`/`tecnico`) → workers em
background (`wpp/worker.php`, `*_worker.php`, Tarefas Agendadas) com
credenciais completas de produção e sem identidade por request.

## Achados Confirmados

### 1. 🔴 CRÍTICO — Upload sem whitelist em `despesas.php` → execução de PHP arbitrário

- **Onde:** `despesas.php` (upload de comprovante de NF) grava o
  arquivo enviado em `uploads/despesas/` sem checar extensão/MIME.
- **Por que é crítico:** `docker/Dockerfile` (base `php:8.2-apache`,
  `a2enmod rewrite`) e `docker/nginx.conf` (proxy genérico pra
  `glpi-web:80`, sem regra específica bloqueando execução de PHP sob
  `/uploads/`) confirmam que um `.php` dentro de `uploads/despesas/` é
  **executável direto via HTTP**.
- **Quem explora:** qualquer usuário autenticado no portal — o gate do
  arquivo é só `autenticado`, sem exclusão de `self-service` (ao
  contrário de outras páginas de staff).
- **Resultado:** execução remota de código arbitrário no container
  `glpi-web`, com acesso à mesma rede/credenciais que o resto do portal.
- **Fix recomendado:** whitelist de extensão + MIME real (ex.: `pdf`,
  `jpg`, `jpeg`, `png`) verificada com `finfo`/`exif_imagetype`, nunca
  só pela extensão enviada pelo client; idealmente também renomear o
  arquivo salvo (UUID) e, se possível, servir `uploads/` por um path
  sem execução de PHP (fora do escopo de um fix cirúrgico).

### 2. 🟠 ALTO — `api.php`: criação de chamado anônima com credencial admin fixa

- **Onde:** `api.php` — endpoint sem nenhum `auth_guard`/sessão, cria
  chamado no GLPI usando credenciais de admin fixas no código.
- **Resultado:** qualquer chamador anônimo na rede cria chamados no
  GLPI como se fosse o admin do portal.
- **Em aberto:** não ficou confirmado se este endpoint é
  **intencionalmente público** (ex.: formulário externo, kiosk,
  integração de terceiro que não tem como autenticar) ou se é uma
  omissão. **Precisa confirmação do usuário antes de qualquer fix** —
  adicionar autenticação pode quebrar quem hoje chama isso sem sessão.

### 3. 🟠 ALTO — `sync.php`: `shell_exec('git pull...')` sem autenticação

- **Onde:** `sync.php` — dispara `git pull` (e bootstrap) no servidor
  via request HTTP anônimo, sem nenhum gate.
- **Resultado:** qualquer chamador anônimo força uma atualização de
  código em produção a qualquer momento; se o remote/branch puder ser
  influenciado por alguém (ou só pelo efeito de disponibilidade de
  forçar um pull/restart fora de hora), isso é uma ação não-autenticada
  num plano de controle do serviço.
- **Em aberto:** não ficou confirmado **como esse endpoint é chamado
  hoje** (manual pelo usuário no navegador após um push? webhook do
  GitHub? nada mais usa?). **Precisa essa resposta antes de adicionar
  um segredo**, pra não quebrar o fluxo real de deploy caso exista um.

### 4. 🟠 ALTO — `anydesk_central.php` / `vnc_central.php`: ações sensíveis sem checagem de admin no servidor

- **Onde:** `anydesk_central.php` define `$is_admin` (linha 9, mesma
  fórmula de `pfsense_proxy.php`/`rdp_central.php`) mas só usa pra
  **esconder botões no HTML/JS** (linhas 532/576) — nenhuma ação do
  dispatcher (`add`, `edit`, `delete`, `bat`, `get_password`, `batch`,
  `save_grupo`, `delete_grupo`) checa `$is_admin` no servidor.
  `vnc_central.php` tem a mesma fórmula na linha 9 e pelo menos a ação
  `delete` (linha 132) sem guarda visível — não li o dispatcher inteiro
  deste segundo arquivo linha a linha.
- **Comparação correta:** `pfsense_proxy.php` (linha 249) e
  `rdp_central.php` (linha 199) fazem exatamente essa checagem antes da
  ação sensível — é o padrão já usado em arquivos-irmãos, só não foi
  replicado aqui.
- **Resultado:** qualquer staff autenticado (não precisa ser admin)
  pode pegar a senha em texto puro de qualquer máquina AnyDesk/VNC
  cadastrada (`get_password`, `bat` — o `.bat` gerado embute a senha
  decriptada na linha de comando do AnyDesk.exe), ou apagar/editar
  cadastros, sem ser admin.
- **Fix recomendado:** replicar literalmente o padrão de
  `rdp_central.php`/`pfsense_proxy.php` — `if (!$is_admin) { echo
  json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }` antes de
  cada ação sensível (no mínimo `get_password`, `bat`, `delete`,
  `delete_grupo`; avaliar se `add`/`edit`/`save_grupo` também devem
  ser admin-only, já que o botão já fica escondido do não-admin no
  client hoje).

### 5. 🟠 ALTO — `chamado_ajax.php` / `glpi_doc_proxy.php`: IDOR em chamado e documento por ID

- **Onde:** `chamado_ajax.php` (detalhe de chamado) e
  `glpi_doc_proxy.php` (conteúdo de anexo) só checam
  `$_SESSION['autenticado']` — nenhum dos dois confirma que o chamado/
  documento pertence ao usuário da sessão (nem que o usuário é staff).
  Ambos usam a credencial de serviço (`GLPI_USER`/`GLPI_PASS`/
  `GLPI_APP_TOKEN`) pra buscar o recurso no GLPI e devolvem o conteúdo
  completo pra qualquer sessão autenticada.
- **Resultado:** um usuário self-service (requerente comum) consegue
  ler qualquer chamado de qualquer outra entidade/loja e baixar
  qualquer anexo só incrementando `id`/`docid` na URL.
- **Fix recomendado:** antes de devolver o recurso, confirmar que
  `$_SESSION['perfil']` é staff (`admin`/`super-admin`/`tecnico`/
  `portal` com permissão), OU, se for self-service, que o
  `users_id_recipient`/entidade do chamado/documento bate com o
  usuário da sessão. É uma mudança sensível a teste — self-service
  genuinamente precisa continuar vendo os próprios chamados.

## Já Conhecido / Adiado (não é achado novo)

- **Senha root do MySQL hardcoded e commitada** em 5 arquivos
  (`agenda/db.php`, `auth_guard.php`, `pendencias.php`,
  `docker/docker-compose.yml`, `docker/scripts/backup-db.ps1`) — já
  documentado em `backlog_senha-db-exposta-repo-publico.md` (memória),
  ação adiada por decisão do usuário.

## Achados de Severidade Menor / Débito Técnico

### 6. 🟡 MÉDIO — `vault_crypto.php`: chave global não-rotacionável reusada em 3 arquivos

- `VAULT_KEY = sha256(GLPI_APP_TOKEN . 'cofre_ti_gmais')`, AES-256-CBC
  sem HMAC, o mesmo bloco de código copiado (não compartilhado) em
  `vault_crypto.php`, `anydesk_central.php` (`rdp_encrypt`/`rdp_decrypt`)
  e `rdp_central.php` — mesma fórmula, 3 implementações independentes.
- Nenhum ACL por segredo individual — qualquer código que leia a tabela
  certa decripta qualquer segredo guardado.
- **Fix não é trivial:** rotacionar a chave ou adicionar HMAC quebra a
  decriptação de **todos** os segredos já guardados (RDP/VNC/AnyDesk/
  pfSense/GitHub) sem uma migração explícita (re-encriptar tudo com a
  chave nova). Não é um fix cirúrgico de uma linha.

### 7. 🟡 MÉDIO — `github_cache_worker.php`: web-reachable sem guarda

- Script single-pass pensado pra rodar em loop CLI (ver comentários já
  existentes em `projetos.php:722` e `github_cache.php:60`: "CLI, sem a
  guarda de sessão HTTP") — o serviço docker-compose que deveria
  envolver esse loop (`github-cache-worker`, `sleep 600`) está **só na
  PR #10, ainda aberta, não mergeada** (confirmado: `docker-compose.yml`
  atual não tem esse serviço, `gh pr view 10` → `state: OPEN`).
- Sem esse service e sem guarda no próprio arquivo, `github_cache_worker.php`
  fica como um `.php` solto no webroot: qualquer request HTTP anônimo
  decripta o token de **todas** as contas GitHub cadastradas (via
  `vault_decrypt()`) e gasta cota da API do GitHub a cada hit.
- **Fix recomendado:** guarda `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }`
  no topo do arquivo — isso já é o comportamento que o próprio código
  assume que existe (comentários citados acima), só nunca foi
  implementado.

## Descartado (não é achado)

- **`auth_guard.php` remapeia `self-service` → `'portal'`**: investigado
  a fundo — é o efeito **intencional** de `perfis.php` (admin atribui um
  perfil de dashboard pra um usuário self-service). Confirmado que isso
  nunca concede `$is_admin` (que exige `perfil` literal
  `admin`/`super-admin`/`tecnico`). Não é escalação de privilégio.
- **`compartilhar.php`** (link público do Cofre TI): token validado por
  lookup exato, expiração e `max_views` checados, cada acesso logado em
  `portal_vault_audit`. Bem implementado; só uma TOCTOU de baixo impacto
  no incremento de `views` (check-then-increment não-atômico), não vale
  como achado.
- **Ausência de CSRF em mutações autenticadas por cookie** (repo-wide) e
  **ausência de `SameSite` explícito no cookie de sessão**: confirmado
  que não existe nenhuma proteção CSRF nas rotas do próprio portal (só
  existe `_glpi_csrf_token`, que é do GLPI, usado em requests *saindo*
  do portal pra dentro do GLPI/pfSense). Fica registrado como débito
  técnico, não como item a priorizar agora — mudaria autenticação da
  sessão em todas as páginas e exigiria revisão ampla.

## Não Revisado Ainda (lacunas de cobertura)

- `agenda/*.php` (27 arquivos) — camada de ação da Agenda (CRUD de
  chamado/calendário/upload) não foi revisada com o mesmo nível de
  detalhe do restante.
- `relatorios_impressoes.php` vs `relatorios_dados.php` — achado
  preliminar (não verificado a fundo) de que o primeiro não tem a
  mesma checagem de sessão do segundo.
- `is_admin` usado em `inventario_redes.php`, `inventario_impressoras.php`,
  `acessos.php`, `ferramentas_gmais.php`, `agenda/users.php` — grep
  encontrou o padrão nesses 5 arquivos, mas não confirmei se é
  **enforced** no servidor ou só cosmético (como era o caso de
  `anydesk_central.php` antes desta auditoria).
- Arquivos de webhook (`wpp/webhook.php`, `webhook_solides.php`,
  `webhook_backup.php`) — vistos de passagem (confirmados usando
  `hash_equals()` com segredo compartilhado, padrão correto), sem
  revisão linha a linha.
- Varredura de SQLi em todo o repo — não feita de forma sistemática
  (confiança atual vem de ter visto PDO preparado/`(int)` cast nos
  arquivos lidos, não de uma varredura completa).
- `vnc_central.php` — só o dispatcher de `anydesk_central.php` foi lido
  por completo; `vnc_central.php` foi só grepado.

## Documentação e Tarefas Geradas

- Achados 1-5 (crítico/alto) + 6-7 (médio) adicionados ao backlog ativo
  em `Portal-Glpi/Tarefas/backlog.md`, seção "🔐 Segurança".
- Este log é a referência de detalhe linkada pelo backlog.

## Atualização 2026-11-11 (mesma sessão) — Achados #1, #4, #7 corrigidos

Os 3 fixes de baixo risco foram implementados, mergeados (PRs #25,
#26, #27 em `feat/orcamento-projeto`) e deployados em produção
(`scp` → `.new` → `php -l` no container `glpi-web` → `Move-Item`),
com verificação pós-deploy (tamanho de arquivo batendo, sem erro novo
em `docker logs`, `github_cache_worker.php` testado rodando via CLI
normalmente).

- **#1 `despesas.php`:** whitelist de extensão (`pdf`/`jpg`/`jpeg`/`png`)
  + MIME real via `finfo_file()` antes de `move_uploaded_file()`.
- **#4 `anydesk_central.php`/`vnc_central.php`:** gate server-side
  `if (!$is_admin) { ... exit; }` em `add`/`edit`/`delete`/
  `save_grupo`/`delete_grupo` (+ `bat`/`get_password` só no anydesk),
  replicando o padrão de `rdp_central.php`/`pfsense_proxy.php`.
  **Achado lateral confirmado por grep:** `$_SESSION['perfil']` (via
  `auth.php::glpi_login()`) só produz `'tecnico'` ou `'self-service'`
  — `'admin'`/`'super-admin'` nunca são atribuídos em lugar nenhum do
  código (mesma fórmula de `$is_admin` repetida em 8 arquivos). Ou
  seja, o gate não distingue "admin" de "técnico comum" — distingue
  staff real de self-service (cru ou remapeado pra `'portal'`). O
  risco de regressão pra técnico comum, levantado antes do fix, não se
  confirmou.
- **#7 `github_cache_worker.php`:** guarda `php_sapi_name() !== 'cli'`.

## Próximos Passos

1. Usuário confirma: `api.php` é intencionalmente público? Como
   `sync.php` é disparado hoje? (bloqueia o fix dos achados #2 e #3)
2. ~~Aplicar os 3 fixes de baixo risco já identificados (achados #1, #4,
   #7)~~ — **feito, ver "Atualização 2026-11-11" acima.**
3. Desenhar com cuidado o fix de IDOR (#5) — requer checagem de
   ownership, teste manual (sem rodar a suíte completa contra produção,
   ver `feedback_suite-testes-producao` na memória).
4. Decidir se vale abrir uma spec de migração de chave pro achado #6
   (`vault_crypto.php`) — não é urgente, é estrutural.
5. Cobrir as lacunas listadas em "Não Revisado Ainda" numa próxima
   sessão de auditoria.
