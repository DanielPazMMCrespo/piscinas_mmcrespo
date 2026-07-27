# Análise de Parâmetros (`app/Filament/Pages/AnaliseParametros.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Uso pontual, não diário.

## Propósito
Página dedicada de análise, reutilizando `CloroPhChartWidget` em tamanho grande. Acesso via `NSPermission::ANALISE_PARAMETROS`. `mount()` regista entrada de activity log ("Acedeu à Análise de Parâmetros") — auditoria de acesso, não só de escrita.

## Estrutura
View renderiza sempre o `CloroPhChartWidget`; **exceto para NS**, renderiza também `ScoreConformidadeWidget`, `HeatmapConformidadeWidget`, `EstabilidadeMedicoesWidget`, `ConsumoQuimicosWidget` (estes 4 só aparecem aqui, não no Dashboard). CSS inline aumenta a altura dos canvases nesta página (420/520px, 300/340px em mobile).

## Widgets extra desta página
- **`ScoreConformidadeWidget`**: KPI único — % de registos sem violações nos últimos 7 dias. `canView`: Admin/Gestor/Técnico. Cor por thresholds (≥95% sucesso, ≥85% aviso, senão perigo).
- **`HeatmapConformidadeWidget`**: grelha 7 dias × piscinas, cor = pior estado do dia. Avalia 4 campos (pH, cloro livre, cloro combinado, temperatura) via `DailyRecord::avaliarConformidade()`, agrega mensagens de violação como tooltip.
- **`EstabilidadeMedicoesWidget`**: tabela por piscina (14 dias) que separa "a água oscila" de "a medição oscila": σ do pH manual vs σ do pH da sonda (rácio ≥3 → suspeita de rotina de amostragem), Δ pH médio manual↔sonda com emparelhamento ±15 min (|Δ| médio ≥0,2 → desvio sistemático, verificar calibração/técnica), σ do cloro livre manual e σ do ORP como contexto. Mínimo 5 análises manuais para interpretar. `canView`: Admin/Gestor/Técnico.
- **`ConsumoQuimicosWidget`**: barras empilhadas de consumo por piscina, 6 meses. Fonte: `RecordAddition` (adições reais do livro sanitário) — **não** os logs de stock (esses são por instalação, não por piscina). Filtra datasets com soma zero.

## Coisas a rever
Nada de relevante encontrado — página coerente e simples.
