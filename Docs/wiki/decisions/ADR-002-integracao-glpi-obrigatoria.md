# ADR-002 — Integração Obrigatória com GLPI para Chamados e Requisições

**Data:** 2026-06-02  
**Status:** APROVADO E GRAVADO  
**Responsável:** Proprietário do sistema  

---

## Regra Principal

> **TODA operação sobre um CHAMADO ou REQUISIÇÃO na Agenda TI DEVE ter integração direta e imediata com o GLPI.**
>
> A Agenda TI é uma INTERFACE da fila do GLPI — não um sistema paralelo. O GLPI é a fonte da verdade.

---

## Operações e Integrações Obrigatórias

### 1. CRIAR chamado pela agenda
- **Endpoint:** `criar_ticket.php`
- **O que deve ir para o GLPI:**
  - `name` ← título
  - `content` ← descrição
  - `type` ← 1=Incidente (chamado) / 2=Requisição
  - `urgency` + `priority` ← prioridade
  - `itilcategories_id` ← categoria
  - `entities_id` ← entidade/loja
  - `requesttypes_id` ← origem
  - `_users_id_requester` ← requerente
  - `_users_id_assign` ← atendente
- **Todos os campos são obrigatórios** se preenchidos no modal. Campos opcionais do GLPI só são enviados se preenchidos.

### 2. EDITAR / SALVAR chamado existente
- **Endpoint:** `atualizar_ticket.php` (chamado via `salvarEventoObj`)
- **Condição:** chamado sempre para `tipo === 'chamado'` ou `tipo === 'requisicao'` com `ticket_id`
- **O que deve ir para o GLPI (via PUT `/Ticket/{id}`):**
  - `name` ← título
  - `content` ← descrição
  - `type` ← tipo
  - `urgency` + `priority` ← prioridade
  - `itilcategories_id` ← categoria
  - `entities_id` ← entidade/loja
  - `requesttypes_id` ← origem
- **Requerente** → atualizado via `PUT /Ticket_User/{id}` ou `POST /Ticket_User`
- **Atendente** → atualizado via `atribuir_ticket.php` (separado)
- **NUNCA** salvar somente na agenda sem refletir no GLPI.

### 3. ARRASTAR chamado do sidebar para a agenda
- **Endpoints:** `atribuir_ticket.php` (atendente) + `eventos.php?action=save` (agenda)
- **O que atualiza no GLPI:** atendente técnico atribuído ao ticket
- **Status do ticket:** muda para "Em atendimento" (status 2) ao atribuir técnico

### 4. RESPONDER chamado
- **Endpoint:** `responder_ticket.php`
- **O que cria no GLPI:** `ITILFollowup` (acompanhamento)
- **Com anexos:** `POST /Document` vinculado ao followup

### 5. FECHAR chamado
- **Endpoint:** `fechar_ticket.php`
- **O que atualiza no GLPI:** `status = 6` (Fechado)
- **Na agenda:** evento fica verde (`concluido = 1`)

### 6. EXCLUIR chamado da agenda
- **Endpoint:** `resetar_ticket.php`
- **O que atualiza no GLPI:** `status = 1` (Novo), remove técnico atribuído
- **Na agenda:** evento removido da tabela `glpi_plugin_agenda_events`

---

## O que NÃO precisa de integração GLPI

| Tipo de evento | Integração GLPI? | Motivo |
|---------------|-----------------|--------|
| `chamado` | ✅ SIM — SEMPRE | Ticket real no GLPI |
| `requisicao` | ✅ SIM — SEMPRE | Ticket real no GLPI |
| `reuniao` | ❌ NÃO | Evento interno da agenda |
| `evento` | ❌ NÃO | Evento interno da agenda |

---

## Arquivos PHP de Integração

| Arquivo | Função GLPI |
|---------|------------|
| `criar_ticket.php` | POST /Ticket — cria chamado/requisição |
| `atualizar_ticket.php` | PUT /Ticket/{id} + PUT /Ticket_User — atualiza todos os campos |
| `atribuir_ticket.php` | POST /Ticket_User type=2 — atribui técnico |
| `responder_ticket.php` | POST /ITILFollowup + POST /Document — resposta com anexos |
| `fechar_ticket.php` | PUT /Ticket/{id} status=6 — fecha o chamado |
| `resetar_ticket.php` | PUT /Ticket/{id} status=1 + DELETE Ticket_User — devolve para fila |
| `ticket_descricao.php` | GET /Ticket/{id} — lê dados completos do chamado |
| `tickets.php` | GET /Ticket — lista chamados abertos (sidebar) |
| `sync_rotinas_ajax.php` | GET /Ticket — sincroniza rotinas do dia |
| `verificar_atrasados.php` | GET + PUT + DELETE — limpa chamados vencidos |

---

## Regra para o Agente de IA

> Ao implementar qualquer funcionalidade nova ou alterar código existente relacionado a chamados/requisições:
>
> 1. **Verificar se a operação tem integração GLPI** — se não tiver, adicionar.
> 2. **Nunca salvar só na agenda** sem refletir no GLPI para chamados/requisições.
> 3. **Reuniões e Eventos** são internos — não têm integração GLPI e não precisam ter.
> 4. Esta regra é PERMANENTE e não pode ser ignorada sem aprovação explícita do responsável.
