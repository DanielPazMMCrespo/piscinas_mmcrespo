# Branch Atual: MAIN

Este ficheiro está checked-in no branch `main` (produção — `https://piscinas-mmcrespo-main.up.railway.app`).
**Push automático para `main`** (`git push origin main`) — não perguntar "main ou teste?" antes de dar push.
Se em algum momento este texto disser "MAIN" mas `git branch --show-current` disser outra coisa, o ficheiro está desatualizado nesse checkout — confiar no `git branch`, não neste texto.

---

# Comandos

```bash
composer install && npm install        # setup inicial (ou: composer run setup)
php artisan migrate --seed             # schema + dados iniciais (users, piscinas, produtos)

composer run dev                       # serve + queue:listen + pail + vite, tudo junto
npm run dev                            # só Vite (hot reload)

composer test                          # == php artisan config:clear && php artisan test
php artisan test --filter=NomeDoTeste  # correr um teste único
vendor/bin/pest tests/Feature/Foo.php  # idem, via Pest diretamente

vendor/bin/pint                        # lint/format (Laravel Pint)
npm run build                          # build de assets para produção

php artisan hanna:sync --discover      # descobrir sensores Hanna Cloud da conta
php artisan hanna:sync                 # sincronizar leituras (agendado a cada 15min)
```

Testes funcionais/manuais (browser, mobile) fazem-se sempre em produção — ver "Regras de Sessão" mais abaixo. `test`/`pest` acima são só para a suite automatizada (SQLite in-memory).

---

# Arquitetura

- **Só admin**: a app inteira vive dentro do painel Filament em `/admin` (`AdminPanelProvider`). Não há front-end público separado — a raiz `/` redireciona para `/admin`.
- **Padrão append-only**: `DailyRecord`, `FilterCheck` e `Incident` nunca apagam o registo original numa edição. Uma correção cria uma nova linha com `e_correcao=true` + `corrige_registo_id` a apontar para a original; `razao_correcao` documenta o porquê. Gráficos e relatórios filtram sempre com `whereDoesntHave('correcoes')` para não contar o registo substituído duas vezes.
- **Duas fontes de verdade por piscina**: o valor "atual" de pH/cloro/temperatura vem ou do último `DailyRecord` manual (`_efetivo` accessors combinam manual + NS) ou da última leitura do controlador Hanna Cloud (`SensorReading`, sincronizado por `hanna:sync`). `PainelPiscinasWidget` e `AlertasService` decidem qual usar por piscina (sonda online <60min > registo manual <8h > sonda offline > sem dados) — esta ordem de prioridade é a lógica central do dashboard. Hoje todas as 5 piscinas têm sonda Hanna instalada.
- **Roles via `App\Constants\UserRole`** (não strings soltas) + `spatie/laravel-permission`: `admin`, `gestor`, `tecnico`, `nadador_salvador`, `inativo`. Nadador-Salvador só vê as suas piscinas e um subconjunto de secções do formulário de registo diário (sem Bomba/Filtros/Contador/Químicos); usa telemóvel pessoal no local. Gestor é essencialmente leitura/relatórios — vê dashboards, análises e stock, mas não mexe em Definições do Sistema, Sensores Hanna nem gere utilizadores ao mesmo nível que Admin. Policies (`DailyRecordPolicy`, `IncidentPolicy`, etc.) fazem a validação de autorização real.
- **Stock em duas camadas**: `StockWarehouse` (central) → `StockInstallation` (por instalação). Toda a movimentação (transferência, consumo em "Adições de Químicos", reabastecimento) é `DB::transaction()` + `lockForUpdate()` e gera um `StockWarehouseLog`/`StockInstallationLog` para auditoria. Alerta de stock baixo compara `quantity <= limite_minimo` por produto/instalação.
- **Cache do dashboard é versionado**: `CacheService` guarda o payload do `PainelPiscinasWidget` sob uma chave `cache_painel_piscinas_{scope}_v{N}`. Ao mudar a forma do array cacheado (`metricas4`, etc.), incrementar `PainelPiscinasWidget::CACHE_SHAPE_VERSION` — caso contrário um deploy pode devolver dados com a forma antiga a uma view já atualizada e rebentar com "Undefined array key" (já aconteceu em produção).
- **Notificações**: `laravel-notification-channels/webpush` (push) + `DatabaseNotification` (sino do Filament). Trilhos de conformidade/incidentes/torneira passam todos por `AlertasService` como fonte única — não recalcular a lógica de "está fora dos limites" noutro sítio.
- **Automação agendada** (`routes/console.php`): sync de sondas, backup diário da BD, arquivamento semanal de registos >1 ano, processamento de filas via scheduler (sem worker Railway dedicado), regras de negócio automáticas (auto-incidente em 3x violação/dia/piscina, escalação de incidente sem resposta >24h via notificação Filament — não confundir com a classe `EscalacaoIncidenteNotification`, que era código morto e foi removida), digests de conformidade/turno/comparação semanal, relatório mensal automático.

---

# Stack Técnica e Integrações

- **Framework:** Laravel 12 LTS, PHP 8.5+.
- **Admin/UI:** Filament 3.3.x.
- **DB:** SQLite (dev) / PostgreSQL 16 (produção, Railway).
- **Roles:** `spatie/laravel-permission`.
- **Audit Trail:** `spatie/laravel-activitylog` + `rmsramos/activitylog` (UI em `CustomActivitylogResource`).
- **PDF:** `barryvdh/laravel-dompdf`.
- **Charts:** Chart.js, carregado por render hook.
- **Sensores:** Hanna Cloud API (sondas BL132), uma por piscina, todas as 5 piscinas cobertas.
- **Fotos:** Cloudflare R2 (S3-compatible) — Railway tem filesystem efémero, uploads vão para o disco `r2`.
- **Deploy:** Railway. Produção: `https://piscinasmmcrespo.up.railway.app`. Staging/testes: `https://piscinasmmcrespo-testes.up.railway.app`.

---

# Páginas do Painel `/admin`

## Operação
- **Registo Diário** (`OperacaoHub` → `DailyRecordResource`): página núcleo, uso diário. Hub de entrada que esconde da sidebar a escolha entre Registos Diários e Incidentes (`shouldRegisterNavigation() = false` nos dois, só o hub aparece). Semáforo de conformidade em tempo real por campo, smart defaults (última piscina/bomba/água/tanque), adições de químicos descontam stock da instalação.
- **Incidentes** (`IncidentResource`): quase sempre criados manualmente pela equipa (auto-incidente por 3x violação/dia/piscina existe mas é raro na prática). Ciclo de vida aberto/resolvido; `IncidentChatWidget` na vista dá timeline de mensagens + mudanças de estado automáticas. Escalação automática (sem resposta >24h) é uma notificação Filament (sino), disparada pelo `ExecuteBusinessRulesCommand`.
- **Esquema** (`EsquemaPiscina`): uso frequente. Vista visual do circuito de água (torneira/contador → piscina → bomba → filtro → retorno) por instalação, com bidões de dosagem e estado da sonda Hanna sobre o mesmo esquema.
- **Ações Operacionais** (`OperationalActionResource`): lavagem/enxaguamento de filtro, torneira, bomba, contador, tanque, análise pontual, reabastecimento de bidão, outro. Cada tipo tem o seu resumo formatado a partir de `dados` (JSON).

## Stock
- **Stock Armazém / Stock Instalação** (`StockWarehouseResource` / `StockInstallationResource`): uso frequente. Duas camadas — ver Arquitetura. Ação "Transferir p/ Instalação" debita armazém, credita instalação.
- **Produtos** (`ProductResource`): catálogo com unidade e `limite_minimo` por instalação (usado no alerta de stock baixo).
- **Bidões de Dosagem** (`DosingContainerResource`, grupo Stock): capacidade e nível de cada bidão de reagente (cloro/pH-) por piscina. O nível desce automaticamente com a dosagem sincronizada do controlador Hanna; aqui só se configura capacidade e regista reabastecimento.

## Dados
- **Análise de Parâmetros** (`AnaliseParametros`): uso pontual, não diário. Reutiliza o `CloroPhChartWidget` em ecrã cheio + `ScoreConformidadeWidget`, `HeatmapConformidadeWidget`, `ConsumoQuimicosWidget` (estes três só aparecem aqui, não no Dashboard).
- **Relatório PDF (CN 14/DA)** (`RelatorioPdf`): livro de registo sanitário oficial. Serve dois usos: auditorias DGS externas (gerado sob pedido) e arquivo interno mensal (o `relatorio:mensal-automatico` já gera automaticamente todo dia 1). Exclui registos corrigidos (`whereDoesntHave('correcoes')`), coluna Conforme ✓/✗ contra os limites de `DailyRecord`.

## Sistema
- **Definições** (`DefinicoesSistema`, admin only): limites regulamentares CN 14/DA (pH, cloro livre, cloro combinado, turbidez, tolerância de aviso), tempos/prazos (validade de leitura, timeout de sonda, validade de convite, aviso de torneira aberta, horários de digest), automação operacional (fator de compensação de dosagem, etc.). Cada campo já tem `helperText` com o valor padrão — a fonte de verdade dos números é este ficheiro de código + `AppSetting`, não este documento.
- **Notificações** (`Notificacoes`): três coisas na mesma página — ativação de push neste dispositivo, zona de testes, e Notificações Personalizadas (broadcast manual por cargo ou utilizador). Uso real: avisos operacionais (piscina fechada, trocar produto no armazém) e lembretes administrativos (reuniões, RH).
- **Utilizadores** (`UserResource`, admin/gestor): gestão de contas + convites (`UserInvitation`/`InvitationService`, token expira em `convite_validade_horas`). Nadador-Salvador pode ter piscinas pré-atribuídas no convite.
- **Sensores Hanna** (`HannaDeviceResource`, admin only): mapeamento dispositivo Hanna Cloud → piscina. `php artisan hanna:sync --discover` lista os dispositivos da conta e cria/atualiza este mapeamento.

## Estrutura
- **Piscinas** (`PoolResource`) / **Instalações** (`InstallationResource`): CRUD admin dos dados físicos (volume, temp_min/max, orp_min/max) usados em todos os cálculos de conformidade.

## Logs
- **Movimentos Armazém/Instalação** (`StockWarehouseLogResource` / `StockInstallationLogResource`): histórico auditável de todas as transações de stock.
- **Activity Log** (`CustomActivitylogResource`): trilho genérico do `rmsramos/activitylog`, visível só a admin.

---

# Regras de Sessão

## Branch de Trabalho
- Trabalhar sempre no branch ativo no momento. Não fazer checkout para outro branch.
- Push automático para o branch indicado no topo deste ficheiro ("Branch Atual") — não perguntar main/teste, o CLAUDE.md de cada branch já diz qual é.

## Testes
- Testes funcionais/manuais (browser, mobile) fazem-se sempre na versão em produção, diretamente no URL da app (`https://piscinas-mmcrespo-main.up.railway.app`). Não montar ambiente local (SQLite, artisan serve) para validar features.

## Persona e Estilo de Resposta
- Lead with the solution. Explain only what isn't obvious.
- If I'm wrong, say so directly and say why.
- If I ask for something that doesn't make sense from a senior engineering perspective — over-engineered, insecure, premature abstraction, wrong layer of the stack — say so directly and explain why before proceeding.
- No filler: no "great question", no "certainly", no "I'd be happy to".
- No hedging: no "you might want to consider", no "one approach could be".
- Short sentences. If a paragraph can be a bullet list, use the list.
- Code must be complete and runnable. Never truncate with "// rest of code here".
