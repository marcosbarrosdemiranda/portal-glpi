# SPEC — Multi-projetos na Agenda + Resposta Inteligente (GitHub vs Manual com Sincronização de Itens)

**Data:** 2026-10-23 · **Status:** 🟡 Aprovando SPEC
**Tipo:** FEAT · Effort: high · Modo: FULL

## Problema

1. **Multi-projetos na Agenda:** O evento da Agenda do tipo projeto precisa permitir a seleção de **múltiplos projetos** (em formato de chips multi-select, análogo aos responsáveis/co-atendentes), e não apenas um projeto em select único.
2. **Resposta Inteligente (GitHub vs Manual):** Ao clicar em "Responder Chamado" (`abrirModalResposta()`):
   - Se o projeto for do **GitHub**: consultar e puxar os commits do dia em tópicos resumidos.
   - Se o projeto for **Manual**: consultar e puxar os **itens/tópicos** pendentes ou do checklist do projeto manual. Se o usuário marcar/concluir os itens na resposta e salvar, o sistema deve atualizar o status desses itens para concluídos (`concluido = 1`) no projeto manual correspondente (`portal_projetos_manuais_itens`).
3. **Listagem no Select / Multi-select:** Garantir que tanto projetos GitHub quanto projetos manuais com status `em_execucao` apareçam nas opções de seleção.

## Decisão de desenho

### 1. Multi-projetos na Agenda (Chips Multi-select)
- Substituir o `<select id="ev-projeto">` por um container de chips multi-select (`.pm-projeto-chip` ou `.projeto-multi-chip`), idêntico ao padrão de responsáveis (`renderRespChipsPm` / `renderAtendentesMulti`).
- A lista de projetos (GitHub em execução + manuais em execução) alimenta o componente de chips.
- O evento salva um array de projetos selecionados (ou JSON em `co_projetos` / `projeto` na tabela `portal_agenda_eventos` / `agenda/eventos.php`).

### 2. Resposta Inteligente no Modal de Resposta (`agenda/index.php`)
- Quando `abrirModalResposta()` é acionado para um evento associado a um projeto:
  - Verificar se é um **projeto manual** ou **projeto GitHub**.
  - **Se for GitHub:** busca commits do dia via `projeto_commits_dia.php` e preenche `#resp-texto`.
  - **Se for Manual:** busca os itens do projeto manual (via novo endpoint ou função) e preenche `#resp-texto` (ou exibe um checklist interativo de itens do projeto manual na resposta).
  - Ao enviar a resposta (`enviarResposta()`), se houver itens de projetos manuais marcados como concluídos, atualizar a tabela `portal_projetos_manuais_itens` definindo `concluido = 1`.

## Critérios de aceite

1. O modal de evento da Agenda permite selecionar múltiplos projetos em formato de chips.
2. A lista de projetos disponíveis inclui todos os projetos GitHub e manuais em execução.
3. Ao responder um chamado vinculado a um projeto do GitHub, os commits do dia são puxados para a descrição.
4. Ao responder um chamado vinculado a um projeto manual, os itens do projeto são listados e, ao serem marcados e salvos, atualizam o estado de conclusão no projeto manual.
