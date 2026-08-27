# ADR-003 — Stack Node.js + Express

> Status: **Aceito** · 2026-06-12 · ver [[index]], [[ARCHITECTURE]].

## Contexto

Necessário backend confiável para sincronização e API REST, atendendo web e (futuro) app nativo. O usuário pediu "a melhor linguagem do momento e mais confiável".

## Decisão

**Node.js 20 + Express 4 + mysql2**, JavaScript ESM, sem ORM (SQL direto parametrizado). Frontend PWA em HTML/CSS/JS puro.

## Justificativa

- Node 20 já estava instalado e é padrão de mercado para APIs/integrações em 2026.
- `fetch` nativo, ecossistema maduro (jsonwebtoken, bcryptjs, mysql2).
- **Uma só linguagem** para sync, API e (futuro) app React Native — reaproveita a mesma API REST.
- PWA evita complexidade de build no MVP e já funciona como "app" no celular.

## Consequências

- Sem tipagem estática (JS, não TS) — mitigado por código simples e bem comentado; TypeScript é opção futura.
- Sem framework de frontend — ótimo para o MVP; se a UI crescer muito, avaliar React/Vue.

## Alternativas consideradas

- **Laravel/PHP**: bom (o site institucional é PHP), mas não unifica com app nativo.
- **Go**: performático, porém mais verboso para este escopo e sem ganho relevante aqui.
- **TypeScript**: desejável a médio prazo; adiado para não atrasar o MVP.

Relacionado: [[ADR-001-Banco-MySQL]], [[Dashboards-e-Relatorios]].
