# SPEC: Dimensão "Projeto" no Orçamento de TI
**Data:** 2026-10-18  **Tipo:** FEAT  **Status:** APROVADA (usuário, 2026-10-18)

## Demanda
Usuário quer agrupar itens de orçamento por **projeto**, independente da
**categoria** já existente (Hardware/Software/Serviços/.../Outros, que
continua intocada — é uma classificação de *tipo de gasto*, não de
*iniciativa*). Exemplo dado: "câmera", "DVR", "cabo" são 3 itens de
categorias diferentes (Hardware/Infraestrutura), mas todos fazem parte do
mesmo projeto "Upgrade do CFTV". Projeto é um nível de agrupamento
**acima** da categoria, ortogonal a ela.

**Nome escolhido:** "Projeto" (decisão do usuário, consciente da
ambiguidade com o módulo nativo de Projetos do GLPI — ver "Risco aceito"
abaixo).

## Modelo de dados

### Nova tabela-catálogo `glpi_portal_projetos`
```sql
CREATE TABLE IF NOT EXISTS glpi_portal_projetos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(120) NOT NULL UNIQUE,
    criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```
Criada via guard em `agenda/orcamento_db.php` (mesmo padrão das tabelas
de backup/alertas — `CREATE TABLE IF NOT EXISTS` no include).

### Nova coluna em `glpi_portal_orcamento`
`projeto_id INT NULL` (sem FK física — mesmo padrão de `tipo_despesa_id`
em despesas, que também não usa FK declarada). `NULL` = item sem projeto
(não é obrigatório). Migração automática via guard
(`SHOW COLUMNS ... LIKE 'projeto_id'` → `ALTER TABLE ADD COLUMN`), igual
ao já usado para `concluido`/`ativo` no mesmo arquivo.

### Nova coluna em `glpi_portal_despesas`
`projeto_id INT NULL`, mesmo guard, agora adicionado em
`agenda/despesas_db.php` (hoje vazio — DDL da tabela em si é manual em
produção, mas uma guard *aditiva* de coluna não exige a tabela ter sido
criada por este arquivo, só que já exista — risco: se a tabela ainda não
existir em algum ambiente, o `SHOW COLUMNS` falha. Mitigação: envolver em
`try/catch` descartando o erro, assim como o resto do arquivo já assume
que a tabela existe).

**Por que em despesas também:** uma despesa pode nascer concretizando um
item de orçamento (herda o `projeto_id` do item de origem, campo hidden
no modal "Concretizar Despesa", igual já faz com `orcamento_id`) ou pode
ser avulsa (ganha um select próprio de Projeto no formulário, mesmo
catálogo). Sem isso, o gasto real de um projeto fica incompleto no
relatório de despesas.

## Backend novo: `agenda/projetos_db.php`
Arquivo novo, paralelo a `agenda/tipos_db.php`:
```php
function listarProjetos(PDO $pdo): array {
    return $pdo->query("SELECT * FROM glpi_portal_projetos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}
```
+ handler POST `action_projeto=add` (cria projeto só por nome, com guard
de sessão `$_SESSION['autenticado']` desde o nascimento — diferente do
`tipos_db.php` antigo, que nasceu sem guard por ser código morto na
época; aqui já nasce com alcance real). **Sem edição/exclusão** por ora —
mesma decisão consciente já tomada para categorias (`glpi_portal_despesas_tipos`):
decidir o que fazer com itens já vinculados a um projeto excluído é
pergunta em aberto, não resolver sem perguntar.

## UI — `orcamento.php`
- Select **Projeto** no modal Novo/Editar Item, logo abaixo de Loja —
  opções: "— Sem projeto —" (vazio) + lista de `listarProjetos()` + opção
  final "+ Novo projeto..." (abre `prompt()` simples, submete via
  `action_projeto=add` por fetch, recarrega a lista do select sem reload
  de página).
- Nova coluna **Projeto** na tabela principal (badge neutro, cor única —
  não reusa `.badge-cat`, pra não implicar que é subtipo de categoria).
- Novo filtro `f-projeto` na barra de filtros (dropdown com todos os
  projetos cadastrados).
- Novo modo de agrupamento **Projeto**, ao lado dos botões "Lojas" e
  "Categorias" já existentes (`toggleAgrupamento('projeto')`), com
  subtotal de Previsto/Realizado por projeto no cabeçalho do grupo — é
  exatamente o caso de uso citado (ver quanto já foi gasto no "Upgrade do
  CFTV" somando câmera+DVR+cabo).
- Modal "Ver detalhes": acrescenta linha Projeto.
- Export PDF (`exportarPDF`): acrescenta coluna Projeto.
- Modal "Concretizar Despesa": campo hidden `projeto_id` preenchido a
  partir do item de orçamento de origem.

## UI — `despesas.php`
- Select **Projeto** no formulário de despesa avulsa (mesmo catálogo via
  `listarProjetos()`).
- Exibir Projeto no modal "Ver detalhes" da despesa.
- **Fora de escopo nesta tela:** filtro e agrupamento por projeto em
  despesas — a tela já tem filtros de mês/ano/loja; adicionar não foi
  pedido e aumenta a spec sem necessidade imediata. Pode entrar depois se
  o usuário sentir falta.

## Fora de escopo (explícito)
- Categoria (Hardware/Software/...) — **não é tocada em nada**, é
  dimensão separada e ortogonal, por decisão explícita do usuário.
- O branch não mesclado `feat/relatorio-bi-orcamento-despesas` (que
  migrou Categoria do Orçamento pra um catálogo compartilhado com
  Despesas) — irrelevante pra esta spec, não será trazido nem descartado
  agora.
- Edição/exclusão de projeto.
- Integração com o módulo nativo "Projetos" do GLPI
  (`relatorios.php`/`relatorios_projetos.php`).
- Filtro/relatório BI combinando Orçamento+Despesas por projeto
  (`relatorios.php` nesta branch não tem as abas de BI — isso vive só no
  branch não mesclado acima).

## Risco aceito (decisão do usuário)
Nomear essa dimensão de "Projeto" colide conceitualmente com o módulo
nativo de Projetos do GLPI (status/progresso/prazo/tarefas/equipe,
exibido na aba "Projetos" do Painel de Relatórios). Usuário optou por
manter o nome mesmo assim, por ser a palavra que já usa naturalmente. Na
prática as duas telas nunca se cruzam hoje, então não há colisão de UI —
só um risco de confusão de nomenclatura se algum dia alguém tentar ligar
os dois conceitos.

## Arquivos afetados
- `agenda/orcamento_db.php` — tabela `glpi_portal_projetos` + coluna
  `projeto_id` em `glpi_portal_orcamento`.
- `agenda/despesas_db.php` — coluna `projeto_id` em `glpi_portal_despesas`.
- `agenda/projetos_db.php` — **novo**.
- `orcamento.php` — select, coluna, filtro, agrupamento, PDF, detalhes,
  hidden no concretizar.
- `despesas.php` — select na despesa avulsa, detalhes.

## Branch
Nova branch `feat/orcamento-projeto`, empilhada em cima da branch atual
`feat/merge-alertas-monitor` (branch ativa do momento, confirmada via
`git branch`/`git status`) — não em `main` nem em
`infra/migracao-docker-glpi` diretamente.
