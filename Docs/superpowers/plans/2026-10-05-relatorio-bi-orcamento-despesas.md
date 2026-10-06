# Relatório BI: Orçamento + Gestão de Despesas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 3 abas novas no Painel de Relatórios (`relatorios.php`), read-only,
pro gestor/diretor: **Previsto vs Realizado** (padrão/primeira), **Orçamento**,
**Gestão de Despesas**. Componente de gráfico único e reaproveitado (barras 12
meses, comparativo ano anterior, mensal por categoria, detalhe por categoria,
filtro de loja) parametrizado pela fonte (orçamento vs despesas); o
Previsto×Realizado é lógica exclusiva, casando os dois agregados por
categoria+mês+loja.

**Spec base:**
`Docs/superpowers/specs/2026-10-05-relatorio-bi-orcamento-despesas-design.md`
— aprovada 2026-10-05, com adendo do mesmo dia: categoria é bidirecional
(criar/renomear em orçamento reflete em despesas e vice-versa, por
desenho — mesma tabela única). Pré-condição de todas as tasks de dados:
categoria unificada via `tipo_despesa_id` (catálogo
`glpi_portal_despesas_tipos` reaproveitado, não renomeado).

**Architecture:**
- `agenda/orcamento_db.php` ganha a migração aditiva de `tipo_despesa_id` em
  `glpi_portal_orcamento` (mesmo padrão guard `SHOW COLUMNS`/`ALTER TABLE` já
  usado pra `ativo`/`concluido`), incluindo a rotina de migração de dados
  (linkar/criar catálogo a partir do texto livre existente em `categoria`).
- `orcamento_bi_dados.php` (novo, raiz, com sessão) — endpoint dedicado,
  fora do fetch central `relatorios_dados.php`, com `action=previsto_realizado
  |orcamento|despesas` + filtros (`loja`, `ano`).
- `orcamento.php`: troca o `<select name="categoria">` fixo (6 opções
  hardcoded) por `<select name="tipo_despesa_id">` populado por
  `glpi_portal_despesas_tipos`, mesmo componente que `despesas.php:161-166`
  já usa. Grava `tipo_despesa_id` (e mantém `categoria` como estava, sem
  apagar).
- `agenda/tipos_db.php` ganha `action_tipo=edit` (rename) ao lado do `add`
  que já existe mas que hoje nenhuma tela chama. Um modal compartilhado
  "Gerenciar categorias" (listar + criar + renomear) é incluído em
  `orcamento.php` e `despesas.php`, os dois batendo no mesmo backend/tabela
  — reflexo bidirecional automático por desenho (mesma linha, não cópia).
- `relatorios.php`: +3 botões `.tab-btn[data-tab]` (ordem: previsto-realizado,
  orcamento, despesas-bi) + 3 painéis, seguindo o padrão lazy-fetch de
  `carregarEquipamentos()` (`relatorios.php:1445-1484`) — fetch dedicado por
  aba, não entra no `getParams()`/fetch central.
- Gráfico de 2 séries por mês reusa o template já existente (aba Evolução,
  `relatorios.php:996-1011`, Abertos×Fechados) adaptado pra
  Planejado×Realizado.

**Tech Stack:** PHP 8.2 + PDO/MariaDB, ApexCharts via CDN já carregado
(`relatorios.php:33`), tema `APEX_DARK` (`relatorios.php:660-670`) reusado
sem introduzir lib nova. Deploy por scp + `.new` + `php -l` no container +
confirmação antes de sobrescrever + `Move-Item`.

---

## Global Constraints

- **Read-only.** Nenhuma task cria ação de editar/suspender nos relatórios
  novos — isso continua só em `orcamento.php`/`despesas.php`.
- **Aditivo.** `categoria` (texto) em `glpi_portal_orcamento` não é apagada;
  `glpi_portal_despesas_tipos` não é renomeada; nenhuma coluna/tabela
  existente é removida.
- **Filtro de suspenso replicado no SQL.** Toda query nova sobre
  `glpi_portal_orcamento` tem `WHERE ativo <> 0` (ou
  `COALESCE(ativo,1)=1`) — hoje esse filtro só existe no JS do cliente
  (`orcamento.php:418-419`), não no banco.
- **`glpi_portal_despesas` não tem `ativo`** — não filtrar, não existe esse
  conceito lá.
- **Chave de comparação é `tipo_despesa_id` + mês + loja**, nunca mais
  string de `categoria` — a migração de schema é pré-requisito de todo o
  resto; nenhuma task de agregação roda antes dela estar concluída e
  verificada em produção.
- **Padrão "todas as lojas" por default** no filtro (visão consolidada de
  diretor); drill-down por loja é opcional, nunca obrigatório pra ver o
  relatório.
- **Sem PDF export** nos relatórios novos — fora de escopo, não implementar.
- **Cuidado com `run.php` em produção** (regra já conhecida) — se algum
  teste novo for escrito pra este fluxo, rodar só o arquivo mexido.

---

## File Structure

| Arquivo | Papel |
|---|---|
| `agenda/orcamento_db.php` | +migração `tipo_despesa_id` (coluna + backfill linkando/criando catálogo) |
| `orcamento_bi_dados.php` | **novo** — endpoint GET com sessão, 3 actions de agregação |
| `orcamento.php` | troca `<select name="categoria">` por `<select name="tipo_despesa_id">` (cadastro + edição) + inclui modal de categorias |
| `despesas.php` | inclui o mesmo modal de categorias (link "⚙ Gerenciar categorias" ao lado do `<select>` já existente) |
| `agenda/tipos_db.php` | +`action_tipo=edit` (rename), ao lado do `add` já existente |
| `categorias_modal.php` | **novo** — partial compartilhado (HTML+JS) do modal "Gerenciar categorias", incluído por `orcamento.php` e `despesas.php` |
| `relatorios.php` | +3 `tab-btn`/painéis + funções JS `carregarOrcamentoBI()`/`carregarDespesasBI()`/`carregarPrevistoRealizado()` + 1 componente de gráfico compartilhado |

---

## Tasks

### Fase 1 — Migração de schema (pré-requisito, bloqueia tudo abaixo)

- [ ] 1.1 Em `agenda/orcamento_db.php`, no bloco de migração existente (guard
  `SHOW COLUMNS FROM glpi_portal_orcamento LIKE 'tipo_despesa_id'`):
  adicionar `ALTER TABLE glpi_portal_orcamento ADD COLUMN tipo_despesa_id
  INT NULL, ADD CONSTRAINT ... FOREIGN KEY (tipo_despesa_id) REFERENCES
  glpi_portal_despesas_tipos(id)` (nullable, aditivo).
- [ ] 1.2 Na mesma migração (roda uma vez, idempotente): para cada valor
  distinto hoje em `categoria`, buscar nome igual case-insensitive em
  `glpi_portal_despesas_tipos.nome`; achou → `UPDATE ... SET
  tipo_despesa_id=?`; não achou → `INSERT INTO glpi_portal_despesas_tipos
  (nome) VALUES (?)` e linkar o id novo. Nunca deixar linha de orçamento
  órfã (sem match e sem criar).
- [ ] 1.3 Verificar em produção (`SELECT categoria, tipo_despesa_id FROM
  glpi_portal_orcamento`) que toda linha ficou com `tipo_despesa_id`
  preenchido após o primeiro acesso a uma página que `require` esse
  arquivo (mesmo padrão de `ativo`, ver `[[orcamento-ver-detalhes-suspender]]`
  — não precisa de script avulso).

### Fase 2 — `orcamento.php`: campo de categoria + gestão compartilhada

- [ ] 2.1 Trocar `<select name="categoria" id="item-cat">` (6 `<option>`
  hardcoded, `orcamento.php:307-314`) por `<select name="tipo_despesa_id"
  id="item-cat">` populado via PHP a partir de `glpi_portal_despesas_tipos`
  (mesmo `foreach` de `despesas.php:163-165`).
- [ ] 2.2 Ajustar o INSERT/UPDATE de `orcamento.php` (linhas 17-19) pra
  gravar `tipo_despesa_id` em vez de `categoria` em cadastros/edições novas
  (campo `categoria` texto deixa de ser escrito, mas a coluna continua
  existindo pra histórico).
- [ ] 2.3 Ajustar o JS de agrupamento/badge/exibição (`orcamento.php:454,
  509-582, 627, 677, 723` — `i.categoria`) pra usar o nome do tipo vindo do
  JOIN (`SELECT o.*, t.nome AS categoria FROM glpi_portal_orcamento o LEFT
  JOIN glpi_portal_despesas_tipos t ON o.tipo_despesa_id=t.id`), preservando
  a chave `categoria` no JSON de saída pra não reescrever todo o JS do
  cliente.
- [ ] 2.4 Teste manual: criar item novo, editar item existente (confirmar
  que item antigo sem `tipo_despesa_id` pré-migração não quebra a tela —
  já devia estar preenchido pela Fase 1, mas validar).
- [ ] 2.5 Em `agenda/tipos_db.php`, adicionar `action_tipo=edit`:
  `UPDATE glpi_portal_despesas_tipos SET nome=? WHERE id=?` (validar nome
  não vazio, mesma validação simples do `add` existente).
- [ ] 2.6 Criar `categorias_modal.php` (partial, HTML+JS): lista as
  categorias atuais (`listarTipos($pdo)`), campo "+ nova categoria" (POST
  `action_tipo=add`) e, por linha, um lápis/inline-edit (POST
  `action_tipo=edit`) — tudo via fetch, sem reload de página; ao salvar,
  recarrega a lista do modal E o `<select>` da página que o incluiu.
- [ ] 2.7 Incluir `categorias_modal.php` em `orcamento.php` e em
  `despesas.php`, com um link/botão "⚙ Gerenciar categorias" ao lado de
  cada `<select>` de categoria, abrindo o mesmo modal.
- [ ] 2.8 Teste manual bidirecional: criar categoria nova em `orcamento.php`
  → abrir `despesas.php` → confirmar que aparece no `<select>` de lá (e
  vice-versa); renomear uma categoria existente em qualquer um dos dois →
  confirmar que o nome mudou no outro (itens já cadastrados continuam
  vinculados pelo `id`, não pelo nome antigo).

### Fase 3 — Endpoint de agregação (`orcamento_bi_dados.php`)

- [ ] 3.1 Criar `orcamento_bi_dados.php` (raiz, com sessão, segue o padrão
  de autenticação dos outros endpoints de relatório). Parâmetros: `action`
  (`orcamento`|`despesas`|`previsto_realizado`), `loja` (opcional, default
  todas), `ano` (opcional, default ano atual).
- [ ] 3.2 `action=orcamento`: `SELECT tipo_despesa_id, t.nome, mes_ano,
  loja, SUM(qty_prevista*unit_previsto) AS total_previsto FROM
  glpi_portal_orcamento o JOIN glpi_portal_despesas_tipos t ON
  o.tipo_despesa_id=t.id WHERE ativo <> 0 [AND loja=?] GROUP BY
  tipo_despesa_id, mes_ano, loja`.
- [ ] 3.3 `action=despesas`: `SELECT tipo_despesa_id, t.nome,
  YEAR(data_pagamento), MONTH(data_pagamento), loja, SUM(valor_pago) AS
  total_realizado FROM glpi_portal_despesas d JOIN
  glpi_portal_despesas_tipos t ON d.tipo_despesa_id=t.id WHERE 1=1 [AND
  loja=?] GROUP BY tipo_despesa_id, ano, mes, loja` (sem filtro de `ativo`).
- [ ] 3.4 `action=previsto_realizado`: casa os dois agregados acima por
  `tipo_despesa_id` + mês + loja (chave garantida pela Fase 1), retorna
  série unificada `{mes, previsto, realizado}` + breakdown por categoria.
- [ ] 3.5 Cada action retorna também os agregados de "ano anterior" (mesmo
  filtro, `ano-1`) pra alimentar o comparativo — pode vir vazio/pouco
  populado (esperado, pouco histórico, não é bug).
- [ ] 3.6 Teste manual via `curl`/browser autenticado: confirmar JSON válido
  nas 3 actions, com e sem filtro de `loja`.

### Fase 4 — Componente de gráfico compartilhado (JS) ✅ concluída

- [x] 4.1 `renderBarrasMensalBI(containerId, labels, series, cores)` em
  `relatorios.php` — gráfico de barras mensal genérico (1-N séries) usando
  `APEX_DARK`, reusado pelas 3 abas novas.
- [x] 4.2 Comparativo ano anterior resolvido com a mesma função acima,
  passando 2 séries (ano atual × ano anterior) com cores diferentes — não
  precisou de função separada.
- [x] 4.3 `renderBarrasCategoriaBI(containerId, dados, label, cor)` — barra
  horizontal por categoria, mesmo padrão visual do `eq-chart-cat` de
  Equipamentos.
- [~] 4.4 Drill-down por categoria **não implementado** — desvio consciente:
  a tabela "Por Categoria" já mostra o detalhe (previsto/realizado/delta ou
  total) sem precisar de clique; adicionar expand-on-click fica como
  melhoria futura caso o gestor sinta falta.
- [~] 4.5 Filtro de loja/ano **não é compartilhado entre as 3 abas** — cada
  painel tem seu próprio filtro independente (`pr-filtro-*`,
  `orcbi-filtro-*`, `despbi-filtro-*`). Desvio deliberado: evita que trocar
  o filtro numa aba altere os dados das outras duas sem o usuário notar.

### Fase 5 — 3 painéis em `relatorios.php` ✅ concluída

- [x] 5.1 3 `.tab-btn[data-tab]` adicionados na ordem: `previsto-realizado`,
  `orcamento-bi`, `despesas-bi` (depois de "Equipamentos").
- [x] 5.2 3 `.painel#painel-NOME` com KPIs + filtro de loja/ano + gráficos.
- [x] 5.3 Painel "Previsto vs Realizado": 2 séries por mês + tabela de
  detalhe por categoria com delta (verde se dentro do previsto, vermelho se
  acima).
- [x] 5.4 Painel "Orçamento": `action=orcamento`, mensal (ano atual ×
  anterior) + breakdown por categoria.
- [x] 5.5 Painel "Gestão de Despesas": `action=despesas`, mesmo formato.
- [~] 5.6 **Não é lazy-fetch por clique de aba** — desvio necessário: ao
  reler o código real de `relatorios.php` durante a execução, confirmei que
  Projetos/Impressões/Equipamentos também não usam esse padrão (o
  `.tab-btn` click listener só troca a classe `.active`, nunca dispara
  fetch). As 3 funções novas (`carregarPrevistoRealizado()`,
  `carregarOrcamentoBI()`, `carregarDespesasBI()`) seguem o padrão real:
  chamadas juntas, sem condição, dentro de `carregarDados()`. Re-fetch
  independente só acontece ao trocar o filtro de loja/ano de cada painel.
- [~] 5.7 **Não mudei a aba padrão da página** — perguntado explicitamente
  ao usuário por ser uma mudança de comportamento que afeta todo mundo que
  usa o Painel de Relatórios, não só quem quer ver o financeiro; resposta:
  manter "Atendimentos" como aba inicial. "Previsto vs Realizado" é só a
  primeira das 3 abas novas em ordem/posição, não a aba padrão da página.

### Fase 6 — Deploy e verificação ✅ concluída (incremental, por fase)

- [x] 6.1 `php -l` local não disponível neste ambiente (sem PHP CLI local)
  — lint feito direto no container via o fluxo de deploy abaixo, em cada
  fase (1, 2+3, 4+5).
- [x] 6.2 Deploy por scp + `.new` + `php -l` no container + `Move-Item`,
  feito incrementalmente por fase (não represado pra um deploy único no
  final): Fase 1 (`agenda/orcamento_db.php`) → Fase 2+3
  (`agenda/tipos_db.php`, `categorias_modal.php`, `orcamento.php`,
  `despesas.php`, `orcamento_bi_dados.php`) → Fase 4+5 (`relatorios.php`).
  Desvio do texto original (que previa represar tudo pra Fase 6), mas
  consistente com a Global Constraint de verificar cada fase antes de
  seguir pra próxima.
- [x] 6.3 Verificação pós-deploy de Fase 4+5: script de smoke test
  (`_scratch_check_relatorios_bi.php`) rodado via CLI no container —
  simula sessão autenticada, inclui o `relatorios.php` real e confirma
  presença das 3 abas/painéis/funções novas e ausência de erro fatal;
  apagado do servidor e local imediatamente após. Verificação via browser
  autenticado (login real) não foi feita — sem credenciais disponíveis
  nesta sessão pro Playwright; fica pendente uma confirmação visual do
  usuário na próxima vez que abrir o Painel de Relatórios.
- [x] 6.4 Commit por passo concluído: Fase 1 (`9d281db`), Fase 2
  (`0b96375`), Fase 3 (`5b402ad`), Fase 4+5 (`ac5baf6`) — push após cada
  um, branch `feat/relatorio-bi-orcamento-despesas`.

---

## Status: concluído

Todas as 6 fases implementadas, deployadas e verificadas (ver desvios
documentados com `[~]` acima). Pendência real única: confirmação visual do
usuário abrindo as 3 abas novas em produção, já que a verificação
automatizada desta sessão não passou por login de browser.
