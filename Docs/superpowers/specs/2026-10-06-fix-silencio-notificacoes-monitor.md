# SPEC: Fix — silêncio por horário não cobre sem_contato/ligado_muito_tempo + título "device:140" no WhatsApp
**Data:** 2026-10-06  **Tipo:** FIX  **Status:** RASCUNHO

## Contexto
Duas falhas confirmadas via DEBUG nesta sessão, ambas em `monitor_lib.php` /
`wpp/gatilhos.php` (motor do Monitor de Rede próprio, ver
[[project_monitor-rede-proprio]]):

1. **Silêncio por horário/loja/feriado não se aplica a `sem_contato` e
   `ligado_muito_tempo`.** `monitor_categoria_no_horario()` +
   `monitor_feriado_hoje()` existem e funcionam corretamente, mas
   `monitor_check_tipo()` (`monitor_lib.php:686`) só aplica esse filtro
   quando `$tipo === 'device'`. O mesmo PDV down gera DUAS ocorrências
   independentes — `monitor:device:<id>` (respeita o horário) e
   `monitor:sem_contato:<id>` (ignora o horário) — porque ambos os tipos
   rodam a mesma checagem "down" sobre o grupo `pdvs`, sem deduplicação.
   Resultado real relatado pelo usuário: PDV02-LJ010 notificou dentro do
   horário de silêncio configurado.
2. **Mensagem de "resolvido" mostra a chave crua, não o nome do
   dispositivo.** `gat_alerta_titulo_da_chave()` (`wpp/gatilhos.php:155`)
   só sabe tratar os formatos `tipo:nome` (1 `:`) e `tipo:nome|volume`
   (com `|`). Para o formato do monitor de rede, `monitor:<subtipo>:<id>`
   (2 `:`), ela cai no fallback genérico e devolve o literal
   `device:140` como "título" — o usuário leu isso como um alerta sobre
   um dispositivo `device:140` que não existe, quando na verdade é o
   ID 140 mal formatado numa mensagem de ocorrência resolvida.

## Objetivo
- `sem_contato` e `ligado_muito_tempo` passam a respeitar o mesmo filtro
  de horário/loja/feriado que `device` já respeita.
- Mensagens de ocorrência (nova/resolvida/lembrete) para chaves no
  formato `monitor:<subtipo>:<id>` mostram o nome real do dispositivo
  (buscado em `portal_monitor_dispositivos`) quando a linha ainda existe
  (ativa ou soft-removida via `removido_em`), e um rótulo claro de
  fallback (ex.: `Dispositivo removido (#140)`) quando a linha já não
  existe mais (hard delete).

## Escopo
### Inclui
- `monitor_lib.php`: estender o `if ($tipo === 'device')` em
  `monitor_check_tipo()` pra incluir `sem_contato` e
  `ligado_muito_tempo`; atualizar o comentário que hoje diz "Único dos
  tipos... que respeita" (vai deixar de ser verdade).
- `wpp/gatilhos.php`: `gat_alerta_titulo_da_chave()` ganha um parâmetro
  opcional `?PDO $pdo = null` (compatível com as chamadas/testes atuais
  que não passam `$pdo`); quando `$pdo` é informado E a chave bate no
  padrão `monitor:<subtipo>:<id>`, busca `nome` em
  `portal_monitor_dispositivos` por `id` (sem filtrar `removido_em`, pra
  cobrir o caso soft-delete) e devolve o nome; sem match, devolve
  `Dispositivo removido (#<id>)`. `gat_msg_alerta_resolvido()` ganha o
  mesmo parâmetro opcional e repassa. As duas chamadas reais em
  `gat_alertas_tipo()` (linha ~432 e ~436) passam a enviar `$pdo` (já
  disponível no escopo da função).
- Testes novos em `wpp/tests/test_gatilhos.php` cobrindo o formato
  `monitor:<subtipo>:<id>` (com `$pdo` real do teste, usando um ID que
  certamente não existe pra validar o fallback — não dá pra garantir
  nome de device real existente de forma estável no banco de produção).

### Exclui
- Deduplicar as ocorrências `device`/`sem_contato` do mesmo PDV (ficaria
  2 notificações — uma por tipo — mesmo depois do fix; é uma limpeza de
  modelagem maior, fora deste FIX pontual).
- Corrigir o texto da descrição do catálogo de `monitor_sem_contato` em
  `alertas_tipos.php` (achado paralelo, documentação desatualizada, não
  bloqueante) — fica como possível item futuro, não entra aqui.
- Qualquer mudança em `link`/`latencia`/`service` (continuam sem filtro
  de horário, é decisão antiga do usuário, não tocar).
- Limpeza retroativa de ocorrências já abertas incorretamente em
  produção (ex.: fechar manualmente um `sem_contato` que não devia ter
  disparado) — se precisar, é uma ação separada depois do deploy, não
  faz parte do código deste fix.

## Comportamento Esperado
- PDV em categoria/loja com horário de silêncio configurado, down
  durante a janela de silêncio: nem `monitor:device:<id>` nem
  `monitor:sem_contato:<id>` notificam enquanto durar a janela; ambos
  voltam a notificar quando a janela abrir (ou imediatamente se ainda
  estiver down e já tiver passado da janela).
- Dispositivo que resolve (volta `up` ou é removido) com occorrência
  `monitor:<subtipo>:<id>` aberta: mensagem de resolvido mostra o nome
  real do dispositivo (se a linha ainda existir em
  `portal_monitor_dispositivos`, removida ou não) ou
  `Dispositivo removido (#<id>)` se o ID não existir mais na tabela.
- Chaves de outros tipos de alerta (`sem_inv:...`, `disco:...|...`, etc.)
  continuam formatando exatamente como hoje — sem regressão.

## Critérios de Aceite
- [ ] `monitor_check_tipo($pdo, 'sem_contato')` com uma linha fixture
  down numa categoria/loja fora do horário configurado retorna array
  vazio (hoje retornaria a linha).
- [ ] `monitor_check_tipo($pdo, 'ligado_muito_tempo')` idem.
- [ ] `monitor_check_tipo($pdo, 'device')` continua filtrando igual (sem
  regressão).
- [ ] `gat_alerta_titulo_da_chave('monitor:device:<id-existente>', $pdo)`
  devolve o nome real do dispositivo.
- [ ] `gat_alerta_titulo_da_chave('monitor:device:999999999', $pdo)`
  devolve `Dispositivo removido (#999999999)`.
- [ ] `gat_alerta_titulo_da_chave('sem_inv:PC-01')` (sem `$pdo`, como os
  testes atuais chamam) continua devolvendo `PC-01` — sem regressão.
- [ ] `php -l` limpo nos dois arquivos no container; suíte
  `wpp/tests/run.php` roda e passa (arquivo mexido, não a suíte geral —
  ver [[feedback_suite-testes-producao]]).

## Riscos e Dependências
- **Risco de silêncio "funcionar demais":** se a categoria/loja do PDV
  não tiver horário configurado em `portal_dude_categoria_config`,
  `monitor_categoria_no_horario()` já devolve `true` (não silencia) —
  comportamento inalterado pra quem nunca configurou horário.
- **Risco de nome incorreto:** a busca por `id` em
  `portal_monitor_dispositivos` ignora `removido_em` de propósito (pra
  cobrir soft-delete) — se o mesmo `id` numérico um dia for reciclado
  por outra tabela/dispositivo, o nome mostrado seria do dispositivo
  errado. Risco aceito: `id` é `AUTO_INCREMENT`, não é reciclado pelo
  MySQL por padrão.
- **Dependência:** nenhuma migração de schema — ambas as mudanças são
  lógica pura, sem alterar tabelas.
- **Produção:** este fix afeta diretamente o worker
  `wpp/worker.php` que roda em loop contínuo — deploy segue o fluxo
  `.new`→`php -l`→`Move-Item` já estabelecido, e verificação roda via
  teste real da suíte (arquivo mexido), não script avulso.
