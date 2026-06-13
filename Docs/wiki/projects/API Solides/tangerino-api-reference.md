# API Sólides Integrations (Tangerino) — Referência Completa

> Fonte: https://docs.tangerino.com.br + Swagger público das APIs (junho/2026).
> API de ponto eletrônico da Sólides DP (ex-Tangerino): colaboradores, batidas, ajustes, férias e espelho de ponto.

## 1. Arquitetura — 3 módulos / base URLs

| Módulo | Base URL | Responsabilidade |
|---|---|---|
| **Employer API** | `https://employer.tangerino.com.br` | Cadastros: cargo, local de trabalho, escala, colaborador, gestor, motivos e lançamentos de ajuste |
| **Punch API** | `https://api.tangerino.com.br/api/punch` | Batidas de ponto (consulta, registro, AFD, justificativas, observações) |
| **Report API** | `https://api.tangerino.com.br/api/report` | Emissão de folha/espelho de ponto |

Swagger UI:
- `https://employer.tangerino.com.br/swagger-ui.html`
- `https://api.tangerino.com.br/api/punch/swagger-ui.html`
- `https://api.tangerino.com.br/api/report/swagger-ui.html`

## 2. Autenticação

- Token de integração **solicitado ao suporte da Sólides DP**; gerenciado no portal web em *Configurações → Integrações* (menu antigo) ou *Employer → Integrações* (menu novo).
- Enviar em **toda** requisição: `Authorization: Basic <token>` (token JWT usado no esquema Basic).
- Teste de conectividade: `GET https://employer.tangerino.com.br/test` → `"Hello, <seu_nome>."`

## 3. Convenções gerais

- **Datas/horas em milissegundos** (epoch millis) em quase todos os campos (`admissionDate`, `startDate`, `endDate`, `lastUpdate`...).
- Exceção: ponto em atraso usa string `yyyy-MM-dd'T'HH:mm:ss.SSSZ` (ex.: `2019-08-01T14:52:36.968-0300`).
- **Paginação**: query params `page` e `size` nos endpoints `find-all`/listagens (retorno `Page<...>`).
- **`externalId`**: identificador externo opcional em cargo, local, colaborador etc. — permite casar registros com o seu sistema e usar `externalId` no lugar do `id` Tangerino nas chamadas.
- **Sincronização incremental**: param `lastUpdate` (millis) em consultas de ponto e listagens — retorna só o que mudou desde a última consulta.
- Arquivos (folha de ponto) retornam em **Base64** (`base64FileContent` + `fileExtension` + `fileName`).
- Horários dentro de escala (`startShift1` etc.) são millis contados a partir da meia-noite.

## 4. Cadastros básicos (Employer API)

### Cargo — Job Role Controller
| Método | Path | Notas |
|---|---|---|
| POST | `/job-role/register` | Body: `{ "description": "...", "externalId": "..."? }` → retorna com `id` |
| GET | `/job-role/find` | `?id=` ou `?externalId=` |
| GET | `/job-role/find-all` | `page`, `size`, `lastUpdate` |
| DELETE | `/job-role/{id}` | |

### Local de trabalho / Setor — Workplace Controller
| Método | Path | Notas |
|---|---|---|
| POST | `/workplace/register` | Body: `{ "name": "...", "externalId": "..."? }` |
| GET | `/workplace/find` | `?id=` ou `?externalId=` |
| GET | `/workplace/find-all` | `page`, `size` |

### Escala de trabalho — Work Schedule Controller
- **Escalas NÃO podem ser criadas via API** — cadastro só pelo portal web.
- `GET /work-schedule` (paginação) — lista escalas com `workScheduleTimetableList` (por dia da semana: `day` 1–7, `startShift1/endShift1`, `startShift2/endShift2` em millis desde 00:00).
- Atribuição de escala a colaborador: `POST /employee-work-schedule/register`; escala livre: `POST /employee-work-schedule/free-work-schedule/assign` e `PUT .../replacement`.

### Colaborador — Employee Controller
| Método | Path | Notas |
|---|---|---|
| POST | `/employee/register` | query `allowUpdate` (bool) permite atualizar se já existir; header `gestorId` opcional |
| GET | `/employee/find` | `?tangerinoId=` ou `?externalId=`, `ignoreFired` |
| GET | `/employee/find-all` | `branchExternalId`, `managerId`, `lastUpdate`, `showFired`, paginação |
| POST | `/employee/dismiss` | Body `DismissDTO` (demissão) |
| PATCH | `/employee/update-phone` | |
| POST | `/employee/upload-photo` | `employeeId` + base64 |

Body do `register` (EmployeeDTO):

| Campo | Tipo | Obrigatório |
|---|---|---|
| `name` | string | sim |
| `email` | string | sim |
| `admissionDate` | millis | sim |
| `effectiveDate` | millis | sim |
| `workSchedule` | int (id da escala) | sim |
| `workplace` | int (id do local) | sim |
| `timeZone` | string (enum) | sim |
| `birthDateInMillis`, `phone`, `cpf`, `ctps`, `series`, `pis`, `externalId` | — | não |

Resposta inclui `id` gerado e **`pin`** — com o PIN o colaborador já consegue bater ponto.

**Time zones**: enum próprio, ex. Brasil: `SAO_PAULO`, `BAHIA`, `BELEM`, `CUIABA`, `FORTALEZA`, `MACEIO`, `MANAUS`, `NORONHA`, `RECIFE`, `RIO_BRANCO`, `ARAGUAINA`, `BOA_VISTA`, `CAMPO_GRANDE`, `PORTO_VELHO`, `SANTAREM`; internacionais: `LONDRES`, `PARIS`, `NOVA_IORQUE`, `TOKYO`, `SYDNEY` etc. (300+).

### Gestor — Manager Controller
| Método | Path | Notas |
|---|---|---|
| POST | `/manager/register` | query `allowUpdate` |
| GET | `/manager/find` | `employeeId` / `tangerinoId` / `employeeExternalId` |
| GET | `/manager/find-all` | paginação, `tangerinoManagerId` |
| PUT | `/manager/employees/associate` | vincula subordinados |

Body do `register` — por id Tangerino ou por externalId:
```json
{
  "employeeId": 123,            // ou "employeeExternalId": "ABC"
  "recordsPunch": true,          // gestor bate ponto?
  "allowChangePunch": true,      // pode editar batidas
  "allowExcludePunch": false,    // pode excluir batidas
  "employeeList": [1, 2, 3]      // ou "employeeExternalIdList": ["A","B"]
}
```

## 5. Ajustes de ponto (Employer API)

### Motivos de ajuste — Adjustment Reason Controller
- `GET /adjustment-reason/find-all` (paginação) → `{ id, description, allowance, fullDay, countAsMissing }` (ex.: `FÉRIAS`, id 1).

### Lançamentos (ajustes, férias, atestados) — Adjustment Record Controller
- `POST /adjustment/register`:
```json
{
  "adjustmentReasonDTO": { "id": 1 },
  "employeeDTO": { "id": 99999 },        // ou { "externalId": "..." }
  "startDate": 1559358000000,
  "endDate": 1561950000000,
  "fullDay": true,
  "status": "APROVADO"                    // APROVADO | PENDENTE | REPROVADO
}
```
- Férias = ajuste com motivo FÉRIAS, `fullDay: true`, período em millis.
- `GET /adjustment/find-all` — filtros `employeeId`, `adjustmentReasonId`, `workplaceId`, paginação.
- `PUT /adjustment/{id}` — altera período/status/fullDay.

### Ponto em atraso (Punch API)
- `POST https://api.tangerino.com.br/api/punch/register/late/1.1`:
```json
{
  "employeeId": 123,                                  // ou "externalId"
  "date": "2019-08-01T14:52:36.968-0300",            // ISO com timezone
  "manualEditingJustificationId": 4
}
```
- Justificativas válidas: `GET /manual-editing-justification-punch/` (paginado).

## 6. Folha de ponto (Punch + Report API)

### Consulta de pontos — `GET https://api.tangerino.com.br/api/punch/`
- Params: `employeeId`, `startDate`, `endDate` (millis), `status`, `lastUpdate` (incremental), `page`, `size`.
- Também: `GET /summary` (resumo paginado), `PUT /{punchId}/status/{status}` (aprovar/reprovar com `description`), `DELETE /punches/{punchId}/employee/{employeeId}`.
- Status de batida: `APPROVED`, `PENDING`, `REPROVED`; pendência: `ENTRADA`, `SAIDA`, `AMBOS`.

### Dados consolidados (Punch API)
- `GET /daily-activity`, `GET /daily-summary/`, `GET /hoursBalance` (banco de horas), `GET /workData`, `GET /closure?date=` — todos com `employeeId`, `startDate`, `endDate`.

### Histórico de observações
- `GET /observation-historical?startDate=&endDate=&punchId=` (millis; `punchId` opcional).

### Emissão do espelho de ponto — Report API
- `GET /time-sheet` com filtros equivalentes à tela de relatórios do portal.
- Resposta: `{ "base64FileContent": "...", "fileExtension": "PDF", "fileName": "..." }` — decodificar o Base64 para obter o PDF.

## 7. Outros recursos do Punch API (Swagger)

- **Registro de batidas**: `POST /register/lite/1.1`, `/register/lite/punchs` (lote), `/register/web/1.1`, `/register/app/1.1`, `POST /modify/punch/1.1`.
- **AFD / relógio eletrônico (Portaria 671)**: `POST /electronic-watch/afd`, `/afd-import`, `/afd-punch`, `/afd-data-import`; `GET /afd-data-export` (filtros `nsr`, `pis`, `serialNumber`), `GET /last-nsr/{serialNumber}/registerType/{registerType}`.
- **Reconhecimento facial**: `/facial/recognize`, `/mobile-facial/enroll|validate|delete/{faceId}`.
- **Integração RHiD**: `/electronic-watch/integration/rhid/enable`, `/rhid/afd/sync`.

## 8. Fluxo típico de integração

1. Obter token com o suporte → validar com `GET /test`.
2. Cadastrar **cargos** e **locais de trabalho** (com `externalId` do seu sistema).
3. Cadastrar **escalas pelo portal web** e consultar ids via `GET /work-schedule`.
4. Cadastrar **colaboradores** (`POST /employee/register`) — guardar `id` e `pin` retornados.
5. Definir **gestores** e subordinados.
6. Operação contínua: lançar férias/ajustes (`/adjustment/register`), pontos em atraso (`/register/late/1.1`), e sincronizar batidas incrementalmente com `lastUpdate`.
7. Fechamento: emitir espelho de ponto via `GET /time-sheet` (Base64 → PDF).
