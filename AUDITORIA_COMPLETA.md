# Auditoria Completa — Piscinas MMCrespo
**Data:** 2026-06-23 | **Branch:** `claude/distracted-stonebraker-05909c` (commit `1a27c57`)
**Realizada por:** 3 agentes senior em paralelo — Código, UI/UX, Frontend

---

> **Como ler este documento:**
> - 🔴 Crítico — pode causar crash, corrupção de dados ou falha de segurança em produção
> - 🟠 Alto — impacto real no utilizador ou na integridade do sistema
> - 🟡 Médio — problema funcional ou de qualidade com workaround
> - 🔵 Baixo — melhoria de qualidade, polish, ou edge case raro
> - 💡 Sugestão — nova funcionalidade ou melhoria

---

## ÍNDICE

1. [Auditoria de Código](#1-auditoria-de-código)
   - 1.1 [Bugs Críticos e Altos](#11-bugs-críticos-e-altos)
   - 1.2 [Performance](#12-performance)
   - 1.3 [Segurança](#13-segurança)
   - 1.4 [Funcionalidade Incompleta](#14-funcionalidade-incompleta--edge-cases)
   - 1.5 [Qualidade de Código](#15-qualidade-de-código)
   - 1.6 [Testes](#16-testes)
2. [Auditoria de UI/UX](#2-auditoria-de-uiux)
3. [Auditoria de Frontend](#3-auditoria-de-frontend)
4. [Roadmap Consolidado](#4-roadmap-consolidado)

---

## 1. Auditoria de Código

> **Auditor:** Agente Senior Dev — Laravel/Filament/PHP 8.5
> **Resumo:** Codebase bem estruturada. Existem 4 bugs críticos que precisam de ser corrigidos antes de produção.

**Distribuição:** Crítico: 4 | Alto: 9 | Médio: 12 | Baixo: 8

### 1.1 Bugs Críticos e Altos

#### 🔴 BUG-01 — `MetricsService::expose()` usa `$this` em método estático
**Ficheiro:** `app/Services/MetricsService.php`, linha ~185
**Fix:** `$version = self::getAppVersion();` (2 min)

---

#### 🔴 BUG-02 — Race condition no `descontarStock`
**Ficheiro:** `app/Filament/Resources/DailyRecordResource/Pages/CreateDailyRecord.php`
**Problema:** `firstOrCreate` + `lockForUpdate` são operações separadas — deixa janela de race condition.
**Fix:** Usar `DB::statement('INSERT ... ON CONFLICT DO NOTHING')` antes do lock. (30 min × 2 sítios)

---

#### 🔴 BUG-03 — Middleware Hanna bloqueia request HTTP
**Ficheiro:** `app/Http/Middleware/EnsureHannaReadingsAreFresh.php`
**Problema:** `Artisan::call('hanna:sync')` é síncrono — bloqueia até 15 segundos.
**Fix:** Usar `dispatch()->afterResponse()` (1 hora)

---

#### 🔴 BUG-04 — Ausência total de Eloquent Policies
**Ficheiro:** `app/Policies/` (não existe)
**Problema:** Autorização só em Resources — sem protecção a nível de Model. `DailyRecordResource` não restringe por instalação, `IncidentResource` mostra incidentes de todas as piscinas ao NS.
**Fix:** Criar Policies para `DailyRecord`, `Incident`, `StockInstallation` (4–6 horas)

---

#### 🟠 BUG-05 — N+1 query no `PainelPiscinasWidget`
**Ficheiro:** `app/Filament/Widgets/PainelPiscinasWidget.php`
**Problema:** `ultimaLeitura()` dispara uma query por piscina a cada polling de 15s.
**Fix:** Eager load com subquery (2 horas)

---

#### 🟠 BUG-06 — `AlertasService` carrega TODOS os registos sem LIMIT
**Ficheiro:** `app/Services/AlertasService.php`
**Problema:** Com 3 anos de dados = ~5475 registos carregados em memória para usar 5.
**Fix:** Subquery por pool (1 hora)

---

#### 🟠 BUG-07 — Race condition duplicados no `HannaCloudSync`
**Ficheiro:** `app/Console/Commands/HannaCloudSync.php`
**Problema:** `exists()` e `create()` separados — dois syncs paralelos geram duplicados.
**Fix:** `insertOrIgnore()` + unique constraint na BD (1 hora)

---

#### 🟠 BUG-08 — `afterCreate()` síncrono — devia usar Job async
**Ficheiro:** `CreateDailyRecord.php`
**Problema:** `guardarFotos()`, `descontarStock()`, etc. correm no request HTTP. Se a notificação de DB falhar, o utilizador vê erro após gravação.
**Fix:** `ProcessDailyRecordAfterCreate::dispatch()->afterResponse()` (1 hora)

---

#### 🟠 BUG-09 — Encoding UTF-8 quebrado em `UserResource`
**Ficheiro:** `app/Filament/Resources/UserResource.php`
**Problema:** Strings em "NÃ£o" em vez de "Não"
**Fix:** Abrir com editor UTF-8 e corrigir (5 min)

---

#### 🟠 BUG-10 — Token Hanna Cloud não cacheado
**Ficheiro:** `app/Services/HannaCloudService.php`
**Problema:** Cada sync autentica de novo = 96 autenticações/dia. Risco de rate-limiting.
**Fix:** `Cache::remember('hanna_access_token', 3600, fn() => ...)` (1 hora)

---

#### 🟡 BUG-11 — `phConforme()` / `cloroLivreConforme()` não null-safe
Retornam `false` para `null` (marcam como "fora dos limites" quando não há dado).
**Fix:** `if ($this->ph === null) return true;` (15 min)

---

#### 🟡 BUG-12 — `Installation::boot()` não apaga pools/stock em cascata
Só apaga incidentes. Deixa piscinas, stock, tap_alerts órfãos.
**Fix:** Estender o observer a apagar relações (2 horas)

---

#### 🟡 BUG-13 — `QuadroOperacionalWidget` faz DELETE por poda em cada request
Poda de `AlertState` com >7 dias corre a cada 60 segundos por utilizador.
**Fix:** Mover para Job agendado diário (30 min)

---

### 1.2 Performance

- **PERF-01:** `CloroPhChartWidget` — `DATE(registado_em)` sem índice funcional (cria full table scan). **Fix:** Índice funcional no PostgreSQL (30 min)
- **PERF-02:** Cache stampede no `PainelPiscinasWidget` — polling 15s + cache 10min com múltiplos utilizadores. **Fix:** `Cache::lock()` (2 horas)
- **PERF-03:** `StockBaixoWidget` sem cache (5 min TTL recomendado)
- **PERF-04:** `AlertasService` instancia `CacheService` duas vezes — injectar no construtor (5 min)
- **PERF-05:** `sensor_readings` sem índice `(pool_id, lida_em)` (15 min)

### 1.3 Segurança

- **SEC-01:** CSP enforced com `unsafe-inline` e `unsafe-eval` — protecção mínima. Usar `strict-dynamic` com nonce (8–16 horas)
- **SEC-02:** PIN não hashado na BD — com 10^6 combinações, ataque offline é trivial. **Fix:** `'pin' => 'hashed'` em casts + migração rehash (2 horas)
- **SEC-03:** Endpoint `/api/metrics` sem autenticação — expõe informação de sistema. **Fix:** Middleware auth (30 min)
- **SEC-04:** Upload de fotos sem `maxSize` explícito. **Fix:** `->maxSize(10240)->image()` (30 min)

### 1.4 Funcionalidade Incompleta / Edge Cases

- **EDGE-01:** Circuit breaker não usado no `HannaCloudSync` — apenas logging, sem retry. **Fix:** Envolver no CB (2 horas)
- **EDGE-02:** `archive:daily-records` não arquiva relações (`record_additions`, `record_photos`) — deixam órfãs (2 horas)
- **EDGE-03:** Sem validação de ordem cronológica em registos de correção
- **EDGE-04:** Utilizador sem role — mensagem de erro não clara
- **EDGE-05:** Convites por email — sem verificação de mailer real em produção (verificar `MAIL_MAILER`)

### 1.5 Qualidade de Código

- **CODE-01:** `DailyRecordResource.php` com ~950 linhas — demasiado longo. **Fix:** Extrair para `DailyRecordFormBuilder` (4 horas)
- **CODE-02:** `CreateDailyRecord` duplica lógica do `ProcessDailyRecordAfterCreate` Job — o Job devia ser a única fonte. **Fix:** Refactor (2 horas)
- **CODE-03:** Inconsistência `UserRole::ADMIN` vs strings `'admin'` — renomear um role exige busca manual. **Fix:** Usar constantes em todos os sítios (1 hora)
- **CODE-04:** `MetricsService` em memória por-processo — inútil em produção multi-worker. Usar Redis
- **CODE-05:** `font-weight: 650` não existe em CSS — interpretado como `700` (15 min)

### 1.6 Testes

**Coberto bem:** Conformidade CN 14/DA, Stock, Roles, PDF, Circuit breaker, Cache, Security headers

**Não coberto (crítico):**
- `Feature/HannaSyncTest.php` — auth falhada, duplicados, device não encontrado
- `Feature/InvitationFlowTest.php` — convite → aceitação → login
- `Feature/AlertStateKanbanTest.php` — moverAlerta(), auto-resolve, poda
- `Unit/Middleware/EnsureHannaFreshTest.php` — stale >30min dispara sync
- `Feature/PinLoginTest.php` — login com PIN

---

## 2. Auditoria de UI/UX

> **Auditor:** Agente Senior UX/Product — análise como utilizador em campo
> **Foco:** Comparação com Skimmer, Pool Brain, FixMyPool, Orenda, Pool Shark H2O

### Sumário Executivo

A app está tecnicamente sólida mas **a interface foi construída de dentro para fora (developer-first)**. Os utilizadores em campo — técnicos com luvas, ao sol, com pressa — enfrentam um fluxo que exige 4–6 minutos para um registo que deveria levar <2 minutos.

**Três falhas estruturais:**
1. Formulário de 8 secções colapsáveis sem orientação clara
2. Dashboard informativo mas não accionável — o admin tem de navegar para 3–4 recursos para agir
3. Sem modo offline e sem confirmação visual de sucesso em ligações fracas

---

### 2.1 Análise de Fluxos Críticos

#### 🟠 UX-01 — Formulário de registo: 8 secções sem orientação (4–6 min de duração)

**Problema:** O técnico abre e vê lista longa de secções colapsadas. Não sabe em que ordem preencher, o que é obrigatório, nem quanto falta.

**Benchmark:** Skimmer usa stepper de 3 passos com barra de progresso — registo em <2 min.

**Solução — Wizard de 4 passos:**
```
[Piscina & Estado] → [Análises] → [Equipamento] → [Químicos & Foto]
```

- Passo 1 (30 seg): Pool, data, `agua_modo`, `bomba_estado`
- Passo 2 (60 seg): pH, cloro, temperatura, turbidez. Semáforo por campo. Sumário antes de avançar
- Passo 3 (30 seg): Filtros, contador, tanque
- Passo 4 (30–90 seg): Produtos, foto, observações

Filament 3 suporta `Wizard` — mantém lógica `Get/Set` existente.
**Esforço:** 16h

---

#### 🟠 UX-02 — Sem sumário de conformidade antes de submeter

**Problema:** Modal de confirmação genérico ("Confirmar registo") — não mostra "3 parâmetros fora dos limites".

**Benchmark:** Pool Brain mostra "2 valores fora dos limites — submeter mesmo assim?"

**Solução:** Modal `->modalContent()` dinâmico:
```
Estado do registo:
✓ pH 7.2 — Conforme
✗ Cloro livre 0.3 mg/L — ABAIXO DO MÍNIMO (mín. 0.5 mg/L)
✓ Temperatura 27°C — Conforme
⚠ Turbidez 4 NTU — Atenção (máx. 5 NTU)
```
**Esforço:** 3h

---

#### 🟠 UX-03 — Nadador Salvador vê interface completa irrelevante

**Problema:** NS abre e vê Stock, Incidentes, Hanna Devices, Activity Log — cria confusão e aumenta tempo de formação.

**Benchmark:** FixMyPlay — utilizadores "Inspector" têm dashboard simplificado, menu lateral não existe.

**Solução:**
1. Redirect login NS para `/admin/registos-diarios/create`
2. Esconder grupos de navegação irrelevantes por role
3. Widget simplificado: só PainelPiscinasWidget com CTA "Registar"
**Esforço:** 4h

---

#### 🟠 UX-04 — Ausência de modo offline

**Problema:** Piscinas de Maceira e Caranguejeira — cobertura instável. Se a ligação falhar, técnico perde os dados do formulário.

**Impacto:** Registos em falta — não-conformidade regulatória CN 14/DA.

**Solução em 3 níveis:**

**Nível 1 — Auto-save localStorage (4h):**
```js
const DRAFT_KEY = 'mmc_draft_record';
form.addEventListener('input', debounce(() => {
    localStorage.setItem(DRAFT_KEY, JSON.stringify(Object.fromEntries(new FormData(form))));
}, 500));
// Ao abrir: "Rascunho guardado às 09:42. Retomar?"
document.addEventListener('livewire:navigating', () => localStorage.removeItem(DRAFT_KEY));
```

**Nível 2 — Service Worker com queue IndexedDB (20h)**
**Nível 3 — PWA full offline com Workbox (40h)**

---

#### 🟠 UX-05 — Dashboard sem cobertura diária imediata

**Problema:** Gestor abre às 11h, quer saber "já registaram em todas as piscinas?" — tem de contar os cartões.

**Solução — Barra de status compacta no topo do widget:**
```
Hoje · Segunda, 23 Jun  |  4/5 piscinas registadas  |  1 alerta activo  |  Última sync Hanna: há 3 min
```
**Esforço:** 3h

---

#### 🟡 UX-06 — Relatório PDF: uma piscina de cada vez

**Problema:** Para Leiria (3 piscinas), admin executa 3 vezes e junta 3 PDFs manualmente.

**Benchmark:** Pool Shark H2O — "Relatório mensal — todas as piscinas" com 2 cliques.

**Solução:** Select de Pool com opção `null` = "Todas as piscinas da instalação". Iterar e gerar PDF concatenado.
**Esforço:** 4h

---

#### 🟡 UX-07 — Tendências invisíveis

**Problema:** `CloroPhChartWidget` existe mas separado — técnico não vê tendência do pH nos últimos 7 dias no cartão da piscina.

**Impacto:** Não detecta deriva gradual (ex: pH a descer 0.1/dia).

**Solução:** Sparkline inline (60×20px) para pH e cloro nos cartões. Dados já carregados em batch.
**Esforço:** 6h

---

#### 🟡 UX-08 — Calculadora de dosagem ausente

**Problema:** pH 6.5 detectado — técnico tem de saber de cabeça quanto produto adicionar.

**Impacto:** Sub/sobre-dosagem. Custo desperdiçado. Risco de saúde pública.

**Benchmark:** FixMyPool calcula exactamente quantos kg adicionar.

**Solução:** No Passo 2 (Análises), quando fora do limite:
```
pH 6.5 detectado | Volume piscina: 900 m³
Dosagem sugerida: +18 kg de Carbonato de Sódio (0.02 kg/m³ por 0.1 pH)
→ [Adicionar ao registo]
```
**Esforço:** 10h

---

#### 🟡 UX-09 — Alertas Hanna por threshold pós-sync

**Problema:** Sensor detecta pH 6.2 às 10h — sem alerta proactivo até técnico abrir dashboard às 11h.

**Impacto:** Violação não detectada por 1 hora.

**Solução:** No `hanna:sync`, após gravar, verificar contra `DailyRecord::METRICAS`. Se fora, notificar admins/técnicos:
```php
if ($leitura->ph < DailyRecord::PH_MIN) {
    Notification::send(User::role(['tecnico', 'admin'])->get(), new HannaThresholdAlert(...));
}
```
**Esforço:** 4h

---

#### 🟡 UX-10 — Incidentes sem ligação ao registo diário

**Problema:** `Incident` não tem `daily_record_id` — correlação manual por data/instalação.

**Solução:** Adicionar campo opcional + link no Resource.
**Esforço:** 2h

---

#### 🔵 UX-11 — Acção "Corrigir" é intimidante

O botão com `color('warning')` não explica que cria novo registo append-only. Modal devia dizer: "Será criado novo registo de correção. Original fica intacto."
**Esforço:** 1h

---

### 2.2 Novas Funcionalidades

| Feature | Impacto | Esforço | Benchmark |
|---|---|---|---|
| Quick Log "Só Análises" (FAB 30 seg) | Alto | 12h | Skimmer |
| Checklists de manutenção preventiva | Alto | 40h | Orenda |
| Modo Inspeção Regulatória (link 24h) | Alto | 24h | Nenhuma app PT |
| Calculadora de dosagem integrada | Médio | 10h | FixMyPool |
| Dashboard NS simplificado | Médio | 4h | FixMyPlay |
| Sparklines 7 dias nos cartões | Médio | 6h | Skimmer |
| Alertas SMS/WhatsApp violações | Crítico produção | 8h | Skimmer |
| Relatório conformidade histórica | Médio | 6h | Pool Shark H2O |

---

### 2.3 Roadmap UX — Por Sprints

#### Sprint 1 — Alto retorno imediato (~29h total)

1. Auto-save rascunho em localStorage — 4h
2. Barra de status diária no dashboard — 3h
3. Alertas Hanna por threshold pós-sync — 4h
4. NS: esconder navegação irrelevante + redirect — 4h
5. Sparklines 7 dias nos cartões de piscina — 6h
6. Relatório PDF multi-piscina — 4h
7. Row color por conformidade na tabela — 1h
8. Sumário de conformidade no modal — 3h

#### Sprint 2 — Maior salto de qualidade (~44h total)

9. Wizard de 4 passos no registo diário — 16h
10. Quick Log FAB (análise em 30 seg) — 12h
11. Calculadora de dosagem inline — 10h
12. Notificação ao técnico de não-conformidade — 2h
13. Fotos com preview imediato — 2h
14. NS: dashboard dedicado — 2h

#### Sprint 3 — Diferenciadores competitivos (~108h total)

15. Checklists de manutenção preventiva — 40h
16. Modo Inspeção Regulatória (link temporário) — 24h
17. Service Worker offline (Nível 2) — 20h
18. Onboarding guiado para novos técnicos — 8h
19. Calculadora de dosagem avançada (por produto) — 16h

---

## 3. Auditoria de Frontend

> **Auditor:** Agente Senior Frontend/Design
> **Stack:** Filament 3.3 + Tailwind 4 + Alpine.js + GSAP + Chart.js

### 3.1 Diagnóstico

**O que está bem:**
- Disciplina de cor anti alarm-fatigue (só vermelho para violação legal)
- Kanban com SortableJS + GSAP + fallback tátil
- ResizeObserver com debounce + cleanup
- PWA com manifest, ícones maskable, service worker network-first
- Chart.js modo multi-métrica normalizado 0–100%

**Problemas críticos:**

#### 🔴 FE-01 — Fonte `Instrument Sans` não carregada
**Impacto:** Toda a gente usa fonte do sistema (Inter no Chrome, San Francisco no Safari).

**Fix (Google Fonts):**
```php
->renderHook(
    PanelsRenderHook::HEAD_START,
    fn (): string => '<link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">',
),
```

**Ou (bundle local):**
```bash
npm install @fontsource-variable/dm-sans
```
```css
@import '@fontsource-variable/dm-sans';
```
**Esforço:** 30 min

---

#### 🟠 FE-02 — Dark mode incompleto — hex codes hard-coded

Variáveis CSS deviam substituir hex codes. Dark mode requer o dobro do CSS manualmente.

**Fix — CSS Custom Properties unificado:**
```css
:root {
    --mmc-surface: #ffffff;
    --mmc-border: #e2e8f0;
    --mmc-text: #0f172a;
    --mmc-bad: #dc2626;
    --mmc-brand: #2b9cd8;
}
.dark {
    --mmc-surface: #1e293b;
    --mmc-border: #334155;
    --mmc-text: #f1f5f9;
    --mmc-bad: #f87171;
    --mmc-brand: #7cc4e8;
}
```

Após isto, dark mode completo fica automático.
**Esforço:** 3–4h

---

#### 🟠 FE-03 — Contraste WCAG AA falha em `.mmc-metric-label`

`opacity: 0.55` sobre branco → luminância ~74%. WCAG AA para texto pequeno exige 4.5:1 — **falha**.

**Fix:**
```css
.mmc-metric-label { color: var(--mmc-text-muted, #64748b); }
/* #64748b sobre #ffffff = 4.6:1 — passa WCAG AA */
```
**Esforço:** 15 min

---

#### 🟡 FE-04 — Chart.js importado completo (~35KB desnecessários)

Só usa `line` — tree-shake para reduzir.

**Fix:**
```js
import { Chart, LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Legend, Tooltip } from 'chart.js';
Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Legend, Tooltip);
```

**Redução:** ~35KB gzipped.
**Esforço:** 30 min

---

#### 🟡 FE-05 — PWA: sem screenshots no manifest

Android 12+ e iOS 16.4+ mostram preview antes de instalar — sem screenshots parece menos profissional.

**Fix:**
```json
"screenshots": [{
    "src": "/images/screenshot-dashboard.png",
    "sizes": "1080x1920",
    "form_factor": "narrow",
    "label": "Dashboard de piscinas"
}]
```

**Esforço:** 1h

---

### 3.2 Cinco Transformações de Alto Impacto

#### T1 — Health Score SVG por piscina (2h)
Círculo animado com % de conformidade no cartão. Código já fornecido no relatório detalhado.

#### T2 — CSS Custom Properties + fonte real (3–4h)
Unificar variáveis (FE-02) + carregar DM Sans. Elimina dark mode incompleto de uma vez.

#### T3 — Bottom Navigation para mobile (2–3h)
Substitui hamburger por barra fixa com 3 itens: Dashboard, Registar, Histórico.

#### T4 — Semáforo amplificado (2h)
Além de hint Filament (texto pequeno), adicionar borda colorida ao campo quando fora dos limites.

#### T5 — Drop target Kanban + count-up GSAP (2h)
Feedback visual ao arrastar card para coluna. Count-up nos números de conformidade.

---

### 3.3 Roadmap Frontend

| # | Item | Estimativa | Impacto |
|---|---|---|---|
| 1 | Carregar DM Sans via @fontsource | 30 min | Alto |
| 2 | CSS Custom Properties + dark mode completo | 3–4h | Alto |
| 3 | Contraste WCAG — opacity → cor | 15 min | Médio |
| 4 | Health score SVG no cartão de piscina | 2h | Alto |
| 5 | Bottom navigation mobile | 2h | Alto |
| 6 | Drop target visual Kanban | 1h | Médio |
| 7 | Count-up GSAP nos números | 1h | Médio |
| 8 | Semáforo amplificado (borda colorida) | 2h | Alto |
| 9 | Chart.js tree-shaking | 30 min | Perf |
| 10 | Screenshots no manifest | 1h | Baixo |
| 11 | Safe area insets iOS | 30 min | Médio |
| 12 | Skeleton loading durante polling | 1h | Médio |

**Total estimado:** ~20h | Sequência recomendada: 1 → 2 → 4 → 5 → 8

---

## 4. Roadmap Consolidado

### Prioridade Máxima — Antes de Produção (~30 horas)

| # | Problema | Agente | Esforço | O que resolver |
|---|---|---|---|---|
| P1 | BUG-09 | Código | 5 min | Encoding UTF-8 |
| P2 | BUG-01 | Código | 2 min | MetricsService crash |
| P3 | FE-01 | Frontend | 30 min | Fonte carregada |
| P4 | FE-02 / FE-03 | Frontend | 30 min | Dark mode + contraste |
| P5 | BUG-16 | Código | 15 min | Propriedade dinâmica PHP |
| P6 | BUG-11 | Código | 15 min | Null-safe conformidade |
| P7 | BUG-10 | Código | 1h | HannaSync duplicados |
| P8 | BUG-03 | Código | 1h | Middleware bloqueador |
| P9 | BUG-08 | Código | 1h | afterCreate assíncrono |
| P10 | SEC-02 | Código | 2h | PIN hashado |
| P11 | BUG-02 | Código | 2h | Race condition stock |
| P12 | BUG-04 | Código | 6h | Eloquent Policies |

### Sprint 1 (Semana 1–2 | ~60h) — Alta qualidade

**Código (15h):**
- Alertas Hanna threshold (4h)
- Policies Eloquent (6h)
- N+1 queries fix (2h)
- Testes críticos (3h)

**UX (12h):**
- Auto-save localStorage (4h)
- Barra status diária (3h)
- Alertas Hanna (4h)
- Dashboard NS (1h)

**Frontend (15h):**
- CSS Custom Properties (3–4h)
- Health score SVG (2h)
- Bottom nav mobile (2h)
- Semáforo amplificado (2h)
- Outros polish (3–4h)

### Sprint 2 (Semana 3–4 | ~60h) — Maior salto de qualidade

**Código (12h):**
- Testes suite completa (8h)
- Cache + performance fixes (4h)

**UX (32h):**
- Wizard 4 passos (16h)
- Quick Log FAB (12h)
- Calculadora inline (4h)

**Frontend (16h):**
- Drop target + count-up (2h)
- Sparklines (6h)
- Polish mobile (8h)

### Sprint 3+ (Mês 2+) — Diferenciadores

- Checklists preventivas (40h)
- Modo Inspeção Regulatória (24h)
- Service Worker offline (20h)
- Alertas SMS/WhatsApp (8h)
- Relatórios agendados (6h)

---

*Documento compilado a 2026-06-23 por 3 agentes senior em paralelo.*
*Tokens utilizados: Código 96.792 | Frontend 71.196 | UX 49.133 | **Total: 217.121 tokens***
