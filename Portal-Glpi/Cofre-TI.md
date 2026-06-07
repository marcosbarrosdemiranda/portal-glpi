---
tags:
  - modulo
  - cofre
  - documentacao
---

# 🔒 Cofre de TI

> Cofre seguro para senhas, comandos, links, documentações críticas e contatos úteis da TI.

**Status:** 🟡 Em andamento (~70%)  
**Prazo:** 01/07/2026

---

## 📂 Estrutura

| Item | Caminho |
|------|---------|
| Código | `cofre.php` (monolítico — backend + frontend + crypto) |
| Dashboard | `dashboard.php` → link para `cofre.php` |
| Documentação | `Portal-Glpi/Cofre-TI.md` |

---

## ✅ Implementado

- [x] **CRUD completo** com criptografia AES-256-CBC
- [x] **5 categorias:** 🔑 Senha, 💻 Comando, 📋 Documentação, 🔗 Link, 📦 Outro
- [x] **Conteúdo mascarado** (`● ● ● ● ●`) na listagem — olho para revelar
- [x] **Cópia sem revelar** — botão copia descriptografado sem exibir na tela
- [x] **Busca** server-side por título, tags e notas (`LIKE`)
- [x] **Filtro por categoria** — pills na toolbar
- [x] **Tags** em chips coloridos
- [x] **Notas** em texto livre
- [x] **Criptografia:** AES-256-CBC, chave derivada de `GLPI_APP_TOKEN + 'cofre_ti_gmais'` via SHA-256
- [x] **Responsivo:** CSS Grid `minmax(300px, 1fr)` com cards

---

## 🔴 Pendente

- [ ] Controle de acesso por nível (técnico x encarregado)
- [ ] Auditoria: quem acessou/visualizou/copiou e quando
- [ ] Compartilhamento seguro de senha (link temporário com expiração)
- [ ] Senha mestra para desbloquear o cofre

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
