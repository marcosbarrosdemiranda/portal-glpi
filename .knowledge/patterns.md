# Patterns — portal-glpi

> Lições de `CLAUDE.md` que já viraram prática padrão (não precisam mais de
> repetição na lista de "LIÇÕES APRENDIDAS", porque já estão internalizadas
> no fluxo de trabalho do dia a dia).

## 1. Produção pode ficar vários commits atrás do git HEAD sem ninguém notar
**Origem:** CLAUDE.md lição #4, graduada em 2026-10-19.

Deploy é scp arquivo-a-arquivo, nunca `git pull` no container — então
produção e o HEAD local divergem silenciosamente até alguém notar um
comportamento estranho. `php -l` não pega isso (sintaxe válida, função só
falta em runtime). **Prática padrão:** antes de caçar bug de lógica num
500/comportamento estranho em produção, baixar a cópia de produção dos
arquivos envolvidos (inclusive os que o suspeito `require`/chama) e
`diff` contra o HEAD local.

## 2. Quoting aninhado quebra em `ssh host "docker exec container <cmd>"`
**Origem:** CLAUDE.md lição #7, graduada em 2026-10-19.

Comando com aspas aninhadas (`grep -n 'x' arquivo`, `php -r "..."`) via
`ssh` → `docker exec` → shell Linux perde o quoting no caminho.
**Prática padrão:** pra qualquer inspeção/mutação de banco de produção
via terminal, escrever um `.php` standalone local → `scp` pro container →
rodar via `docker exec glpi-web php /caminho/script.php` → apagar local e
remoto logo depois de usar. Usado consistentemente em toda investigação
desta sessão (achados do Pré-chamado, backfills, checagem de schema).
