---
date: 2026-06-06
status: concluida
author: Claude
---

# Log de Sessão: Central pfSense — Proxy + Auto-login + Player Embutido

## Contexto e Objetivo

O card pfSense na Central de Acessos era um link único, mas na realidade cada loja tem seu próprio firewall pfSense. O objetivo era transformar esse card em uma página central listando todas as lojas com acesso rápido.

**Regra importante:** Zero alteração nos firewalls — sem ativar API, sem configurar nada nos pfSense.

### Refinamento pós-primeira versão

O usuário testou a primeira versão e pediu:
1. ❌ **Não abrir nova janela** — queria o pfSense na mesma página
2. ❌ **Sem cert warning** — site não seguro ao abrir HTTPS
3. ❌ **Sem digitar senha** — queria auto-login

Solução: PHP proxy (pfsense_proxy.php) que faz o meio-de-campo entre o navegador e o pfSense.

## Implementações Realizadas

### PHP Proxy: `pfsense_proxy.php`
- **Auto-login:** cURL faz POST com credentials no pfSense (ignorando SSL), mantém cookie de sessão
- **Proxy de páginas:** toda requisição ao pfSense passa pelo nosso servidor — browser nunca vê o IP do pfSense diretamente
- **URL rewriting:** links, scripts, imagens e formulários do pfSense são reescritos para passar pelo proxy
- **Suporte a POST:** formulários do pfSense (regras, alterações) funcionam através do proxy
- **Renovação de sessão:** se a sessão do pfSense expirar, o proxy reloga automaticamente

### Player Embutido (mesma página)
- **View "Lista"** → mostra as lojas em cards
- **View "Player"** → barra superior + iframe com o pfSense proxyzado
- **Barra superior do player:**
  - `← Voltar` — retorna à lista de lojas
  - Nome da loja + IP
  - `🔄 Recarregar` — reload do iframe
  - `🔗 Abrir externo` — abre pfSense diretamente (fallback)
- Clique em "Abrir" → esconde a lista, mostra o player com pfSense já logado

### Banco de dados
- Tabela `portal_pfsense_lojas` com dados criptografados (AES-256-CBC)

### Fluxo do Usuário
```
Dashboard → Central de Acessos → card pfSense
  → pfsense_proxy.php (mesma aba)
    → vê lista de lojas
    → clica "Abrir" na Loja 001
      → barra superior + iframe com pfSense já logado
      → navega pelo pfSense sem cert warning, sem redigitar senha
    → clica "← Voltar" → volta pra lista
```

## Arquivos Criados
- `pfsense_proxy.php` — Central pfSense + proxy com auto-login

## Arquivos Modificados
- `acessos.php` — pfSense aponta para pfsense_proxy.php + abrirUrl() navegação inteligente

## Arquivos Removidos
- `pfsense_lojas.php` — substituído pelo proxy (versão consolidada)
