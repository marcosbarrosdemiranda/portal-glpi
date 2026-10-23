# Chamado de Balanças: campos extras + vínculo no Inventário + BI

**Data:** 2026-10-22 · **Status:** 🟢 Fase 1 IMPLEMENTADA (campos no chamado) ·
Fases 1b/2/3 pendentes

## Fase 1 — IMPLEMENTADO (2026-10-22)

- Container `id=7` ("Reparo Equipamentos") recebeu os 2 campos pela UI admin
  do GLPI: "Equipamento (Balança)" (`dropdown`, single) e "Tipo de Defeito"
  (`dropdown`, multi-select, 8 valores fixos).
- Condição de exibição criada em
  `glpi_plugin_fields_containerdisplayconditions` (`itemtype=Ticket,
  search_option=7, condition=2, value=162`) — réplica do padrão Tabloides.
- Catálogo "Equipamento" semeado com as 12 balanças já cadastradas em
  `portal_balancas` na data; catálogo "Tipo de Defeito" semeado com os 8
  valores fixos.
- **Teste manual end-to-end confirmado em produção:** chamado real
  (`#11890`, categoria Hardware > Balanças) criado, aba "Reparo
  Equipamentos" apareceu corretamente, os 2 campos renderizaram com as
  opções semeadas, valores selecionados e salvos (single="5" balança;
  multi=["Célula de Carga","Placa Principal"]) — confirmado via SQL direto
  na tabela gerada `glpi_plugin_fields_ticketreparoequipamentos`:
  `plugin_fields_equipamentobalanafielddropdowns_id=4` (int) e
  `plugin_fields_tipodedefeitofielddropdowns_id=["4","2"]` (JSON array),
  exatamente como previsto pelo mecanismo do plugin Fields. Chamado de
  teste movido pra lixeira (soft-delete reversível) após a confirmação.

## Problema

Chamados de categoria "Hardware > Balanças" (`glpi_itilcategories.id=162`)
não têm nenhum campo estruturado pra identificar **qual balança** teve
problema nem **qual tipo de defeito**. O padrão "Tabloides" (categoria
Impressões, id=181) já resolve isso pra outra categoria via plugin Fields
do GLPI (container "Impressões", campos tipo `number`). Sem o equivalente
pra Balanças, não dá pra:
- filtrar o chamado por equipamento real (hoje é texto livre no título);
- listar, no Inventário de Balanças, o histórico de chamados daquela
  balança;
- montar um gráfico de BI de "qual defeito mais aparece"/"qual balança mais
  quebra".

## Decisão de desenho (confirmada com o usuário)

Três fases, uma spec só, implementadas em ordem (fase 2 e 3 dependem dos
dados que a fase 1 cria).

### Fase 1 — Campos extras no chamado (plugin Fields do GLPI)

- **Reaproveitar** o container `id=7` ("Reparo Equipamentos", `type=tab`,
  hoje vazio — 0 campos, 0 condição de exibição, mas já com profile rights
  configurados nos 8 perfis). Não criar container novo (confirmado com o
  usuário).
- **Condição de exibição** nova em
  `glpi_plugin_fields_containerdisplayconditions`: `itemtype=Ticket,
  search_option=7, condition=2, value=162` — mesmo padrão exato da linha já
  existente do Tabloides (`value=181`, confirmada em produção).
- **Campo 1 — "Equipamento (Balança)"**: `type=dropdown`, `multiple=0`.
  Fonte dos valores: `portal_balancas.identificacao` (tabela própria do
  portal, **não** é tipo de item nativo do GLPI — confirmado: balança não
  existe em `glpi_peripherals`/`glpi_monitors`). O plugin Fields guarda os
  valores de um campo `dropdown` numa tabela própria gerada
  automaticamente ao criar o campo pela UI admin do GLPI — essa lista
  precisa ficar **sincronizada** com `portal_balancas`:
  - Hook no mesmo ponto onde `inventario_balancas.php` já grava
    `INSERT`/`UPDATE`/exclusão em `portal_balancas` (`salvarBalanca()` /
    `excluirBalanca()` no front, handlers correspondentes no backend) →
    upsert/delete espelhado na tabela de dropdown do plugin Fields.
  - Precedente já usado no projeto pra esse tipo de sync bidirecional entre
    tabela própria do portal e catálogo de outro sistema: ver
    [[central-rdp-guacamole-auto-sync]] e
    [[categoria-compartilhada-bidirecional]].
  - Estrutura exata da tabela gerada (nome, colunas) só é conhecida depois
    de criar o campo uma vez pela UI admin do GLPI — confirmar via
    diagnóstico read-only no início do PLAN, antes de escrever o hook.
- **Campo 2 — "Tipo de Defeito"**: `type=dropdown`, `multiple=1`
  (multi-select). Valores **fixos**, sem sincronização com nada: Teclado,
  Placa Principal, Placa Wifi, Célula de Carga, Cabeça Térmica/Impressão,
  Caixa de Junção, Display, Outros.

### Fase 2 — Chamados vinculados no Inventário de Balanças

- **Achado técnico importante:** o relatório que já existe de "equipamento
  com chamado vinculado" (`relatorios_equipamentos.php`) usa
  `glpi_items_tickets` — o vínculo **nativo** do GLPI entre um chamado e um
  item real (`Computer`/`Printer`/`Phone`/`Peripheral`). Balança não é um
  desses tipos, então esse mecanismo **não serve aqui**. O vínculo desta
  feature vem do valor do campo "Equipamento" (Fase 1), gravado na tabela
  gerada pelo plugin Fields pro container 7.
- Em `inventario_balancas.php`, no modal de detalhes de cada balança
  (`abrirDetalhes()`), adicionar uma seção "Chamados vinculados" alimentada
  por um novo endpoint (ex.:
  `inventario_balancas.php?action=chamados_balanca&balanca_id=N`) que faz
  `JOIN` entre a tabela de valores do container 7 e `glpi_tickets`,
  filtrando pela balança aberta no modal.
- Exibição: lista simples (data, título, status, tipo(s) de defeito),
  reaproveitando o padrão visual já existente no modal.

### Fase 3 — Gráfico no BI de Relatórios

- Nova aba "Balanças" em `relatorios.php`, mesmo padrão das abas já
  existentes (`Impressões`, `Equipamentos`).
- Novo endpoint `relatorios_balancas.php`, mesma fonte de dados da Fase 2
  (join container 7 ↔ `glpi_tickets`), filtrado por categoria=162 e
  respeitando o filtro de período já existente na página.
- Dois gráficos (confirmado com o usuário — "os dois"):
  1. **Frequência por tipo de defeito** — geral, por período; como o campo
     é multi-select, um chamado com 2+ defeitos conta em cada um.
  2. **Quebra por loja/balança** — qual balança/loja concentra mais
     chamados, cruzável com tipo de defeito.

## Escopo inclui

- 1 linha nova em `glpi_plugin_fields_containerdisplayconditions`.
- 2 campos novos em `glpi_plugin_fields_fields` (dropdown Equipamento,
  dropdown multi Tipo de Defeito) + tabela(s) geradas pelo plugin ao salvar
  via admin do GLPI.
- Hook de sincronização em `inventario_balancas.php`
  (criar/editar/excluir balança → upsert/delete no dropdown do plugin
  Fields).
- Novo endpoint + seção "Chamados vinculados" no modal de detalhes de
  `inventario_balancas.php`.
- Nova aba + endpoint `relatorios_balancas.php` em `relatorios.php` (2
  gráficos).

## Escopo exclui

- Qualquer alteração em Tabloides ou no container "Equipamentos Novos"
  (id=6).
- Registrar Balança como itemtype nativo do GLPI / usar
  `glpi_items_tickets` — descartado, ver achado técnico da Fase 2.
- Preencher retroativamente chamados antigos de Balanças que não têm os
  campos novos — só vale pra chamados abertos depois do deploy.

## Riscos

- **Médio** — toca em 3 sistemas (plugin Fields do GLPI em produção,
  `inventario_balancas.php`, `relatorios.php`).
- Ponto mais delicado: o campo "Equipamento (Balança)" (tipo `dropdown`)
  precisa ser criado pela UI de admin do GLPI (Configurar → Plugins →
  Fields), não só por SQL direto — o plugin gera a tabela e os metadados
  dinamicamente. Isso será feito/confirmado no início do PLAN.
- O hook de sincronização é a parte mais nova — se a tabela gerada tiver
  estrutura diferente do esperado, a sincronização pode falhar
  silenciosamente sem dar erro visível. Mitigação: confirmar a estrutura
  real via diagnóstico read-only antes de escrever o hook, e adicionar log
  de erro (não silencioso) na sincronização.

## Critérios de aceite

1. Abrir um chamado novo com categoria "Hardware > Balanças" mostra a aba
   "Reparo Equipamentos" com os 2 campos (Equipamento, Tipo de Defeito).
2. Cadastrar/editar/excluir uma balança em Inventário de Balanças reflete
   automaticamente nas opções do campo "Equipamento" do chamado, sem passo
   manual no GLPI.
3. Selecionar múltiplos tipos de defeito ao preencher o chamado salva
   todos.
4. Abrir o modal de detalhes de uma balança no Inventário mostra os
   chamados vinculados a ela (ou mensagem vazia, se nenhum).
5. Aba "Balanças" em Relatórios mostra os 2 gráficos (frequência por tipo
   de defeito; quebra por loja/balança), respeitando o filtro de período
   já existente na página.
6. Nenhuma regressão nas abas/containers existentes (Tabloides,
   Equipamentos Novos, outras abas de Relatórios).
