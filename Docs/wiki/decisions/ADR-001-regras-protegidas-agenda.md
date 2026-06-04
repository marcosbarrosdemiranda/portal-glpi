# ADR-001 — Regras de Comportamento Protegidas da Agenda

**Data:** 2026-06-02  
**Status:** APROVADO E TRAVADO  
**Responsável:** Proprietário do sistema  

---

## Contexto

Durante o desenvolvimento da Agenda TI (portal-glpi/agenda/), foram estabelecidas regras de negócio críticas que **não devem ser alteradas sem permissão explícita do responsável**.

---

## Regras Protegidas

### 1. Criação de eventos em datas passadas — BLOQUEADO
- **Onde:** `dateClick` callback no FullCalendar (`agenda/index.php`)
- **Regra:** Não é possível criar nenhum evento clicando em um dia anterior ao dia atual.
- **Motivo:** Impede agendamentos retroativos incorretos.

### 2. Arrastar chamados do sidebar para datas passadas — BLOQUEADO
- **Onde:** `eventReceive` callback (`agenda/index.php`)
- **Regra:** Chamados arrastados do painel lateral não podem ser soltos em datas passadas.
- **Motivo:** Mesma razão do item 1.

### 3. Mover eventos existentes para datas passadas — BLOQUEADO
- **Onde:** `eventAllow` callback (`agenda/index.php`)
- **Regra:** Nenhum evento pode ser reposicionado para um dia anterior ao atual.
- **Motivo:** Consistência histórica; impede edição retroativa acidental.

### 4. Eventos concluídos — NÃO PODEM SER MOVIDOS OU REDIMENSIONADOS
- **Onde:** `editable: !concluido` em `carregarEventos` (`agenda/index.php`)
- **Regra:** Eventos com `concluido = 1` (cor verde) são somente-leitura no calendário.
  - Não podem ser arrastados para outro horário/dia.
  - Não podem ser redimensionados.
  - Podem ser clicados para visualização.
- **Motivo:** Chamado já encerrado; o registro histórico não deve ser alterado.

### 5. Visibilidade de eventos por atendente — ISOLAMENTO GARANTIDO
- **Onde:** `eventosFiltrados()` (`agenda/index.php`)
- **Regra:**
  - Na visão de atendente específico, só aparecem eventos cujo `atendente` corresponde ao filtro.
  - Concluídos com `atendente` vazio (histórico anterior ao sistema) aparecem **apenas** para o usuário logado.
  - **NUNCA** mostrar eventos de um atendente na agenda de outro.
- **Motivo:** Privacidade e organização por técnico.

---

### 6. Fechamento de chamado no GLPI — IMPLEMENTAÇÃO CONGELADA

- **Onde:** `agenda/fechar_ticket.php` + `salvarEventoObj()` em `agenda/index.php`
- **Regras IMUTÁVEIS (testadas e aprovadas pelo responsável):**
  1. **Um único `PUT status=6`** — NÃO fazer dois passos (status=5 → status=6). Testado: quebra o fechamento neste ambiente GLPI.
  2. **Sempre retornar `ok=true`** após o PUT — NÃO adicionar validação de resposta GLPI. O formato de resposta varia por versão do GLPI e a validação causou regressão.
  3. **Sequência obrigatória:** `atualizar_ticket.php` → `.then()` → `fechar_ticket.php`. NUNCA em paralelo. Um PUT paralelo de campos chega ao GLPI depois do fechamento e REABRE o chamado (race condition confirmada).
- **Motivo:** Comportamento correto confirmado em produção. Qualquer "melhoria" nesta área já gerou regressão documentada.

---

## Marcadores no Código

Todas as seções acima estão sinalizadas com:

```
// ⚠️ REGRA PROTEGIDA — NÃO ALTERAR SEM PERMISSÃO DO RESPONSÁVEL ⚠️
```

---

## Alterações Futuras

Qualquer mudança nas regras acima **exige aprovação explícita** do responsável antes de ser implementada. O agente de IA deve recusar alterações nessas seções sem confirmação direta do usuário responsável.
