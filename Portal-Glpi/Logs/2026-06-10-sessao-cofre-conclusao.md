---
date: 2026-06-10
status: concluida
author: Claude
tags:
  - cofre
  - seguranca
  - auditoria
  - pin
  - compartilhamento
  - frontend
  - backend
---

# Log de Sessão: Conclusão do Cofre TI

## Objetivo
Finalizar o módulo Cofre TI: formulário dinâmico por categoria, categoria Contatos Úteis,
auditoria de acessos, PIN por usuário e compartilhamento por link temporário.

## Banco de dados (esclarecimento)
O portal **não usa banco separado** — tudo no MySQL do GLPI (banco `glpi2`, conexão única
em `agenda/db.php`). As tabelas do portal usam prefixo `portal_` (ex.: `portal_vault`),
sem tocar nas tabelas nativas `glpi_*`.

## Entregas

### 1. Formulário dinâmico por categoria (`cofre.php`)
- Objeto `CAMPOS_POR_CAT` define, por categoria, quais campos exibir + label/placeholder/altura.
- Link = título + link/URL + notas (conforme pedido). Campos ocultos são **limpos** para não
  persistir dado de outra categoria.
- Helper `setCampo()` substitui o antigo `ajustarCampos()` espalhado.

### 2. Categoria 📞 Contatos Úteis
- Nova categoria `contato`: pessoa de referência (`usuario`) + telefone/ramal (`url`, **visível**,
  não mascarado), tags e notas. Decisão: telefone NÃO vai no campo mascarado `conteudo`.
- Badge `cat-contato`, botão de filtro e opção no select.

### 3. Auditoria (`portal_vault_audit`)
- Helper `vault_audit()` registra: reveal, copy, create, update, delete, share, share_view,
  pin_unlock, pin_set, pin_reset — com user_id, nome, IP e timestamp.
- `reveal` interno do modal/edição usa `ctx=edit` para NÃO contar como acesso.
- Modal "Auditoria" na toolbar lista os últimos 200 eventos.

### 4. PIN por usuário (`portal_vault_pin`)
- Hash via `password_hash`. Overlay bloqueia o cofre: cria PIN na 1ª vez, desbloqueia por
  sessão (`$_SESSION['vault_unlocked']`), e "Esqueci o PIN" reseta (login do portal já autentica).
- Todas as ações de dados retornam 403 `locked` enquanto não desbloqueado.

### 5. Compartilhamento por link temporário (`portal_vault_share` + `compartilhar.php`)
- `share_create`: gera token (`bin2hex(random_bytes(24))`), expiração (5min–24h) e limite de
  views (1–20). Modal por card.
- `compartilhar.php` é **público** (sem login): valida token/expiração/views, consome 1
  visualização, descriptografa e exibe; registra `share_view` na auditoria.

### 6. Refatoração
- Crypto (`VAULT_KEY` + `vault_encrypt/decrypt`) extraída para `vault_crypto.php`, usada por
  `cofre.php` e `compartilhar.php`.

## Decisões do responsável
- Senha mestra → **PIN por usuário** (não global).
- Controle de acesso por nível → **descartado** por ora; segurança via auditoria + PIN.
- Compartilhamento → **link público temporário** com expiração e limite de views.

## Arquivos
- `cofre.php` — formulário dinâmico, contatos, auditoria, PIN, share (backend + frontend)
- `vault_crypto.php` — **novo**, crypto compartilhada
- `compartilhar.php` — **novo**, página pública de visualização
- `Docs/wiki/projects/portal-glpi-prd.md` — Cofre 100% concluído
- `Portal-Glpi/Módulos/Cofre-TI.md` — doc atualizada

## 7. Sessão e inatividade (transversal ao portal)
- **Logout por inatividade de 60 min no portal inteiro:** novo `auth_guard.php`
  (session_start + checagem de `last_activity`). Incluído no topo de ~38 páginas protegidas
  (substituiu o `session_start();` de cada uma). `auth.php` e arquivos de debug ficaram de fora.
  - Substituição feita em **nível de bytes** para preservar encoding/BOM/emojis.
  - Polling em background NÃO conta como atividade: requisições com `?bg=1` não renovam o
    relógio. As notificações (`assets/notificacoes.js`) agora enviam `bg=1` e, ao receber
    **HTTP 440**, redirecionam para `auth.php?timeout=1` — é o "heartbeat" que desloga a aba ociosa.
  - `auth.php?timeout=1` exibe aviso "Sua sessão expirou por inatividade".
- **Re-trava do Cofre por inatividade de 5 min:** independente do portal.
  - Backend: `$COFRE_IDLE=300`; gate de ações e `pin_status` checam `vault_last`; ação `pin_lock`.
  - Frontend: timer de 5 min resetado por atividade real; trava ao fechar/trocar aba
    (`pagehide` → `sendBeacon(pin_lock)`); revalida ao voltar à aba (`visibilitychange`).

## Decisões adicionais do responsável
- Timeout do portal: **60 min**. Escopo: **portal inteiro**.
- Cofre re-trava em **5 min** de inatividade + ao sair/fechar a aba.

## Arquivos (sessão de segurança)
- `auth_guard.php` — **novo**, guarda de sessão com timeout
- `assets/notificacoes.js` — poll marcado como `bg=1` + tratamento de 440
- `auth.php` — aviso de sessão expirada
- ~38 páginas protegidas — `session_start();` → `require auth_guard.php`

## Pendente / próximos
- [ ] Teste manual ponta-a-ponta no XAMPP (criar PIN, revelar, compartilhar, abrir link público, ver auditoria)
- [ ] Testar timeout: deixar aba ociosa e confirmar logout (60 min portal / 5 min cofre)
- [ ] (Opcional) Controle de acesso por nível, se surgir perfil "encarregado" no login
