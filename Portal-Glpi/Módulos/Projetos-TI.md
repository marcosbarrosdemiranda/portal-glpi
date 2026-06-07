---
tags:
  - modulo
  - projetos
  - documentacao
---

# 💼 Projetos de TI

> Gestão de projetos da TI com progresso em tempo real via markdown (Obsidian).

**Status:** 🟢 Ativo — ~80%  
**Fonte de dados:** `Docs/wiki/projects/*.md` (arquivos markdown)  
**🏠 Módulo:** [[Bem-vindo|Portal GLPI]]

---

## 📂 Estrutura

| Item | Caminho |
|------|---------|
| Código | `projetos.php` (monolítico — parser markdown + frontend + export) |
| Dados | `Docs/wiki/projects/*.md` |
| Dashboard | `dashboard.php` → link para `projetos.php` |
| Equipe | `equipe_detalhe.php` (desempenho em projetos por técnico) |
| Relatórios | `relatorios_projetos.php` (JSON endpoint) |
| Documentação | `Portal-Glpi/Projetos-TI.md` |
| Senhas e credenciais | [[Cofre-TI]] |

---

## ✅ Funcionalidades

### Lista (Cards)
- [x] Cards responsivos: título, % geral, barra de progresso, mini-módulos (top 5)
- [x] Meta info: equipe, prazo
- [x] Clique → detalhe do projeto

### Detalhe
- [x] **Header**: título, objetivo, equipe, prazo, repositório GitHub, status, barra geral
- [x] **Gráfico SVG de Previsão**: linha planejada, linha de previsão, pontos de módulo, dot do progresso real
- [x] **Gantt**: cronograma semana a semana, barras coloridas, marcador "Hoje"
- [x] **Módulos colapsáveis**: com tarefas (✅/⭕), progresso, prazo, subsessões
- [x] **Status**: Concluído, Adiantado, No prazo, Atenção, Em atraso

### Exportação (nova)
- [x] Botão **📄 Exportar** no header do detalhe
- [x] Modal com checkboxes: cabeçalho, Gantt, módulos individualmente
- [x] **📥 Download .md**: baixa markdown remontado com só as seções selecionadas
- [x] **🖨️ Imprimir / PDF**: `window.print()` esconde seções não selecionadas
- [x] CSS `@media print` otimizado para PDF (cores, quebras, fontes)

---

## 🔧 Como funciona

### Parser do Markdown
- Função `parseProjeto()` lê `*.md` e extrai título, objetivo, equipe, prazo, repo
- Separa em módulos por `## Heading`, tarefas por `- [x]` / `- [ ]`
- Progresso calculado como `round(done / total * 100)` por módulo e geral
- Cronograma é lido de dentro de blocos ``` no markdown

### Export
- PHP lê o `.md` original, divide por `##`, filtra seções pelo parâmetro `sections[]`
- Serve como download `.md` (Content-Disposition: attachment)
- Para print: JS oculta elementos `[data-export-section]` não selecionados, chama `window.print()`, restaura após

---

## 📐 Modelo de dados (markdown)

```markdown
# Nome do Projeto
> **Objetivo:** Descrição
> **Equipe:** Nome1, Nome2
> **Prazo:** 01/06/2026 → 01/07/2026
> **Repositório:** https://github.com/...

## Módulo 1
- [x] Tarefa concluída
- [ ] Tarefa pendente

## Cronograma
\`\`\`
Semana 1 (01/06-07/06) → Descrição
\`\`\`
```

---

## 🔴 Pendente
- [ ] Responsável e co-responsáveis por projeto
- [ ] Vinculação de chamados GLPI a projetos
- [ ] Filtro por módulo / status na view do portal
- [ ] Migrar para banco MySQL (hoje é markdown puro)

---

## 📌 Observações
- Data em formato BR (DD/MM/YYYY)
- Não há write capability — projetos são editados no Obsidian
- O parser do `equipe.php` e `relatorios_projetos.php` duplicam a lógica de parseProjeto()
- PRD em `Docs/wiki/projects/portal-glpi-prd.md` é também um projeto válido (auto-referência)
