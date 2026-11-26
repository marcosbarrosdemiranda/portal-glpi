# Spec: Reconhecimento Facial na resposta da Rotina Diária (v2)

> **Status:** APROVADA · 2026-11-25
> 🎯 CLASSIFY: FEAT · Effort: high · Modo: FULL
> v2: opção B (histórico ontem/hoje) aprovada + última face detectada.

## 1. Objetivo
Caixa **📷 Reconhecimento Facial** na janela "Responder Chamado" do chamado
recorrente **"Backup, Relatórios e Banco de Dados - Rotina Diária"**, logo
**abaixo** de "💾 Resumo de Backups" (faixa cheia `col-12`, mesmo padrão de
Firewall/Antenas — ajustado durante a execução, 2026-11-26, a pedido do
usuário ao ver o layout real), com três conteúdos:

1. **Status atual** (último diagnóstico do webhook) + checagens.
2. **Histórico ontem + hoje** de anormalidades (opção B, escolhida).
3. **Última face detectada** — miniatura + quando/quem, pra conferir se a
   captura está funcionando.

## 2. Como o padrão funciona hoje (confirmado no código)
Não é cron: é `fetch` on-demand em `abrirModalResposta()` (`agenda/index.php`),
condicionado a `titulo.includes('Backup, Relatórios e Banco de Dados')`
(variável `mostrarBackup`), que hoje alimenta 3 caixas: `resp-backup`,
`resp-solides`, `resp-db-status`. No envio, `enviarResposta()` empurra o texto
de cada caixa visível no array `blocos`.

## 3. Fonte dos dados

### 3.1. Status atual — já existe
`reconhecimento_ultimo_diagnostico($pdo)` → `portal_reconhecimento_saude`
(alimentada por `webhook_reconhecimento.php`).

### 3.2. Histórico ontem/hoje (opção B) — já existe, só consultar
`portal_alertas_historico` já grava as transições `nova`/`resolvida` dos tipos
`reconhecimento_checagem` e `reconhecimento_sem_contato` (via
`gat_alertas_tipo()`). Nova função em `reconhecimento_lib.php`:

```php
reconhecimento_relatorio_dias(PDO $pdo, int $agoraTs): array
```
Agrupa por dia (ontem, hoje), parea `nova`→`resolvida` pela `chave` e devolve
texto no mesmo formato visual do Sólides:

```
Reconhecimento Facial: ❌ 2 anormalidade(s), 1 em aberto
Ontem (20/11): sem anormalidades
Hoje (21/11): 2 anormalidade(s), 1 ainda em aberto
  ✅ Evolution API às 03:12 — resolvido às 03:20
  ❌ Defense IA às 08:05 — AINDA EM ABERTO
```

### 3.3. Última face detectada — **precisa de mudança no coletor**
O coletor (`face_events.py`, projeto `Reconhecimento-facial`) já salva foto +
metadados de cada reconhecimento em `events/AAAA-MM-DD/` (`events.jsonl` com
`timestamp`, `name`, `image`, `face_crop`, `attrs`). Mas **nada disso chega no
portal hoje** — o webhook só manda o bloco de saúde
(`health.build_payload()`: `origem/evento/status/resumo/gerado_em/host/checagens`).

**Desenho escolhido: carona no webhook que já existe** (não exige porta nova,
funciona atrás de NAT, sem login/credencial a mais). O coletor passa a incluir
um bloco **opcional** no mesmo POST:

```json
"ultima_face": {
  "quando": "2026-11-21T07:58:12",
  "nome": "Fulano da Silva",
  "canal": "Entrada Loja 03",
  "thumb_b64": "<JPEG do face_crop, ~200px, base64 sem prefixo>"
}
```

Regras no portal:
- Campo **opcional**: se o coletor ainda não mandar, a caixa mostra
  *"coletor ainda não envia a última face — atualizar o coletor"* e nada quebra.
- Guardamos **só a última** (mesmo `ON DUPLICATE KEY UPDATE` da linha de saúde),
  então não há crescimento de tabela nem histórico de imagens.
- `thumb_b64` truncado acima de 400 KB (descarta e registra aviso) pra não
  inchar o payload.

> ⚠️ **Privacidade:** imagem de rosto é dado biométrico. Por isso: só a última,
> sem histórico, visível apenas dentro do portal autenticado. Se preferir
> **sem imagem** (só `nome` + `quando`), é só dizer — é um campo a menos.

## 4. Mudanças por arquivo

| Arquivo | Mudança |
|---|---|
| `reconhecimento_lib.php` | +colunas `ultima_face_*` na tabela (migração leve, padrão do projeto); `reconhecimento_registrar_payload()` grava o bloco opcional; nova `reconhecimento_relatorio_dias()` + `reconhecimento_relatorio_texto()` |
| `reconhecimento_resumo_ajax.php` (novo) | Espelho de `solides_resumo_ajax.php`: devolve `{ok, texto, face:{quando,nome,canal,thumb_b64}}` |
| `agenda/index.php` | 3 edições cirúrgicas: div `resp-reconhecimento` entre Sólides e Banco de Dados (com `<img>` da miniatura); bloco de `fetch` no `abrirModalResposta()`; push do texto em `enviarResposta()` |
| `reconhecimento_config.php` | Mostra a última face recebida também na tela de config (conferência rápida sem abrir chamado) |

**Fora deste repo (recado pro coletor):** incluir `ultima_face` em
`health.build_payload()`, lendo o último registro de `events.jsonl` do dia e o
`face_crop` redimensionado. O texto do contrato acima vai pronto pra você colar
na sessão do projeto `Reconhecimento-facial`.

## 5. Critérios de aceite
1. Caixa 📷 aparece **só** na rotina "Backup, Relatórios e Banco de Dados",
   logo abaixo da caixa do Ponto.
2. Mostra status atual + checagens + histórico ontem/hoje.
3. Mostra a miniatura da última face com data/hora e nome (ou o aviso de
   "coletor ainda não envia", se o campo não vier).
4. O texto (sem a imagem) entra na resposta enviada ao GLPI.
5. Falha de rede/endpoint não quebra o modal.
6. Payload sem `ultima_face` continua sendo aceito normalmente pelo webhook.
