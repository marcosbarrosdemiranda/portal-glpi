# Ferramentas Gmais — Card de acesso a subsistemas do grupo

**Data:** 2026-07-24
**Status:** Aprovado para implementação

## Contexto

O dashboard (`dashboard.php`) tem uma seção "Recursos" com cards para "Área do
Conhecimento" e "Cofre TI". O usuário quer adicionar um novo card nessa seção,
"Ferramentas Gmais", que dá acesso a subsistemas próprios do grupo empresarial
(sistemas web hospedados fora do GLPI). Hoje existem dois:

- **Checklist Gmais** — `https://checklist-gmais.grupogmais.com:7414/login`
- **Ponto Gmais** — `https://ponto.grupogmais.com:7413/`

Mais subsistemas serão adicionados no futuro, então a lista não pode ser fixa
no código.

O projeto já resolve exatamente esse problema para outra categoria de
ferramentas: `acessos.php` mantém uma tabela `portal_acessos` (grupos
remoto/infra/erp) com CRUD via modal, sem precisar editar código para
adicionar um item. O design abaixo replica esse padrão para os subsistemas do
grupo, em uma página própria (fora da "Central de Acessos", porque
conceitualmente pertence a "Recursos", não a "Acessos" de infraestrutura de
TI).

## Componentes

### 1. Tabela `portal_ferramentas_gmais`

Criada automaticamente (`CREATE TABLE IF NOT EXISTS`) na primeira carga de
`ferramentas_gmais.php`, mesmo padrão de `agenda/db.php` / `portal_acessos`:

```sql
CREATE TABLE IF NOT EXISTS portal_ferramentas_gmais (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(100)  NOT NULL,
    descricao VARCHAR(255)  DEFAULT '',
    url       VARCHAR(500)  DEFAULT '',
    icone     VARCHAR(60)   DEFAULT 'bi-link',
    cor_bg    VARCHAR(20)   DEFAULT '#f3e5f5',
    cor_text  VARCHAR(20)   DEFAULT '#ad1457',
    ordem     INT           DEFAULT 0,
    ativo     TINYINT(1)    DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Sem coluna `grupo` — diferente de `portal_acessos`, aqui é uma única
categoria (não há necessidade de agrupar em subseções).

Populada uma vez, se vazia, com os dois defaults:

| nome | descricao | url | icone |
|---|---|---|---|
| Checklist Gmais | Checklist digital do grupo | `https://checklist-gmais.grupogmais.com:7414/login` | `bi-list-check` |
| Ponto Gmais | Controle de ponto eletrônico do grupo | `https://ponto.grupogmais.com:7413/` | `bi-clock-history` |

### 2. Página `ferramentas_gmais.php`

Clone estrutural de `acessos.php`, simplificado (sem os agrupamentos
remoto/infra/erp — uma grade única):

- Guarda de sessão igual às outras páginas internas (`auth_guard.php`,
  redireciona self-service para o dashboard).
- `$is_admin = in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico'])`
  — mesma regra de `acessos.php`.
- Grid de cards (`.acesso-card`, reaproveitando o CSS já validado em
  `acessos.php`) com ícone, nome, descrição e botão "Acessar" que abre a URL
  (nova aba, via a mesma função `abrirUrl()`).
- Card "+ Adicionar" (só admin) abre modal para cadastrar novo subsistema:
  nome, url, descrição, ícone Bootstrap, cor de fundo/ícone.
- Engrenagem por card (só admin, aparece no hover) abre modal de edição
  (nome, url, descrição) — mesmo fluxo de `editarAcesso()` /
  `salvarConfig()` de `acessos.php`, adaptado para a tabela nova.
- Exclusão: apenas itens adicionados depois dos 2 defaults podem ser
  excluídos (mesma regra `id > N` de `acessos.php`, aqui `id > 2`).
- Endpoints AJAX (`?action=save|add|delete`) seguem o mesmo contrato de
  `acessos.php`, mas apontam para `portal_ferramentas_gmais`.
- Botão "Início" no topbar volta para `dashboard.php`.

### 3. Card no dashboard (`dashboard.php`)

Dentro do bloco `<!-- ── RECURSOS ── -->` (linha ~341), depois do card
"Cofre TI":

```html
<a href="ferramentas_gmais.php" class="dash-card card-ferramentas-gmais">
  <div class="card-icon"><i class="bi bi-diagram-3-fill"></i></div>
  <h5>Ferramentas Gmais</h5>
  <p>Acesso rápido aos sistemas internos do grupo — Checklist, Ponto e outros.</p>
</a>
```

Controlado por `pode_ver('ferramentas_gmais', $perfil_cards)`, e adicionado
à condição do `section-label` de Recursos (linha ~342) para o cabeçalho da
seção só aparecer quando algum card dela estiver liberado.

CSS novo (junto dos outros `.card-*` em `dashboard.php`):

```css
.card-ferramentas-gmais { border-top-color: #ad1457; }
.card-ferramentas-gmais .card-icon { background: #fce4ec; color: #ad1457; }
```

### 4. Permissão em `perfis.php`

Novo item no catálogo `$SECOES['Recursos']`:

```php
'ferramentas_gmais' => ['label' => 'Ferramentas Gmais', 'icon' => 'bi-diagram-3-fill', 'css' => 'card-ferramentas-gmais'],
```

Isso faz a chave aparecer na tela de edição de perfis (`/perfis.php`), onde
o admin marca quais perfis enxergam o card. Perfis existentes não ganham a
permissão automaticamente — precisa ser marcada manualmente, mesmo
comportamento de qualquer card novo adicionado ao catálogo.

## Fora de escopo

- Login automático / SSO nos subsistemas — os cards apenas abrem a URL,
  o usuário loga manualmente (como hoje acontece com os links "Acessar" de
  `acessos.php`).
- Guardar credenciais desses subsistemas no Cofre TI — não foi pedido; pode
  virar uma spec futura se necessário.

## Teste manual

1. Como admin: acessar `/perfis.php`, confirmar que "Ferramentas Gmais"
   aparece no catálogo de Recursos e pode ser marcado para um perfil.
2. Com um usuário desse perfil: dashboard mostra o card "Ferramentas Gmais"
   em Recursos.
3. Clicar no card → abre `ferramentas_gmais.php` com os dois cards
   (Checklist Gmais, Ponto Gmais) e botão "Acessar" funcionando (abre em
   nova aba).
4. Como admin: adicionar um terceiro subsistema pelo modal, confirmar que
   aparece na grade sem editar código; editar a URL de um existente; excluir
   o item adicionado (defaults não podem ser excluídos).
5. Usuário sem a permissão `ferramentas_gmais`: card não aparece no
   dashboard.
