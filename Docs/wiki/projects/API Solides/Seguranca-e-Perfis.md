# Segurança e Perfis de Acesso

> Conceito · ver [[index]]. Código em `dashboard/src/auth.js`.

## Autenticação

- Login por **e-mail + senha**; senha verificada com **bcrypt** (`senha_hash` na tabela `usuarios`).
- Em sucesso, emite **JWT** assinado (`JWT_SECRET`) com `{ id, nome, email, perfil, lojaId }`, expiração **12 h**.
- Cliente envia `Authorization: Bearer <token>`; middleware `autenticar` valida em todas as rotas `/api/*` (exceto `/api/login`).

## Proteções

- **Rate limiting de login**: 5 tentativas por e-mail a cada 15 min (em memória).
- `x-powered-by` desabilitado.
- SQL sempre parametrizado (named placeholders) → sem injeção.
- Segredos em `.env` (ver [[CONTRIBUTING#Segredos e credenciais]]).

## Perfis

| Perfil | Vê | Pode |
|---|---|---|
| **ADMIN** | Tudo | Gerenciar usuários (`/api/usuarios`) |
| **RH** | Todas as lojas | Leitura completa |
| **GESTOR** | **Só a própria loja** | Leitura restrita |

## Escopo por loja (regra crítica)

`lojaPermitida(req, lojaIdSolicitada)`:
- **GESTOR** → sempre retorna o `lojaId` do próprio token, **ignorando** qualquer `lojaId` enviado na URL.
- **ADMIN/RH** → usam o `lojaId` solicitado (ou todas, se vazio).

> Testado: um GESTOR tentando `?lojaId=<outra loja>` continua vendo só a dele; `/api/usuarios` responde **403**.

`exigirPerfil('ADMIN')` protege as rotas de gestão de usuários.

## Usuários iniciais

| E-mail | Perfil | Loja |
|---|---|---|
| ti1@grupogmais.com | ADMIN | — |
| rh@grupogmais.com | RH | — |
| gestor001@grupogmais.com | GESTOR | Supermercado Santos - Bonito (2168218) |

Criar mais: `node src/criar-usuario.js "Nome" email senha PERFIL [lojaId]`.

## Pendências de segurança para produção

- **HTTPS obrigatório** fora da rede local (hoje HTTP em localhost). Ver [[Backlog]].
- Rotacionar `JWT_SECRET` e trocar senhas padrão.
- Considerar refresh tokens e auditoria de acesso.

Relacionado: [[PRD-Dashboard-Ponto-GMais]], [[Dashboards-e-Relatorios]].
