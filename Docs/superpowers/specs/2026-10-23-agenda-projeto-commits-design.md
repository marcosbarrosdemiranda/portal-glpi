# SPEC — Filtro de Projetos em Execução na Agenda + Puxar Commits do Dia na Resposta

**Data:** 2026-10-23 · **Status:** 🟡 Aprovando SPEC
**Tipo:** FEAT · Effort: high · Modo: FULL
**Branch:** `feat/projetos-manuais` (ou branch atual)

## Problema

1. Na Agenda, ao criar um evento do tipo "Projeto", o select de projetos (`agenda/projetos_lista.php`) lista **todos** os projetos visíveis, independentemente do status (Futuros, Em Execução, Concluídos). O usuário quer que apareçam **apenas os projetos "em execução"**.
2. Quando um evento de agenda do tipo "Projeto" (vinculado a um repositório GitHub) é respondido ("Responder Chamado"), o usuário quer que o sistema **consulte e puxe automaticamente para a descrição da resposta os commits trabalhados naquele dia**, formatados em tópicos resumidos.

## Decisão de desenho

### 1. Filtro de projetos em execução (`agenda/projetos_lista.php`)
- `agenda/projetos_lista.php` passará a consultar:
  - Repositórios GitHub das contas ativas. Para cada repo, verificar seu status na tabela `portal_projetos_status` (se não houver registro, o padrão é `'em_execucao'`). Incluir apenas os que tiverem status `'em_execucao'`.
  - Projetos manuais na tabela `portal_projetos_manuais` com `status = 'em_execucao'`.
- Combinar ambos em uma lista única, ordenada alfabeticamente, e retornar em JSON.

### 2. Puxar commits do dia ao responder (`agenda/projeto_commits_dia.php` + `agenda/index.php`)
- **Novo endpoint `agenda/projeto_commits_dia.php?repo=NOME&data=YYYY-MM-DD`**:
  - Identifica a qual conta GitHub o repositório pertence (iterando pelas contas ativas em `portal_github_contas` e testando com `github_listar_repos()`).
  - Obtém os commits do repositório via `github_commits_completo()` (ou cache).
  - Filtra os commits cuja data (`data` no formato ISO, convertida para `YYYY-MM-DD` no fuso local/UTC) coincida exatamente com a `data` solicitada.
  - Retorna a lista de commits `{sha, msg, autor, data, url}`.
- **Integração no modal de resposta (`agenda/index.php`)**:
  - Quando `abrirModalResposta()` é chamado, verifica se o evento é do tipo `projeto` (ou se o título/projeto corresponde a um repo GitHub conhecido).
  - Se for, extrai a data do evento (`_eventoParaResponder.start`, truncada para `YYYY-MM-DD`) e o nome do projeto (`_eventoParaResponder.titulo` ou `_eventoParaResponder.descricao`).
  - Faz um `fetch('projeto_commits_dia.php?repo=' + encodeURIComponent(repo) + '&data=' + encodeURIComponent(data))`.
  - Se retornar commits, formata o texto em tópicos resumidos e injeta em `#resp-texto`:
    ```
    Commits realizados em YYYY-MM-DD:
    - msg do commit (sha)
    ...
    ```
  - Se não houver commits no dia (ou se for projeto manual / não-GitHub), mantém o campo de texto livre.

## Critérios de aceite

1. O select de projetos no modal de evento da Agenda (`tipo === 'projeto'`) exibe estritamente projetos (GitHub e manuais) com status `'em_execucao'`.
2. Ao clicar em "Responder Chamado" em um evento de projeto do GitHub, o sistema consulta os commits daquele repositório na data do evento.
3. Os commits encontrados aparecem automaticamente em tópicos resumidos no campo de descrição da resposta, permitindo edição pelo usuário antes do envio.
