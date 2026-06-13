# Dashboards e Relatórios

> Conceito · ver [[index]]. Backend `dashboard/src/server.js`; frontend `dashboard/public/`.

## Frontend

PWA leve (HTML/CSS/JS puro, sem framework). `manifest.json` permite "Adicionar à tela inicial" no celular. Filtros globais de **data**, **loja** e **setor**. A aba "Hoje" recarrega a cada 60 s.

## Abas do painel

| Aba | Conteúdo |
|---|---|
| **Hoje** | Cards **clicáveis** (drill-down): ativos, trabalhando agora, registraram, sem registro, ausências, batidas pendentes, HE do mês. Lista "trabalhando agora" com tempo decorrido. |

> **Cards com filtro (drill-down NA PRÓPRIA aba Hoje):** clicar num card **não troca de aba** — os cards continuam no topo e só a **lista abaixo** é atualizada conforme o card selecionado (destacado). Conteúdo da lista: *Trabalhando agora* (entrada + tempo), *Registraram* (total + situação), *Sem registro* (com motivo se houver ajuste), *Batidas pendentes*, *Ausências justificadas*, *Horas extras* (ranking por colaborador), *Colaboradores ativos* (todos, com situação). As abas Pontos / H. Extras / Ausências seguem existindo para a visão completa.
>
> Botão **"Por setor"** ao lado do título da lista: desligado (padrão) = **lista corrida** dos colaboradores com coluna Loja/Setor; ligado = lista **agrupada por loja → setor** (cabeçalhos de grupo). O modo escolhido vale para todos os cards.
| **Pontos** | Registros do dia agrupados por **loja → setor**, com pares E1/S1…, total e situação (TRABALHANDO/PENDENTE/OK) + lista de quem não registrou (indicando férias/atestado) |
| **H. Extras** | Período livre; **por setor** e **por colaborador** (HE faixa 1/2, noturno, faltas) |
| **Ausências** | Quem está de férias/afastado/folga no dia, com período |

> **Banco de Horas removido** (12/06/2026) — a empresa não usa. A aba, o endpoint `/api/banco-horas` e a coleta `syncBancoHoras()`/`/hoursBalance` foram desativados. A tabela `banco_horas` permanece no schema, vazia/ignorada.

## API REST (endpoints)

| Método | Rota | Retorno |
|---|---|---|
| POST | `/api/login` | `{ token, usuario }` |
| GET | `/api/me` | dados do token |
| GET | `/api/filtros` | lojas (escopo do perfil) + setores |
| GET | `/api/resumo` | cards do dia (`?data&lojaId`) |
| GET | `/api/pontos` | registros + sem-registro (`?data&lojaId&setorId`) |
| GET | `/api/horas-extras` | por colaborador e por setor (`?inicio&fim&lojaId`) |
| GET | `/api/ausencias` | ausências do dia (`?data&lojaId`) |
| GET | `/api/banco-horas` | saldos (`?lojaId`) |
| GET | `/api/usuarios` · POST · DELETE | gestão de usuários (**ADMIN**) |
| GET | `/api/sync-info` | última sincronização |

Todas (exceto login) exigem **Bearer JWT** e respeitam o [[Seguranca-e-Perfis|escopo de loja]].

## Regras de negócio na consulta

- **Datas seguras quanto a fuso**: limites do dia calculados em millis em JS (fuso `America/Campo_Grande`, **UTC-4** — Cuiabá/MS, confirmado com batida real), não com funções de fuso do MySQL.
- **Horas extras** somadas direto de `resumo_diario` (oficial Sólides), em minutos → exibidas como `XhYY`.
- **"Sem registro"** = ativo que bate ponto, **não tem batida no dia E não tem ausência justificada** (corrigido 13/06/2026 — quem tem férias/atestado/INSS sai daqui e conta só em "Ausências justificadas"; antes havia dupla contagem). Validação: `ativos = registraram + sem registro + ausências`. *Limitação:* inclui quem ainda não chegou no turno — refino futuro (considerar a escala).
- "Trabalhando agora" = batida com entrada e sem saída.

## BI · Horas Extras (réplica do Power BI) ✅

Página dedicada `public/bi.html` (tema vermelho GMÁIS), reaproveitando o login JWT do painel. Replica o relatório de Horas Extras do Power BI em 3 visões:

| Visão | Conteúdo |
|---|---|
| **Matriz** | Colaborador × dias (HH:MM:SS), células altas em amarelo + painel "Total por Colaborador" |
| **Lojas** | Tabela por setor + **pizza** de HE por loja |
| **Departamentos** | Colaborador/Setor + **barras** por departamento + **Top colaboradores** (colunas) |

- Filtros: **Data** (período), **Loja** (abas 001/003/010/030/031/101), **Departamento** (setor).
- KPI **Total Horas Extras** no topo (reflete os filtros).
- Endpoints: `GET /api/bi/horas-extras` (matriz, totais, porSetor, porLoja, topColaboradores) e `GET /api/bi/dimensoes`.
- **Fórmula de HE** centralizada em `server.js` (`const HE = he_tipo1+he_tipo2+he_tipo3+he_tipo4`). Calibrado: loja 001 em 23–27/05 deu 215:51 vs 215:59 do Power BI.
- Gráficos em **SVG/HTML puro** (sem dependências, offline). Respeita o [[Seguranca-e-Perfis|escopo de loja]] do GESTOR.

## Próximos (visualizações)

Heatmap de presença, exportações, departamento multi-seleção (checkboxes como no Power BI). Ver [[Backlog]].

Relacionado: [[Modelo-de-Dados]], [[Seguranca-e-Perfis]].
