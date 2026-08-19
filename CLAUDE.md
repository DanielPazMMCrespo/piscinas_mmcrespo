# Branch Atual: TEST

Este ficheiro está checked-in no branch `test` (staging — `https://piscinasmmcrespo-testes.up.railway.app`).
**Push automático para `test`** (`git push origin test`) — não perguntar "main ou teste?" antes de dar push.
Se em algum momento este texto disser "TEST" mas `git branch --show-current` disser outra coisa, o ficheiro está desatualizado nesse checkout — confiar no `git branch`, não neste texto.

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
vendor/bin/phpstan analyse             # análise estática (larastan)
npm run build                          # build de assets para produção

php artisan hanna:sync --discover      # descobrir sensores Hanna Cloud da conta
php artisan hanna:sync                 # sincronizar leituras (agendado a cada 15min)
```

Testes funcionais/manuais (browser, mobile) fazem-se sempre em produção — ver "Regras de Sessão" mais abaixo. `test`/`pest` acima são só para a suite automatizada (SQLite in-memory).

---

# Arquitetura

- **Só admin**: a app inteira vive dentro do painel Filament em `/admin` (`AdminPanelProvider`). Não há front-end público separado — a raiz `/` redireciona para `/admin`. Exceção nova: o wrapper PWA em `/m` (ver "PWA mobile (`/m`)" mais abaixo), que é uma casca Livewire separada e **ainda é protótipo**.
- **Padrão append-only**: `DailyRecord`, `FilterCheck` e `Incident` nunca apagam o registo original numa edição. Uma correção cria uma nova linha com `e_correcao=true` + `corrige_registo_id` a apontar para a original; `razao_correcao` documenta o porquê. Gráficos e relatórios filtram sempre com `whereDoesntHave('correcoes')` para não contar o registo substituído duas vezes.
- **Duas fontes de verdade por piscina, escolhidas num único sítio**: `SourceSelectionService::selectSource()` é a cascata central — sonda fresca (≤60 min) → último registo manual (≤8h) → sonda com avaria declarada → sonda stale → sem dados. Devolve `source`, `reading`, `record`, `age_minutes`, `is_artifact`, `artifact_reason` e `outage`. Usada por `PainelPiscinasWidget` e `EsquemaPiscina`. Não recalcular esta decisão noutro sítio. O valor "atual" manual vem dos accessors `_efetivo` do `DailyRecord` (combinam campos manuais + `ns_`). Hoje todas as 5 piscinas têm sonda Hanna instalada.
- **Leituras que não contam (artefactos)**: `LeituraArtefactoService` calcula as janelas em que a leitura do controlador é inválida — lavagem/enxaguamento de filtro (duração assumida de 20 min + 10 min de estabilização), bomba parada, e o período de uma `SensorOutage` declarada. Uma leitura dentro dessas janelas não gera não-conformidade em nenhum ecrã, gráfico ou no livro sanitário. Fonte única — não duplicar a regra.
- **Avaria de sonda (`SensorOutage`)**: aberta por uma Ação Operacional do tipo `avaria_sonda` (motivos: peça partida, em reparação, em calibração, sem comunicação, removida, leituras erradas, outro) e fechada por outra ação a dar baixa. Enquanto está aberta, a sonda aparece como "indisponível" no cartão do dashboard, no Kanban, no Esquema do Circuito, na página Sensores Hanna (coluna + filtro) e no modal de detalhes. O `OperationalActionObserver` é quem abre/fecha o registo.
- **Encerramento de piscinas (`PoolClosure` + `PoolClosureService`)**: fonte única para "esta piscina estava aberta neste dia?". Usar `PoolClosureService::mapa()` em ecrãs de intervalo (heatmap, gráficos, PDF — uma query para a janela toda) e `Pool::estaEncerradaEm()` para um único dia. Nunca reimplementar — uma segunda versão divergiria do livro sanitário. Encerrar/reabrir também limpa `AlertState` e `TapAlert` da piscina e notifica a equipa (`PiscinaEncerradaNotification`).
- **Nadador-salvador bloqueado por piscina encerrada (`PoolAccessRequest`)**: se **todas** as piscinas atribuídas ao NS estão encerradas, o middleware `BlockClosedPoolAccess` desvia-o para `/piscinas-encerradas`, onde pode pedir acesso temporário ao admin (`PoolAccessRequestService`, estados `pendente`/`aprovado`/`negado`, resource "Pedidos de Acesso" em Operação). Uma aprovação só cobre o encerramento vigente no momento da decisão — se a piscina reabrir e encerrar outra vez, o NS volta a ficar bloqueado e tem de pedir de novo.
- **Roles via `App\Constants\UserRole`** (não strings soltas) + `spatie/laravel-permission`: `admin`, `gestor`, `tecnico`, `nadador_salvador`, `inativo`. Nadador-Salvador só vê as suas piscinas e um subconjunto de secções do formulário de registo diário (sem Bomba/Filtros/Contador/Químicos); usa telemóvel pessoal no local, e as suas permissões finas estão em `App\Constants\NSPermission` (`registo_diario`, `incidentes`, `analise_parametros`). Gestor é essencialmente leitura/relatórios. Policies (`DailyRecordPolicy`, `IncidentPolicy`, `StockInstallationPolicy`) fazem a validação de autorização real.
- **Páginas ligáveis/desligáveis por Gestor (`App\Constants\PaginaGestor` + `users.paginas_visiveis`)**: no `UserResource` pode-se ligar/desligar por utilizador Gestor 13 páginas (Utilizadores, Convites, Encerramentos, Stock Visão Geral/Armazém/Instalação, Produtos, Bidões, Movimentos Armazém/Instalação, Análise, Relatório PDF, Esquema). `User::podeVerPagina()` devolve sempre `true` para quem não é Gestor; páginas core (Registo Diário/Incidentes) e admin-only ficam sempre fora da lista.
- **Stock em duas camadas**: `StockWarehouse` (central) → `StockInstallation` (por instalação). Toda a movimentação (transferência, consumo em "Adições de Químicos", reabastecimento de bidão) é `DB::transaction()` + `lockForUpdate()` e gera `StockWarehouseLog`/`StockInstallationLog` para auditoria. Alerta de stock baixo compara `quantity <= limite_minimo` por produto/instalação.
- **Cache do dashboard é versionado**: `CacheService` guarda o payload do `PainelPiscinasWidget` sob uma chave `cache_painel_piscinas_{scope}_v{N}`. Ao mudar a forma do array cacheado (`metricas4`, `sonda`, etc.), incrementar `PainelPiscinasWidget::CACHE_SHAPE_VERSION` — caso contrário um deploy pode devolver dados com a forma antiga a uma view já atualizada e rebentar com "Undefined array key" (já aconteceu em produção). **Valor atual: 5.**
- **Notificações**: `laravel-notification-channels/webpush` (push) + `DatabaseNotification` (sino do Filament) + e-mail via Resend. `AlertasService` é a fonte única dos trilhos de conformidade/incidentes/torneira/sonda — não recalcular "está fora dos limites" noutro sítio.
- **Pesquisa global (Cmd/Ctrl+K)**: `PaginasGlobalSearchProvider` acrescenta um grupo "Páginas" no topo dos resultados (o Filament só pesquisa Resources), com sinónimos por página (`livro sanitario` → Relatório PDF, `limites` → Definições) e filtragem por `canAccess()`. Os 3 resources de logs e o Activity Log ficam **deliberadamente** de fora — são linhas de histórico e afogariam os resultados úteis.
- **Sincronização offline**: `OfflineSyncController` recebe registos diários e ações operacionais gravados no dispositivo sem rede (`POST /offline-sync/daily-records`, `POST /offline-sync/operational-actions`), sempre atrás de `auth` + `RequirePasswordChange` e com `abort_unless($user->can('create', ...))`.
- **Automação agendada** (`routes/console.php`, lista completa): `hanna:sync` (15 min, só com credenciais), `backup:database` (03:00), `archive:daily-records --older-than=365` (domingos 04:00), `activitylog:clean` (segundas 04:30, retenção 730 dias), `queue:work --queue=daily-records,sensor-sync,default --stop-when-empty --max-time=50` (cada minuto — não há worker Railway dedicado), `timers:fire-due` (cada minuto), `torneiras:verificar-abertas` (15 min), `notificacoes:resumo-conformidade` (cada minuto, dedup por slot), `notificacoes:custom-fire-due` (cada minuto), `regras:executar` (15 min — auto-incidente em 3x violação/dia/piscina, escalação >24h, fecho automático de stock), `tendencias:verificar` (6h), `notificacoes:resumo-turno` (cada minuto, dedup), `notificacoes:comparacao-semanal` (domingos 09:00), `relatorio:mensal-automatico` (dia 1, 06:00), `alerts:housekeeping` (à hora, poda >7 dias).

---

# Stack Técnica e Integrações

- **Framework:** Laravel 12 LTS, `composer.json` exige `php ^8.2` (dev local corre PHP 8.5.6 NTS VS17 x64 em `C:\php\php.exe`; NÃO usar Herd Lite).
- **Admin/UI:** Filament 3.3.x. Tema: light mode por defeito, primary `#0284c7`, success `#059669`, danger `#f43f5e`, gray Zinc. Sidebar recolhível em desktop, largura máxima `ScreenTwoExtraLarge`.
- **Fontes:** o painel Filament é registado com `->font('Lato', provider: LocalFontProvider::class)` (sem `<link>` externo), mas o CSS da app define `--font-sans`/`--font-heading` como **Inter** com `!important` — logo o que se vê é Inter. Ficheiros vêm todos do bundle (`@fontsource/inter`, `@fontsource/lato`, `@fontsource/montserrat`), zero pedidos a CDN.
- **DB:** SQLite (dev) / PostgreSQL 16 (produção, Railway). 102 migrações.
- **Roles:** `spatie/laravel-permission`.
- **Audit Trail:** `spatie/laravel-activitylog` + `rmsramos/activitylog` (UI em `CustomActivitylogResource`, item de menu "Auditoria").
- **PDF:** `barryvdh/laravel-dompdf`.
- **E-mail:** `resend/resend-php`.
- **Erros:** `sentry/sentry-laravel`.
- **Charts:** Chart.js 4 + `chartjs-adapter-luxon`, `chartjs-plugin-annotation`, `chartjs-plugin-zoom`, `hammerjs` (gestos), carregado por render hook.
- **Animações/UI:** GSAP, GLightbox (empacotado no `app.js`, já não vem de CDN).
- **Sensores:** Hanna Cloud API (sondas BL132), uma por piscina, todas as 5 piscinas cobertas. `HannaCircuitBreaker` protege a integração: 5 falhas em 5 min abrem o circuito, 1 min depois passa a half-open, e em circuito aberto devolve a última leitura em cache.
- **Fotos:** Cloudflare R2 (S3-compatible) via `league/flysystem-aws-s3-v3` — Railway tem filesystem efémero, uploads vão para o disco `r2`. `LIVEWIRE_TMP_DISK=local`.
- **Qualidade:** Laravel Pint + larastan/phpstan, Pest 3 (57 ficheiros em `tests/Feature`, mais `tests/Unit` por models/services/policies/middleware).
- **Deploy:** Railway. Produção: `https://piscinasmmcrespo.up.railway.app`. Staging/testes: `https://piscinasmmcrespo-testes.up.railway.app`.

---

# Páginas do Painel `/admin`

Ordem dos grupos de navegação (por frequência real de uso): **Registo Diário → Operação → Dados → Stock → Sistema → Estrutura (recolhido) → Logs (recolhido)**.

Cada Resource com pasta própria tem um `CLAUDE.md` local mais detalhado (propósito, lógica não óbvia, ações, e uma lista de coisas a rever encontradas no código — carrega automaticamente ao trabalhar nessa pasta). As páginas standalone têm o equivalente em `docs/paginas/*.md`:

- `app/Filament/Resources/DailyRecordResource/CLAUDE.md`, `IncidentResource/CLAUDE.md`, `OperationalActionResource/CLAUDE.md`
- `app/Filament/Resources/StockWarehouseResource/CLAUDE.md` (+ StockService), `StockInstallationResource/CLAUDE.md`, `ProductResource/CLAUDE.md`, `DosingContainerResource/CLAUDE.md`, `StockWarehouseLogResource/CLAUDE.md`, `StockInstallationLogResource/CLAUDE.md`
- `app/Filament/Resources/UserResource/CLAUDE.md`, `UserInvitationResource/CLAUDE.md`, `HannaDeviceResource/CLAUDE.md`, `PoolResource/CLAUDE.md`, `InstallationResource/CLAUDE.md`
- `docs/paginas/custom-activitylog.md`, `dashboard.md`, `analise-parametros.md`, `definicoes-sistema.md` (⚠️ tem um bug confirmado por corrigir), `encerramentos.md`, `esquema-piscina.md`, `notificacoes.md`, `relatorio-pdf.md`, `auth-login.md`
- **Sem documentação local ainda** (dívida a fechar): `PoolClosureResource`, `PoolAccessRequestResource`, `CustomActivitylogResource` (pasta), `StockHub`, e o wrapper PWA `/m`.

## Registo Diário (grupo)
- **Registos Diários** (`DailyRecordResource`, sort 1): página núcleo, uso diário. Semáforo de conformidade em tempo real por campo, smart defaults (última piscina/bomba/água/tanque), lookback real no contador/água/bomba/hora, adições de químicos descontam stock da instalação. Pesquisa global própria aceita frases com data ("registo dia 2").
- **Incidentes** (`IncidentResource`, sort 2): quase sempre criados manualmente pela equipa (auto-incidente por 3x violação/dia/piscina existe mas é raro). Ciclo aberto/resolvido, `IncidentChatWidget` com timeline de mensagens + mudanças de estado, fotos, filtros, pesquisa na descrição. Escalação automática (sem resposta >24h) é notificação do sino, disparada por `regras:executar`.
- **Esquema** (`EsquemaPiscina`, sort 3): vista visual do circuito de água (torneira/contador → piscina → bomba → filtro → retorno) por instalação, com bidões de dosagem e estado da sonda Hanna (incluindo avaria declarada, com botão para atualizar) sobre o mesmo esquema.

## Operação
- **Ações Operacionais** (`OperationalActionResource`, sort 2): lavagem/enxaguamento de filtro, torneira, bomba, contador, tanque, análise pontual, reabastecimento de bidão, **avaria/indisponibilidade de sonda**, outro. Cada tipo tem o seu resumo formatado a partir de `dados` (JSON). Editável pelo autor durante 24h.
- **Encerramentos** (`EncerramentoPiscinas`, sort 4): estado atual por piscina em cima, histórico completo em baixo (`HistoricoEncerramentosWidget`, colunas e filtros vindos de `PoolClosureResource::table()` — fonte única; só as ações mudam, o "Editar" é um link para a rota do resource).
- **Pedidos de Acesso** (`PoolAccessRequestResource`, sort 5): pedidos de nadadores-salvadores bloqueados por piscina encerrada; aprovar/negar notifica o requerente (`PedidoAcessoRespondidoNotification`).

## Dados
- **Análise de Parâmetros** (`AnaliseParametros`, sort 5): uso pontual. `CloroPhChartWidget` em ecrã cheio + `ViolacoesPeriodoWidget`, `ScoreConformidadeWidget`, `HeatmapConformidadeWidget`, `EstabilidadeMedicoesWidget`, `ConsumoQuimicosWidget`. Cada acesso é registado no trilho de auditoria (canal `analise`).
- **Relatório PDF (CN 14/DA)** (`RelatorioPdf`, sort 6): livro de registo sanitário oficial. Auditorias DGS externas (sob pedido) e arquivo interno mensal (`relatorio:mensal-automatico` no dia 1). Exclui registos corrigidos (`whereDoesntHave('correcoes')`), coluna Conforme ✓/✗, avalia turbidez, defaults do mês anterior, contagem prévia. **Geração é síncrona** (stream direto) — o job assíncrono foi revertido e apagado por causa da UX de espera.

## Stock
- **Visão Geral** (`StockHub`, sort -1): entrada do grupo Stock.
- **Stock Armazém / Stock Instalação** (`StockWarehouseResource` / `StockInstallationResource`): duas camadas — ver Arquitetura. Ação "Transferir p/ Instalação" debita armazém, credita instalação. Unidades reais, filtros e somas na tabela.
- **Produtos** (`ProductResource`): catálogo com unidade e `limite_minimo` por instalação.
- **Bidões de Dosagem** (`DosingContainerResource`, sort 40): capacidade e nível de cada bidão de reagente (cloro/pH-) por piscina. O nível desce automaticamente com a dosagem sincronizada do controlador Hanna; aqui configura-se capacidade e registam-se reabastecimentos (que debitam stock via `dosing_containers.product_id`).

## Sistema
- **Definições** (`Definicoes`, sort 10): três separadores — "Minhas Notificações" (todos), "Sistema" e "Avisos" (só admin). Sistema: limites CN 14/DA (pH, cloro livre, cloro combinado, turbidez, tolerância de aviso), tempos/prazos (validade de leitura, timeout de sonda, validade de convite, aviso de torneira aberta, horários de digest), automação operacional (fator de compensação de dosagem). A fonte de verdade dos números é o código + `AppSetting`, não este documento.
- **Sensores Hanna** (`HannaDeviceResource`, sort 30, admin only): mapeamento dispositivo Hanna Cloud → piscina, coluna de estado (online/stale/avaria) + filtro. `php artisan hanna:sync --discover` cria/atualiza o mapeamento.
- **Utilizadores** (`UserResource`, admin/gestor): contas, convites, e o painel de páginas visíveis por Gestor (`PaginaGestor`). Gestor **não** pode mudar password/PIN de admins.
- **Convites** (`UserInvitationResource`, sort 45): pendentes/expirados/aceites, ações "Reenviar" (regenera token) e "Revogar".

## Estrutura (recolhido)
- **Piscinas** (`PoolResource`, admin only) / **Instalações** (`InstallationResource`): CRUD dos dados físicos (volume, temp_min/max, orp_min/max, ordem, tanques) usados em todos os cálculos de conformidade. A tabela de Piscinas tem ainda a ação **"Livro Sanitário (PDF)"** por linha, que gera um PDF mensal em A4 landscape via `DgsPdfReportService` + `resources/views/pdf/dgs-report.blade.php` (termo de abertura/encerramento, coluna Cloro Combinado, numeração legal "X de Y"). ⚠️ Este caminho é **paralelo** ao `RelatorioPdf` e, ao contrário dele, **não exclui registos corrigidos nem tem em conta encerramentos** — decidir se se unifica ou se se corrige.

## Logs (recolhido)
- **Movimentos Armazém/Instalação** (`StockWarehouseLogResource` / `StockInstallationLogResource`): histórico auditável das transações de stock, com a unidade real do produto.
- **Encerramentos** (`PoolClosureResource`): resource e rota continuam vivos (é para lá que o "Editar" do widget aponta), mas o **item de menu foi removido** — o histórico já aparece no rodapé de Operação → Encerramentos.
- **Auditoria** (`CustomActivitylogResource` + `ActivitylogPlugin`, sort 99, admin only): trilho de auditoria **único**. Junta CRUD dos models, autenticação (incl. login falhado, lockout, reset de password), alterações de definições, movimentos de stock/bidões e falhas de sistema. Escrita centralizada em `App\Support\Auditoria`; retenção de 730 dias.

## Dashboard
`Dashboard` ("Painel de Controlo") mostra, por esta ordem: `PainelPiscinasWidget` → `QuadroOperacionalWidget` (Kanban) → `StockBaixoWidget` → `CloroPhChartWidget` → `EstabilidadeMedicoesWidget`. Alertas antes dos gráficos, cartões recolhidos em mobile, estado da sonda sempre visível.

---

# PWA mobile (`/m`) — ESTADO: PROTÓTIPO, NÃO LIGADO AOS DADOS

A app é instalável (`public/manifest.json`: nome "Gestão Piscinas", `start_url: /m`, `display: standalone`, `portrait-primary`, tema preto, ícones 192/512 + maskable com o logótipo oficial branco sobre azul; `sw.js` com timeout em Promise). No painel `/admin` há render hooks para as tags PWA, bottom nav mobile e prompt de ativação de notificações.

Além disso existe uma **casca mobile separada** (`resources/views/components/layouts/mobile.blade.php`, dark, `100dvh`, safe-area insets, bottom nav própria de 4 itens) com estas rotas em `routes/web.php`:

| Rota | Componente | Estado real |
|---|---|---|
| `/m` | `App\Livewire\MobileDashboard` | só renderiza a view |
| `/m/diario` | `Mobile\DailyLog` | `save()` **valida e faz reset, mas não grava nada** ("em um cenário real, gravaríamos no modelo DailyRecord") |
| `/m/analise` | `Mobile\Analysis` | gráficos com **dados hardcoded** (7 dias fictícios) |
| `/m/incidentes/novo` | `Mobile\ReportIncident` | "simula guardar" — **não cria Incident** |
| `/m/exportar` | `Mobile\ExportPdf` | usa `/api/pdf/export`, que devolve **texto fingido de PDF** |
| `mobile.profile` | — | rota **não existe**, o item da bottom nav aponta para `#` |

⚠️ **Dois problemas a resolver antes de isto ir para uso real:**
1. Nenhuma destas rotas (nem `/api/pdf/export`) está atrás de `auth` — qualquer pessoa não autenticada abre `/m` e o formulário de registo.
2. Nada persiste. É desenho, não funcionalidade. Quem abrir a app instalada cai em `/m` (é o `start_url`), ou seja no protótipo, não no painel real.

---

# Regras de Sessão

## Branch de Trabalho
- Trabalhar sempre no branch ativo no momento. Não fazer checkout para outro branch.
- Push automático para o branch indicado no topo deste ficheiro ("Branch Atual") — não perguntar main/teste.

## Testes
- Testes funcionais/manuais (browser, mobile) fazem-se sempre na versão em produção, diretamente no URL da app. Não montar ambiente local (SQLite, artisan serve) para validar features.

## Higiene do repositório
- O `git add -A` em PowerShell com heredocs mal interpretados já criou **duas vezes** dezenas de ficheiros-lixo com nomes como `({`, `p.slug`, `hasRole('admin'))`. Já foram limpos em `4d3d393` e voltaram a aparecer como untracked. Nunca usar `git add -A`; usar `git commit --only <lista>` para commits parciais (o index é partilhado com outras sessões a correr no mesmo repositório).

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
> Última atualização: 2026-08-19 (auditoria do código vs documentação; sessões 22–24 documentadas)

## Sessão 24 — PWA, mobile-first e livro sanitário DGS (2026-08-06 → 2026-08-14)
- **Redesign mobile-first do dashboard** (`624e642`) com "vibe Linear/Revolut": Inter adicionada (`@fontsource/inter`), `--font-sans`/`--font-heading` passam a Inter com `!important`, glassmorphism e micro-animações no `widgets.css` e no `painel-piscinas.blade.php`.
- **Formulários de registo para uma mão** (`8553df8`, `270c4a4`): `DailyRecordFormBuilder` + CSS reorganizados para alcance do polegar.
- **Tabs em scroll horizontal** em vez de wrap no telemóvel (`de9fb0e`, `24b2b9e`); grelha responsiva nos seletores do gráfico para não esmagar em mobile (`557cb32`).
- **Resolução de alertas simplificada** (`5a33082`, `c041464`): modal glassmorphic no `QuadroOperacionalWidget` a substituir o `confirm()` nativo, alvos de toque maiores, ligação direta ao `IncidentResource`.
- **Livro Sanitário DGS por piscina** (`8030c7f`, `5431dce`, `8f75d13`): `DgsPdfReportService` + `pdf/dgs-report.blade.php`, ação por linha em `PoolResource`, A4 landscape, termo de abertura/encerramento, coluna Cloro Combinado, numeração "X de Y". **Não exclui correções nem encerramentos** — ver aviso na secção Estrutura.
- **Transformação PWA** (`5adc0a2`, `7ec5666`): `manifest.json` com `start_url: /m`, ícones oficiais (branco sobre azul, e mais leves: 16 KB → 7 KB no 192), casca `layouts/mobile`, bottom nav de 4 itens, e 5 componentes Livewire em `/m`. **Tudo com dados fictícios e sem `auth`** — ver a secção "PWA mobile (`/m`)". É a dívida mais grave em aberto.
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
| PWA `/m` (Sessão 24) | **PROTÓTIPO** | Desenho pronto, dados e autenticação por ligar. Ver secção própria. |

---

## Dívida técnica em aberto (por ordem de gravidade)
1. **`/m` sem `auth` e sem persistência** — qualquer pessoa não autenticada abre o protótipo, e o `start_url` do manifest aponta para lá.
2. **`/api/pdf/export`** devolve texto fingido com `Content-Type: application/pdf`, sem autenticação.
3. **Dois geradores de livro sanitário** (`RelatorioPdf` vs `DgsPdfReportService`), e o segundo ignora correções e encerramentos.
4. **`docs/paginas/definicoes-sistema.md`** documenta um bug confirmado ainda por corrigir.
5. **Ficheiros-lixo de heredoc** outra vez untracked na raiz (`({`, `p.slug`, `hasRole('admin'))`, …).
6. **`PersistenceTest`** falha por ambiente (`SESSION_LIFETIME` 120 no `.env` local vs 43200 no `.env.example`).
7. **Sem `CLAUDE.md`/doc local** para `PoolClosureResource`, `PoolAccessRequestResource`, `StockHub` e a casca `/m`.

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

---

## Checklist OBRIGATÓRIA antes de Produção
- [ ] `APP_DEBUG=false` e `APP_ENV=production` no `.env`.
- [ ] Passwords dos seeders via env vars (`ADMIN_PASSWORD_*`), nunca em código.
- [ ] `APP_URL` com domínio real e HTTPS ativado.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [x] PostgreSQL em produção (feito na sessão 15).
- [x] CSP no `SecurityHeaders` (feito na sessão 17).
- [x] Backups automáticos da DB (`backup:database`, diário às 03:00).
- [ ] `/m` fechado com `auth` ou removido do `start_url` do manifest.
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
  - `app/Services/SourceSelectionService.php` (cascata sonda/manual)
  - `app/Services/LeituraArtefactoService.php` (leituras que não contam)
  - `app/Services/AlertasService.php` (alertas)
  - `app/Support/Auditoria.php` (escrita no trilho de auditoria)
