# Deploy em Produção — Passo a Passo

Runbook de acesso ao servidor de produção e envio de atualizações de código do portal-glpi.

## Servidor

| Item | Valor |
|---|---|
| Host | `192.168.1.198` (hostname `Backup-Arquifunc`) |
| Alias SSH | `ssh glpi-server` (já configurado em `~/.ssh/config`) |
| Chave | `~/.ssh/id_ed25519_glpi_server` (label `claude-glpi-server-deploy`) |
| Usuário remoto | `externo` (grupo Administradores) |
| Stack Docker | `C:\docker\glpi-portal\` (compose + código em `C:\docker\glpi-portal\glpi2\`) |
| Código do portal | `C:\docker\glpi-portal\glpi2\portal-glpi\` no host = `/var/www/html/glpi2/portal-glpi/` no container `glpi-web` |
| URL pública | `https://ti.grupogmais.com:7412/glpi2/` |

**⚠️ Regra de ouro: NUNCA `git pull` dentro do container `glpi-web`.** O `.git` de lá está travado numa branch antiga e cheio de arquivos não commitados — um pull faria merge quebrado. Deploy é sempre via SCP.

## 1. Antes de mandar pra produção — conferir tudo que o commit tocou

Não confiar de memória em quais arquivos mudaram. Rodar:

```bash
git show --stat <commit>
# ou, pra um intervalo/branch inteira:
git diff --stat main..HEAD
```

Isso evita esquecer arquivo de teste ou arquivo secundário (já causou 26 falsos-positivos em produção por runbook incompleto).

## 2. Deploy de arquivo alterado (caso comum)

SCP direto pro caminho do host (bind-mount do container, não precisa reiniciar nada):

```bash
scp "C:\claude code\portal-glpi\<arquivo>" glpi-server:"C:/docker/glpi-portal/glpi2/portal-glpi/<arquivo>"
```

Repetir pra cada arquivo alterado. Depois, recarregar a página no navegador — PHP é interpretado, não precisa rebuild/restart de container.

## 3. Deploy mais cuidadoso — checar sintaxe antes de sobrescrever

Quando o arquivo é sensível (ex: lib carregada em várias páginas), subir como `.new`, validar, só então substituir:

```bash
# 1. Sobe como .new
scp "<local>" glpi-server:"C:/docker/glpi-portal/glpi2/portal-glpi/<arquivo>.new"

# 2. Valida sintaxe PHP dentro do container
ssh glpi-server "powershell -Command \"docker exec glpi-web php -l /var/www/html/glpi2/portal-glpi/<arquivo>.new\""

# 3. Só se deu OK, substitui o original
ssh glpi-server "powershell -Command \"Move-Item -Force 'C:/docker/glpi-portal/glpi2/portal-glpi/<arquivo>.new' 'C:/docker/glpi-portal/glpi2/portal-glpi/<arquivo>'\""
```

## 4. Rodar script PHP no servidor (debug, bootstrap, migração)

Via `docker exec` no container `glpi-web`, sem precisar abrir navegador:

```bash
ssh glpi-server "powershell -Command \"docker exec glpi-web php /var/www/html/glpi2/portal-glpi/<arquivo>.php\""
```

## 5. Deploy de tabela/worker novo — semear antes

Se o gatilho novo depende de uma tabela de estado (ex: baseline de sync), rodar o script de seed **antes** do deploy do worker ficar live, ou parar o worker e limpar o estado (`wpp_baseline_ok`) antes de reiniciar. Deploy de worker com tabela vazia + worker já rodando pode disparar um backfill/blast indesejado.

## 6. Conferir se o arquivo em produção bate com o local

```bash
ssh glpi-server "powershell -Command \"Select-String -Path '<caminho no host>' -Pattern '<trecho característico>'\""
```

## Checklist rápido de deploy

1. `git show --stat` no(s) commit(s) → listar TODOS os arquivos tocados (inclusive testes)
2. Se tem tipo de alerta/notificação novo no catálogo: inserir com `notif_whatsapp=0` antes de testar
3. `scp` de cada arquivo pro host de produção
4. Se for lib sensível: usar o fluxo `.new` + `php -l` + `Move-Item`
5. Recarregar página no navegador (ou `docker exec ... php` pro script de debug)
6. Rodar suíte de testes completa contra produção quando o deploy alegar "0 falhas esperadas" — não assumir que vai passar

## Referências

- [[deploy-portal-glpi-pattern]] — memória de por que SCP e não git pull
- [[reference_ssh_servidor_glpi]] — detalhes do acesso SSH e discos do servidor
- [[feedback-deploy-worker-semear-antes]] — cuidado ao subir worker com tabela vazia
- [[feedback_mutar-whatsapp-antes-de-testar]] — mutar WhatsApp antes de testar tipo novo
- [[feedback_runbook-sync-incompleto]] — por que conferir `git show --stat` antes de listar arquivos
