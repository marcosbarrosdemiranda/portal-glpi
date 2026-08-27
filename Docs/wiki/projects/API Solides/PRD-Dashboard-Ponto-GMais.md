# PRD — Dashboard de Ponto Grupo G+

> Projeto · ver [[index]] e [[ARCHITECTURE]].

## Problema

O Grupo G+ controla o ponto eletrônico de **~218 colaboradores ativos** distribuídos em **6 lojas** pela plataforma **Sólides/Tangerino**. O RH e os gestores precisam acompanhar entrada/saída, **horas extras**, faltas e ausências de forma **dinâmica**, em **web e celular**, sem depender das telas e relatórios da plataforma original — e com **controle de acesso** por unidade.

## Objetivos

1. Espelhar os dados da Sólides em banco próprio e robusto (**MySQL**).
2. Painel **web e mobile** com **login seguro** e perfis de acesso.
3. Visão **por loja e por setor**, com dados praticamente em tempo real (sync a cada 10 min).
4. Base para **app nativo** futuro reaproveitando a mesma API.

## Personas

| Persona | Necessidade | Acesso |
|---|---|---|
| **RH / DP** | Visão global de todas as lojas, horas extras, ausências | Perfil RH |
| **Gestor de loja** | Acompanhar só a sua unidade: quem está, quem faltou, HE da equipe | Perfil GESTOR (restrito) |
| **Admin / TI** | Tudo + cadastro de usuários e manutenção | Perfil ADMIN |

Detalhes em [[Seguranca-e-Perfis]].

## Escopo do MVP (✅ entregue)

- Integração e mapeamento completo da [[Integracao-API-Solides|API Sólides]].
- Banco [[Modelo-de-Dados|MySQL `ponto_gmais`]] (16 tabelas).
- [[Sincronizacao-de-Dados|Sincronização]] full/incremental/rápida + agendamento.
- API REST + [[Seguranca-e-Perfis|login JWT e perfis]].
- [[Dashboards-e-Relatorios|Painel PWA]]: Hoje, Pontos, Horas Extras, Ausências — com filtros de data/loja/setor.
- [[Dashboards-e-Relatorios|BI de Horas Extras]] réplica do Power BI (matriz, pizza por loja, barras por departamento).

## Fora do escopo do MVP

- Lançamentos de volta na Sólides (a integração hoje é **leitura**).
- Cálculo próprio de horas extras (usamos o oficial da Sólides).
- App nativo (fase futura).

## Métricas de sucesso

- RH consegue ver HE do mês por loja/setor sem abrir a Sólides.
- Gestor abre no celular e vê a equipe presente em segundos.
- Dados defasados no máximo ~10 min.

## Dados reais (referência 2026-06-12)

- 6 lojas (Supermercado Santos Bonito/JDM, Rincão Atacadista Bonito/N.A.S., Santos Express, Gmais Adm) — razão social **Gmais Comércio de Alimentos Ltda**.
- 24 setores, 218 colaboradores ativos, ~1.305 h extras acumuladas no mês.

Próximos passos em [[Backlog]].
