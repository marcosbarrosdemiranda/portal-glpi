---
date: 2026-11-10
status: resolvido
author: AI Agent
tags:
  - agenda
  - fix
  - log-sessao
---

# Log de Sessão: Pré-chamado perdia o segundo técnico ao converter em chamado real

## Contexto e Objetivo

Usuário reportou que pré-chamados com título "tabloides" com dois
técnicos marcados ficavam só com um técnico depois de convertidos em
chamado real no GLPI — o segundo não era atribuído na criação.

## Diagnóstico

- Rastreado o fluxo completo: `converterEmChamado()` (`agenda/index.php`)
  → `criar_ticket.php` (cria o chamado real via REST API do GLPI) →
  `eventos.php?action=save` (atualiza o espelho local do evento na
  Agenda).
- `criar_ticket.php` já montava `_users_id_assign` corretamente como
  array com todos os técnicos selecionados.
- Hipótese inicial: a REST API do GLPI truncaria o array e só
  atribuiria o primeiro técnico — baseada em mismatch histórico
  (tickets antigos com só 1 técnico em `glpi_tickets_users` apesar de
  intenção de 2).
- **Teste ao vivo** (aprovado pelo usuário): criado 1 chamado real de
  teste com 2 técnicos (`[10, 7]` — Felix e Marcos) pelo exato fluxo de
  `criar_ticket.php`, depois soft-deletado. Resultado: GLPI atribuiu os
  **dois corretamente** (`CommonITILObject::updateActors()` itera o
  array certo). Isso derrubou a hipótese inicial.
- Causa real encontrada em `eventos.php` (~linha 297):
  `$co_atendentes = $body['co_atendentes'] ?? null;` — quando o payload
  do save não inclui `co_atendentes`, vira `NULL` e é persistido no
  UPDATE, apagando qualquer técnico secundário já salvo no evento. O
  bug nunca foi do lado do GLPI — foi só o espelho local da Agenda, que
  `converterEmChamado()` atualizava mandando apenas o técnico primário.

## Correção Realizada

- `converterEmChamado()` (`agenda/index.php`) agora monta `co_atendentes`
  com o restante do `multiSel` (tudo exceto o primeiro) e inclui esse
  array no body do `fetch('eventos.php?action=save', ...)`, espelhando
  o que o GLPI já recebe.
- Nenhuma mudança em `criar_ticket.php` nem `eventos.php` — o fix é só
  no caller (`index.php`) passar o dado que já tinha.

## Arquivos Modificados

- `agenda/index.php` (+5 linhas, dentro de `converterEmChamado()`)

## Deploy

- Validado com `php -l` no container (`glpi-web`) antes de publicar.
- Publicado em produção via fluxo padrão: `scp` como `.new` →
  `docker exec glpi-web php -l` → `Move-Item -Force`, com verificação de
  `LastWriteTime` confirmando que o `Move-Item` rodou.

## Git

- Branch `fix/prechamado-converter-co-atendentes` → PR #22 → merge em
  `feat/orcamento-projeto` (commit `ce990e2`) → branch local e remota
  apagadas.

## Lição / Padrão a vigiar

- A REST API do GLPI (`_users_id_assign` como array) é confiável pra
  atribuir múltiplos técnicos numa chamada só — não assumir truncamento
  sem testar ao vivo primeiro.
- `eventos.php?action=save` usa `??` pra campos opcionais do payload:
  qualquer campo ausente vira `NULL` e sobrescreve o valor anterior
  (sem merge parcial). Mesmo formato de bug pode recorrer em outro
  campo opcional desse handler se o caller não mandar o valor completo
  a cada save.

## Pendências

- Nenhuma. Fix implementado, testado e em produção.
