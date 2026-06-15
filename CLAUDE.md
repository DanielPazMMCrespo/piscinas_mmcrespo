# Contexto Completo — Projeto Piscinas MMCrespo
> Última atualização: 2026-06-15 (Sessão 11 — Revisão completa + limpeza do repo + correção de 4 bugs; IA/OCR fora do âmbito)

## Sessão 11 — Revisão, limpeza e correções (resumo)
- **Kanban "real-time"**: é o polling de 60s do `QuadroOperacionalWidget` que atualiza. O `AlertasService::limparMemo()` que tinha sido adicionado era decorativo (memo é por-pedido; a request seguinte já recalcula) — **removido** o método e a chamada em `afterCreate()`.
- **Fotos bomba/tanque**: campos opcionais `bomba_foto` e `tanque_foto` no `DailyRecord` (migração idempotente), visíveis no `ViewDailyRecord`. (não usados no PDF — são evidência fotográfica.)
- **Revisão de 4 frentes** (segurança, código, produção, PDF) via subagentes. PDF verificado funcional (gera %PDF, coluna Conforme correta, exclui correções). Segurança acima da média (sem segredos no git, autorização por role OK, uploads privados+MIME, sessões endurecidas; falta CSP).
- **Limpeza do repo**: removidos 42 ficheiros-lixo commitados (heredocs mal interpretados nos `git add -A` das sessões anteriores) + `*.zip` no `.gitignore`. BD de registos de teste limpa.
- **4 bugs corrigidos**: (1) `cloroCombinado` dava negativo com `cloro_total` null → agora null-safe + `notificarNaoConformidade` com guards de null; (2) `app.js` montava observers/listeners em duplicado + `setInterval(500ms)` eterno → montagem única + observer-only + debounce no auto-scroll; (3) `agua_modo` null fechava alerta de torneira indevidamente → trata null como estado desconhecido; (4) `limparMemo` morto removido.
- **Pendente para produção (não bloqueante para uso local)**: achatar a saga de migrações (largam-se `jobs`/`cache_locks`/pivots de incidente que ficam minas latentes), `enum()`→`string()` para PostgreSQL, passwords dos seeders, `PRODUCAO.md`, backups, CSP. Dead code IA (`GeminiAnalysisService`/`OcrVisionService` + colunas `*_ia`/`resultado_ocr`) por limpar.

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
- [ ] Trocar passwords de todos os seeders.
- [ ] `APP_URL` com domínio real e HTTPS ativado.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] Migrar infraestrutura para PostgreSQL.
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