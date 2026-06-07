# Log de Sessão — 06/06/2026 (Parte 3)

## Resumo
Correção de dupla codificação UTF-8 no `agenda/index.php` causada por edições repetidas com PowerShell. Todos os acentos, símbolos e emojis foram restaurados.

---

## Problema
O arquivo `agenda/index.php` foi editado várias vezes com PowerShell 5.1, que lê arquivos como Windows-1252 e reescreve como UTF-8, causando dupla codificação:
- Original UTF-8 `C3 A9` (`é`) → lido como Latin-1 `Ã©` → re-escrito `C3 83 C2 A9`
- Emojis 4-byte `F0 9F 8E AB` (`🎫`) → cada byte mapeado via Windows-1252 → sequência de 8-12 bytes

## Correções aplicadas (3 passagens)

### Passagem 1 — Acentos portugueses (438 ocorrências)
Padrão `C3 83 C2 XX` → `C3 XX` (onde XX = byte original 0xA0-0xBF)
- á, é, í, ó, ú, ç, ã, õ, ê, â, etc.
- Palavras como: `média`, `descrição`, `concluído`, `Botões`, `horário`, `título`

### Passagem 2 — Símbolos 3-byte UTF-8 (1468 ocorrências)
Padrões iniciando com `C3 A2` (corrupção de byte E0-EF original)
- `──` box-drawing (separadores visuais em comentários)
- `→` seta, `—` travessão, `≤` menor-igual
- `✅` checkmark, `❌` cross mark

### Passagem 3 — Emojis 4-byte UTF-8 (21 ocorrências)
Padrões iniciando com `C3 B0 C5 B8` (corrupção de `F0 9F` original)
- `🎫` Chamado GLPI, `📋` Requisição GLPI
- `👥` Reunião, `📅` Evento
- `🔍` Buscar chamado
- `🟢🟡🔴🟣` prioridades (Baixa, Média, Alta, Crítica)
- `📊` logs, `📎` anexos, `📝` períodos, `🔄` atualizações
- `🔒` cadeado, `✅` checkmark em toasts

### Solução técnica
Script PowerShell com substituição binária byte-a-byte usando lookup table reversa de Windows-1252.

## Arquivos alterados
| Arquivo | Alteração |
|---------|-----------|
| `agenda/index.php` | ~1900 correções de bytes, 310 linhas alteradas |

## Commits
- `df09ce7` — fix: corrige dupla codificação UTF-8 em todo agenda/index.php
- `6a98e10` — fix: corrige dupla codificação de emojis nos modais e sidebar
