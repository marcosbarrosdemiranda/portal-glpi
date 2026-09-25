# Monitor de rede próprio do portal (substitui o The Dude)

> Status: **roteiro aprovado, implementação por etapas** · criado 2026-09-25
> Cada etapa é entregue, deployada e validada antes de começar a próxima.
> Branch por etapa: `feat/monitor-rede-etapaN` a partir de `infra/migracao-docker-glpi`.

---

## 1. Por que

O The Dude gera alerta falso e para de avisar sem ninguém perceber:

| Problema observado | Quando | Causa |
|---|---|---|
| PDV121 preso "down" com IP de teste (.222) | 20–24/09 | Dude mandou o "down" do teste e nunca o "up" (pra ele o device nunca caiu no IP real) |
| Dude sem notificar nada | desde 20/09 15:46 | Notification do Dude parada — sem nenhum aviso |
| "Dude sem contato" demorou 5 dias pra disparar | 25/09 | O ping de segurança do portal atualiza a mesma tabela e "zera o relógio" |
| 4 PDVs presos "down" ~20h | 14/09 | Dude travou o acompanhamento de devices específicos |

Conclusão: o problema é **depender de um sistema externo que só avisa quando muda de estado**. Qualquer aviso perdido deixa o estado errado para sempre.

## 2. O que muda

O próprio portal pinga todos os equipamentos cadastrados, a cada 1 minuto, **em paralelo**, e decide caiu/voltou com confirmação. O Dude sai do caminho.

**Continua igual (reaproveitado):** tabela `portal_dude_estado` (é de onde a Central lê), tipos da Central (`dude_device`, `dude_ligado_muito_tempo`...), horário por categoria, exceções por loja/dia, feriados, motor 🔔/✅/⏰, WhatsApp, render agrupado por categoria/loja.

**Muda:** quem escreve em `portal_dude_estado` passa a ser o monitor do portal, não o webhook do Dude.

## 3. Decisões de desenho

1. **Cadastro no portal** — tabela nova `portal_monitor_dispositivos` (nome, IP, loja, categoria, ativo). Semeada com os 40 devices que existem hoje em `portal_dude_estado`.
2. **Ping em paralelo sem mudar a imagem Docker** — o worker não tem `fping`, mas tem `ping` (iputils). Dispara 1 processo `ping -c 1 -W 1` por IP ao mesmo tempo (`proc_open`) e coleta todos: rodada inteira ≈ 1–2 s, independente de quantos estão fora. (Se um dia passar de ~200 devices, trocar por `fping` = só adicionar `fping` no `docker/Dockerfile`.)
3. **Confirmação contra falso alarme** — cai só depois de **3 falhas seguidas** (≈ 3 min); volta depois de **2 sucessos seguidos**. Valores configuráveis (padrão global na etapa 2; por categoria fica como melhoria futura).
4. **Só grava em `portal_dude_estado` quando o estado MUDA** — assim `atualizado_em` continua significando "nesse estado desde", e o "ligado muito tempo" segue funcionando sem alteração.
5. **Heartbeat próprio** — cada rodada grava `monitor_ultima_rodada` (wpp_cfg). O alerta "sem contato" passa a ser "monitor parado há X min" — nunca mais confundido com atualização de estado.
6. **Sem TCP fallback por padrão** — o `dude_ping_ip()` de hoje tenta 7 portas TCP quando o ping falha (≈ 4,5 s por device fora). Device que bloqueia ICMP ganha um campo opcional `porta_tcp` no cadastro e é testado só nela.
7. **Carga** — 40 pings/min ≈ nada pra rede e CPU. A rodada roda no `portal-wpp-worker` (já existe, loop de 30 s); o monitor se auto-limita a 1 rodada a cada 60 s.

## 4. Roteiro por etapas

### Etapa 1 — Cadastro de equipamentos (sem monitorar ainda)
**Objetivo:** ter a lista de equipamentos no portal, editável.
- [ ] `monitor_lib.php`: cria `portal_monitor_dispositivos` (`id, nome, ip UNIQUE, loja, categoria, porta_tcp NULL, ativo, status ENUM('up','down','desconhecido'), status_desde, falhas_seguidas, sucessos_seguidos, ultimo_ping, latencia_ms, criado_em`) + CRUD.
- [ ] Importação única dos 40 devices de `portal_dude_estado` (nome, IP, loja, categoria). Os 22 PDVs "sem loja" entram sem loja — a tela deixa corrigir.
- [ ] Tela `monitor_dispositivos.php` (lista com filtro por categoria/loja, novo, editar, ativar/desativar, excluir) + link em Configurar Alertas.
- [ ] Testes `wpp/tests/test_monitor_lib.php` (CRUD, IP único, validação de IP).
- **Pronto quando:** os 40 aparecem na tela, dá pra corrigir loja do PDV121 e cadastrar um novo. Nada muda nos alertas.
- **Estimativa:** ~2h.

### Etapa 2 — Motor de ping em modo sombra
**Objetivo:** o portal pinga tudo e guarda o resultado, **sem gerar alerta** — pra comparar com a realidade antes de confiar.
- [ ] `monitor_ping_lote(array $ips): array` — pings em paralelo via `proc_open`, devolve `ip => [ok, latencia_ms]`. Seam de teste igual ao `__dude_ping_fake`.
- [ ] `monitor_aplicar_resultado(array $disp, bool $ok, int $falhasParaCair, int $sucessosParaVoltar): array` — **função pura**: novo estado + se houve transição. Testes cobrindo: 1 e 2 falhas não derrubam, 3 derruba; 1 sucesso não levanta, 2 levantam; pisca-pisca não gera transição.
- [ ] `monitor_rodada(PDO $pdo)` — respeita o intervalo de 60 s, pinga os ativos, grava contadores/status/latência na tabela do monitor, grava `monitor_ultima_rodada` e a duração da rodada.
- [ ] Liga no `wpp/worker.php` (ao lado de `dude_gatilho_verificar_ping`).
- [ ] Na tela: coluna "status do monitor" + "desde" + latência, e destaque quando o monitor discorda do `portal_dude_estado`.
- **Pronto quando:** 1–2 dias rodando, duração da rodada < 5 s, e as divergências com o Dude explicadas (esperado: o monitor certo onde o Dude travou).
- **Estimativa:** ~3h + 1–2 dias de observação.

### Etapa 3 — Virada: monitor passa a mandar nos alertas
**Objetivo:** Central de Alertas passa a usar o monitor.
- [ ] Antes de tudo: conferir `notif_whatsapp` dos tipos `dude_*` e decidir com o usuário se fica ligado na virada (regra: mutar antes de testar).
- [ ] Sincronização inicial: copia o status atual do monitor para `portal_dude_estado` (1 vez), pra primeira rodada não gerar rajada de 🔔/✅.
- [ ] `monitor_rodada` passa a escrever **só as transições** em `portal_dude_estado` (`tipo='device'`, `chave=nome`, detalhe "sem resposta a ping (3 tentativas)").
- [ ] `alerta_check_dude_sem_contato` → usa `monitor_ultima_rodada` ("monitor de rede parado há X min", padrão 5 min).
- [ ] Remove `dude_gatilho_verificar_ping` do worker (o monitor já faz isso melhor) — função fica comentada + nota, conforme regra de remoção.
- [ ] Webhook do Dude: ignora devices cadastrados no monitor (loga e responde 200) — Dude pode continuar ligado sem interferir.
- **Pronto quando:** derrubar um device de teste gera 🔔 em ~3 min e ✅ em ~2 min depois de voltar; PDV desligado fora do horário não alerta.
- **Estimativa:** ~2h.

### Etapa 4 — Latência e acabamento
- [ ] Alerta de latência alta a partir da `latencia_ms` do monitor (limiar por categoria, N rodadas seguidas) → alimenta `dude_latencia`.
- [ ] Renomear na interface "The Dude" → "Monitor de rede" (nomes internos `dude_*` ficam, pra não mexer em histórico/config).
- [ ] Limiar de queda/volta por categoria (PDV pode ser mais tolerante que Servidor).
- [ ] Remover tipos sem uso (`dude_link`, `dude_service` — hoje zero registros) com spec de remoção.
- **Estimativa:** ~3h.

### Etapa 5 — Aposentar o Dude
- [ ] Depois de 1 semana estável da etapa 3: desligar as Notifications no cliente do Dude, apagar o token do webhook, parar o container `dude` (decisão do usuário).
- [ ] Atualizar runbook e memória.

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Device bloqueia ICMP (aparece sempre "down") | Campo `porta_tcp` no cadastro; a etapa 2 (sombra) mostra quais são antes de virar |
| Rodada lenta trava o worker (WhatsApp/alertas atrasam) | Pings em paralelo com timeout de 1 s; duração medida e exibida na etapa 2 |
| Rajada de notificação na virada | Sincronização inicial + conferir mute antes (etapa 3) |
| Worker parado = monitor parado | Heartbeat `monitor_ultima_rodada` + alerta "monitor parado" |
| Rede da loja cai inteira → 8 PDVs alertam de uma vez | Aceito na v1 (é informação real); agrupamento por loja fica como melhoria |

## 6. Fora do escopo
- Monitorar serviços (HTTP/porta de aplicação) — o Ponto já tem webhook próprio.
- SNMP / tráfego / banda (backlog separado).
