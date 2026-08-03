# Auditoria de velocidade de uso — Piscinas MMCrespo

Data: 2026-08-01 · Branch: `claude/app-performance-ux-optimization-55a9j8`

Objetivo: **quantos toques e quantos segundos custa fazer o trabalho real, e o que mudar
para custar menos**. Oito auditores usaram a app como utilizadores diários (técnico,
nadador-salvador, gestor, admin), com browser real, em telemóvel (390×844) e desktop
(1440×900), e só depois foram ao código localizar a causa de cada fricção.

## Como foi testado

- **Não foi possível testar em produção**: a política de rede desta sessão bloqueia
  `piscinasmmcrespo.up.railway.app` e o staging (o proxy devolve 403 ao CONNECT). Montou-se
  a app neste ambiente a partir do branch de trabalho, com dados realistas: 5 piscinas,
  3 instalações, 153 registos diários ao longo de 30 dias, 325 leituras de sonda (incluindo
  leituras frescas), 7 incidentes (3 abertos), 25 ações operacionais, stock com produtos
  abaixo do mínimo, bidões a níveis variados.
- Browser real (Chromium/Playwright) com medição de `ttfb`, `domcontentloaded`, tempo até a
  informação ficar visível, número de pedidos, peso transferido, erros de consola/HTTP,
  contagem de alvos de toque abaixo de 44 px e altura de página em ecrãs de scroll.
- Cada tarefa foi executada de ponta a ponta e os **toques foram contados**: criar registo
  diário, corrigir registo, criar e resolver incidente, transferir stock, reabastecer bidão,
  gerar o livro sanitário em PDF, convidar um nadador-salvador, alterar limites legais.
- Ficaram 21 rotas do painel exercitadas, 107 screenshots e ~50 scripts de teste.

**Duas ressalvas de leitura.** (1) O container não tem saída para CDNs, por isso os tempos
absolutos que envolvem fontes externas (Google Fonts, jsdelivr) não são os de produção — mas
revelaram um problema real, descrito em P0-3. (2) Um artefacto do `php artisan serve` obrigou
a repetir algumas navegações; essas repetições não foram contadas como toques.

## O que quebra trabalho hoje (P0 — corrigir primeiro)

Não são questões de gosto: cada um destes foi reproduzido e confirmado no código.

| # | Problema | Evidência | Ficheiro |
|---|----------|-----------|----------|
| P0-1 | **Guardar as Definições põe todos os limites legais a zero.** `save()` grava `''` nos campos vazios e `SettingsService::get()` devolve `''` em vez do default (`'' ?? $default` não dispara). Depois de um Guardar: `getPhMax()=0.0`, `getPhMin()=0.0`, `getTransparenciaMax()=0.0`, e um pH de 7,4 passa a "VERMELHO — acima do máximo (0)". Zera também o fator de dosagem e desliga os digests de turno/conformidade. A tabela `app_settings` começa vazia (não há seeder de defaults), por isso basta o primeiro Guardar numa instalação nova | Reproduzido; código confirmado | `Definicoes.php:277-291`, `SettingsService.php:41` |
| P0-2 | **A sugestão de dosagem aparece 1000× maior do que é.** A dose é calculada em ml/g e impressa com a unidade do produto (kg/L) e separador de milhares: pH 8,6 numa piscina de 170 m³ mostra "**+24.438 kg** de Minorador de pH" quando são 24,4 L. Um técnico que siga o hint sobredosa a piscina | Reproduzido no formulário; fórmula confirmada | `DosageCalculatorService.php:58-88`, `DailyRecordFormBuilder.php:354-360` |
| P0-3 | **Uma navegação pode pendurar 25 s por causa de recursos de terceiros.** O `<head>` carrega 4 recursos externos bloqueantes — Lato via `fonts.bunny.net` (do `->font('Lato')`), o **mesmo** Google Fonts duas vezes (render hook + `@import` no `app.css`) e o GLightbox por CDN em todas as páginas — e o service worker faz `fetch().catch()` **sem timeout**. Medido: 380 ms com o service worker bloqueado, 25,7 s com ele ativo e os CDNs sem resposta. Em produção não são 25 s, mas é o que acontece com wifi municipal com captive portal ou 4G fraca | Medido 3×; código confirmado | `sw.js:99-101`, `AdminPanelProvider.php:52,130-139`, `app.css:1` |
| P0-4 | **A página "Movimentos — Armazém" devolve HTTP 500 sempre.** `StockWarehouseLog` não tem a relação `produto()` que o resource usa em quatro sítios; `product_id` existe na tabela mas o `StockService` nunca o preenche. O fornecedor/nº de fatura que se escreve na "Entrada" nunca pode ser lido | HTTP 500 medido; `RelationNotFoundException` no log | `StockWarehouseLogResource.php:37,55,60,76-80`, `StockWarehouseLog.php` |
| P0-5 | **O atalho "Registo Rápido" do dashboard bloqueia a submissão.** Entrar com `?pool=X` cria uma linha vazia obrigatória em "Adições de Químicos" → "O campo produto é obrigatório" num passo onde o técnico nem esteve. E o modo `quick=1` desfaz-se no primeiro roundtrip Livewire (5 × `Livewire Entangle Error`), atirando o utilizador para o passo "Bombas" com os 6 passos completos | Reproduzido nos 3 caminhos de entrada | `DailyRecordFormBuilder.php:777,435-439,468,218` |
| P0-6 | **O rascunho automático nunca é restaurado.** O modal "Recuperar registo anterior?" aparece a +500 ms, mas a +3 000 ms o `setInterval(saveDraft, 2000)` já gravou o formulário vazio por cima; "Sim, recuperar" devolve campos em branco. Em paralelo, o `DataCloneError` a cada 2 s impede a gravação no IndexedDB — as fotos e o rascunho "à prova de crash" não existem | Reproduzido com `contador=1555` | `app.js:1568,1597,1024-1031` |
| P0-7 | **O gestor pode mudar a password e o PIN dos admins.** `canEdit()` bloqueia a edição de admins, mas as ações "Enviar Email de Redefinição" e "Forçar Pass / PIN" não têm `visible()`/`authorize()` e aparecem nas linhas do Daniel, do Márcio e do Admin | Reproduzido com o papel `gestor` | `UserResource.php:210,226` |
| P0-8 | **"Resolver" no dashboard pode esconder uma violação legal para sempre.** Com a condição ainda ativa, o alerta desaparece das duas listas (não fica em "ativos" nem em "resolvidos hoje"), sem confirmação e sem undo — testado num registo com pH 8,40 e cloro livre 0,20. Nos incidentes, o mesmo botão só grava `AlertState`: o incidente continua aberto e continua a escalar às 24 h | Reproduzido | `QuadroOperacionalWidget.php:92-96,152-159`, `quadro-operacional.blade.php:43-45` |

## Onde está o tempo perdido (ganhos de velocidade)

**Peso das páginas.** Cada página transfere 2,5–3,1 MB, dos quais **1,27 MB são um único PNG**
(`logo_mmcrespo_branco.png`, servido a 100 px de altura) — 44% do peso. Não há `gzip` para
JS/CSS no nginx (`nginx-site.template` não define `gzip_types`), logo ~1 MB de JS/CSS viaja sem
compressão. `/images/` não tem `Cache-Control`, por isso o logo é revalidado em cada página.
`echo.js` (90 kB) é carregado sem broadcasting configurado. Corrigir estas quatro coisas leva
cada página para menos de 600 kB — é a maior melhoria de velocidade por linha de código do
relatório inteiro. (Chart.js já está code-split corretamente.)

**Tempo artificial.** O preloader de ecrã inteiro esconde-se com `setTimeout` de 500 ms + 250 ms
de fade **depois** do DOMContentLoaded, com `pointer-events: auto` — ou seja, ~750 ms de splash
opaco por navegação, medidos a tapar conteúdo que já estava utilizável aos 245 ms.

**Servidor não é o gargalo:** TTFB de 79–290 ms em todas as rotas. Mas há N+1 a sério:
`/admin/esquema` faz **89 queries** por render (e repete-as a cada 30 s por causa do
`wire:poll.30s`), `/admin/analise-parametros` faz **61**, o painel do dashboard faz **51**, e a
Análise calcula o payload do gráfico **duas vezes** por interação (29 queries). Em PostgreSQL na
Railway, cada query é um round-trip.

**Polling em tudo.** Nove tabelas fazem `->poll('10s')` — 360 pedidos/hora por página aberta.
Medido: 1,6 MB/minuto nas listas de stock, numa informação que muda duas ou três vezes por dia.
Os widgets do dashboard a 30 s devolvem HTML idêntico ~20 vezes por janela de cache.

**Mobile.** A app abre com a gaveta de navegação aberta a tapar 82% do ecrã (1 toque para
fechar, em cada carregamento). No formulário de registo, o primeiro campo está a 1 635 px
(≈2 ecrãs) por causa da secção "Início" (372 px, com um select "Responsável" desativado que só
repete o nome de quem está autenticado) e do cabeçalho de 6 passos (438 px). No dashboard, "o
que tenho de fazer a seguir" está a 5,4 ecrãs de scroll, depois de um gráfico analítico de 7
dias. As tabelas de stock são 2–3× mais largas que o ecrã. 43 de 53 alvos de toque do formulário
estão abaixo de 44 px.

**Contexto que se perde entre ecrãs.** Os cinco alertas "sem registo hoje" já sabem a piscina e
mandam o utilizador para um formulário vazio (`create` sem `?pool=`). Criar um incidente não
herda piscina nem instalação (4 dos 11 toques são a repetir o que se viu no ecrã anterior). O
segundo registo do dia não herda contador, `agua_modo`, pressão nem hora. A sonda Hanna tem
pH/ORP/temperatura frescos e não é oferecida para preencher nada.

**Buscas que não encontram.** Não existe pesquisa global (nenhum resource define
`getGloballySearchableAttributes`; `Ctrl+K` não faz nada). Nos incidentes, "bomba" devolve zero
resultados apesar de existir "Bomba do filtro com ruido anormal" — a pesquisa só cobre
instalação e tipo, e o único filtro é o Estado. No log de stock não há filtro de data nem soma,
por isso "quanto gastei de cloro este mês" não tem resposta possível na interface.

## Papéis: quem não consegue trabalhar

- **Técnico não tem acesso ao Esquema** (`/admin/esquema` devolve 403 e o item não aparece na
  sidebar) — é a página da casa das máquinas, com atalhos de 1 toque para lavagem de filtro,
  fecho de torneira e leitura de contador, e está fechada a quem faz esse trabalho.
- **Técnico não pode corrigir uma ação operacional** que ele próprio criou (`canEdit()` só
  admin, enquanto `EditOperationalAction::mount()` autoriza técnico) e a vista não tem botão
  "Editar" para ninguém.
- **Gestor tem 403 no Relatório PDF**, mas é destinatário da notificação do relatório mensal —
  e os PDFs mensais só existem no link dessa notificação, sem listagem no painel.
- **Gestor vê o alerta de stock baixo no dashboard e tem 403 nas seis páginas de stock**, ao
  contrário do que as próprias Policies autorizam.
- **Nadador-salvador sem piscinas atribuídas** vê "Criar Incidente" e "Criar registo" e encontra
  selects vazios ou um 403.
- **Qualquer 403 diz "A sua conta foi encerrada por inatividade"** — a mensagem errada, que gera
  um telefonema ao administrador de cada vez que alguém toca numa página que não é dele.

## Plano sugerido

**Vaga 1 — dois dias, tudo pequeno, corrige o que está quebrado**
P0-1 a P0-8, mais: mensagem de 403 correta, logo em WebP, `gzip` no nginx, `Cache-Control` em
`/images/`, preloader sem `setTimeout`, sidebar fechada abaixo de 1024 px, `poll` das listas de
10 s → 60 s (ou removido), `?pool=` nos links dos alertas, seeder de defaults para
`app_settings`. Ganho: as páginas passam de ~2,8 MB para <600 kB, a navegação deixa de ter
750 ms artificiais e o registo da manhã deixa de rebentar no atalho.

**Vaga 2 — uma semana, muda o dia-a-dia**
Reordenar os widgets do dashboard (alertas antes dos gráficos) e abrir o painel recolhido em
mobile; dar o Esquema ao técnico e torná-lo legível a 390 px; pré-encher o registo diário
(contador, `agua_modo`, hora, valores da sonda com um toque); ligar a lista de incidentes à
página do incidente, com descrição, idade, pesquisa na descrição e filtros; defaults e contagem
prévia no Relatório PDF; corrigir o tab "Tabela" da Análise; alinhar `canAccess` com as Policies
para o gestor.

**Vaga 3 — quando houver espaço**
Ecrã único de stock (uma linha por produto, total, armazém, instalações, bidões, ações inline);
lista de "Violações no período" na Análise; foto nos incidentes; consumo real dos bidões a
debitar stock; resolver os N+1 do Esquema, da Análise e do painel; pesquisa global; fechar a
questão do `/admin/operacao-hub` (hoje é uma página órfã: `shouldRegisterNavigation()` devolve
`false` e nenhum ficheiro do projeto lhe aponta).

## Detalhe por página

Os relatórios que seguem são o registo de cada auditoria, com medições, evidências e a linha de
código de cada correção proposta.

## Registo Diário — criar (`/admin/daily-records/create`)

**Tarefa (mobile 390×844, `tecnico`):** dashboard → "Registar" → Leiria → 3 fieldsets de piscina (contador, água, pressão, pH, Cl livre, Cl total, temperatura, banhistas, observações) + foto obrigatória do quadro NS → "Criar" → modal → "Confirmar e guardar". Registos 154/155/156 gravados. Repetido em desktop e para Maceira via `?pool=4`, incluindo adição de químico acima do stock.

**Medições:**
- **Leiria completo, mobile: 42 toques | 25 interações de campo | ~54 s líquidos** | 6 passos | **16 alturas de ecrã de scroll**
- Leiria completo, desktop: 41 toques | ~47 s
- Maceira (`?pool=4`, 1 piscina, 1 adição): **14 toques | 31,5 s**
- `ns` (com piscina atribuída): **8 toques | ~24 s | 1 passo, 5 campos**
- Página: `load=428–628ms settled=1,8s ttfb=89–201ms fcp=320–532ms requests=21–23`
- Latências: montar wizard 290 ms · upload de foto (1,8 KB) **2 324 ms** · validação do "Criar" 767 ms · gravação 830 ms · cada campo `live()` 438–455 ms (×12 só nas análises de Leiria)
- Geometria (1 piscina): "Início" 372 px + cabeçalho do wizard 438 px → **primeiro campo a 1 635 px** (≈2 ecrãs); "Criar" a 2 132 px. Passo NS com 3 piscinas = 3 434 px (4,1 ecrãs)
- Alvos: inputs 38 px, toggles 24 px → **43–44 de 53 abaixo de 44 px**. `inputmode` correto em todos os numéricos
- Consola: `DataCloneError` do rascunho a cada 2 s (≈130 por sessão); 5 × `Livewire Entangle Error` no modo `quick=1`

**Lista/vista/correção (`/admin/daily-records`):** `load=552–681ms settled=704–882ms requests=24`, `poll('10s')`, 10 registos/página (docH 3 720 px) | filtrar piscina+data = **10 toques / 22 s** | ver + corrigir = **8 toques / 22,8 s** | 32 de 114 alvos <44 px. Correção append-only funcionou (registo 159 → `corrige_registo_id=158`).

**Hub (`/admin/operacao-hub`):** rota órfã (`shouldRegisterNavigation()=false`, nenhum link no código); se lá se chegar, o cartão não tem `href` — abre modal e só depois oferece "Criar registo" (+2 toques).

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|-------------------------------|-------------------|----------------|---------|
| 1 | Alta | **Foto do "quadro NS" é obrigatória para o técnico**: sem ela a submissão falha. Custa 1 toque + fotografar + 2,3 s de upload com ficheiro de 1,8 KB (com foto real de iPhone, dezenas de segundos em 4G) | `required` só quando `self::isNS()` | `DailyRecordFormBuilder.php:650` | S |
| 2 | Alta | **O rascunho nunca é restaurado.** Rascunho com `contador=1555`; o modal "Recuperar registo anterior?" aparece a +500 ms mas a +3 000 ms o `setInterval(saveDraft, 2000)` já gravou o formulário **vazio** por cima → "Sim, recuperar" devolve campos em branco. Mais `DataCloneError` a cada 2 s | Não iniciar o auto-save enquanto o prompt estiver pendente (nem gravar formulário pristine); sanitizar o payload antes do `put` | `resources/js/app.js:1597`, `:1568`, `:1024-1031` | M |
| 3 | Alta | **Entrar pelo cartão da piscina (`?pool=X`, botão "Registo Rápido") cria uma linha vazia obrigatória em "Adições de Químicos" e bloqueia a submissão** ("O campo produto é obrigatório") num passo onde o técnico nem esteve. Sem params → 0 linhas; `?pool=4` → 1 linha | `->defaultItems(0)` no Repeater e semear `pools` também quando a instalação vem do `?pool=` | `DailyRecordFormBuilder.php:777` e `:435-439` | S |
| 4 | Alta | **O modo rápido colapsa no primeiro roundtrip Livewire**: com `?pool=4&quick=1` os passos passam de 2 para os 6 completos ao mudar um campo, com 5 × `Livewire Entangle Error`, e o utilizador é atirado para o passo "Bombas". Causa: `request()->query('quick')` é lido na construção do schema e os POSTs para `/livewire/update` não têm query string | Guardar o modo numa propriedade da página (`mount()`), não em `request()->query()` | `DailyRecordFormBuilder.php:468` e `:218` | M |
| 5 | Alta | **Sugestão de dosagem 1000× errada.** pH 8,6 em Maceira (170 m³): "⚡ Sugestão: **+24.438 kg** de Minorador de pH"; cloro 0,3: "**+2.019 L** de Germicida". A dose é calculada em ml/g e rotulada com `produto->unidade` (kg/L) | Converter ml→L/kg antes de formatar (ou devolver a dose já na unidade do produto) | `DosageCalculatorService.php:58-71,78-88`; texto em `DailyRecordFormBuilder.php:354-360` | S |
| 6 | Alta | **Nada é reaproveitado entre registos.** No 2º registo do dia: instalação, contador, `agua_modo`, pressão e `hora_colheita` vazios. O "smart default"/lookback documentado não existe no builder (`grep Último` = 0). O valor do contador só aparece **depois** de falhar a validação. A sonda Hanna tem pH/ORP/temp frescos mas só é usada para comparar ("📡 Sonda: ✓ Conforme", sem números) | `placeholder`/`helperText` com a última leitura; herdar `agua_modo`/`bomba_ferrada`; `default(now())` em `hora_colheita` também no remount; botão "usar valor da sonda" | `DailyRecordFormBuilder.php:232-258,484-501,644-649,655-694` | M |
| 7 | Alta | **A 390 px a barra lateral abre por omissão e tapa o formulário** — 1 toque a fechá-la em cada carregamento (`$persist(true)` do Filament + `.fi-sidebar{position:fixed!important}`) | Fechar a sidebar por omissão abaixo de `lg` | `resources/css/app.css:189`; store do Filament | S |
| 8 | Média | **2 ecrãs de scroll antes do primeiro campo, em todos os passos**: "Início" (372 px, com select "Responsável" desativado que repete o nome do autenticado) + cabeçalho de 6 passos (438 px); "Criar" fora do wizard a 2 132 px; a barra flutuante tapa campos ("Banhistas") | Colapsar "Início" após escolher instalação e remover "Responsável"; cabeçalho compacto em mobile; ações sticky; `padding-bottom` | `DailyRecordFormBuilder.php:407-454`; `CreateDailyRecord.php:191-229`; `bottom-nav.blade.php` | M |
| 9 | Média | **Os hints do semáforo destroem o layout do passo NS em mobile**: o texto é renderizado na linha do label de uma grelha de 2/4 colunas e quebra uma palavra por linha, empurrando os inputs para 136 px. Passo NS de Leiria = 3 434 px | Hint curto (só veredicto) + detalhe em `helperText` a `columnSpanFull`, ou 1 coluna por piscina em mobile | `DailyRecordFormBuilder.php:338-370`, `:719` | M |
| 10 | Média | **Encontrar "piscina X na data D" = 10 toques / 22 s**; com o datepicker "De" aberto o campo "Até" fica inacessível. Filtros num modal sem "Aplicar", sem atalhos de período; lista com `poll('10s')` | Filtros inline em mobile com "Hoje/Ontem/7 dias"; `closeOnDateSelection`; poll 60 s ou remover | `DailyRecordTableBuilder.php:104-130`, `:34` | S |
| 11 | Média | **Na vista os valores da água estão atrás de um separador**: o slideOver abre em "Geral" (Bomba, Contador, Água, Tanque) — pH/cloro estão em "Análises", +1 toque por consulta. Nos cartões aparece "Cl. Comb.: **−0,49** ✓" | Abrir na tab "Análises" ou pôr pH/Cl no topo; combinado negativo = dado inválido | `DailyRecordTableBuilder.php:395-420` | S |
| 12 | Média | **Sem guarda de duplicado e sem aviso de stock parcial ao autor.** Dois registos da mesma piscina no mesmo dia sem aviso (ids 157/158). Na adição de 5 L com 2 L em stock: desce 2→0, o registo fica com 5 e **só os admins** recebem "Stock insuficiente"; o técnico vê "Registo guardado!" | Avisar quando já existe registo hoje (e oferecer "Corrigir"); notificar também quem submeteu | `DailyRecordService.php:56-60`; `ProcessDailyRecordAfterCreate.php:123-195` | S |

**Outros pontos verificados:** duas folhas externas render-blocking no `<head>` (`->font('Lato')` via bunny.net + Google Fonts com Montserrat+Lato) — quando não respondem o FCP salta de 0,4 s para 25,7 s; Lato duplicado. Preloader esconde-se 500 ms + 250 ms após DOMContentLoaded (~0,75 s por navegação). A página 403 diz sempre "A sua conta foi encerrada por inatividade" — foi o que o `ns` sem piscinas recebeu. `Data do Registo`/`Hora da colheita` são inputs nativos e apareceram em formato US. Com `QUEUE_CONNECTION=sync` o job pós-criação corre no pedido e uma falha de notificação faz rollback do registo (em produção há bcmath e fila `database`, mas o acoplamento é frágil).

**Nota de método:** navegações por clique devolveram HTML truncado (artefacto do `artisan serve`), contornado com reload e não contado como toque; explica ~30 s nos tempos de parede. A atribuição de piscinas do `ns@mmcrespo.pt` foi alterada temporariamente para testar o papel e revertida.

### Top 3
1. Pré-encher em vez de exigir (contador/pressão/`agua_modo`/hora/sonda): corta a maior parte das 25 interações de campo e elimina o ciclo "submeter → erro do contador → voltar 5 passos".
2. Desbloquear os atalhos que já existem mas estão quebrados: `defaultItems(0)` e o modo `quick=1` a sobreviver aos roundtrips — o caminho de 14 toques passa a ser o normal em vez de um beco de 42.
3. Tirar 900 px de cabeçalho e a sidebar do caminho em mobile: ~2 ecrãs de scroll por passo e 1 toque por carregamento.
## Dashboard (`/admin`)

**Tarefa testada:** como `tecnico` e `gestor`, em 390x844 e 1440x900: abrir /admin e responder às 4 perguntas operacionais contando scrolls/toques; usar os CTAs por piscina (`Registo Rápido` → verificar pré-seleção), os links dos alertas, resolver um cartão e recarregar; configurar o gráfico para ver cloro/pH 7d de uma piscina; tentar agir a partir do StockBaixoWidget; contar pedidos `/livewire/update` ao abrir e ao longo de 90 s.

**Medições (mobile, `tecnico`):** cliques até 1ª resposta útil=0 (scroll) | ttfb=100 ms | dcl=470 ms | settled (1º valor de piscina no DOM)=1548 ms | requests=33 (2 665 KB, **1 274 KB só do logo**) | erros=0 (5 = CDNs bloqueados pelo harness)
**Desktop:** ttfb=106 ms | dcl=362 ms | settled=978 ms | requests=26
**Livewire:** 6 `/livewire/update` ao abrir (um `__lazyLoad` por widget + notificações; o mais lento 544 ms) → depois 1 pedido/30 s (3 widgets com poll vão batched). Polling não perde scroll nem estado dos cartões.
**Preloader** azul opaco tapa a página de 194 ms a 995 ms, com o conteúdo já no DOM aos 245 ms.
**Página mobile = 7 054 px = 8,4 ecrãs.** KPIs 194 px · 1º cartão 469 px · gráfico 4 247 px (5,0 ecrãs) · Alertas 4 597 px (5,4) · "sem registo hoje" 5 036 px (6,0) · stock 6 287 px (7,4).

**Respostas às 4 perguntas (mobile, `tecnico`):**
| Pergunta | Respondível? | Custo |
|---|---|---|
| Quais piscinas estão fora dos limites agora? | Sim | 4,2 ecrãs de scroll (ou 1 toque "Recolher todas" → 1,1 ecrãs) |
| Qual falta registar hoje? | Parcial — KPI diz "0/5" mas não *quais* | 6 ecrãs + 1 toque |
| Há alguma sonda offline? | **Não** | impossível no dashboard e nas páginas visíveis ao técnico |
| O que tenho de fazer a seguir? | Sim, mas a 5,4 ecrãs | 5–6 scrolls |

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|------|------|------|---------|
| 1 | Alta | Ordem dos widgets em mobile põe os analíticos a estrangular os operacionais: Painel → Gráfico 7d → Alertas (y=4597, 5,4 ecrãs) → Stock → Estabilidade. Os `$sort` (-30, 2, -20, -10, 6) são inertes: `Dashboard::getVisibleWidgets()` não ordena, usa a ordem do array | Ordem: Painel → Alertas → Stock → Gráfico → Estabilidade. Gráfico e Estabilidade só em Análise de Parâmetros | `app/Filament/Pages/Dashboard.php:41-47` | S |
| 2 | Alta | Painel abre com as 5 piscinas expandidas: 4,2 ecrãs para varrer. "Recolher todas" dá 1,1 ecrãs com badge por piscina — a vista exception-first existe mas está desligada por defeito | `allOpen`/`open` = false quando `innerWidth < 1024`; badge a dizer *qual* parâmetro falha | `resources/views/filament/widgets/painel-piscinas.blade.php:44,66,118` | S |
| 3 | Alta | "Resolver" faz o alerta desaparecer das duas listas, sem undo: com `AlertState=resolvido` e condição ainda ativa não entra em ativos nem em resolvidos. Testado no registo 147 (pH 8,40 / Cl. livre 0,20 / Cl. comb. 1,13 confirmados em BD) | Listar resolvidos-hoje cuja condição persiste, com "ainda fora dos limites" + "Reabrir"; confirmação quando é violação legal | `QuadroOperacionalWidget.php:92-96` e `152-159` | M |
| 4 | Alta | Duas respostas contraditórias no mesmo ecrã: KPI "PISCINAS CONFORMES 2/5" (base = última leitura de sonda) vs "0/5 piscinas conformes hoje" no widget Alertas. Idem "REGISTOS HOJE 0/5" vs "Sem registo diário hoje (5)" | Uma só definição (a do `AlertasService`) alimenta KPI e Alertas; KPI passa a link | `PainelPiscinasWidget.php:524` vs `AlertasService.php:287` | M |
| 5 | Alta | Alertas que já sabem a piscina não a passam ao formulário: os 5 subalertas "sem registo hoje" apontam para `/admin/daily-records/create` sem `?pool=`; igual no alerta de torneira. O cartão da piscina já faz `?pool=N&quick=1` | `getUrl('create', ['pool' => $piscina->id, 'quick' => 1])` nos dois sítios | `app/Services/AlertasService.php:252` e `:310` | S |
| 6 | Alta | 1 274 KB de logo em todos os carregamentos (48% dos 2 665 KB): `logo_mmcrespo_branco.png` no preloader e como variante dark (`display:none`, mas descarregada). Existe `logo-mmcrespo.png` com 34 KB | Reduzir o PNG branco (≤40 KB) e trocar a variante dark por CSS `filter`/`<picture>` | `resources/views/filament/preloader.blade.php:2`, `filament/brand-logo.blade.php:12` | S |
| 7 | Alta | "Há alguma sonda offline?" não é respondível: com registo manual ≤8 h a cascata escolhe `manual` e o estado da sonda desaparece do cartão. Técnico não tem alternativa (`EsquemaPiscina::canAccess()` e Sensores Hanna são admin-only) | Linha fixa por cartão com idade da última leitura da sonda, independente da fonte escolhida | `app/Services/SourceSelectionService.php:60-90`; blade `:154-160`; `EsquemaPiscina.php:66` | M |
| 8 | Média | Preloader opaco impõe ~750 ms fixos por navegação (timer 500 ms + fade 250 ms) com DOM pronto aos 245 ms | Esconder no `DOMContentLoaded` (≤150 ms) e não reaparecer em cliques de link | `resources/views/filament/preloader.blade.php:64-66,79-92` | S |
| 9 | Média | 51 queries / 81 ms para construir o painel: coleções batch descartadas porque `SourceSelectionService::selectSource()` é chamado dentro do `map()` por piscina (+ janela ORP ±60 min por piscina) → 8 queries por piscina | Passar `$sondas`/`$ultimasLeituras`/`$ultimosRegistos` a `selectSource()`; ORP com um `whereIn` | `PainelPiscinasWidget.php:311,285-294` | M |
| 10 | Média | Cache de 10 min congela a frescura visível ("Sonda • há 3m" pode ter 13 min) e o poll de 30 s devolve HTML idêntico ~20x. Nos alertas há duas camadas (30 s por cima de 5 min) e os observers só invalidam a de dentro. `DB::transaction` de auto-resolução corre em cada render/poll | Cachear timestamps e formatar na blade; remover camada de 30 s; poll 60 s; mover auto-resolução para o comando agendado | `PainelPiscinasWidget.php:41,75,337-341`; `QuadroOperacionalWidget.php:37,62,140-150`; `CacheService.php:137` | M |
| 11 | Média | `StockBaixoWidget` só informa e está cortado no telemóvel: tabela de 602 px em contentor de 356 px → "Limite Mínimo" fora do ecrã; 0 links, 0 ações | Em mobile 2 colunas ("Instalação · Produto", "0,000 / 10,000") + ação "Transferir do armazém" | `StockBaixoWidget.php:48-66` | M |
| 12 | Média | O cartão nunca diz "sem registo hoje" apesar de o widget calcular `sem_hoje` e `url_registar`. Vermelho gasto: ORP fora de banda pinta "Alerta" em 3 de 5 cartões sem gerar ação, enquanto Cl. Combinado negativo (−0,05 / −0,55) aparece verde "OK" | Badge "falta registar hoje"; ORP em âmbar informativo; combinado <0 → "verificar medição" | `PainelPiscinasWidget.php:514,518,377,426`; blade `:131,148` | S |

**Gráfico:** legível a 390 px, mas denso; "cloro/pH de uma piscina" = 2 toques (7d por defeito). Problema é estar a 5 ecrãs e ocupar o lugar dos alertas.
**Como `gestor`:** dashboard igual menos as ações rápidas por cartão, **mas mantém os 8 botões "Resolver"** (`QuadroOperacionalWidget::canView()` só exclui o NS) — papel de leitura pode apagar alertas de violação legal.
**Estados vazios:** `—`/"N/A" não distingue "sonda sem leitura" de "nunca registado"; `há Xh` não diz quando deixa de servir; `0,00 FNU` indistinguível de não medido. Só o vazio do Stock está bem redigido.

### Top 3
1. Reordenar widgets + painel recolhido em mobile (1+2): "o que fazer a seguir" passa do 6º para o 2º ecrã; varrimento de 4,2 → 1,1 ecrãs.
2. Corrigir semântica de "Resolver" + `?pool=` nos links de alerta (3+5): evita esconder violação legal com um toque; −2 toques por registo iniciado de um alerta.
3. Cortar 1,2 MB de logo + timer fixo do preloader (6+8): ~750 ms por navegação e ~48% dos bytes.
## Incidentes (`/admin/incidents`, `/create`, `/{id}`, `/{id}/edit`)

**Tarefas testadas (mobile primeiro, depois desktop):**
1. `tecnico`: dashboard → criar incidente completo — **11 toques + 1 scroll**, sem modal de confirmação.
2. `tecnico`: alerta "Ver incidente" → escrever no chat → Resolver com texto — **5 toques + ~7 scrolls**; pela lista (⋮ → Resolver) **4 toques**.
3. Encontrar o incidente resolvido da Maceira: **7 toques**.
4/5. Papéis `ns`/`gestor` e ligações com dashboard/registo verificadas em código e browser.

| rota | ttfb | dcl | settled | requests | erros |
|---|---|---|---|---|---|
| `/admin/incidents` | 131-307ms | 404-601ms | 563-731ms | 22 | 0 |
| `/admin/incidents/create` | 129-287ms | 537ms | 2178ms | 23-29 | 0 (500 no submit, ver #11) |
| `/admin/incidents/2` | 301ms | ~600ms | 2158ms (+130ms chat lazy) | 29 | 0 |

20-28 alvos tácteis <44px por página (inclui **Criar** e **Resolver**).

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|-------------------------------|-------------------|----------------|---------|
| 1 | Alta | **Da lista não se chega à página do incidente.** Tocar no cartão abre um slideOver com o *formulário desativado* — sem conversa, sem estado, sem Resolver, com helperText de formulário numa vista de leitura. O ⋮ só tem "Resolver" e "Visualizar" (mesmo slideOver). A página com o chat só se alcança pelo alerta do dashboard ou por notificação | `->recordUrl(fn ($r) => IncidentResource::getUrl('view', ...))`; tirar `->slideOver()`; criar `infolist()` em vez de reutilizar o form | `IncidentResource.php:181-182,233` | M |
| 2 | Alta | **A lista não diz de que se trata nem há quanto tempo.** Cartão = instalação, piscina, data, técnico, tipo, estado; a descrição nunca aparece; um incidente de 16/07 é igual a um de hoje | `->description(fn ($r) => Str::limit($r->descricao, 80))`; `ocorreu_em->since()` na 2ª linha | `IncidentResource.php:190-208` | S |
| 3 | Alta | **A pesquisa não cobre a descrição nem a piscina.** "bomba" → 0 resultados com o incidente "Bomba do filtro com ruido anormal"; "Lazer" → 0. Só `instalacao.name` e `type` são `searchable()` | `searchable()` numa coluna `descricao` (oculta) e em `piscina.name` | `IncidentResource.php:194,214` | S |
| 4 | Alta | **Só existe 1 filtro (Estado)** — sem instalação, piscina, tipo ou datas; e o filtro por omissão "Aberto" devolve "Sem Incidentes" sem dizer que está a esconder registos. Encontrar o resolvido da Maceira: 7 toques | 3 `SelectFilter` + filtro de datas; `emptyStateDescription` a mencionar o filtro ativo | `IncidentResource.php:224-229` | S |
| 5 | Alta | **Não se pode anexar foto a um incidente** — zero `FileUpload`, enquanto o registo diário tem 8 campos em `r2`. Uma avaria descrita a texto obriga a WhatsApp em paralelo | `FileUpload::make('fotos')->disk('r2')->multiple()->maxSize(20480)` + tabela `incident_photos` | `IncidentResource.php:150-156` | M |
| 6 | Alta | **Zero contexto pré-preenchido:** nada de `?pool=`/`?installation=` nem "última instalação usada". 4 dos 11 toques repetem o que o utilizador acabou de ver | `default(fn () => request()->integer('installation') ?: ...)` e idem `pool`; botão "Reportar incidente" no cartão da piscina e na vista do registo | `IncidentResource.php:105-134` (padrão: `OperationalActionResource.php:174`) | S |
| 7 | Média | Campo **"Técnico / Nadador-Salvador" é um select desativado com o próprio utilizador** — 96px, zero informação, e empurra a Descrição para fora do ecrã (form 1119px vs viewport 844px) | `->default(auth()->id())->dehydrated()->hidden()` ou `Placeholder` | `IncidentResource.php:135-141` | S |
| 8 | Média | **`ocorreu_em` obrigatório, formato americano com segundos:** "08/01/2026, 12:06:56 AM" no picker nativo vs "27/07/2026 23:44" na tabela | `->seconds(false)->native(false)->displayFormat('d/m/Y H:i')` | `IncidentResource.php:142-145` | S |
| 9 | Média | **Dois botões "Resolver" com efeitos diferentes.** No dashboard o botão é `moverAlerta('incidente\|3','resolvido')`: só grava `AlertState` e esconde o cartão (volta amanhã) — o incidente continua `aberto` e continua a escalar às 24h. Incidentes entram como nível `neutro` → fim da lista, y≈5000px (≈6 ecrãs) | Nas linhas `incidente`, link para a ação real de resolução (ou renomear "Tratado hoje"); nível `amarelo` a incidentes abertos | `quadro-operacional.blade.php:43-45`; `AlertasService.php:165-174` | M |
| 10 | Média | **Chat sem polling e escondido.** O `IncidentChatWidget` só re-renderiza no próprio envio (a tabela faz `poll('10s')`, a conversa não); é `lazy` e fica a ~1,5 ecrãs de scroll | `$pollingInterval = '15s'`; `$isLazy = false` | `IncidentChatWidget.php:29` | S |
| 11 | Média | **Notificações enviadas dentro do request.** `Notification::send()` sem `ShouldQueue`: ao tocar "Criar" espera-se por database+WebPush(+mail) de todos os admin/técnicos. No sandbox (sem GMP/BCMath) devolveu **500 depois de gravar**. Em produção há bcmath → é latência, não erro | `implements ShouldQueue` nas duas notificações | `CreateIncident.php:40-43`; `IncidentResource.php:338-341` | S |
| 12 | Média | **`requiresConfirmation()` no Criar/Guardar é código morto** — `getCreateFormAction()` usa `->submit('create')` que ignora modais (verificado: grava direto). E `createAnother` foi removido → dois incidentes seguidos obrigam a voltar à lista | Apagar `requiresConfirmation()`; devolver `getCreateAnotherFormAction()` | `CreateIncident.php:22-30`; `EditIncident.php:29-37` | S |

**Papéis e coerência:** `ns` sem piscinas vê "Criar Incidente" mas o select Instalação abre com **0 opções**; `ns` vê 0 incidentes e 404 nos de outros (correto). `gestor` vê todos, **pode criar** e **escrever no chat** (o widget não verifica permissões), mas não resolve nem edita — contradiz "gestor = leitura". Tipos fora do enum (`sanitario`, `equipamento`) aparecem em branco na vista e como `ucfirst` na tabela (`AlertasService.php:169` usa `$incidente->type` cru em vez de `IncidentType::label()`).

### Top 3
1. Ligar a lista à página do incidente + descrição e idade no cartão (#1+#2).
2. Pesquisa na descrição + filtros por instalação/piscina/tipo/data (#3+#4).
3. Contexto pré-preenchido, formulário mais curto e foto (#6+#7+#5): −4 dos 11 toques.
## Esquema do Circuito de Água (`/admin/esquema`) + Ações Operacionais

**Tarefa testada:** como `tecnico`, abrir o esquema e responder a "torneira aberta? bomba ferrada? nível dos bidões? sonda online?"; trocar instalação/piscina; registar dali lavagem de filtro / fecho de torneira. Como `tecnico` a página devolve **403** — repetido como `admin`.
**Medições (mobile 390×844, admin):** ttfb=138–257ms | dcl=431–514ms | settled≈1,7s | requests=21 | **79 queries / 81ms** de render para Leiria (3 piscinas), 27 para 1 piscina | página=3727px mobile, 2072px desktop.
**Ações Operacionais:** lavagem=**6 toques/40s** · torneira=**8 toques** · bidão=**8 toques** · filtrar lista=**7 toques/13s** · pelo dashboard ("Lavar filtro" no cartão)=**2–3 toques** | lista: ttfb=194ms dcl=549ms requests=22 (+`poll('10s')`).

**Respostas cronometradas (mobile, admin):**
| Pergunta | Como se responde | Custo |
|---|---|---|
| Torneira aberta? | ponto de 8px sem texto; texto só no painel de detalhe | 0 toques (ambíguo) ou 2 toques + 1 scroll |
| Bomba ferrada? | idem; deu `?`/cinzento em 3/3 piscinas | 2 toques + scroll, resposta "desconhecido" |
| Nível dos bidões? | %, litros e desenho no cartão e no detalhe | **0 toques** — o único bem resolvido |
| Sonda online? | "Controlador · há 43 minutos" dentro do SVG, a **7,7px** e cortado | 1 swipe horizontal + esforçar a vista |
| Trocar instalação / piscina | chip / cartão | 1 toque cada |

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|-------------------------------|-------------------|----------------|---------|
| 1 | Alta | O técnico não tem esquema: `/admin/esquema` devolve 403 e o item não aparece na sidebar. A página tem código só útil a não-admins (filtro de piscinas NS, `@can('create', DailyRecord)`) que para admin é sempre verdadeiro | `hasAnyRole([ADMIN, GESTOR, TECNICO])`; NS já é filtrado em `piscinasPermitidas()` | `EsquemaPiscina.php:66-69` (guards mortos em `partials/esquema-circuito.blade.php:36,228,260,263,303,306`) | S |
| 2 | Alta | Qualquer 403 diz "**A sua conta foi encerrada por inatividade**". O técnico que toque no esquema lê que perdeu a conta | Texto neutro; mensagem de inatividade só com `session('mmc_inativo')` | `resources/views/errors/403.blade.php:46-47` | S |
| 3 | Alta | O técnico cria a ação mas não a pode corrigir (1200 m³ em vez de 120 → `/edit` dá 403). `EditOperationalAction::mount()` autoriza TECNICO, mas `canEdit()` só admin. A vista não tem botão "Editar" para ninguém | `canEdit()`: admin sempre; técnico nas suas ações <24h. Adicionar `EditAction` em `getHeaderActions()` | `OperationalActionResource.php:65-68` vs `Pages/EditOperationalAction.php:21-24`; `Pages/ViewOperationalAction.php` | S |
| 4 | Alta | No telemóvel o esquema esconde o que interessa: `min-width:560px` em janela de 306px → 7 textos cortados; SVG escala a 0,7 → texto real de **7,7–9,1px** | Abaixo de 480px layout empilhado (ou `viewBox` do troço + valores em HTML fora do SVG) | `resources/css/esquema.css:133-143`; `partials/esquema-circuito.blade.php:46-51` | M |
| 5 | Alta | Às 9h, antes do registo do dia, o esquema responde "?": `estaStale()`=8h → torneira/bomba/tanque desconhecidos (Bomba cinzenta em 3/3, Torneira em 2/3). O contador não aparece no desenho | Mostrar sempre o último valor com idade ("Bomba: ferrada ontem 09:12"); contador no nó | `EsquemaPiscina.php:310-313,344-364,374-378`; blade `:104-108,193-198` | M |
| 6 | Média | Tocar num componente parece não fazer nada: painel de detalhe abre fora do ecrã (y=679, altura 539, viewport 844) | Painel acima do SVG em mobile, ou `scrollIntoView` no `toggle()` | `partials/esquema-circuito.blade.php:236`; `resources/js/app.js` (`mmcEsquema`) | S |
| 7 | Média | Ação mais frequente custa 6 toques porque a piscina nunca vem escolhida (`default` só lê `?pool=`); pelo dashboard custa 2 | Fallback: última piscina do próprio utilizador (padrão do `DailyRecordResource`) | `OperationalActionResource.php:174` | S |
| 8 | Média | 79 queries por render (Leiria) e o `wire:poll.30s` repete-as: `selectSource()` chamado 2× por piscina (`buildPoolState` e `valoresAgua`) | Passar o `$source` já calculado; `wire:poll.60s` | `EsquemaPiscina.php:164-166` e `503-506`; `esquema-piscina.blade.php:30` | S |
| 9 | Média | Lista em 390px: tabela de 1214px em janela de 356px — "Valores" começa em x=521, só por swipe sem indicação. Faz `poll('10s')` = 6 pedidos/min em dados móveis | Esconder colunas abaixo de `sm`, resumo em `description()` da coluna "Ação"; `poll('60s')` ou nenhum | `OperationalActionResource.php:436,438-467` | M |
| 10 | Baixa | Alvos táteis <44px: chips de instalação 40px, nó Torneira/Contador **59×34**, "Gerir / reabastecer" 138×**20**; pontos de estado são círculos de 8px só a cor | 44px mínimo; caixa da torneira maior; texto junto ao ponto | `esquema.css:79-90,622-633,747-753`; blade `:91` | S |
| 11 | Baixa | Textos que mentem: detalhe diz "registo com mais de **24h**" quando o código usa 8h (docs repetem 24h); coluna "Valores" imprime JSON cru (`duracao_min: 7 \| valor: 27`); rótulo "Data e hora (colheita)" numa lavagem de filtro | Alinhar com 8h; traduzir chaves ou "—"; rótulo neutro | `esquema-circuito.blade.php:248,321,382` vs `EsquemaPiscina.php:312`; `OperationalAction.php:226-232`; `OperationalActionResource.php:200-201` | S |
| 12 | Baixa | (a) modal "Confirmar ação operacional" **nunca aparece** — `requiresConfirmation()` ignorado porque a ação-pai usa `->submit('create')` (contradiz o CLAUDE.md da pasta); (b) checkbox "Aplicar às 3 piscinas de Leiria" desenha-se acima do select que o faz aparecer | (a) remover código morto; (b) mover checkbox para depois do `tipo` | `Pages/CreateOperationalAction.php:52-58`; `OperationalActionResource.php:184-198` | S |

**Nota de ambiente:** a 2ª navegação media ~26s de FCP; isolado é o service worker (`public/sw.js`) à espera dos CDNs bloqueados. Sem SW: 430ms. Em produção são 4 pedidos externos bloqueantes com rede fraca. A sidebar mobile abre por cima do conteúdo na 1ª visita (`isOpen` é `$persist(true)`), +1 toque.

**Respostas diretas:** o esquema **serve para agir** — cada componente tem CTAs ("Só fechar torneira", "Só lavagem de filtro"…) que abrem o formulário com piscina+tipo+hora preenchidos (`?pool=1&tipo=lavagem_filtro`, 1 toque). O problema é acesso e descoberta: quem trabalha na casa das máquinas não pode abrir a página. Lavagem de filtro: 2 toques pelo dashboard, 4 pelo esquema, **6 pela via óbvia** (a única que não pré-preenche nada).

### Top 3
1. Dar o esquema ao técnico (`EsquemaPiscina.php:66-69`) + corrigir a mensagem de 403.
2. Esquema legível de telemóvel (layout empilhado <480px, valores fora do SVG, detalhe acima do desenho).
3. Pré-preencher a piscina nas ações (`:174`) + tabela mobile a 3 colunas e poll 10s→60s.
## Stock — Armazém / Instalação / Produtos / Bidões / Logs

Alterações feitas pela UI (previstas no briefing): +20 L entrada no armazém (Hipoclorito), 5 L e 25 L transferidos para Leiria, bidão de Cloro da Lazer reabastecido (36%→100%).

**Medições (mobile, isoladas):**
| rota | status | ttfb | load | settled | requests |
|---|---|---|---|---|---|
| stock-warehouses | 200 | 291 | 834 | 2034 | 22 |
| stock-installations | 200 | 208 | 558 | 1758 | 22 |
| products | 200 | 147 | 454 | 1654 | 22 |
| dosing-containers | 200 | 178 | 474 | 1675 | 22 |
| stock-warehouse-logs | **500** | 1359 | 2634 | — | 1 |
| stock-installation-logs | 200 | 108 | 405 | 1605 | 23 |

**Toques por pergunta (mobile):**
- "quanto tenho de cloro no armazém?" → 2 toques até à lista, +4 para descobrir unidades/categoria em Produtos; soma mental de 3 linhas em 2 unidades (48 L + 320 kg + 269 kg). Sem total, sem unidade, sem categoria na tabela.
- "que produtos abaixo do mínimo e onde?" → 0 toques mas ~6 swipes (widget a `scrollY=5667` de 6958 px); ou 4 toques + 2-3 páginas.
- "quanto gastei de cloro em Leiria este mês?" → 8 toques e **não é respondível** (sem filtro de data, sem soma, consumo por bidões nunca debita stock).
- Transferência: 5 toques + digitação, 6,9 s. Entrada: 4 toques, 4,7 s. Reabastecer bidão: 2 toques, 5,2 s.

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|-------------------------------|-------------------|----------------|---------|
| 1 | Alta | **"Movimentos — Armazém" dá 500 sempre.** `RelationNotFoundException: Call to undefined relationship [produto] on model [StockWarehouseLog]`. O fornecedor/nº de fatura escrito na "Entrada" nunca pode ser lido. O modelo só tem `armazem()` e `utilizador()`; `product_id` existe na tabela mas o `StockService` nunca o preenche | `->with('armazem.produto')`, coluna `armazem.produto.name`, unidade `$record->armazem?->produto?->unidade`, filtro `SelectFilter::make('stock_warehouse_id')->relationship('armazem.produto','name')` (padrão que já funciona no log de instalação) | `StockWarehouseLogResource.php:37,55,60,76-80`; `app/Models/StockWarehouseLog.php` | S |
| 2 | Alta | **Consumo real de cloro não existe.** `reabastecer()` de um bidão de 20 L não debita `StockInstallation` — só grava `DosingContainerLog`. O único caminho que consome stock são as adições do registo diário. Com dosagem automática Hanna o químico sai pelos bidões → `stock_installation_logs` tinha 0 linhas com 153 registos | No `reabastecer()` debitar `StockInstallation` via `StockService::consumeInstallationStock()` na mesma transação; `product_id` no `DosingContainer` | `app/Models/DosingContainer.php:161`; `DosingContainerResource.php:126-155`; `StockService.php:131` | M |
| 3 | Alta | **Nenhum ecrã dá a imagem do stock.** 6 páginas (armazém, instalações em 3 páginas de 10, produtos p/ unidades, bidões, 2 logs) e nenhuma soma nada. Sem pesquisa global (`globalSearch`/`recordTitleAttribute` não configurados) | Página única "Stock": uma linha por produto com unidade, total, armazém, coluna por instalação, bidões, badge "abaixo do mínimo" e ações inline | novo `app/Filament/Pages/StockHub.php` | M |
| 4 | Alta | **Erro fecha o modal e perde tudo.** Transferir 99 999 L → `danger` e o modal fecha (instalação+quantidade+observações perdidas). Mensagem ambígua em pt-PT: "Disponível: 1.000. Pedido: 9999" | `$action->halt()` no `catch`; `number_format($qtd, 3, ',', ' ')` nas mensagens | `StockWarehouseResource.php:182-188`; `StockInstallationResource.php:150-156,184-189`; `StockService.php:72,137` | S |
| 5 | Alta | **Modal "Transferir" não diz o quê nem quanto há.** Sem nome do produto, sem disponível, sem unidade; em mobile o modal cobre a linha, logo não se confirma o produto | `modalHeading(fn ($record) => "Transferir {$record->produto->name}")`, `hint()` com disponível+unidade, `suffix(unidade)`, `maxValue($record->quantity)` | `StockWarehouseResource.php:146-167`; precedente `DailyRecordFormBuilder.php:793-804` | S |
| 6 | Alta | **Tabelas 2-3× mais largas que o ecrã em 390px:** armazém 871px em 356 (515 escondidos), instalações 1086 (730), bidões 1075 (719), produtos 718 (362). Produto+quantidade e botão de ação nunca visíveis juntos; barra flutuante tapa a paginação | Ações em `ActionGroup`; esconder checkbox de bulk sem permissão; `padding-bottom` ≥80px no `.fi-ta-ctn` | `StockWarehouseResource.php:113-195`; `StockInstallationResource.php:122-196`; `DosingContainerResource.php:125-205` | M |
| 7 | Média | **"Abaixo do mínimo" não é filtrável nem visível:** `limite_minimo` está `toggleable(isToggledHiddenByDefault: true)` e `->filters([])` está vazio; 24 linhas a 10/página = 3 páginas para comparar à mão | Badge vermelho na quantidade; `Filter::make('abaixo_minimo')` ligado por defeito; filtros de instalação e produto | `StockInstallationResource.php:110-121` | S |
| 8 | Média | **Widget de stock baixo é beco sem saída:** fim de um scroll de 5667px, sem unidade, `acoes: []` | Ação "Transferir do armazém" no widget + unidade; subir para `sort=-25` | `StockBaixoWidget.php:17,48-66` | S |
| 9 | Média | **Logs sem data e sem soma.** Filtros só Tipo/Instalação/Produto; "no último mês" é a olho em páginas de 25; sem `Summarizer`. `stock_installation_logs` não guarda `pool_id` → consumo não é atribuível a uma piscina | `Filter::make('periodo')` com 2 `DatePicker` (default mês corrente), `summarize(Sum::make())`, gravar `pool_id` no log | `StockInstallationLogResource.php:61-88`; `StockWarehouseLogResource.php:58-81`; `ProcessDailyRecordAfterCreate.php:165` | M |
| 10 | Média | **`gestor` vê o alarme e não pode ver nada.** Sidebar sem grupo Stock e as 6 rotas dão 403 — mas o `StockBaixoWidget` mostra-lhe os 6 produtos em falta. As Policies contradizem o `canAccess`: `viewAny()` autoriza todos os cargos | `canAccess()` = `viewAny` (leitura para gestor); ações de movimento por `can('transferStock')` | `StockWarehouseResource.php:49-52` e afins vs `StockWarehousePolicy.php:13-21` | S |
| 11 | Média | Qualquer 403 diz "A sua conta foi encerrada por inatividade" (medido nas 6 rotas) | Mensagem de "sem permissão" + botão para o painel; texto de inatividade só com `session('mmc_inativo')` | `resources/views/errors/403.blade.php:44-48` | S |
| 12 | Baixa | `poll('10s')` em 5 tabelas: 4 pedidos em 32 s, 567 KB (armazém), 854 KB (instalações), 853 KB (bidões) ≈ **1,6 MB/min** numa lista que muda 2-3× por dia. Armazém e bidões sem eager loading: 9 e 11 queries em vez de 2 | `poll('60s')` ou remover; `with('produto')` / `with('piscina')` | `StockWarehouseResource.php:96-99`; `DosingContainerResource.php:85-88`; `StockInstallationResource.php:99`; logs `:36` | S |

Notas menores: `stock-installations/create` não tem campo de quantidade (criar linha + "Entrada Direta" são dois fluxos); quantidades sem unidade nas listas ("2,000") e com separador decimal diferente nos logs ("5.000 L"); não existe UI para `dosing_container_logs`; o reabastecimento de bidão é o único fluxo sem problemas (2 toques, pré-preenchido, unidades coerentes).

Fora de âmbito mas medido: `resources/css/app.css:1` faz `@import` de fonts.googleapis.com, além do `<link>` em `AdminPanelProvider.php:133-135` e do `->font('Lato')` em `:52` — três fontes remotas.

### Top 3
1. Ecrã único de stock (3): "tenho cloro?" de 6 toques + soma mental para 1 toque.
2. Corrigir o log do armazém (500) e dar-lhe data + soma (1 e 9).
3. Modais que não fazem perder trabalho (4 e 5).
## Análise de Parâmetros / Relatório PDF / Activity Log

**Análise (`/admin/analise-parametros`)** — cliques=7 só para ver 2 dos 4 parâmetros legais | ttfb=294–340ms | dcl=587–797ms | settled=1,8–2,0s | requests=26 | mobile: dcl=1174ms, settled=2375ms, doc=2771px (3,3 ecrãs), 27/42 alvos <44px.
Custo por filtro: 1 pedido `/livewire/update` por select (65–95ms), 2 por botão de período; gráfico atualiza em 116–317ms — **não há recarregamento total**.

**Relatório PDF (`/admin/relatorio-pdf`)** — cliques=13 do formulário até ao 1.º PDF (14 desde o dashboard) | ttfb=114ms | dcl=411–941ms | requests=21 | erros=0.
- 1 piscina, 7 dias: 723ms, 887 844 B, 2 páginas
- 3 piscinas, mês: 2051ms, 938 201 B, 8 páginas (94 registos)
- intervalo sem dados: 520ms, 1 273 043 B, **3 páginas** — gera livro oficial vazio
- CSV mês: 415ms, 8 241 B, 92 linhas, 40 não conformes
- Servidor: `construirSeccoes` mês = 38 queries/36ms; o custo é o dompdf (1,7s)

**Activity Log (`/admin/activitylogs`)** — 3 interações | ttfb=151ms | dcl=493ms | mobile 3 de 5 colunas visíveis | polling real 1 pedido/25s (o `poll('10s')` do código não está ativo). Resultado: 4 linhas, todas com Utilizador `—` e Assunto `Pool # 4`. **Pergunta não respondida.**

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|---|---|---|---|
| 1 | Alta | Tab **"Tabela" nunca abre**: botão fica ativo, gráfico continua, 0 tabelas no DOM, 0 erros. A resposta Livewire traz a tabela (65 518 B) e é descartada — o servidor renderiza 500+500 linhas por clique para nada | `wire:ignore` do gráfico dentro do `@if` impede o morph: `wire:key` distinto por ramo ou gráfico em componente filho | `painel-parametros.blade.php:74-98` (ignore em :80); `CloroPhChartWidget.php:250` | S |
| 2 | Alta | Sem resposta numérica para auditoria: 7 cliques e depois "a olho" contra a banda verde. Com os eixos por omissão (Controlador pH/ORP) as violações **não aparecem** — verdade da BD para Lazer/14d: 4 registos fora, 2 só por cloro combinado. Cobrir os 4 parâmetros = ~11 cliques | Lista "Violações no período": data, parâmetro, valor, link para o registo (3 queries/80ms) | `AnaliseParametros.php` + `analise-parametros.blade.php:3-5` | M |
| 3 | Alta | "Pior score este mês" não é respondível: Score é 1 número global de 7 dias, heatmap fixo em 7 dias, nenhum widget com controlo de período/piscina. Resposta real: Competição 29%, Caranguejeira 67,7%, Lazer 70%, Infantil 71%, Maceira 80% | Um `Stat`/linha por piscina + toggle 7/30 dias, ordenado pela pior | `ScoreConformidadeWidget.php:30,51`; `HeatmapConformidadeWidget.php:29` | S/M |
| 4 | Alta | **No dia 1 de cada mês o formulário abre inválido**: início=01/08, fim=31/07 → "A data fim tem de ser igual ou posterior à data início" e zero ficheiros, exatamente no dia em que se tira o livro do mês anterior | Default = mês anterior completo + atalhos "Mês passado / Últimos 7 dias / Este mês" | `RelatorioPdf.php:95-96` | S |
| 5 | Alta | **Gestor: 403** no Relatório PDF e sem entrada na sidebar, mas é destinatário da notificação do relatório mensal. Os PDFs mensais só vivem no link da notificação — nada no painel refere `relatorios-mensais/` | Incluir `gestor` no `canAccess`; listagem dos relatórios mensais gerados | `RelatorioPdf.php:84-87` vs `GerarRelatorioMensalCommand.php:52,95-100` | S (+M) |
| 6 | Média-Alta | PDF oficial imprime **"Conforme" hardcoded por nome de piscina**: Transparência e Bomba/Tanque para 'Lazer','Competição','Infantil' saem sempre "Conforme", ignorando o valor medido. A turbidez não é avaliada em nenhum sítio | Avaliar turbidez contra `getTransparenciaMax()` e imprimir valor + ✓/✗ para todas; remover a lista de nomes | `resources/views/pdf/livro-sanitario.blade.php:348,355-356`; `DailyRecord.php:292-343` | S |
| 7 | Média | Antes de gerar não se sabe quantos registos saem nem se algum é não-conforme. Intervalo vazio gera livro oficial de 3 páginas/1,27 MB com "Sem registos" | `Placeholder` live "X registos, Y não conformes"; se X=0 avisar e não gerar | `RelatorioPdf.php:113-168`; `livro-sanitario.blade.php:231-235,253-257` | S |
| 8 | Média | Modo "controlador: todos os registos" + mês: 1.º clique não gera nada e **reescreve a data fim em silêncio** (31/07→07/07), exige 2.º clique. Quem não repara leva um livro de 7 dias a pensar que é o mês | `maxDate` reativo (início+6d) + helperText "máx. 7 dias neste modo" | `RelatorioPdf.php:296-307` | S |
| 9 | Média | Mobile 390: heatmap 776px em caixa de 308px (2 de 7 dias visíveis); Estabilidade 1008px em 308px — a coluna "Leitura", a única com recomendação, fica fora do ecrã. Células de 28px não clicáveis e o motivo só existe em `title=` | Heatmap transposto ou cartões por piscina, célula tocável com link; Estabilidade como lista piscina→veredicto | `heatmap-conformidade.blade.php:22-32,55-60`; view do `EstabilidadeMedicoesWidget` | M |
| 10 | Média | **`CustomActivitylogResource` não está a ser usado** (as páginas do pacote sobrepõem-se): badges em inglês, "Pool # 4" em vez de "Piscina: Maceira", sem "Descrição/Ação", autor "—". Como o log é `logOnlyDirty`, um update de piscina não tem o nome nas properties → pesquisar "Maceira" não o encontra. Sem filtro por piscina/subject | Páginas próprias com `$resource = CustomActivitylogResource::class` + `getPages()`, filtro por `subject_type`; ou assumir o pacote e apagar o resource morto | `CustomActivitylogResource.php:18,23` | M |
| 11 | Média | Cada mudança de filtro calcula o payload **duas vezes**: 15 queries no `afterStateUpdated`→dispatch + 14 no render = 29 por interação; a cache não se aplica aos eixos por omissão | Memoizar o payload por pedido e reutilizar no render | `CloroPhChartWidget.php:243-248,386`; `painel-parametros.blade.php:75` | S |
| 12 | Baixa | (a) PDF em mobile com "Opções de personalização" aberta → 24 checkboxes e 3 ecrãs até "Exportar" (y=2249; 59/94 alvos <44px); (b) "Instalação" sem default (2 toques) e datepicker `native(false)` readonly → 4 toques por data, não se pode escrever; (c) validação nativa em inglês; (d) NS vê o menu Análise mas o select de piscinas vem vazio sem explicação; (e) card "Consumo de químicos" sempre presente e vazio; (f) erro JS em cada carregamento: `chartjs-plugin-annotation beforeDraw → Cannot read properties of undefined (visibleElements)`; (g) CSV/PDF publicam cloro combinado negativo (−0,43) marcado "Conforme" | (a) `->collapsed()`; (b) default de instalação + data escrita; (c) mensagem PT; (d) estado vazio explicativo; (e) esconder card sem dados; (f) investigar duplo render/destroy; (g) tratar total<livre como dado inválido | `RelatorioPdf.php:170-174,122-131,151-167`; `CloroPhChartWidget.php:134-138`; `consumo-quimicos.blade.php:5-6`; `resources/js/app.js:126-131,186-198` | S |

### Top 3
1. Corrigir o tab "Tabela" + "Violações no período": pergunta de auditoria de ~11 cliques falíveis para 2 cliques com números e link.
2. Relatório PDF: defaults (mês anterior), contagem live antes de gerar, acesso ao gestor, opções colapsadas em mobile — de 13 cliques (com erro garantido no dia 1) para ~3.
3. Score por piscina com toggle 7/30 dias + tirar o "Conforme" hardcoded do PDF.

**Sobre o relatório mensal automático:** para arquivo interno torna a página dispensável no dia-a-dia (`relatorio:mensal-automatico` usa o mesmo motor e notifica admin+gestor). A página fica indispensável só para intervalo ad-hoc numa inspeção DGS, CSV, e recuperar um mês cujo link se perdeu — e este último é hoje o único caminho, porque não há listagem de `relatorios-mensais/` e o gestor tem 403.
## Sistema e Estrutura — Definições, Utilizadores, Convites, Sensores Hanna, Piscinas/Instalações

**Definições:** alterado `cloro_livre_max` 2.0 → 1.4, gravado, efeito confirmado no dashboard (`Cl. Livre 1,44 mg/L` passou de OK a **Alerta**), revertido. Cliques=3 | ttfb=186ms | dcl=661ms | settled=1862ms | requests=23 · gravar 1 limite=513–1011ms · gravar 8 limites=17 toques/980ms · mobile: página 2345px, 41/117 alvos <44px.
**Utilizadores/Convites:** convite=**5 toques/439ms** · reenviar=**2 toques/410ms** · revogar=**2 toques** | users: ttfb=168 dcl=460 | convites: ttfb=138 dcl=471.
**Sensores Hanna:** 1 toque até ao modal "Detalhes" (que responde à pergunta) | ttfb=168 dcl=524 | polling **4 pedidos em 32s** vs 1 em `/admin/installations`.
**Piscinas/Instalações:** `temp_max` da Maceira 30 → 27 confirmado no dashboard (CONFORMES 2/5 → 1/5), revertido. pools: dcl=805; pools/4/edit: dcl=946.

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|-------------------------------|-------------------|----------------|---------|
| 1 | Alta | **Um clique em "Guardar Definições" zera todos os limites legais.** `save()` grava `''` para cada campo vazio; `get()` usa `?? $default` e `''` não é null → o default nunca entra. Provado: após 1 Guardar, `getPhMax()=0.0`, `getPhMin()=0.0`, `getTransparenciaMax()=0.0` e `avaliarConformidade('ph', 7.4)` → VERMELHO "acima do máximo (0)". Numa instalação nova a tabela está vazia (não existe `AppSettingsSeeder`), logo todos os campos estão em branco e o primeiro Guardar destrói o semáforo, os relatórios e o dashboard. Atinge também `fator_compensacao_dosagem` (dose → 0) e os multi-selects (`[]`), desligando silenciosamente os digests de turno e de conformidade | `foreach` salta vazios: `if ($value === null \|\| $value === '' \|\| $value === []) continue;` (ou apaga a `AppSetting`); `SettingsService::get()` tratar `''` como ausente | `Definicoes.php:277-291`; `SettingsService.php:41`; afetados: `DailyRecord.php:49-76`, `DosageCalculatorService.php:25`, `InvitationService.php:41`, `SourceSelectionService.php:119-124`, `AlertasService.php:158,246`, `SendShiftSummaryCommand.php:28`, `SendComplianceDigestCommand.php:35` | S |
| 2 | Alta | **`/admin/pools` não tem nenhuma ação na linha.** `ViewAction`+`EditAction`→`slideOver()` estão declaradas mas não renderizam: `contentGrid()` faz o Filament escolher `ActionsPosition::AfterContent`, que nunca é desenhado numa `<table>` normal. Editar uma piscina obriga a clicar na linha → View → "Editar": 3 ecrãs em vez de 1 slide-over | Remover `->contentGrid([...])` ou forçar `->actionsPosition(ActionsPosition::AfterCells)` | `PoolResource.php:86-89` e `:130-133` | S |
| 3 | Alta | **A página 403 mente ao utilizador.** O `gestor` a abrir `/admin/pools`, `/admin/installations` ou `/admin/hanna-devices` recebe "Ficou sem acesso — A sua conta foi encerrada por inatividade". A conta está ativa | Texto genérico de permissão; reservar a mensagem de inatividade para `session('mmc_inativo')` | `errors/403.blade.php:46-47` (cf. `AdminPanelProvider.php:90`) | S |
| 4 | Alta | **O gestor pode mudar a password/PIN do Admin.** Em `/admin/users` como `gestor` aparecem "Enviar Email de Redefinição" e "Forçar Pass / PIN" nas linhas de `Daniel Paz`, `Márcio` e `Admin Teste`. `canEdit()` bloqueia a edição, mas estas duas `Action` não têm `visible()`/`authorize()` | `->visible(fn (User $record) => UserResource::canEdit($record))` nas duas ações (e revalidar no `action()`) | `UserResource.php:210` e `:226` | S |
| 5 | Média | **Depois de convidar, o admin não vê nada.** O email convidado não aparece em `/admin/users` e a tabela não menciona convites; o estado só existe em "Convites", 2 itens abaixo na sidebar. No modal, as piscinas aparecem só por nome, sem instalação — quem convida tem de saber de cabeça que Competição/Infantil/Lazer são Leiria | Badge de pendentes em "Convites" (`getNavigationBadge()`) + tab "Pendentes" em `/admin/users`; agrupar checkboxes por instalação | `ListUsers.php:55-59,78-82`; `UserInvitationResource.php` | M |
| 6 | Média | **Estado vazio de Convites é um beco sem saída:** "Sem Convites" com ícone X e zero ações | `->emptyStateActions([...])` para a ação `convidar`; trocar o ícone X por `heroicon-o-envelope` | `UserInvitationResource.php` (bloco `table()`) | S |
| 7 | Média | **A lista de sondas é pior a mostrar saúde de sonda que o dashboard.** "Última leitura" é só data absoluta a cinzento, sem idade relativa nem badge; responder "qual está a falhar e desde quando" exige subtrair de cabeça ou abrir "Detalhes" sonda a sonda. O `sensor_timeout_minutos` nunca aparece aqui | `->since()`/`description(diffForHumans())` + `badge()->color()` contra `sensor_timeout_minutos`; filtro "Só sondas em falha" | `HannaDeviceResource.php:79-84` | S |
| 8 | Média | **Polling caro numa página que se abre raramente.** `poll('10s')` + `ultimaLeitura()` sem memoização, chamado por 4 colunas × 5 linhas ≈ 20 queries por render; dados mudam a cada 15 min (`hanna:sync`) | Memoizar `ultimaLeitura()` (ou eager-load) e poll 60s/remover | `HannaDevice.php:41`; `HannaDeviceResource.php:72` | S |
| 9 | Média | **Três folhas externas bloqueiam o render, duas com o mesmo URL:** `<link>` para Google Fonts em `AdminPanelProvider.php:135` e `@import` do **mesmo URL** em `resources/css/app.css:1`, mais `fonts.bunny.net` do `->font('Lato')`. Com o CDN inalcançável: DCL 946ms → 25,6s | Remover o `@import`, um só provedor, idealmente auto-hospedar com `preload` | `resources/css/app.css:1`; `AdminPanelProvider.php:133-137` | S |
| 10 | Média | **Definições envia sempre os 3 separadores:** 1354 nós DOM, 20 inputs e 30 toggles no HTML para 10 campos visíveis; os separadores escondem-se com `display:none` e `getUsuariosNotificacoes()` (com `withCount`) corre mesmo oculto | `@if($tab === '…')` em vez de `display:none`; resolver utilizadores só no separador ativo | `definicoes.blade.php:31,196,217`; `Definicoes.php:459-475` | S |
| 11 | Média | **O dropdown do último select tapa o botão "Guardar Definições".** Com o dropdown de "Resumo de Conformidade" aberto, o toque em Guardar **não dispara nenhum** `/livewire/update` e não há aviso — o utilizador acredita que gravou | Fechar o dropdown ao perder foco/scroll, ou footer sticky com `z-index` acima do dropdown | `definicoes.blade.php:196-215`; `Definicoes.php:184-190` | M |
| 12 | Baixa | (a) Mobile: sidebar com 1236px de conteúdo para 780px visíveis — Instalações y=896, Piscinas y=940, Utilizadores y=1052, Definições y=1096, Sensores y=1140, Convites y=1184; itens de 40px. (b) "Convites" (sort 45) separado de "Utilizadores" por Definições e Sensores. (c) A barra flutuante tapa os últimos 9px de "Convites" e o cabeçalho do grupo Logs. (d) O gestor vê "Definições" com apenas "Minhas Notificações" — nome enganador. (e) Docs desatualizados: `docs/paginas/definicoes-sistema.md` aponta para `DefinicoesSistema.php` (inexistente); `docs/paginas/notificacoes.md` documenta uma página que já não existe. (f) `PoolResource.php:51` tem soft hyphen em "Temperatura Mínima" | Colapsar `Estrutura`; ordenar Sistema como Utilizadores→Convites→Definições→Sensores; `padding-bottom` na sidebar; label dinâmico; atualizar/apagar os dois `.md`; corrigir o label | `AdminPanelProvider.php:72-80`; `UserInvitationResource.php:35`; `HannaDeviceResource.php:35`; `Definicoes.php:44,48`; `PoolResource.php:51`; docs | S |

### Top 3
1. Parar de gravar strings vazias em `Definicoes::save()` (1) — hoje um Guardar põe todos os limites CN 14/DA a 0, pinta tudo de vermelho, zera a calculadora de dosagem e desliga os digests, sem aviso.
2. Devolver a ação "Editar" à lista de Piscinas (2): 3 ecrãs → 1 slide-over.
3. Fechar o ciclo do convite sem sair de Utilizadores (5) + corrigir a página 403 (3).

**Estado da BD:** convites de teste revogados; `temp_max` da Maceira em 30.0; limites regulamentares nos valores padrão (6.9/8.0/0.5/2.0/0.6/5.0/0.2/1.25, 14:00+20:00, 08:00+13:00+18:00). A tabela `app_settings` estava **vazia** antes da auditoria e ficou com 10 linhas preenchidas com os valores certos.
## Transversal — navegação, mobile e performance

### Login (mobile)
`/admin` sem sessão → 1 redirect → `/admin/login`: ttfb 457ms, fcp 564ms, 7 requests. 3 toques + 2 escritas; submit→dashboard ~200ms. `autocomplete` correto, **sem autofocus** no email, "manter sessão" é checkbox de **16x16px** com `default(false)` (`Login.php:125-129`). `/primeiro-acesso`: 3 campos + submit, inputs de 39px, cabe num ecrã.

### Performance (admin, desktop, contexto limpo, CDNs bloqueados)
`ttfb` = mediana de 3 corridas `curl` (servidor puro). Ordenado por DCL.

| rota | ttfb srv | dcl | fcp | reqs | html | JS | CSS | total | queries |
|---|---|---|---|---|---|---|---|---|---|
| /admin/daily-records | 220 | 711 | 888 | 24 | 553kb | 10/969kb | 9/270kb | 3113kb | 18 |
| /admin/relatorio-pdf | 99 | 694 | 884 | 22 | 236kb | 8/870kb | 270kb | 2697kb | — |
| /admin/esquema | 127 | 670 | 948 | 21 | 194kb | 7/766kb | 270kb | 2551kb | **89** |
| /admin/analise-parametros | 290 | 597 | 808 | 27 | 208kb | 13/1043kb | 270kb | 2841kb | **61** |
| /admin/operational-actions | 185 | 559 | 768 | 22 | 416kb | 8/770kb | 270kb | 2776kb | 17 |
| /admin/definicoes | 184 | 524 | 672 | 23 | 423kb | 9/865kb | 270kb | 2878kb | — |
| /admin/stock-installations | 173 | 514 | 700 | 22 | 356kb | 770kb | 270kb | 2716kb | 14 |
| /admin/dosing-containers | 156 | 508 | 700 | 22 | 378kb | 770kb | 270kb | 2738kb | 13 |
| /admin/incidents | 141 | 490 | 624 | 22 | 272kb | 770kb | 270kb | 2633kb | 15 |
| /admin/hanna-devices | 149 | 490 | 684 | 22 | 296kb | 770kb | 270kb | 2656kb | — |
| /admin/products | 140 | 458 | 596 | 22 | 293kb | 770kb | 270kb | 2653kb | — |
| /admin/stock-warehouses | 157 | 456 | 596 | 22 | 286kb | 770kb | 270kb | 2646kb | — |
| /admin/users | 163 | 453 | 640 | 22 | 308kb | 770kb | 270kb | 2668kb | — |
| /admin/activitylogs | 126 | 432 | 604 | 22 | 298kb | 770kb | 270kb | 2658kb | — |
| /admin/pools | 125 | 405 | 568 | 22 | 270kb | 770kb | 270kb | 2630kb | — |
| /admin/daily-records/create | 86 | 394 | 536 | 21 | 149kb | 766kb | 270kb | 2506kb | 13 |
| /admin/operacao-hub | 79 | 385 | 460 | 21 | 127kb | 766kb | 270kb | 2483kb | 10 |
| /admin | 96 | 337 | 432 | 33 | 136kb | 766kb | 270kb | 2792kb | 14 |

O servidor **não é o gargalo** (79–290ms). O peso é: `logo_mmcrespo_branco.png` **1274kb**, `livewire.js` 379kb, `app.js` 149kb, `support.js` 134kb, filament `app.css` 104kb, `echo.js` 90kb, `forms.css` 80kb, o nosso `app.css` 81kb. Chart.js **está** code-split (só `/admin` e Análise carregam os 238kb). O dashboard soma ainda 6 `/livewire/update` = 310kb depois do DCL.

### Mapa de toques (mobile, a partir da entrada)
Somar **+1 toque** a tudo: a app abre com o menu lateral aberto a tapar 82% do ecrã.

| tarefa | tecnico | admin | caminho |
|---|---|---|---|
| novo registo diário | 1 (FAB) **+2** | idem | FAB não leva piscina → escolher instalação (round-trip de 818kb) antes do 1.º campo |
| idem, com a piscina certa | 1 + até 3,5 ecrãs de scroll | idem | cartão da piscina → "Registo Rápido" (`?pool=N&quick=1`) |
| novo incidente | 3 | 3 | menu → Incidentes → Criar |
| esquema da piscina | **impossível (403)** | 2 | admin-only |
| ver stock baixo | 0 toques, **6,5 ecrãs de scroll** (y=5516 de 6734) | idem | widget no fundo do dashboard |
| gerar PDF | ~6 | ~6 | menu → Relatório PDF → 4 campos → Gerar |
| definições | — | 2 | menu → Definições |

Ordem atual dos grupos: Painel · Registo Diário · Operação · Dados · Stock · Estrutura · Sistema · Logs.
Ordem proposta pela frequência real: **Painel · Registo Diário (Registos, Incidentes, Esquema) · Operação (Ações) · Stock · Dados · Sistema · Estrutura · Logs**.
Não há pesquisa global nem atalhos (nenhum resource define `getGloballySearchableAttributes`; `Ctrl+K` não faz nada; único keybinding é `mod+s` em `CreateDailyRecord.php:197`).

### Achados
| # | Sev | Fricção observada (evidência) | Correção proposta | Ficheiro:linha | Esforço |
|---|-----|---|---|---|---|
| 1 | Alta | **Cada navegação pendura 25,7s quando um CDN não responde.** Medido 3x (25760/25797/25676ms). Com `serviceWorkers:'block'` = **380ms**. Causa: o SW faz `fetch(req).catch(...)` sem timeout e o `<head>` tem 4 recursos externos bloqueantes (fonts.bunny.net do `->font('Lato')`, fonts.googleapis.com no render hook, o **mesmo** Google Fonts outra vez via `@import` no CSS, glightbox.css) | Auto-hospedar fontes e GLightbox; no SW `Promise.race([fetch(req), timeout(3000)])` | `public/sw.js:99-101`; `AdminPanelProvider.php:130-139`; `resources/css/app.css:1` | M |
| 2 | Alta | **1,27 MB de logo em todas as páginas** (`logo_mmcrespo_branco.png` = 1 304 783 bytes, renderizado a 100px de altura) = 44% do peso da página | WebP a 300px (~20kb) + `<img width height loading>` | `filament/preloader.blade.php:2`; `filament/brand-logo.blade.php:12`; `pages/auth/login.blade.php:4,17` | S |
| 3 | Alta | **A app abre no telemóvel com a gaveta de navegação aberta** (x=0, largura 320 de 390 = 82%) em contexto novo — é o `$persist(true)` do Filament, sem reset por breakpoint | `alpine:init` → se `innerWidth < 1024`, `Alpine.store('sidebar').close()` | `resources/js/app.js` (arranque, ~1703) | S |
| 4 | Alta | **Preloader cobra 500ms fixos no arranque e 250ms em cada navegação SPA**, overlay opaco com `pointerEvents:auto`; medido 405–455ms *depois* de o formulário estar utilizável | Esconder no `DOMContentLoaded` sem `setTimeout`; em `livewire:navigated` esconder já; nunca bloquear pointer events | `filament/preloader.blade.php:65-71,75-77` | S |
| 5 | Alta | **Qualquer 403 diz "A sua conta foi encerrada por inatividade"** (reproduzido com `tecnico` em `/admin/esquema`) | Mensagem neutra + botão de voltar; decidir o acesso do técnico ao Esquema | `errors/403.blade.php:46-47`; `EsquemaPiscina.php:66-69` | S |
| 6 | Alta | **Sem gzip para JS/CSS em produção**: o `nginx-site.template` não define `gzip`/`gzip_types` → ~1,0–1,3 MB de JS/CSS por página sem compressão | `gzip on; gzip_vary on; gzip_min_length 1024; gzip_types text/css application/javascript application/json image/svg+xml;` | `nginx-site.template:1-10` | S |
| 7 | Média | **N+1**: `/admin/esquema` = **89 queries** (por piscina: `hanna_devices` ×6, `sensor_readings` ×6, `operational_actions` ×3, `filter_checks` ×6, `users where id in (4)` ×9); Análise = **61**, com `installations where id=?` ×15 | `whereIn('pool_id', …)` e distribuir; `Pool::query()->with('instalacao')` nos widgets | `EsquemaPiscina.php:147-200`; `ConsumoQuimicosWidget.php:44`; `HeatmapConformidadeWidget.php:46`; `EstabilidadeMedicoesWidget.php:57` | M |
| 8 | Média | **O atalho sempre visível é o caminho lento.** O FAB vai para `create` sem piscina; o default de instalação só funciona com linhas em `user_pools` (o `tecnico` não tem) → 2 toques extra + `/livewire/update` de **818kb** (430ms local) antes do 1.º campo | Default pela última `DailyRecord` do utilizador; FAB com `?pool=<última>&quick=1` | `DailyRecordFormBuilder.php:423-431`; `bottom-nav.blade.php:19` | S |
| 9 | Média | **O rascunho nunca chega ao IndexedDB**: `DataCloneError: #<Object> could not be cloned` a cada 2s em `create` (o `component.get('data')` é proxy do Livewire). localStorage funciona (109 bytes), IndexedDB fica a 0 chaves → o rascunho "à prova de crash" não existe | `structuredClone(JSON.parse(JSON.stringify(payload)))`; gravar só em mudança em vez de `setInterval(2000)` | `resources/js/app.js:1024-1032,1573-1581,1596` | S |
| 10 | Média | **Fila offline partilhada + sync concorrente**: `syncOfflineRecords()` e `syncOfflineOperationalActions()` leem o mesmo store `offline_queue` e correm em paralelo no arranque, em `livewire:navigated` e no evento `online`, sem lock → um registo diário é enviado também para `/offline-sync/operational-actions` (que faz `create($data)` sem validação) e podem duplicar registos | Separar `offline_queue_daily`/`offline_queue_actions`, guardar `tipo`, flag de sync em curso | `app.js:1157,1189-1191,1713-1714,1818`; `OfflineSyncController.php:110-119` | M |
| 11 | Média | Peso morto: `echo.js` **90kb** sem broadcasting (`BROADCAST_CONNECTION=log`); GSAP no bundle **e** do CDN sem `defer` no login; Montserrat só para headings; `/images/` sem `Cache-Control` (nginx só define `expires` para `/build/`) | Desregistar o asset `echo`; remover o script GSAP do login; `expires 30d` para `/images/` | `nginx-site.template:12-16`; `layouts/login-layout.blade.php:14` | S |
| 12 | Baixa | Alvos <44px: dashboard **85/104**, lista de registos 54/138, esquema 36/93, create 25/44, `/primeiro-acesso` 3/5, checkbox "manter sessão" 16px; a barra flutuante interceta cliques em campos do formulário; scroll horizontal interno na tabela do dashboard (602px) e no SVG do esquema (560px); `/admin/operacao-hub` é órfã | 44px mínimos; `pointer-events:none` no contentor da barra (só nos `<a>` fica `auto`); apagar ou ligar o hub | `bottom-nav.blade.php:2`; `OperacaoHub.php:32-35` | M |

### Top 3
1. Cortar os pedidos a terceiros do `<head>` e pôr timeout no service worker — diferença medida entre **380ms e 25,7s** por navegação.
2. Logo de 1,27 MB → ~20 kB e gzip para JS/CSS no nginx: de ~2,6–3,1 MB para <600 kB por página.
3. Fechar a sidebar em <1024px e dar piscina ao FAB: o registo da manhã passa de ~4 interações e 3 esperas para 1 toque e 1 espera.

---

# Implementação (2026-08-01)

Tudo o que segue foi implementado e verificado neste branch. Verificação: 380 testes
a passar (os 2 que estavam vermelhos antes eram o próprio bug do dia 1 do mês nos
testes do PDF), 23 rotas do painel abertas em browser real nos quatro papéis sem
qualquer erro 500/JS, e os fluxos principais executados de ponta a ponta (registo
diário submetido pelo atalho, definições gravadas, PDF gerado).

## Os oito P0

| # | O que mudou | Ficheiros |
|---|-------------|-----------|
| P0-1 | `Definicoes::save()` deixa de gravar strings vazias — apaga a linha e o `SettingsService` cai no default do código; `get()` trata `''`/`[]` como ausente. Verificado: com a tabela vazia e com `ph_max=''`, `getPhMax()` continua 8,0 e um pH de 7,4 continua "Conforme" | `Definicoes.php`, `SettingsService.php` |
| P0-2 | A dose passa a ser formatada no serviço (`dose_formatada`), convertendo ml/g → L/kg. O hint mostra agora "+24,44 kg" em vez de "+24.438 kg" | `DosageCalculatorService.php`, `DailyRecordFormBuilder.php` |
| P0-3 | Fontes auto-hospedadas (`@fontsource/lato` + `montserrat`) e GLightbox empacotado — zero pedidos a terceiros no `<head>`; o Google Fonts duplicado (link + `@import`) desapareceu. O service worker ganhou timeout (4 s em navegações, 8 s em assets) e deixa de interceptar outras origens | `AdminPanelProvider.php`, `app.css`, `app.js`, `login-layout.blade.php`, `public/sw.js` |
| P0-4 | O log de armazém lê o produto por `armazem.produto` (a relação que existe) e o `StockService` passa a preencher `product_id`. A página devolve 200, com unidade e total | `StockWarehouseLogResource.php`, `StockWarehouseLog.php`, `StockService.php` |
| P0-5 | `defaultItems(0)` no repeater de químicos e o modo rápido guardado numa propriedade da página (`aplicarContexto`), que sobrevive aos POSTs do Livewire. Verificado: 2 passos antes e depois do roundtrip, 0 linhas de químicos, registo submetido em 5 toques | `DailyRecordFormBuilder.php`, `CreateDailyRecord.php` |
| P0-6 | O auto-save trava enquanto o modal de recuperação está aberto e não grava formulários vazios; o payload é serializado antes de ir para o IndexedDB (fim do `DataCloneError`); intervalo de 2 s → 10 s | `app.js` |
| P0-7 | "Enviar Email de Redefinição" e "Forçar Pass / PIN" ganham `visible(canEdit)` e revalidam com `abort_unless` na ação | `UserResource.php` |
| P0-8 | Um alerta marcado como tratado cuja condição persiste fica em "Resolvidos hoje" com a marca "a condição continua ativa" e botão de reabrir; violações legais pedem confirmação; nos incidentes o botão passa a levar à página do incidente (a resolução real) | `QuadroOperacionalWidget.php`, `quadro-operacional.blade.php` |

## Bug adicional encontrado durante a implementação

**O conteúdo estava debaixo do menu em desktop.** `.fi-sidebar` era forçada a
`position: fixed !important` em todos os tamanhos, mas em desktop o Filament usa
`lg:sticky` (barra em fluxo, conteúdo ao lado). Resultado: `.fi-main-ctn` começava em
x=0 com a barra de 320 px por cima — a primeira coluna de cartões ficava tapada. O
`fixed` passou a aplicar-se só abaixo de 1024 px, onde é mesmo uma gaveta.
Medido depois: sidebar 0–320, conteúdo 320–1440.

## Velocidade e peso

- Logótipo de **1 274 KB → 31 KB** (WebP a 600 px, PNG reduzido para 120 KB como
  fallback), com `width`/`height` e `loading`. A variante escura deixa de ser
  descarregada quando não é usada.
- `gzip` ligado no nginx para CSS/JS/JSON/SVG/woff2, `expires 30d` para `/images/` e
  `/fonts/`, e `no-cache` para `/sw.js` (uma versão antiga do service worker ficava
  presa no dispositivo).
- Preloader sem espera artificial: esconde no `DOMContentLoaded` e no
  `livewire:navigated`, com `pointer-events: none` — eram ~750 ms por navegação.
- Polling: nove tabelas de 10 s → 60 s; painel e quadro de 30 s → 60 s; stock baixo
  30 s → 120 s; esquema 30 s → 60 s.
- Queries: painel do dashboard 51 → 43 (fonte de dados reutilizada + ORP de todas as
  piscinas numa query), esquema deixa de calcular a fonte duas vezes por piscina,
  `HannaDevice::ultimaLeitura()` memoizada, `Pool::pluck` do `<head>` cacheada com
  invalidação no observer, payload do gráfico memoizado por pedido.
- Medido no fim, em mobile e nas 23 rotas: **0 problemas**, load médio 414 ms,
  22 pedidos por página.

## Dia-a-dia

- **Dashboard**: alertas antes dos gráficos (o "que fazer a seguir" passou do 6.º para
  o 2.º ecrã; a página encurtou de 8,4 para 5 ecrãs), cartões recolhidos em mobile com
  badge do parâmetro em falha e "Falta registar", linha do estado da sonda sempre
  visível, KPIs com a base explicada, cloro combinado negativo tratado como medição
  inválida em vez de "OK".
- **Registo diário**: instalação/piscina pré-selecionadas, contador com a última
  leitura visível antes de falhar, `agua_modo`/bomba/tanque herdados do último registo,
  hora reposta ao mudar de instalação, foto do quadro NS opcional para o técnico,
  "Início" colapsado após escolher a instalação, campo "Responsável" removido, aviso
  quando já existe registo de hoje, e a notificação de stock insuficiente passa
  também para quem submeteu.
- **Incidentes**: a linha da lista abre a página do incidente (com conversa e
  resolução), descrição e idade no cartão, pesquisa por descrição e piscina, filtros de
  instalação/piscina/tipo/data, fotos anexáveis (nova coluna `fotos`), contexto vindo
  de `?pool=`/`?installation=`, data em formato pt-PT, chat com polling de 30 s e sem
  lazy, notificações em fila, "Criar e adicionar outro".
- **Esquema**: acessível a técnico, gestor e NS (era admin-only), no grupo "Registo
  Diário", detalhe que rola para o ecrã em mobile e textos alinhados com as 8 h reais.
- **Ações operacionais**: técnico corrige as suas ações nas primeiras 24 h, botão
  "Editar" na vista, piscina pré-preenchida, resumo debaixo do tipo em vez de uma
  coluna fora do ecrã, chaves do JSON traduzidas.
- **Stock**: gestor com leitura (as Policies já o autorizavam), modais com produto no
  título, disponível e unidade, `maxValue` e `halt()` para não perder o formulário no
  erro, filtro "só abaixo do mínimo", filtro de período com soma nos dois logs,
  unidades em todas as listas, widget de stock baixo com duas colunas e ação
  "Repor do armazém", e o reabastecimento de bidão passa a debitar o stock da
  instalação (nova coluna `product_id` em `dosing_containers`).
- **Dados**: separador "Tabela" da Análise corrigido (`wire:key` por ramo), nova lista
  "Violações dos limites no período", score de conformidade por piscina com 7/30 dias,
  Relatório PDF com default do mês anterior no dia 1, atalhos de período, contagem
  prévia ("X registos, Y fora dos limites"), acesso ao gestor, opções colapsadas, e o
  livro sanitário avalia a turbidez em vez de imprimir "Conforme" por nome de piscina
  (a turbidez passou também a entrar em `listarViolacoes()`).
- **Sistema**: ações de piscina visíveis na lista (o `contentGrid` matava-as), badge de
  convites pendentes, estado vazio dos convites com ação, saúde da sonda na lista de
  sensores com filtro "só em falha", separadores das Definições condicionais em vez de
  `display:none`, e barra de gravação fixa (o dropdown tapava o botão).
- **Navegação e mobile**: grupos por frequência de uso (Estrutura e Logs colapsados),
  gaveta fechada abaixo de 1024 px, barra inferior sem interceptar cliques e com folga
  no fim da página, FAB com a última piscina do utilizador e "Esquema" no terceiro
  lugar, e a página 403 deixa de dizer que a conta foi encerrada por inatividade.
- **Offline**: fila separada por tipo (registo diário vs ação operacional) com lock —
  um registo diário podia ser enviado para o endpoint das ações operacionais e duas
  sincronizações simultâneas podiam duplicar registos.

## O que ficou de fora, e porquê

- **`echo.js` (90 KB) continua a ser carregado.** É registado pelo próprio Filament;
  removê-lo exige mexer no registo de assets do painel e o ganho (≈25 KB depois de
  gzip) não justifica o risco de partir o bootstrap do JS.
- **`CustomActivitylogResource` continua sem efeito** (as páginas do pacote
  `rmsramos/activitylog` sobrepõem-se às nossas). A decisão — assumir o pacote e
  apagar o resource, ou registar páginas próprias — é de produto, não de correção.
- **Ecrã único de stock** (uma linha por produto com armazém + instalações + bidões):
  é uma página nova, não uma correção. Os filtros, unidades, somas e a ação de reposição
  no widget cobrem entretanto as perguntas do dia-a-dia.
- **Pesquisa global (`Ctrl+K`)**: exige definir atributos pesquisáveis em cada resource
  e decidir o que entra; fica para uma iteração própria.
