# Nome do Projeto — Nome Legível

> **Objetivo:** Uma frase descrevendo o propósito do projeto.
> **Equipe:** Ex.: 1 Encarregado + 2 Técnicos
> **Prazo:** Ex.: 30 dias — 03/06/2026 → 03/07/2026
> **Repositório:** https://github.com/<usuario>/<repo>

<!--
  Versão mantida/canônica deste formato: skill "prd-projeto" em
  https://github.com/marcosbarrosdemiranda/prd-projeto-skill (instalável
  globalmente em ~/.claude/skills/prd-projeto). Este arquivo é só a cópia
  local pra quem abrir a pasta sem a skill instalada — se o formato mudar,
  atualizar lá primeiro e replicar aqui.
-->

---

<!--
  FORMATO EXIGIDO — lido por parseProjeto() em projetos.php.
  Nada fora desse formato é interpretado (vira texto decorativo, ignorado
  pelo card/% de progresso). Copie este arquivo pra começar um projeto novo.

  REGRAS DE NOMENCLATURA (docPrdDoProjeto() / carregarProjetosDaPasta()):
  - Pasta: Docs/wiki/projects/<nome-exato-do-repo-no-github>/
    (comparação é case-insensitive, mas use o mesmo nome do repo)
  - Arquivo principal: <nome-do-repo>-prd.md (ou qualquer "*-prd.md" que
    contenha o nome do repo; na falta, cai pra "prd.md", README, ou o
    primeiro .md da pasta — mas o nome <repo>-prd.md é o mais seguro)
  - Pastas que começam com "_" (como esta) são IGNORADAS pelo parser —
    use pra templates, docs auxiliares, ADRs, etc. Nunca um projeto real.

  CABEÇALHO (linhas "> **Chave:** valor", só dentro do bloco antes do
  primeiro "## "):
  - Objetivo / Equipe / Prazo / Repositório — todas opcionais, mas use
    sempre que der (tudo opcional é melhor que nada pro card).
  - Prazo: se tiver DUAS datas (dd/mm/aaaa) no texto, a 2ª é o fim do
    projeto, usada pra calcular "previsão de término" x prazo real.
    Qualquer separador entre as datas funciona (→, -, até, etc.).

  MÓDULOS (cada "## Título" que não seja "Cronograma" nem "Progresso"
  vira um módulo — nome do heading = nome exibido):
  - "> texto" logo após o "##" (antes de qualquer tarefa) = descrição
    do módulo, mostrada no card.
  - "> **Prazo:** dd/mm/aaaa" dentro do módulo = prazo SÓ desse módulo
    (não confundir com o prazo do projeto, que tem 2 datas).
  - "### Subtítulo" agrupa tarefas visualmente (campo "sub", hoje não
    exibido no card, mas fica no dado).
  - "- [x] texto" = tarefa feita · "- [ ] texto" = tarefa pendente.
    Cada uma conta como 1 "funcionalidade" no total do projeto.

  "## Progresso Geral" (tabela markdown) — é **decorativo**. O parser
  não lê tabelas; o % real vem 100% da contagem de "- [x]"/"- [ ]" nos
  módulos abaixo. Mantenha a tabela só por legibilidade humana, e
  atualize os dois (tabela + checkboxes) juntos pra não ficarem
  contraditórios.

  "## Cronograma" — só o conteúdo DENTRO de um bloco ``` ``` é lido,
  linha por linha, no formato "Semana N (dd/mm-dd/mm) → descrição".
-->

## Progresso Geral

| Módulo | Status | Conclusão |
|--------|--------|-----------|
| Nome do Módulo 1 | 🟡 Em andamento | ~0% |

---

## Módulo 1 — Nome do Módulo
> Descrição curta do que esse módulo entrega.
> **Prazo:** dd/mm/aaaa

### Subseção (opcional)
- [ ] Tarefa pendente
- [x] Tarefa já feita

---

## Cronograma

```
Semana 1 (03/06-09/06) → Levantamento e setup inicial
Semana 2 (10/06-16/06) → Módulo 1
```
