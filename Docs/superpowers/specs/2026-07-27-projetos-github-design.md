# Projetos via GitHub — contas pessoais, status manual e 3 seções

**Data:** 2026-07-27
**Status:** Aprovado para implementação

## Contexto

O módulo Projetos (`projetos.php`) hoje lê arquivos `.md` de uma pasta de
rede (`\\192.168.1.51\arquifunc\Ti\PROJETOS E DOCUMENTAÇÕES`), sincronizada
manualmente para `Docs/wiki/projects/`. Só aparecem 3 projetos porque o
sync está parado há semanas, e depender de acesso SMB a uma pasta de rede
mostrou-se frágil (problemas de sessão/credencial no servidor Docker, ver
sessão de debug anterior).

Nova abordagem: cada programador cadastra sua(s) própria(s) conta(s)
GitHub (usuário + token) no próprio portal; os projetos passam a ser os
repositórios dessas contas, buscados ao vivo via API do GitHub (HTTPS,
sem dependência de rede local). Cada projeto ganha um status manual
(Futuro / Em Execução / Concluído), exibido em 3 seções.

Os 3 projetos atuais (baseados na pasta de rede) ficam de fora dessa nova
visão por enquanto — o código que os carrega permanece intacto e
inalterado (não é removido), só não é mais o que popula a tela principal
de Projetos. Podem voltar a ser integrados depois, em spec separada, se
fizer sentido.

## Componentes

### 1. Biblioteca `github_client.php` (novo arquivo, sem HTML)

Funções puras de acesso à API do GitHub (`api.github.com`), sem nenhuma
lógica de página — só HTTP + parsing:

```php
function github_testar_token(string $token): array
// GET /user com o token. Retorna ['ok'=>bool, 'login'=>string|null, 'msg'=>string]
// 'ok'=false com 'msg' explicando (token inválido, expirado, sem permissão).

function github_listar_repos(string $token): array
// GET /user/repos?affiliation=owner&per_page=100&sort=pushed (pagina se houver mais de 100)
// Retorna lista de repos próprios (não-fork) com:
//   nome, descricao, url (html_url), privado (bool), linguagem,
//   ultimo_push (pushed_at), issues_abertas (open_issues_count), arquivado (archived)
// Em caso de erro (token revogado, rate limit, rede), retorna
// ['erro' => 'mensagem'] em vez de lançar exceção — quem chama decide
// como exibir (ver seção 3, tolerância a falha por conta).
```

Todas as chamadas usam `Authorization: Bearer <token>`,
`Accept: application/vnd.github+json` e um `User-Agent` fixo (a API do
GitHub exige User-Agent, senão rejeita a requisição). Timeout curto
(8s) via cURL para não travar a página inteira se a API estiver lenta.

### 2. Tabela `portal_github_contas`

Uma conta GitHub cadastrada por um técnico. Token guardado criptografado
com o mesmo esquema já usado pelo Cofre TI (`vault_crypto.php` —
`vault_encrypt()`/`vault_decrypt()`, chave derivada de `GLPI_APP_TOKEN`).

```sql
CREATE TABLE IF NOT EXISTS portal_github_contas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT           NOT NULL,
    apelido       VARCHAR(60)   NOT NULL,
    usuario_github VARCHAR(100) NOT NULL,
    token_enc     TEXT          NOT NULL,
    ativo         TINYINT(1)    DEFAULT 1,
    ultimo_teste_ok TINYINT(1)  DEFAULT NULL,
    ultima_verificacao DATETIME DEFAULT NULL,
    criado_em     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Cada usuário só vê e gerencia as próprias contas (`WHERE user_id = ?`,
sessão via `$_SESSION['user_id']` — mesmo padrão de `auth_guard.php`
usado em todo o portal). Diferente do Cofre TI, isso NÃO é
compartilhado entre a equipe.

### 3. Tabela `portal_projetos_status`

Guarda o status manual de cada repositório (o GitHub não tem esse
conceito nativamente).

```sql
CREATE TABLE IF NOT EXISTS portal_projetos_status (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    conta_id      INT           NOT NULL,
    repo_nome     VARCHAR(255)  NOT NULL,
    status        ENUM('futuro','em_execucao','concluido') NOT NULL DEFAULT 'em_execucao',
    atualizado_em TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_conta_repo (conta_id, repo_nome),
    FOREIGN KEY (conta_id) REFERENCES portal_github_contas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Quando um repositório aparece pela primeira vez (sem linha nessa
tabela), o status exibido é `em_execucao` (default), mas só é
persistido no banco no momento em que o usuário efetivamente troca o
status pela primeira vez (evita gravar uma linha pra cada repo só de
visualizar a lista) — na leitura, ausência de linha = trata como
`em_execucao` em memória.

### 4. `projetos.php` — painel "Minhas Contas GitHub"

Novo bloco no topo da página (mesmo estilo visual de card usado em
`acessos.php`/`ferramentas_gmais.php`): lista as contas GitHub do
usuário logado, com um card "+ Adicionar" que abre modal (apelido,
usuário GitHub, token — input tipo password). Ao salvar, chama
`github_testar_token()` antes de gravar; se falhar, mostra o erro no
modal e não salva. Cada conta cadastrada mostra um indicador (ok / erro
de autenticação) baseado em `ultimo_teste_ok`.

Engrenagem por conta (editar apelido/token, re-testar) e exclusão —
mesmo padrão de UI já usado nas outras telas administráveis do portal.

Endpoints AJAX (`?action=conta_add|conta_save|conta_delete|conta_testar`),
todos restritos a `user_id = $_SESSION['user_id']` (não é preciso ser
admin — cada um mexe só nas próprias contas).

### 5. `projetos.php` — 3 seções de projetos

Pra cada conta ativa do usuário logado, chama `github_listar_repos()`.
Se a chamada falhar pra uma conta específica (token revogado, rate
limit, GitHub fora do ar), essa conta é pulada com um aviso inline
("⚠️ [apelido]: não foi possível carregar — token inválido ou GitHub
indisponível") — não derruba a página inteira, as outras contas
continuam aparecendo normalmente.

Repos de todas as contas são combinados, cada um cruzado com
`portal_projetos_status` (default `em_execucao` se ausente), e
agrupados em 3 seções (mesmo padrão visual de `grupo-header` +
`grupo-grid` já usado em `acessos.php`):

- **🔮 Futuros**
- **🚧 Em Execução**
- **✅ Concluídos**

Cada card mostra: nome do repo, descrição, linguagem principal, issues
abertas, data do último push, e um menu (mesmo estilo `⋮`/engrenagem já
usado em outras telas) com as 3 opções de status — trocar dispara
`?action=status_set` (grava/atualiza `portal_projetos_status`) e move o
card de seção via re-render (reload simples da lista, sem necessidade
de animação/drag-and-drop).

O card inteiro é clicável e abre o repositório no GitHub em nova aba
(`target="_blank"`, mesmo padrão `abrirUrl()` de `ferramentas_gmais.php`
com as correções de segurança já aplicadas — URL sempre vem da API do
GitHub, nunca de input livre do usuário, então nem precisa da validação
de esquema aplicada lá). Não há visão de detalhe dentro do portal — o
próprio GitHub já é a página de detalhe (README, issues, commits).

### 6. Código legado (pasta de rede)

`carregarProjetosDaPasta()`, `parseProjeto()`, o fallback de
`config_projetos.local.php`, a visão de detalhe com módulos/cronograma,
e o export `.md`/impressão continuam no arquivo, intactos, mas não são
mais chamados pela renderização principal da lista. Ficam como código
morto por ora (não é objetivo desta spec removê-los).

## Segurança

- Tokens GitHub: nunca expostos ao cliente/JS — só usados
  server-side, decriptados na hora da chamada à API e descartados.
- Segue o padrão de escaping já corrigido em `ferramentas_gmais.php`
  (todo campo vindo de fonte externa — aqui, a resposta da API do
  GitHub — passa por `htmlspecialchars()` na saída).
- Endpoints AJAX de contas GitHub sempre filtram por
  `user_id = $_SESSION['user_id']` — um usuário nunca lê/edita/exclui
  conta de outro, mesmo manipulando o `id` na requisição.
- Content-Type check nos endpoints POST (mesma correção de CSRF já
  aplicada em `ferramentas_gmais.php`).

## Fora de escopo

- Reintegrar os projetos da pasta de rede na mesma visão (fica pra spec
  futura, se decidido).
- Detecção automática de status (ex: repo arquivado = concluído
  automaticamente) — status é 100% manual por enquanto.
- Múltiplos usuários vendo/editando o status do mesmo repo (não há
  noção de "equipe" aqui — cada card pertence à conta de quem
  cadastrou; se dois técnicos cadastrarem a mesma conta, cada um teria
  sua própria cópia de status, o que é uma limitação aceita por ora).

## Teste manual

1. Cadastrar uma conta GitHub com token válido → aparece no painel,
   indicador ok.
2. Cadastrar uma conta com token inválido → erro exibido no modal, não
   salva.
3. Repos da conta aparecem nas 3 seções (todos em "Em Execução" na
   primeira vez).
4. Trocar o status de um card → ele se move pra seção correta.
5. Revogar um token no GitHub e recarregar a página → conta aparece com
   aviso de erro, resto da página funciona normal.
6. Confirmar que os 3 projetos antigos (pasta de rede) não aparecem
   mais na lista principal, mas o código deles continua no arquivo.
