# Log de Sessão — 21/06/2026 (parte 5)

## Resumo
Correção de bug: eventos criados no slot "Dia Inteiro" da agenda apareciam no dia anterior.
Ajustes no chamado.php: auto-preenchimento Fim (+15 min) e redirect para histórico.

---

## Bugs corrigidos

### 1. Eventos "Dia Inteiro" sumindo da agenda
**Sintoma:** Ao criar um evento no slot "Dia Inteiro" (ex: 23/06), o evento aparecia no dia anterior (22/06 à noite) e "sumia" do dia esperado.

**Causa raiz:** `new Date("2026-06-23")` em JavaScript interpreta data ISO sem timezone como **UTC** meia-noite. Em GMT-4 (Bonito/MS), isso resulta em `2026-06-22 20:00` local. `toDatetimeLocal()` então formatava como `"2026-06-22T20:00"`, salvando o evento no dia errado.

**Solução:** Parse manual da string de data usando `new Date(+p[0], +p[1]-1, +p[2])` que cria a data em timezone local.

**Arquivo:** `agenda/index.php:2046-2053`

### 2. Auto-preenchimento Fim (+15 min)
**Arquivo:** `chamado.php` — evento `change` no campo Início calcula automaticamente Fim +15 min.

### 3. Redirect para histórico após resposta
**Arquivo:** `chamado.php` — `location.reload()` → `window.location.href = 'historico.php'`

## Arquivos alterados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | Fix UTC → local no parse de data do Dia Inteiro |
| `chamado.php` | Auto +15 min no Fim; redirect p/ historico.php |

## Status
- [x] Fix agenda: data do Dia Inteiro agora usa timezone local
- [x] Chamado: auto-preenchimento Fim +15 min
- [x] Chamado: redirect para histórico após resposta
- [x] Copiado para servidor 192.168.1.198
