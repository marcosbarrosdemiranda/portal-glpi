# SPEC: Alerta — Sitef (Fiserv) sem resolver DNS
**Data:** 2026-10-06  **Tipo:** FEAT  **Status:** RASCUNHO

## Contexto
Implementação do item anotado em [[backlog_sitef-express]] (pedido
2026-09-13, só documentado até agora). O usuário agora define o desenho
concreto: monitorar a resolução DNS do host `tls-prod.fiservapp.com`
(endpoint usado pelo Sitef/Fiserv — captura/pagamento com cartão nas
lojas) e emitir alerta (notificação do portal + WhatsApp) quando esse
nome parar de resolver, usando o mesmo motor da Central de Alertas já
usado por `sefaz_ms`/`solides_*`/`monitor_*` (catálogo em
`alertas_tipos.php`, ocorrências em `wpp/gatilhos.php`).

Imediatamente antes deste pedido, uma falha real e passageira de
conectividade com o Ponto (API Sólides) se resolveu sozinha em minutos
— por isso o usuário escolheu, via pergunta direta, exigir 5 minutos
seguidos sem resolver antes de disparar (evitar o mesmo tipo de falso
positivo com o Sitef).

## Objetivo
Novo tipo de alerta `sitef_dns` no catálogo da Central de Alertas:
checa se `tls-prod.fiservapp.com` resolve via DNS; se ficar **5 minutos
seguidos** sem resolver, dispara ocorrência (notificação do portal +
WhatsApp, se o tipo estiver com `notif_whatsapp=1` — nasce com `0` por
[[feedback_mutar-whatsapp-antes-de-testar]]); auto-resolve (mensagem
"✅ Resolvido") quando voltar a resolver.

## Escopo
### Inclui
- `sitef_lib.php` (novo, raiz, mesmo padrão de `solides_lib.php`):
  - `alerta_check_sitef_dns(PDO $pdo, array $p): array` — faz
    `checkdnsrr('tls-prod.fiservapp.com', 'A')`; se resolver, limpa o
    estado de falha e devolve `[]`; se não resolver, rastreia "desde
    quando" via `portal_wpp_config` (chave `sitef_dns_falha_desde`,
    mesmo mecanismo `wpp_cfg_get()/wpp_cfg_set()` já usado em
    `solides_lib.php`); só devolve a ocorrência quando o tempo de falha
    seguida ≥ `$p['minutos']` (default 5).
  - `alerta_render_sitef(array $ocorr, string $tipo = ''): string` —
    innerHTML simples, mesmo formato de `alerta_render_solides`
    (tabela título/detalhe + botão de dispensar).
- `alertas_tipos.php`: `require_once __DIR__ . '/sitef_lib.php';` (junto
  dos outros `require_once` de lib) + nova entrada no catálogo:
  ```php
  'sitef_dns' => [
      'nome'      => 'Sitef (Fiserv) — DNS não resolve',
      'descricao' => 'tls-prod.fiservapp.com (endpoint do Sitef/Fiserv,
          pagamento com cartão) sem resolver via DNS por tempo seguido.',
      'params'    => [
          'minutos' => ['label' => 'Minutos seguidos sem resolver', 'default' => 5, 'min' => 2, 'max' => 60],
      ],
      'check'  => 'alerta_check_sitef_dns',
      'render' => 'alerta_render_sitef',
      'icone'  => 'bi-credit-card-2-front',
      'cor'    => 'danger',
  ],
  ```
- `wpp/tests/test_sitef_lib.php` (novo): testa a lógica de
  grace-period com `$pdo` real — simula falha nova (sem estado prévio →
  não dispara ainda), falha persistente (estado antigo ≥5min → dispara)
  e volta a resolver (limpa estado); não depende de rede de verdade
  pro host da Fiserv (controla via mock da própria função de checagem
  DNS, igual ao padrão de `$GLOBALS['__wpp_fake_send']` usado nos testes
  de `gatilhos.php`).
- Seguir [[feedback_mutar-whatsapp-antes-de-testar]]: após o deploy,
  `UPDATE portal_alertas_config SET notif_whatsapp=0 WHERE tipo='sitef_dns'`
  (ou equivalente) ANTES de qualquer teste, e só ligar de volta depois
  de confirmar que não dispara em falso.

### Exclui
- Checagem de porta/serviço TCP do Sitef em si (o backlog original
  especulava isso; o usuário confirmou agora que é só resolução DNS).
- Checagem por loja/rede individual — é uma checagem central única
  (mesmo padrão pull de `sefaz_ms`/`solides_checagem`), não simula a
  visão de cada loja; não existe hoje infraestrutura de probe remoto
  por loja pra esse tipo de checagem.
- Qualquer alerta de "IP mudou"/DNS poisoning — só resolve/não resolve.
- Corrigir a descrição desatualizada do catálogo de `monitor_sem_contato`
  (achado paralelo de outra sessão, não relacionado a este FEAT).

## Comportamento Esperado
- DNS resolvendo normalmente: nenhuma ocorrência, catálogo mostra "sem
  problemas".
- DNS para de resolver por menos de 5 minutos (ex.: blip passageiro):
  nenhuma ocorrência é criada, nenhuma notificação/WhatsApp.
- DNS para de resolver por 5 minutos seguidos ou mais: ocorrência
  `sitef_dns:tls-prod.fiservapp.com` criada, notificação no portal +
  WhatsApp (se `notif_whatsapp=1`).
- DNS volta a resolver depois de uma ocorrência aberta: mensagem de
  "✅ Resolvido" (mesmo fluxo genérico de `gat_alertas_tipo()`), estado
  de falha limpo.

## Critérios de Aceite
- [ ] `alerta_check_sitef_dns()` com DNS ok (mock) sempre devolve `[]` e
  limpa qualquer estado de falha anterior.
- [ ] Falha simulada pela primeira vez (sem estado prévio): devolve `[]`
  (grace period começando), mas grava `sitef_dns_falha_desde`.
- [ ] Falha simulada com `sitef_dns_falha_desde` 6 minutos atrás (mock):
  devolve 1 ocorrência com `chave = 'sitef_dns:tls-prod.fiservapp.com'`.
- [ ] Falha simulada com `sitef_dns_falha_desde` 2 minutos atrás: ainda
  devolve `[]` (dentro do grace period de 5min).
- [ ] Entrada aparece em "Configurar Alertas" com os campos
  nome/descrição/ícone corretos e o parâmetro "Minutos seguidos sem
  resolver" editável.
- [ ] `php -l` limpo no container; `wpp/tests/test_sitef_lib.php` passa
  (arquivo mexido, não a suíte geral).
- [ ] Pós-deploy: `notif_whatsapp=0` confirmado ANTES de qualquer teste
  real/observação em produção.

## Riscos e Dependências
- **Risco de falso positivo residual:** se o container `glpi-web` ou
  `portal-wpp-worker` tiver seu próprio problema de DNS (resolver local
  quebrado) independente da Fiserv estar no ar, o alerta dispara mesmo
  com o Sitef saudável — é uma limitação inerente de checar DNS de
  dentro de um único ponto da rede, não tem como diferenciar sem outro
  ponto de referência; aceito como trade-off do mesmo padrão já usado
  pelos outros checks pull (`sefaz_ms`, `solides_checagem`).
- **Dependência:** nenhuma migração de schema — reaproveita
  `portal_wpp_config` (já existe, key-value genérico).
- **Onde o check roda:** mesmo mecanismo dos demais tipos do catálogo —
  dentro do loop do `wpp/worker.php` (Central de Alertas), não é uma
  rotina separada nem cron novo.
- **Produção:** `checkdnsrr()` precisa estar disponível no container
  `glpi-web`/worker (extensão de rede padrão do PHP) — validar no
  `php -l`/execução do teste antes de considerar concluído.
