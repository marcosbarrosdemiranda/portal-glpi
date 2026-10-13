# SPEC: Fix — falso-positivo de ✅ "resolvido" ao bater o horário de silêncio
**Data:** 2026-10-13  **Tipo:** FIX  **Status:** APROVADA

## Bug confirmado
`monitor_check_tipo()` (`monitor_lib.php:692-697`) remove inteiramente do
resultado qualquer PDV (tipos `device`/`ligado_muito_tempo`) que esteja
fora da janela 07:00–20:00 da categoria (ou em feriado) — mesmo que o
dispositivo continue de fato caído.

`gat_alertas_tipo()` (`wpp/gatilhos.php:443-460`) não tem como diferenciar
"voltou de verdade" de "saiu da lista porque entrou a janela de
silêncio": ao não achar mais a chave em `$atuais`, sempre trata como
RESOLVIDA e manda ✅ pro grupo + apaga a ocorrência.

## Comportamento desejado
Entre 20:00–07:00 (ou em feriado), PDV sem comunicação não deve gerar 🔔
nem ✅ — a ocorrência deve ficar "congelada" (continuar aberta, sem
notificar nada) até a janela reabrir. Só aí, com o dado real, decide se
dispara ✅ (voltou de verdade) ou mantém aberta (ainda caído).

## Causa raiz
O filtro de horário/feriado hoje é **posicional** (tira a linha do
array) em vez de **informacional** (marcar e deixar quem decide
notificar avaliar). Isso faz o "desaparecimento por horário" ser
indistinguível de "desaparecimento por recuperação real" no único lugar
que decide 🔔/✅.

## Mudança proposta (2 arquivos)

### 1. `monitor_lib.php:monitor_check_tipo()`
Trocar o `array_filter` (que remove a linha) por um `foreach` que mantém
a linha e acrescenta `'silenciado' => bool` (true = fora da
janela/feriado). Nenhum outro tipo ganha essa chave (ausente = false nos
consumidores).

### 2. `wpp/gatilhos.php:gat_alertas_tipo()`
- **NOVAS:** pular (não grava, não envia) ocorrência com
  `silenciado=true` — mesmo padrão já usado pro horário genérico por tipo
  ("não grava, retenta quando a janela abrir").
- **RESOLVIDAS:** nenhuma mudança de código necessária — como a chave
  agora continua presente em `$atuais` (só com a flag),
  `isset($porChave[$chave])` passa a ser `true` e o item simplesmente não
  entra no caminho de resolução. É isso que mata o falso ✅.
- **LEMBRETE:** pular item com `silenciado=true` ao montar `$devidas`
  (não cutucar lembrete durante a janela de silêncio).

## Efeito concreto no caso relatado
- PDV cai às 18h (dentro da janela) → 🔔 dispara normalmente.
- Bate 20:00 → dispositivo passa a aparecer com `silenciado=true`, mas
  continua presente → nenhuma notificação, ocorrência permanece aberta.
- Às 07:00 a janela reabre:
  - Se o PDV realmente ainda estiver caído, nada dispara (continua
    aberto, sem novidade).
  - Se tiver voltado de verdade durante a madrugada, aí sim dispara ✅.

## Fora de escopo
O horário genérico por tipo (`portal_alertas_horario`,
`$dentroDoHorario` em `gat_alertas_tipo`) já ignora RESOLVIDAS hoje — mas
esse mecanismo só suprime notificação, nunca remove a ocorrência da
lista. O que `gat_alertas_tipo` recebe, então, não tem o mesmo bug (não
há falso ✅ por aí). Não tocar nesse caminho — é comportamento diferente
e não foi o reportado.

## Testes a ajustar/acrescentar
- `wpp/tests/test_monitor_lib.php` (~linha 189-194) — hoje afirma que o
  PDV fora do horário some de `monitor_check_tipo('device')`; passa a
  afirmar que ele continua aparecendo, com `silenciado=true`; dentro do
  horário, `silenciado=false`.
- `wpp/tests/test_gatilhos.php` — novo bloco simulando
  `$GLOBALS['_fake_ocorr']` com um item `silenciado=true`:
  - (a) Como NOVA, não grava/não envia.
  - (b) Já aberta (sem a flag) e depois reaparecendo com a flag, não
    dispara ✅ nem apaga a linha.
  - (c) Com lembrete vencido, não reenvia enquanto `silenciado=true`.

## Verificação (2026-10-13, antes de iniciar implementação)
Spec recuperada de sessão que caiu; revalidada linha a linha contra o
código atual antes de aprovar — `monitor_lib.php:692-697`,
`wpp/gatilhos.php:443-460` e o teste existente em `test_monitor_lib.php`
conferem exatamente com o descrito acima.

Aprovado pelo usuário em 2026-10-13.
