# Dashboard (`app/Filament/Pages/Dashboard.php`)

Sem pasta própria — página standalone. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral.

## Propósito
Substitui o `Filament\Pages\Dashboard` nativo. Sem controlo de acesso próprio (qualquer utilizador autenticado). Cabeçalho vazio de propósito (`getHeading()` devolve `''`) porque o `PainelPiscinasWidget` desenha o seu próprio cabeçalho estilizado no topo (ver conversa desta sessão — foi feito deliberadamente para não duplicar título).

## Widgets (ordem real ditada pelo `$sort` de cada um, não pela ordem do array em `getWidgets()`)

### `PainelPiscinasWidget` (sort -30, primeiro)
Cartão de estado por piscina — pH, Redox(ORP), Cl. Livre, Cl. Combinado, Temp., Turbidez, cada um com valor à direita do label. Combina duas fontes de verdade (manual vs. sonda Hanna).
- **Cache** 10min por `cacheScope()` (`full_v{N}` / `ns_{id}_v{N}`), lock anti-stampede (15s timeout, block 5s, fallback sem cache se expirar).
- `CACHE_SHAPE_VERSION` tem de ser incrementado manualmente sempre que a forma do array cacheado mudar (mecanismo manual — já causou um 500 em produção quando esquecido, ver histórico de commits).
- Cascata de origem por métrica: controlador online (≤60min, sem "artefacto") → manual (≤8h) → controlador offline/artefacto → sem dados. Cloro combinado é **sempre** manual (nunca via sonda); turbidez também só manual.
- `LeituraArtefactoService::motivoEm()` deteta janelas de lavagem/bomba parada para não contar como conformidade.
- Sparklines SVG (curva Catmull-Rom) gerados em PHP, sem depender de JS de gráficos.
- Ações rápidas (Registo Rápido, Análise, Lavar filtro, Torneira, Contador) são injetadas **depois** da cache (dependem do utilizador atual, corretamente não cacheadas).

### `CloroPhChartWidget` (sort 2, reutilizado também em Análise de Parâmetros)
Gráfico de linhas configurável (2 eixos), comparando parâmetro manual vs. sonda, por piscina.
- `canView()`: permissão `NSPermission::ANALISE_PARAMETROS`.
- NS tem período fixo forçado a 12h (não pode mudar) e a métrica "transparência" escondida.
- Cache 10min só quando o período não é curto e nenhum eixo é do controlador (dados de sensor mudam a cada 15min).
- Exclui leituras em janelas de "artefacto".

### `QuadroOperacionalWidget` (sort -20)
Kanban de alertas (Para tratar / Em tratamento / Resolvido hoje). Escondido do NS.
- Alertas são **calculados** (não persistidos) via `AlertasService::calcular()`, cache 30s por utilizador. Só o **estado de tratamento** (`AlertState`) é persistido.
- Poda de `AlertState` >7 dias corre no máximo 1x/hora, dentro do próprio `getViewData()` (side-effect de escrita numa leitura de widget).
- Auto-resolução: se a condição de um alerta desaparecer, marca `resolvido_auto` sozinho.
- Suporta status legado `em_curso` — indica uma renomeação de estados nunca totalmente limpa.

### `StockBaixoWidget` (sort -10)
Tabela de produtos com `quantity <= limite_minimo`, filtrada por instalações do NS. Cache 5min só dos IDs (query final volta à BD). Sem `canView()` — visível a todos. Sem ações, só leitura.

## Coisas resolvidas
- ✓ Poda de `AlertState` >7 dias movida para `alerts:housekeeping` command (agendado a cada hora) — `getViewData()` já não tem side-effects de DELETE.
- ✓ `em_curso` como status legado documentado — ainda suportado para leitura (retrocompatibilidade com registos antigos), mas não pode ser criado novo (validação em `AlertasService::moverAlerta()`).
