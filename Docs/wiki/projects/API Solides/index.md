# 🗂️ Dashboard de Ponto — Grupo G+ · Mapa de Conteúdo (MOC)

> Documentação viva do projeto de integração com a **API Sólides / Tangerino** (ponto eletrônico) e do **Dashboard de Ponto** web/mobile do Grupo G+.
> Última atualização: **2026-06-12**

---

## 🎯 Visão geral

Sincronizamos os dados de ponto eletrônico da **Sólides DP (ex-Tangerino)** para um **banco MySQL local** e servimos um **painel web/mobile** com login seguro, separado por **perfil de acesso** (ADMIN / RH / GESTOR) e por **unidade (loja)**. Tudo dinâmico: entrada/saída ao vivo, horas extras (com BI) e ausências.

```
API Sólides/Tangerino ──(sync incremental)──▶ MySQL (ponto_gmais) ──(REST + JWT)──▶ Painel Web/Mobile (PWA)
```

---

## 🧭 Navegação

### Fundamentos
- [[ARCHITECTURE]] — arquitetura completa, stack, fluxos e roadmap
- [[CONTRIBUTING]] — convenções de código, segredos e como rodar

### Projeto / Produto
- [[PRD-Dashboard-Ponto-GMais]] — escopo, objetivos e personas

### Conceitos técnicos
- [[Integracao-API-Solides]] — autenticação, módulos, endpoints e pegadinhas
- [[Modelo-de-Dados]] — as 16 tabelas do banco `ponto_gmais`
- [[Sincronizacao-de-Dados]] — modos full/inc/rápido e agendamento
- [[Seguranca-e-Perfis]] — JWT, bcrypt, escopo por loja
- [[Dashboards-e-Relatorios]] — abas do painel e consultas

### Decisões de arquitetura (ADRs)
- [[ADR-001-Banco-MySQL]] — por que MySQL/MariaDB
- [[ADR-002-Sync-Local-vs-Tempo-Real]] — espelho local em vez de consultar a API ao vivo
- [[ADR-003-Stack-NodeJS]] — Node.js + Express + mysql2

### Operação
- [[Backlog]] — ✅ feito e 🔜 futuro (todos os tópicos)
- [[2026-06-12-sessao-01]] — log da sessão inicial

---

## 📊 Estado atual (2026-06-12)

| Item | Status |
|---|---|
| Estudo e mapeamento da API Sólides | ✅ Concluído |
| Banco MySQL `ponto_gmais` (16 tabelas) | ✅ Em produção local |
| Sincronização (full/inc/rápido) + agendamento | ✅ Ativo (10 min / 1 h) |
| API REST + login JWT + perfis | ✅ Funcionando |
| Painel web/mobile (PWA) | ✅ Funcionando |
| Gráficos/visualizações ricas | 🔜 Próxima fase |
| Acesso em rede/servidor + HTTPS | 🔜 Próxima fase |
| App nativo | 🔮 Futuro |

---

## 🔑 Acessos rápidos

- **Painel:** `http://localhost:3000`
- **Código:** `C:\Claude Code\API Solides\` (`tangerino-sync/` e `dashboard/`)
- **Referência da API:** `C:\Claude Code\API Solides\tangerino-api-reference.md`
- Credenciais e segredos: ver [[CONTRIBUTING#Segredos e credenciais]]
