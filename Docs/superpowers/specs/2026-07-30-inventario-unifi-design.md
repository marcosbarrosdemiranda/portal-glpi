# Inventário de Redes — monitoramento de access points UniFi

**Data:** 2026-07-30
**Status:** Aprovado para implementação

## Contexto

O portal já tem um hub "Inventário" (`inventario.php`) com cards de categoria —
hoje só "PCs" (via API do GLPI) e "Balanças" (via sync com SQL Server/Firebird)
estão implementados; um card **"Redes"** já existe na tela, mas desativado
("Em breve"). O time administra hoje **4 controladoras UniFi** (Cloud Key /
software controller clássico, porta 8443, login local de admin — não conta de
nuvem Ubiquiti) e precisa enxergar rapidamente quais access points estão
online/offline, quantos clientes conectados e o modelo/uptime de cada um.

## Componentes

### 1. `unifi_client.php` (novo arquivo, sem HTML/sessão)

Funções puras que falam com a API clássica da controladora UniFi
(`https://{host}:8443/api/...`). Segue o padrão já usado em
`github_client.php` (funções isoladas, retorno em array, nunca lança
exceção) combinado com o tratamento de sessão via cookie já usado em
`pfsense_proxy.php` (cookie jar temporário por requisição,
`CURLOPT_SSL_VERIFYPEER => false` — certificado autoassinado é a norma
nesses controllers locais).

```php
function unifi_login(string $url, string $usuario, string $senha): array
// POST {url}/api/login — {"username":...,"password":...}
// Retorna ['ok'=>bool, 'cookieFile'=>string (caminho do arquivo temp de
// cookies, já autenticado — quem chamar é responsável por apagar depois
// com unlink()), 'msg'=>string]

function unifi_listar_aps(string $url, string $usuario, string $senha, string $site = 'default'): array
// Faz login (via unifi_login), busca GET {url}/api/s/{site}/stat/device,
// filtra type === 'uap' (ignora switches/gateways que a mesma rota retorna),
// apaga o cookie file, retorna lista de APs ou ['erro'=>string]. Cada AP:
//   nome, modelo, status ('online'|'offline'), clientes (int), uptime_seg (int), ip, mac
// status: state === 1 → 'online'; qualquer outro valor → 'offline'.

function unifi_testar_login(string $url, string $usuario, string $senha): array
// So confirma que o login funciona (pra validar credenciais no cadastro).
// Retorna ['ok'=>bool, 'msg'=>string].
```

Timeout curto (8s) em cada chamada cURL — uma controladora fora do ar não
pode travar a página inteira (mesmo princípio de tolerância a falha por
conta já usado no módulo Projetos: uma controladora com erro aparece com
aviso, as outras continuam funcionando).

### 2. Tabela `portal_unifi_controladoras`

```sql
CREATE TABLE IF NOT EXISTS portal_unifi_controladoras (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    apelido            VARCHAR(60)   NOT NULL,
    url                VARCHAR(255)  NOT NULL,   -- ex: https://192.168.1.10:8443
    usuario             VARCHAR(100)  NOT NULL,
    senha_enc          TEXT          NOT NULL,
    site               VARCHAR(60)   NOT NULL DEFAULT 'default',
    ativo              TINYINT(1)    DEFAULT 1,
    ultimo_teste_ok    TINYINT(1)    DEFAULT NULL,
    ultima_verificacao DATETIME      DEFAULT NULL,
    criado_em          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Senha criptografada com `vault_encrypt()`/`vault_decrypt()` (mesmo esquema
do Cofre TI e das contas GitHub) — nunca em texto puro, nunca enviada ao
navegador/JS.

Diferente das contas GitHub (pessoais, por usuário), essas controladoras
são **infraestrutura compartilhada da empresa** — sem coluna `user_id`.
Cadastro/edição/exclusão restritos a `$is_admin` (mesmo `in_array($_SESSION['perfil'] ?? '', ['admin','super-admin','tecnico'])` já usado em `acessos.php`); a
visualização da lista de APs fica aberta a qualquer usuário autenticado
não-self-service (mesmo padrão de `inventario_pcs.php`).

### 3. Página `inventario_redes.php`

Mesmo padrão visual de `inventario_balancas.php` (topbar, hero, cards de
controladora em acordeão). Estrutura:

- Painel "Controladoras UniFi" (visível a todos; botão "+ Adicionar" e
  engrenagem de editar/testar/excluir só para `$is_admin` — mesmo padrão de
  modal usado em `projetos.php` para as contas GitHub, incluindo o botão
  "Testar" ligado a `unifi_testar_login()`).
- Para cada controladora ativa, uma seção em acordeão com o nome
  (apelido) e a grade de APs daquela controladora:
  - Bolinha verde (online) / vermelha (offline) — mesmo idioma visual dos
    status dots de `inventario_pcs.php`.
  - Nome do AP, modelo, quantidade de clientes conectados, uptime
    formatado (ex: "3d 4h").
  - Se a controladora estiver inacessível (login falhou, timeout), a
    seção mostra um aviso inline em vez de travar a página — as outras
    controladoras continuam aparecendo normalmente.
- Sem cache: busca ao vivo a cada carregamento da página (volume baixo —
  4 controladoras, poucos APs cada — resposta rápida o suficiente).

### 4. `inventario.php`

O card "Redes" (hoje `.disabled`, "Em breve") passa a linkar para
`inventario_redes.php` e perde o estado desativado.

## Segurança

- Senhas das controladoras: `vault_encrypt()`/`vault_decrypt()`, nunca
  expostas ao cliente/JS, decriptadas só server-side no momento da
  chamada à API.
- Endpoints AJAX de CRUD das controladoras: POST-only, gate de
  `Content-Type: application/json` antes de qualquer escrita (mesmo
  padrão CSRF já corrigido em `ferramentas_gmais.php`/`projetos.php`).
- Escrita restrita a `$is_admin`; leitura (listar APs) aberta a qualquer
  usuário autenticado não-self-service.
- `CURLOPT_SSL_VERIFYPEER => false` é aceitável aqui porque essas
  controladoras estão na rede interna com certificado autoassinado — mesmo
  padrão já em produção em `pfsense_proxy.php` para o mesmo cenário
  (dispositivo de infraestrutura interna, não um serviço público).
- Todo texto vindo da API da controladora (nome do AP, modelo) passa por
  `esc()`/`htmlspecialchars()` antes de ir pro HTML.

## Fora de escopo

- Suporte a UniFi OS (UDM Pro/Cloud Gateway, API via `/proxy/network/`) —
  as 4 controladoras atuais são todas do tipo clássico (porta 8443); pode
  virar uma spec futura se o time migrar algum equipamento.
- Login via conta de nuvem Ubiquiti (SSO) — fora de escopo, não dá pra
  automatizar com 2FA.
- Monitoramento de switches/gateways/roteadores (o card "Redes" original
  mencionava "switches, roteadores, access points") — por ora só access
  points, que é o pedido atual. A mesma API já retorna os outros tipos de
  device, então ampliar depois é incremental.
- Ações de gerenciamento (reiniciar AP, alterar config) — só leitura/
  monitoramento por enquanto.

## Teste manual

1. Como admin: cadastrar uma controladora com credenciais válidas →
   aparece na lista, "Testar" confirma login ok.
2. Cadastrar com credenciais inválidas → erro exibido, não salva.
3. APs da controladora aparecem no acordeão com status, modelo, clientes
   e uptime corretos (conferir contra a interface web da própria
   controladora).
4. Derrubar/desligar uma controladora (ou usar URL errada) → aviso
   inline nessa seção, as outras 3 controladoras continuam normais.
5. Usuário não-admin: vê a lista de APs normalmente, mas não vê botão de
   adicionar/editar/excluir controladora.
6. Card "Redes" no Inventário não aparece mais como "Em breve" e linka
   corretamente.
