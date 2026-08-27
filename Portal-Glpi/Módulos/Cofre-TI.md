---
tags:
  - modulo
  - cofre
  - documentacao
---

# 🔒 Cofre de TI

> Cofre seguro para senhas, comandos, links, documentações críticas e contatos úteis da TI.

**Status:** ✅ Concluído (10/06/2026)  
**Prazo:** 01/07/2026  
**🏠 Módulo:** [[Bem-vindo|Portal GLPI]]

---

## 📂 Estrutura

| Item | Caminho |
|------|---------|
| Código | `cofre.php` (monolítico — backend + frontend + crypto) |
| Dashboard | `dashboard.php` → link para `cofre.php` |
| Documentação | `Portal-Glpi/Cofre-TI.md` |
| Projetos relacionados | [[Projetos-TI]] |

---

## ✅ Implementado

- [x] **CRUD completo** com criptografia AES-256-CBC
- [x] **5 categorias:** 🔑 Senha, 💻 Comando, 📋 Documentação, 🔗 Link, 📦 Outro *(+ 📞 Contatos Úteis em breve)*
- [x] **Conteúdo mascarado** (`● ● ● ● ●`) na listagem — olho para revelar
- [x] **Cópia sem revelar** — botão copia descriptografado sem exibir na tela
- [x] **Busca** server-side por título, tags e notas (`LIKE`)
- [x] **Filtro por categoria** — pills na toolbar
- [x] **Tags** em chips coloridos
- [x] **Notas** em texto livre
- [x] **Formulário dinâmico por categoria** — cada tipo exibe só os campos relevantes (ex.: Link = título, link, notas). Config central `CAMPOS_POR_CAT`; campos ocultos são limpos para não persistir dado de outra categoria
- [x] **Categoria 📞 Contatos Úteis** — pessoa de referência + telefone/ramal (campo visível, não mascarado), tags e notas
- [x] **Auditoria** — `portal_vault_audit` registra revelar/copiar/criar/editar/excluir/compartilhar/desbloquear + IP e timestamp; modal "Auditoria" na toolbar (últimos 200 eventos)
- [x] **PIN por usuário** — `portal_vault_pin` (hash via `password_hash`); overlay bloqueia o cofre por sessão; cadastro na 1ª vez, desbloqueio e reset
- [x] **Re-trava por inatividade (5 min)** — timer no cliente + backstop no servidor (`vault_last`); também trava ao fechar/sair da aba (`pin_lock` via `sendBeacon`)
- [x] **Compartilhamento por link temporário** — `portal_vault_share` (token + expiração + limite de views); página pública `compartilhar.php` valida e consome visualização
- [x] **Criptografia:** AES-256-CBC, chave derivada de `GLPI_APP_TOKEN + 'cofre_ti_gmais'` via SHA-256
- [x] **Responsivo:** CSS Grid `minmax(300px, 1fr)` com cards

---

## 🟢 Backlog futuro (opcional)

- [ ] Controle de acesso por nível técnico x encarregado — descartado por ora (segurança via auditoria + PIN). Não há perfil "encarregado" no login hoje (`auth.php`)

---

## 📞 Contatos Úteis *(planejado)*

Nova categoria para telefones e contatos importantes do setor, internos e externos.

### Esquema de campos sugerido

| Campo do banco | Uso no contato |
|----------------|----------------|
| `titulo` | Nome do contato / setor |
| `usuario` | Pessoa de referência |
| `conteudo` | Telefone principal (criptografado) |
| `url` | Telefone alternativo / Ramal |
| `tags` | Tipo (TI, RH, Adm, Segurança, Fornecedor...) |
| `notas` | Horário de atendimento, observações |

### Exemplo

```
📞 João Silva — Suporte N2
   📱 (11) 91234-5678
   ☎️ 4567-8901 (ramal 2803)
   🏢 TI / Suporte Técnico
   🕐 08h-18h seg-sex
```

---

## 🔐 Segurança

- Chave de criptografia é derivada de `GLPI_APP_TOKEN` + salt fixo
- Conteúdo só é descriptografado no servidor (nunca no client)
- `reveal` action retorna JSON com o conteúdo descriptografado
- Cache `revelados = {}` em memória JS — limpa ao fechar a aba
- Técnicos+ têm acesso total; self-service é bloqueado

---

## 📌 Observações Técnicas

- Tabela `portal_vault` criada automaticamente via `CREATE TABLE IF NOT EXISTS`
- Nenhum ADR registrado para este módulo — pendente
- A busca por tag já funciona no código, mas PRD ainda marca como pendente
- Todos os inputs escapados com `htmlspecialchars` no output
