# Log de Sessão — 22/06/2026

## Resumo
Melhorias de UI na agenda de atendimentos e correção de bug na página de login.

---

## 1. Fix: campo senha não clicável no login (`auth.php`)

**Problema:** O campo de senha só era clicável na parte superior. No meio e na parte inferior o cursor virava mão (pointer) e o clique não ativava o campo.

**Causa raiz:** A regra CSS `.input-icon .bi` (2 seletores de classe, especificidade maior) aplicava `left: 14px` ao ícone de olho `.toggle-senha`. Com `left: 14px` + `right: 12px` simultâneos, o elemento se esticava por toda a largura do campo com `z-index: 5`, capturando todos os cliques acima do input.

**Primeira tentativa (não funcionou):** Adicionar `left: auto` ao `.toggle-senha` — falhou por especificidade CSS (`.input-icon .bi` sobrescreve).

**Solução correta:** Alterar o seletor para `.input-icon .bi:not(.toggle-senha)`, excluindo o ícone de olho da regra de posicionamento esquerdo.

**Arquivo alterado:** `auth.php`

---

## 2. Campos com busca por digitação no modal de chamado (`agenda/index.php`)

**Implementação:** Adicionada biblioteca **Tom Select 2.3.1** (via CDN) nos campos **Entidade**, **Requerente** e **Categoria** do modal de criação/edição de chamado.

**Comportamento:** Ao clicar no campo, o usuário pode digitar para filtrar as opções em tempo real. As opções ficam sem texto na tag `<option value="">` (vazia) para que o placeholder não apareça como valor selecionado.

**Pontos de integração mantidos:**
- `.value` e `.selectedOptions` do `<select>` original continuam funcionando (Tom Select mantém sincronizado)
- Modo edição: usa `ts.setValue(valor, true)` para preencher sem disparar eventos
- Reset: usa `ts.setValue('', true)` para limpar
- Inserção dinâmica de opção não listada: `ts.sync()` + `ts.setValue()`
- Validação: `marcarInvalido()` atualizada para também marcar `.ts-wrapper.is-invalid`

**Arquivos alterados:** `agenda/index.php`

**CDN adicionados:**
```html
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
```

---

## 3. Botão "Detalhes do Evento" no preview do sidebar (`agenda/index.php`)

**Implementação:** Adicionado botão **Detalhes do Evento** (azul) no popup que aparece ao clicar num chamado do sidebar, ao lado do já existente "Abrir chamado".

**Comportamento:**
- Fecha o popup do sidebar
- Abre o mesmo modal de leitura da agenda (`modalEvento` em modo leitura)
- Carrega dados completos do GLPI via `ticket_descricao.php` (entidade, categoria, requerente, followups)
- Data/hora exibida como "agora" (chamado ainda não está na agenda)
- Botões **Responder** e **Novo Período** ficam disponíveis normalmente
- Botão **Deletar** fica oculto (não há evento na agenda para excluir)

**Técnica:** Variável `_previewTicket` guarda os dados do ticket atual do preview para evitar escaping de strings no `onclick`.

**Função criada:** `verDetalhesSidebar()`

**Arquivo alterado:** `agenda/index.php`

---

## 4. Data/hora do agendamento nos cards do sidebar (`agenda/tickets.php` + `agenda/index.php`)

**Problema:** Chamados já agendados ficavam com badge "Em andamento" no sidebar, mas sem indicar quando estavam agendados.

**Implementação:**
- `tickets.php`: Query atualizada para buscar também o `start` do próximo evento não-concluído de cada ticket. Campo `agenda_start` adicionado ao JSON de retorno.
- `index.php`: Badge adicional ao lado de "Em andamento" mostrando o dia/hora formatado:
  - **Hoje 14:30** — se for hoje
  - **Amanhã 08:00** — se for amanhã
  - **23/06 09:00** — demais datas
- Estilo: azul claro (`#d0e4ff`) para diferenciar do badge "Em andamento" (azul escuro)
- Se o ticket tiver múltiplos eventos, exibe o mais próximo (primeiro `ORDER BY start ASC`)

**Função criada:** `formatAgendaStart(start)` — formata datetime ISO para exibição amigável em pt-BR

**Arquivos alterados:** `agenda/tickets.php`, `agenda/index.php`

---

---

## 5. Perfis de Usuário — controle de acesso por perfil (`perfis.php` + `dashboard.php`)

**Implementação:** Sistema RBAC (controle de acesso por papel) para o dashboard do portal.

### Banco de dados (auto-criado)
| Tabela | Conteúdo |
|--------|---------|
| `portal_perfis` | id, nome, descricao, cards (JSON), criado_em |
| `portal_perfil_usuarios` | user_id (GLPI), perfil_id — chave primária em user_id (1 perfil por usuário) |

### `perfis.php` — nova página
- Lista perfis com badge de quantidade de cards e usuários
- Criação/edição de perfil:
  - Nome + descrição
  - Grid de cards por seção (Atendimento, KPIs, Recursos, Acessos, Gestão de TI, Configuração)
  - Cada card tem botões **Ouvinte** (👁) e **Interagir** (✏️) — seleção visual com cores
  - Lista de usuários GLPI ativos com checkboxes para atribuição
- Exclusão com confirmação
- Acessível via card "Perfis de Usuário" na seção Configuração do dashboard

### `dashboard.php` — filtro por perfil
- Carrega o perfil do usuário logado via `user_id` da sessão
- Se não tem perfil atribuído → vê **todos** os cards (comportamento padrão / admin)
- Se tem perfil → vê apenas os cards liberados
- Seções (labels) são ocultadas automaticamente se nenhum card da seção estiver visível
- Card "Perfis de Usuário" adicionado na seção Configuração

### Modo ouvinte vs interagir
- Armazenado no JSON e disponível para uso futuro nas páginas individuais
- Fase atual: controla visibilidade do card no dashboard
- Fase futura: cada página verificará `$_SESSION['perfil_cards']` para restringir ações

**Arquivos criados/alterados:** `perfis.php` (novo), `dashboard.php`

---

## Arquivos alterados nesta sessão
| Arquivo | Alteração |
|---------|-----------|
| `auth.php` | Fix: campo senha clicável em toda a área (`:not(.toggle-senha)`) |
| `agenda/index.php` | Tom Select nos campos Entidade/Requerente/Categoria |
| `agenda/index.php` | Botão "Detalhes do Evento" no preview do sidebar |
| `agenda/index.php` | Badge de data/hora do agendamento nos cards do sidebar |
| `agenda/tickets.php` | Query atualizada para retornar `agenda_start` |
| `perfis.php` | Novo — CRUD de perfis de usuário com cards e atribuição |
| `dashboard.php` | Filtro de cards por perfil + card "Perfis de Usuário" |

## Sincronização com servidores
- [x] Copiado para `\\192.168.1.198\xampp\htdocs\glpi2\portal-glpi\`
- [ ] Copiado para `\\192.168.1.51\...` (pendente confirmar path)

## Status
- [x] Fix login campo senha
- [x] Tom Select com busca por digitação (Entidade, Requerente, Categoria)
- [x] Botão "Detalhes do Evento" no sidebar
- [x] Data/hora do agendamento nos cards do sidebar
- [x] Perfis de Usuário — CRUD completo + filtro no dashboard
