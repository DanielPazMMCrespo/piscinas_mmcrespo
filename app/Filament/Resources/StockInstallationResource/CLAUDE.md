# Stock de Instalação (`StockInstallationResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Stock por instalação/produto, com `limite_minimo` configurável para alertas de stock baixo. Acesso Admin+Técnico; eliminar só Admin.

## Estrutura de dados
- `StockInstallation`: `installation_id`, `product_id`, `quantity`, `limite_minimo`. Par (`installation_id`,`product_id`) é único (validado no form). `registos()` liga corretamente por FK real `stock_installation_id` (ao contrário do StockWarehouse).
- `limite_minimo` é o campo usado por `StockBaixoWidget`, `AlertasService` e `MetricsController` (`quantity <= limite_minimo`) para stock baixo.

## Lógica de negócio não óbvia
- `StockInstallationObserver` (created/updated/deleted) só invalida caches — não tem lógica de negócio.
- **Consumo automático fora deste recurso**: `ProcessDailyRecordAfterCreate::descontarStock()` desconta stock diretamente quando um registo diário tem adições de produtos — usa upsert+lock próprios, **não chama `StockService`**, e **não lança exceção** em stock insuficiente (faz `consumo = min(pedido, disponivel)` e acumula os nomes insuficientes, aviso não bloqueante). Isto diverge do comportamento de `StockService::consumeInstallationStock` (ação manual), que lança `DomainException` e bloqueia.

## Ações
- Editar (sem restrição extra além da policy padrão `update`), "Consumo Manual" (`StockService::consumeInstallationStock`, captura `DomainException`).

## Coisas resolvidas
- ✓ **Logging automático em edições**: `EditStockInstallation` agora tem `handleRecordUpdate` + `afterSave` (igual a `EditStockWarehouse`), gerando logs automáticos de entrada/saída na edição.

## Coisas resolvidas
- ✓ **Ação "Entrada Direta"**: `StockService::addInstallationStock()` (transação+lock+log `entrada`, guard `quantity>0`) — dá entrada de stock recebido diretamente na instalação, fora do fluxo armazém→instalação. Substitui a edição manual não auditada do campo `quantity`. (Nota: `stock_installation_logs.tipo_movimento` é enum `['entrada','consumo']` sem coluna de nota/fornecedor, por isso a entrada direta e a transferência partilham o tipo `entrada`.)

## Coisas a rever
- Autorização das ações de entrada/consumo usa `can('update', $record)` genérico, diferente do padrão de policies dedicadas (`updateStock`/`transferStock`) do StockWarehouse. Funcional; deixado por não valer o risco de mexer em autorização só por consistência de estilo.
