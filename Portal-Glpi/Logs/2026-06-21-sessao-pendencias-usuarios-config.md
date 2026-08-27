# Log de Sessão — 21/06/2026 (continuação)

## Resumo
Criação do módulo **Pendências e Anotações**, seção **Configuração** no dashboard e página **Gestão de Usuários**.

---

## O que foi implementado

### 1. Módulo Pendências e Anotações (`pendencias.php`)
Tabela `anotacoes` no MySQL (auto-create via PHP na primeira execução). CRUD completo com:
- Listagem agrupada por loja (entidade) com cards por prioridade (borda colorida)
- Filtros por loja e status
- Criar/Editar via modal Bootstrap
- Concluir/Reativar com 1 clique
- Converter em chamado GLPI (cria ticket status=Novo, sem atribuir técnico)
- Excluir com modal de confirmação

Card no dashboard (seção Atendimento) com ícone 📋 laranja.

### 2. Seção Configuração no Dashboard
Nova seção após **Gestão de TI** com label ⚙️ Configuração.
Primeiro card: **Gestão de Usuários**.

### 3. Gestão de Usuários (`usuarios.php`)
CRUD de usuários do GLPI via API REST:
- Listagem com busca por nome/login
- Criação de novo usuário (login, nome, e-mail, telefone, celular, senha, perfil, loja)
- Edição de dados + reset de senha
- Exclusão com confirmação
- Vincula perfil e entidade via `User_Profile`

## Arquivos criados/alterados
| Arquivo | Ação |
|---------|------|
| `pendencias.php` | Criado — módulo de anotações |
| `dashboard.php` | Alterado — card Pendências + seção Configuração + card Usuários |
| `usuarios.php` | Criado — gestão de usuários GLPI |

## Status
- [x] Pendências e Anotações: criado e no ar
- [x] Card Pendências no dashboard
- [x] Seção Configuração no dashboard
- [x] Gestão de Usuários: criado e no ar
- [x] Copiado para servidor 192.168.1.198
