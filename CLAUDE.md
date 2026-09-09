# Branch Atual: TEST

Este ficheiro está checked-in no branch `test` (staging — `https://piscinasmmcrespo-testes.up.railway.app`).
**Push automático para `test`** (`git push origin test`) — não perguntar "main ou teste?" antes de dar push.
**Push para `main` só a pedido explícito** e depois de a suite passar e de o teste manual em staging estar feito. Na sessão 25 os dois branches ficaram alinhados em `7a687fe`; o `main` local está preso na worktree `implement_pool_closure_task`, por isso o alinhamento faz-se com `git push origin test:main`.
Se em algum momento este texto disser "TEST" mas `git branch --show-current` disser outra coisa, o ficheiro está desatualizado nesse checkout — confiar no `git branch`, não neste texto.

---

# Comandos

```bash
composer install && npm install        # setup inicial (ou: composer run setup)
php artisan migrate --seed             # schema + dados iniciais (users, piscinas, produtos)

composer run dev                       # serve + queue:listen + pail + vite, tudo junto
npm run dev                            # só Vite (hot reload)

php artisan config:clear; php artisan test   # suite inteira (~292 s) — ver nota abaixo
php artisan test --filter=NomeDoTeste  # correr um teste único
vendor/bin/pest tests/Feature/Foo.php  # idem, via Pest diretamente

vendor/bin/pint                        # lint/format (Laravel Pint)
vendor/bin/phpstan analyse             # análise estática (larastan)
npm run build                          # build de assets para produção

php artisan hanna:sync --discover      # descobrir sensores Hanna Cloud da conta
php artisan hanna:sync                 # sincronizar leituras (agendado a cada 15min)
```

`composer test` **não serve para a suite inteira**: o Composer mata qualquer processo aos 300 s e a suite leva ~292 s de testes mais o arranque. Serve para um ficheiro ou um filtro.

Testes funcionais/manuais (browser, mobile) fazem-se sempre em produção ou staging — ver "Regras de Sessão" mais abaixo. `test`/`pest` acima são só para a suite automatizada (SQLite in-memory).

---

# Arquitetura

- **Só admin**: a app inteira vive dentro do painel Filament em `/admin` (`AdminPanelProvider`). Não há front-end público separado — a raiz `/` redireciona para `/admin`. O protótipo `/m` foi apagado; a app instalada abre o painel real (ver "PWA" mais abaixo).
- **Padrão append-only**: `DailyRecord`, `FilterCheck` e `Incident` nunca apagam o registo original numa edição. Uma correção cria uma nova linha com `e_correcao=true` + `corrige_registo_id` a apontar para a original; `razao_correcao` documenta o porquê. Gráficos e relatórios filtram sempre com `whereDoesntHave('correcoes')` para não contar o registo substituído duas vezes.
- **Duas fontes de verdade por piscina, escolhidas num único sítio**: `SourceSelectionService::selectSource()` é a cascata central — sonda fresca (≤60 min) → último registo manual (≤8h) → sonda com avaria declarada → sonda stale → sem dados. Devolve `source`, `reading`, `record`, `age_minutes`, `is_artifact`, `artifact_reason` e `outage`. Usada por `PainelPiscinasWidget` e `EsquemaPiscina`. Não recalcular esta decisão noutro sítio. O valor "atual" manual vem dos accessors `_efetivo` do `DailyRecord` (combinam campos manuais + `ns_`). Hoje todas as 5 piscinas têm sonda Hanna instalada.
- **Leituras que não contam (artefactos)**: `LeituraArtefactoService` calcula as janelas em que a leitura do controlador é inválida — lavagem/enxaguamento de filtro (duração assumida de 20 min + 10 min de estabilização), bomba parada, e o período de uma `SensorOutage` declarada. Uma leitura dentro dessas janelas não gera não-conformidade em nenhum ecrã, gráfico ou no livro sanitário. Fonte única — não duplicar a regra.
- **Avaria de sonda (`SensorOutage`)**: aberta por uma Ação Operacional do tipo `avaria_sonda` (motivos: peça partida, em reparação, em calibração, sem comunicação, removida, leituras erradas, outro) e fechada por outra ação a dar baixa. Enquanto está aberta, a sonda aparece como "indisponível" no cartão do dashboard, no Kanban, no Esquema do Circuito, na página Sensores Hanna (coluna + filtro) e no modal de detalhes. O `OperationalActionObserver` é quem abre/fecha o registo.
- **Encerramento de piscinas (`PoolClosure` + `PoolClosureService`)**: fonte única para "esta piscina estava aberta neste dia?". Usar `PoolClosureService::mapa()` em ecrãs de intervalo (heatmap, gráficos, PDF — uma query para a janela toda) e `Pool::estaEncerradaEm()` para um único dia. Nunca reimplementar — uma segunda versão divergiria do livro sanitário. Encerrar/reabrir também limpa `AlertState` e `TapAlert` da piscina e notifica a equipa (`PiscinaEncerradaNotification`).
- **Plano de trabalhos da paragem técnica (`PoolClosureTask` + `PlanoParagemService`)**: cada `PoolClosure` pode gerar um plano com os 13 trabalhos do template legal (`TrabalhoParagem::template()`) — esvaziamento, limpeza e desinfeção do tanque, Legionella, supercloração, etc. Cada trabalho é uma máquina de estados auditada (`previsto` / `em_curso` / `executado` / `nao_executado` / `nao_aplicavel`), com `origem` ortogonal ao estado (`declarada`, `reconstruida` a partir de ações operacionais ou sonda, `inferida`). **Não é append-only** — vive de `LogsActivity`. As provas por trabalho são três colunas JSON: `fotos`, `videos` (1 clipe, 60 MB) e `documentos` (boletim de Legionella, obrigatório para fechar esse trabalho). `PlanoParagemPdfService` gera dois documentos: o Plano de Trabalhos (prévio) e o Relatório de Paragem (final, com gráfico de sonda em SVG, Anexo A de ações operacionais, fotos embutidas, índice de vídeos e índice **com SHA-256** dos boletins). Os vídeos saem referenciados **sem hash** — decisão do responsável técnico, para não descarregar 60 MB do R2 a cada geração. **Ficheiro que o dompdf não imprime nunca é omitido em silêncio de um documento legal** — sai referenciado, com o motivo. Documentação completa: `app/Filament/Resources/PoolClosureResource/CLAUDE.md`.
- **Nadador-salvador bloqueado por piscina encerrada (`PoolAccessRequest`)**: se **todas** as piscinas atribuídas ao NS estão encerradas, o middleware `BlockClosedPoolAccess` desvia-o para `/piscinas-encerradas`, onde pode pedir acesso temporário ao admin (`PoolAccessRequestService`, estados `pendente`/`aprovado`/`negado`, resource "Pedidos de Acesso" em Operação). Uma aprovação só cobre o encerramento vigente no momento da decisão — se a piscina reabrir e encerrar outra vez, o NS volta a ficar bloqueado e tem de pedir de novo.
- **Roles via `App\Constants\UserRole`** (não strings soltas) + `spatie/laravel-permission`: `admin`, `gestor`, `tecnico`, `nadador_salvador`, `inativo`. Nadador-Salvador só vê as suas piscinas e um subconjunto de secções do formulário de registo diário (sem Bomba/Filtros/Contador/Químicos); usa telemóvel pessoal no local, e as suas permissões finas estão em `App\Constants\NSPermission` (`registo_diario`, `incidentes`, `analise_parametros`). Gestor é essencialmente leitura/relatórios. Policies (`DailyRecordPolicy`, `IncidentPolicy`, `StockInstallationPolicy`) fazem a validação de autorização real.
- **Páginas ligáveis/desligáveis por Gestor (`App\Constants\PaginaGestor` + `users.paginas_visiveis`)**: no `UserResource` pode-se ligar/desligar por utilizador Gestor 13 páginas (Utilizadores, Convites, Encerramentos, Stock Visão Geral/Armazém/Instalação, Produtos, Bidões, Movimentos Armazém/Instalação, Análise, Relatório PDF, Esquema). `User::podeVerPagina()` devolve sempre `true` para quem não é Gestor; páginas core (Registo Diário/Incidentes) e admin-only ficam sempre fora da lista.
- **Stock em duas camadas**: `StockWarehouse` (central) → `StockInstallation` (por instalação). Toda a movimentação (transferência, consumo em "Adições de Químicos", reabastecimento de bidão) é `DB::transaction()` + `lockForUpdate()` e gera `StockWarehouseLog`/`StockInstallationLog` para auditoria. Alerta de stock baixo compara `quantity <= limite_minimo` por produto/instalação.
- **Cache do dashboard é versionado**: `CacheService` guarda o payload do `PainelPiscinasWidget` sob uma chave `cache_painel_piscinas_{scope}_v{N}`. Ao mudar a forma do array cacheado (`metricas4`, `sonda`, etc.), incrementar `PainelPiscinasWidget::CACHE_SHAPE_VERSION` — caso contrário um deploy pode devolver dados com a forma antiga a uma view já atualizada e rebentar com "Undefined array key" (já aconteceu em produção). **Valor atual: 6.**
- **Notificações**: `laravel-notification-channels/webpush` (push) + `DatabaseNotification` (sino do Filament) + e-mail via Resend. `AlertasService` é a fonte única dos trilhos de conformidade/incidentes/torneira/sonda — não recalcular "está fora dos limites" noutro sítio.
- **Pesquisa global (Cmd/Ctrl+K)**: `PaginasGlobalSearchProvider` acrescenta um grupo "Páginas" no topo dos resultados (o Filament só pesquisa Resources), com sinónimos por página (`livro sanitario` → Relatório PDF, `limites` → Definições) e filtragem por `canAccess()`. Os 3 resources de logs e o Activity Log ficam **deliberadamente** de fora — são linhas de histórico e afogariam os resultados úteis.
- **Sincronização offline**: `OfflineSyncController` recebe registos diários e ações operacionais gravados no dispositivo sem rede (`POST /offline-sync/daily-records`, `POST /offline-sync/operational-actions`), sempre atrás de `auth` + `RequirePasswordChange` e com `abort_unless($user->can('create', ...))`.
- **Automação agendada** (`routes/console.php`, lista completa): `hanna:sync` (15 min, só com credenciais), `backup:database` (03:00), `archive:daily-records --older-than=365` (domingos 04:00), `activitylog:clean` (segundas 04:30, retenção 730 dias), `queue:work --queue=daily-records,sensor-sync,default --stop-when-empty --max-time=50` (cada minuto — não há worker Railway dedicado), `timers:fire-due` (cada minuto), `torneiras:verificar-abertas` (15 min), `notificacoes:resumo-conformidade` (cada minuto, dedup por slot), `notificacoes:custom-fire-due` (cada minuto), `regras:executar` (15 min — auto-incidente em 3x violação/dia/piscina, escalação >24h, fecho automático de stock), `tendencias:verificar` (6h), `notificacoes:resumo-turno` (cada minuto, dedup), `notificacoes:comparacao-semanal` (domingos 09:00), `relatorio:mensal-automatico` (dia 1, 06:00), `alerts:housekeeping` (à hora, poda >7 dias).

---

# Stack Técnica e Integrações

- **Framework:** Laravel 12 LTS, `composer.json` exige `php ^8.2` (dev local corre PHP 8.5.6 NTS VS17 x64 em `C:\php\php.exe`; NÃO usar Herd Lite).
- **Admin/UI:** Filament 3.3.x. Tema: light mode por defeito, primary `#0284c7`, success `#059669`, danger `#f43f5e`, gray Zinc. Sidebar recolhível em desktop, largura máxima `ScreenTwoExtraLarge`.
- **Fontes:** o painel Filament é registado com `->font('Lato', provider: LocalFontProvider::class)` (sem `<link>` externo), mas o CSS da app define `--font-sans`/`--font-heading` como **Inter** com `!important` — logo o que se vê é Inter. Ficheiros vêm todos do bundle (`@fontsource/inter`, `@fontsource/lato`, `@fontsource/montserrat`), zero pedidos a CDN.
- **DB:** SQLite (dev) / PostgreSQL 16 (produção, Railway). 105 migrações.
- **Roles:** `spatie/laravel-permission`.
- **Audit Trail:** `spatie/laravel-activitylog` + `rmsramos/activitylog` (UI em `CustomActivitylogResource`, item de menu "Auditoria").
- **PDF:** `barryvdh/laravel-dompdf`.
- **E-mail:** `resend/resend-php`.
- **Erros:** `sentry/sentry-laravel`.
- **Charts:** Chart.js 4 + `chartjs-adapter-luxon`, `chartjs-plugin-annotation`, `chartjs-plugin-zoom`, `hammerjs` (gestos), carregado por render hook.
- **Animações/UI:** GSAP, GLightbox (empacotado no `app.js`, já não vem de CDN).
- **Sensores:** Hanna Cloud API (sondas BL132), uma por piscina, todas as 5 piscinas cobertas. `HannaCircuitBreaker` protege a integração: 5 falhas em 5 min abrem o circuito, 1 min depois passa a half-open, e em circuito aberto devolve a última leitura em cache.
- **Fotos:** Cloudflare R2 (S3-compatible) via `league/flysystem-aws-s3-v3` — Railway tem filesystem efémero, uploads vão para o disco `r2`. `LIVEWIRE_TMP_DISK=local`.
- **Qualidade:** Laravel Pint + larastan/phpstan, Pest 3 — **706 testes em 121 ficheiros** (90 em `tests/Feature`, 31 em `tests/Unit` por models/services/policies/middleware), ~292 s a correr. O `composer test` estoura no *process timeout* de 300 s do próprio Composer: para a suite inteira usar `php artisan config:clear; php artisan test`.
- **Deploy:** Railway. Produção: `https://piscinasmmcrespo.up.railway.app`. Staging/testes: `https://piscinasmmcrespo-testes.up.railway.app`.

---

# Páginas do Painel `/admin`

Ordem dos grupos de navegação (por frequência real de uso): **Registo Diário → Operação → Gestão → Estrutura (recolhido) → Sistema (recolhido) → Logs (recolhido)**.

Os antigos grupos "Dados" e "Stock" foram fundidos em **Gestão** na sessão 25. Nove resources deixaram de aparecer na sidebar (`shouldRegisterNavigation(): false`) e chegam-se por header actions ou por link direto — as rotas e os `canAccess()` continuam todos ativos. **Regra:** se se esconder um resource, o grupo declarado no seu `$navigationGroup` deixa de precisar de estar em `navigationGroups()`; mas se um resource **visível** declarar um grupo que não está nessa lista, o Filament acrescenta-o no fim da sidebar e **ignora o `->collapsed()`**. Foi o que aconteceu ao grupo `Logs` do `ActivitylogPlugin`, corrigido em `d310c0c`.

Cada Resource com pasta própria tem um `CLAUDE.md` local mais detalhado (propósito, lógica não óbvia, ações, e uma lista de coisas a rever encontradas no código — carrega automaticamente ao trabalhar nessa pasta). As páginas standalone têm o equivalente em `docs/paginas/*.md`:

- `app/Filament/Resources/DailyRecordResource/CLAUDE.md`, `IncidentResource/CLAUDE.md`, `OperationalActionResource/CLAUDE.md`
- `app/Filament/Resources/StockWarehouseResource/CLAUDE.md` (+ StockService), `StockInstallationResource/CLAUDE.md`, `ProductResource/CLAUDE.md`, `DosingContainerResource/CLAUDE.md`, `StockWarehouseLogResource/CLAUDE.md`, `StockInstallationLogResource/CLAUDE.md`
- `app/Filament/Resources/UserResource/CLAUDE.md`, `UserInvitationResource/CLAUDE.md`, `HannaDeviceResource/CLAUDE.md`, `PoolResource/CLAUDE.md`, `InstallationResource/CLAUDE.md`
- `docs/paginas/custom-activitylog.md`, `dashboard.md`, `analise-parametros.md`, `definicoes-sistema.md` (⚠️ tem um bug confirmado por corrigir), `encerramentos.md`, `esquema-piscina.md`, `notificacoes.md`, `relatorio-pdf.md`, `auth-login.md`
- `app/Filament/Resources/PoolClosureResource/CLAUDE.md` — encerramentos **e toda a paragem técnica** (template legal, máquina de estados, invariantes, evidência de sonda, os dois PDF)
- **Sem documentação local ainda** (dívida a fechar): `PoolAccessRequestResource`, `CustomActivitylogResource` (pasta) e `StockHub`.

## Registo Diário (grupo)
- **Registos Diários** (`DailyRecordResource`, sort 1): página núcleo, uso diário. Semáforo de conformidade em tempo real por campo, smart defaults (última piscina/bomba/água/tanque), lookback real no contador/água/bomba/hora, adições de químicos descontam stock da instalação. Pesquisa global própria aceita frases com data ("registo dia 2").
- **Incidentes** (`IncidentResource`, sort 2): quase sempre criados manualmente pela equipa (auto-incidente por 3x violação/dia/piscina existe mas é raro). Ciclo aberto/resolvido, `IncidentChatWidget` com timeline de mensagens + mudanças de estado, fotos, filtros, pesquisa na descrição. Escalação automática (sem resposta >24h) é notificação do sino, disparada por `regras:executar`.
- **Esquema** (`EsquemaPiscina`, sort 3): vista visual do circuito de água (torneira/contador → piscina → bomba → filtro → retorno) por instalação, com bidões de dosagem e estado da sonda Hanna (incluindo avaria declarada, com botão para atualizar) sobre o mesmo esquema.

## Operação
- **Ações Operacionais** (`OperationalActionResource`, sort 2): lavagem/enxaguamento de filtro, torneira, bomba, contador, tanque, análise pontual, reabastecimento de bidão, **avaria/indisponibilidade de sonda**, outro. Cada tipo tem o seu resumo formatado a partir de `dados` (JSON). Editável pelo autor durante 24h.
- **Pedidos de Acesso** (`PoolAccessRequestResource`): pedidos de nadadores-salvadores bloqueados por piscina encerrada; aprovar/negar notifica o requerente (`PedidoAcessoRespondidoNotification`). **Fora da sidebar** desde a sessão 25 — chega-se pelo botão `[Pedidos de Acesso]` no topo de Encerramentos, com badge de pendentes. Esse botão usa `PoolAccessRequestResource::canAccess()` e `::getUrl()`, e sai antes de contar os pendentes para não pagar a query a quem não tem acesso.

## Gestão
- **Stock** (`StockHub`, sort 1): entrada única do stock, uma linha por produto com armazém + cada instalação. Três header actions: `[Novo Produto]` (slide-over que grava `Product` direto, autorizado por `ProductResource::canAccess()`, concentração de cloro obrigatoriamente 0.01–100), `[Bidões de Dosagem]` e `[Histórico de Movimentos]` (ambos com `canAccess()` + `getUrl()` do resource respetivo, nunca URL em duro).
- **Encerramentos** (`EncerramentoPiscinas`): estado atual por piscina em cima, histórico completo em baixo (`HistoricoEncerramentosWidget`, colunas e filtros vindos de `PoolClosureResource::table()` — fonte única; só as ações mudam, o "Editar" é um link para a rota do resource).
- **Relatórios PDF (CN 14/DA)** (`RelatorioPdf`): livro de registo sanitário oficial. Auditorias DGS externas (sob pedido) e arquivo interno mensal (`relatorio:mensal-automatico` no dia 1). Exclui registos corrigidos (`whereDoesntHave('correcoes')`), coluna Conforme ✓/✗, avalia turbidez, defaults do mês anterior, contagem prévia. **Geração é síncrona** (stream direto) — o job assíncrono foi revertido e apagado por causa da UX de espera.
- **Análise de Parâmetros** (`AnaliseParametros`): uso pontual. `CloroPhChartWidget` em ecrã cheio + `ViolacoesPeriodoWidget`, `ScoreConformidadeWidget`, `HeatmapConformidadeWidget`, `EstabilidadeMedicoesWidget`, `ConsumoQuimicosWidget`. Cada acesso é registado no trilho de auditoria (canal `analise`).

### Fora da sidebar, dentro do Stock (rotas vivas)
Estes quatro continuam com rota, policies e pesquisa global; só saíram do menu para o Stock ter uma porta de entrada em vez de cinco:
- **Stock Armazém / Stock Instalação** (`StockWarehouseResource` / `StockInstallationResource`): duas camadas — ver Arquitetura. Ação "Transferir p/ Instalação" debita armazém, credita instalação. Unidades reais, filtros e somas na tabela.
- **Produtos** (`ProductResource`): catálogo com unidade, categoria e `concentracao_cl` (% de cloro ativo, usada pelo `DosageCalculatorService`).
- **Bidões de Dosagem** (`DosingContainerResource`, sort 40): capacidade e nível de cada bidão de reagente (cloro/pH-) por piscina. O nível desce automaticamente com a dosagem sincronizada do controlador Hanna; aqui configura-se capacidade e registam-se reabastecimentos (que debitam stock via `dosing_containers.product_id`).

## Estrutura (recolhido)
- **Piscinas** (`PoolResource`, admin only) / **Instalações** (`InstallationResource`): CRUD dos dados físicos (volume, temp_min/max, orp_min/max, ordem, tanques) usados em todos os cálculos de conformidade. A ação por linha **"Livro Sanitário (PDF)"** (`DgsPdfReportService`) **foi removida** — era um segundo gerador do mesmo documento legal que, ao contrário do `RelatorioPdf`, não excluía registos corrigidos nem tinha em conta encerramentos. O livro sanitário sai por um caminho só: Gestão → Relatórios PDF. Ver o comentário em `PoolResource.php:236`.

## Sistema (recolhido)
- **Definições** (`Definicoes`, sort 10): três separadores — "Minhas Notificações" (todos), "Sistema" e "Avisos" (só admin). Sistema: limites CN 14/DA (pH, cloro livre, cloro combinado, turbidez, tolerância de aviso), tempos/prazos (validade de leitura, timeout de sonda, validade de convite, aviso de torneira aberta, horários de digest), automação operacional (fator de compensação de dosagem). A fonte de verdade dos números é o código + `AppSetting`, não este documento.
- **Sensores Hanna** (`HannaDeviceResource`, sort 30, admin only): mapeamento dispositivo Hanna Cloud → piscina, coluna de estado (online/stale/avaria) + filtro. `php artisan hanna:sync --discover` cria/atualiza o mapeamento.
- **Utilizadores** (`UserResource`, admin/gestor): contas, convites, e o painel de páginas visíveis por Gestor (`PaginaGestor`). Gestor **não** pode mudar password/PIN de admins.
- **Convites** (`UserInvitationResource`, sort 45): pendentes/expirados/aceites, ações "Reenviar" (regenera token) e "Revogar". **Fora da sidebar** desde a sessão 25 — chega-se por Utilizadores.

## Logs (recolhido)
- **Movimentos Armazém/Instalação** (`StockWarehouseLogResource` / `StockInstallationLogResource`): histórico auditável das transações de stock, com a unidade real do produto.
- **Encerramentos** (`PoolClosureResource`): voltou ao menu de Logs na sessão 25 (é também para lá que o "Editar" do `HistoricoEncerramentosWidget` aponta). O histórico continua a aparecer no rodapé de Gestão → Encerramentos; este item é o acesso direto à tabela.
- **Auditoria** (`CustomActivitylogResource` + `ActivitylogPlugin`, sort 99, admin only): trilho de auditoria **único**. Junta CRUD dos models, autenticação (incl. login falhado, lockout, reset de password), alterações de definições, movimentos de stock/bidões e falhas de sistema. Escrita centralizada em `App\Support\Auditoria`; retenção de 730 dias.

## Dashboard
`Dashboard` ("Painel de Controlo") mostra, por esta ordem: `PainelPiscinasWidget` → `QuadroOperacionalWidget` (Kanban) → `StockBaixoWidget` → `CloroPhChartWidget` → `EstabilidadeMedicoesWidget`. Alertas antes dos gráficos, cartões recolhidos em mobile, estado da sonda sempre visível.

---

# PWA

A app é instalável no telemóvel: `public/manifest.json` (nome "Gestão Piscinas", `start_url: /admin`, `scope: /`, `display: standalone`, `portrait-primary`, tema preto, ícones 192/512 + maskable com o logótipo oficial branco sobre azul, e dois screenshots) mais `public/sw.js` (com timeout em Promise) e `nginx` a servir o `/sw.js` com `no-cache`. As tags vão para o `<head>` por render hook do `AdminPanelProvider` (`filament.pwa-head`), a par da bottom nav mobile e do prompt de ativação de notificações.

**A app instalada abre o painel real.** O protótipo `/m` — casca Livewire dark com 5 componentes de dados fictícios, sem `auth` e sem persistência, mais o `/api/pdf/export` que devolvia texto fingido — **foi todo apagado**. Já não existem `app/Livewire/`, `layouts/mobile.blade.php` nem rotas `/m`, e o `start_url` passou de `/m` para `/admin`. Era a dívida mais grave do projeto; está fechada.

Mobile hoje é o painel Filament em ecrã pequeno, não uma segunda app. Ver as regras 20, 21 e 22 em "Regras de Código" — são todas sobre overlays fixos a disputar píxeis no telemóvel.

---

# Regras de Sessão

## Branch de Trabalho
- Trabalhar sempre no branch ativo no momento. Não fazer checkout para outro branch.
- Push automático para o branch indicado no topo deste ficheiro ("Branch Atual") — não perguntar main/teste.

## Testes
- Testes funcionais/manuais (browser, mobile) fazem-se sempre na versão em produção ou staging, diretamente no URL da app. Não montar ambiente local (SQLite, artisan serve) para validar features.
- O login faz-se com a **sessão já aberta no Chrome do Daniel** (ferramentas `claude-in-chrome`), não escrevendo credenciais. Staging: `https://piscinasmmcrespo-testes.up.railway.app`. O endpoint `/api/health` diz se a base de dados e a cache estão ligadas antes de se abrir o painel.
- Um teste novo só conta depois de se confirmar que **falha sem a correção**. Na sessão 25, o `assertFormFieldIsHidden` passava por acaso em duas hipóteses diferentes; só reverter a correção e ver o teste rebentar provou que apanhava o bug.
- Correr a suite inteira, não só o ficheiro novo: um teste pode passar isolado e envenenar outro (ver memos `static`, sessão 25).

## Higiene do repositório
- O `git add -A` em PowerShell com heredocs mal interpretados já criou **três vezes** ficheiros-lixo com nomes como `({`, `p.slug`, `hasRole('admin'))`, `data`, `fim`, `halt()`, `map(function`. Foram limpos em `4d3d393` e outra vez na sessão 25. Nunca usar `git add -A`; usar `git commit --only <lista>` para commits parciais (o index é partilhado com outras sessões a correr no mesmo repositório). Para ficheiros novos, `git add <caminho>` explícito antes do `--only`.
- **Modo de falha novo, encontrado a 2026-09-08: 714 cópias `*_1.*` da árvore inteira**, criadas de uma vez, todas não rastreadas (`.env_1.example`, `CLAUDE_1.md`, um `_1.php` por classe, 124 em `tests/`). Todas verificadas como **cópias byte a byte** dos originais — as 3 que o `diff` marcou eram só CRLF vs LF. Não é o lixo de heredoc de 0 bytes: é uma duplicação em massa por alguma ferramenta. O Pest ignora-as (só descobre `*Test.php`, e estas acabam em `Test_1.php`), logo não inflacionam a contagem de testes. Ao dar de cara com isto: confirmar que são cópias antes de apagar, e nunca `git add -A`.
- **Há 9 worktrees ativas** neste repositório (`git worktree list`), várias com alterações não commitadas. Uma reestruturação de 15 ficheiros esteve fora do controlo de versões durante dias porque vivia numa delas. Ao começar uma auditoria ou uma revisão, correr `git status` **e** `git worktree list` — e nesse caso, fazer commit de segurança antes de tocar em código.
- Uma worktree criada pelo Antigravity pode não ter `vendor/` — nela não se corre `artisan`, `pest`, `pint` nem `phpstan`. Trazer o trabalho para o checkout principal (ou criar uma worktree própria com `vendor` ligado por junção) antes de verificar.

## Persona e Estilo de Resposta
- Lead with the solution. Explain only what isn't obvious.
- If I'm wrong, say so directly and say why.
- If I ask for something that doesn't make sense from a senior engineering perspective — over-engineered, insecure, premature abstraction, wrong layer of the stack — say so directly and explain why before proceeding.
- No filler: no "great question", no "certainly", no "I'd be happy to".
- No hedging: no "you might want to consider", no "one approach could be".
- Short sentences. If a paragraph can be a bullet list, use the list.
- Respostas curtas — menos texto, especialmente com Opus. Contexto extenso, planos e trade-offs longos vão para o CLAUDE.md/docs da página, não para o chat.
- Code must be complete and runnable. Never truncate with "// rest of code here".

## Regras de Código
- Match the style and conventions already in the file.
- No comments that explain what the code does — only comments for non-obvious WHY.
- No extra features, no premature abstractions, no defensive code for impossible scenarios.
- Never introduce security vulnerabilities (XSS, SQLi, IDOR, command injection).
- No emojis in code or commit messages.

## Formato de Resposta para Alterações de Código
Return exactly:
1. Full modified files (not diffs, not fragments)
2. Any migrations or schema changes needed
3. Commands to run, in order

## Token Efficiency
- Answer the question asked, not adjacent questions I didn't ask.
- If something needs clarification, ask one question, not five.
- Don't restate my question back to me.
- Don't summarize what you just did at the end of a response.

---

# Contexto Completo — Projeto Piscinas MMCrespo
> Última atualização: 2026-09-08 (revisão da dívida técnica contra o código: `/m`, `/api/pdf/export`, `DgsPdfReportService` e o bug das Definições já estavam fechados; `docs/onboarding.md` criado)
> Sessão 26 (2026-09-01): vídeo curto de evidência nos trabalhos de paragem técnica, dois travões silenciosos ao upload corrigidos, `main` alinhado com `test`.

## Sessão 26 — Vídeo curto de evidência na paragem técnica (2026-09-01)

Pedido do Daniel: "vídeo para mostrar o tanque limpo era muito muito melhor" do que fotos. Antes desta sessão, cada trabalho do plano de paragem só aceitava **fotos** (JPEG/PNG/WebP) e, no caso da Legionella, o **PDF** do boletim.

**O que passou a existir** (`1c18031`):
- Coluna `videos` (JSON) em `pool_closure_tasks` (migração `2026_09_01_000001`), a par de `fotos` e `documentos`. `PlanoParagemService` passa-a nos três sítios onde já passava as outras (criar plano, acrescentar trabalho, marcar executado).
- `FileUpload::make('videos')` no formulário de execução: **1 clipe por trabalho**, máximo **60 MB** (`MAX_VIDEO_KB`), mp4/mov.
- Ação de tabela **"Evidências"** (`verEvidencias`) + `resources/views/filament/paragem/evidencias.blade.php`: `<video controls>` mais link de descarga, e também as fotos. Fecha um buraco antigo — depois de um trabalho ficar `executado`, a ação "Executar" desaparece e **as fotos deixavam de se poder ver na app**; só saíam no PDF.
- No relatório PDF o vídeo **nunca é embutido** (o dompdf não o reproduz): `processarVideos()` gera um índice com nome do ficheiro, dimensão e SHA-256, impresso na secção 4 — o mesmo tratamento que já se dava aos boletins. **O hash do vídeo foi retirado depois** (decisão do responsável técnico: não é exigido pela CN 14/DA e obrigava a descarregar até 60 MB do R2 a cada geração); os boletins mantêm o SHA-256.
- `media-src` no CSP (sem ele o `r2.dev` caía no `default-src 'self'` e o vídeo não tocava), `upload_max_filesize` 25M → 64M, `post_max_size` e `client_max_body_size` para 128M.

**Duas coisas que faziam o vídeo desaparecer em silêncio** (`046b969`), as duas encontradas só porque o teste novo usa os **bytes verdadeiros de um MP4** (`tests/Fixtures/video-evidencia.mp4`, 64 KB de um ficheiro real — o cabeçalho `ftyp` é o que decide) em vez de um `UploadedFile::fake()->create()` vazio:
1. **`config/livewire.php` recusava tudo o que não fosse imagem ou PDF.** É uma porta global, anterior ao campo, e recusa **sem mensagem**: o clipe desaparecia, a tarefa ficava `executado` e o livro sanitário ficava sem a prova. Nenhum `acceptedFileTypes` no campo salva disto.
2. **O `fileinfo` do PHP classifica muitos MP4 reais como `application/mp4`**, não `video/mp4` — depende da marca no cabeçalho `ftyp`. Com só `video/mp4` na lista, um vídeo legítimo do telemóvel era rejeitado com erro de tipo de ficheiro.

**Aviso no formulário** (`dc4a287`): em 4K, 15 s passam dos 60 MB e o upload é recusado — o técnico só descobria **depois** de filmar. O `helperText` passa a `HtmlString` com os passos exatos no telemóvel (Gravar Vídeo 1080p 30 fps + Formatos "Mais Compatível"), que resolve de caminho o HEVC não abrir no Chrome do Windows. Contas: a 1080p/30 "Mais Compatível" cabem ~28 s; a 4K/30, ~10 s.

**Verificação.** 555 testes (eram 549). Pint limpo, PHPStan sem erros novos nos ficheiros tocados. Os três testes novos foram confirmados a **falhar** sem as respetivas correções. No browser: vídeo real de 7,6 MB a reproduzir (`readyState` 4, 33 s lidos, imagem no ecrã), SHA-256 do relatório **igual** ao `hash_file` do ficheiro em disco, e o aviso legível em desktop e em 375 px.

**O teste em browser foi local, não em staging** — a sessão do Chrome não estava autenticada em staging e não se escrevem passwords. Criou-se uma conta descartável só no SQLite local (que é gitignored) e uma rota `local`-only para entrar sem formulário; ambas removidas antes do commit. Duas notas para a próxima vez:
- `php artisan serve` é **um pedido de cada vez** e no Windows não há `PHP_CLI_SERVER_WORKERS`. O polling do painel segura o único worker durante minutos e o upload nunca apanha vez — foi por isso que o upload real não se conseguiu fazer pela UI local. O que se testou pela UI foi o campo, o aviso e a reprodução; o upload em si está coberto pelo teste com o MP4 verdadeiro.
- `after()` numa migração é **ignorado em silêncio no PostgreSQL** (`PostgresGrammar::$modifiers` não inclui `After`). É seguro — a coluna fica no fim — e já havia meia dúzia de migrações no repositório a fazê-lo.

**Ficheiros-lixo de heredoc apareceram três vezes durante a sessão** (`videos`, `$record`, `form(function`, `refresh()`, `main`, `$(curl`, …), todos com 0 bytes, todos limpos antes de cada commit. Ver "Higiene do repositório" — o problema é o heredoc em PowerShell, não o `git add`.

**`main` alinhado com `test` em `dc4a287`.** Produção e staging confirmados estáveis depois de cada deploy (5 verificações seguidas ao `/api/health` mais a página de login).

## Sessão 25 — Reestruturação de navegação, cards verticais e auditoria QA (2026-08-25 → 2026-08-26)

Duas metades: a reestruturação em si (feita noutra sessão, noutra pasta de trabalho) e a auditoria QA que a validou, encontrou 3 regressões e as corrigiu.

**A reestruturação** (`655f1f7`, `85f2eeb`, `d310c0c`, `60cbc21`):
- Sidebar de 7 grupos para 6: "Dados" + "Stock" fundidos em **Gestão**; `Estrutura`, `Sistema` e `Logs` recolhidos.
- Nove resources fora do menu com `shouldRegisterNavigation(): false` — `StockWarehouse`, `StockInstallation`, `Product`, `DosingContainer`, `UserInvitation`, `PoolAccessRequest` (e, temporariamente, os 3 de Logs, que voltaram em `d310c0c`).
- `StockHub` passa a porta única do stock, com 3 header actions.
- `EncerramentoPiscinas` ganha o botão `[Pedidos de Acesso]` com badge de pendentes.
- **Registo Diário sem Tabs**: `DailyRecordFormBuilder` troca `Tabs::make('Piscinas')` por uma `Section` vertical por piscina (`🏊 Nome` + `Volume: X m³` na descrição), `statePath("pools.{id}")` mantido igual. Filtros e químicos passam a secções `collapsible()->collapsed()`. O técnico preenche as 3 piscinas de Leiria com scroll, sem tocar em separadores.
- `CreateDailyRecord`: botão "Criar" passa a **"Gravar Registos"** (`size('lg')`, primary, ícone de check).

**A auditoria encontrou o trabalho fora do repositório.** Os 15 ficheiros estavam **não commitados** numa worktree do Antigravity (`C:\Users\danie\.gemini\antigravity\worktrees\piscinas_mmcrespo-main\optimize_pool_measurement_flow`), que não tem `vendor/`. Lição: antes de auditar, confirmar `git status` **e** `git worktree list` — havia 5 worktrees ativas nesse momento (são 9 hoje) e o trabalho pode estar em qualquer uma.

**Três regressões corrigidas (`7a687fe`)**:
1. **Risco legal.** Ao fundir "Lavagem filtros" + "Enxaguamento" + "Posição normal" numa só secção, a condição `->visible(...)` das duas últimas desapareceu. Como `filtro_foto_enxaguamento` e `filtro_foto_posicao_normal` estão na whitelist do `DailyRecordService`, o técnico podia gravar a prova de um enxaguamento que nunca aconteceu no livro sanitário. **A condição antiga também estava errada**: usava o caminho absoluto `$get("pools.{$pool->id}.filtro_faz_retrolavagem")` dentro de um container cujo statePath já era `pools.{id}`, logo resolvia para `pools.1.pools.1.…` e devolvia sempre null — antes da reestruturação estes campos estavam **sempre escondidos**. Corrigido com o caminho relativo `$get('filtro_faz_retrolavagem')`, colocado dentro dos closures dos schemas para não se perder outra vez.
2. **Erro 500.** `DosageCalculatorService` fazia `$produto->concentracao_cl ?? 10.0` e dividia por `($concentracao * 10)`. O `??` só apanha null, logo um produto com `concentracao_cl = 0` dava `DivisionByZeroError` na sugestão de dose. Passa a devolver `null` com concentração `<= 0`; o `[Novo Produto]` do StockHub exige 0.01–100.
3. **Autorização invertida.** O `criarProduto` do StockHub estava visível a `[ADMIN, GESTOR]` escrito à mão e gravava `Product::create()` direto — um Gestor com a página "Produtos" desligada em `PaginaGestor` criava produtos pelo hub, e um Técnico (que *tem* acesso a Produtos) não via o botão. Passa a `ProductResource::canAccess()`. Os 3 botões com `->url('/admin/…')` em duro passam a `getUrl()` + `canAccess()`.

**Bug latente na suite, corrigido na raiz.** O teste novo do enxaguamento desligou silenciosamente a validação do contador noutro ficheiro: `DailyRecordFormBuilder::$ultimoRegistoMemo` (e os dois memos de sonda) são `static`, valem um pedido HTTP em produção, mas a suite corre num processo e o `RefreshDatabase` reinicia os IDs das piscinas. Um ficheiro que abrisse o formulário fixava "piscina 1 não tem registo anterior" para todos os seguintes. Novo `DailyRecordFormBuilder::limparMemos()`, chamado em `Tests\TestCase::setUp()`.

**Verificação**: 535 testes a passar (eram 529 + 6 novos), Pint limpo, PHPStan neutro (53 antes e depois, todos pré-existentes). Os 2 ficheiros de teste novos foram confirmados a **falhar** sem as correções. Teste manual no browser em staging (sessão real do Chrome, sem montar ambiente local): toggle de retrolavagem ida-e-volta, mobile 390x844, os 3 botões do StockHub, recusa da concentração 0, badge de Encerramentos, e `/admin/products` a responder apesar de estar fora do menu. Zero erros de consola.

**`main` alinhado com `test`** (`29546a8..7a687fe`, fast-forward de 6 commits) — primeira vez em várias sessões que os dois branches estão iguais.

**Ficheiros-lixo de heredoc apareceram pela terceira vez** (`data`, `fim`, `halt()`, `map(function`, `concentracao_cl`, `$get('filtro_faz_retrolavagem'))`, `visiveis`), todos com 0 bytes. Limpos. Ver "Higiene do repositório".

## Sessão 24 — PWA, mobile-first e livro sanitário DGS (2026-08-06 → 2026-08-14)
- **Redesign mobile-first do dashboard** (`624e642`) com "vibe Linear/Revolut": Inter adicionada (`@fontsource/inter`), `--font-sans`/`--font-heading` passam a Inter com `!important`, glassmorphism e micro-animações no `widgets.css` e no `painel-piscinas.blade.php`.
- **Formulários de registo para uma mão** (`8553df8`, `270c4a4`): `DailyRecordFormBuilder` + CSS reorganizados para alcance do polegar.
- **Tabs em scroll horizontal** em vez de wrap no telemóvel (`de9fb0e`, `24b2b9e`); grelha responsiva nos seletores do gráfico para não esmagar em mobile (`557cb32`).
- **Resolução de alertas simplificada** (`5a33082`, `c041464`): modal glassmorphic no `QuadroOperacionalWidget` a substituir o `confirm()` nativo, alvos de toque maiores, ligação direta ao `IncidentResource`.
- **Livro Sanitário DGS por piscina** (`8030c7f`, `5431dce`, `8f75d13`): `DgsPdfReportService` + `pdf/dgs-report.blade.php`, ação por linha em `PoolResource`, A4 landscape, termo de abertura/encerramento, coluna Cloro Combinado, numeração "X de Y". Não excluía correções nem encerramentos. **Removido depois** — era um segundo gerador do mesmo documento legal; ver a secção Estrutura.
- **Transformação PWA** (`5adc0a2`, `7ec5666`): `manifest.json` com `start_url: /m`, ícones oficiais (branco sobre azul, e mais leves: 16 KB → 7 KB no 192), casca `layouts/mobile`, bottom nav de 4 itens, e 5 componentes Livewire em `/m`. Tudo com dados fictícios e sem `auth`. **A casca `/m` foi apagada depois** e o `start_url` passou a `/admin`; só o manifest, o `sw.js` e os ícones sobreviveram. Ver a secção "PWA".
- **Merge de `main` para `test`** (`4d2dcd2`).

## Sessão 23 — Permissões por página, pedidos de acesso e endurecimento (2026-08-04 → 2026-08-05)
- **`PaginaGestor` + `users.paginas_visiveis`** (migração `2026_08_04_000002_add_paginas_visiveis_to_users_table`): 13 páginas ligáveis/desligáveis por utilizador Gestor, com `User::podeVerPagina()` chamado no `canAccess()` de cada página/resource coberta.
- **Pedido de acesso com piscina encerrada**: `PoolAccessRequest` + `PoolAccessRequestStatus` + `PoolAccessRequestService` + `PoolAccessController` + middleware `BlockClosedPoolAccess` + `pool-access/blocked.blade.php` + duas notificações. Migração `2026_08_04_000002_create_pool_access_requests_table`. Uma aprovação vale só para o encerramento em vigor.
- **Endurecimento do login** (`app/Filament/Pages/Auth/Login.php`): rate limit **por conta + REMOTE_ADDR** (não `request()->ip()`, que com `trustProxies(at: '*')` seria forjável via `X-Forwarded-For`), auto-deteção de PIN (4–6 dígitos), e `DUMMY_HASH` para gastar um `Hash::check` real quando o e-mail não existe — sem isto o timing permitia enumerar contas.
- **Whitelist de campos** no `DailyRecordService` (`CAMPOS_PISCINA_PERMITIDOS` + `array_intersect_key`) e autorização explícita no `OfflineSyncController` (`abort_unless($user->can('create', ...))`).
- **11 testes de segurança novos**: XSS na sugestão de dosagem e nas fotos, whitelist de campos, guarda da lista de utilizadores em Definições, âmbito de piscinas na pesquisa global, rate limit por conta, timing oracle no login, autorização do offline sync, CSV injection no relatório, password na criação de utilizador, e o fluxo completo de pedido de acesso.
- **Dashboard dava 500 em PostgreSQL** (`8c13b3a`): o `select()` do histórico listava `ph_efetivo`, `cloro_livre_efetivo`, `cloro_combinado`, `temperatura_efetivo` — que são **accessors, não colunas**. O SQLite de dev trata um identificador desconhecido entre aspas duplas como literal de texto e engole o erro; o PostgreSQL responde "column does not exist". Passou a selecionar as colunas reais (`manual` + `ns_`), com teste `PainelPiscinasSelectColumnsTest`.
- **PDF revertido de assíncrono para síncrono** (`9937e9f`, `7035690`): o job `GerarLivroSanitarioJob` foi apagado; espera com stream direto é melhor UX do que "vai receber uma notificação".
- **Navegação**: grupo "Dados" passa para cima de "Stock" (`b7741d2`).
- **Limpeza**: 5 ficheiros-lixo de heredoc removidos (`4d3d393`) — e já voltaram a aparecer, ver "Higiene do repositório".

## Sessão 22 — Pesquisa global, encerramentos e avaria de sonda (2026-08-03 → 2026-08-04)
- **Pesquisa global alargada** (`c4e8971`): faltavam 5 resources (Stock Armazém, Stock Instalação, Bidões, Encerramentos, Convites) e as páginas standalone nunca apareciam. `PaginasGlobalSearchProvider` acrescenta o grupo "Páginas" com sinónimos e filtragem por `canAccess()`. Corrigido também o `recordTitleAttribute` em Produtos, Instalações, Piscinas e Sensores Hanna, que mostravam o nome do modelo em vez do registo. Logs e Activity Log ficam de fora de propósito.
- **Dois 500 na pesquisa corrigidos** (`2cb77e4`, `bad0581`): `static::getModel()->query()` sobre uma class-string; e o `merge()` de uma Eloquent Collection com objetos `GlobalSearchResult` (que não têm `getKey()`). A pesquisa por dia passou a aceitar frases ("registo dia 2") com regex `\b` para não dar falso positivo no dia 21.
- **Avaria de sonda de ponta a ponta** (`83ddd74`): `SensorOutage` + `OperationalActionObserver` + integração em `AlertasService`, `LeituraArtefactoService`, `SourceSelectionService`, `PainelPiscinasWidget`, `EsquemaPiscina`, `HannaDeviceResource` e `DailyRecordResource`. Migração `2026_08_04_000001_create_sensor_outages_table`. O técnico deixa de precisar de dizer "os valores são fictícios" fora do sistema.
- **Histórico de encerramentos no rodapé** da página Encerramentos (`07d650e`, `57e3ae7`): `HistoricoEncerramentosWidget` reutiliza `PoolClosureResource::table()`; o item duplicado em Logs saiu do menu.
- **Activity Log traduzido** — item de menu passa a "Auditoria" (`2c5ae89`).
- **`dose_ph_ml` ↔ `dose_cloro_ml`** invertidos no parsing do histórico Hanna: corrigido (`f09025f`) — estava pendente da sessão 21.

## Sessão 21 — Trilho de auditoria único no Activity Log (resumo)
- **Diagnóstico**: existiam quatro trilhos separados e sem ponto de entrada comum — Spatie Activitylog (11 models), logs de stock, logs de bidões e `Log::` em `storage/logs` (invisível ao admin e efémero no Railway). Sem IP, sem registo de login falhado, sem retenção.
- **Bug estrutural encontrado e corrigido**: `/admin/activitylogs` era servida pela `ListActivitylog` do pacote `rmsramos`, cuja `$resource` aponta em duro para o Resource do pacote. O `CustomActivitylogResource::table()` **nunca era chamado**. Criadas `CustomActivitylogResource/Pages/ListActivitylog` e `ViewActivitylog` + `getPages()`.
- **`App\Support\Auditoria`** é o ponto único de escrita para eventos que não são CRUD (canais `auth`, `definicoes`, `stock`, `sistema`, `operacao`, `analise`). Anexa IP e user-agent, exceto em consola.
- **Autenticação**: `Failed`, `Lockout` e `PasswordReset` passam a ser registados. A password nunca entra nas propriedades.
- **Models novos no trilho**: `IncidentMessage`, `UserInvitation` (exclui `token`), `DosingContainer` (só configuração), `FilterCheck`, `CustomBroadcast`. `RecordAddition`/`RecordPhoto` deliberadamente fora.
- **`AppSetting` não usa o trait**: PK string vs `subject_id` inteira. O diff é escrito à mão em `Definicoes::save()` no formato `old`/`attributes`. Comparação frouxa de propósito ('6.9' vs 6.9).
- **Eventos de negócio**: sync Hanna falhado (só o ciclo com falhas), arquivamento de registos, stock insuficiente nos dois caminhos.
- **Espelho de stock**: `MovimentoStockObserver` replica os três logs no trilho, dentro da transação do `StockService`.
- **Leitura**: coluna "Alterações" mostra `Campo: antigo → novo` traduzido por `App\Constants\AuditLabels`.
- **Retenção**: 730 dias em `config/activitylog.php` + `activitylog:clean` às segundas 04:30.
- **Migração**: `2026_08_03_000002_ensure_batch_uuid_on_activity_log` (idempotente).
- **Verificação**: 436 testes a passar. Falha 1 teste pré-existente e ambiental (`PersistenceTest`: `.env` local tem `SESSION_LIFETIME=120`, `.env.example` tem `43200`).
- **Commits**: `47682fb`, `5ee3d59`.

## Sessão 20 — Auditoria de velocidade de uso e correções (resumo)
- **Auditoria com 8 agentes em paralelo** sobre a app a correr (mobile 390x844 e desktop, quatro papéis), com contagem de toques e medição de tempos/peso/queries. Resultado em `docs/auditoria-velocidade-app.md` (+ PDF de 29 páginas).
- **Oito bugs que quebravam trabalho, todos corrigidos**: (1) `Definicoes::save()` gravava `''` e punha os limites CN 14/DA a zero; (2) sugestão de dosagem impressa 1000x maior; (3) navegação a pendurar até 25 s (4 recursos externos no `<head>` + service worker sem timeout); (4) `/admin/stock-warehouse-logs` a devolver 500 (relação `produto()` inexistente → `armazem.produto`); (5) atalho "Registo Rápido" a bloquear a submissão; (6) rascunho automático nunca restaurado; (7) gestor conseguia mudar password/PIN de admins; (8) "Resolver" no dashboard escondia violações legais sem undo.
- **Bug de layout**: `.fi-sidebar` era `position: fixed !important` em todos os tamanhos; o `fixed` passou a aplicar-se só abaixo de 1024 px.
- **Peso e velocidade**: logótipo 1 274 KB → 31 KB (WebP), gzip para CSS/JS no nginx, `expires` em `/images/`, `no-cache` no `/sw.js`, preloader sem os ~750 ms artificiais, polling das tabelas 10 s → 60 s, painel de 51 → 43 queries, `ultimaLeitura()` e payload do gráfico memoizados, `Pool::pluck` do `<head>` cacheada.
- **Migrações novas**: `2026_08_01_000001_add_fotos_to_incidents_table`, `2026_08_01_000002_add_product_id_to_dosing_containers`.
- **Verificação**: 380 testes a passar, 23 rotas nos quatro papéis sem 500, load médio de 414 ms em mobile.

## Sessão 19 — Auditoria Completa e Correção de Discrepâncias (resumo)
- Comparação sistemática da documentação vs código real; features técnicas confirmadas (R2, GLightbox, CSP, Dark Mode, auto-save, Policies).
- Discrepâncias corrigidas: fonte documentada como "DM Sans" mas o código tinha Lato; `LIVEWIRE_TMP_DISK` ausente do `.env.example`; sessão 17 a não referir CSP; merge conflict no fim do CLAUDE.md.

## Sessão 18 — Limpeza e Otimização Geral de Código Morto (resumo)
- **Backend**: removidos `AlertingService`, `HannaThresholdService`, `StructuredLogger`, `RequestIdMiddleware`, `SentryContextMiddleware`, `HannaInspectSchema`, e os blocos `'slack'`/`'gemini'` em `config/services.php`.
- **Vistas**: apagados `welcome.blade.php` e 6 componentes órfãos + a pasta do wizard Livewire morto.
- **Seeders/configs**: removidos 8 seeders inativos, dumps SQL, `config/alerting.php`, `config/prometheus.php`.
- **Assets**: removido `mmcKanban`, `sortablejs`, `setupAutoScroll` e seletores CSS mortos.
- **Validação**: 308 testes a passar; build Vite OK.

## Sessão 17 — Fotos R2 + Upload Mobile + Lightbox (resumo)
- **R2** (S3-compatible) para fotos persistentes; os 8 `FileUpload`/`ImageEntry` do `DailyRecordResource` usam `->disk('r2')`.
- **Fixes**: `TypeError` no `DailyRecordObserver` (`pool_id` string→int), `livewire-tmp` criado no entrypoint, `LIVEWIRE_TMP_DISK=local`.
- **CSP**: `img-src` com `https://*.r2.dev`; `script-src`/`style-src` com jsDelivr (na altura, para GLightbox — hoje empacotado).
- **Limites mobile/HEIC**: `upload_max_filesize=25M`, nginx `client_max_body_size=100M`, `maxSize(20480)`.
- **GLightbox** auto-wired a todas as `ImageEntry` via MutationObserver.
- **Commits**: `2c238ba`, `7ae5f95`, `6f7cba7`, `e3246e4`.

## Sessão 16 — Batch 4 & 5: Auditoria + Cache Locks (resumo)
- Corrigido `relation "cache_locks" does not exist` em produção (migração `2026_06_23_000006`).
- Arquivamento em cascata de `record_additions`/`record_photos` + `ArchiveDailyRecordsTest`.
- Policies `DailyRecordPolicy`, `IncidentPolicy`, `StockInstallationPolicy`; strings de cargo trocadas por `UserRole`.
- UX/WCAG AA: barras de progresso no painel, Custom Properties para dark mode, pesos de fonte inválidos (650→600), contraste do `.mmc-metric-label`, auto-save do formulário em `localStorage`.
- **Commits**: `dfe810a`, `e7e76bf`.

## Sessão 15 — Deploy Railway PostgreSQL + fix JSONB notifications (resumo)
- Transição SQLite (dev) → PostgreSQL 16 (produção).
- **Erro 500 no dashboard**: `notifications.data` era `TEXT`; o Filament filtra com `data->>'format'`, e o operador `->>` exige JSONB. Migração `2026_06_19_000001` faz `ALTER ... TYPE jsonb USING data::jsonb`.
- `TrustProxies` com `at: '*'` para o proxy da Railway (é isto que obriga o login a usar `REMOTE_ADDR` no rate limit — ver sessão 23).
- **Commit**: `730a9d3`.

## Sessão 14 — Popup pós-registo + transferência warehouse (resumo)
- Popup persistente "Registo guardado! O que pretende fazer a seguir?" via `dispatch('notificationSent', ...)`.
- Modal de confirmação no botão "Criar" (closure, não string — string faz bypass ao modal).
- `Installation::boot()` apaga incidentes em cascata (a FK não tinha `cascadeOnDelete`).
- Ação "Saída" → "Transferir p/ Instalação" com `lockForUpdate()` e log nas duas pontas.
- Unidades reais nos logs de stock; vírgula bloqueada em campos decimais.
- Ação corretiva movida do registo para o repeater de "Adições de Químicos".
- **Commits**: `844324f`, `54c61b9`, `6eb5e9c`.

## Sessão 13 — Histórico de stock + validação de quantidade (resumo)
- `StockInstallationLogResource` + `StockWarehouseLogResource` com filtros completos.
- Validação de quantidade disponível ao adicionar químicos.
- UX por papel auditada (NS vê 3 secções, técnico o formulário todo).
- **Fix**: tabela `jobs` restaurada (migração `2026_06_15_000002`, idempotente).
- **Commits**: `4daaca3`, `c20910d`, `c8aa6cc`, `e238237`.

## Sessão 12 — Limpeza de dead code + preparação PostgreSQL (resumo)
- Removidos `GeminiAnalysisService`, `OcrVisionService`, `IncidentPool`, `IncidentProduct` e testes mortos.
- `$fillable` limpos em `FilterCheck` e `RecordPhoto`.
- Migração de limpeza `2026_06_15_000001_clean_dead_ai_columns` (idempotente).
- `unsignedTinyInteger('attempts')` → `unsignedSmallInteger` (única incompatibilidade real com PostgreSQL).
- `declare(strict_types=1)` em `FilterCheck`, `RecordPhoto`, `Incident`.

## Sessão 11 — Revisão, limpeza e correções (resumo)
- O "real-time" do Kanban é o polling de 60 s; `AlertasService::limparMemo()` era decorativo e foi removido.
- Campos opcionais `bomba_foto` e `tanque_foto`.
- Revisão em 4 frentes (segurança, código, produção, PDF).
- Removidos 42 ficheiros-lixo commitados; `*.zip` no `.gitignore`.
- **4 bugs**: `cloroCombinado` negativo com `cloro_total` null; `app.js` a montar observers em duplicado + `setInterval(500ms)` eterno; `agua_modo` null a fechar alerta de torneira; `limparMemo` morto.

## Sessão 10 — Correlações fechadas + limpezas (resumo)
- Volumes das piscinas no `PoolSeeder` (900/600/50/170/170 m³) — sem eles a calculadora de dosagem devolvia sempre "Cálculo impossível".
- Correlação torneira (`agua_modo` → `tap_alerts`) ligada; novo modelo `TapAlert`.
- Ciclo de vida dos incidentes (status, resolvido_em/por/resolucao, ação "Resolver", filtro default 'aberto').
- Limpezas: campos de IA mortos no `FilterCheckResource`; `CloroPhChartWidget` com "Turbidez" FNU max 5.

## Sessão 9 — Dashboard centrado no técnico (resumo)
- Ordem: `PainelPiscinasWidget` → `QuadroOperacionalWidget` → `StockBaixoWidget`.
- Apagados `BoasVindasWidget`, `StatsOverviewWidget`, `SensoresHannaWidget`; `AccountWidget` desregistado.
- Sondas Hanna integradas nos cartões das piscinas; CTA "Registar" por piscina (`create?pool=ID`).
- Leituras em falta mostram "—" neutro.

## Sessão 8 — Quadro Kanban + polish (resumo)
- `QuadroOperacionalWidget` (3 colunas, drag-and-drop, estado em `alert_states` com chave `tipo|id|data`, poda >7 dias).
- `AlertasService` como cálculo central memoizado por pedido.
- `BoasVindasWidget` (hero) — depois removido na sessão 9.
- Fixes: payload do gráfico com `@json`, `APP_LOCALE=pt_PT`, índices em `daily_records`, tabela `notifications` + sino.

## Sessão 7 — Transformação UX (benchmark contra Skimmer, Pool Brain, FixMyPool, Orenda, Pool Shark H2O)
- **Registo Diário Turbo**: semáforo de conformidade em tempo real (fonte única `DailyRecord::avaliarConformidade()`), ação corretiva obrigatória quando há violação, smart defaults/lookback, adições a descontar stock, filtros na tabela, `inputmode=decimal`.
- **Dashboard Exception-First**: `AlertasOperacionaisWidget` (mais tarde substituído pelo Kanban), valores conformes em tom neutro, gráfico multi-métrica normalizado 0–100%.
- **Design**: light mode por defeito, alvos táteis ≥48 px.

## A Minha Persona (Programador)
Trata-me como profissional. Vai direto à resposta. Output técnico funcional primeiro, explicação depois. Sem linguagem de cobertura ou passiva. Diz-me diretamente se eu estiver errado.

## O Projeto
**Nome:** Piscinas MMCrespo
**Tipo:** Aplicação web de gestão operacional para piscinas municipais sob contrato da MMCrespo.
**Contexto legal:** Cumprimento obrigatório de CN 14/DA (DGS 2009), NP 4542:2017, DR 5/97.

| Instalação | Piscinas | Volume | Temp. Normal |
|---|---|---|---|
| Leiria | Competição (25x17.4m) | 900 m³ | 26-27°C |
| Leiria | Lazer (25x17.4m) | 600 m³ | 28-30°C |
| Leiria | Infantil (17.4x5m) | 50 m³ | 28-30°C |
| Maceira | Maceira (16.6x10m) | 170 m³ | 28-30°C |
| Caranguejeira | Caranguejeira (16.6x10m) | 170 m³ | 28-30°C |

---

## Estado do Desenvolvimento

| Plano | Estado | Descrição |
|---|---|---|
| Planos 1 a 3.9 | **CONCLUÍDO** | DB, Filament, Dashboards, Mobile/PWA, Análises Avançadas, Refatoração Caminho da Água, Segurança (Headers/Sessões) e Log de Atividades. Limpeza PSR-4 completa. |
| Plano 4 — Inteligência Artificial | **FORA DO ÂMBITO** | Decisão do Daniel (2026-06-15): IA/OCR não será implementado. |
| Plano 5 — Relatórios PDF (CN 14/DA) | **CONCLUÍDO** | `RelatorioPdf` + `pdf/livro-sanitario.blade.php`. Geração síncrona. |
| Plano 6 — UI de Audit Trail | **CONCLUÍDO** | Trilho único ("Auditoria"), sessão 21. |
| Plano 7 — Transformação UX (Sessão 7) | **CONCLUÍDO** | Ver secção da sessão 7. |
| PWA `/m` (Sessão 24) | **REMOVIDO** | O protótipo foi apagado. O `start_url` do manifest passou a `/admin` — a app instalada abre o painel real. Ver secção "PWA". |
| Reestruturação de navegação (Sessão 25) | **CONCLUÍDO** | Sidebar de 6 grupos, 9 resources fora do menu, cards verticais no registo diário. Auditado, 3 regressões corrigidas, 535 testes a passar, validado em browser. `main` = `test` em `7a687fe`. |
| Vídeo de evidência na paragem (Sessão 26) | **CONCLUÍDO** | 1 clipe de 60 MB por trabalho, ação "Evidências" para o ver, índice com SHA-256 no relatório. 555 testes, validado em browser. `main` = `test` em `dc4a287`. |

---

## Dívida técnica em aberto (por ordem de gravidade)
> Lista revista e verificada contra o código em 2026-09-08. Quatro itens que aqui estavam
> abertos já estavam feitos — ver "Fechado" no fim da secção.

1. **A evidência escolhida em "Sugerir Evidência" não é guardada.** O modal exige que se
   escolha uma ação operacional ou um evento de sonda, valida a escolha, e depois passa ao
   serviço só `executado_em`, `observacoes` e `origem`. O trabalho fica marcado `reconstruida`
   sem dizer **de quê** — o relatório legal não consegue citar a prova que o sustenta.
   Ver `PoolClosureResource/CLAUDE.md`, "Coisas a rever".
2. **`PoolClosureTask::dadosFormatados()` larga campos que o formulário recolhe.** Em
   `VERIFICACAO_PARAMETROS` (trabalho **obrigatório**) o formulário grava `dados.temperatura_agua`
   e `dados.acido_cianurico`, e o ramo do `switch` lê `dados['temperatura']` e ignora o ácido
   cianúrico; em `ARRANQUE_AQUECIMENTO` o `dados.equipamentos` também cai. O PDF imprime
   `dadosFormatados()`, logo esses valores **nunca chegam ao documento legal**.
3. **Sem `CLAUDE.md`/doc local** para `PoolAccessRequestResource`, `CustomActivitylogResource` (pasta) e `StockHub`. O `docs/paginas/encerramentos.md` e o `stock` estão desatualizados desde a sessão 25 (grupo, botões novos).
4. **Ficheiros-lixo de heredoc** já reapareceram várias vezes na raiz (`({`, `p.slug`, `hasRole('admin'))`, `data`, `fim`, `halt()`, `videos`, `$(curl`, …), sempre com 0 bytes. Verificar `git status` antes de qualquer commit.
5. **Nove worktrees ativas** (`git worktree list`) com trabalho não commitado. Antes de auditar ou de dar por concluída uma feature, verificar todas — a reestruturação da sessão 25 esteve fora do repositório durante dias.
6. **Enxaguamento e posição normal nunca foram testados no terreno** — a condição de visibilidade estava errada desde o início e só ficou correta na sessão 25. Confirmar com a equipa se os campos fazem sentido como estão, agora que aparecem de facto.
7. **A duração do vídeo de evidência não é validada.** Os "10-15 segundos" são só texto no formulário; o servidor não tem `ffmpeg` para medir. O que trava mesmo é o tamanho (60 MB). Um clipe de 2 minutos a 1080p passa.
8. **`OrcamentoGestosRegistoDiarioTest::test_o_alvo_de_trinta_gestos` fica `incomplete`, de propósito.** É um alvo em aberto: o registo diário ainda leva mais de 30 gestos. É o único `incomplete` da suite — não é uma falha, mas também não está atingido.
9. **`PaginaGestor::STOCK_VISAO_GERAL`** estava rotulado "Stock — Visão Geral" no painel de páginas visíveis do `UserResource`, mas a página passou a chamar-se "Stock" na sessão 25. Rótulo corrigido para "Stock", igual ao `$navigationLabel` do `StockHub`; confirmar que não há mais nomes divergentes no painel.

**Fechado** (estava escrito como aberto e já não é):
- `/m` sem `auth` e sem persistência — **a casca foi apagada**, `start_url` passou a `/admin`.
- `/api/pdf/export` a devolver texto fingido — **rota apagada**; o `routes/api.php` só tem `/health` e `/metrics`.
- Dois geradores de livro sanitário — **`DgsPdfReportService` apagado**, sobra o `RelatorioPdf`.
- Bug do `Select` sem import nas Definições — **corrigido** (ver `docs/paginas/definicoes-sistema.md`).
- `PersistenceTest` a falhar por `SESSION_LIFETIME` no `.env` local — **passa**; a suite inteira corre com 0 falhas.

---

## Regras de Código & Decisões Técnicas (Strict Rules)
1. **Tipagem:** Todo o PHP usa tipagem estrita (`declare(strict_types=1);`).
2. **Formulários:** Layouts com `Section::make()->collapsible()`. Ficheiros temporários usam `->dehydrated(false)`. Validações reativas avaliam apenas campos existentes na respetiva Section.
3. **Campos numéricos:** `TextInput::configureUsing` força `type="text"` nos numéricos — `type="number"` descarta silenciosamente a vírgula digitada (7,2 → "72") e corrompe valores sem erro visível; o `inputmode="decimal"` mantém o teclado certo e o `app.js` converte vírgula → ponto antes da validação.
4. **Database:** Stock obriga a `DB::transaction()` + `lockForUpdate()`. Colunas adicionadas a tabelas existentes usam `if (!Schema::hasColumn(...))`.
5. **Operações Append-Only:** ver Arquitetura. Gráficos usam `whereDoesntHave('correcoes')`.
6. **Nunca selecionar accessors no `select()`** — o PostgreSQL rebenta e o SQLite engole o erro (sessão 23).
7. **Segurança:** middleware `SecurityHeaders` com CSP; sessões `secure=true` (produção), `http_only=true`, `same_site=strict`; rate limit de login por conta + `REMOTE_ADDR`.
8. **Interface/Gráficos:** `CloroPhChartWidget` usa `spanGaps = false`. Grelhas de widgets usam `minmax(min(100%, Npx), 1fr)`.
9. **`$get()` é relativo ao container, não à raiz do formulário** (sessão 25). Dentro de um componente com `statePath("pools.{id}")`, escrever `$get("pools.{$pool->id}.campo")` resolve para `pools.1.pools.1.campo` e devolve **null sem erro** — a condição parece funcionar e está sempre falsa. Usar o nome do campo (`$get('campo')`), ou `$get('...', isAbsolute: true)` quando é mesmo preciso sair do container.
10. **Condições de visibilidade vivem junto dos campos**, dentro do closure que devolve o schema — não no sítio onde o schema é montado. Foi ao reorganizar o sítio de montagem que a regra do enxaguamento se perdeu (sessão 25).
11. **Atalhos que gravam models diretamente reutilizam o `canAccess()` do Resource** (e o `getUrl()` para links), nunca uma lista de papéis reescrita à mão. Uma segunda cópia da regra divergiu da primeira e abriu um furo de autorização (sessão 25).
12. **`??` não apanha zero.** Num denominador (`concentracao_cl`, volume, capacidade) o fallback tem de testar `<= 0`, não só null — senão é `DivisionByZeroError` em produção (sessão 25).
13. **Memos `static` em classes de formulário precisam de reset na suite.** Valem um pedido HTTP em produção, mas os testes correm num processo e o `RefreshDatabase` reinicia os IDs. Se se acrescentar um memo ao `DailyRecordFormBuilder`, acrescentá-lo também ao `limparMemos()` (sessão 25).
14. **Esconder um resource da sidebar é `shouldRegisterNavigation(): false`** — a rota, as policies e a pesquisa global continuam ativas, e isso é intencional. Mas um resource **visível** cujo `$navigationGroup` não esteja em `navigationGroups()` cria um grupo solto no fim da sidebar, sem respeitar `->collapsed()` (sessão 25).
15. **`config/livewire.php` → `temporary_file_upload.rules` é a porta de TODOS os uploads**, e recusa **sem mensagem visível**: o ficheiro desaparece, o formulário grava na mesma e fica-se com um registo sem prova. Ao acrescentar um tipo novo de anexo em qualquer sítio da app, acrescentar a extensão a essa lista **antes** de tocar no `acceptedFileTypes` do campo (sessão 26).
16. **`acceptedFileTypes` tem de cobrir as variantes reais do MIME.** O `fileinfo` do PHP devolve `application/mp4` para muitos MP4 legítimos (depende da marca no cabeçalho `ftyp`), não `video/mp4`. Validar por um único MIME "correto" rejeita ficheiros verdadeiros de telemóvel (sessão 26).
17. **Um teste de upload com `UploadedFile::fake()->create()` não prova nada sobre validação de tipo** — o ficheiro vai vazio e o MIME é o que se lhe disser. Para regras de `mimes`/`mimetypes`, usar bytes verdadeiros (ver `tests/Fixtures/video-evidencia.mp4`), que é o que o `fileinfo` vai ler (sessão 26).
18. **`after()` numa migração é ignorado no PostgreSQL** (`PostgresGrammar::$modifiers` não o inclui). Não parte nada — a coluna fica no fim da tabela — mas não se pode contar com a ordem das colunas em produção.
19. **`danger`/`success`/`warning` não são cores do Tailwind desta app.** Só existem no CSS publicado do Filament, e mesmo lá sem utilitários de fundo: `text-danger-600` existe, `bg-danger-600` **não**. Uma classe `bg-danger-*` escrita num blade nosso não gera regra nenhuma e falha em silêncio — o estado "excedido" da barra de timers esteve invisível desde que foi escrita. Em markup próprio usar a paleta base (`bg-red-600`); para cor semântica usar os componentes do Filament (`<x-filament::badge color="danger">`).
20. **Nenhum overlay `fixed` nosso pode assentar em cima do `.fi-topbar`.** O topbar é `position: relative` em desktop e `sticky; z-index: 30` em mobile, e é onde vivem o botão da sidebar, a pesquisa global e o sino. Uma barra `fixed top-0 z-50` tapa-os, e sem botão de fechar o painel fica sem navegação. Os overlays nossos vão para o fundo (`.mmc-timer-bar`), e o contentor de notificações do Filament (`fixed inset-4 z-50`, largura toda abaixo de 416 px) é empurrado para baixo do cabeçalho com `.fi-no { top: 4.5rem }`.
21. **Estado de UI persistido em `localStorage` precisa de uma forma de ser apagado a partir da UI.** Os timers de retrolavagem só eram limpos ao gravar o registo ou ao escolher "Descartar e sair" — fechar a app deixava-os a correr para sempre, visíveis em todas as páginas. Qualquer chave que sobreviva à navegação tem de ter um botão que a mate.
22. **Os overlays fixos do fundo empilham-se por `--mmc-timer-bar-space`, não por offsets em duro.** No telemóvel disputam os mesmos píxeis a bottom nav (`fixed bottom-4`, z-50), a barra de ações do formulário (`sticky`, z-20), a barra dos timers e o convite de notificações. Com `bottom: 0` em duro, a barra de ações ficava **debaixo** da bottom nav e o botão "Gravar Registos" era inclicável em 13 de 14 posições de scroll — o registo diário não se conseguia gravar no telemóvel. Ao acrescentar um overlay no fundo, empilhar com `max(<base>, var(--mmc-timer-bar-space, 0px))` e medir com `document.elementFromPoint()` no centro do alvo, que é a única prova de que o clique chega lá.

---

## Checklist OBRIGATÓRIA antes de Produção
- [ ] `APP_DEBUG=false` e `APP_ENV=production` no `.env`.
- [ ] Passwords dos seeders via env vars (`ADMIN_PASSWORD_*`), nunca em código.
- [ ] `APP_URL` com domínio real e HTTPS ativado.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [x] PostgreSQL em produção (feito na sessão 15).
- [x] CSP no `SecurityHeaders` (feito na sessão 17).
- [x] Backups automáticos da DB (`backup:database`, diário às 03:00).
- [x] `/m` fora do `start_url` do manifest (a casca foi apagada; `start_url` é `/admin`).
- [ ] Domínio custom configurado.

---

## 🗺️ Mapa de Contexto (AI_CONTEXT)
Os seguintes ficheiros têm um bloco `[AI_CONTEXT]` no cabeçalho que dita as regras estritas da sua modificação. **Lê-os sempre antes de os alterares**:

- **Operações:**
  - `app/Filament/Resources/DailyRecordResource.php` (registos diários, validação CN 14/DA, append-only)
  - `app/Filament/Resources/IncidentResource.php` (incidentes e auto-resolução)
  - `app/Filament/Pages/RelatorioPdf.php` (Livro de Registo Sanitário oficial)
- **Stock:**
  - `app/Filament/Resources/StockWarehouseResource.php` (armazém central, transferências)
  - `app/Filament/Resources/StockInstallationResource.php` (stock local, consumos)
- **Dashboards:**
  - `app/Filament/Pages/Dashboard.php` (exception-first, Kanban)
- **Serviços com fonte única (não duplicar a regra):**
  - `app/Services/PoolClosureService.php` (encerramentos)
  - `app/Services/PoolAccessRequestService.php` (bloqueio do NS)
  - `app/Services/PlanoParagemService.php` (plano de trabalhos da paragem; máquina de estados dos trabalhos)
  - `app/Services/EvidenciaParagemService.php` (candidatos a evidência a partir da sonda)
  - `app/Models/PoolClosureTask.php` (o trabalho em si — não é append-only, é auditado)
  - `app/Services/SourceSelectionService.php` (cascata sonda/manual)
  - `app/Services/LeituraArtefactoService.php` (leituras que não contam)
  - `app/Services/AlertasService.php` (alertas)
  - `app/Services/DosageCalculatorService.php` (dose sugerida a partir de `concentracao_cl`; devolve `null` em vez de inventar valores)
  - `app/Support/Auditoria.php` (escrita no trilho de auditoria)
- **Formulário do registo diário:**
  - `app/Filament/Resources/DailyRecordResource/DailyRecordFormBuilder.php` — ~1100 linhas, monta o formulário todo. Ver as regras 9, 10 e 13 em "Regras de Código": `$get()` relativo, condições junto dos campos, e memos `static` a limpar na suite.
