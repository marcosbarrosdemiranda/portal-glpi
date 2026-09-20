# Plano: The Dude - Mover notificações do local pro Docker

## Problema Atual

1. **Notificações "Execute locally"** rodam na estação de trabalho (PC do usuário)
2. Isso **trava o PC** com frequência
3. Causa **falsos positivos** porque o PC trava e perde notificações
4. O The Dude Docker (192.168.1.246) deveria enviar as notificações diretamente

## Arquitetura Atual

```
Estação (PC do usuário)
  └── The Dude Desktop App
        └── "Execute locally" → curl → webhook.php (GLPI)
        └── trava o PC ❌

Servidor 192.168.1.246 (Docker)
  └── The Dude Docker (4.0beta3)
        └── Notificações configuradas mas não estão sendo usadas
        └── Deveria ser a fonte das notificações ✅
```

## Arquitetura Desejada

```
Servidor 192.168.1.246 (Docker)
  └── The Dude Docker (4.0beta3)
        └── Notifications → curl → webhook.php (GLPI) ✅
        └── Sem dependência da estação ❌

Estação (PC do usuário)
  └── The Dude Desktop App (somente visualização)
        └── Sem notificações locais ✅
```

## Plano de Implementação

### Fase 1: Mover Notificações pro Docker (URGENTE)

#### 1.1 Acessar The Dude Docker via API
- O The Dude 4.0beta3 tem API REST interna
- Porta 2210 já está mapeada no Docker
- Endpoint: `http://192.168.1.246:2210/api/`

#### 1.2 Exportar configuração atual
- Listar todas as Notifications ativas
- Identificar quais usam "Execute locally"
- Exportar configuração completa

#### 1.3 Reconfigurar notificações
- Mudar todas as Notifications de "Execute locally" para "Execute on server"
- URL do webhook: `https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php`
- Parameters: `token=3fb5860faab2b6be9fd43cec8f766ced&tipo=device&chave=[Device.Name]&nome=[Device.Name]&addr=[Device.FirstAddress]&categoria=[Category.Name]&estado=[Device.Status]`

#### 1.4 Testar
- Forçar mudança de estado de um device
- Verificar se webhook recebe notificação
- Confirmar que PC não trava mais

### Fase 2: Melhorias no Portal (MELHORIA)

#### 2.1 Verificação periódica independente
- Portal já faz ping direto a cada 30min (down) e 3h (up)
- Melhorar para verificar TODOS os devices periodicamente
- Configurar intervalos por categoria

#### 2.2 Dashboard de status
- Criar página que mostra status atual de todos os devices
- Última atualização de cada um
- Histórico de mudanças

#### 2.3 Alertas de heartbeat
- Se The Dude não enviar notificação há X minutos, alertar
- Detectar se webhook está funcionando

### Fase 3: Documentação (IMPORTANTE)

#### 3.1 Documentar configuração
- Como configurar notificações no The Dude Docker
- Como adicionar novos devices
- Como testar webhooks

#### 3.2 Runbook de troubleshooting
- O fazer se notificações pararem
- Como verificar se Docker está rodando
- Como acessar logs do The Dude

## Comandos Úteis

### Acessar The Dude Docker
```bash
ssh gmais@192.168.1.246
docker exec -it dude bash
```

### Verificar status do container
```bash
docker ps | grep dude
docker logs dude --tail 50
```

### Testar webhook manualmente
```bash
curl -k "https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php?token=3fb5860faab2b6be9fd43cec8f766ced&tipo=device&chave=teste&nome=Teste&addr=192.168.1.1&categoria=PDVs&estado=down"
```

### Verificar logs do webhook
```bash
ssh glpi-server
docker exec glpi-web tail -f /var/log/apache2/error.log | grep the_dude_webhook
```

## Riscos e Mitigações

| Risco | Mitigação |
|-------|-----------|
| The Dude Docker não tem curl | Instalar: `docker exec dude apt-get install -y curl` |
| Webhook URL inacessível do Docker | Testar conectividade antes de migrar |
| Notificações antigas continuam rodando | Parar o The Dude Desktop App na estação |
| Perda de dados durante migração | Backup da config antes de alterar |

## Próximos Passos Imediatos

1. **Acessar The Dude Docker** e verificar notificações ativas
2. **Testar webhook** do Docker pro GLPI
3. **Migrar notificações** de "Execute locally" pra "Execute on server"
4. **Parar The Dude Desktop App** na estação
5. **Monitorar** por 24h pra confirmar que não trava mais

## Critérios de Sucesso

- [ ] PC do usuário não trava mais
- [ ] Notificações chegam no GLPI corretamente
- [ ] falsos positivos reduzidos em 90%+
- [ ] Todos os devices com notificações ativas no Docker
- [ ] Documentação completa da configuração

---

**Responsável:** Marcos / TI
**Prazo estimado:** 1-2 dias
**Dependências:** Acesso SSH ao servidor 192.168.1.246
