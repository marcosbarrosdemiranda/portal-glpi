# Log de Sessão — 04/06/2026

## Resumo
Sessão de refinamentos visuais, correções de bugs e novas funcionalidades nos módulos
Projetos, Equipe, Agenda e portal geral.

---

## Módulo Projetos (`projetos.php`)

### Gráfico de Previsão de Término
- Implementado gráfico SVG inline no detalhe do projeto
- Linha azul tracejada = ritmo planejado (0% no início → 100% no prazo)
- Linha cinza tracejada = previsão de conclusão no ritmo atual
- Ponto colorido = progresso real hoje
- Marcadores verticais por módulo (vermelho = módulo em atraso)
- Resumo textual: Início · Prazo · Conclusão prevista · dias de atraso

### Badge de Status
- Adicionado ao cabeçalho do detalhe do projeto
- Estados: Adiantado (azul) / No prazo (verde) / Atenção (amarelo) / Em atraso (vermelho)
- Calculado comparando % real vs % esperado proporcional ao tempo decorrido

### Prazo por Módulo
- Parser atualizado para capturar `> **Prazo:** DD/MM/AAAA` do Obsidian
- Prazo exibido em cada card de módulo
- Card fica vermelho com "Em atraso" se a data passou e módulo < 100%
- PRD atualizado: prazos adicionados nos Módulos 10–14

### Correção do parser (prazo do projeto)
- Bug: `## Project Requirements Document` no PRD fazia parser entrar em modo módulo
  antes de capturar o prazo do projeto
- Fix: distingue prazo do projeto (2 datas) de prazo de módulo (1 data) pela contagem
  de barras `/` na string

---

## Módulo Equipe (`equipe.php`)

### Busca de chamados por técnico
- Corrigida query que usava `Ticket?searchText[_users_id_assign]=` (campo inexistente)
- Nova query: `search/Ticket?criteria[field=5, equals, user_id]&criteria[field=12, lt, 5]`
- `field=5` = técnico atribuído | `field=12 < 5` = status aberto (não resolvido/fechado)

---

## Agenda (`agenda/index.php`)

### Cache no modal de novo chamado
- Corrigido: campos `ev-entidade`, `ev-requerente`, `ev-categoria`, `ev-origem` não eram
  limpos ao abrir novo chamado — ficavam com dados do chamado anterior

### Validação do campo Atendente
- Corrigido: validação checava `multiSel` (chips) para todos os tipos, mas chamado usava
  `ev-atendente` (select) — resultava em erro mesmo com atendente selecionado
- Unificado: todos os tipos agora usam chips, validação sempre verifica `multiSel`

### Padronização dos Atendentes — sempre chips
- Removida alternância entre dropdown (chamado) e chips (reunião/evento)
- Todos os tipos usam chips compactos (Felix · Marcos · Celso)
- `ev-atendente` sempre oculto; `ev-atendentes-multi` sempre visível
- `ajustarCamposPorTipo()` garante consistência em qualquer tipo
- Chamado/Requisição: campo-atendentes = col-md-6 (ao lado de Entidade)
- Reunião/Evento: campo-atendentes = col-12 (linha inteira)

### Layout do modal
- Grid reorganizado para linhas de 6+6=12 em todas as linhas:
  - Entidade (6) | Atendentes (6)
  - Requerente (6) | Categoria (6)
  - Origem (6) | Prioridade (6)
- Label + chips na mesma linha horizontal
- Texto "Clique para selecionar" removido

### Nomes dos atendentes — primeiro nome
- `apelidoAtendente()` retorna só o primeiro nome (última palavra do formato GLPI)
- Aplicado em: select ev-atendente, filtro topbar, modal de seleção, autor de followup
- Chips: Felix · Marcos · Celso (hover mostra nome completo via `title`)

---

## Alias de Entidades — todo o portal

### Criado `entidade_alias.php` (função PHP compartilhada)
- `apelido_entidade(string $nome): string`
  - Decodifica `&#62;` → `>` (GLPI retorna HTML entities)
  - Mapeia nome completo para alias: Lj 001, Lj 003, Lj 010, Lj 030, Lj 101
- `primeiro_nome(string $nome): string`
  - Retorna a última palavra do nome (firstname no formato GLPI realname+firstname)

### Arquivos atualizados com alias
| Arquivo | Ponto corrigido |
|---------|----------------|
| `historico.php` | Coluna entidade na listagem |
| `chamado.php` | Meta-dado entidade no detalhe + autor followup |
| `inventario.php` | Seções por entidade + filtro |
| `notificacoes.php` | Campo entidade nas notificações push |
| `equipe.php` | Nome do técnico no card (primeiro nome) |
| `agenda/glpi_api.php` | Campo `setor` retornado para sidebar |

---

## Módulo Projetos — Obsidian

### Novo Módulo 15 — Acesso Mobile
Adicionado ao PRD com 4 seções e 14 tarefas:
- Acesso na Rede (IP interno, testes, VPN)
- Responsividade CSS (menu hambúrguer, modais, cards)
- Agenda no Mobile (visualização touch)
- PWA (manifest.json, ícone, service worker)

---

## Commits desta sessão
| Hash | Descrição |
|------|-----------|
| `b205ef2` | feat: projetos — gráfico de previsão de término + status + prazo por módulo |
| `dc09cca` | fix: alias de entidades em todo o portal + correções de validação e cache |
| `bb7ca6c` | fix: alias de entidade na sidebar da agenda (glpi_api.php) |
| `6af2d9f` | feat: primeiro nome dos atendentes em todo o portal |
| `e0ad751` | fix: layout do modal de evento — colunas 6+6 em todas as linhas |
| `dff764f` | fix: atendentes sempre como chips compactos em todos os tipos de evento |
| `8d23ffa` | fix: atendentes label+chips na mesma linha horizontal |
| `c36ac9f` | fix: remove texto 'Clique para selecionar' dos atendentes |

---

---

## Horários de Plantão — Destaque Visual na Grade

### Implementação
- Adicionado destaque visual (fundo cinza claro `#f5f3f1`) nas células dos horários de plantão
- Períodos destacados: 06-07h, 11-13h, 17-22h
- Geração via JS dinâmico: injeta `<style>` com regras `.fc-timegrid-slot[data-time="..."]` 
  para cada slot de 30 min dentro dos ranges definidos
- Se adapta automaticamente à granularidade dos slots (30 min)
- Zero impacto em performance — executa uma vez após `calendar.render()`
- Constante `RANGES` facilmente editável no código para adicionar/remover períodos

### Arquivo modificado
| Arquivo | Alteração | Status |
|---------|-----------|--------|
| `agenda/index.php` | Injeção de CSS plantonista após `calendar.render()` (linha 997) | ✅ CONGELADO |

---

## Pendências em aberto
1. **Equipe** — `search/Ticket` ainda retorna 0 chamados para todos os técnicos.
   Debug necessário: criar `debug_equipe.php` temporário e inspecionar retorno da API.
2. **Obsidian compartilhado** — pasta de rede para projetos de todos os técnicos
   (pendência futura, sem data — alterar `$pastaProj` em `projetos.php` quando definido).
3. **Mobile (Módulo 15)** — aguardando início da implementação (prazo 03/07).

---

## Continuação — Correção Concluídos + Descoberta do Índice de Busca

### Problema
Chamados concluídos (status=6) mostravam 0 para todos os técnicos. Ao clicar em um card, o modal detalhado não abria (erro JSON: `Unexpected token '<'` — PHP fatal por função `apelidoAtendente()` ausente em `chamado_ajax.php`).

### Diagnóstico via `_debug_chamados.php`
- Ticket **#9094** (criado pelo portal): GET direto mostra `status=6 (Fechado)`, mas search API mostra `status=3 (Planejado)`
- Ticket **#8878** (criado no GLPI): mesma discrepância — GET direto `status=6`, search API `status=3`
- **Conclusão**: o índice de busca do GLPI está desatualizado — não reflete mudanças de status
- Operadores `lessthan`/`morethan`/`notequals`/`OR` em `field=3` (status) também falham consistentemente
- `_users_id_assign` não aparece em tickets fechados (N/A), mas `Ticket_User` com `type=2` (atribuído) existe

### Correções aplicadas

| Arquivo | Correção |
|---------|----------|
| `chamado_ajax.php` | Adicionada função `apelidoAtendente()` que estava faltando (fatal error) |
| `chamado_ajax.php` | Normalização de arrays do `expand_dropdowns=true` para todos os campos |
| `chamado_ajax.php` | Busca de anexos (docs) dos followups |
| `entidade_alias.php` | `apelido_entidade()` agora aceita string ou array (via is_array check) |
| `equipe_detalhe.php` | **Substituída search API por SQL direto via PDO** — ignora índice de busca quebrado |
| `equipe.php` | Modal de chamado agora com estilo igual ao da agenda (header, grid, descrição, followups, anexos) |

### Solução — SQL direto no banco
Em `equipe_detalhe.php`:
```sql
SELECT t.id, t.name, t.status, t.type, t.date, t.date_mod, e.completename as entity_name
FROM glpi_tickets t
JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.users_id = ? AND tu.type = 2
LEFT JOIN glpi_entities e ON e.id = t.entities_id
WHERE t.is_deleted = 0
-- + filtro opcional de data em t.date
ORDER BY t.date_mod DESC LIMIT 200
```
- Status 1-4 → abertos | Status 5-6 → concluídos
- Filtro de data aplicado no WHERE
- Sem dependência da API REST GLPI (removeu initSession/killSession)

### Pendências
1. **Desempenho em Projetos** — a aba de projetos no modal da Equipe precisa ser implementada (dados já retornados pelo backend, front-end pendente)
2. **Chamados da Equipe** — filtro de data ainda precisa ser aplicado aos projetos (posteriormente)

## Ideias Anotadas
1. **Agenda — Configurar ranges de plantão** futuramente via interface (select no topo da agenda)
   em vez de código, permitindo que cada técnico defina seus próprios horários de plantão.
2. **Cores diferentes por tipo de plantão** (ex: amarelo claro para almoço, cinza para após-expediente)
   para tornar a grade ainda mais informativa.
3. **Badge "Plantão"** nos eventos criados dentro desses horários, indicando visualmente que
   foi um atendimento de plantão.

---

## Correção — Colunas do Banco GLPI (continuação)
As queries SQL estavam usando nomes de coluna errados. Após debug via `_debug_rotinas.php`, constatou-se:

| Coluna esperada | Coluna real | Tabela |
|----------------|-------------|--------|
| `name` | `name` ✅ (inglês) | `glpi_ticketrecurrents` |
| `periodicity` | `periodicity` ✅ (inglês, em segundos) | `glpi_ticketrecurrents` |
| `comment` | `comment` ✅ (inglês) | `glpi_ticketrecurrents` |
| `tickettemplates_id` | `tickettemplates_id` ✅ | `glpi_ticketrecurrents` |
| `value` | `value` ✅ (inglês, não `valor`) | `glpi_tickettemplatepredefinedfields` |
| `nome` | `name` ❌ (inglês) | `glpi_tickettemplates` / `glpi_users` |

**Aprendizado**: as colunas são em **inglês** — o `SHOW COLUMNS` do MySQL traduziu os nomes no output, mas o PDO retorna com as chaves originais em inglês.

## Filtro de Rotinas por Técnico — Corrigido
A query principal agora usa `glpi_tickettemplatepredefinedfields` com `num=5` (users_id_assign) para filtrar as rotinas de cada técnico:

```sql
SELECT tr.id, tr.name, tr.periodicity, tr.comment, tr.tickettemplates_id, tt.name
FROM glpi_ticketrecurrents tr
LEFT JOIN glpi_tickettemplates tt ON tr.tickettemplates_id = tt.id
WHERE tr.is_active = 1 AND tr.entities_id = 0
  AND EXISTS (
    SELECT 1 FROM glpi_tickettemplatepredefinedfields tpf
    WHERE tpf.tickettemplates_id = tr.tickettemplates_id
      AND tpf.num = 5 AND tpf.value = ?
  )
```

Distribuição dos técnicos por template (num=5):
- **ID 7** → templates 2, 4, 8, 22, 24
- **ID 10** → templates 5, 6, 7, 13, 23
- **ID 57** → templates 9, 10, 11, 12, 14, 15

## Modal → Painel Inline (solicitação do usuário)
- Modal Bootstrap substituído por painel inline abaixo da grid de cards
- Animação slideDown (CSS `@keyframes`)
- Clique no mesmo card fecha o painel
- Scroll suave automático até o painel
- Três seções empilhadas verticalmente (sem abas): Rotinas, Chamados, Desempenho
- Badge de frequência corrigido para valores reais de periodicidade

## Interpretação da Periodicidade
`periodicity` armazena segundos ou string textual:
- `86400` = 1 dia → **Diário**
- `604800` = 7 dias → **Semanal**
- `1296000` = 15 dias → **Quinzenal**
- `2592000`+ = 30+ dias → **Mensal**
- `"2 MONTHS"` = string → **Mensal**

## Arquivos modificados/criados nesta sessão
| Arquivo | Alteração |
|---------|-----------|
| `_debug_rotinas.php` | Debug das tabelas (nome real das colunas, dados de ticketrecurrents, num=5, users) |
| `equipe_detalhe.php` | Corrigido: colunas name/periodicity/comment, filtro por técnico via num=5 |
| `equipe.php` | Modal substituído por painel inline com slideDown, fechar ao clicar no mesmo card |

---

## Correções Pós-Resumo — Novo Período, Entidade e Notificações

### 1. Novo Período — `ev-orig-start` não limpo (REGRESSÃO CORRIGIDA)
- **Problema**: `novoPeriodo()` não limpava `ev-orig-start`; ao salvar, o PHP sincronizava TODOS eventos do mesmo ticket com `start = orig_start`, puxando o antigo para o mesmo horário do novo
- **Fix**: `document.getElementById('ev-orig-start').value = '';` no `novoPeriodo()`
- Arquivo: `agenda/index.php` (linha 1944)

### 2. Entidade com `&#62;` em vez de `>` (HTML entities)
- **Problema**: GLPI retorna `&#62;` (HTML entity do `>`) nos nomes de entidade. O mapa `ALIAS_ENTIDADES` no JS usa `>` literal, então o lookup falhava e exibia o nome cru com `&#62;`
- **Fixes**:
  - `agenda/entidades.php`: `html_entity_decode()` no nome antes do `json_encode`
  - `agenda/ticket_descricao.php`: `html_entity_decode()` no nome da entidade retornada
  - `agenda/index.php` (`preencherModal`): seleção de entidade por `data-id` numérico (em vez de comparar string), mesmo padrão do requerente
- Arquivos: `entidades.php`, `ticket_descricao.php`, `index.php`

### 3. Título de chamado acumulando `#9099 – – #9099 – …`
- **Problema**: Ao arrastar chamado da sidebar, o título vira `#<id> – Título`. Esse título ia pro GLPI via `atualizar_ticket.php`. Ao re-adicionar, o GLPI já tinha o prefixo, e outro era adicionado — acumulando infinitamente
- **Fix**: Regex que limpa `#\d+\s*[–-]\s*` do título antes de enviar ao `atualizar_ticket.php`
- Arquivo: `agenda/index.php` (linha 2332)

### 4. Períodos anteriores não ficam verdes ao concluir
- **Problema**: Quando o último período de um chamado era concluído, os períodos anteriores continuavam com status original
- **Fix**: Em `eventos.php?action=save`, se `concluido=1` e `ticket_id` setado, propaga `concluido=1` para TODOS os eventos do mesmo ticket
- Arquivo: `agenda/eventos.php` (linhas 195-201)

### 5. Notificações — SQL direto + som (autoplay)
- **Problemas**:
  - `notificacoes.php` usava API REST GLPI (search) — índice desatualizado podia perder chamados novos
  - `notificacoes.js` usava Web Audio API sem `resume()` — navegadores bloqueavam o som por autoplay policy
- **Fixes**:
  - `notificacoes.php`: Troca para SQL direto via PDO (`glpi_tickets WHERE status=1 AND date > ?`)
  - `notificacoes.js`: Adicionado `audioCtx.resume()` se contexto suspenso; try/catch no segundo bip
- Arquivos: `notificacoes.php`, `assets/notificacoes.js`

## Pendências em aberto
1. **Equipe — Desempenho em Projetos**: backend já retorna dados em `equipe_detalhe.php`, front-end do painel pendente
2. **Obsidian compartilhado** — pasta de rede para projetos de todos os técnicos
3. **Mobile (Módulo 15)** — aguardando início da implementação (prazo 03/07)
