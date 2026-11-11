# PLAN: Dimensão "Projeto" no Orçamento de TI
Spec: `Docs/superpowers/specs/2026-10-18-orcamento-projeto-design.md`

## Fase 0 — Branch
`git checkout -b feat/orcamento-projeto` a partir de `feat/merge-alertas-monitor`
(branch ativa atual).

## Fase 1 — Dados/backend
1. `agenda/orcamento_db.php`:
   - `CREATE TABLE IF NOT EXISTS glpi_portal_projetos (id, nome UNIQUE, criado_em)`.
   - Guard de coluna `projeto_id INT NULL` em `glpi_portal_orcamento`
     (mesmo padrão `SHOW COLUMNS ... ALTER TABLE ADD COLUMN` já usado
     pra `ativo`/`concluido`).
2. `agenda/despesas_db.php`:
   - Guard de coluna `projeto_id INT NULL` em `glpi_portal_despesas`,
     envolvido em `try/catch` (arquivo não garante que a tabela exista,
     é DDL manual).
3. `agenda/projetos_db.php` (**novo**):
   - `listarProjetos(PDO $pdo): array`.
   - Handler POST `action_projeto=add` (com guard de sessão
     `$_SESSION['autenticado']` — nasce com alcance real, diferente do
     `tipos_db.php` original).

## Fase 2 — `orcamento.php`
1. `require_once agenda/projetos_db.php` + `$projetos = listarProjetos($pdo)`.
2. Handler POST `save`: incluir `projeto_id` no INSERT/UPDATE (`NULL` se
   vazio).
3. Query principal: `LEFT JOIN glpi_portal_projetos p ON p.id = o.projeto_id`
   pra trazer `projeto_nome`.
4. HTML/JS:
   - Select Projeto no modal Novo/Editar (+ opção "+ Novo projeto...").
   - Coluna Projeto na tabela (badge neutro).
   - Filtro `f-projeto` na barra de filtros.
   - Botão de agrupamento "Projetos" ao lado de Lojas/Categorias —
     agrupamento próprio (não combinado com loja+categoria, mesmo nível
     de simplicidade que "Categorias" sozinho hoje tem).
   - `verDetalhesOrc()`: linha Projeto.
   - `abrirModal`/`editarItem`: popular/limpar o select Projeto.
   - `abrirModalConcretizar()`: hidden `projeto_id` herdado do item.
   - `exportarPDF()`: coluna Projeto nas colunas exportadas.
   - `criarProjetoRapido()`: fetch POST `action_projeto=add`, repovoa os
     `<select>` de projeto sem reload de página.

## Fase 3 — `despesas.php`
1. `require_once agenda/projetos_db.php` + listar projetos.
2. Handler POST `save`: incluir `projeto_id` no INSERT.
3. Query: `LEFT JOIN glpi_portal_projetos` pra trazer nome.
4. Select Projeto no formulário de despesa avulsa.
5. Exibir Projeto no "Ver detalhes" da despesa.
(Concretização via `orcamento.php` já cobre o hidden — nada a fazer além
de aceitar o campo no INSERT.)

## Fase 4 — Deploy (produção, `glpi-server`)
1. `scp` de cada arquivo tocado/novo pra
   `C:\docker\glpi-portal\glpi2\portal-glpi\<arquivo>.new`.
2. `docker exec glpi-web php -l <arquivo>.new` em cada.
3. `Move-Item -Force` por cima do original — confirmar com
   `Get-ChildItem` no nome final (lição do incidente do worker morto).
4. Acessar `orcamento.php` autenticado uma vez pra disparar a migração
   automática; confirmar via `SHOW COLUMNS` remoto que `projeto_id` e a
   tabela `glpi_portal_projetos` existem nas duas tabelas.

## Fase 5 — Verificação
1. Criar projeto "Upgrade do CFTV"; cadastrar 3 itens de categorias
   diferentes (Hardware/Infraestrutura) nele; confirmar que o
   agrupamento por Projeto soma Previsto/Realizado corretamente.
2. Concretizar 1 desses itens em despesa; confirmar que a despesa nasce
   com o mesmo `projeto_id`.
3. Confirmar que nenhum item/categoria existente perdeu dado (snapshot
   antes/depois, per lição de suíte-em-produção — aqui não tem teste
   automatizado novo, então o snapshot é manual via `SELECT COUNT(*)`
   antes/depois do deploy).
4. Commit por passo concluído + push (fluxo autônomo já autorizado).

## Fora de escopo (herdado da spec)
Categoria intocada; branch antigo de categoria-catálogo não tocado;
editar/excluir projeto; filtro/agrupamento por projeto em despesas.php;
integração com módulo nativo de Projetos do GLPI; relatório BI.
