---
tags:
  - modulo
  - monitor-rede
  - alertas
  - roteiro
---

# 📡 Monitor de Rede (substitui o The Dude)

> O próprio portal pinga os equipamentos do inventário e alimenta a Central de Alertas, o mapa da rede e o status de cada categoria do Inventário. O The Dude sai de cena.

**Status:** 🟡 Em implementação — etapa 1/7 concluída
**Decidido em:** 25/09/2026
**🏠 Módulo:** [[Bem-vindo|Portal GLPI]]
**Roteiro técnico completo:** `Docs/superpowers/specs/2026-09-25-monitor-rede-portal-design.md`
**Acompanhamento:** [[backlog]]

---

## ❓ Por que trocar o Dude

| Problema | Quando |
|---|---|
| PDV121 preso "caído" com IP de teste (.222) — alerta voltava todo dia às 06:00 | 20–24/09 |
| Dude parou de notificar sem aviso (último aviso real 20/09 15:46) | 20/09 → |
| "Dude sem contato" levou 5 dias pra disparar (o ping do portal zerava o relógio) | 25/09 |
| 4 PDVs presos "caído" ~20h | 14/09 |

Causa comum: o Dude **só avisa quando muda de estado**. Aviso perdido = estado errado pra sempre.

## ⚙️ Como vai funcionar

- **Equipamentos vêm do inventário** (GLPI + balanças + pfSense + servidores MGV). Cadastro manual só pra exceção.
- **Chave "Monitorar"** em cada equipamento (na tela do inventário e na lista do monitor).
- **Configuração por grupo**, toda editável pela tela do portal:

| Grupo | Pinga a cada | Considera caído após | Reinício / volta rápida |
|---|---|---|---|
| PDVs | 30 s | 4 min fora | 🔁 aviso na hora |
| Servidores / pfSense | 30 s | 1 min fora | 🔁 aviso na hora |
| Balanças | 2 min | 6 min fora | só registro |
| Demais | 1 min | 3 min fora | só registro |

- **Reinício do PDV** (1–3 min fora): não vira alerta de queda — vira "🔁 PDV003 reiniciou (fora 1 min 40 s)" e fica registrado no equipamento.
- **Notificações:** todas no mesmo grupo de WhatsApp de alertas de hoje; WhatsApp e lembrete ligáveis por grupo.
- **Carga:** pings em paralelo, ~120 pings/min — irrelevante pra rede e servidor.

## 🗺️ Roteiro

- [x] **Etapa 1** — Equipamentos do inventário + chave "Monitorar" + configuração por grupo ✅ 25/09 — tela: Configurar Alertas → Monitor de Rede
- [ ] **Etapa 2** — Motor de ping em modo sombra (monitora, não alerta — comparar com a realidade)
- [ ] **Etapa 3** — Virada: monitor passa a alimentar a Central de Alertas
- [ ] **Etapa 4** — Mapa da rede no Inventário (loja → grupos → equipamentos com 🟢🔴🟡⚪, nome e IP) + status ligado/desligado em cada categoria do Inventário
- [ ] **Etapa 5** — Notificação por grupo (WhatsApp, lembrete, aviso de reinício)
- [ ] **Etapa 6** — Latência alta + renomear "The Dude" → "Monitor de rede"
- [ ] **Etapa 7** — Remover tudo do Dude (portal, nomes internos `dude_*` → `rede_*`, container)

## 🔗 Relacionados

- [[Bem-vindo|Portal GLPI]]
- [[backlog]]
- [[ARCHITECTURE]]
