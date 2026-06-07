# Log de Sessão — 05/06/2026 (parte 2)

## Resumo
Correção do bug de entidade em chamados recorrentes (entidade raiz mudava para Lj 030), ajuste no sync para capturar tickets de templates recorrentes independente da entidade, correção do `preencherModal` para tratar `entidade_id=0`, e grid da agenda começando às 05:00.

---

## Problema identificado

### Bug: Entidade de chamados recorrentes mudava de raiz para Lj 030
- Template em `glpi_ticketrecurrents` configurado com `entities_id=0` (entidade raiz)
- Tickets criados pelo cron do GLPI a partir desse template iniciavam com `entities_id=0`
- Ao abrir o modal na agenda, `preencherModal()` chamava `ticket_descricao.php` que retornava `entidade_id=0`
- Em JS, `0` é falsy → condição `if (selEnt && d.entidade_id)` falhava
- Select da entidade ficava no primeiro option da lista (Lj 030, entities_id=3)
- Ao salvar, `atualizar_ticket.php` recebia `entidade_id=3` e enviava `entities_id=3` ao GLPI via PUT
- **Resultado**: chamado de rotina agora tinha entidade Lj 030

### Sync perdia tickets com entidade alterada
- `sync_rotinas_ajax.php` e `sync_rotinas.php` filtravam `WHERE t.entities_id = 0`
- Tickets que tiveram entidade alterada (ex: "Importação de Vendas", agora entities_id=3) não eram mais encontrados
- Ficavam invisíveis na agenda

### Grid começava às 06:00
- `slotMinTime: '06:00:00'` — rotinas criadas às 05:00 não apareciam

---

## Correções aplicadas

### 1. `agenda/index.php` — `preencherModal` (linha 1500)
**Antes:** `if (selEnt && d.entidade_id)` — falhava quando `d.entidade_id = 0`
**Depois:** `if (selEnt && d.entidade_id !== undefined && d.entidade_id !== null)` — aceita 0 corretamente

### 2. `agenda/atualizar_ticket.php` (linha 27-32)
**Antes:** `$entidade_id = isset($body['entidade_id']) && $body['entidade_id'] ? ...`
**Depois:** `$entidade_id = null` — FORÇADO. Nunca envia `entities_id` no PUT.
- Marcado como ⚠️ REGRA PROTEGIDA

### 3. `agenda/sync_rotinas_ajax.php` + `agenda/sync_rotinas.php`
**Antes:** `WHERE t.entities_id = 0`
**Depois:**
```sql
WHERE t.entities_id = 0
   OR EXISTS (
     SELECT 1 FROM glpi_ticketrecurrents tr
     WHERE tr.is_active = 1 AND tr.entities_id = 0 AND t.name = tr.name
   )
```
Isso captura tickets de templates recorrentes mesmo se a entidade foi alterada.

### 4. `agenda/index.php` — grid (linha 822)
**Antes:** `slotMinTime: '06:00:00'`
**Depois:** `slotMinTime: '05:00:00'`

---

## Arquivos modificados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | Fix `preencherModal` (entidade=0) + grid 05:00 |
| `agenda/atualizar_ticket.php` | Bloqueio total de entities_id no PUT |
| `agenda/sync_rotinas_ajax.php` | Query expandida via subquery glpi_ticketrecurrents |
| `agenda/sync_rotinas.php` | Query expandida via subquery glpi_ticketrecurrents |

## Pendências
1. Observar se "Importação de Vendas - Rotina Diária" aparece no sync hoje
2. Remover `debug_rotinas.php` após validação
