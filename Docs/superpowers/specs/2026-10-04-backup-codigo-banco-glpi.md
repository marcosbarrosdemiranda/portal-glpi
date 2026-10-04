# Backup automático de Código + Banco de Dados do GLPI

**Data:** 2026-10-04
**Status:** 🔶 BACKLOG — URGENTE (pendência levantada, detalhamento e implementação ainda não iniciados)

## Contexto

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
