# PRD — Sessão 5: Correções + Novo Registo Diário Operacional

> Estado: AGUARDA SIGN-OFF do Daniel antes de construir.
> Data: 2026-06-01

---

## Problema

O dashboard, os gráficos e a página de stock têm bugs/lacunas. Além disso, o registo
diário atual (11 campos simples) não reflete o workflow operacional real do técnico no
terreno: verificação de bomba, filtros/retrolavagem, contador de água, tanque de
compensação, análises, aspeto da água e adições de produtos com dose calculada.

## Critérios de Sucesso

1. Dashboard mostra estado atual das piscinas + permite criar registo diário direto.
2. Widget "Welcome/Sign out" removido do corpo do dashboard; saudação movida para cabeçalho.
3. Registo diário = wizard guiado multi-passo (igual em desktop e mobile).
4. Página de stock de armazém abre sem erro e está ligada à BD (entradas/saídas/transferências atualizam quantidade).
5. Gráficos funcionam: 1 piscina de cada vez, todas as variáveis dessa piscina no mesmo gráfico, leitura prática.
6. Página dedicada de gráficos em tamanho grande.
7. Novo role "Gestor" (abaixo de patrão/admin e técnico): só visualiza, não cria registos.

## Constraints

- Laravel 12 + Filament 3.3 + PostgreSQL (dev em SQLite) + Chart.js.
- Append-only no livro sanitário (técnico/NS criam e corrigem; só admin edita/elimina).
- Limites CN 14/DA centralizados em constantes no model DailyRecord.
- Sem em-dashes, voz ativa, código funcional primeiro.
- Projeto 2+ anos: decisões por long-term, rapidez, segurança, praticidade.

---

## Decisões tomadas (com sign-off do Daniel via AskUserQuestion)

- **Modelo de dados: HÍBRIDO.**
  - Valores escalares 1-1 → colunas diretas em `daily_records` (rápido, sem JOIN).
  - Coleções N-1 → tabelas filhas existentes (`record_photos`, `record_additions`, `filter_checks`).
- **Dose de cloro:** adicionar `concentracao` (%) ao Product e `volume` (m³) ao Pool. Fórmula `Volume × Dose ÷ %`. **Sugestão EDITÁVEL** pelo técnico.
- **Ordem de trabalho:** QUICK WINS primeiro, depois o grande (registo diário).
- **Formato:** Wizard nativo Filament, sempre (desktop + mobile).
- **Parâmetros da água:** passo próprio no wizard (continua a ser o registo legal).
- **Role Gestor:** só visualização (registos diários, incidentes, valores tempo real e passados).

---

## Diagnóstico do estado real (auditoria feita)

- `app.js` ESTÁ compilado (`public/build/assets/app-DcEwaSF5.js`, 29 maio).
- Models `StockWarehouseLog` / `StockInstallationLog` existem.
- Pages do StockWarehouseResource existem (List/Create/Edit/View).
- StockWarehouseResource e StockInstallationResource parecem corretos no código.
- Log Laravel só tem erro ANTIGO (28 maio) de APP_KEY — não relacionado com stock.
- **PENDENTE:** reproduzir o erro atual da página de stock em runtime (provável: dados em falta, seeder não corrido, ou exceção de migração SQLite). Diagnosticar antes de "consertar".
- Pool NÃO tem coluna `volume`. Product NÃO tem `concentracao`/`%`. Precisam de migração.
- `record_photos.type` é `enum('ns','tecnico')` — INSUFICIENTE. Expandir para todos os tipos de foto.
- `transparencia` é INTEGER (decisão antiga, não mexer no tipo).

---

## Plano de Execução

### FASE A — Quick Wins (verificáveis cedo)

**A1. Remover AccountWidget do dashboard + saudação no cabeçalho**
- `AdminPanelProvider.php`: remover `Widgets\AccountWidget::class` da lista de widgets.
- Adicionar saudação "Bem-vindo {utilizador}" via render hook no cabeçalho (PanelsRenderHook).

**A2. Role Gestor (só leitura)**
- `RolesAndPermissionsSeeder.php`: criar role `gestor`.
- Resources: `canViewAny`/`canView` true para gestor; `canCreate`/`canEdit`/`canDelete` false.
- Gestor vê: DailyRecord, Incident, gráficos, painel piscinas. Não vê: stock edição, users.
- Criar utilizador seed `gestor@mmcrespo.pt`.

**A3. Fix página Stock Armazém**
- Reproduzir erro em runtime primeiro (php artisan + abrir rota / tinker).
- Corrigir a causa raiz concreta (não adivinhar).
- Confirmar que entrada/saída/transferência atualizam quantidade via transação.
- NOTA: "transporte para outra piscina" = transferência armazém → instalação. Verificar se essa action existe; se não, criar (saída do armazém + entrada na instalação, atómica).

**A4. Fix gráficos dashboard**
- Reescrever para: SELECT de 1 piscina (não multi), todas as variáveis dessa piscina num só gráfico.
- Eixo duplo já existe; manter mas focar legibilidade (prático > bonito).
- Confirmar render real no browser.

**A5. Página dedicada de gráficos grande**
- Já existe `CloroPhChartWidget` com `$isDiscovered = false` mas NÃO existe a Page (pasta Pages/ vazia!).
- Criar `app/Filament/Pages/AnaliseParametros.php` + view. (CLAUDE.md dizia existir — NÃO existe. Discrepância documentada.)

**A6. Registo diário visível no dashboard**
- PainelPiscinasWidget já mostra estado. Adicionar botão/ação "Novo Registo Diário" proeminente no topo do dashboard.

### FASE B — Novo Registo Diário (Wizard) — a peça grande

Migrações novas:
- `daily_records`: + `bomba_estado` (enum ferrada/desferrada), `tanque_nivel` (integer 0-100),
  `torneira_modo` (enum automatico/ligada/desligada), `torneira_tem_agua` (boolean),
  `aspeto_agua` (enum visivel/semi_turva/turva), `contador_leitura` (decimal),
  `fez_retrolavagem` (boolean).
- `pools`: + `volume` (decimal m³). Seed: 900/600/50/170/170.
- `products`: + `concentracao` (decimal %, nullable).
- `record_photos.type`: expandir enum (bomba, pressao_filtro, lavagem, enxaguamento, posicao_normal,
  contador, tanque, analise_ns, analise_nossa, turbidimetro, observacao).

Wizard steps:
1. Bomba — foto + estado ferrada/desferrada (água a circular?).
2. Filtros — foto pressão; se divergente → fez_retrolavagem; se sim → 3 fotos (lavagem, enxaguamento, normal) obrigatórias.
3. Contador — foto + leitura; estado torneira (modo + com/sem água).
4. Tanque compensação — foto + nível % (0-100).
5. Análises NS — foto (data/hora).
6. Análises nossas — foto (data/hora) + Parâmetros da Água (pH, cloro livre, cloro total, temp, transparência) — CN 14/DA.
7. Aspeto da água — foto turbidímetro + visivel/semi-turva/turva.
8. Adições — produtos + dose sugerida editável (Volume×Dose÷%).
9. Observações — texto + foto opcional.

### FASE C — Verificação

- `php artisan filament:clear-cached-components && npm run build`.
- Abrir cada página no browser, confirmar sem erro.
- Testar criar um registo diário completo no wizard.
- Confirmar stock atualiza.
- Confirmar gestor só vê, não edita.
- Atualizar CLAUDE.md com o estado real.

---

## Open Questions (resolver durante a execução)

- Erro concreto da página de stock (diagnosticar em runtime).
- "Pressão divergente" dos filtros: é comparação entre dois manómetros? Quantos? (confirmar com Daniel quando chegarmos à Fase B step 2).
- Dose de cloro: a "Dose (mg/L)" alvo vem de onde? Input do técnico ou valor fixo por piscina?
