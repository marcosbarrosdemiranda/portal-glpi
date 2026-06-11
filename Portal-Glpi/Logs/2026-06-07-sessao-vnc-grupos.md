---
date: 2026-06-07
status: concluida
author: Claude
tags:
  - vnc
  - rdp
  - grupos-dinamicos
  - guacamole
  - infraestrutura
---

# Log de Sessão: VNC funcional + Grupos Dinâmicos RDP/VNC

## Objetivo
Implementar acesso VNC funcional via Apache Guacamole e refatorar categorias estáticas (servidor/coletor/pc) para grupos dinâmicos cadastráveis pelo usuário.

## Realizado

### VNC funcional via Guacamole
- Diagnóstico de falha "estação remota não pode ser encontrada" no Guacamole
- **Causa raiz:** RealVNC Server com `Encryption: Always on` — incompatível com libvncclient do guacd
- **Solução:** Configurar RealVNC Server → Options → Security → Encryption: `Prefer off`
- Testado e confirmado: Guacamole conecta VNC normalmente após desabilitar criptografia
- `guacamole_conectar.php` já trata ambos os protocolos (RDP e VNC) corretamente (linhas 17-20)

### noVNC + websockify (fallback)
- websockify instalado via `pip install websockify` (Python)
- Rodando na porta 6080
- `vnc.php` atualizado para usar websockify direto (NOVNC_BASE + TokenFile)
- Mantido como alternativa, mas via Guacamole é o caminho principal

### Grupos Dinâmicos — Central VNC (`vnc_central.php`) ✅ COMPLETO
- Criada tabela `portal_vnc_grupos` com: id, nome, icone, cor_bg, cor_fundo, cor_badge, cor_text, ordem
- 3 defaults: Servidores, Coletores, PCs Estratégicos
- Hardcoded `$cats` substituído por carregamento dinâmico do banco
- Actions PHP: `list_grupos`, `save_grupo`, `delete_grupo`
- Ao excluir grupo, máquinas movidas para o primeiro grupo disponível
- Modal "Gerenciar Grupos VNC" com editar/excluir
- JS dinâmico: `GRUPOS = []`, `carregarTudo()`, `carregarGrupos()`, `renderizarSelectCategoria()`
- Botão "Grupos" visível na página principal (acima dos filtros)

### Grupos Dinâmicos — Central RDP (`rdp_central.php`) ✅ COMPLETO
- Criada tabela `portal_rdp_grupos` com mesma estrutura
- Mesmo padrão de implementação da Central VNC
- Migração automática de categorias antigas (servidor/coletor/pc → Servidores/Coletores/PCs Estratégicos)
- Botão "Grupos" visível na página principal

### Correções de migração
- Dados existentes com `categoria` em lowercase (`'servidor'`, `'coletor'`, `'pc'`) são convertidos automaticamente para os novos nomes de grupo capitalizados na primeira execução

## Detalhes Técnicos

### Fluxo de Grupos Dinâmicos
1. Na primeira execução, `portal_{rdp/vnc}_grupos` é criada com 3 defaults
2. `$cats` e `$cat_lista` populados do banco (não mais hardcoded)
3. Validações `in_array` usam `$cat_lista` em vez de array fixo
4. Front-end: `GRUPOS = []` carregado via `list_grupos` → `renderizarSelectCategoria()`
5. `renderStats()`, `renderFiltro()`, `renderLista()` iteram sobre `GRUPOS` em vez de array fixo
6. Modal de grupos permite criar/editar/excluir com nome, ícone e cores

### RealVNC + Guacamole
- RealVNC Server precisa: `Encryption: Prefer off`
- Senha VNC limitada a 8 caracteres (RFB protocol)
- Porta padrão: 5900
- Guacamole guacd: porta 4822 (Docker), bridge network

## Arquivos modificados
- `vnc_central.php` — refatorado para grupos dinâmicos + botão Grupos
- `rdp_central.php` — refatorado para grupos dinâmicos + botão Grupos
- `vnc.php` — atualizado para websockify via Python (pip)
- `Docs/wiki/projects/portal-glpi-prd.md` — atualizado status

## Pendente
- [ ] Central AnyDesk (próximo passo)
- [ ] SSH via browser (xterm.js)
- [ ] Documentar RealVNC Encryption requirement na wiki
