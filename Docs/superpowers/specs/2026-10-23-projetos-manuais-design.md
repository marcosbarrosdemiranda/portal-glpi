# Projetos Manuais — cadastro direto no portal (sem GitHub)

**Data:** 2026-10-23 · **Status:** 🟡 SPEC em aprovação
**Branch planejada:** `feat/projetos-manuais` (base `feat/projetos-estimativa-horas` →
`fix/projetos-github-cache-worker` → `infra/migracao-docker-glpi` — PRs #10/#11
ainda não mergeados na branch de integração, mas já estão em produção via scp;
basear direto em `infra/migracao-docker-glpi` reverteria essas 2 features)

## Problema

`projetos.php` hoje só lista projetos que vêm de contas GitHub cadastradas
(repositórios reais, com progresso tirado de PRD/issues/README). Não existe
jeito de cadastrar um projeto que não tem repositório no GitHub (ex. um
projeto de infraestrutura, processo interno, iniciativa sem código) com o
mesmo tratamento visual e organizacional que os projetos GitHub já têm:
título, descrição, barra de progresso, previsão de término, responsáveis, e
classificação em **Futuros / Em Execução / Concluídos**.

## Decisão de desenho (confirmada com o usuário)

### Modelo de dados novo

Duas tabelas novas, criadas via `CREATE TABLE IF NOT EXISTS` no topo de
`projetos.php` — mesmo padrão já usado ali pras tabelas GitHub
(`portal_github_contas`, `portal_projetos_status`):

```sql
CREATE TABLE portal_projetos_manuais (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    titulo        VARCHAR(255) NOT NULL,
    descricao     TEXT NULL,
    status        ENUM('futuro','em_execucao','concluido') NOT NULL DEFAULT 'em_execucao',
    previsao      DATE NULL,
    responsaveis  TEXT NULL,            -- JSON [{"id":N,"nome":"...","cor":"#..."}], mesmo formato de co_atendentes da Agenda
    criado_por    INT NULL,             -- user_id de quem criou, só auditoria/histórico, SEM gate de permissão
    criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portal_projetos_manuais_itens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    projeto_id INT NOT NULL,
    texto      VARCHAR(500) NOT NULL,
    concluido  TINYINT(1) NOT NULL DEFAULT 0,
    ordem      INT NOT NULL DEFAULT 0,
    criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projeto_id) REFERENCES portal_projetos_manuais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Progresso é **calculado on-the-fly** (`COUNT(concluido=1) / COUNT(*)` dos
itens do projeto) — mesmo padrão `progresso{feitas,total,pct}` já usado nos
cards GitHub, com `fonte = "Checklist manual"`.

### 4 decisões confirmadas com o usuário

1. **Responsáveis** — chips multi-select de técnicos cadastrados do portal,
   reaproveitando o mesmo padrão visual e formato JSON (`[{id,nome,cor}]`)
   já usado em `co_atendentes`/`co_projetos` na Agenda (`agenda/eventos.php`).
   A fonte exata da lista de técnicos/cores que alimenta esse componente na
   Agenda será confirmada via diagnóstico read-only no início do PLAN, pra
   não duplicar/hardcodar uma lista divergente.
2. **Itens/funcionalidades** — checklist **plano**, sem agrupamento em
   módulos (diferente da página de detalhe `.md` hoje inacessível pela UI,
   que agrupa tarefas em módulos). Cada item: texto + checkbox + ordem.
3. **Permissão** — qualquer perfil autenticado não-self-service pode criar,
   editar, excluir e marcar/desmarcar itens de **qualquer** projeto manual,
   inclusive os criados por outro técnico. Sem ownership check (mesmo nível
   de permissão já usado no resto do módulo — só bloqueia self-service).
4. **Previsão de término** — só campo de data manual, igual ao campo
   "previsão manual" que já existe pros projetos GitHub (`gh_action=set_previsao`).
   Sem reaproveitar a ferramenta de estimativa por horas/dia.

### Integração visual com os 3 buckets existentes

Os mesmos 3 containers de seção (`$reposPorSecao['futuro'|'em_execucao'|'concluido']`)
passam a receber projetos GitHub **e** projetos manuais juntos — mesmo
comportamento de expandir/colapsar, mesma contagem no cabeçalho da seção.
Card de projeto manual (`.manual-proj-card`, variante visual do
`.gh-proj-card` existente) tem:
- Badge discreto "Manual" (distingue de card GitHub, que não tem).
- Título + menu "⋮" (Editar / Excluir / Mudar status / Definir previsão).
- Descrição.
- Barra de progresso (mesmo componente visual dos cards GitHub).
- Chips de responsáveis (mesmo componente visual da Agenda).
- Previsão de término, se definida.
- Checklist de itens com checkbox clicável inline — toggle imediato via
  fetch (sem reload da página inteira), atualizando checklist + barra de
  progresso na hora; campo "+ adicionar item" inline no próprio card.

### CRUD — endpoints novos

Modal Bootstrap novo (`#modalProjetoManual`) + endpoints roteados por
`?pm_action=` (prefixo próprio, não colide com `gh_action` já existente):
`pm_action=criar|salvar|excluir|status_set|item_add|item_toggle|item_delete|item_editar`.
JSON body + prepared statements — mesmo padrão já usado pro CRUD de contas
GitHub no mesmo arquivo (`gh_action=conta_add/conta_save/conta_delete`).

## Escopo inclui

- 2 tabelas novas (`portal_projetos_manuais`, `portal_projetos_manuais_itens`).
- Endpoints `pm_action=*` em `projetos.php`.
- Modal de criação/edição + card de projeto manual integrado às 3 seções
  existentes, ao lado dos cards GitHub.
- Componente de chips de responsáveis reaproveitando o padrão visual da
  Agenda.
- Barra de progresso calculada a partir dos itens do projeto manual.
- Toggle de item concluído sem reload de página.

## Escopo exclui

- Qualquer alteração no fluxo de contas GitHub / cache / estimativa por
  horas já existente.
- Agrupamento de itens em módulos (decidido: checklist plano).
- Restrição de edição por responsável/criador (decidido: qualquer técnico
  edita qualquer projeto manual).
- Sincronização com GitHub Projects board ou qualquer API externa —
  projeto manual é 100% dados próprios do portal.
- Reaproveitar/reviver a página de detalhe `?proj=arquivo.md` (código morto
  hoje, só atingível por `renderProjCard()` que nunca é chamada) — projeto
  manual vive inteiramente no card da lista, sem página de detalhe própria
  nesta primeira versão.

## Riscos

- **Médio-baixo** — feature nova e isolada (tabelas novas, endpoints com
  prefixo próprio que não colide com `gh_action`), mas toca o arquivo mais
  sensível do módulo (`projetos.php`, 2235 linhas, HTML+JS+PHP misturados
  no mesmo arquivo).
- Ponto de atenção: confirmar a fonte exata da lista de técnicos/cores dos
  chips de responsáveis (hoje só usada dentro da Agenda) antes de escrever
  o componente em `projetos.php`, no início do PLAN via diagnóstico
  read-only.
- Misturar os 2 tipos de card (GitHub vs manual) na mesma seção precisa
  ficar visualmente claro o suficiente pra não confundir "projeto real" com
  "projeto manual" — mitigado com o badge "Manual".

## Critérios de aceite

1. Ação visível em `projetos.php` pra criar um projeto manual (título,
   descrição, previsão, responsáveis, itens iniciais).
2. Projeto manual criado aparece na seção certa (Futuros/Em Execução/
   Concluídos) conforme o status escolhido, ao lado dos projetos GitHub.
3. Card do projeto manual mostra barra de progresso calculada
   automaticamente a partir do checklist de itens.
4. Marcar/desmarcar um item atualiza checklist + barra de progresso
   imediatamente, sem reload de página.
5. Editar título/descrição/previsão/responsáveis/itens de um projeto
   manual já criado funciona via modal, sem duplicar o registro.
6. Excluir um projeto manual remove o card e os itens vinculados (cascade),
   com confirmação antes.
7. Mudar o status move o card pra seção certa, igual ao comportamento já
   existente dos projetos GitHub.
8. Qualquer técnico autenticado (perfil não-self-service) consegue fazer
   todas as ações acima em qualquer projeto manual, inclusive os criados
   por outro técnico.
9. Nenhuma regressão nos projetos GitHub existentes (contas, status,
   previsão, estimativa, cache).
