# Esquema (`app/Filament/Pages/EsquemaPiscina.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Uso frequente (uma das páginas do dia a dia). Só Admin.

## Propósito
Esquema visual do circuito de água por instalação (torneira/contador → piscina → bomba → filtro → retorno → tanque → bidões), com estado "ao vivo" de cada componente.

## Estrutura
- Navegação: `#[Url(as: 'instalacao')]` e `#[Url(as: 'pool')]` (deep-link antigo por piscina, resolve para a instalação e depois é limpo).
- Duas secções na view: "Visão geral" (grelha de cartões clicáveis, pill de estado geral, chips de valores, dots por componente, mini-bidões via partial `esquema-bidao`) e "Detalhe por piscina" (stack de circuitos completos via partial `esquema-circuito`, destaque ao passar o rato no cartão da visão geral). `wire:poll.30s` refresca tudo.

## Lógica não óbvia
- `valoresAgua()`: mesma cascata sonda-fresca(≤60min)→manual(≤8h)→sonda-stale→artefacto→sem-dados do `PainelPiscinasWidget`, mas **reimplementada aqui de forma independente** — duplicação de lógica de negócio, risco de divergência se um dos dois for alterado sem o outro.
- `STALE_HORAS = 24` (diferente das 8h do registo manual no dashboard e dos 60min do controlador) — piscina "desconhecida" só depois de 24h sem dados.
- `estadoGeral()`: semáforo agregado — cor mais grave vence (água má, torneira aberta, ou bidão crítico → crítico; stale, bomba parada, tanque por verificar, bidão em aviso → aviso).
- `estadoFiltro()`: junta 3 fontes possíveis de "última lavagem" (`FilterCheck`, campo `filtro_faz_retrolavagem` do registo diário, ação operacional de lavagem) e usa a mais recente das 3.
- `estadoTanque()`: só aparece se a instalação tiver `tanques_verificaveis` (campo não exposto na UI de Definições/Instalações — ver `InstallationResource/CLAUDE.md`).
- `justificacoes()`: ações operacionais das últimas 6h usadas para explicar valores fora dos limites (ex. lavagem de filtro a afetar pH/ORP temporariamente).

## Coisas a rever
- ~~Cascata de fontes duplicada com o `PainelPiscinasWidget`~~ — **resolvido**: ambos usam agora o `SourceSelectionService` (`app/Services/SourceSelectionService.php`). `valoresAgua()` delega no serviço, já não reimplementa a lógica.
