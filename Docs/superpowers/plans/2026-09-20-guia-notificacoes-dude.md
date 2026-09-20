# Guia: Mover Notificações do The Dude - Local → Docker

## ✅ Backup Realizado
- Arquivo: `/dude/data/dude.db.bak` no container Docker
- Data: 20/09/2026 15:46

---

## 📋 Passo a Passo para Mudar Notificações

### 1. Acessar The Dude Docker
```
Abra o The Dude Desktop App no seu PC
Conecte em: 192.168.1.246:2210
```

### 2. Listar Notificações Ativas
```
Vá em: Tools → Notifications
```
Anote todas as notificações que estão com **"Execute locally"** marcada.

### 3. Mudar cada Notificação
Para CADA notificação que usa "Execute locally":

1. **Clique duas vezes** na notificação pra editar
2. **Desmarque** "Execute locally"
3. **Marque** "Execute on server"
4. Na URL, coloque:
```
https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php
```
5. Nos Parameters, coloque:
```
token=3fb5860faab2b6be9fd43cec8f766ced&tipo=device&chave=[Device.Name]&nome=[Device.Name]&addr=[Device.FirstAddress]&categoria=[Category.Name]&estado=[Device.Status]
```
6. **Salve**

### 4. Repetir para TODAS as notificações
- Notificações de **device down**
- Notificações de **device up**
- Notificações de **link down/up**
- Notificações de **service down/up**

### 5. Testar
1. **Force** a mudança de estado de um device (desligue e ligue)
2. **Verifique** se o webhook recebeu a notificação
3. **Confirme** que o PC NÃO trava mais

### 6. Parar The Dude Desktop App
Depois de confirmar que tudo funciona:
- **Feche** o The Dude Desktop App no seu PC
- As notificações agora saem do **Docker** automaticamente

---

## 🔧 Parâmetros Disponíveis no The Dude

| Parâmetro | Descrição |
|-----------|-----------|
| `[Device.Name]` | Nome do device |
| `[Device.Address]` | IP do device |
| `[Device.FirstAddress]` | Primeiro IP |
| `[Device.Status]` | Status (up/down) |
| `[Category.Name]` | Nome da categoria |
| `[Map.Name]` | Nome do mapa |
| `[Service.Name]` | Nome do serviço |
| `[Service.Status]` | Status do serviço |

---

## ⚠️ Importante

1. **NÃO delete** as notificações antigas — apenas edite
2. **Teste UMA** notificação primeiro antes de mudar todas
3. **Mantenha** o The Dude Desktop App aberto durante os testes
4. **Só feche** o app depois de confirmar que tudo funciona

---

## 🐛 Se Não Funcionar

### Problema: Webhook não recebe notificação
1. Verifique se a URL está correta
2. Teste manualmente:
```bash
curl -k "https://ti.grupogmais.com:7412/glpi2/portal-glpi/the_dude_webhook.php?token=3fb5860faab2b6be9fd43cec8f766ced&tipo=device&chave=teste&nome=Teste&addr=192.168.1.1&categoria=PDVs&estado=down"
```

### Problema: PC ainda trava
1. Verifique se todas as notificações foram mudadas
2. Reinicie o The Dude Desktop App
3. Verifique se não há outras aplicações usando recursos

### Problema: Docker não envia notificações
1. Verifique se o container está rodando:
```bash
docker ps | grep dude
```
2. Verifique os logs:
```bash
docker logs dude --tail 50
```

---

## 📞 Contato

Em caso de dúvida, consulte o documentação do portal:
- `Docs/superpowers/plans/2026-09-20-dude-notificacoes-docker.md`
- `dude_config.php` (configuração no portal)
