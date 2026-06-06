# Log de Sessão — 05/06/2026 (parte 3)

## Resumo
Correção do bug de eventos duplicados (red + green no mesmo horário) ao concluir chamados pela resposta.

---

## Problema

### Bug: Chamado concluído via "Responder" aparecia duplicado (vermelho + verde lado a lado)
- Usuário abria chamado na agenda → clicava "Responder" → digitava resposta e marcava "Concluir chamado"
- Ao enviar, o chamado era fechado no GLPI corretamente, mas na agenda aparecia duplicado:
  - Um evento vermelho (aberto, `concluido=0`) — o original
  - Um evento verde (concluído, `concluido=1`) — criado pelo fallback
- Após F5 (recarregar), o evento vermelho sumia e só restava o verde

### Causa Raiz
Em `eventos.php?action=concluir_ticket`, o UPDATE usava:

```sql
WHERE ticket_id = :ticket_id AND concluido = 0
```

Em alguns cenários de borda (múltiplos eventos para o mesmo ticket, possível inconsistência de `ticket_id` entre o snapshot JS e o valor no DB), o UPDATE retornava `rowCount() = 0`. Isso ativava o fallback no JavaScript (`enviarResposta()`), que criava um **novo** evento com `concluido=1` — mas o evento original (com `concluido=0`) permanecia no banco. Resultado: dois eventos lado a lado no mesmo horário.

---

## Correções Aplicadas

### 1. `agenda/eventos.php` — `action=concluir_ticket` (linhas 62-109)
**Antes:**
```sql
WHERE ticket_id = :ticket_id AND concluido = 0
```

**Depois:**
```sql
WHERE ticket_id = :ticket_id
```

Além disso, após o UPDATE, faz uma consulta COUNT para verificar se eventos existem no DB:
- Se existem (`$exists > 0`): retorna `updated = 1` (independente de rowCount do UPDATE)
- Se não existem: retorna `updated = 0` (evento foi deletado, JS recria)

Marcado como ⚠️ REGRA PROTEGIDA — remoção do `AND concluido = 0` é intencional para evitar race condition.

### 2. `agenda/index.php` — fallback do `enviarResposta()` (linha 1919)
**Antes:** `ticket_id: snap.ticket_id`
**Depois:** `ticket_id: parseInt(ticketId)`

Usa o mesmo `ticketId` enviado ao `concluir_ticket` em vez de `snap.ticket_id`, garantindo consistência entre a consulta e o fallback.

---

## Arquivos Modificados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/eventos.php` | Remove `AND concluido = 0` do WHERE; adiciona COUNT pós-UPDATE |
| `agenda/index.php` | Fallback usa `parseInt(ticketId)` em vez de `snap.ticket_id` |
