# Análise de Parâmetros (`app/Filament/Pages/AnaliseParametros.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Uso pontual, não diário.

## Propósito
Página dedicada de análise, reutilizando `CloroPhChartWidget` em tamanho grande. Acesso via `NSPermission::ANALISE_PARAMETROS`. `mount()` regista entrada de activity log ("Acedeu à Análise de Parâmetros") — auditoria de acesso, não só de escrita.

## Estrutura
View renderiza sempre o `CloroPhChartWidget`; **exceto para NS**, renderiza também `ScoreConformidadeWidget`, `HeatmapConformidadeWidget`, `ConsumoQuimicosWidget` (estes 3 só aparecem aqui, não no Dashboard). CSS inline aumenta a altura dos canvases nesta página (420/520px, 300/340px em mobile).

## Widgets extra desta página
- **`ScoreConformidadeWidget`**: KPI único — % de registos sem violações nos últimos 7 dias. `canView`: Admin/Gestor/Técnico. Cor por thresholds (≥95% sucesso, ≥85% aviso, senão perigo).
- **`HeatmapConformidadeWidget`**: grelha 7 dias × piscinas, cor = pior estado do dia. Avalia 4 campos (pH, cloro livre, cloro combinado, temperatura) via `DailyRecord::avaliarConformidade()`, agrega mensagens de violação como tooltip.
- **`ConsumoQuimicosWidget`**: barras empilhadas de consumo por piscina, 6 meses. Fonte: `RecordAddition` (adições reais do livro sanitário) — **não** os logs de stock (esses são por instalação, não por piscina). Filtra datasets com soma zero.

## Coisas a rever
Nada de relevante encontrado — página coerente e simples.
