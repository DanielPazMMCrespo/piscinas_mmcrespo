# Contexto Completo — Projeto Piscinas MMCrespo

> **Como usar:** Cola este ficheiro inteiro no início de qualquer conversa nova com qualquer IA.
> Última atualização: 2026-06-09 (sessão 5)

---

## Quem sou e como quero ser tratado

Sou um programador junior, recém-entrado no mundo do trabalho, sem licenciatura em engenharia informática concluída. Trabalho sozinho, 4-5h/dia, com auxílio de IAs. Programo com Claude ou equivalente como colaborador técnico.

**Regras de comunicação:**
- Trata-me como profissional. Não expliques fundamentos que não pedi.
- Vai direto à resposta. Contexto e raciocínio a seguir, nunca antes.
- Sem bullet points desnecessários — usa prosa quando faz sentido.
- Se eu estiver errado, diz diretamente. Não suavizes.
- Se houver uma abordagem melhor, diz uma vez e faz o que pedi.
- Sem em-dashes. Sem voz passiva. Sem linguagem de cobertura.
- Output técnico: código funcional primeiro, explicação depois se necessário.

---

## O Projeto

**Nome:** Piscinas MMCrespo  
**Tipo:** Aplicação web de gestão operacional para piscinas municipais sob contrato da empresa MMCrespo.  
**Contexto legal:** Projeto público, visibilidade mediática se falhar. Cumprimento obrigatório de CN 14/DA (DGS 2009), NP 4542:2017, DR 5/97.

### Instalações e piscinas (5 no total)

| Instalação | Piscinas | Volume |
|---|---|---|
| Leiria | Competição (25x17.4m, 2m prof.) | 900 m³ |
| Leiria | Lazer (25x17.4m, 1.1m prof.) | 600 m³ |
| Leiria | Infantil (17.4x5m, 0.3-1.2m prof.) | 50 m³ |
| Maceira | Maceira (16.6x10m) | 170 m³ |
| Caranguejeira | Caranguejeira (16.6x10m) | 170 m³ |

Temperaturas: Competição 26-27°C, Lazer/Infantil/Maceira/Caranguejeira 28-30°C.

---

## Stack Técnica (decisão final atualizada)

- **Framework:** Laravel 12 LTS (Downgrade do 13 devido a problemas de dependências openspout no PHP 8.5)
- **Admin/UI:** Filament 3.3.x
- **Base de dados:** SQLite local para dev (ficheiro `database/database.sqlite`); PostgreSQL para produção
- **Roles:** spatie/laravel-permission configurado nos modelos e recursos
- **Audit Trail:** spatie/laravel-activitylog 4.9+ instalado; trait `LogsActivity` em DailyRecord, User, Pool
- **PDF:** barryvdh/laravel-dompdf (a implementar)
- **AI:** Gemini / Claude API — OCR removido desta versão; a retomar no Plano 4
- **Offline:** Auto-save de rascunhos em localStorage (PWA completo é Fase 2)
- **Charts:** Chart.js (instalado via npm, carregado via render hook — substituiu ApexCharts nativo do Filament por necessidade de eixo duplo e bandas de conformidade)

---

## Roles e Permissões

| Role | Permissões |
|---|---|
| **Admin** | Acesso total a Estrutura, Inventário, Sistema e Operação. (Login: admin@mmcrespo.pt / password) |
| **Técnico** | Acesso a Inventário e Operação. Regista stocks e incidentes. (Login: tecnico@mmcrespo.pt / password) |
| **Nadador Salvador (NS)** | Acesso apenas a Operação. Cria registos diários das suas piscinas. (Login: ns@mmcrespo.pt / password) |

---

## Modelo de Dados (Implementado e com Seeders Reais)

- `installations`, `pools` (inclui coluna `volume` decimal para calculadora de dosagem)
- `users`
- `daily_records` (ver colunas completas abaixo), `record_additions`, `record_photos`
- `filter_checks`, `incidents`, `incident_pools`, `incident_products`
- `products`, `stock_warehouse`, `stock_warehouse_logs`
- `stock_installations`, `stock_installation_logs`
- `activity_log` (spatie/laravel-activitylog)

### Colunas de daily_records (completas)
Campos base: `pool_id`, `user_id`, `registado_em`, `ph`, `cloro_livre`, `cloro_total`, `temperatura`, `transparencia`, `caleira_feita`, `renovacao_agua`, `observacoes`, `e_correcao`, `corrige_registo_id`, `razao_correcao`
Bomba/filtro (migration 2026_06_08): `bomba_com_bolhas`, `pressao_filtro`, `estado_valvulas_filtro`
NS + filtros (migration 2026_06_09_085943): `ns_foto`, `ns_ph`, `ns_cloro_livre`, `ns_cloro_total`, `ns_temperatura`, `filtro_faz_retrolavagem`, `filtro_foto_retrolavagem`, `filtro_foto_enxaguamento`, `filtro_foto_posicao_normal`
Caminho da água (migration 2026_06_09_120000): `bomba_ferrada`, `contador_valor`, `agua_modo`, `tanque_ok`, `tanque_observacoes`, `analises_fotos` (json)

---

## Estado Atual do Projeto

O projeto encontra-se estabilizado, alojado em `C:\dev\piscinas_mmcrespo-main` e versionado em `https://github.com/DanielPazMMCrespo/piscinas_mmcrespo`.

| Plano | Estado | Descrição |
|---|---|---|
| Plano 1 — Fundações | **CONCLUÍDO** | Instalação do Laravel 12, PostgreSQL (SQLite local para dev), 14 Migrações, 14 Models, Seeders (roles, utilizadores, instalações, piscinas, químicos) e testes unitários. |
| Plano 2 — Interface Administrativa (Filament) | **CONCLUÍDO** | Todos os Recursos gerados e divididos em 4 Navigation Groups. Permissões por role. Formulários com secções. Branding MMCrespo aplicado (cores #2b9cd8 / #76b82a, logo). **Segurança reforçada:** race conditions em stock resolvidos (DB::transaction + lockForUpdate), validação regulatória CN 14/DA nos parâmetros da água, IDOR resolvido, uploads privados, proteção do último admin. |
| Plano 3 — Dashboards e Analytics | **CONCLUÍDO** | Widgets do dashboard: **PainelPiscinasWidget** (estado de todas as piscinas ao primeiro olhar — pH/cloro livre/cloro total/temperatura com cores de conformidade CN 14/DA, no topo), StatsOverviewWidget (KPIs), CloroPhChartWidget (gráfico dual Y-axis 14 dias, exclui registos corrigidos), StockBaixoWidget (alertas stock mínimo). UltimosRegistosWidget removido por redundância. |
| Plano 3.5 — Integridade, UX e Segurança | **CONCLUÍDO** | Livro sanitário append-only (só admin edita/elimina registos operacionais; campo cria e corrige). Workflow de **correção** (novo registo ligado ao original + razão). Ecrã de confirmação nos registos operacionais. Cores de conformidade nas tabelas. Logo adaptativo dark/light + comprimido (2.3MB→34KB). Timezone Europe/Lisbon. Testes de feature a blindar as regras. |
| Plano 3.6 — Mobile e PWA leve | **CONCLUÍDO** | Widgets custom responsivos (grelhas colapsam em telemóvel, alturas de gráfico reduzidas em ecrãs `<640px`). Tabela de registos diários reestruturada com `Split`/`Stack` (colapsa em cartão a partir de `md`). **PWA leve instalável:** `manifest.json` + ícones 192/512/maskable + service worker mínimo (network-first, sem cache offline) + tags no `<head>` via render hook. App instala-se no ecrã principal do telemóvel (Android/iOS), abre em ecrã inteiro. |
| Plano 3.7 — Análise de Parâmetros (gráficos avançados) | **CONCLUÍDO** | `CloroPhChartWidget` reescrito com dois modos: **1 piscina** → um gráfico único com todos os parâmetros em cores distintas + eixo duplo (cloro/temp à esquerda, pH à direita) para correlacionar pH↔cloro de relance; **N piscinas** → um gráfico por parâmetro, cor por piscina. Bandas de conformidade CN 14/DA como faixa verde. Página dedicada **Análise de Parâmetros** (Operação → Análise) para evitar scroll excessivo no dashboard. `ResizeObserver` com debounce 100ms para resize fiável ao rodar o ecrã. Dados de demo realistas (14 dias × 5 piscinas, variação sinusoidal estável com anomalias pontuais). |
| Plano 3.8 — Registo Diário (caminho da água) | **CONCLUÍDO** | Formulário do registo diário completamente reestruturado pela lógica do caminho da água. 8 secções colapsáveis com ícone e anel verde/vermelho: **Informação Geral**, **Bomba** (toggle ferrada), **Filtros** (retrolavagem + 3 fotos), **Contador & Água** (valor m³ + 5 estados: auto/ON/OFF com/sem água), **Tanque de Compensação** (ok + observações), **Análises NS** (foto + 4 leituras), **Nossas Análises** (4 parâmetros + até 5 fotos), **Adições de Químicos** (calculadora aceita % decimal tipo 0,56), **Observações**. Campo `volume` adicionado às Piscinas para a calculadora. |
| Plano 3.9 — Hardening Fase 2 + Audit Trail | **CONCLUÍDO** | Headers de segurança HTTP (`SecurityHeaders` middleware: X-Content-Type-Options, X-Frame-Options, HSTS). `config/session.php` com `secure`, `http_only`, `same_site=strict`. `.env.example` atualizado para produção. `spatie/laravel-activitylog` instalado (v4.9+); trait `LogsActivity` + `getActivitylogOptions()` (logFillable, logOnlyDirty, dontSubmitEmptyLogs) em DailyRecord, User, Pool. Migrations idempotentes (colunas protegidas com `Schema::hasColumn`). |
| Plano 4 — Inteligência Artificial | **PENDENTE** | OCR das fotos e análise de filtros via Gemini API. Removido nesta sessão por baixa precisão. A retomar quando o foco voltar à IA. |
| Plano 5 — Relatórios PDF (CN 14/DA) | **PENDENTE** | Construção dos relatórios formatados de forma regulamentar para download. |
| Plano 6 — UI de Audit Trail | **PENDENTE** | Instalar `rmsramos/activitylog` e ligar `\Rmsramos\Activitylog\ActivitylogPlugin::make()` no `->plugins([])` do `AdminPanelProvider`. O `pxlrbt/filament-activity-log` NÃO suporta Filament 3. |

**Próximo passo imediato:** Resolver o `ext-intl` no PHP local (ver nota abaixo), depois instalar `rmsramos/activitylog` para a UI de audit trail (Plano 6), ou avançar para o Plano 5 (relatórios PDF).

**Nota de desenvolvimento — ext-intl no Windows (POR RESOLVER):**
- O `php_intl.dll` está em falta na instalação PHP local (`C:\php\ext\`).
- PHP 8.5.0 NTS x64. Fix: descarregar `php-8.5.0-nts-Win32-vs17-x64.zip` de `windows.php.net/downloads/releases/`, copiar `ext\php_intl.dll` para `C:\php\ext\` e todos os `icu*.dll` da raiz do ZIP para `C:\php\`. Descomentar `extension=intl` no `php.ini`. Reiniciar o terminal.
- No `.env` local usar `SESSION_SECURE_COOKIE=false` (cookie secure só funciona em HTTPS; em localhost o login parte se estiver `true`).

### Decisões técnicas tomadas (não reverter sem razão forte)
- `transparencia` é coluna `INTEGER` na BD — usar `->step(1)` no formulário, não `0.1`
- Uploads de filtros usam `->disk('local')` (privado, `storage/app/private/`) — não voltar para `public`
- Stock usa `DB::transaction()` + `lockForUpdate()` — obrigatório para evitar race conditions em PostgreSQL
- Gráfico cloro/pH usa `null` (não `0`) para dias sem registo, com `'spanGaps' => false`
- `nadador_salvador` só vê os seus próprios registos diários e limpezas de filtros (scope por `user_id`)
- **Registos operacionais (registo diário, filtros, incidentes) são append-only para o campo:** técnico/NS criam e corrigem, só o admin edita/elimina. Imposto via `canEdit`/`canDelete`/`canDeleteAny` nos Resources (nível de policy, não só UI)
- **Correção de registo** nunca apaga o original — cria novo registo com `e_correcao=true`, `corrige_registo_id` e `razao_correcao`. O original substituído é excluído de gráficos/estatísticas via `whereDoesntHave('correcoes')`
- Limites regulamentares CN 14/DA centralizados em constantes no model `DailyRecord` (`PH_MIN/MAX`, `CLORO_LIVRE_MIN/MAX`, `CLORO_COMBINADO_MAX`) — fonte única de verdade
- Temperatura avalia-se contra os limites próprios de cada piscina (`Pool::temp_min/temp_max`), não um valor fixo
- Logo: view `resources/views/filament/brand-logo.blade.php` com filtro CSS `.dark` (não depende do build do Tailwind do projeto)
- Componentes visuais em widgets custom usam estilos inline / seletor `.dark` — o Filament compila o seu próprio CSS e não inclui classes Tailwind arbitrárias do projeto
- **Chart.js** instalado via npm e carregado no painel por render hook (`@vite('resources/js/app.js')` no `HEAD_END`) — o Filament não inclui o `app.js` do projeto por defeito. Componente Alpine `mmcChart` registado no evento `alpine:init`
- Grelhas de widgets custom usam `minmax(min(100%, Npx), 1fr)` (não `minmax(Npx, 1fr)`) para colapsarem em telemóvel sem transbordar; cartões com `min-width: 0`
- Tabelas com muitas colunas usam `Split`/`Stack` com `->from('md')` para colapsarem em cartão no telemóvel mantendo a densidade em desktop
- **PWA leve:** `public/manifest.json`, ícones em `public/images/icon-*.png` (gerados do logo com fundo branco via PowerShell System.Drawing), `public/sw.js` (network-first, sem cache offline), tags no `<head>` via `resources/views/filament/pwa-head.blade.php` + render hook. Os ícones têm fundo sólido porque o logo é transparente e ficaria invisível no ecrã principal
- **Página Análise de Parâmetros:** `app/Filament/Pages/AnaliseParametros.php` + view dedicada. Widget `CloroPhChartWidget` tem `protected static bool $isDiscovered = false` para não aparecer também no dashboard. Carregado via `@livewire()` na view da página
- **Resize de gráficos ao rodar ecrã:** `ResizeObserver` em `resources/js/app.js` (componente Alpine `mmcChart`) observa o `parentElement` do canvas com debounce 100ms; usa `$cleanup` para desligar o observer quando o componente é destruído
- **Dados de demo:** `database/seeders/DemoRegistosDiariosSeeder.php` — 14 dias × 5 piscinas, variação sinusoidal determinística (sem `rand()`), anomalias pontuais (pH 8.4 Piscina 1 corrigida, cloro 0.3 Maceira sem correção)

- **Registo diário — secções colapsáveis:** todas as secções usam `->collapsible()` + `->icon()` + `->extraAttributes()` com anel verde/vermelho. A ordem segue o caminho da água: Bomba → Filtros → Contador & Água → Tanque → Análises NS → Nossas Análises → Adições → Observações
- **Calculadora de dosagem:** `step(0.0001)` e `minValue(0.0001)` no campo concentração para aceitar valores como 0,56% (granulado). Fórmula: `(volume_piscina × dosagem_mg_L) / concentracao_%`
- **analises_fotos:** coluna `json` em `daily_records`; FileUpload com `->multiple()->maxFiles(5)->reorderable()->appendFiles()` na secção Nossas Análises
- **agua_modo:** coluna `string(30)` com 5 opções: `auto_com_agua`, `auto_sem_agua`, `on_com_agua`, `on_sem_agua`, `off`
- **Migrations idempotentes:** colunas adicionadas em migrations de alter usam `if (! Schema::hasColumn(...))` para evitar "duplicate column" em ambientes com BD parcialmente migrada. `down()` das migrations redundantes é no-op
- **spatie/laravel-activitylog:** instalar com `--ignore-platform-req=ext-intl` enquanto o ext-intl estiver em falta. UI pendente via `rmsramos/activitylog` (não usar `pxlrbt/filament-activity-log` — não suporta Filament 3)
- **SESSION_SECURE_COOKIE:** `true` em produção (HTTPS), `false` em dev local (HTTP). Configurar no `.env` local explicitamente para evitar login partido em localhost

### A fazer no futuro (anotado, não urgente)
- **PWA com cache offline:** o service worker atual é network-first sem cache (mostrar dados sanitários desatualizados seria pior que um erro). Fase 2 prevê auto-save de rascunhos em localStorage + sincronização quando voltar a rede. Implementar quando o técnico precisar de registar sem ligação no terreno
- **Tooltip multi-métrica com unidade por série:** no modo multi-métrica (1 piscina, vários parâmetros), o tooltip mostra valor sem unidade para pH e mg/L para os outros. Melhorar passando a unidade por série no config JS

---

## Segurança — estado e checklist de produção

**Já tratado no código:**
- Autenticação Filament com throttling de login nativo (Laravel)
- Autorização por role: `canAccess` nos Resources + `canEdit/canDelete` nos operacionais (bloqueia também acesso direto por URL, não só esconde botões)
- IDOR resolvido (scope por `user_id` em registos diários e filtros)
- Race conditions em stock: `DB::transaction()` + `lockForUpdate()` + guarda contra stock negativo
- Mass assignment controlado por `$fillable` em todos os models
- Uploads privados (`disk('local')`), whitelist de MIME e tamanho máximo
- Proteção do último admin e contra auto-eliminação / auto-escalonamento de role
- Unicidade (instalação+produto) validada com mensagem limpa, não erro SQL
- `AuthenticateSession` ativo (invalida outras sessões ao mudar password)
- `.env` fora do git; `APP_KEY` definida
- Testes de feature a cobrir as regras de integridade (`tests/Feature/DailyRecordIntegrityTest.php`)
- Headers HTTP de segurança: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Strict-Transport-Security` via `app/Http/Middleware/SecurityHeaders.php` registado em `bootstrap/app.php`
- `config/session.php`: `secure`, `http_only=true`, `same_site=strict`
- Audit trail: `spatie/laravel-activitylog` em DailyRecord, User, Pool (logFillable, logOnlyDirty)

**OBRIGATÓRIO antes de produção (não esquecer):**
- [ ] `APP_DEBUG=false` e `APP_ENV=production` no `.env` de produção
- [ ] **Trocar as passwords-padrão** (`password`) de todos os utilizadores seeded
- [ ] `APP_URL` com o domínio real + **HTTPS** forçado
- [ ] `SESSION_SECURE_COOKIE=true` (cookies só por HTTPS)
- [ ] Migrar de SQLite para **PostgreSQL** (a decisão de stack); validar que o `lockForUpdate` funciona na BD final
- [ ] Configurar backups automáticos da BD (o livro sanitário é registo legal)
- [ ] Política de passwords mais forte se exigido pela entidade pública
- [ ] Rever logs/erros para não exporem dados sensíveis

## UX Não Negociável
- Ecrã de confirmação antes de submeter (implementado nos registos operacionais).
- Botão submit desativa após primeiro toque (nativo do Filament/Livewire via estado de loading).
- Validação dura em tempo real; limites legais visíveis como `helperText` em cada campo; correção via workflow dedicado.

## Validação Regulamentar (não esquecer)
Antes de produção: obter parecer escrito da USP do ACES Pinhal Litoral a aceitar o formato digital do livro de registo sanitário. Manter livro em papel em paralelo no primeiro ano.

---

## Prompts Prontos para a Próxima IA

Cola o conteúdo deste ficheiro (CLAUDE.md) no início de cada conversa com qualquer IA.

### Prompt 1 — Gráfico interativo: normalizar parâmetros (% do intervalo)

**Contexto:** Laravel 12 + Filament 3.3 + Chart.js. O widget `CloroPhChartWidget` (app/Filament/Widgets/CloroPhChartWidget.php) é um Widget custom com InteractsWithForms. Tem dois multi-selects: piscinas e parâmetros. O método `getGraficos()` decide o modo:
  - 1 piscina selecionada → modo 'multi-metrica': 1 gráfico, cada parâmetro = 1 linha com cor própria, eixo duplo (pH à direita 6.5-8.5, resto à esquerda 0-3).
  - 2+ piscinas → modo 'mono-metrica': 1 gráfico por parâmetro, cada piscina = 1 linha.

O componente Alpine `mmcChart` está em `resources/js/app.js`. A view está em `resources/views/filament/widgets/painel-parametros.blade.php`.

**Problema:** Quando há 1 piscina + vários parâmetros, as escalas são muito diferentes (pH: 6.5–8.5; cloro: 0–2.5; temperatura: 22–32). O gráfico fica ilegível porque as linhas de temperatura e cloro ficam comprimidas.

**Tarefa:** No modo 'multi-metrica', normalizar os dados para uma escala percentual (0–100%) calculada com base nos limites min/max definidos em `METRICAS[]`. Assim todas as linhas ficam legíveis no mesmo gráfico. O eixo Y deve mostrar "% do intervalo". Os tooltips devem continuar a mostrar o valor real (ex: "pH 7.4", "Cl 1.1 mg/L").

Manter compatibilidade com o modo 'mono-metrica' (inalterado).

Após implementar: `php artisan filament:clear-cached-components && npm run build`

---

### Prompt 2 — Plano 4: OCR de filtros com IA (Gemini/Claude API)

**Contexto:** Laravel 12 + Filament 3.3. O `FilterCheckResource` (app/Filament/Resources/FilterCheckResource.php) tem um campo de upload de foto do filtro (disco privado 'local', storage/app/private/). Os modelos têm colunas `resultado_ia` (string, nullable) e `descricao_ia` (text, nullable).

**Tarefa:** Integrar a API do Google Gemini para OCR/análise automática das fotos.

1. Instalar via composer: `google/generative-ai-php` (ou HTTP direto via Laravel Http facade se não existir — usar `https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent` com multipart/form-data para imagem).

2. Criar `app/Services/GeminiFilterAnalysisService.php` com método `analyze(string $imagePath): array` que:
   - Recebe o path do ficheiro privado
   - Envia à API Gemini com o prompt: "Analisa esta foto de um filtro de piscina. Diz: 1) Estado geral (Limpo/Sujo/Muito sujo/Danificado). 2) Descrição do que vês (máx 2 frases). Responde em JSON: {\"resultado\": \"...\", \"descricao\": \"...\"}"
   - Retorna `['resultado' => '...', 'descricao' => '...']` ou lança exceção

3. No `FilterCheckResource`, após o upload da foto (`foto_filtro`), usar um Action 'Analisar com IA' que: lê o ficheiro privado, chama o serviço, preenche `resultado_ia` e `descricao_ia` no formulário via `$set()`. Mostrar loading state e Filament Notification (sucesso/erro).

4. Adicionar `GEMINI_API_KEY` ao `.env` e `config/services.php`.

5. Não quebrar o resto do formulário nem as regras de `canEdit/canDelete` existentes.

Após implementar: `php artisan filament:clear-cached-components`

---

### Prompt 3 — Plano 5: Relatórios PDF regulamentares (CN 14/DA)

**Contexto:** Laravel 12 + Filament 3.3 + `barryvdh/laravel-dompdf` (já instalado). Modelos: `DailyRecord`, `FilterCheck`, `Incident`, `Pool`, `Installation`. Limites legais CN 14/DA centralizados em constantes no model `DailyRecord` (PH_MIN=6.9, PH_MAX=8.0, CLORO_LIVRE_MIN=0.5, CLORO_LIVRE_MAX=2.0, CLORO_COMBINADO_MAX=0.6).

**Tarefa:** Criar um relatório PDF do livro sanitário (CN 14/DA) por piscina e período.

1. Criar `app/Filament/Pages/RelatorioPDF.php` como página Filament no grupo 'Operação', com formulário: Select de instalação, Select de piscina (filtrado pela instalação), DatePicker de data início e fim, e botão "Gerar PDF". Só admin e técnico acedem (`canAccess` via `hasAnyRole`).

2. Criar `resources/views/pdf/livro-sanitario.blade.php` com:
   - Cabeçalho: nome da instalação, piscina, período, gerado em, referência CN 14/DA
   - Tabela de registos diários (data, hora, técnico, pH, cloro livre, cloro total, temperatura, transparência, caleira, renovação, observações)
   - Coluna "Conforme" com ✓/✗ baseado nos limites de `DailyRecord::PH_MIN` etc
   - Rodapé com paginação e assinatura
   - CSS inline simples, preto e branco (dompdf não suporta CSS externo)
   - Registos corrigidos excluídos via `whereDoesntHave('correcoes')`; incluir nota se houver correções

3. Na página Filament, action de submit que gera o PDF com:
   ```php
   Pdf::loadView('pdf.livro-sanitario', compact('dados'))->download('livro-sanitario.pdf')
   ```

4. Adicionar link para a página na navegação (navigationSort = 20).

Após implementar: `php artisan filament:clear-cached-components`

---

### Prompt 4 — Migração para PostgreSQL + checklist de produção

**Contexto:** Laravel 12. Ambiente de dev usa SQLite (database/database.sqlite). Target de produção: PostgreSQL. O `.env` tem `DATABASE_URL` ou `DB_*` vars.

**Tarefa:** Preparar o projeto para produção.

1. Atualizar `.env.example` com variáveis PostgreSQL (DB_CONNECTION=pgsql, DB_HOST, DB_PORT=5432, DB_DATABASE, DB_USERNAME, DB_PASSWORD).

2. Verificar todas as migrações em `database/migrations/` para compatibilidade com PostgreSQL: tipos de coluna (boolean vs tinyint), índices, defaults. SQLite é permissivo; PostgreSQL é estrito com tipos. Corrigir o que for necessário.

3. Confirmar que os `DB::transaction()` + `lockForUpdate()` em `StockInstallationResource` e `StockWarehouseResource` funcionam em PostgreSQL (em SQLite fazem lock da BD inteira, não da linha — comportamento diferente, código correto para pgsql).

4. Criar/atualizar `config/database.php` para ter charset correto para PostgreSQL (utf8, não utf8mb4 que é MySQL).

5. Criar ficheiro `PRODUCAO.md` na raiz com o checklist de produção baseado no CLAUDE.md secção "Segurança":
   - APP_DEBUG=false, APP_ENV=production
   - Trocar passwords de todos os seeders
   - APP_URL com domínio real + HTTPS
   - SESSION_SECURE_COOKIE=true
   - Configurar backups automáticos (sugerir laravel-backup package)
   - Comando de deploy: `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, `npm run build`

Não executar nenhuma migração. Só preparar configurações e documentação.
