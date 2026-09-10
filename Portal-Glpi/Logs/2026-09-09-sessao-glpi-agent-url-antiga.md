# Log de Sessão — 09/09/2026

## Resumo
GLPI Agent no PC CFTV-LJ003 não enviava inventário no ciclo automático. Causa: registro ainda com a URL do **servidor antigo** (`http://192.168.1.198/glpi2/`, porta 80 do XAMPP, que morreu). A solução de frota **já existe no repo** desde 06/09 (`util/Instaladores - AD/`) — só não tinha chegado nesse PC.

Relacionado: [[2026-06-06-sessao-inventario-gateway]]

---

## DEBUG — causa raiz

Log do agente:
```
500 Can't connect to 192.168.1.198:80 (recusou ativamente)
No supported answer from server at http://192.168.1.198/glpi2/
```

`HKLM\SOFTWARE\GLPI-Agent\server = http://192.168.1.198/glpi2/`. Servidor atual só responde em `192.168.1.198:7412` (container `glpi-nginx`); porta 80 removida do nginx no commit `b149b18` (06/09). "Manual funcionava" = testado com `--server` corrigido.

## Solução já existente no repo (não sabia no início da sessão)

| Arquivo | Uso |
|---|---|
| `util/Instaladores - AD/run-startup.bat` + `deploy-glpi-agent.ps1` + MSI | GPO Startup Script — instala/atualiza + reescreve `server`+`no-ssl-check` no registro e no `agent.cfg` em TODO boot. Idempotente. |
| `util/Instaladores/Repontar agente.bat` / `.ps1` | Repoint manual de 1 PC já instalado, sem reinstalar. Rodar como admin. |
| `util/Instaladores - AD/PASSO-A-PASSO.txt` / `LEIA-ME.txt` | Runbook do GPO (criar GPO, NETLOGON, MS16-072, máquina nova) |

URL padrão da frota: **`https://192.168.1.198:7412/glpi2/` + `no-ssl-check=1`**.
Motivo de usar IP e não hostname: PCs de loja **não resolvem** o DNS interno (`ti.grupogmais.com`); o cert Let's Encrypt é pro hostname, não pro IP → `no-ssl-check` ligado. (Nesta sessão o CFTV-LJ003 resolvia o DNS, mas o padrão é IP pra funcionar em toda a rede.)

## Fix aplicado nesta sessão (CFTV-LJ003)

Setei registro na mão pra `https://ti.grupogmais.com:7412/glpi2/` + `no-ssl-check=0` → funcionou (inventário subiu 19:13). **Pendente:** realinhar pro padrão da frota rodando `util/Instaladores/Repontar agente.bat` nele.

## Pendente / dúvida em aberto

- A GPO "GLPI Agent" está criada e vinculada? O PASSO-A-PASSO.txt está com os `[ ]` sem marcar. Se CFTV-LJ003 estava na URL velha em 09/09, o GPO não chegou nele (não criado, fora de escopo, ou sem reboot pós-deploy).
- Ação: conferir no `gpmc.msc` se a GPO existe; se sim, garantir NETLOGON com MSI + escopo cobrindo as OUs de PC. Se não, seguir o PASSO-A-PASSO.

## Aprendizado
Migração de servidor (IP/porta/protocolo) não reaponta agentes que gravam o endpoint na config de instalação — falha **silenciosa** (serviço fica `Running`). Aqui o time já tinha resolvido; a lição é operacional: **verificar cobertura do GPO**, não só existir o script. Ver [[project_migracao_disco_c_glpi]].
