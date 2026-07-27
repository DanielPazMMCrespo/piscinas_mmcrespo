# Piscinas (`PoolResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
CRUD de piscinas dentro de uma instalação — os dados físicos (volume, limites) usados em todos os cálculos de conformidade. Só Admin.

## Estrutura de dados
- `installation_id`, `name`, `type` (texto livre, ex. Interior/Exterior/Infantil — não é enum), `temp_min`/`temp_max` (obrigatórios), `orp_min`/`orp_max` (opcionais, só com placeholder "660"/"750" — **não gravam esse valor se ficarem vazios**, o fallback tem de estar implementado noutro sítio em runtime), `volume` (usado na calculadora de dosagem), `active`.
- `$fillable` do model inclui `ordem_bombas`/`ordem_filtros`, que **não aparecem no formulário** — não são dead code: `DailyRecordFormBuilder` usa-os para ordenar as piscinas nos passos de Bomba/Filtros. Só não são editáveis na UI (set via seed/tinker).
- `nomeCompleto()`: concatena instalação + piscina, exceto se forem iguais.
- Hook `deleting`: apaga `filter_checks` (FK RESTRICT — obrigatório), `tap_alerts`, `sensor_readings`, `bidoesDosagem()`. `daily_records`/`operational_actions`/`user_pools` têm `cascadeOnDelete` na BD, logo caem sozinhos.

## Ações
Ver, Editar, Eliminar (bulk, com aviso de cascata no modal de confirmação).

## Coisas resolvidas
- ✓ **`canAccess()` agora usa null-safe operator**: consistente com UserResource e outras páginas.
- ✓ **`filter_checks` no hook de delete**: `filter_checks.pool_id` é `constrained()` sem cascade (RESTRICT) — apagar uma piscina com verificações rebentava com FK violation em PostgreSQL. O hook agora apaga-as primeiro.
- ✓ **Aviso de cascata**: o modal de eliminação lista o que é apagado em cascata.
- ✓ **`orp_min`/`orp_max` sem default confirmado como intencional**: null = usar o range padrão do BL132 (660/750). O fallback `?? 660/750` está aplicado em `PainelPiscinasWidget` e `EsquemaPiscina` onde o ORP é avaliado. (Nota: a constante 660/750 está duplicada nesses dois sítios + `CloroPhChartWidget` — candidato a `WaterQualityThresholds`.)
