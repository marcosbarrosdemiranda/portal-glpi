# Backup automático de Código + Banco de Dados do GLPI

**Data:** 2026-10-04 · **Correção:** 2026-10-04 (mesma sessão seguinte)
**Status:** 🔶 BACKLOG — escopo reduzido (ver correção abaixo); integração com
Central de Alertas ainda não feita

## ⚠️ Correção importante (2026-10-04)

A premissa deste documento estava **errada**. O backup **já existe e já roda
em produção** desde a migração pro Docker (18/07/2026) — ver
`Portal-Glpi/Arquitetura/PLANO_MIGRACAO_DOCKER.md` linhas 129-130 e
`Portal-Glpi/Logs/2026-07-18-sessao-docker-migracao.md`. Confirmado via SSH
nesta correção: as 2 Tarefas Agendadas rodaram hoje com sucesso, log limpo,
6 dumps de ~220MB no disco.

- `docker/scripts/backup-db.ps1` — dump do `glpi2` (mysqldump via `docker
  exec`), comprimido em stream (GZipStream), retenção 5 dias, salvo em
  `E:\Backup Sistemas\Backup-glpi-portal\Docker-DB\`. Tarefa Agendada
  `\Backup\Glpi-Docker-DB`, diária 05:00.
- `docker/scripts/backup-files.ps1` — espelho incremental (`robocopy /MIR`)
  de `files/` (anexos) pra `Docker-Files\`. Tarefa Agendada
  `\Backup\Glpi-Docker-Files`, diária 05:30.

**O que de fato falta** (escopo real deste backlog a partir de agora): essas
duas tarefas rodam "no escuro" — nada reporta pro portal. Se uma falhar
silenciosamente (disco cheio, container fora do ar no horário, etc.),
ninguém fica sabendo até precisar restaurar. O portal já tem o mecanismo
genérico pronto pra isso (usado hoje só pelos 2 servidores do back-gmais):
`backup_lib.php` / `webhook_backup.php` / `backup_maquinas.php` — ver
[[2026-09-12-central-alertas-backup-gmais]]. Falta só: cadastrar esta
máquina (host GLPI local) em `backup_maquinas.php` e os dois scripts
chamarem o webhook ao final do job, igual o back-gmais já faz.

Config de frequência/horário/retenção **não entra mais em escopo** — já é
gerenciada pela Tarefa Agendada do Windows, não precisa de tela nova em
`manutencao.php` pra isso.

## Decisões confirmadas na sessão de correção (2026-10-04)

1. **2 políticas separadas**, não 1 combinada: "Banco de Dados" (de
   `backup-db.ps1`) e "Arquivos" (de `backup-files.ps1`) reportam
   independente — se só um falhar, não mascara o outro. Mesmo padrão dos
   servidores back-gmais.
2. **Nome da máquina a cadastrar:** `GLPI Produção (local)`.
3. **Quem cadastra a máquina:** o usuário mesmo, manualmente, via
   `backup_maquinas.php` (tela já existente) — não eu. Ele vai gerar o
   token/URL do webhook e me passar pra eu usar nos scripts.
4. **Card novo em `manutencao.php`:** status read-only (sem botão de ação —
   a Tarefa Agendada já cobre a cadência), lendo `portal_backup_execucoes`
   filtrado pela máquina `GLPI Produção (local)`, mostrando por política
   (Banco de Dados / Arquivos): status, horário do último recebido, tamanho
   se disponível. Mesma fonte de dados que alimenta a Central de Alertas —
   zero tabela nova.
5. **Também aparece automaticamente na "Rotina Diária"**: o usuário apontou
   que isso "vai colocar no chamado também" — já é automático, sem código
   novo: `backup_resumo_dia()` / `backup_resumo_texto()` (em `backup_lib.php`,
   usados por `backup_resumo_ajax.php` no chamado recorrente "Backup,
   Relatórios e Banco de Dados - Rotina Diária" da Agenda) já leem TODA a
   tabela `portal_backup_execucoes` sem filtrar por máquina — assim que
   "GLPI Produção (local)" começar a reportar, as 2 políticas novas entram
   nesse resumo diário junto com Arquifunc/Zukkin, sem precisar tocar nesse
   código.
6. **`alertas_tipos.php` não muda** — `backup_erro` e `backup_silencio` já
   são genéricos por máquina/política, a máquina nova já vai cair no
   catálogo que já existe.

## ⚠️ Risco a controlar ANTES de testar o webhook

Confirmado no banco (2026-10-04): `backup_erro` e `backup_silencio` já estão
**`ativo=1` E `notif_whatsapp=1`** (usados em produção pelos servidores
back-gmais). Isso significa que **qualquer teste que mande `status: error`**
pro webhook depois que a máquina nova for cadastrada **dispara WhatsApp real**
pro grupo de Alertas — mesma armadilha de
[[feedback_mutar-whatsapp-antes-de-testar]] ("já vazou 2x" em outra sessão).
Antes de qualquer teste com payload de erro: desativar `notif_whatsapp` pra
esses 2 tipos em `alertas_config.php` (ou testar só com `status: success`) e
reativar depois.

## Próximo passo imediato

Aguardando o usuário cadastrar a máquina `GLPI Produção (local)` em
`backup_maquinas.php` e passar a URL do webhook (com token). Com isso em
mãos, os 2 arquivos a editar são:
- `docker/scripts/backup-db.ps1` — ao final (sucesso ou erro), `Invoke-RestMethod`
  POST pro webhook com `{"message": "Política: Banco de Dados\nStatus:
  success|error\n..."}`. Falha de rede no POST não pode derrubar o backup em
  si (try/catch isolado, só loga).
- `docker/scripts/backup-files.ps1` — mesma ideia, política "Arquivos",
  status pelo exit code do `robocopy` (`< 8` = success).

Depois: rodar manualmente (ou esperar a próxima janela 05:00/05:30), conferir
`ultimo_contato` em `backup_maquinas.php` e montar o card novo em
`manutencao.php`.

## Contexto original (histórico — não reflete mais a realidade, ver correção acima)

Hoje o stack GLPI (código em `C:\docker\glpi-portal\glpi2` + banco `glpi2` no
container `glpi-db`) **não tem nenhuma rotina de backup**. Isso ficou exposto
durante a sessão de limpeza do `glpi_logs` (2026-10-04) — se algo tivesse dado
errado de forma irreversível, não haveria como restaurar.

O usuário já tem uma peça do quebra-cabeça pronta: o **back-gmais** (app Go,
repo próprio), que roda em 2 servidores de backup dedicados e já sabe rodar
políticas, girar retenção de longo prazo, subir pra Google Drive, e notificar
a Central de Alertas do portal via webhook (`backup_erro` / `backup_silencio`
— ver [[2026-09-12-central-alertas-backup-gmais.md]]). Esse app já cobre
*outros* servidores — falta o GLPI entrar na lista de fontes.

## Pedido do usuário (essência)

- Backup automático, **1x/dia ou a cada X dias**, horário configurável.
- Reter localmente um número configurável de dias (sugestão inicial do
  usuário: **5 dias**) — depois disso o back-gmais entra, pega os arquivos,
  cuida da nuvem (Google Drive) e da retenção de longo prazo. **Esse app não
  muda** — zero trabalho duplicado, o portal só precisa gerar e manter os
  arquivos localmente por essa janela.
- Escopo pedido: **código** do GLPI + **banco de dados** do GLPI.
- Arquivos de imagem/anexo (`files/PNG`, uploads de chamados etc.):
  **explicitamente fora de escopo por agora** — usuário disse não saber ainda
  como tratar isso (volume grande, provavelmente precisa de estratégia
  incremental própria) e pediu pra estudar em separado.
- Configuração (frequência, horário, dias de retenção) deve ficar numa tela
  de Configurações do portal — reaproveitar a área de Ferramentas de
  Manutenção (`manutencao.php`), perto do card "Banco de Dados GLPI" que já
  existe (mesmo lugar onde a sessão de limpeza do `glpi_logs` mexeu).

## Decisões já tomadas

1. Escopo inicial = dump do MySQL (banco `glpi2`) + backup do código.
2. Arquivos de anexo/imagem ficam fora do escopo inicial — item de estudo
   futuro, spec própria depois.
3. Retenção **local** (janela curta, dias) é responsabilidade do portal;
   retenção de **longo prazo + upload pra nuvem** é responsabilidade do
   back-gmais — sem sobreposição.
4. UI de configuração entra em `manutencao.php`, na seção que já existe pra
   ferramentas de banco de dados.

## Perguntas abertas (bloqueiam o detalhamento / SPEC)

1. **Integração com o back-gmais:** qual pasta/convenção uma policy nova no
   `config.yaml` de um dos 2 servidores de backup esperaria encontrar neste
   host? (Precisa olhar como as policies existentes apontam pra outras
   fontes, pra manter o mesmo padrão.)
2. **Escopo exato de "código":** `/var/www/html/glpi2` inteiro (inclui
   `files/`, que tem os anexos) ou só a parte de aplicação **sem** `files/`?
   Precisa decidir separado da questão dos arquivos de imagem.
3. **Onde rodar o dump:** dentro do container `glpi-web` (já tem acesso ao
   filesystem do GLPI, mesmo padrão usado hoje em `manutencao.php`) ou um
   worker dedicado novo (`backup-worker`, no padrão dos outros workers já
   existentes em `docker/docker-compose.yml`)?
4. **Espaço em disco:** quanto sobra pra reter N dias de dump + código?
   (O banco caiu bastante com a purga do `glpi_logs` em 2026-10-04 — revisar
   tamanho final antes de estimar.)
5. **Credenciais do dump:** usuário/senha dedicados pro `mysqldump` (seguir o
   padrão já usado em `DB_CENTRAL_USER`/`DB_CENTRAL_PASSWORD`, ver
   [[project_monitor_db_central]]) e se o dump sai comprimido (`gzip`) direto.
6. **Arquivos de imagem/anexo:** estratégia própria, fora deste escopo —
   precisa de spec dedicada depois (provavelmente incremental, dado o volume).

## Próximo passo

Rodar `/spec` neste tema numa sessão futura — primeiro respondendo as
perguntas acima (principalmente #1, já que define o formato de saída que o
resto do design depende) — antes de abrir PLAN/EXECUTE.
