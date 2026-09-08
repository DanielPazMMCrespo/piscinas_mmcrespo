# Dashboard (`app/Filament/Pages/Dashboard.php`)

Sem pasta própria — página standalone. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral.

## Propósito
Substitui o `Filament\Pages\Dashboard` nativo. Sem controlo de acesso próprio (qualquer utilizador autenticado). Cabeçalho vazio de propósito (`getHeading()` devolve `''`) porque o `PainelPiscinasWidget` desenha o seu próprio cabeçalho estilizado no topo (ver conversa desta sessão — foi feito deliberadamente para não duplicar título).

## Widgets (ordem real ditada pelo `$sort` de cada um, não pela ordem do array em `getWidgets()`)

## Widgets (ordem real ditada pelo `$sort` de cada um)

### `PainelPiscinasWidget` (sort -30, primeiro)
Cartão de estado por piscina — pH, Redox(ORP), Cl. Livre, Cl. Combinado, Temp., Turbidez, cada um com valor à direita do label. Combina duas fontes de verdade (manual vs. sonda Hanna).
- **Cache** 10min por `cacheScope()` (`full_v{N}` / `ns_{id}_v{N}`), lock anti-stampede (15s timeout, block 5s, fallback sem cache se expirar).
- `CACHE_SHAPE_VERSION` tem de ser incrementado manualmente sempre que a forma do array cacheado mudar.
- Cascata de origem por métrica: controlador online (≤60min, sem "artefacto") → manual (≤8h) → controlador offline/artefacto → sem dados.
- `LeituraArtefactoService::motivoEm()` deteta janelas de lavagem/bomba parada para não contar como conformidade.
- Sparklines SVG (curva Catmull-Rom) gerados em PHP, sem depender de JS de gráficos.
- Ações rápidas: ação principal destacada `[Registar Água]` (direta para os parâmetros de água) + botões compactos de máquinas (`Lavar filtro`, `Torneira`, `Contador`). Duplicação de "Análise rápida" eliminada.

### `QuadroOperacionalWidget` (sort -20)
Lista exception-first dos alertas ativos + secção colapsável "Resolvidos hoje". Escondido do NS.
- Alertas calculados no momento via `AlertasService::calcular()`.
- Alerta de "Sem registo diário" só acorda após a hora crítica (12:00), eliminando ruído matinal.
- Ação "Resolver" é direta (1 toque) sem modais bloqueadores de confirmação.
- Alertas de stock baixo removidos daqui (já vivem na tabela dedicada de stock).

### `StockBaixoWidget` (sort -10)
Tabela de produtos com `quantity <= limite_minimo`.
- Cache de 5min escopada por utilizador/perfil (`cache_low_stock_ids_ns_{id}` vs `cache_low_stock_ids_admin`), evitando contaminação de visualização entre perfis.
- Ação direta "Repor do armazém" (com pesquisa pré-preenchida no armazém).

*(Nota: `CloroPhChartWidget` e `EstabilidadeMedicoesWidget` foram retirados da Dashboard principal para tornar a página rápida e ergonómica no telemóvel; vivem na página dedicada "Análise de Parâmetros").*

## Coisas resolvidas
- ✓ Gráficos pesados e tabela de 14 dias movidos exclusivamente para "Análise de Parâmetros" — Dashboard 100% leve no terreno.
- ✓ Extinção de "Análise rápida" paralela — toda a água vai para `daily_records` oficial.
- ✓ Ação principal "Registar Água" destacada no cartão.
- ✓ Correção de escopo de cache no `StockBaixoWidget`.
- ✓ Remoção de pré-carregador artificial (`filament.preloader`).
- ✓ Desativação de incidentes automáticos de base de dados por 3 violações de parâmetros.
- ✓ Fim do bloqueio forçado de Nadador-Salvador em piscinas encerradas.
- ✓ Eliminação da exigência de justificação escrita para valor zero (`0.00`).

## Coisas a rever
- `CACHE_SHAPE_VERSION` continua a ser um mecanismo manual (fonte de um 500 em produção quando esquecido). Não é bug atual; só um risco conhecido a lembrar ao mudar a forma do payload do `PainelPiscinasWidget`.
