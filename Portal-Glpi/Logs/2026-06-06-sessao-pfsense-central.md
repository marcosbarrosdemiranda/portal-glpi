---
date: 2026-06-06
status: concluida
author: Claude
---

# Log de Sessão: Central pfSense — Portal de Lojas

## Contexto e Objetivo

O card pfSense na Central de Acessos era um link único, mas na realidade cada loja tem seu próprio firewall pfSense. O objetivo era transformar esse card em uma página central listando todas as lojas com IP, credenciais criptografadas e acesso rápido em um clique.

**Regra importante:** Zero alteração nos firewalls — sem ativar API, sem configurar nada nos pfSense.

## Implementações Realizadas

### Nova página: `pfsense_lojas.php`
- Lista todas as lojas com pfSense em cards organizados
- Cada card mostra: loja, IP, usuário
- **Botão "Abrir"** → abre `https://{ip}` em nova aba (usuário loga manualmente)
- **Botão "👁️ Ver senha"** → revela/oculta a senha criptografada
- **Botão "📋 Copiar"** → copia senha para área de transferência
- Admin: botões de editar/excluir + modal para adicionar nova loja

### Banco de dados
- Nova tabela `portal_pfsense_lojas`:
  - `id`, `loja`, `ip`, `usuario`, `senha_enc`, `ativo`, `ordem`, `created_at`
- Senha criptografada com AES-256-CBC (mesma chave do Cofre TI)
- Loja 001 já populada com os dados fornecidos

### Atualização na Central de Acessos (`acessos.php`)
- Card pfSense agora aponta para `pfsense_lojas.php`
- Função `abrirUrl()` melhorada: URLs locais (sem `http`) abrem na mesma aba; URLs externas abrem em nova aba

## Fluxo do Usuário

```
Dashboard → Central de Acessos → card pfSense
  → clica → pfsense_lojas.php (mesma aba)
    → vê lista de lojas com IPs
    → "Abrir" → https://{ip} (nova aba) → login manual
    → "👁️" → revela senha → "📋" → copia → cola no login
```

## Arquivos Criados
- `pfsense_lojas.php` — Central pfSense com lojas e credenciais criptografadas

## Arquivos Modificados
- `acessos.php` — pfSense aponta para nova página + `abrirUrl()` navegação inteligente
