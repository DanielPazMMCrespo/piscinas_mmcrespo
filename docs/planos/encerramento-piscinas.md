# Plano — Encerramento de Piscinas (época balnear / manutenção / obra)

> Estado: planeado (não implementado). Decisões confirmadas com o Daniel em 2026-08-03.

## Problema

A app assume que as 5 piscinas estão sempre operacionais. `pools.active` é um booleano sem
datas, sem motivo e sem histórico — desligá-lo faz a piscina desaparecer de tudo, incluindo do
passado, o que é errado para o livro sanitário (CN 14/DA): um período encerrado tem de ficar
documentado, não apagado.

Ao encerrar a época balnear temporariamente, hoje acontece:
- alertas vermelhos "sem registo diário hoje" todos os dias, para sempre;
- score de conformidade e heatmap tratam dias encerrados como "sem dados" (falha do operador);
- relatório PDF mostra buracos sem explicação — lido por um auditor da DGS como omissão do dever legal;
- digests, comparação semanal e tendências continuam a contar piscinas paradas.

## Decisões tomadas

1. **`agua_em_tratamento` é uma flag por encerramento.** Ligada (manutenção/obra, água mantida):
   registos diários continuam permitidos mas passam a opcionais, nunca geram alerta vermelho de
   falta. Desligada (época balnear, piscina vazia/parada): registos diários bloqueados e nada é
   esperado.
2. **Piscinas encerradas ficam visíveis e marcadas**, não escondidas. Cartão do dashboard mantém-se
   com badge, valores em cinzento e botão Reabrir; no heatmap os dias encerrados têm estado próprio.
3. **Página dedicada "Encerramentos" no grupo Operação** (acessível a admin/gestor/técnico) + Resource
   de histórico auditável. `PoolResource` (Estrutura, admin-only) não é o sítio para o gestor.

## Correções à revisão (2026-08-03, após leitura do código)

1. **A substituição de `->where('active', true)` NÃO pode ser cega.** Três call sites de `Pool` têm de
   continuar a listar piscinas encerradas, e trocá-los por `operacionais()` seria um bug:
   - `RelatorioPdf:140` — o Select do livro sanitário **tem** de incluir piscinas encerradas; é
     precisamente sobre elas que se emite a declaração de encerramento;
   - `EsquemaPiscina:135` — o circuito continua navegável durante a obra;
   - `UserResource/Pages/ListUsers:58` — atribuir piscinas a um nadador-salvador tem de funcionar antes
     da reabertura.
   Confirmado também que `DosageCalculatorService:105/119` e `SourceSelectionService:38` filtram
   `Product`/`HannaDevice`, não `Pool` — ficam intocados.

2. **Semântica do `fim` e o desfazer no mesmo dia.** `fim` é o **último dia encerrado (inclusivo)** —
   natural para o PDF ("encerrada de 03/08/2026 a 30/09/2026"). Logo `reabrir()` grava
   `fim = data_reabertura->subDay()`. Se isso cair antes de `inicio` (só acontece ao desfazer um
   encerramento criado no mesmo dia), o encerramento é **apagado** em vez de gravado com intervalo
   inválido — nunca produziu efeito, não há histórico sanitário a preservar. Um `if`, e evita a
   alternativa (`fim` exclusivo) que tornaria o intervalo confuso em todos os ecrãs de leitura.

3. **Cortado: o downgrade do alerta de stock baixo** em instalações com todas as piscinas encerradas.
   Marginal, e o stock de armazém continua a fazer sentido monitorizar fora de época.

Verificado que o resto assenta: `CacheService::invalidateAllAlerts()` já existe (o `invalidateAlerts()`
é por utilizador e não serviria); o mapa de encerramentos usa **uma chave fixa**, logo o `Cache::forget()`
funciona em todos os drivers, incluindo `file` em dev, onde a invalidação por wildcard não funciona; as
policies são auto-descobertas por convenção; `PoolFactory` já existe para os testes.

## Modelo de domínio

`pools.active` mantém o significado atual: **estrutural** — a piscina existe/não existe na operação
(desativada = removida do sistema, erro de dados, piscina demolida). O encerramento é **temporal**.
São dois eixos independentes; a app expõe um estado derivado `ativa | encerrada | desativada`.

### Nova tabela `pool_closures`

Granularidade ao **dia** (não datetime): a conformidade, os registos e o relatório são todos diários.

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | id | |
| `pool_id` | FK `pools` cascadeOnDelete | |
| `inicio` | date | primeiro dia encerrado (inclusivo) |
| `fim` | date nullable | último dia encerrado (inclusivo). `null` = encerramento em aberto |
| `motivo` | string | ver `MotivoEncerramento` |
| `agua_em_tratamento` | boolean default false | decisão 1 |
| `observacoes` | text nullable | |
| `encerrada_por` | FK `users` nullOnDelete | |
| `reaberta_por` | FK `users` nullable nullOnDelete | |
| `reaberta_em` | timestamp nullable | quando a reabertura foi registada (≠ `fim`, que é o dia lógico) |
| timestamps | | |

Índices: `(pool_id, inicio)`, `(pool_id, fim)`. Migração idempotente (`Schema::hasTable`).

**Sem backfill automático.** As piscinas com `active = false` hoje não têm datas conhecidas. A
`PoolResource` passa a mostrar um hint nessas linhas sugerindo converter num encerramento datado.

### `App\Constants\MotivoEncerramento`

Mesmo padrão de `IncidentStatus`/`UserRole` (final class, consts, `all()`, `isValid()`, `label()`,
`labels()`): `EPOCA_BALNEAR`, `MANUTENCAO`, `OBRA`, `AVARIA`, `ORDEM_AUTORIDADE`, `OUTRO`.

### `App\Models\PoolClosure`

`declare(strict_types=1)`, `LogsActivity`, casts de datas, relações `piscina()`, `encerradaPor()`,
`reabertaPor()`. Accessors: `esta_ativo` (hoje dentro do intervalo), `dias`, `descricao_periodo`
("de 03/08/2026 a 30/09/2026" / "desde 03/08/2026"). `saved`/`deleted` invalidam o cache (ver abaixo).

### API no `Pool`

```php
public function encerramentos(): HasMany;                       // ordenado desc por inicio
public function encerramentoEm(?CarbonInterface $data = null): ?PoolClosure;
public function estaEncerradaEm(?CarbonInterface $data = null): bool;
public function getEstadoOperacionalAttribute(): string;         // ativa|encerrada|desativada

public function scopeOperacionais(Builder $q): Builder;          // active=true E não encerrada hoje
public function scopeAbertasEm(Builder $q, CarbonInterface $d): Builder;
public function scopeEncerradasEm(Builder $q, CarbonInterface $d): Builder;
```

`scopeOperacionais()` substitui o `->where('active', true)` espalhado por 17 call sites — passa a
haver um único sítio a definir "piscina a operar".

### `App\Services\PoolClosureService`

- `encerrar(Pool $piscina, array $dados, User $utilizador): PoolClosure`
  Valida sobreposição com encerramento já em aberto (`DomainException`). Dentro de `DB::transaction()`:
  cria o registo; resolve `TapAlert` abertos dessa piscina (uma torneira aberta numa piscina encerrada
  não é um alerta); marca `AlertState` pendentes dessa piscina como resolvidos; invalida caches;
  notifica.
- `reabrir(Pool $piscina, User $utilizador, ?CarbonInterface $fim = null): PoolClosure`
  Fecha o encerramento ativo com `fim` (default: ontem, para que hoje já conte como aberto),
  `reaberta_por`/`reaberta_em`; invalida caches; notifica.
- `encerrarEmLote(Collection $piscinas, array $dados, User): Collection` — caso real da época balnear.
- `mapa(Collection $poolIds, CarbonInterface $inicio, CarbonInterface $fim): array`
  **Peça central de performance.** Uma query devolve os intervalos que intersetam a janela; o retorno é
  `[pool_id => [ ['inicio' => Carbon, 'fim' => ?Carbon, 'motivo' => string, 'agua_em_tratamento' => bool], … ]]`
  mais um helper `encerradaEm(int $poolId, string $data): bool` sobre esse mapa. Todos os ecrãs de
  intervalo (heatmap 30 dias, gráficos, PDF de um mês) usam isto — nunca uma query por dia/piscina.

### Cache

`CacheService`: `getClosureMap()`/`cacheClosureMap()`/`invalidateClosures()` sob
`cache_pool_closures_v1` com TTL longo, invalidado na escrita de `PoolClosure`. `encerrar()`/`reabrir()`
invalidam também `invalidateAlerts()`, `invalidatePoolData()` e `invalidateGraphCache($poolId)`, e
incrementam **`PainelPiscinasWidget::CACHE_SHAPE_VERSION` para 3** (a forma do payload muda).

### Autorização

`PoolClosurePolicy`: `create`/`update` (encerrar/reabrir) → admin, gestor, técnico. `delete` → só admin
(apagar um encerramento é reescrever o histórico sanitário). `viewAny` → todos, incluindo
nadador-salvador, limitado às suas piscinas via `NSPermission`.

---

## Sweep pela app — onde o estado tem de ser compreendido

### Escrita (criar/reabrir)

- **Nova página `App\Filament\Pages\EncerramentoPiscinas`** (grupo Operação, ícone `heroicon-o-lock-closed`):
  lista as piscinas por instalação com o estado atual; por piscina, ação **Encerrar** (form: motivo,
  data de início, data de fim opcional, toggle `agua_em_tratamento`, observações) ou **Reabrir** (data
  de reabertura + confirmação). Ação em lote **"Encerrar época balnear"**: multi-select de piscinas,
  um motivo e uma data para todas. Alvos táteis ≥48px (uso em campo).
- **`PoolClosureResource`** (grupo Logs, ou Operação com `shouldRegisterNavigation` no hub): histórico
  filtrável por piscina/motivo/estado, colunas piscina, motivo, período, dias, quem encerrou/reabriu.
  Editar só `observacoes`/`motivo`; apagar só admin.
- **`PoolResource`**: coluna de estado operacional (badge) + hint nas linhas `active = false` sem
  encerramento datado.
- **Notificações**: ao encerrar/reabrir, push + notificação de base de dados (sino) para
  admin + gestor + técnico + nadadores-salvadores dessa piscina, via o padrão já usado no
  `AlertasService`/`CustomBroadcast`.

### Dashboard

- **`PainelPiscinasWidget`**: query passa a `Pool::query()->where('active', true)` **com** o mapa de
  encerramentos carregado; a piscina encerrada mantém o cartão, ganha `encerramento` no payload
  (motivo, período, `agua_em_tratamento`), valores renderizados com a classe neutra `mmc-na`, sparklines
  congeladas e as quick-actions substituídas por **Reabrir** (admin/gestor/técnico). Bump da
  `CACHE_SHAPE_VERSION`.
- **`AlertasService`**: para piscinas encerradas hoje, **não** gerar `SEM_REGISTO`, `FORA_LIMITES`,
  `TEMPERATURA` nem `TORNEIRA`. Exceção: com `agua_em_tratamento = true`, `FORA_LIMITES` mantém-se
  (água tratada fora dos limites é um problema real), mas `SEM_REGISTO` desaparece. Denominadores
  `totalPiscinas` e `conformesHoje` passam a contar só piscinas abertas — senão "3/5 conformes" fica
  permanentemente errado. Um único cartão neutro agregado `AlertType::ENCERRADA|{hoje}` quando há ≥1
  encerrada ("2 piscinas encerradas — Competição, Lazer"), com link para a página de Encerramentos.
  Novo valor em `App\Constants\AlertType`.
- **`QuadroOperacionalWidget`**: nada a mudar — consome o `AlertasService`; os cartões da piscina
  encerrada auto-resolvem-se pelo mecanismo que já existe.

### Registo Diário

- **`DailyRecordFormBuilder`**: o Select de piscina exclui piscinas encerradas sem
  `agua_em_tratamento`; as encerradas com tratamento aparecem com sufixo "(encerrada — em tratamento)".
- **`CreateDailyRecord`**: se chegar `?pool=X` de uma piscina encerrada sem tratamento (link antigo,
  bookmark), mostra notificação de erro e não deixa gravar — validação no servidor, não só no Select.
  Com tratamento, grava normalmente mas mostra hint informativo.
- **`DailyRecordResource` (tabela/vista)**: badge "piscina encerrada nesta data" nas linhas cujo
  `registado_em` cai num período encerrado. Serve para auditoria retroativa.
- **`OperacaoHub`**: contagens do hub excluem piscinas encerradas.

### Ações Operacionais

**Permitidas sempre**, inclusive em piscina encerrada — é precisamente durante o encerramento que se
faz drenagem, lavagem de filtros, obra. O Select em `OperationalActionResource:156` deixa de filtrar
por `active` puro e passa a mostrar todas as ativas, marcando as encerradas. A vista/tabela mostra
badge de piscina encerrada à data.

### Incidentes

- **Manuais: permitidos sempre** (vandalismo, fuga, avaria durante encerramento).
- **`ExecuteBusinessRulesCommand`**: a regra de auto-incidente (3× violação/dia/piscina) salta piscinas
  encerradas nesse dia. Escalação >24h continua a funcionar (um incidente aberto não deixa de ser
  urgente porque a piscina fechou).
- **`IncidentResource`**: badge "piscina encerrada em {ocorreu_em}" na tabela e na vista; filtro
  opcional "incluir incidentes de piscinas encerradas".

### Análise de Parâmetros

- **`HeatmapConformidadeWidget`**: novo estado de célula `encerrada`, cor própria (cinzento escuro
  tracejado) com tooltip "Encerrada — {motivo}", distinto de `sem_dados`. **É a prova visual mais
  clara de que a app compreende o estado.** Usa `PoolClosureService::mapa()` para os 30 dias.
- **`ScoreConformidadeWidget`**: denominador por piscina exclui dias encerrados. Nota de rodapé
  "N dias excluídos (piscina encerrada)".
- **`CloroPhChartWidget`**: banda de fundo sombreada sobre os intervalos encerrados (plugin custom
  Chart.js pequeno em `resources/js/app.js`, desenhado no `beforeDraw`), com o `spanGaps = false`
  já existente a garantir que a linha não atravessa o período.
- **`ConsumoQuimicosWidget`** e **`EstabilidadeMedicoesWidget`**: dias encerrados fora das médias;
  piscina encerrada durante toda a janela aparece com estado explícito em vez de zero.

### Relatório PDF (livro sanitário CN 14/DA) — a parte legalmente crítica

- `RelatorioPdf::construirSeccoes()` ganha a chave `encerramentos` por secção (intervalos que
  intersetam o período pedido).
- `resources/views/pdf/livro-sanitario.blade.php`: banner por piscina listando os períodos
  ("Piscina encerrada de 03/08/2026 a 30/09/2026 — motivo: encerramento de época balnear; registado
  por Daniel Paz em 03/08/2026") **e** linhas inline na tabela, em ordem cronológica, a fechar o
  bloco de dias sem registos. Um auditor tem de ver a justificação no sítio onde faltam os dados.
- O resumo de conformidade (`mostrar_resumo`) exclui dias encerrados do denominador.
- `GerarRelatorioMensalCommand`: se uma piscina esteve encerrada todo o mês, gera a secção com a
  declaração de encerramento em vez de uma tabela vazia.

### Comandos agendados

Todos passam a `Pool::operacionais()` (hoje) ou ao mapa (histórico):

| Comando | Mudança |
|---|---|
| `CheckParameterTrendsCommand` | exclui dias encerrados da série da tendência |
| `SendShiftSummaryCommand` | `totalPiscinas` só abertas |
| `SendWeeklyComparisonCommand` | comparação semana-a-semana ignora dias encerrados |
| `SendComplianceDigestCommand` | não avisa por falta de registo em piscina encerrada |
| `CheckOpenTapsCommand` | salta piscinas encerradas |
| `ExecuteBusinessRulesCommand` | auto-incidente salta piscinas encerradas |
| `GerarRelatorioMensalCommand` | ver acima |

### Restantes ecrãs

- **`EsquemaPiscina`**: piscina encerrada continua navegável com selo "ENCERRADA — {motivo}" sobre o
  circuito; a sonda mostra-se sem avisos de stale.
- **Sondas Hanna**: `hanna:sync` continua a correr (os dados são úteis e o controlador continua ligado).
  Suprimir avisos de sonda offline/stale e de dosagem para piscinas encerradas sem tratamento.
- **`DosingContainer`**: consumo retroativo por dosagem mantém-se — reflete o que o controlador fez.
- **Stock**: sem mudança funcional. Alerta de stock baixo de uma instalação com todas as piscinas
  encerradas desce a neutro (baixa prioridade, pode ficar para depois).
- **`MetricsController`** (Prometheus): novo gauge `piscinas_encerradas`; gauges de conformidade
  excluem encerradas.
- **`AdminPanelProvider:105`** (`window.__poolNomes`): sem mudança.

---

## Testes

- `PoolClosureTest` — sobreposição rejeitada; `estaEncerradaEm()` nos limites (`inicio` e `fim`
  inclusivos, `fim = null` em aberto); `reabrir()` grava `fim`/`reaberta_por`; `scopeOperacionais`.
- `PoolClosureServiceTest` — `encerrar()` resolve `TapAlert` e `AlertState`; lote; caches invalidados.
- `AlertasServiceEncerramentoTest` — piscina encerrada não gera `SEM_REGISTO`; com
  `agua_em_tratamento` gera `FORA_LIMITES`; denominadores corretos.
- `DailyRecordPiscinaEncerradaTest` — gravação bloqueada sem tratamento, permitida com tratamento.
- `RelatorioPdfEncerramentoTest` — declaração de encerramento presente no HTML/PDF; denominador do
  resumo.
- `HeatmapEncerramentoTest` — célula com estado `encerrada`.

## Fases de execução

1. **Domínio** — migração, `MotivoEncerramento`, `PoolClosure`, API no `Pool`, `PoolClosureService`,
   `PoolClosurePolicy`, cache, testes de domínio. Nada visível ainda.
2. **Escrita** — página `EncerramentoPiscinas`, `PoolClosureResource`, ações na `PoolResource`,
   notificações.
3. **Leitura A (operação diária)** — `AlertasService`, `PainelPiscinasWidget`, Registo Diário,
   Ações Operacionais, Incidentes.
4. **Leitura B (dados e conformidade)** — 5 widgets de análise, Relatório PDF, comandos agendados,
   `EsquemaPiscina`, `MetricsController`.
5. **Fecho** — `docs/paginas/encerramentos.md`, secção no `CLAUDE.md` (raiz e por Resource afetado),
   `vendor/bin/pint`, `composer test`, `npm run build`, push.

Fase 1 é pré-requisito de tudo. As fases 3 e 4 são independentes entre si e podem ser feitas em
qualquer ordem depois da 2.
