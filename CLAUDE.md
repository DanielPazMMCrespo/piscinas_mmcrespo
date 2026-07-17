# Regras de Sessão

## Branch de Trabalho
- Trabalhar sempre no branch ativo no momento. Não fazer checkout para outro branch.
- No fim de cada tarefa, antes do push, perguntar: "é push para teste ou main?"

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
> Última atualização: 2026-06-23 (Sessão 16 — Batch 4 & Batch 5: Auditoria Completa + Correção de Cache Locks)

## Sessão 17 — Fotos R2 + Upload Mobile + Lightbox (resumo)
- **Cloudflare R2 para fotos persistentes**: Railway tem filesystem efémero — ficheiros perdem-se no deploy. Integrado R2 (S3-compatible) via `league/flysystem-aws-s3-v3`. Disco `r2` configurado em `config/filesystems.php`. Todos os 8 campos `FileUpload` e `ImageEntry` do `DailyRecordResource` usam `->disk('r2')`.
- **Fixes de upload**: corrigido `TypeError` no `DailyRecordObserver` (`pool_id` string→int); criado diretório `livewire-tmp` no `docker-entrypoint.sh`; `LIVEWIRE_TMP_DISK` mantido em `local` (R2 não suporta mime_type durante validação Livewire).
- **CSP atualizada**: `img-src` inclui `https://*.r2.dev`; `script-src`/`style-src` incluem `https://cdn.jsdelivr.net` (para GLightbox).
- **Limites de upload para mobile/iPhone HEIC**: `upload_max_filesize=25M` no Dockerfile e `.user.ini`; nginx `client_max_body_size=100M`; `maxSize(20480)` nos FileUpload.
- **Lightbox (GLightbox)**: carregado via CDN no `AdminPanelProvider` (render hook `HEAD_END`). Ao clicar numa foto abre lightbox a ecrã inteiro com pinch-to-zoom mobile. Auto-wired a todas as `ImageEntry` do painel via MutationObserver.
- **Vista do registo**: row click na tabela abre modal com infolist completo (todos os campos + fotos); botão "Editar" no header abre página de edição.
- **Commits**: `2c238ba`, `7ae5f95`, `6f7cba7`, `e3246e4`.

## Sessão 16 — Batch 4 & Batch 5: Auditoria Completa + Correção de Cache Locks (resumo)
- **Correção de Cache Locks (Bug de Produção)**: Corrigido o erro `relation "cache_locks" does not exist` em produção adicionando a migração `2026_06_23_000006_ensure_cache_locks_table_exists.php`. Esta migração garante a criação da tabela `cache_locks` necessária para locks atómicos do cache em PostgreSQL.
- **Arquivamento em Cascata (Batch 4)**: Atualizado o comando `archive:daily-records` para realizar cópia dos registos de `record_additions` e `record_photos` para as novas tabelas de arquivo antes de remover os registos originais. Adicionada a migração `2026_06_23_000004_create_record_additions_and_photos_archives.php` e o teste unitário robusto `ArchiveDailyRecordsTest.php` (com correção de compatibilidade de `agua_modo` nulo no SQLite via migração `2026_06_23_000005_make_agua_modo_nullable_in_archive.php`).
- **Políticas e Segurança de Dados (Batch 4)**: Criadas as políticas de acesso Eloquent `DailyRecordPolicy`, `IncidentPolicy` e `StockInstallationPolicy` para verificação de permissões e restrições. Refatoradas as referências de cargos por strings para o uso central das constantes da classe `UserRole` em Filament (`UserResource`, `FilterCheckResource`, `IncidentResource` e `ListUsers`).
- **UX/UI Premium & Acessibilidade WCAG AA (Batch 5)**:
  - Adicionado painel com barras de progresso visual dinâmicas no topo do painel principal (`PainelPiscinasWidget` / `painel-piscinas.blade.php`), exibindo a percentagem de registos diários preenchidos hoje e conformidade com limites legais.
  - Refatorado todo o estilo CSS no widget do painel para usar Custom Properties (variáveis CSS) nos blocos `:root` e `.dark`, garantindo compatibilidade elegante e automática com Dark Mode.
  - Substituídos os pesos inválidos de fonte (de `650` para `600`) e corrigida a opacidade e rácio de contraste da classe `.mmc-metric-label` (`0.72` em light mode e `0.8` em dark mode) para cumprir as regras WCAG AA.
  - Configurada a fonte premium **DM Sans** em Filament (`AdminPanelProvider.php`) e tema principal (`resources/css/app.css`).
  - Implementado o rascunho de auto-save/restore do formulário via `localStorage` no ficheiro `resources/js/app.js` para o formulário `/daily-records/create`, com auto-limpeza aquando do evento de gravação.
- **Commits**: `dfe810a` e `e7e76bf` (pushed para `test` e `main`).

## Sessão 15 — Deploy Railway PostgreSQL + fix JSONB notifications (resumo)
- **Produção em Railway**: Transição de SQLite (dev) para PostgreSQL 16 (produção). Produção: `https://piscinasmmcrespo.up.railway.app`. Testes/Staging: `https://piscinasmmcrespo-testes.up.railway.app`.
- **Erro 500 no dashboard diagnosticado**: Coluna `notifications.data` era `TEXT` em vez de `JSONB`. Filament filtra com `data->>'format' = 'filament'` — o operador `->>` (JSON extraction) requer JSONB em PostgreSQL. Erro: `SQLSTATE[42883]: Undefined function` + `operator does not exist: text ->> unknown`.
- **Fix migração**: `2026_06_19_000001_fix_notifications_data_column_jsonb.php` executa `ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb` em produção. Migração original (`2026_06_11_172454_create_notifications_table.php`) corrigida para criar `jsonb` em vez de `text`.
- **Login funcional**: Utilizador `daniel@mmcrespo.pt` (password rotacionada em produção Railway). Seeder `UserSeeder` corre em `docker-entrypoint.sh` com `db:seed --force` — utiliza `ADMIN_PASSWORD_DANIEL` e `ADMIN_PASSWORD_MARCIO` de env vars. Em dev local, fallback para `dev_changeme_*`.
- **TrustProxies corrigido**: Bootstrap já tinha `trustProxies(at: '*')` para Railway (reverse proxy, terminates SSL externally). Assets carregam via HTTPS corretamente.
- **Próximos passos para go-live**: (1) Mudar password de `daniel@mmcrespo.pt` via interface `/primeiro-acesso` ou tinker antes de lançar; (2) Verificar permissões de roles (admin, tecnico, nadador_salvador, gestor); (3) Testar fluxo completo (login → registos → dashboard → notificações); (4) Configurar domínio custom (mmcrespo.leiria.com?); (5) Configurar backups automáticos da DB PostgreSQL.
- **Commit**: `730a9d3` (fix: cast notifications.data from TEXT to JSONB for PostgreSQL).

## Sessão 14 — Popup pós-registo + transferência warehouse (resumo)
- **Popup pós-registo**: Ao criar registo diário, notificação persistente "Registo guardado! O que pretende fazer a seguir?" com botões "Novo Registo" (fecha e fica no form) e "Ir para o Dashboard" (/admin). Implementado via `$this->dispatch('notificationSent', notification: $notificacao->toArray())` em `CreateDailyRecord::create()`.
- **Modal de confirmação**: Botão "Criar" mostra diálogo de confirmação "Confirmar registo" antes de gravar (fix: mudado de `->action('create')` string para `->action(fn () => $this->create())` closure — string bypassa o sistema de modal).
- **Fix FK delete Instalação**: `Installation::boot()` elimina incidentes em cascata antes de apagar a instalação (tabela `incidents` não tinha `cascadeOnDelete()`; model observer resolve).
- **Transferência warehouse→instalação**: Ação "Saída" do `StockWarehouseResource` substituída por "Transferir p/ Instalação" — debita warehouse com `lockForUpdate()`, credita instalação (cria se não existe), cria `StockWarehouseLog` (saida) + `StockInstallationLog` (entrada). Notificação de sucesso.
- **Unidades reais nos logs de stock**: `StockInstallationLogResource` e `StockWarehouseLogResource` agora mostram a unidade do produto (ex: "kg", "L") em vez de "unid." fixo. Usado `formatStateUsing` + `.unidade` do model.
- **UX decimal**: app.js bloqueia tecla vírgula em campos `type="number"` ou `inputmode="decimal"` (força ponto decimal). Fallback para paste e IME (teclados móveis).
- **Ação corretiva (refactor)**: Removida do registo diário (campo obrigatório quando havia parâmetros fora dos limites). Agora está no Repeater de "Adições de Químicos" como campo de texto opcional — a ação corretiva é descrita junto com o produto adicionado, não no registo geral. Migrações: `add_acao_corretiva_to_record_additions`, `remove_acao_corretiva_from_daily_records`.
- **Commits**: 844324f (popup pós-registo + transferência warehouse), 54c61b9 (refactor ação corretiva), 6eb5e9c (gitignore). **ESTA VERSÃO ESTÁ PRONTA PARA TESTES EM LEIRIA**.

## Sessão 13 — Histórico de stock + validação de quantidade (resumo)
- **Histórico de transações**: `StockInstallationLogResource` (entrada/consumo por instalação) + `StockWarehouseLogResource` (entrada/saída central). Ambas com tabelas filtráveis (tipo movimento, produto, instalação, fornecedor), utilizador, data/hora. Ícones trending-down, grupo "Stock".
- **Validação de quantidade**: ao selecionar um produto em "Adições de Químicos", mostra a quantidade disponível da instalação; tenta inserir mais → erro "Quantidade insuficiente. Disponível: X unidades". Métodos privados `quantidadeDisponivel()` + `helperQuantidadeDisponivel()`.
- **UX por papel (audit completo)**: Nadador-Salvador vê apenas Informação Geral + Análises NS + Observações (secções Bomba, Filtros, Contador, Tanque, Nossas Análises, Químicos ocultadas); Técnico tem form completo; Admin tudo + históricos de stock + activity log. Forma prática e sem distrações.
- **Fix: jobs table restaurada** — migração `2026_06_15_000002_restore_jobs_table` (tabela dropada por engano em sessão 11; `QUEUE_CONNECTION=database` + notificações de stock agora funcionam). Idempotente (verifica se existe antes de criar).
- **Commits desta sessão**: `4daaca3` (dead code + PostgreSQL), `c20910d` (CSP + backups + NS form fix + PRODUCAO.md), `c8aa6cc` (jobs table fix), `e238237` (stock history + quantity validation).

## Sessão 12 — Limpeza de dead code + preparação PostgreSQL (resumo)
- **Dead code removido**: `GeminiAnalysisService`, `OcrVisionService` (serviços IA/OCR nunca chamados); `IncidentPool`, `IncidentProduct` (models + tabelas dropadas, relações `piscinas()`/`produtosIncidente()` em `Incident` removidas); `DailyRecordWizardSubmitTest` (testava wizard apagado), `GeminiAnalysisServiceTest` (testava serviço morto).
- **$fillable limpos**: `FilterCheck` (removidos `resultado_ia`, `descricao_ia`); `RecordPhoto` (removido `resultado_ocr` + cast).
- **Migration de limpeza**: `2026_06_15_000001_clean_dead_ai_columns` — dropa `record_photos.resultado_ocr`, `filter_checks.resultado_ia`/`descricao_ia` se existirem (idempotente; cobre fresh migrate em PostgreSQL onde a migração original as criaria). Aplicada ✓
- **PostgreSQL pronto**: `config/database.php` já tinha `pgsql` configurado corretamente. `enum()` é compatível (check constraint em Laravel 12). `unsignedTinyInteger('attempts')` na migration `jobs` corrigido para `unsignedSmallInteger` (única incompatibilidade real com PostgreSQL encontrada). Para fazer a transição: alterar `DB_CONNECTION=pgsql` + credenciais no `.env`.
- **declare(strict_types=1)** adicionado a `FilterCheck`, `RecordPhoto`, `Incident` (alinhamento com regra do projeto).
- **Pendente para produção**: passwords dos seeders, CSP no `SecurityHeaders`, `PRODUCAO.md`, backups automáticos.

## Sessão 11 — Revisão, limpeza e correções (resumo)
- **Kanban "real-time"**: é o polling de 60s do `QuadroOperacionalWidget` que atualiza. O `AlertasService::limparMemo()` que tinha sido adicionado era decorativo (memo é por-pedido; a request seguinte já recalcula) — **removido** o método e a chamada em `afterCreate()`.
- **Fotos bomba/tanque**: campos opcionais `bomba_foto` e `tanque_foto` no `DailyRecord` (migração idempotente), visíveis no `ViewDailyRecord`. (não usados no PDF — são evidência fotográfica.)
- **Revisão de 4 frentes** (segurança, código, produção, PDF) via subagentes. PDF verificado funcional (gera %PDF, coluna Conforme correta, exclui correções). Segurança acima da média (sem segredos no git, autorização por role OK, uploads privados+MIME, sessões endurecidas; falta CSP).
- **Limpeza do repo**: removidos 42 ficheiros-lixo commitados (heredocs mal interpretados nos `git add -A` das sessões anteriores) + `*.zip` no `.gitignore`. BD de registos de teste limpa.
- **4 bugs corrigidos**: (1) `cloroCombinado` dava negativo com `cloro_total` null → agora null-safe + `notificarNaoConformidade` com guards de null; (2) `app.js` montava observers/listeners em duplicado + `setInterval(500ms)` eterno → montagem única + observer-only + debounce no auto-scroll; (3) `agua_modo` null fechava alerta de torneira indevidamente → trata null como estado desconhecido; (4) `limparMemo` morto removido.

## Sessão 10 — Correlações fechadas + limpezas (resumo)
Leitura completa do codebase (modelos, serviços, recursos, widgets, migrações, seeders, testes, JS, configs). Correções feitas e verificadas:
- **Volumes das piscinas no `PoolSeeder`** (900/600/50/170/170 m³) — a calculadora de dosagem dividia por volume null e devolvia sempre "Cálculo impossível". Aplicado também às piscinas já existentes na BD.
- **Correlação torneira (`agua_modo` → `tap_alerts`) ligada**: `CreateDailyRecord::gerirTorneira()` abre alerta quando `agua_modo='on_com_agua'` (decisão Daniel: só este estado), fecha-o no registo seguinte que mude o estado. Novo modelo `TapAlert`. A tabela `tap_alerts` (sessão ~6) finalmente recebe escritas. Verificado: 1→0 alertas.
- **Ciclo de vida dos incidentes**: migração com `status` (aberto|resolvido) + `resolvido_em/por/resolucao`. Ação "Resolver" no `IncidentResource`, coluna de estado, filtro (default 'aberto'), secção de resolução na vista. `AlertasService` só mostra incidentes não resolvidos (janela 30 dias). Resolver na origem faz o cartão auto-resolver no Kanban. Verificado: SIM→não.
- **Limpezas**: campos de IA mortos (`resultado_ia`/`descricao_ia`) removidos do `FilterCheckResource`; `CloroPhChartWidget` corrigido (transparencia → "Turbidez" FNU, max 5, alinhado com o resto da app).
- **Pendente (decisões já tomadas, por implementar se quiseres)**: nada bloqueante. Próximos candidatos: testes para stock/PDF, CSP no SecurityHeaders, `PRODUCAO.md`.

## Sessão 9 — Dashboard centrado no técnico (resumo)
Decisão do Daniel: ao abrir a app vê-se (1) valores das piscinas, (2) Kanban, (3) stock baixo. **O resto saiu do dashboard.**
- **Ordem**: `PainelPiscinasWidget` (sort -30) → `QuadroOperacionalWidget` (-20) → `StockBaixoWidget` (-10).
- **Apagados**: `BoasVindasWidget` (hero da sessão 8 + componente `mmcHero` do app.js), `StatsOverviewWidget`, `SensoresHannaWidget` (+ views). `AccountWidget` desregistado.
- **Sondas Hanna integradas nos cartões das piscinas**: linha "Sonda" discreta (pH avaliado contra CN 14/DA, ORP mV, T água) com idade humanizada e aviso stale >15 min. Os dados manual + sensor da mesma piscina vivem agora no mesmo cartão.
- **CTA por piscina**: botão "Registar" em cada cartão → `create?pool=ID`; o Select do formulário dá prioridade a `request()->integer('pool')` sobre o smart default. "Registar agora" global no header da secção (substitui o CTA do hero).
- **Leituras em falta** nos cartões mostram "—" neutro (classe `mmc-na`) em vez de "0,00".
- Verificado no preview: ordem correta, `?pool=4` pré-seleciona Maceira, cartão "fora dos limites" auto-resolveu-se no Kanban quando o null-guard eliminou a condição (mecanismo provado em situação real), consola limpa.

## Sessão 8 — Quadro Kanban + polish (resumo)
- **`QuadroOperacionalWidget`** substitui o `AlertasOperacionaisWidget`: Kanban com 3 colunas (Para tratar / Em tratamento / Resolvido hoje), drag-and-drop (SortableJS, `delay` em touch) + botões de fallback tátil, GSAP para entrada/movimento. Estado persistido em `alert_states` (chave estável `tipo|id|data`, snapshot JSON, poda >7 dias). Cartões cuja condição desaparece passam sozinhos a "Resolvido (automático)".
- **`AlertasService`**: cálculo central dos alertas (memoizado por pedido), partilhado pelo Kanban e pelo hero. Leituras null em registos legados não geram falsos "pH 0,00".
- **`BoasVindasWidget`** (hero): saudação, data pt, "X/Y piscinas conformes" + "N alertas por tratar" (count-up GSAP), CTA "Registar agora" 48px. Ondas SVG/CSS, sem WebGL (bateria em campo).
- **Verificado no browser** (preview desktop + 375px): Kanban move e persiste, mobile usa carrossel scroll-snap, consola limpa. Gráfico multi-métrica normalizado validado por probe (pH 7,22→29%, temp 27,2→120%).
- **Fixes**: payload do gráfico emitia `Js::from` mas o app.js fazia `JSON.parse` (caminho de update partido) → agora `@json`; `APP_LOCALE=pt_PT`; índices `daily_records(pool_id, registado_em)` e `corrige_registo_id`; tabela `notifications` + `->databaseNotifications()` no painel.
- **npm**: + `gsap`, `sortablejs`.

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

## Stack Técnica e Integrações
- **Framework:** Laravel 12 LTS (PHP 8.5.6 NTS VS17 x64 em `C:\php\php.exe`. NÃO usar Herd Lite).
- **Admin/UI:** Filament 3.3.x.
- **DB:** SQLite (Dev) / PostgreSQL (Produção).
- **Roles:** `spatie/laravel-permission` (Admin, Técnico, Nadador Salvador).
- **Audit Trail:** `spatie/laravel-activitylog` + `rmsramos/activitylog` instalados.
- **PDF:** `barryvdh/laravel-dompdf`.
- **Charts:** Chart.js via npm carregado por render hook.
- **Sensores (Hanna Cloud):** Integrado com sensores Hanna BL132. Possui tabela `sensor_readings`, `HannaDeviceResource`, `SensoresHannaWidget` e cron job `hanna:sync`.

---

## ✅ TAREFAS IMEDIATAS — TODAS CONCLUÍDAS (Sessão 7)
1. ✅ `$isDiscovered = false` no `CloroPhChartWidget` (já estava feito em sessão anterior).
2. ✅ `transparencia` → `->step(1)` no formulário principal e na ação "Corrigir" (coluna INTEGER).
3. ✅ `->after(...)` pendurados removidos das duas migrações + guards `Schema::hasColumn` adicionados (idempotência PostgreSQL/MySQL).
4. ✅ `ActivitylogPlugin` (rmsramos) ativado no `AdminPanelProvider` — menu "Activity Logs" visível só a admin.
5. ✅ Migração `2026_06_10_090000_drop_orphan_columns_from_daily_records` criada e aplicada (11 colunas órfãs removidas). A página WIP `RegistoDiario` e o wizard Livewire mortos foram apagados.
6. ✅ `ResizeObserver` com debounce 100ms em `resources/js/app.js` (+ `destroy()` para limpar). `optimize:clear` + `npm run build` executados.

**Limpeza adicional:** apagados `test_gemini*.php`, `test_models.php`, `$series`; raiz `/` redireciona para `/admin`; `config/services.php` ganhou as chaves `gemini` e `hanna`; `hanna:sync` agendado (15 min) em `routes/console.php`; tabela `notifications` criada + sino de notificações ativo.

---

## Regras de Código & Decisões Técnicas (Strict Rules)
1. **Tipagem:** Todo o PHP usa tipagem estrita (`declare(strict_types=1);`).
2. **Formulários:** Layouts prioritários com `Section::make()->collapsible()`. Ficheiros temporários usam `->dehydrated(false)`. Validações reactivas (`extraAttributes`) avaliam apenas campos existentes na respetiva Section para prevenir null pointers.
3. **Database:** Stock obriga a `DB::transaction()` + `lockForUpdate()`. Colunas de migração adicionadas a tabelas existentes usam `if (!Schema::hasColumn(...))` (idempotência).
4. **Operações Append-Only:** `DailyRecord`, `FilterCheck` e `Incident` não apagam registos originais nas edições dos técnicos. Cria-se novo registo com `e_correcao=true`, `corrige_registo_id` e `razao_correcao`. Gráficos usam `whereDoesntHave('correcoes')`.
5. **Segurança (Hardening):** Middleware `SecurityHeaders` configurado. Sessões `secure=true` (produção), `http_only=true`, `same_site=strict`.
6. **Interface/Gráficos:** `CloroPhChartWidget` usa `spanGaps = false` (null para dias sem dados). Grelhas de widgets usam `minmax(min(100%, Npx), 1fr)`.

---

## Estado do Desenvolvimento

| Plano | Estado | Descrição |
|---|---|---|
| Planos 1 a 3.9 | **CONCLUÍDO** | DB, Filament, Dashboards, Mobile/PWA, Análises Avançadas, Refatoração Caminho da Água, Segurança (Headers/Sessões) e Log de Atividades. Limpeza PSR-4 completa. |
| Plano 4 — Inteligência Artificial | **FORA DO ÂMBITO** | Decisão do Daniel (2026-06-15): IA/OCR não será implementado. Serviços `Gemini`/`OcrVision` mantidos no código mas sem uso. |
| Plano 5 — Relatórios PDF (CN 14/DA) | **CONCLUÍDO** | `RelatorioPdf` (grupo Operação) + `pdf/livro-sanitario.blade.php`: coluna Conforme ✓/✗, assinaturas, paginação, multi-piscina, exclui correções. `barryvdh/laravel-dompdf` instalado. Geração testada (PDF válido). |
| Plano 6 — UI de Audit Trail | **CONCLUÍDO** | `ActivitylogPlugin` ativo. |
| Plano 7 — Transformação UX (Sessão 7) | **CONCLUÍDO** | Ver secção abaixo. |

---

## Sessão 7 — Transformação UX (benchmark contra Skimmer, Pool Brain, FixMyPool, Orenda, Pool Shark H2O)

**Registo Diário Turbo (`DailyRecordResource` + `CreateDailyRecord`):**
- **Semáforo de conformidade em tempo real**: hint colorido + ícone por campo (pH, cloros, temperatura, turbidez e equivalentes NS) que reage ao valor ao sair do campo. Fonte única: `DailyRecord::avaliarConformidade()` (verde/amarelo/vermelho/neutro). Temperatura usa `Pool::temp_min/max`; cloro total avalia o combinado.
- **Ação corretiva obrigatória** (`acao_corretiva`, nova coluna) — campo aparece e fica required quando há parâmetro fora do limite legal (padrão "failed → action"). Admins recebem notificação `danger` na base de dados.
- **Smart defaults / lookback**: piscina pré-selecionada do último registo do utilizador; bomba/água/tanque herdam o último estado da piscina (reativo); métricas mostram "Último: X (dd/mm HH:mm)" no helperText; contador valida que não anda para trás.
- **Adições de químicos descontam stock** da instalação (`DB::transaction` + `lockForUpdate` + log `consumo`); stock insuficiente não bloqueia o registo (desconta até zero + avisa).
- **Filtros na tabela**: piscina, intervalo de datas, ternário de correções. Contagem "n/total" por secção. `inputmode=decimal` nos numéricos.

**Dashboard Exception-First:**
- Novo `AlertasOperacionaisWidget` (topo, full width): piscinas sem registo hoje, parâmetros fora dos limites, incidentes recentes, stock baixo, torneiras abertas — cada um clicável para a ação. Disciplina de cor anti alarm-fatigue (vermelho só para violação legal). Estado "tudo em ordem" neutro.
- `PainelPiscinasWidget`: valores conformes em tom neutro (só o fora-de-limite é vermelho) + "há Xh".
- `CloroPhChartWidget` modo multi-métrica: eixo único normalizado 0–100% do intervalo legal (Prompt 1 resolvido), tooltips com valores reais.

**Design:** light mode por defeito (legibilidade ao sol), cores `danger`/`warning`/`gray` no tema, alvos táteis ≥48px nos alertas.

---

## Checklist OBRIGATÓRIA antes de Produção
- [ ] `APP_DEBUG=false` e `APP_ENV=production` no `.env`.
- [ ] Trocar passwords de todos os seeders (`database/seeders/UserSeeder.php`).
- [ ] `APP_URL` com domínio real e HTTPS ativado.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] Migrar para PostgreSQL: alterar `DB_CONNECTION=pgsql` + `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` no `.env`. `config/database.php` já tem a configuração `pgsql` pronta.
- [ ] Adicionar CSP ao `SecurityHeaders` middleware.
- [ ] Configurar rotina de Backups automáticos da DB.

---

## Prompts Prontos para a Próxima IA

### Prompt 1 — Gráfico interativo: normalizar parâmetros (% do intervalo)
**Tarefa:** No `CloroPhChartWidget` (modo 'multi-metrica' para 1 piscina), normalizar os dados no eixo Y para uma escala percentual (0–100%) calculada com base nas constantes `METRICAS[]` do model. Isto evitará o esmagamento das linhas (pH vs Cloro). O eixo Y deve mostrar "% do intervalo", mas os tooltips mantêm os valores reais (ex: "pH 7.4"). Compatibilidade total com modo 'mono-metrica'. 

### Prompt 2 — Plano 5: Relatórios PDF regulamentares (CN 14/DA)
**Tarefa:** Utilizando `barryvdh/laravel-dompdf`, criar `app/Filament/Pages/RelatorioPDF.php` sob o navigation group 'Operação'. Requisitos:
1. Formulário com Select de Instalação, Select de Piscina, DatePicker Início/Fim. Botão de exportação.
2. View `resources/views/pdf/livro-sanitario.blade.php`: Tabela de registos diários com todas as métricas obrigatórias.
3. Adicionar coluna "Conforme" (✓/✗) validando contra limites `DailyRecord::PH_MIN`, etc.
4. Excluir registos corrigidos via `whereDoesntHave('correcoes')`.
5. Estilos em CSS inline (limitação do dompdf), preto e branco, com paginação e área de assinatura.

### Prompt 3 — Migração para PostgreSQL + Documentação
**Tarefa:** Adaptar ficheiros de migração para conformidade estrita PostgreSQL (tinyint vs boolean, remoção de `.sqlite` refs). Criar o ficheiro `PRODUCAO.md` na raiz com o checklist de *deploy* e comandos de cache otimizada.