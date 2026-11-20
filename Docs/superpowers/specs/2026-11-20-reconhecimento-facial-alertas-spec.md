# Especificação: Central de Alertas — Reconhecimento Facial

> **Status:** AGUARDANDO APROVAÇÃO (SPEC GATE)
> **Projeto:** Portal GLPI — Central de Alertas
> **Módulo:** Monitoramento de Reconhecimento Facial (`FaceEventsIntelbras`)

---

## 1. Visão Geral
Esta spec detalha como o sistema de **Reconhecimento Facial** se integra à **Central de Alertas** do Portal GLPI, definindo as regras de negócio, os tipos de alerta, a parametrização e o comportamento de notificação (painel + WhatsApp + histórico).

---

## 2. Tipos de Alerta no Catálogo

### 2.1. `reconhecimento_checagem` — Reconhecimento Facial com problema
- **Nome exibido:** Reconhecimento Facial com problema
- **Descrição:** Checagem de saúde do Reconhecimento Facial fora do normal (módulos como *Defense*, *Evolution*, *WhatsApp*, *Envio*). Alimentado pelo webhook periódico do coletor.
- **Parâmetros configuráveis (`params`):**
  - `minutos`: Ignorar diagnóstico mais velho que (padrão: `15` min, mín: `6`, máx: `240`).
- **Lógica de detecção (`alerta_check_reconhecimento_checagem`):**
  1. Busca o último diagnóstico gravado na tabela `portal_reconhecimento_saude` para a origem `'reconhecimento'`.
  2. Se o diagnóstico for mais antigo que o limite de minutos configurado, descarta para evitar falsos positivos (quem avisa é o tipo *sem contato*).
  3. Percorre o array `checagens[]` do payload JSON.
  4. Para cada checagem com `status !== 'ok'` e `id !== ''`, gera uma ocorrência estável com chave `reconhecimento:<id>`.
- **Renderização (`alerta_render_reconhecimento`):**
  - Tabela listando o nome da checagem, detalhe do erro/alerta e botão de dispensar/resolver manualmente.
- **Ícone:** `bi-camera-video`
- **Cor:** `danger` (vermelho)

### 2.2. `reconhecimento_sem_contato` — Reconhecimento Facial sem contato
- **Nome exibido:** Reconhecimento Facial sem contato
- **Descrição:** O coletor de Reconhecimento Facial parou de enviar o diagnóstico de saúde — indica coletor desligado, serviço parado, queda de rede ou webhook inativo.
- **Parâmetros configuráveis (`params`):**
  - `minutos`: Minutos sem contato (padrão: `15` min, mín: `6`, máx: `240`).
- **Lógica de detecção (`alerta_check_reconhecimento_sem_contato`):**
  1. Busca o último diagnóstico em `portal_reconhecimento_saude`.
  2. Se nunca recebeu nenhum diagnóstico, ou se o tempo decorrido desde `recebido_em` for superior ao limite de minutos configurado, gera a ocorrência com chave fixa `reconhecimento_sem_contato`.
- **Renderização (`alerta_render_reconhecimento`):**
  - Tabela listando a indisponibilidade, tempo exato sem contato e botão de dispensar.
- **Ícone:** `bi-wifi-off`
- **Cor:** `warning` (amarelo)

---

## 3. Comportamento e Notificações (Motor de Alertas)
- **WhatsApp:** Integrado com o worker (`wpp/worker.php`). Quando uma ocorrência nasce (`nova`), dispara notificação `🔔` no grupo de Alertas configurado (respeitando o campo `notif_whatsapp` do catálogo). Quando resolvida, dispara `✅`.
- **Lembrete (`lembrete_min`):** Se configurado (ex: a cada 60 min), reenvia lembrete `⏰` enquanto o problema persistir.
- **Histórico:** Todas as transições (nova / resolvida) são gravadas em `portal_alertas_historico` para auditoria e relatórios.

---

## 4. Próximos Passos (Após Aprovação)
1. Confirmação do usuário (**SPEC GATE**).
2. Revisão final das regras no código.
3. Deploy e validação em produção.
