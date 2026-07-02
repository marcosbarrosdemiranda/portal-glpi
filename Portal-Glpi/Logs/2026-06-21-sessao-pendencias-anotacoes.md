# Log de Sessão — 21/06/2026

## Resumo
Criação do módulo **Pendências e Anotações**: tabela MySQL, página CRUD completa, card no dashboard.

---

## O que foi implementado

### 1. Tabela `anotacoes` no MySQL
Criada via `CREATE TABLE IF NOT EXISTS` no próprio PHP (auto-criação na primeira execução).

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT AUTO_INCREMENT PK | |
| entidade_id | INT | FK para entidade GLPI |
| titulo | VARCHAR(255) | Título da pendência |
| descricao | TEXT | Descrição detalhada |
| prioridade | ENUM('baixa','media','alta') | Padrão: media |
| status | ENUM('pendente','concluido','convertido') | Padrão: pendente |
| user_id / user_nome | INT + VARCHAR | Quem criou |
| ticket_id | INT NULL | Quando convertido em chamado |
| created_at / updated_at | DATETIME | Timestamps automáticos |

### 2. Página `pendencias.php`
- **Listagem**: Cards agrupados por loja (entidade) com borda colorida por prioridade
- **Filtros**: por loja e status (todas/pendentes/concluídas/convertidas)
- **Criar/Editar**: Modal Bootstrap com campos: loja, título, descrição, prioridade
- **Concluir**: ✅ Marca como concluído com 1 clique
- **Reativar**: 🔄 Reverte para pendente
- **Excluir**: 🗑️ Modal de confirmação
- **Converter em chamado**: 🎯 Cria ticket no GLPI via API (status=Novo, não atribuído para não afetar SLA)

### 3. Card no Dashboard
Adicionado na seção **Atendimento**, logo após Histórico de Chamados.

## Arquivos criados/alterados
| Arquivo | Ação |
|---------|------|
| `pendencias.php` | Criado — página completa do módulo |
| `dashboard.php` | Alterado — card + CSS `.card-pendencias` |

## Status
- [x] Tabela criada (auto-create no PHP)
- [x] Listagem por loja
- [x] Criar/Editar/Excluir anotações
- [x] Concluir e reativar
- [x] Converter para chamado GLPI
- [x] Card no dashboard
- [x] Copiado para servidor 192.168.1.198
