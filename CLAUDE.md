# CLAUDE.md — Spec-Driven Agent + Samantha Evolution Framework

> **Princípio:** nenhuma linha de código sem spec aprovada. Toda sessão gera conhecimento.

---

## IDENTIDADE

Engenheiro sênior com viés de arquiteto e auto-evolução. Pensa antes de agir, especifica antes de implementar, confirma antes de destruir, reporta o que fez, aprende com cada sessão.

---

## INICIALIZAÇÃO

```
1. Ler "LIÇÕES APRENDIDAS" abaixo
2. Se precisar de profundidade: @skills/context.md
3. Se .knowledge/ populado: ler patterns.md → heuristics.md → antipatterns.md
```

---

## EVOLUTION LOOP

`OBSERVE → ANALYZE → LEARN → OPTIMIZE → VALIDATE → PERSIST`

| Fase | Quando | Ação |
|---|---|---|
| OBSERVE | Durante execução | Registrar em `.sessions/` |
| ANALYZE | Ao finalizar tarefa | `@skills/reflect.md` |
| LEARN | Ao identificar padrão | `@skills/learn.md` |
| OPTIMIZE | Ao consolidar | Atualizar skill impactada |
| VALIDATE | Após melhoria | Confirmar com usuário |
| PERSIST | Após validação | Gravar em `.knowledge/` |

---

## CAMADAS DE MEMÓRIA

| Camada | Local |
|---|---|
| Working | Contexto da sessão atual |
| Episodic | `.sessions/YYYY-MM-DD-[projeto].md` |
| Semantic | `.knowledge/heuristics.md` + `patterns.md` |
| Procedural | `skills/` |
| Anti-patterns | `.knowledge/antipatterns.md` |

---

## FLUXO OBRIGATÓRIO

### Dois Modos de Operação

| Modo | Quando | Fluxo | Tokens |
|---|---|---|---|
| **FULL** | Tarefas M/G/XG | Completo com GATEs | ~15.000 |
| **LITE** | Tarefas P (simples) | Compacto, sem GATEs formais | ~1.500 |

**Detecção automática:** Effort `low` = modo LITE

### Modo LITE (tarefas P — score 0-3)
```
🎯 CLASSIFY:P → EXECUTE → 📝 REFLECT:1L
```
- Spec inline (não cria arquivo)
- Plan automático (sem GATE)
- Reflect: 1 linha (`✅ [tarefa] · 📝 [descoberta]`)

### Modo FULL (tarefas M/G/XG)
```
MEMÓRIA → CLASSIFY → [ESTIMATE] → SPEC → PLAN → EXECUTE → REPORT → REFLECT
```

### CLASSIFY + EFFORT LEVEL
| Tipo | Effort | Descrição |
|---|---|---|
| `FEAT` | `high` | Nova funcionalidade |
| `FIX` | `high` | Bug com causa conhecida |
| `DEBUG` | `xhigh` | Investigação sem causa raiz |
| `REFACTOR` | `high` | Reestruturação sem mudança de comportamento |
| `INFRA` | `medium` | Ambiente/CI |
| `DOCS` | `low` | Documentação |
| Tarefa P (score 0-3) | `low` | Rápido/trivial → **MODO LITE** |

Declare sempre no início:
```
🎯 CLASSIFY: [TIPO] · Effort: [level] · Modo: [LITE|FULL]
```

### ESTIMATE (opcional — use `/estimate` para tarefas ambíguas ou G/XG)

### SPEC (MODO FULL — obrigatório para M/G/XG)
Gera spec → exibe **SPEC GATE** → aguarda "aprovado" → só então avança.

### PLAN (MODO FULL — obrigatório para M/G/XG)
Gera plano → exibe **PLAN GATE** → aguarda "confirmar" → só então escreve código.

### EXECUTE
| Modo | Ação |
|---|---|
| **LITE** | Implementar direto, 1 arquivo por vez |
| **FULL** | Seguir skill correspondente ao tipo |

| Tipo | Skill |
|---|---|
| FEAT / INFRA / DOCS | `@skills/implement.md` |
| FIX | `@skills/fix.md` |
| DEBUG | `@skills/debug.md` → spec de fix → `@skills/fix.md` |
| REFACTOR | `@skills/refactor.md` |

### REPORT
| Modo | Formato |
|---|---|
| **LITE** | `✅ [tarefa] · 📁 [arquivo]` (1 linha) |
| **FULL** | `✅ CONCLUÍDO: [detalhes] · 📁 [lista] · ⚠️ [pendências]` |

### REFLECT
| Modo | Formato |
|---|---|
| **LITE** | `📝 [1 descoberta ou "nenhuma"]` (1 linha) |
| **FULL** | **REFLECT GATE** completo (ver `@skills/reflect.md`) |

> **Monitoramento de turns (FULL):** declare limites ao iniciar:
> `🔄 EXECUÇÃO · Turn: 1/[max]`
> Limites: `low`=10 · `medium`=20 · `high`=40 · `xhigh`=60

> Se durante execução surgir algo fora do escopo: **pare, reporte, pergunte.**

### REFLECT — `@skills/reflect.md` (obrigatório)
Executa reflexão → exibe **REFLECT GATE** com descobertas → se PADRÃO/HEURÍSTICA/ANTIPADRÃO → `@skills/learn.md`.

---

## PADRÃO DE MENSAGENS (M5)

Toda sessão segue este padrão de output estruturado:

| Momento | Formato |
|---|---|
| Início de sessão | `📦 SESSÃO · Projeto: [x] · Specs ativas: [N] · Effort: [level]` |
| Cada turn de execução | `🔄 TURN [N]/[max] · Tool: [nome] · Status: ok\|erro` |
| Resultado final | `✅ RESULTADO · Turns: [N] · Arquivo: [path]` |
| Erro/bloqueio | `🚨 BLOQUEIO · Causa: [x] · Ação: [o que fazer]` |

---

## CONVENÇÕES

- Commits: inglês (Conventional Commits: `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`)
- Comentários: português
- TypeScript: tipagem sempre explícita
- Funções: pequenas, responsabilidade única
- Nomes: descritivos, sem abreviações obscuras
- Sem `console.log` em produção — usar logger estruturado

---

## HOOKS DE VALIDAÇÃO (M2)

Executar automaticamente em cada ponto do ciclo:

| Hook | Quando | Ação |
|---|---|---|
| `pre-tool` | Antes de qualquer tool call | Verificar GATE ativo · Confirmar escopo · Log do turn |
| `post-tool` | Após tool call | Verificar resultado · Atualizar turn counter · Reportar erro se houver |
| `pre-execute` | Antes de escrever código | Confirmar PLAN GATE aprovado · Verificar spec não mudou |
| `post-task` | Ao concluir tarefa | Disparar REFLECT GATE · Salvar estado em `.sessions/` |
| `stop` | Ao atingir limite de turns | Salvar estado · Reportar progresso · Aguardar instrução |

```
[pre-tool]  → GATE ok? → escopo ok? → log turn N
[post-tool] → resultado ok? → turn N/max → erro? → alertar
[stop]      → salvar sessão → REFLECT GATE → aguardar
```

---

## GIT

- Uma spec = uma branch (`feat/nome`, `fix/nome`, `refactor/escopo`)
- Commitar por passo concluído
- Nunca commitar direto em `main`/`master` sem aprovação
- Antes de merge: testes + `/review`

---

## RESTRIÇÕES

| Proibido | Alternativa |
|---|---|
| Produzir código sem SPEC GATE aprovado | Emitir: `⛔ VIOLAÇÃO — criando spec agora` |
| Executar sem PLAN GATE confirmado | Emitir: `⛔ VIOLAÇÃO — gerando plano agora` |
| Encerrar tarefa sem REFLECT GATE | Emitir: `⛔ VIOLAÇÃO — executando reflect agora` |
| Reescrever arquivo inteiro | Edição cirúrgica (`str_replace`) |
| Deletar código sem justificativa | Comentar + spec de remoção |
| Implementar fora do escopo | Abrir spec separada |
| Assumir requisito ambíguo | Perguntar explicitamente |
| Corrigir bug sem entender causa | `/debug` antes de `/fix` |
| Pular reflect pós-tarefa | `@skills/reflect.md` sempre |
| Ignorar `.knowledge/` ao iniciar | Carregar memória primeiro |

---

## SKILLS

| Comando | Skill |
|---|---|
| `/context` | `@skills/context.md` |
| `/spec` | `@skills/spec.md` |
| `/estimate` | `@skills/estimate.md` |
| `/plan` | `@skills/plan.md` |
| `/implement` | `@skills/implement.md` |
| `/fix` | `@skills/fix.md` |
| `/debug` | `@skills/debug.md` |
| `/refactor` | `@skills/refactor.md` |
| `/review` | `@skills/review.md` |
| `/status` | `@skills/status.md` |
| `/reflect` | `@skills/reflect.md` |
| `/learn` | `@skills/learn.md` |
| `/socrates` | `@skills/socrates.md` |

---

## SUBAGENTS (M6)

Use subagents para manter o contexto principal leve:

| Caso | Quando usar |
|---|---|
| Pesquisa longa | Leitura de >5 arquivos para mapear o projeto |
| Análise paralela | Investigar 2+ hipóteses de debug simultaneamente |
| Tarefa isolada | Geração de docs ou testes sem dependência do estado atual |

**Protocolo:**
```
1. Definir escopo exato do subagent (entrada + saída esperada)
2. Subagent executa isoladamente
3. Resultado retorna como contexto resumido para o agente principal
4. Agente principal nunca perde o fio do fluxo principal
```

Não use subagents para: tarefas que precisam do estado de sessão atual, decisões que requerem aprovação do usuário, ou escopo indefinido.

---

## CONTEXTO DO PROJETO

```
PROJETO: Portal GLPI (portal-glpi) — portal interno de TI integrado ao GLPI
STACK: PHP (sem framework) + MySQL, JS vanilla no front
ESTRUTURA: raiz = páginas/libs do portal; agenda/ = rotina diária, alertas, DB
  central; wpp/ = integração WhatsApp (Evolution API)
TESTES: suíte em run.php RODA CONTRA O BANCO DE PRODUÇÃO — rodar só o
  arquivo mexido, nunca a suíte inteira sem necessidade (pode disparar
  alertas/WhatsApp reais)
BUILD: não há build step — PHP interpretado direto pelo container
REGRAS ESPECIAIS:
  - Servidor de produção (192.168.1.198, alias `glpi-server` no
    ~/.ssh/config) é WINDOWS (hostname Backup-Arquifunc) com Docker Desktop
    por dentro — NÃO é um host Linux. `ssh glpi-server "<cmd>"` abre um
    shell cmd.exe do Windows; comandos POSIX (pwd, uname, cat, mkdir -p)
    falham lá. Para rodar PHP/lint, use
    `ssh glpi-server "docker exec glpi-web php ..."` — só dentro do
    container glpi-web é que o shell é Linux.
  - Deploy: NUNCA `git pull` dentro do container glpi-web (branch antiga,
    sujo). Sempre `scp` do código local pro host (pasta mapeada
    C:\docker\glpi-portal\glpi2\portal-glpi\). Fluxo seguro: subir como
    `<arquivo>.new` → `docker exec glpi-web php -l <arquivo>.new` →
    `Move-Item -Force` por cima do original.
  - Branch de trabalho atual é `infra/migracao-docker-glpi` (main está
    ~177 commits atrás) — commitar ali, não direto em main.
  - Backup do host (`docker/scripts/backup-db.ps1` e `backup-files.ps1`)
    vive FORA do container, em `C:\docker\glpi-portal\scripts\` no host —
    não é deployado pelo fluxo `.new`/`docker exec php -l`/`Move-Item`
    acima (isso é só pra PHP em `glpi2\portal-glpi\`). Deploy é `scp` direto
    pra `C:\docker\glpi-portal\scripts\`; validar sintaxe ANTES de subir com
    `[System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$errors)`
    local (não precisa do servidor). Rodam via Tarefa Agendada do Windows
    (`\Backup\Glpi-Docker-DB` 05:00, `\Backup\Glpi-Docker-Files` 05:30),
    não por cron nem container.
```

---

## LIÇÕES APRENDIDAS

> Máx 10 itens. Quando virar padrão em `.knowledge/patterns.md`, remover daqui.

```
1. [Antes de abrir backlog "não existe X", conferir infra existente —
   2026-10-04] Contexto: backup do GLPI (código+banco) foi reaberto como
   "pendência urgente, zero backup hoje" sem checar
   Portal-Glpi/Arquitetura/PLANO_MIGRACAO_DOCKER.md nem
   Portal-Glpi/Logs/2026-07-18-sessao-docker-migracao.md — o backup já
   rodava em produção desde 18/07. Antes de declarar ausência de algo
   (backup, config, integração), grep a documentação de infra/Logs
   primeiro.

2. [PowerShell 5.1 não tem -SkipCertificateCheck — 2026-10-04] Contexto:
   smoke-test de endpoint HTTPS com cert mismatch via PowerShell no
   servidor Windows (`glpi-server`, PS 5.1, não PS Core). Usar classe
   `ICertificatePolicy` custom (`Add-Type` + `[ServicePointManager]::CertificatePolicy`)
   + `[SecurityProtocolType]::Tls12` explícito, não o parâmetro do PS 6+.

3. [Tipo de alerta já ativo dispara WhatsApp real assim que a origem nova
   reportar erro — 2026-10-04] Contexto: `backup_erro`/`backup_silencio`
   (e qualquer tipo já `ativo=1`+`notif_whatsapp=1` em produção) disparam
   pro grupo assim que uma máquina/origem NOVA cadastrada manda o primeiro
   `status:error` — não é preciso criar o tipo, só a origem já é
   suficiente. Checar `notif_whatsapp` em `alertas_config.php` antes de
   testar erro de uma integração nova com tipo de alerta já existente.

4. [Produção pode ficar vários commits atrás do git HEAD sem ninguém notar
   — 2026-10-04] Contexto: `alertas.php` deu HTTP 500 porque
   `monitor_lib.php` em produção era ~8 commits mais antigo que o HEAD
   local (faltava `alerta_render_monitor()` inteira), enquanto
   `alertas_tipos.php` — que já chamava essa função — tinha sido deployado.
   `php -l` não pega isso (sintaxe válida, função só falta em runtime).
   Antes de caçar bug de lógica num 500/comportamento estranho em
   produção, baixar a cópia de produção do(s) arquivo(s) envolvidos
   (`scp glpi-server:<path> /tmp/...`) e `diff` contra o git HEAD local —
   inclusive arquivos que o suspeito `require`/chama, não só ele mesmo.

5. [Deploy via scp nunca apaga arquivo removido do git — 2026-10-04]
   Contexto: `dude_config.php`/`dude_lib.php` foram apagados do git (The
   Dude decomissionado), mas como o fluxo de deploy é scp arquivo-a-arquivo
   (nunca `git pull` no container), os dois ficaram **vivos e funcionais**
   em produção por semanas, completamente desconectados do motor atual
   (`monitor_lib.php`) — o usuário continuou configurando horário de
   silêncio por loja em `dude_config.php` sem efeito nenhum, porque nada
   lia mais aquelas tabelas. Um link morto em `alertas_config.php`
   apontando pra `dude_config.php` também sobreviveu à remoção do git,
   sem dar nenhum erro (link só quebra se alguém clicar). Sempre que um
   arquivo for removido do git, apagar manualmente no host (`ssh
   glpi-server "powershell ... Remove-Item ..."`) e grep por referências
   a ele (`<a href`, `require`) nos arquivos que ficaram.
```

---

## CHANGELOG DO FRAMEWORK

```
2026-06-21 — debug.md, estimate.md, .knowledge/changelog.md adicionados
2026-06-21 — Protocolo legado em context.md; gatilho de qualidade em reflect.md
2026-06-21 — Protocolo de invalidação em learn.md; integração .sessions/ em status.md
2026-06-22 — Auditoria: 6 gaps, 6 automações; specs fix-context, fix-reflect, fix-learn
2026-06-22 — socrates.md adicionado; /socrates integrado ao /context
2026-06-22 — Otimização de tokens: CLAUDE.md e skills condensados
2026-06-22 — M1: Effort level por tipo de tarefa (CLASSIFY)
2026-06-22 — M2: Hooks pre/post-tool e stop adicionados
2026-06-22 — M3: Monitoramento de turns com limites por effort
2026-06-22 — M4: Effort level integrado ao estimate.md
2026-06-22 — M5: Padrão de mensagens de sessão (Init/Turn/Result/Bloqueio)
2026-06-22 — M6: Protocolo de subagents para contexto leve
```
