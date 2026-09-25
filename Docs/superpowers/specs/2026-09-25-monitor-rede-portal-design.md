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

1. **Equipamentos vêm do inventário** (revisado 2026-09-25) — não é cadastro paralelo. Fontes: computadores do GLPI (categoria do portal: PDVs, PCs retaguarda, VMs, TVs, rádios…; loja = entidade do GLPI), `portal_balancas`, `portal_pfsense_lojas`, `portal_servidores_mgv`. Ficam de fora os `__ignorado__` (containers Docker que o agente cadastra como PC) e as impressoras (já têm worker SNMP próprio). Cadastro manual só pra exceção (switch/link fora do inventário). 31 dos 40 devices do Dude já batem por IP com o GLPI; os outros 9 estão em balanças/servidores.
   - **IP:** PC com vários IPs no GLPI → usa o da rede da loja (`192.168.x`); dá pra fixar outro IP manualmente no equipamento.
1b. **Chave "Monitorar" em cada equipamento** — na tela de detalhe do inventário e na lista do monitor. Tabela `portal_monitor_dispositivos` guarda só o que o inventário não tem: `origem` + `origem_id` (ex.: `glpi_computer:123`, `balanca:7`, `manual:1`), `monitorar` (sim/não), IP fixado, e o estado do ping. Equipamento novo no inventário entra com o padrão do grupo ("monitorar novos automaticamente").
1c. **Configuração por grupo** (grupo = categoria: PDVs, Balanças, Servidores…) — cada grupo tem a própria forma de alertar. Evolui a `portal_dude_categoria_config` que já existe (horário, ligado muito tempo) com:
   - monitorar novos automaticamente (sim/não)
   - tolerância: falhas pra considerar caído / sucessos pra considerar de volta (ex.: PDV 5 falhas, Servidor 2)
   - WhatsApp ligado/desligado **para aquele grupo**
   - lembrete (a cada X min enquanto continuar fora)
   - horário de notificação + exceções por loja/dia + feriados (já existem)
   - *(a confirmar)* grupo de WhatsApp de destino diferente por categoria — hoje só existe 1 grupo de alertas
2. **Ping em paralelo sem mudar a imagem Docker** — o worker não tem `fping`, mas tem `ping` (iputils). Dispara 1 processo `ping -c 1 -W 1` por IP ao mesmo tempo (`proc_open`) e coleta todos: rodada inteira ≈ 1–2 s, independente de quantos estão fora. (Se um dia passar de ~200 devices, trocar por `fping` = só adicionar `fping` no `docker/Dockerfile`.)
3. **Confirmação contra falso alarme** — cai só depois de **3 falhas seguidas** (≈ 3 min); volta depois de **2 sucessos seguidos**. Padrão 3/2, configurável por grupo (etapa 1).
4. **Só grava em `portal_dude_estado` quando o estado MUDA** — assim `atualizado_em` continua significando "nesse estado desde", e o "ligado muito tempo" segue funcionando sem alteração.
5. **Heartbeat próprio** — cada rodada grava `monitor_ultima_rodada` (wpp_cfg). O alerta "sem contato" passa a ser "monitor parado há X min" — nunca mais confundido com atualização de estado.
6. **Sem TCP fallback por padrão** — o `dude_ping_ip()` de hoje tenta 7 portas TCP quando o ping falha (≈ 4,5 s por device fora). Device que bloqueia ICMP ganha um campo opcional `porta_tcp` no cadastro e é testado só nela.
7. **Carga** — 40 pings/min ≈ nada pra rede e CPU. A rodada roda no `portal-wpp-worker` (já existe, loop de 30 s); o monitor se auto-limita a 1 rodada a cada 60 s.

## 4. Roteiro por etapas

### Etapa 1 — Equipamentos do inventário + chave "Monitorar" + grupos (sem monitorar ainda)
**Objetivo:** saber exatamente o que vai ser monitorado, direto do inventário, e poder ligar/desligar por equipamento e por grupo.
- [ ] `monitor_lib.php`: `monitor_listar_inventario()` junta as fontes (GLPI computers por categoria, balanças, pfSense, servidores MGV) → lista única `origem, origem_id, nome, ip, loja, grupo`. Regra de IP: preferir `192.168.x`; IP fixado manualmente vence.
- [ ] Tabela `portal_monitor_dispositivos` (`origem, origem_id` UNIQUE, `monitorar`, `ip_fixo NULL`, `porta_tcp NULL`, `status ENUM('up','down','desconhecido')`, `status_desde`, `falhas_seguidas`, `sucessos_seguidos`, `ultimo_ping`, `latencia_ms`) + manuais (`origem='manual'`, com nome/IP/loja/grupo próprios).
- [ ] Config por grupo: colunas novas em `portal_dude_categoria_config` — `monitorar_novos`, `falhas_para_cair` (padrão 3), `sucessos_para_voltar` (padrão 2). (WhatsApp/lembrete por grupo ficam na etapa 4, que mexe no motor.)
- [ ] Semente: os 40 do Dude entram como `monitorar=sim` (casados por IP com o inventário); grupos PDVs/Balança/Servidor com `monitorar_novos=sim`, demais grupos `não` até o usuário ligar.
- [ ] Tela `monitor_dispositivos.php`: lista por grupo/loja com a chave Monitorar, IP efetivo, origem; bloco de config por grupo; cadastro manual. Link em Configurar Alertas.
- [ ] Chave "Monitorar" também na tela de detalhe do equipamento no inventário.
- [ ] Testes `wpp/tests/test_monitor_lib.php`: regra de escolha de IP, IP fixo vence, `monitorar_novos` aplicado a equipamento novo, ignorados/impressoras fora, manual com IP inválido recusado.
- **Pronto quando:** a tela mostra os equipamentos do inventário por grupo com loja certa (PDV121 com loja), dá pra ligar/desligar um equipamento e um grupo inteiro. Nada muda nos alertas.
- **Estimativa:** ~3h.

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
- [ ] `monitor_rodada` passa a escrever **só as transições** em `portal_dude_estado` (`tipo='device'`, `chave=nome`, detalhe "sem resposta a ping (N tentativas)", N = tolerância do grupo).
- [ ] `alerta_check_dude_sem_contato` → usa `monitor_ultima_rodada` ("monitor de rede parado há X min", padrão 5 min).
- [ ] Remove `dude_gatilho_verificar_ping` do worker (o monitor já faz isso melhor) — função fica comentada + nota, conforme regra de remoção.
- [ ] Webhook do Dude: ignora devices cadastrados no monitor (loga e responde 200) — Dude pode continuar ligado sem interferir.
- **Pronto quando:** derrubar um device de teste gera 🔔 em ~3 min e ✅ em ~2 min depois de voltar; PDV desligado fora do horário não alerta.
- **Estimativa:** ~2h.

### Etapa 4 — Notificação por grupo
**Objetivo:** PDVs notificam de um jeito, Balanças de outro.
- [ ] Colunas novas em `portal_dude_categoria_config`: `notif_whatsapp` (sim/não por grupo), `lembrete_min`, e — se confirmado — `destino_jid` (grupo de WhatsApp próprio da categoria; vazio = grupo de alertas padrão).
- [ ] Motor (`wpp/gatilhos.php` / `gat_alertas_tipo`): hoje WhatsApp e lembrete são por **tipo** de alerta; passa a consultar a config do **grupo** da ocorrência quando ela tiver categoria (tipo continua valendo como chave geral: tipo desligado = nada sai).
- [ ] Tela: bloco de notificação dentro da config de cada grupo.
- [ ] Testes: grupo com WhatsApp desligado não envia, lembrete por grupo respeitado, grupo sem config herda o do tipo.
- **Antes de testar:** mutar WhatsApp (regra do projeto).
- **Estimativa:** ~3h.

### Etapa 5 — Latência e acabamento
- [ ] Alerta de latência alta a partir da `latencia_ms` do monitor (limiar por grupo, N rodadas seguidas) → alimenta `dude_latencia`.
- [ ] Renomear na interface "The Dude" → "Monitor de rede" (nomes internos `dude_*` ficam, pra não mexer em histórico/config).
- [ ] Remover tipos sem uso (`dude_link`, `dude_service` — hoje zero registros) com spec de remoção.
- **Estimativa:** ~2h.

### Etapa 6 — Aposentar o Dude
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
