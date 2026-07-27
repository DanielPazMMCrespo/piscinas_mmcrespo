# Activity Log (`CustomActivitylogResource`)

Não tem pasta própria (ficheiro único, sem `Pages/` — estende o Resource do pacote `rmsramos/activitylog`, que já fornece navegação/`getPages()`/`canAccess()` herdados).

## Propósito
Trilho de auditoria genérico (Spatie Activitylog). Sobrepõe só a tabela para tradução PT e formatação por domínio — sem `create`/`edit`/`delete` (log é imutável por design).

## Lógica não óbvia
- `getLogNameColumnComponent()`: traduz só 3 categorias (`auth`, `analise`, `relatorios`); qualquer outra cai em `ucwords()`.
- `getSubjectTypeColumnComponent()`: cadeia de heurísticas para um identificador legível (`name` → `label` → `produto.name` → `registado_em` formatado para DailyRecord → `#{id} (tipo)` para Incident/OperationalAction → `subject_id` cru). Mostra "(Apagado)" se o registo já não existe, "(Lixo)" se em soft-delete. Cobre 11 modelos explicitamente — um modelo novo aparece com nome de classe cru.
- `getCauserNameColumnComponent()`: `causer_id === null` → "Sistema / Automático" (ações do scheduler).

## Coisas resolvidas
- ✓ **`canAccess()` adicionado**: restringido a Admin (activity log é sensível — não deve ser público).

## Coisas a rever
- Cobertura de traduções mantida manualmente — um novo model com `LogsActivity` aparece com nome de classe até alguém atualizar este ficheiro.
