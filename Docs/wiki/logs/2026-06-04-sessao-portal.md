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

## Ideias Anotadas
1. **Agenda — Configurar ranges de plantão** futuramente via interface (select no topo da agenda)
   em vez de código, permitindo que cada técnico defina seus próprios horários de plantão.
2. **Cores diferentes por tipo de plantão** (ex: amarelo claro para almoço, cinza para após-expediente)
   para tornar a grade ainda mais informativa.
3. **Badge "Plantão"** nos eventos criados dentro desses horários, indicando visualmente que
   foi um atendimento de plantão.
