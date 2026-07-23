# Instalações (`InstallationResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
CRUD do nível hierárquico acima de Pool (instalação municipal com várias piscinas). Só Admin.

## Estrutura de dados
- Form simples: `name`, `morada`, `active`.
- `$fillable` do model inclui `tanques_verificaveis` (bool), que **não existe no formulário** — decide se a instalação exige registo de "verificações de tanque" (usado em `EsquemaPiscina`/`DailyRecordResource`), configurado só por seed/tinker.
- Hook `deleting`: apaga em cascata `incidentes()`, depois (por causa de FK `restrictOnDelete`) apaga primeiro os `registos()` de cada `stockInstallation` antes de apagar o próprio `stockInstallation`. `piscinas()` é apagada via query builder direta.

## Ações
Ver, Editar, Eliminar (bulk, sem confirmação/bloqueio).

## Coisas resolvidas
- ✓ **`canAccess()` agora usa null-safe operator**: consistente com UserResource e outras páginas.

## Coisas a rever
- **`tanques_verificaveis` não é exposto no formulário Filament** — se precisares de mudar isto por instalação, tem de ser via tinker/seed, não pela UI.
- Apagar uma Instalação com piscinas associadas **não** dispara o hook `deleting` do model `Pool` (delete em massa via query builder não dispara eventos por registo) — `tap_alerts`/`sensor_readings`/bidões dessas piscinas ficam órfãos, ao contrário de quando se apaga uma Pool isoladamente pelo `PoolResource`.
- Eliminar não tem confirmação/aviso do impacto (contraste com as salvaguardas do UserResource).
