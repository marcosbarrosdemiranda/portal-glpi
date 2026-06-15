# ADR-004 — Estratégia Mobile: PWA Primeiro, APK Depois

**Data:** 2026-06-13
**Status:** ATIVO

---

## Contexto

O Portal TI precisa ser acessível em celulares e tablets para os técnicos
usarem em campo. Existem duas abordagens principais:

1. **PWA (Progressive Web App)** — o portal vira um app instalável via
   navegador (Chrome/Safari), sem loja, sem compilação.
2. **APK nativo** — app Android empacotado, publicado internamente ou na
   Play Store.

O portal já é responsivo (Bootstrap), então o custo adicional do PWA é
baixo: basicamente `manifest.json` + service worker + ícones.

## Decisão

**Adotar estratégia em duas fases:**

### Fase 1 — PWA (imediatamente)

- Criar `manifest.json` com nome, ícone, cor, modo standalone
- Service worker para cache offline básico
- Botão "Instalar app" no banner
- Notificações push para novos chamados

Justificativa:
- ✅ Rápido de implementar (horas, não dias)
- ✅ Funciona em Android e iOS simultaneamente
- ✅ Zero dependência externa (loja, certificado, compilação)
- ✅ Atualiza sozinho (basta recarregar)
- ✅ Já temos 80% do layout responsivo pronto

### Fase 2 — APK (futuro, após PWA consolidado)

- Empacotar o mesmo PWA como APK via Capacitor ou PWA2APK
- Adicionar biometria (digital/face) para desbloqueio do cofre
- QR Code para escanear equipamentos no inventário
- Possível publicação interna

Justificativa:
- A funcionalidade extra (biometria, câmera) justifica o esforço extra
- O PWA serve como prova de conceito antes de investir no APK
- O código base é o mesmo — não há reescrita

## Regras

1. Nenhuma funcionalidade mobile deve depender de APK — tudo que é essencial
   deve funcionar no PWA/ navegador.
2. O APK pode adicionar funcionalidades extras (câmera, biometria), nunca
   requisitos obrigatórios.

## Impacto

- `Docs/wiki/projects/portal-glpi/portal-glpi-prd.md` — Módulo 15 atualizado
- `Portal-Glpi/Decisões/ADR-004-estrategia-mobile-pwa-primeiro.md` — este documento
- `manifest.json`, `service-worker.js`, ícones — a serem criados na Fase 1
