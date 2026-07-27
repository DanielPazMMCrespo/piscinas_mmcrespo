# Bidões de Dosagem (`DosingContainerResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Gestão dos bidões físicos de reagente (cloro/pH-) ligados a cada piscina. O nível desce automaticamente pela dosagem reportada pelo controlador Hanna (`HannaCloudSync::descontarBidao()`); aqui só se configura capacidade e regista reabastecimento/ajuste manual. Acesso Admin+Técnico.

## Estrutura de dados
- `pool_id`, `tipo` (`cloro`/`ph_menos`), `capacidade_ml`, `restante_ml`, `alerta_percent` (default 20), `reabastecido_em`/`reabastecido_por`, `alerta_notificado_em`.
- No formulário os valores são apresentados em **litros** mas guardados em **ml** (conversão via `formatStateUsing`/`dehydrateStateUsing`).
- `logs()` HasMany `DosingContainerLog` (`tipo_movimento`: consumo/reabastecimento/ajuste/consumo_sonda).

## Lógica de negócio não óbvia (toda no Model, não num Service)
- `percentagem()`/`nivel()`: `'critico'` se `% < alerta_percent`, `'aviso'` se `% < alerta_percent*2`, senão `'ok'`.
- `consumir()`: transação+lock, nunca vai a negativo, cria log `consumo` com quantidade negativa.
- `reabastecer()`: define o nível **absoluto** (não soma), limpa `alerta_notificado_em` (permite nova notificação no próximo episódio de nível baixo), cria log `reabastecimento`. Depois, **fora da transação** (deliberado, para não bloquear a BD), chama `recalcularConsumoAposReabastecimento()` — refaz retroativamente o consumo que ocorreu entre o reabastecimento e a última sincronização Hanna, subtraindo do nível recém-reposto; falha silenciosamente (log warning) se faltarem credenciais Hanna.
- Notificação de nível baixo (`DosingContainerLowAlert`): fonte única `DosingContainer::notificarSeBaixo()` — dispara quando `estaBaixo() && alerta_notificado_em === null` (dedupe por episódio, até haver reabastecimento). Chamada tanto pelo `HannaCloudSync::descontarBidao()` como pela ação "Ajustar nível".

## Ações
- "Reabastecer" (pede nível em L após reabastecer, chama `$record->reabastecer()`), "Ajustar nível" (pede nível real medido, calcula delta e faz update+log **diretamente na página**, não no Model), Editar (config, sem log).

## Coisas resolvidas
- ✓ **Race condition em "Ajustar nível" corrigida a sério**: o `lockForUpdate()` estava fora de transação (lock libertado de imediato, inútil). Agora o read+update+log correm dentro de `DB::transaction()`.
- ✓ **Notificação em ajuste manual**: descer o nível abaixo do limiar via "Ajustar" passa a alertar como o sync do controlador (`notificarSeBaixo()`) — já não depende da sonda estar ligada.

## Coisas a rever
- Editar `capacidade_ml`/`alerta_percent` não fica auditado (só reabastecer/ajustar/consumir geram log). Aceite: é escape-hatch de admin, uso raro.
- **`DosingContainerService` recusado deliberadamente** (não é dívida): a lógica transacional já está encapsulada nos métodos do Model (`consumir`/`reabastecer`/`notificarSeBaixo`), que é onde pertence. Um serviço só "por simetria" com o `StockService` seria abstração prematura sem ganho.
