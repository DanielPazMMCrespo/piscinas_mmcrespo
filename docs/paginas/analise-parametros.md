# Análise de Parâmetros (`app/Filament/Pages/AnaliseParametros.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Uso pontual, não diário.

## Propósito
Página dedicada de análise avançada e auditoria regulamentar CN 14/DA. Reutiliza o motor de visualização de `CloroPhChartWidget` em tamanho grande (alta resolução temporal). Acesso via `NSPermission::ANALISE_PARAMETROS` e `PaginaGestor::ANALISE_PARAMETROS`. O `mount()` regista auditoria de acesso no activity log ("Acedeu à Análise de Parâmetros").

## Estrutura e Navegação Segmentada (Apple HIG / Tesla)
Para eliminar scroll infinito e focar a atenção do utilizador, a página adota navegação segmentada instantânea em abas (Alpine.js `x-data="{ tab: 'evolucao' }"` com 0ms de latência e sem recarga de página):

1. **Aba 1: 📊 Evolução & Gráficos**
   - **`CloroPhChartWidget`** em formato expansivo (ecrã total).
   - Métricas padrão inteligentes: `ph` e `cloro_livre` (garante que piscinas sem sonda Hanna abrem imediatamente com dados úteis de análises manuais, eliminando o ecrã vazio anterior).
   - Filtros temporais: `6h`, `24h`, `7d`, `14d`, `30d` (novo: janela mensal para inspeções camarárias e auditorias da DGS) e `Personalizado`.
   - Atalhos táteis rápidos de calibração "Comparar Sensor vs Manual" (pH, Cloro ORP vs Livre, Temperatura).

2. **Aba 2: 🛡️ Auditoria DGS (CN 14/DA)**
   - **`ScoreConformidadeWidget`**: KPIs executivos de conformidade regulamentar geral e detalhada por piscina (seletor 7/30 dias).
   - **`HeatmapConformidadeWidget`**: Grelha semanal 7 dias × piscinas com mapa de calor (verde/aviso/perigo/encerrada) e indicação de parâmetros violados.
   - **`ViolacoesPeriodoWidget`**: Tabela forense de todas as medições fora dos limites legais CN 14/DA, timestamps com tempo relativo, piscina e justificação.

> [!NOTE]
> Para o cargo **Nadador-Salvador**, a página renderiza diretamente o gráfico da sua piscina atribuída (sem acesso a dados globais de auditoria). Os widgets desnecessários de "Estabilidade das medições" e "Consumo de químicos por piscina" foram removidos da vista para maximizar a fluidez e a clareza operacional.
