# Log de Sessão — 29/06/2026
## Correções: Agenda + Chamado + Checklists

---

## 1. Botão "Excluir Chamado" na agenda (agenda/index.php)

**Problema:** Botão não aparecia no modal de evento.

**Causa:** Lógica verificava `ticket_id` em `extendedProps`, mas deveria verificar o `tipo` do evento.

**Correção:**
- Condição alterada para `_isGlpi = tipo === 'chamado' || tipo === 'requisicao'`
- Botão agrupado abaixo de "Excluir da agenda" via `d-flex flex-column`
- `excluirChamadoModal()` usa fallback em `ev-ticket-id` se `dataset.ticketId` estiver vazio

---

## 2. Atendente não atualizava na agenda ao editar pelo Histórico (chamado.php)

**Problema raiz (3 camadas):**

### Camada 1 — GLPI não atualizava (sessão anterior)
- GLPI retorna `[{"id": X}]` (array) para PUT, código verificava só `$res['id']` (object)
- Fix: `$okTec = !empty($resTec['id']) || (!empty($resTec[0]) && !empty($resTec[0]['id']))`

### Camada 2 — Nome curto salvo na agenda
- `CURRENT_ATENDENTE_NOME` usava `nome_user()` → `primeiro_nome()` → "Felix"
- JS `respAtNome` usava `.split(/\s+/).pop()` → último word = "Felix"
- Filtro da agenda compara por nome COMPLETO (`"Agnelo Felix"`)
- Fix: `$user_nomes[$uid]` direto (sem `primeiro_nome`), JS usa `.trim()` sem split

### Camada 3 — Eventos históricos com nome curto no banco
- Script `_fix_atendente_names.php` corrigiu 9 eventos com nome curto
- Eventos afetados: "Felix" → "Agnelo Felix", "Marcos" → "Barros de Miranda Marcos", "Celso" → "Lima Cavalheiro Celso"

---

## 3. Checklists de rotinas adicionados (agenda/index.php)

| Chamado | Itens |
|---------|-------|
| Carga de Balança - Rotina Diária | Loja 001, 003, 010, 030 |
| Carga Geral / PDVs Ligados | Carga geral, PDVs ligados |
| DVRs e Câmeras - Rotina Diária | DVRs, Câmeras |
| Verificar Microfones Câmeras | Câmeras PDVs, Salas Prevenção, Gerencias, Tesourarias, Sala de Reunião |
| Servidores - Rotina Diária | Server Unidades/Matriz, Gunnebo, TS, Integração, ArquiFunc, Backup, Dominio, Delphos |
| Manutenção Preventiva Konica | Recarga, Limpeza lixeira, Unidades de imagem, Belt, Revelador, Limpeza geral |

---

## 4. Outros ajustes

- **Histórico:** Botão "Agenda TI" adicionado na topbar ao lado de "Início"
- **Modal agenda:** Campo descrição com `max-height:180px; overflow-y:auto; resize:vertical`
- **Credenciais servidor:** Conta `externo / gr142536` usada para sync SMB

## Arquivos alterados

| Arquivo | Alterações |
|---------|-----------|
| `agenda/index.php` | Botão excluir, checklists, descrição scroll |
| `chamado.php` | Fix nome completo atendente (GLPI + agenda) |
| `historico.php` | Botão Agenda TI na topbar |

## Sincronização
- [x] Copiado para `\\192.168.1.198\xampp\htdocs\glpi2\portal-glpi\`
