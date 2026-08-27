# Log de Sessão — 21/06/2026

## Resumo
Correção no `historico.php`: Tipo e Status mostravam números (1, 6) em vez de labels ("Incidente", "Fechado"), e cabeçalho `#` alterado para `ID`.

---

## O que foi corrigido

### 1. Tipo e Status numéricos → labels legíveis
**Problema:** A API GLPI com `expand_dropdowns=true` não converte campos tipo array (status, type) para texto. `$row[12]` retornava `"6"` em vez de `"Fechado"`, e `$row[14]` retornava `"1"` em vez de `"Incidente"`. O código existente tentava usar `$status_rev['Fechado']` num valor que já era `6`, caindo no fallback.

**Solução:** Detecção `is_numeric()` antes de tentar resolver o nome:
- Se `$s_display` é numérico → converte pra int e busca em `$status_map`
- Senão → usa o `$status_rev` (reverso) como antes
- Mesma lógica aplicada ao tipo com `$type_map` / `$type_rev`
- Adicionado `$type_map = [1=>'Incidente', 2=>'Requisição']` (não existia, só tinha o reverso)

### 2. Cabeçalho `#` → `ID`
- HTML: `<th>#</th>` → `<th>ID</th>`
- CSV export: `'#'` → `'ID'`

## Arquivo alterado
| Arquivo | Alteração |
|---------|-----------|
| `historico.php` | Linhas 33-34: adicionado `$type_map`; linhas 117-122: detecção is_numeric + mapeamento; linha 424: `#` → `ID`; linha 209: CSV `#` → `ID` |

## Commits
- Pendente

## Status
- [x] Tipo mostra "Incidente"/"Requisição" em vez de 1/2
- [x] Status mostra "Novo"/"Fechado"/etc. em vez de 1/2/3/4/5/6
- [x] Cabeçalho mostra "ID" em vez de "#"
- [x] CSV export também corrigido
- [x] Copiado para servidor 192.168.1.198
