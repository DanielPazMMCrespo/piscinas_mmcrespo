# Design: Gráfico de Análise de Parâmetros — migração para Apache ECharts

Data: 2026-07-13
Estado: aprovado por Daniel
Mockups: https://claude.ai/code/artifact/e3719349-dd1b-4022-8358-3393dabb0bc4

## Objetivo

Tornar o gráfico da página de Análise de Parâmetros "next level" em quatro frentes,
com prioridade a análise profunda em mobile:

1. **Conformidade à vista** — perceber de imediato se os valores estão dentro dos
   limites legais (CN 14/DA), sem interpretar eixos.
2. **Navegação temporal** — focar num intervalo específico com o dedo, sabendo
   sempre onde se está no período total.
3. **Valores exatos** — ler o valor preciso de qualquer ponto sem precisão de toque.
4. **Comparação** — multi-métrica numa piscina e multi-piscina numa métrica,
   mantendo também o modo atual de 2 eixos.

## Decisão

Substituir Chart.js por Apache ECharts no componente do gráfico. Todas as
funcionalidades necessárias são nativas do ECharts (crosshair/tooltip axis,
markArea, visualMap, dataZoom slider+inside, legenda clicável), eliminando os
plugins e o código custom que seriam necessários para o mesmo resultado em
Chart.js. Alternativas consideradas: evoluir Chart.js (teto de UX mobile mais
baixo, mais código custom a manter) e quick-wins apenas (não resolve navegação
temporal nem conformidade visual).

## Âmbito

Confinado a 3 ficheiros + testes:

- `app/Filament/Widgets/CloroPhChartWidget.php`
- `resources/views/filament/widgets/painel-parametros.blade.php`
- `resources/js/charts/analise.js` (novo; importado por `resources/js/app.js`)

Nenhum outro ficheiro é tocado. O projeto está em fase final: proibido restaurar
ficheiros inteiros de commits antigos; alterações cirúrgicas apenas.

## Arquitetura

### Modos de visualização

Um seletor de modo substitui os dois selects de eixo atuais:

| Modo | Séries | Eixos Y | Banda legal |
|---|---|---|---|
| `dual` (atual, mantido) | 2 métricas, 1 piscina | 2 eixos reais (esq/dir) | markArea por eixo quando a métrica tem banda |
| `multi-metrica` | 1–7 métricas, 1 piscina | 1 eixo normalizado 0–100% do intervalo min/max de cada métrica | sem banda no eixo; conformidade via visualMap na linha |
| `multi-piscina` | 1 métrica, todas as piscinas ativas no scope do utilizador | 1 eixo real | banda única da métrica (markArea) |

### Widget PHP (`CloroPhChartWidget`)

- Livewire properties: `mode` (`dual`|`multi-metrica`|`multi-piscina`, default `dual`),
  `leftMetric`/`rightMetric` (modo dual), `selectedMetrics: array` (multi-métrica),
  `selectedMetric: string` (multi-piscina). `poolSelecionada`, `period`, `tabAtiva`,
  `customStartDate/EndDate` mantêm-se.
- Validação server-side de todos os inputs contra listas válidas (padrão atual
  `PERIODOS_VALIDOS`/`TABS_VALIDAS`, estendido a modos e métricas).
- `getChartPayload()` devolve:

```json
{
  "mode": "dual",
  "period": "7d",
  "series": [
    {
      "key": "ph", "label": "pH", "unidade": "", "casas": 2,
      "cor": "#76b82a",
      "banda": {"min": 6.9, "max": 8.0},
      "yMin": 6.5, "yMax": 8.5,
      "axis": "left",
      "data": [{"x": "2026-07-10T14:30:00+01:00", "y": 7.42}]
    }
  ]
}
```

  - `axis` só é relevante no modo `dual` (`left`/`right`).
  - No modo `multi-metrica`, cada série leva `yMin/yMax` para a normalização no JS.
  - No modo `multi-piscina`, `key` é o pool id, `label` é o nome da piscina e a
    banda/unidade vêm ao nível do payload (métrica única).
- Queries: iguais às atuais — `whereDoesntHave('correcoes')`, coalesce `campo ?? ns_campo`,
  leituras de sonda de `sensor_readings`, cache apenas em períodos longos sem sensor.
  Multi-piscina = N queries (máx. 5 piscinas; sem necessidade de otimizar).
- Scope: `poolsQuery()` mantém a restrição de Nadador-Salvador às suas piscinas,
  também no modo multi-piscina.

### Componente JS (`resources/js/charts/analise.js`)

- Componente Alpine `mmcEcharts`, registado em `alpine:init` (padrão atual).
- Import tree-shaken:
  `echarts/core` + `LineChart` + `GridComponent`, `TooltipComponent`,
  `DataZoomComponent` (inside + slider), `LegendComponent`, `MarkAreaComponent`,
  `VisualMapComponent`, `CanvasRenderer`. Import dinâmico (`import()`) no primeiro
  init, partilhado entre instâncias — mesmo padrão do componente atual.
- Ciclo de vida idêntico ao provado no componente atual: `wire:ignore` no blade,
  init único, updates via `Livewire.on('mmc-chart-update')`, `dispose()` no
  destroy, retry por requestAnimationFrame enquanto o container tem largura 0
  (máx. 15 tentativas), ResizeObserver com debounce 100ms → `chart.resize()`.
- Opções ECharts:
  - `tooltip: { trigger: 'axis', axisPointer: { type: 'cross' }, confine: true }`,
    formatter custom com valores reais + unidade (no modo multi-métrica converte
    o valor normalizado de volta ao real).
  - Banda legal: `markArea` (cor `rgba(118,184,42,0.14)`, dark: `0.12`).
  - Não conformidade: `visualMap piecewise` por série com banda — troço fora da
    banda a `#dc2626`. No modo multi-métrica aplica-se sobre o valor real via
    `dimension` dos dados originais guardados em `series.data[i].value[2]`.
  - `dataZoom`: `[{type: 'inside'}, {type: 'slider', height: 34}]`.
  - Autoscale: `min`/`max` do eixo omitidos (ECharts autoescala); `scale: true`.
  - Dark mode: cores de texto/grelha da função `cores()` atual, reagindo ao
    `Alpine.store('theme')` (padrão atual).
- Legenda nativa clicável (`legend.selected`) para ligar/desligar séries.

### Blade

- Mantém: tabs Gráfico/Tabela, botões de período, form Filament, `wire:ignore`
  no wrapper Alpine, estados vazios (`_hasData`/`_hasSeries` — getter já corrigido
  em 52d34e7, portado para o novo componente).
- Muda: seletor de modo (3 chips), selects condicionais por modo
  (esq/dir | multi-select de métricas | select de métrica), container do ECharts
  (`div` em vez de `canvas`; o ECharts cria o canvas).
- Altura: 380px desktop / 300px mobile (+34px do slider face aos 260px atuais).

## Remoção do código antigo

O componente `mmcChart` e as dependências `chart.js`, `chartjs-plugin-zoom`,
`chartjs-plugin-annotation`, `chartjs-adapter-luxon` (e `hammerjs` se não usado
por mais nada) só são removidos num commit próprio, **depois de Daniel validar o
novo gráfico em produção**. Até lá coexistem no bundle.

## Testes

Feature test do payload (`tests/Feature/`):

- Modo dual: 2 séries com `axis` correto, banda presente quando a métrica a tem.
- Modo multi-métrica: N séries com `yMin/yMax`, respeita `selectedMetrics` inválidas
  (filtradas contra `getMetricas()`).
- Modo multi-piscina: uma série por piscina ativa; utilizador Nadador-Salvador
  só recebe as suas piscinas.
- Correções excluídas (`whereDoesntHave('correcoes')`) e coalesce `ns_*` mantidos.
- Períodos e datas custom validados.

Verificação manual no preview local (desktop + 375px): crosshair, slider,
visualMap vermelho num registo fora da banda, toggle de séries, dark mode.

## Plano de commits

1. `feat(charts): payload multi-modo no CloroPhChartWidget + testes`
2. `feat(charts): componente mmcEcharts (resources/js/charts/analise.js)`
3. `feat(charts): blade da análise com seletor de modo e container ECharts`
4. (após validação em produção) `chore(charts): remover chart.js e componente antigo`

Cada commit toca apenas os ficheiros do seu passo. Sem restauros de ficheiros
inteiros de commits antigos.

## Riscos

- **Bundle**: +~90 kB gzip enquanto chart.js coexistir (+~330 kB não-gzip);
  desaparece no commit 4. Aceitável temporariamente.
- **visualMap no modo multi-métrica**: a conversão normalizado↔real no tooltip e
  no visualMap é o ponto mais delicado; coberto pela verificação manual com dados
  reais fora da banda.
- **Touch no slider**: alvos do dataZoom slider são pequenos por defeito —
  aumentar `handleSize`/`moveHandleSize` para alvo tátil ≥40px.
