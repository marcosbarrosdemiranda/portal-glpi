# Log de Sessão — 12/06/2026

## Resumo
Módulo de inventário de balanças completo: CRUD, sync automático com Firebird do MGV 6, status online/offline.

---

## Inventário — Balanças

### Mudança
- `inventario_balancas.php` — **novo** — página completa de inventário de balanças com:
  - CRUD de servidores MGV (nome, IP, conexão Firebird)
  - CRUD de balanças (identificação, modelo, série, loja, departamento)
  - Sync automático via PDO_Firebird ou ODBC Firebird
  - Status online/offline via ping nos servidores MGV
  - Layout acordeão por servidor (mesmo padrão do inventário_pcs)
- `inventario.php` — card "Balanças" ativado (antes era placeholder "Em breve")

### Arquivos
| Arquivo | Alteração |
|---------|-----------|
| `inventario_balancas.php` | Novo — página completa com CRUD + sync Firebird |
| `inventario.php` | Card Balanças ativado (link para novo módulo) |

### Arquitetura
- Tabelas `portal_servidores_mgv` e `portal_balancas` criadas no MySQL (CREATE TABLE IF NOT EXISTS)
- Possui sync opcional com Firebird do MGV 6 — tenta PDO_Firebird, fallback ODBC
- Compatível com futura migração para consulta direta ao banco do MGV

## Commits
- `feat: inventário de balanças com CRUD + sync MGV Firebird`
