# Log de Sessão — 22/06/2026
## Perfil do Portal — Controle de Acesso e Modo Ouvinte

---

## 1. Perfil do Portal carregado no `auth_guard.php`

**Problema:** Usuários com perfil GLPI `self-service` que tinham um perfil do portal atribuído (ex: Direção) eram redirecionados para o dashboard e não conseguiam acessar as páginas liberadas no perfil.

**Causa raiz:** 25+ páginas PHP têm a verificação `if (self-service → redirect dashboard)`. Sem alterar todos os arquivos, não havia como deixar esses usuários entrar.

**Solução:** `auth_guard.php` — único ponto de entrada de todas as páginas — agora:
1. Carrega o perfil do portal do banco uma vez por sessão → `$_SESSION['portal_perfil_cards']`
2. Se o usuário é `self-service` no GLPI mas tem perfil do portal atribuído → `$_SESSION['perfil'] = 'portal'`
3. Todas as verificações de `self-service` nos 25+ arquivos passam automaticamente sem nenhuma alteração neles

**Tabelas usadas:**
- `portal_perfis` — id, nome, cards (JSON `{chave: 'ouvinte'|'interagir'}`)
- `portal_perfil_usuarios` — user_id (GLPI), perfil_id

**Arquivo alterado:** `auth_guard.php`

---

## 2. Modo ouvinte na Agenda de Atendimentos (`agenda/index.php`)

**Variável PHP:** `$is_ouvinte = ($agenda_modo === 'ouvinte')` com `$agenda_modo` lido de `$_SESSION['portal_perfil_cards']['agenda']`

**Variável JS:** `const MODO_OUVINTE = <?= $is_ouvinte ? 'true' : 'false' ?>`

**Restrições aplicadas para ouvinte:**
| Elemento | Solução |
|----------|---------|
| Botão "Novo Evento" | Oculto via PHP (`<?php if (!$is_ouvinte): ?>`) |
| Calendário FullCalendar | `editable: false`, `droppable: false` |
| `dateClick` (criar clicando na data) | Guard `if (MODO_OUVINTE) return` |
| Drag do sidebar | `iniciarDrag()` retorna early se `MODO_OUVINTE` |
| Botão "Editar" no banner-readonly | Oculto via PHP |
| Botões Deletar/Responder/Novo Período no modal | Ocultos via JS quando `MODO_OUVINTE` |
| Checkbox "Marcar como concluído" | `setModoLeitura()` oculta `.col-concluido` quando `MODO_OUVINTE` |
| Botão Salvar | Protegido em `mostrarSalvarSeConcluido()` com `if (MODO_OUVINTE) return` |
| `.querySelector('button')` | Protegido com `?.` / `if (btn)` para evitar erro quando botão não existe |

**Arquivo alterado:** `agenda/index.php`

---

## 3. Dashboard — 3 cards fixos para self-service + perfil do portal (`dashboard.php`)

**Problema:** Usuário self-service com perfil do portal só via os cards do perfil, perdendo os 3 cards básicos (Abrir Chamado, Meus Chamados, Área do Conhecimento).

**Solução:** Variável `$is_self_glpi = ($perfil === 'self-service' || $perfil === 'portal')`.
- `'portal'` só é atribuído pelo auth_guard a usuários que eram `self-service` → sem necessidade de logout/login
- Se `$is_self_glpi`: exibe os 3 cards sempre no topo
- Cards do perfil aparecem abaixo, **sem repetir** `abrir_chamado` e `conhecimento` (já exibidos no topo)
- Labels de seção ("Atendimento", "Recursos") ajustadas para não aparecer vazias

**Arquivo alterado:** `dashboard.php`

---

## 4. Modo ouvinte no Histórico de Chamados (`historico.php` + `chamado.php`)

### `historico.php`
- Variável: `$hist_ouvinte = ($_cards_hist !== null) && (card['historico'] === 'ouvinte')`
- Oculta: botão 🗑️ de cada linha + coluna da lixeira no cabeçalho

### `chamado.php` (página de detalhes do chamado)
- Usa a mesma chave `historico` do perfil
- Oculta: botões **Editar** e **Excluir** do cabeçalho
- Oculta: seção inteira **Responder Chamado** (form + envio)
- Resultado: ouvinte lê detalhes, followups e anexos — sem nenhuma ação

---

## 5. Modo ouvinte no Orçamento de TI (`orcamento.php`)

- Variável: `$orc_ouvinte` (chave `orcamento`)
- JS: `const MODO_OUVINTE_ORC`
- Oculta: botão "+ Novo Item"
- Oculta na renderização JS: ícones de lápis (editar) e lixeira (excluir) por linha

---

## 6. Modo ouvinte no Inventário (`inventario_balancas.php`)

- Variável: `$inv_ouvinte` (chave `inventario`)
- JS: `const MODO_OUVINTE_INV`
- Oculta (PHP): botões "+ Servidor MGV" e "+ Balança" na toolbar
- Oculta (JS template): botões "+ balança", editar e excluir de cada servidor na listagem dinâmica
- Mantém visível: botão "Verificar Status" (ação de leitura)

---

## Padrão para aplicar modo ouvinte em nova página

```php
// Topo da página
$_cards_xxx  = $_SESSION['portal_perfil_cards'] ?? null;
$xxx_ouvinte = ($_cards_xxx !== null) && (($_cards_xxx['chave_card'] ?? 'ouvinte') === 'ouvinte');
```

```php
// HTML — envolver qualquer botão de ação com:
<?php if (!$xxx_ouvinte): ?> ... <?php endif; ?>
```

```js
// Para elementos renderizados via JS:
const MODO_OUVINTE_XXX = <?= $xxx_ouvinte ? 'true' : 'false' ?>;
// Nos templates: ${MODO_OUVINTE_XXX ? '' : `...botões...`}
```

---

## Arquivos alterados nesta sessão

| Arquivo | Alteração |
|---------|-----------|
| `auth_guard.php` | Carrega perfil do portal na sessão; eleva `self-service` → `portal` |
| `agenda/index.php` | Modo ouvinte completo (botões, drag, edição, concluído) |
| `dashboard.php` | 3 cards fixos no topo para self-service; `$is_self_glpi` sem precisar de logout |
| `historico.php` | Oculta botão excluir por linha para ouvinte |
| `chamado.php` | Oculta Editar, Excluir e seção Responder para ouvinte |
| `orcamento.php` | Oculta Novo Item e botões editar/excluir por linha para ouvinte |
| `inventario_balancas.php` | Oculta botões de criação/edição/exclusão para ouvinte |

## Sincronização
- [x] Copiado para `\\192.168.1.198\xampp\htdocs\glpi2\portal-glpi\`
