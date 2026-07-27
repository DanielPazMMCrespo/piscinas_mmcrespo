# Instalações (`InstallationResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
CRUD do nível hierárquico acima de Pool (instalação municipal com várias piscinas). Só Admin.

## Estrutura de dados
- Form simples: `name`, `morada`, `active`.
- `$fillable` do model inclui `tanques_verificaveis` (bool), que **não existe no formulário** — decide se a instalação exige registo de "verificações de tanque" (usado em `EsquemaPiscina`/`DailyRecordResource`), configurado só por seed/tinker.
- Hook `deleting`: apaga `incidentes()`; depois cada piscina **uma a uma** (`piscinas->each->delete()`) para disparar o hook `Pool::deleting` de cada (limpa filter_checks/tap_alerts/sensor_readings/bidões); depois (FK `restrictOnDelete`) apaga os `registos()` de cada `stockInstallation` antes do próprio `stockInstallation`.

## Ações
Ver, Editar, Eliminar (bulk, com aviso de cascata no modal).

## Coisas resolvidas
- ✓ **`canAccess()` agora usa null-safe operator**: consistente com UserResource e outras páginas.
- ✓ **Cascade das piscinas corrigido**: era `piscinas()->delete()` em massa (não disparava `Pool::deleting`), deixando `tap_alerts`/`sensor_readings`/bidões órfãos e rebentando no `filter_checks` RESTRICT. Agora apaga piscina a piscina, disparando o hook de cada.
- ✓ **Aviso de cascata no modal de eliminação**.

## Coisas a rever
- **`tanques_verificaveis` não é exposto no formulário Filament** — se precisares de mudar isto por instalação, tem de ser via tinker/seed, não pela UI. (Gap menor, deixado por decidir se vale expor.)
