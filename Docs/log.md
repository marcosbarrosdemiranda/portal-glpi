# Timeline de Atividades Macro

## [2026-06-02] — Sessão 2 — Paste de imagem + bug evento verde sumindo
- **Tipo:** fix + feat
- **Arquivo alterado:** `agenda/index.php`
- **feat:** Colar imagem da área de transferência (`Ctrl+V`) no modal "Responder Chamado"
  - Paste capturado no modal inteiro e no textarea
  - Thumbnail 32×32 nos chips de imagem (clique para ver em tamanho real)
  - Flash visual na drop zone ao colar
  - Dica `Ctrl+V` exibida na drop zone
- **fix crítico:** Evento concluído sumia do grid após ~5 segundos (race condition)
  - **Causa:** `verificarAtrasados.php` deletava eventos antigos (concluido=0, end < último domingo) e chamava `calendar.refetchEvents()` ~5s após o carregamento. Enquanto `fechar_ticket.php` aguardava resposta da API GLPI, o refetchEvents concorrente removia o evento do store do FullCalendar. Quando `enviarResposta()` tentava `calendar.getEventById(evId)`, recebia `null` → save de `concluido=1` era silenciosamente ignorado → evento ficava sem concluido=1 no DB → próximo `refetchEvents()` não o exibia mais.
  - **Correção:** Captura snapshot do evento (plain object) em `abrirModalResposta()` no momento do clique do botão, antes de qualquer race condition. `enviarResposta()` usa o snapshot (`_eventoParaResponder`) em vez de `calendar.getEventById()`. O snapshot usa `_dropCache` como fallback para eventos recém arrastados (sem `atendente` nos `extendedProps` do FC). Se o evento foi deletado do DB pelo `verificarAtrasados`, o INSERT fallback em `eventos.php` o recria com `concluido=1`.

---

## [2026-06-02] — Sessão de expansão do portal — novos módulos e correções de agenda
- **Tipo:** fix + feat
- **Arquivos alterados:**
  - `agenda/index.php` — correções críticas de comportamento drag/drop/resize
  - `agenda/eventos.php` — sem alteração direta
  - `inventario.php` — busca de IPs via NetworkPort → NetworkName → IPAddress
  - `inventario_detalhes.php` — reescrito com cURL multi + SO detalhado + programas instalados
  - `relatorios.php` — nova aba Monitor SLA
  - `dashboard.php` — reorganização em seções + novos cards
- **Arquivos novos:**
  - `ping.php` — verifica status online/offline via TCP 445 + ICMP
  - `projetos.php` — gestão de projetos de TI (localStorage)
  - `equipe.php` — visão da equipe via API GLPI
  - `orcamento.php` — controle de orçamento (localStorage)
  - `contratos.php` — gestão de contratos com alertas de vencimento (localStorage)
  - `licencas.php` — licenças de software via API GLPI
  - `sla.php` — stub (Monitor SLA integrado ao relatorios.php)
  - `cofre.php` — cofre de senhas/comandos criptografados (AES-256, banco glpi2)
  - `acessos.php` — central de acessos a ferramentas (RDP, VNC, pfSense, VMware, etc.)
  - `vnc.php` — gerenciador VNC com viewer noVNC embutido ⚠️ PENDENTE (ver abaixo)

### Correções na Agenda (agenda/index.php)
- **Bug duplicação no drop:** `eventReceive` disparava `eventChange` via `setProp/setEnd`.
  Corrigido com flag `_inEventReceive` que suprime `eventChange` durante mutações programáticas.
- **Bug tipo/atendente perdidos no resize:** `eventChange` não enviava `atendente_id` nem `concluido`.
  Corrigido adicionando todos os campos + fallback via `_dropCache` (cache local do drop).
- **Bug evento não sumia ao excluir:** FullCalendar mantém eventos drag em fonte separada.
  Corrigido com `calendar.getEventById(id).remove()` explícito antes do `refetchEvents()`.
- **Campos adicionados ao `eventChange`:** `atendente_id`, `concluido`, `orig_start` (para sync de co-atendentes ao mover).

### Inventário
- `ping.php` criado (estava faltando — causava todos offline).
- IPs buscados via `NetworkPort → NetworkName → IPAddress` (3 chamadas bulk à API GLPI).
- `inventario_detalhes.php` reescrito com cURL multi (6 requisições em paralelo).
- Adicionados: SO detalhado (versão, arquitetura, kernel) e programas instalados.
- Programas: `Item_SoftwareVersion` (IDs brutos) + `SoftwareVersion/{id}?expand_dropdowns` em lotes de 30 para obter nome real do software.

### Relatórios — Monitor SLA
- Aba "🚦 Monitor SLA" adicionada ao `relatorios.php` (6ª aba).
- Busca chamados abertos (status 1,2,3) independente do filtro de data.
- Semáforo por urgência: Alta=4h · Média=8h · Baixa=12h.
- Tabela ordenada: vermelhos → amarelos → verdes.
- Acesso direto via `relatorios.php?tab=sla`.
- Card SLA removido do dashboard (acesso consolidado no Painel de Relatórios).

### Dashboard — reorganização em seções
| Seção | Módulos |
|-------|---------|
| 📞 Atendimento | Agenda · Abrir Chamado · Histórico |
| 📊 KPIs | Painel de Relatórios · Inventário |
| 📚 Recursos | Área do Conhecimento · Cofre TI |
| 🖥️ Acessos | Acesso Remoto · Infraestrutura · Ferramentas ERP |
| 💼 Gestão de TI | Projetos · Equipe · Orçamento · Contratos · Licenças |

### Cofre TI (cofre.php)
- Tabela `portal_vault` no banco `glpi2`.
- Criptografia AES-256-CBC (chave derivada do GLPI_APP_TOKEN).
- Categorias: Senha, Comando, Documentação, Link, Outro.
- Conteúdo mascarado na listagem; reveal e copy individuais.
- CRUD completo com modal + tags + notas.

### Central de Acessos (acessos.php)
- Tabela `portal_acessos` com defaults pré-populados.
- 3 grupos: Acesso Remoto · Infraestrutura · Ferramentas ERP.
- Ferramentas padrão: Remote Desktop, VNC, AnyDesk, pfSense, VMware, Mikrotik, UniFi.
- URL configurável por card via ⚙️ (admin).
- RDP: gera e baixa arquivo `.rdp` com o hostname configurado.
- VNC: card aponta para `vnc.php`.

---
## ⚠️ PENDENTE — VNC (vnc.php) — parou aqui em 02/06/2026

**Status:** Página criada e funcional no portal. Dependência de infraestrutura ainda não instalada.

**O que foi feito:**
- `vnc.php` criado com CRUD de máquinas VNC (tabela `portal_vnc`).
- Senhas criptografadas (AES-256).
- Geração de `tokens.cfg` para websockify.
- Viewer noVNC embutido em overlay full-screen no portal.
- Guia de instalação integrado na página.

**O que falta para funcionar:**
1. Baixar e instalar **noVNC** em `C:\xampp\htdocs\novnc\`
2. Baixar **websockify.exe** em `C:\xampp\htdocs\novnc\utils\`
3. Configurar **RealVNC** em cada máquina: Encryption=`Prefer off`, Auth=`VNC password`
4. Rodar websockify na porta 6080 com o `tokens.cfg` gerado pelo portal
5. Cadastrar as máquinas em `vnc.php` com IP, porta e senha
6. Opcional: registrar websockify como serviço Windows via NSSM

**Constantes a ajustar em `vnc.php` (linha ~10):**
```php
define('VNC_PROXY_HOST', '192.168.1.198');  // IP do servidor XAMPP
define('VNC_PROXY_PORT', '6080');           // Porta do websockify
define('NOVNC_PATH',     '/novnc/vnc.html');// Path do noVNC no XAMPP
```

---

## [2026-06-01] — Sessão de correções e melhorias
- **Tipo:** fix + feat
- **Arquivos alterados:** `portal/index.php`, `portal/api_atendente.php`, `agenda/config.php`, `agenda/glpi_api.php`, `agenda/index.php`
- **Arquivo novo:** `agenda/ticket_descricao.php`
- **Correções:**
  - GLPI_URL corrigido de `/glpi_prod` para `/glpi2` (servidor XAMPP 192.168.1.198)
  - Portal técnico: removidas referências JS a `sel-status`/`sel-impacto` que travavam o botão "Adicionar"
  - Portal técnico: requerente não perde seleção ao interagir com outros campos
  - Adicionados timeouts cURL (5s/15s/20s) e AbortController 40s no fetch
- **Funcionalidades:**
  - Requerente: `<select>` dropdown pré-selecionado com usuário logado
  - Agenda: descrição do chamado exibida no modal (busca automática do GLPI se vazia)
  - Agenda: campo `descricao` incluído nos dados de tickets novos
- **Revertido:** `nome_curto_entidade()` — causou erro fatal em produção

## [] — Inicializacao do Workspace
- **Tipo:** chore
- **Descricao:** Criacao da estrutura de documentacao integrada Claude e estabelecimento do contrato de engenharia (CONTRIBUTING.md).
