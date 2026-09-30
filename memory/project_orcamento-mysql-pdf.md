---
name: feat_orcamento-mysql-pdf
description: Migração do módulo de orçamento para MySQL com campos calculados e exportação PDF.
metadata:
  type: project
---

**Why:** O módulo atual dependia de `localStorage`, limitando o acesso e a persistência. Necessário centralizar no banco de dados e integrar aos novos requisitos de campos (Qtd, VlUnit, Total).

**How to apply:**
1. Criar a nova tabela `glpi_portal_orcamento` no banco `glpi2` (reusando `$pdo` do GLPI).
2. Refatorar `orcamento.php` para realizar CRUD diretamente via PHP/SQL, eliminando `localStorage`.
3. Adicionar campos: Qtd (Prev/Real), VlUnit (Prev/Real), Total (Prev/Real - calculado no banco).
4. Implementar rotina JS (`jsPDF`) para exportação dos relatórios por Mês/Ano.

[[branch_integracao-infra-docker]]
[[feedback-commit-push-cada-passo]]
