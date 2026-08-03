# Auditoria de código morto e inconsistências

> **Estado: aplicado.** Tudo o que está abaixo foi corrigido/removido em 2026-08-03,
> com as exceções listadas em "O que se decidiu manter", no fim. Documento mantido
> como registo do que foi encontrado e da razão de cada decisão.

> Base: commit `00b9c8c` (branch `main`, 2026-08-03). Varrimento automático de todas as
> definições de método, classe, constante, view, rota, classe CSS `.mmc-*` e função JS em
> `app/`, `resources/`, `routes/`, `database/`, `config/`, cruzado com `tests/`.
> Hooks do Filament/Laravel (`canAccess`, `getPages`, `toDatabase`, `getGlobalSearchResult*`,
> `getForms`, …) foram excluídos — são chamados pelo framework, não pelo nosso código.

## 1. Ficheiros inteiros sem uso

| Ficheiro | Porquê |
|---|---|
| `app/Filament/Pages/OperacaoHub.php` + `resources/views/filament/pages/operacao-hub.blade.php` + `operacao-hub-modal.blade.php` + bloco `.mmc-hub-*` (`resources/css/widgets.css:390-409`) | Página órfã: `shouldRegisterNavigation()` devolve `false` e **nada** aponta para `/admin/operacao-hub` — o bottom-nav vai direto a `/admin/daily-records/create`. Leva consigo `registoDiarioAction()`, `getDailyRecordUrl()`, `getIncidentUrl()` |
| `app/Filament/Resources/DailyRecordResource/Pages/EditDailyRecord.php` | A rota `edit` não existe em `getPages()` e `canEdit()` devolve sempre `false`. Classe inalcançável (`getHeaderActions`, `getFormActions`) |
| `app/Constants/FilamentColors.php` | Zero referências no projeto: 6 constantes + `all()`, `fromAlertLevel()`, `isValid()` |
| `app/Logging/ContextProcessor.php` | Nenhum canal em `config/logging.php` usa `processors` custom (só `PsrLogMessageProcessor`) |
| `app/Exceptions/InvalidDateException.php`, `app/Exceptions/PermissionException.php` | Nunca lançadas nem apanhadas fora dos testes |
| `app/Console/Commands/ImportLazerRecordsCommand.php` (259 linhas) | Importação one-off com leituras hard-coded de screenshots (Pool 2, abr–mai 2026). Não agendada, não referenciada |
| `app/Filament/AvatarProviders/GenericAvatarProvider.php` | `User` implementa `HasAvatar` e `getFilamentAvatarUrl()` devolve sempre `images/user-placeholder.svg`, logo o `defaultAvatarProvider` nunca é consultado. Os dois devolvem a mesma imagem — um é redundante |

## 2. Métodos mortos (nenhuma chamada, nem interna)

**Serviços**
- `DosageCalculatorService::avaliarTendencia()` (98 linhas: regressão linear + deteção de tendência)
- `DosageCalculatorService::registarEficacia()`
- `SettingsService::getBool()`
- `HannaCloudService::getHistory()` — a app usa `getHistoryReadings()`

**Modelos**
- `SensorReading::hannaDevice()`
- `TapAlert::openedRecord()`, `TapAlert::resolvedRecord()`, `TapAlert::resolvedBy()` (só `openedBy()` é usada)
- `Pool::users()`
- `User::hasPin()`
- `User::clearPushNotificationRequest()` — só chamada pelo método morto abaixo

**Filament**
- `Definicoes::limparSolicitacao()` — a blade só liga `pedirAtivacao()` e `limparSubscricoesUtilizador()`

**Enums / Constants**
- `EstadoConformidade::colorClass()`
- `AlertLevel::all()`, `AlertLevel::isValid()`
- `AlertType::all()`, `AlertType::isValid()`
- `IncidentStatus::all()`, `IncidentStatus::isValid()`
- `IncidentType::all()`, `IncidentType::isValid()`
- `UserRole::isValid()`

## 3. Métodos vivos apenas por causa dos testes

Nenhum caminho de produção os chama — o teste é a única razão de existirem:

- `CacheService::cacheGraphData()`, `getGraphData()`, `invalidateAllGraphs()`
- `AlertasService::resetMemo()` (aceitável — é utilitário de teste)
- `UserInvitation::isPending()`
- `DailyRecord::registoOriginal()`
- `friendlyMessage()` nas 4 classes de `app/Exceptions/`

## 4. Constantes e CSS sem uso

- `PainelPiscinasWidget::ORP_CLORO_MIN` / `ORP_CLORO_MAX` (680/820) — o comentário diz que servem para o agregado de "conformes", mas nunca são lidas
- `AlertState::STATUS` (`['pendente','em_curso','resolvido','resolvido_auto']`) — o estado `em_curso` também já não existe no widget
- `.mmc-pool-name` em `resources/css/app.css:36`
  (As restantes 16 classes `.mmc-*` que o scan marcou são modificadores compostos dinamicamente — `mmc-jerry--{{ $nivel }}`, `mmc-vg-card--{{ $estado }}`, `mmc-alert-row--{{ $nivel }}` — falsos positivos.)

## 5. Colunas de BD sem qualquer leitura/escrita

`daily_records.bomba_com_bolhas` e `daily_records.estado_valvulas_filtro` só aparecem no
`$fillable`/`$casts` do model. Nenhum form, tabela, infolist, PDF ou comando as toca.

## 6. Rota morta

`DELETE /push/subscribe` (`push.unsubscribe`) + `PushSubscriptionController::destroy()`.
O `push.js` só usa `POST /push/subscribe` e `DELETE /push/subscriptions` (`destroyAll`).

## 7. Bugs encontrados durante o varrimento

1. **`Livewire.emit()` é API do Livewire v2** — `resources/js/app.js:694` (dentro de
   `enviarNotificacao()`). No Livewire 3 é `Livewire.dispatch()`. Consequência: o listener
   `Livewire.on('timerExpirou', …)` em `app.js:7` nunca dispara e a chamada lança
   `TypeError` quando um timer de retrolavagem expira. O fallback `new Notification()` do
   browser continua a funcionar, o que mascara a falha. **Todo o bloco `app.js:6-33` é
   código morto na prática.**
2. **A cache do gráfico nunca é invalidada.** `CloroPhChartWidget::getChartPayload()`
   grava em `chart_v3_*` (10 min TTL), mas `CacheService::invalidateGraphCache()` — chamada
   pelo `DailyRecordObserver` e por `Pool::saved` — apaga o prefixo `cache_graph_*`, que
   **ninguém escreve** (só os testes). Um registo novo não aparece no gráfico até a chave
   expirar.
3. **`ScoreConformidadeWidget` viola a regra append-only.** Filtra
   `where('e_correcao', false)` em vez de `whereDoesntHave('correcoes')`: conta o registo
   original que foi corrigido e ignora a correção — exatamente o contrário do PDF, dos
   gráficos e do `AlertasService`.
4. **Override redundante** de `shouldRegisterNavigation(): return true` em
   `DailyRecordResource` e `IncidentResource` (é o default). Resto do desenho antigo em que
   o hub os escondia da sidebar.
5. **Fonte Google carregada duas vezes**: `resources/css/app.css:1` (`@import url(...)`) e o
   render hook `HEAD_END` do `AdminPanelProvider` (linhas 133-135) pedem o mesmo
   Montserrat+Lato.

## 8. Documentação desalinhada com o código

- `QuadroOperacionalWidget` — o docblock descreve "Kanban com 3 colunas
  (Para tratar / Em tratamento / Resolvido hoje), drag-and-drop (SortableJS), animações
  GSAP". Hoje é uma lista simples com dois estados (`pendente`/`resolvido`);
  o `sortablejs` foi removido do `package.json` na sessão 18. Idem no `CLAUDE.md` da raiz.
- `CLAUDE.md` (raiz) descreve **duas** páginas separadas, "Definições do Sistema"
  (`DefinicoesSistema`) e "Notificações" (`Notificacoes`). Na realidade existe uma só página
  `Definicoes` com três separadores (Minhas Notificações / Sistema / Avisos).
- `CLAUDE.md` diz que o `OperacaoHub` esconde Registos Diários e Incidentes da sidebar
  (`shouldRegisterNavigation() = false` nos dois). É o contrário: os dois estão na sidebar e
  é o hub que está escondido.
- `CLAUDE.md` afirma que `EscalacaoIncidenteNotification` "era código morto e foi removida".
  A classe existe e é usada pelo `ExecuteBusinessRulesCommand:134`.
- `CLAUDE.md` diz que `ScoreConformidadeWidget`, `HeatmapConformidadeWidget` e
  `ConsumoQuimicosWidget` só aparecem na Análise de Parâmetros (correto), mas não menciona
  `ViolacoesPeriodoWidget` (novo, na mesma página) nem que o `CloroPhChartWidget` e o
  `EstabilidadeMedicoesWidget` aparecem nas duas.
- `docs/paginas/operacao-hub.md`, `definicoes-sistema.md` e `notificacoes.md` documentam
  páginas que já não existem com esse nome/forma.

## O que está saudável

- **Views**: todas as 33 blades são referenciadas (`errors/403` e `errors/419` são convenção
  Laravel).
- **Imports**: zero `use` não utilizados — o commit `3f4603e` limpou os 10 que existiam.
- **Notificações**: as 17 classes em `app/Notifications/` têm todas um emissor real.
- **Comandos**: todos os 14 estão agendados em `routes/console.php` ou são utilitários
  manuais legítimos (`backup:database`, `hanna:sync --discover`) — exceto o
  `import:lazer-records` acima.
- **JS**: nenhuma função de topo morta em `app.js`/`push.js`/`gsap-transitions.js`; todos os
  endpoints e eventos Livewire têm as duas pontas ligadas, com a exceção do `timerExpirou`.
- **Widgets**: os 10 têm ponto de montagem (Dashboard, Análise de Parâmetros ou
  `ViewIncident`).
- **Circuit breaker Hanna**: `recordFailure`/`recordSuccess` parecem test-only mas são
  chamados por `execute()`, que o `HannaCloudSync` usa.

## O que se decidiu manter (e porquê)

Itens que o varrimento apontou mas que **não** foram removidos:

- **`UserInvitation::isPending()`** — faz par com `isExpired()`/`isAccepted()`, ambos usados
  no `UserInvitationResource`. Partir a trinca de predicados de estado para poupar 4 linhas
  não compensa.
- **`DailyRecord::registoOriginal()`** — inversa de `correcoes()`; documenta a relação
  append-only e está coberta por teste de modelo.
- **`friendlyMessage()`** em `SensorCommunicationException` e `StockInsufficientException` —
  as duas exceções são lançadas em produção; a mensagem amigável é superfície de API já
  testada. (As outras duas classes de exceção foram apagadas por inteiro.)
- **`AlertasService::resetMemo()`** — utilitário legítimo para reiniciar o memo estático
  entre testes.
- **`HannaCloudService::getHistory()`** — o varrimento classificou-o mal: é chamado por
  `getHistoryReadings()` no mesmo ficheiro.
- **`Pool::users()`** — igualmente mal classificado. É usado por nome em
  `whereHas('users', …)` no `DailyRecordFormBuilder`, que nenhum grep por `->users` apanha.

## Correções de âmbito além do relatado

O bug do `where('e_correcao', false)` não estava só no `ScoreConformidadeWidget`: o mesmo
filtro aparecia em 7 sítios (`ScoreConformidadeWidget`, `ViolacoesPeriodoWidget`,
`HeatmapConformidadeWidget`, `ConsumoQuimicosWidget`, `RelatorioPdf` (contagem de
pré-visualização), `EsquemaPiscina` (histórico de lavagens) e `DailyRecordFormBuilder`
(piscinas já registadas hoje)). Todos passaram a usar só `whereDoesntHave('correcoes')`.

## Pendente (decisão do Daniel)

- As colunas `daily_records.bomba_com_bolhas` e `estado_valvulas_filtro` saíram do model mas
  continuam na BD. Largá-las exige migração e perde o histórico que lá esteja.
- `tests/Feature/PersistenceTest::test_session_lifetime_is_configured_to_30_days` espera
  `session.lifetime = 43200`, mas o `.env` local tem `SESSION_LIFETIME=120` e o `phpunit.xml`
  não fixa a variável. Falha pré-existente, não relacionada com esta limpeza: ou produção
  quer sessões de 30 dias (e o `.env` está errado) ou o teste está errado.
