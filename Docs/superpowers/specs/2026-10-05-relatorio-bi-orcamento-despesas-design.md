# Relatório BI: Orçamento + Gestão de Despesas (Painel de Relatórios)

**Data:** 2026-10-05 · **Status:** 🟡 SPEC — aguardando aprovação

## Pedido do usuário (essência)

No "Painel de Relatórios" (`relatorios.php`), três abas novas voltadas pro
gestor/diretor da empresa (não pro uso operacional do dia a dia que já existe
em `orcamento.php`/`despesas.php`):

1. **Previsto vs Realizado** (aba padrão/primeira) — união dos dois domínios.
2. **Orçamento** — só o lado planejado.
3. **Gestão de Despesas** — só o lado realizado.

Itens 1/2/3/5/6 do backlog original ([[backlog_relatorios_orcamento_despesas]])
— barras 12 meses, comparativo ano anterior, mensal por categoria, detalhe por
categoria, filtro por loja — valem pros dois domínios (Orçamento e Despesas) e
devem ser implementados como **um componente único reaproveitado**, não
duplicado. O item 4 (previsto × realizado) é exclusivo da aba "Previsto vs
Realizado".

## Fatos confirmados no código (2026-10-05, via leitura real, não suposição)

- **Abas em `relatorios.php`:** botão `.tab-btn[data-tab]` + painel
  `.painel#painel-NOME`, troca de aba em JS (`relatorios.php:692-704`), estado
  sincronizado na URL via `history.replaceState`.
- **Lib de gráfico:** ApexCharts via CDN (`relatorios.php:33`), tema padrão
  `APEX_DARK` (`relatorios.php:660-670`) reaproveitado em todo `new
  ApexCharts(...)` — usar o mesmo, sem introduzir lib nova.
- **Padrão de carga de dados por aba:** abas "core" usam fetch central
  (`relatorios_dados.php`), mas abas de domínio próprio (Projetos,
  Equipamentos, Impressões) fazem fetch **lazy e dedicado**
  (`carregarProjetos()`, `carregarEquipamentos()`) — este relatório segue esse
  segundo padrão, com endpoint PHP próprio (`orcamento_bi_dados.php` ou
  similar), não entra no fetch monolítico.
- **Gráfico de barras com 2 séries por mês já existe** (aba "Evolução",
  Abertos×Fechados — `relatorios.php:996-1011`) — é o template a seguir pro
  item 4 (Planejado×Realizado por mês). **Comparativo ano a ano não existe em
  lugar nenhum do projeto** — lógica nova. A tabela de orçamento só existe
  desde 2026-09-30, então esse gráfico nasce com pouco histórico por ora (não
  é bug, é esperado).
- **`glpi_portal_orcamento`:** `categoria` VARCHAR(50) texto livre, `mes_ano`
  string única `'YYYY-MM'`, `ativo` TINYINT(1) (suspenso = `ativo=0`), `loja`
  (existe e funciona em produção, mas **sem DDL rastreável em nenhum arquivo
  versionado** — dívida técnica conhecida, não bloqueia este trabalho).
- **`glpi_portal_despesas`:** `data_pagamento` é data única (não
  `mes_ano` string — formato diferente do orçamento), categoria vem de FK
  `tipo_despesa_id` → `glpi_portal_despesas_tipos.nome`, `loja` existe (mesma
  situação de DDL não rastreada), `orcamento_id` existe mas é **opcional**
  (despesa pode não ter vínculo com item de orçamento), **não existe**
  `ativo`/suspenso aqui (não precisa — despesa paga é fato consumado).
- **Exclusão de item suspenso hoje é feita no JavaScript do cliente**
  (`orcamento.php:418-419`, `ativoItem()`), **não no SQL**. Qualquer agregação
  nova no backend precisa replicar manualmente `WHERE ativo <> 0` (ou
  `COALESCE(ativo,1)=1`), porque o banco/queries PHP existentes não filtram
  isso por conta própria.

## Decisões confirmadas nesta sessão (2026-10-05)

1. **Ordem das abas:** Previsto vs Realizado → Orçamento → Gestão de
   Despesas (a primeira é a que abre por padrão).
2. **Categoria não pode ser texto livre — precisa ser o mesmo vocabulário nos
   dois lados.** Decisão de implementação: orçamento passa a usar o catálogo
   que despesas já tem (`glpi_portal_despesas_tipos`), em vez de criar tabela
   nova. Detalhe em "Mudança de schema" abaixo.
3. **Tela 100% read-only** — nenhuma ação de editar/suspender nos relatórios
   novos (isso continua só em `orcamento.php`/`despesas.php`).
4. **Filtro de loja com padrão "todas as lojas" (consolidado)** — visão de
   diretor, não de gerente de loja; drill-down por loja é opcional.
5. **Componente único** pros itens 1/2/3/5/6, parametrizado pela fonte
   (orçamento vs despesas) — não duplicar a lógica de gráfico duas vezes.
6. **Endpoint PHP dedicado** (não entra no fetch central `relatorios_dados.php`)
   — segue o padrão já usado por Projetos/Equipamentos/Impressões.
7. **(2026-10-05, pedido adicional do usuário) Gestão de categoria é
   bidirecional:** criar ou renomear uma categoria em `orcamento.php` **tem
   que** aparecer em `despesas.php` e vice-versa. Como as duas telas passam a
   ler/escrever a mesma tabela única `glpi_portal_despesas_tipos` (decisão 2
   acima), isso é satisfeito **naturalmente pelo desenho** — criar/renomear é
   editar a mesma linha que o outro lado também lê, não uma cópia. O que
   falta pra isso funcionar de fato (hoje não existe UI pra nenhum dos dois
   lados) é detalhado em "Gestão de categoria compartilhada" abaixo.

## Gestão de categoria compartilhada (criar/editar, bidirecional)

**Estado atual confirmado no código (2026-10-05):** já existe
`agenda/tipos_db.php` com `listarTipos($pdo)` e um handler POST
`action_tipo=add` (insere em `glpi_portal_despesas_tipos`) — mas **nenhuma
tela tem formulário que chame isso** (`despesas.php` só faz
`require_once` + `listarTipos()` pra popular o `<select>`, sem botão de
criar categoria nova; `orcamento.php` nem usa esse arquivo ainda, hoje tem
6 opções fixas). Não existe ação de **renomear** em lugar nenhum.

**Decisão:** um componente compartilhado — pequeno modal "Gerenciar
categorias" (listar + criar + renomear), incluído tanto em `orcamento.php`
quanto em `despesas.php`, chamando o mesmo backend:
- `agenda/tipos_db.php` ganha `action_tipo=edit` (`UPDATE
  glpi_portal_despesas_tipos SET nome=? WHERE id=?`), ao lado do `add` que
  já existe.
- O `<select>` de categoria em cada tela (já apontando pra
  `glpi_portal_despesas_tipos` depois da migração) ganha um link/botão
  "⚙ Gerenciar categorias" ao lado, abrindo o modal compartilhado.
- Ao salvar (criar ou renomear) no modal, a lista do `<select>` da própria
  tela é atualizada via fetch, sem reload de página. O reflexo no **outro**
  lado é automático na próxima vez que aquela tela carregar a lista — não
  precisa de sincronização em tempo real entre abas abertas simultaneamente
  (fora de escopo; ninguém pediu WebSocket/polling pra isso).
- **Excluir categoria fica fora de escopo** por ora (usuário não pediu);
  teria que decidir o que fazer com itens de orçamento/despesas já
  vinculados a uma categoria apagada — pergunta em aberto pra quando/se
  isso for pedido.

- Adicionar coluna `tipo_despesa_id INT NULL`, referenciando
  `glpi_portal_despesas_tipos(id)` (nullable, aditivo — nenhuma coluna
  existente é removida).
- **Migração de dados existentes (automática, roda uma vez):** para cada
  valor distinto hoje em `categoria`, procurar nome igual (case-insensitive)
  em `glpi_portal_despesas_tipos.nome`; se achar, linkar `tipo_despesa_id`;
  se não achar, **criar a entrada no catálogo** (nunca deixar órfão) e linkar.
  Mesmo padrão de migração guardada por `SHOW COLUMNS`/`ALTER TABLE` já usado
  em `agenda/orcamento_db.php` para `concluido`/`ativo` (ver
  [[orcamento-ver-detalhes-suspender]]).
- A coluna `categoria` (texto) **não é apagada** — fica como histórico/
  fallback, só deixa de ser usada em cadastros novos.
- `orcamento.php`: campo de categoria no formulário passa de texto livre pra
  `<select>` populado por `glpi_portal_despesas_tipos`, no mesmo componente
  que `despesas.php` já usa pra `tipo_despesa_id`.
- A tabela `glpi_portal_despesas_tipos` **não é renomeada** (minimizar risco/
  blast radius em `despesas.php`, que já depende dela) — fica com nome
  histórico mesmo compartilhada entre os dois domínios agora. Se o usuário
  preferir renomear pra algo genérico (`glpi_portal_categorias`), é uma
  decisão separada, não bloqueia esta spec.

## Agregação server-side (endpoint novo)

Para cada aba, o endpoint agrega (não delega pro JS do cliente, diferente de
`orcamento.php`):

- **Orçamento:** `SELECT tipo_despesa_id, mes_ano, loja, SUM(total_previsto) ...
  FROM glpi_portal_orcamento WHERE ativo <> 0 GROUP BY ...` (filtro de
  suspenso aplicado no SQL, replicando `ativoItem()`).
- **Despesas:** `SELECT tipo_despesa_id, MONTH(data_pagamento), YEAR(data_pagamento),
  loja, SUM(valor_pago) ... FROM glpi_portal_despesas GROUP BY ...` (sem
  filtro de `ativo` — não existe esse conceito aqui).
- **Previsto vs Realizado:** os dois agregados acima, casados por
  `tipo_despesa_id` + mês + loja (chave de comparação única e garantida pela
  migração de schema acima — não depende mais de bater string).

## Escopo explicitamente fora

- PDF export dos relatórios novos — não pedido ainda, não assumir.
- Renomear `glpi_portal_despesas_tipos` pra nome genérico.
- Resolver [[backlog_monitor-max-horas-missing]] (tema não relacionado,
  mencionado numa sessão anterior, sem resposta do usuário ainda).

## Perguntas que NÃO bloqueiam (resolvidas com default razoável, usuário pode
ajustar no PLAN GATE)

- Ordem entre "Orçamento" e "Despesas" (2ª e 3ª aba): mantive a ordem citada
  pelo usuário.
- Comparativo ano a ano vai nascer vazio/pouco populado por enquanto (pouco
  histórico) — aceito como esperado, não é bug.

## Próximo passo

Aguardando aprovação desta SPEC para seguir pro PLAN (quebra em etapas de
implementação: migração de schema → endpoint(s) PHP → componente de gráfico
compartilhado → 3 painéis em `relatorios.php`).
