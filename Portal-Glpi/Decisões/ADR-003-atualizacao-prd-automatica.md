# ADR-003 — Atualização Automática do PRD a Cada Funcionalidade

**Data:** 2026-06-03
**Status:** ATIVO

---

## Contexto

O projeto usa `Docs/wiki/projects/portal-glpi-prd.md` como fonte de verdade
do escopo e progresso. O portal de Projetos lê esse arquivo diretamente.
Para que o progresso reflita a realidade, o PRD precisa estar sempre atualizado.

## Decisão

**A cada funcionalidade confirmada pelo responsável como concluída:**

1. O agente DEVE atualizar `Docs/wiki/projects/portal-glpi-prd.md`:
   - Marcar a tarefa como `- [x]` se estava `- [ ]`
   - Adicionar nova entrada `- [x]` se é funcionalidade nova não prevista
   - Adicionar novos itens `- [ ]` para pendências que surgirem

2. Atualizar a tabela de **Progresso Geral** no topo do PRD se o % mudou.

3. Atualizar `Portal-Glpi/Tarefas/backlog.md`:
   - Mover item de pendente para `✅ Concluído Recentemente`
   - Adicionar novas pendências se surgirem

4. Fazer commit + push com mensagem descritiva.

## Regra de Ouro

> O PRD no Obsidian deve sempre refletir o estado real do portal.
> Se o portal tem, o PRD tem `- [x]`. Se falta, tem `- [ ]`.

## Impacto

- `Docs/wiki/projects/portal-glpi-prd.md` — atualizado a cada sessão
- `Portal-Glpi/Tarefas/backlog.md` — mantido em sincronia
- `projetos.php` — exibe automaticamente o progresso atualizado
