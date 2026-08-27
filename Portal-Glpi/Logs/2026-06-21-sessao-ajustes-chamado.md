# Log de Sessão — 21/06/2026 (parte 4)

## Resumo
Ajustes no `chamado.php`: auto-preenchimento do campo Fim (+15 min) ao alterar Início, e redirect para histórico após enviar resposta.

---

## O que foi ajustado

### 1. Auto-preenchimento Fim +15 min
- Ao alterar o campo **Data / Início** no formulário Responder Chamado, o campo **Fim** é automaticamente preenchido com a mesma data e horário + 15 minutos
- Código: `resp-start change → new Date(val) + 15 min → resp-end`

### 2. Redirect para Histórico
- Após enviar resposta com sucesso, redireciona para `historico.php` em vez de dar reload na página
- `location.reload()` → `window.location.href = 'historico.php'`

## Arquivo alterado
| Arquivo | Alteração |
|---------|-----------|
| `chamado.php` | +15 min auto no campo Fim; redirect para historico.php após resposta |

## Status
- [x] Início muda → Fim preenche com +15 min
- [x] Após enviar resposta → vai pra historico.php
- [x] Copiado para servidor 192.168.1.198
- [x] Logs copiados para 192.168.1.51
