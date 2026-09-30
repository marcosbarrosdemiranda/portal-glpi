---
name: feat_orcamento-mysql-pdf
description: Migração do módulo de orçamento para MySQL com campos calculados e exportação PDF.
metadata:
  type: project
---

**Why:** O módulo atual dependia de `localStorage`, limitando o acesso e a persistência. Necessário centralizar no banco de dados e integrar aos novos requisitos de campos (Qtd, VlUnit, Total).

**How to apply:**
1. Criar a nova tabela MySQL `portal_orcamento` com colunas calculadas.
2. Criar API PHP transacional (`api/orcamento.php`).
3. Refatorar `orcamento.php` para utilizar a API.
4. Implementar rotina JS (`jspdf`) para exportação de relatórios por Mês/Ano.

[[branch_integracao-infra-docker]]
[[feedback-commit-push-cada-passo]]
