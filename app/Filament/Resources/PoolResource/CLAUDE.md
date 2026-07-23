# Piscinas (`PoolResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
CRUD de piscinas dentro de uma instalação — os dados físicos (volume, limites) usados em todos os cálculos de conformidade. Só Admin.

## Estrutura de dados
- `installation_id`, `name`, `type` (texto livre, ex. Interior/Exterior/Infantil — não é enum), `temp_min`/`temp_max` (obrigatórios), `orp_min`/`orp_max` (opcionais, só com placeholder "660"/"750" — **não gravam esse valor se ficarem vazios**, o fallback tem de estar implementado noutro sítio em runtime), `volume` (usado na calculadora de dosagem), `active`.
- `$fillable` do model inclui `ordem_bombas`/`ordem_filtros`, que **não aparecem no formulário** — geridos noutro lado ou dead code.
- `nomeCompleto()`: concatena instalação + piscina, exceto se forem iguais.
- Hook `deleting`: apaga manualmente `tap_alerts`, `sensor_readings` e `bidoesDosagem()` associados (queries diretas, não cascade); `registosDiarios()` **não** é limpo.

## Ações
Ver, Editar, Eliminar (bulk, **sem** confirmação extra nem bloqueio por dados associados).

## Coisas resolvidas
- ✓ **`canAccess()` agora usa null-safe operator**: consistente com UserResource e outras páginas.

## Coisas a rever
- `orp_min`/`orp_max` só têm placeholder, não `->default()` — confirmar onde está o fallback real de 660/750 quando o campo fica null.
- Ao apagar uma Pool, `sensor_readings`/`tap_alerts`/bidões são limpos mas `registosDiarios()` fica — inconsistência dentro do próprio hook.
- `$fillable` inclui `ordem_bombas`/`ordem_filtros` mas não aparecem no formulário — dead code ou geridos noutro lado?
