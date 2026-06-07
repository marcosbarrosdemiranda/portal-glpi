# Log de Sessão — 06/06/2026 (Parte 2)

## Resumo
Finalização das pendências do Módulo 3 — Histórico de Chamados: filtros por data/entidade, exportação CSV e histórico do requerente.

---

## Filtros por Data e Entidade no Histórico

### Mudança
Adicionados filtros de período (data inicial/final) e entidade no `historico.php`, utilizando o formato de `criteria` da API GLPI.

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `historico.php` | Função `glpi_tickets()`: parâmetros `dt_ini`, `dt_fim`, `entidade_id` via GLPI criteria + nova função `carregarEntidades()` |

### Detalhes Técnicos
- **Filtro de data**: `field=15` (date), `searchtype=morethan`/`lessthan` — criteria indexado dinamicamente
- **Filtro de entidade**: `entities_id=` na query string da API
- **Dropdown de entidades**: busca via `/Entity` API, exibe nomes com `apelido_entidade()`
- Campos mapeados: `entidade_id` e `requerente` extraídos da resposta da API

---

## Exportação CSV

### Mudança
Adicionada exportação CSV no `historico.php` e `meus_chamados.php`, acionada via `?export=csv`.

### Funcionalidades
- BOM UTF-8 para acentos no Excel
- Separador `;` (formato PT-BR)
- Colunas: #, Título, Tipo, Status, Urgência, Entidade, Abertura, Atualização, Requerente
- Fetch de até 100.000 registros sem paginação para exportação completa
- Botão "Exportar CSV" visível apenas quando há resultados
- Preserva todos os filtros ativos na exportação

---

## Histórico do Requerente (Self-Service)

### Mudança
`meus_chamados.php` foi reescrito com a mesma engine do `historico.php` — agora usa criteria GLPI com `field=4` (users_id_requester) para buscar APENAS chamados do usuário logado com suporte completo a filtros, paginação e exportação.

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `meus_chamados.php` | Reescrevido: nova função `get_meus_chamados()`, filtros, paginação, CSV, tabela completa |

### Funcionalidades
- **Filtro fixo**: `criteria[0][field]=4&criteria[0][searchtype]=equals&criteria[0][value]=USER_ID` — garante que o usuário só vê os próprios chamados
- **Filtros opcionais**: status, tipo, data inicial, data final
- **Paginação**: 200 por página, com navegação e elipses
- **Exportação CSV**: mesmo formato do histórico completo
- **Tabela**: #, Título, Tipo, Status, Urgência, Entidade, Abertura, Atualização
- **Estatísticas**: total, exibindo, novos, em andamento, resolvidos/fechados
- **Clique na linha**: redireciona para `chamado.php?id=X` (detalhe do chamado)

### Performance
Diferente da implementação anterior que fazia N chamadas individuais (`Ticket_User` + `Ticket`), agora faz UMA única chamada à API com criteria, suportando paginação nativa do GLPI.

---

## PRD Atualizado

### Mudança
`portal-glpi-prd.md` atualizado:
- Módulo 3: status alterado de 🟡 ~70% para 🟢 100%
- Pendências de filtros, exportação e self-service movidas para concluídas

### Commits Pendentes
- (incluir no próximo commit)
