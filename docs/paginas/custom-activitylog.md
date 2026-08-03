# Activity Log (`CustomActivitylogResource`)

Não tem pasta própria (ficheiro único, sem `Pages/` — estende o Resource do pacote `rmsramos/activitylog`, que já fornece navegação/`getPages()`/`canAccess()` herdados).

## Propósito
Trilho de auditoria **único** da app (Spatie Activitylog), restrito a Admin. É a linha do tempo completa: CRUD dos models, autenticação, alterações de definições, movimentos de stock/bidões e falhas de sistema. Sem `create`/`edit`/`delete` — log é imutável por design.

## O que entra no trilho

| Categoria (`log_name`) | Origem | Exemplos |
|---|---|---|
| Geral (`default`) | trait `LogsActivity` nos models | criar/editar registo diário, piscina, incidente, convite, bidão |
| `auth` | `LogUserAuthentication` | login, logout, **login falhado**, lockout, reposição de password |
| `definicoes` | `Definicoes::save()` | alteração dos limites CN 14/DA e restantes definições |
| `stock` | `MovimentoStockObserver` + escritas diretas | entradas/saídas de armazém, consumos, dosagem de bidões, stock insuficiente |
| `sistema` | `Auditoria::sistema()` | sync Hanna falhado, arquivamento de registos |
| `analise` / `relatorios` | páginas correspondentes | consulta de análises, geração de PDF |

Models com `LogsActivity`: `DailyRecord`, `Incident`, `IncidentMessage`, `OperationalAction`, `Pool`, `PoolClosure`, `Installation`, `Product`, `StockWarehouse`, `StockInstallation`, `HannaDevice`, `User`, `UserInvitation`, `DosingContainer`, `FilterCheck`, `CustomBroadcast`.

## Lógica não óbvia
- **`App\Support\Auditoria`** é o ponto único de escrita para eventos que não são CRUD. Anexa IP e user-agent (`contexto()`), exceto em consola — o scheduler não tem pedido e um IP ali seria inventado.
- **`AppSetting` não usa o trait**: a PK é uma string (`key`) e `activity_log.subject_id` é inteiro. As alterações de definições são escritas à mão em `Definicoes::save()`, com o diff no formato `old`/`attributes` para o renderizador as mostrar como qualquer alteração de model.
- **`DosingContainer` só regista configuração** (`capacidade_ml`, `alerta_percent`, `product_id`, `tipo`). `restante_ml` desce a cada sync da Hanna (15 min) e inundaria o trilho; o consumo real vem do espelho de `DosingContainerLog`.
- **`UserInvitation` exclui `token`** — o hash não tem valor de auditoria.
- **Espelho de stock**: `MovimentoStockObserver` corre dentro da transação do `StockService`; se a transação abortar, a entrada de auditoria desaparece com ela (comportamento desejado). Os Resources de movimentos continuam a existir como vista operacional.
- **Sync Hanna só regista o ciclo com falhas** — um sync bom a cada 15 min seria só ruído.
- `getPropertiesColumnComponent()` substitui o `properties` cru por linhas `Campo: antigo → novo`, traduzidas por `App\Constants\AuditLabels`. O JSON original continua na BD; isto é só a camada de leitura.
- `getCauserNameColumnComponent()`: `causer_id === null` → "Sistema / Automático" (ações do scheduler), com o IP como descrição por baixo do nome.
- `getSubjectTypeColumnComponent()`: cadeia de heurísticas para um identificador legível. Mostra "(Apagado)" se o registo já não existe, "(Lixo)" se em soft-delete.

## Retenção
`config/activitylog.php` → 730 dias, aplicados pelo `activitylog:clean` agendado às segundas 04:30 (`routes/console.php`). Sem esse agendamento a tabela cresce indefinidamente em PostgreSQL.

## Coisas a rever
- Cobertura de traduções (`$modelNames` e `AuditLabels::CAMPOS`) mantida manualmente — um model ou coluna nova aparece com nome cru até alguém atualizar.
- `RecordAddition`/`RecordPhoto` ficaram deliberadamente fora: são filhos de `DailyRecord`, que já é auditado, e o consumo de químicos aparece pelo canal `stock`.
