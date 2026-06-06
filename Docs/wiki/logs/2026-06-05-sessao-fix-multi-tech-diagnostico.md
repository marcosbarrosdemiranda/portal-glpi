# Log de Sessão — 05/06/2026 (parte 4)

## Resumo
Diagnóstico e logging para bug multi-atendente + fix ENUM requisicao + validação resposta PHP.

---

## Problema: Multi-atendente só mostra para um técnico

### Status
**AINDA NÃO RESOLVIDO**. Após extensa análise de código, não foi encontrada a causa raiz.

### Análise do Fluxo
O fluxo multi-atendente para chamados novos (2+ técnicos) é:
1. `criar_ticket.php` → cria ticket GLPI com `atendentes_ids` (array)
2. `finalizarMulti(res.ticket_id)` → cria um evento por técnico via `mapTech`
3. `salvarEventoObj(d)` para cada evento → POST individual para `eventos.php?action=save`
4. Cada evento tem: `id` único (uniqEvId), `atendente` correto, `_skipGlpi = true`
5. `eventos.php` → UPDATE → 0 rows → INSERT (evento novo)
6. `calendar.refetchEvents()` → carrega `eventos.php?action=list`
7. `eventosFiltrados()` → filtra por `filtroAtendente`

### Causas Potenciais (não confirmadas)
- PHP salva primeiro evento OK, segundo falha silenciosamente (sem validação de resposta)
- Names com caracteres de escape HTML no dropdown vs raw no DB
- `tipo='requisicao'` não está no MySQL ENUM (corrigido por mapeamento)
- Race condition entre as duas requisições concorrentes

### Correções Aplicadas (commit `c9e1beb`)
- `salvarEventoObj` e `salvarEventoObjAsync`: validam `res.ok` da resposta PHP
- Multi-tech `Promise.all`: adicionado `.catch()` com reload de emergência
- `carregarEventos`: log por atendente (total, contagens)
- `eventosFiltrados`: log de filtrados vs excluídos com detalhes
- Multi-tech flow: log dos dados enviados para cada atendente
- `getAtendentesMultiSelecionados`: filtra IDs NaN
- `eventos.php`: mapeia `'requisicao'` → `'chamado'` (ENUM incompatível)
- Filter dropdown: usa escape mínimo no value para match exato

### Como Testar
Criar chamado multi-tech com console aberto (F12 → Console).
Verificar:
1. `📊 Multi-tech save:` mostra ambos atendentes
2. `📊 carregarEventos:` mostra total e contagem por atendente
3. `📊 eventosFiltrados:` mostra se ambos passam no filtro
4. Se houver `❌ eventos.php save falhou:` no console — esse é o erro

---

## Outras Correções
- **ENUM 'requisicao'**: PHP em `eventos.php` agora mapeia `'requisicao'` → `'chamado'` porque o MySQL ENUM é `('evento','chamado','manutencao','reuniao')` e não inclui `'requisicao'`

### Fix adicional (commit `ef48463`)
- **Edição de chamado multi-tech**: agora deleta eventos antigos via `deleteByTicket` antes de recriar. Antes criava novos eventos sem remover os antigos → duplicatas na agenda de cada técnico.

## Arquivos Modificados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | Validação res.ok, .catch(), console.log diagnóstico, filtro NaN IDs, deleção antigos na edição |
| `agenda/eventos.php` | Mapeamento requisicao → chamado |
