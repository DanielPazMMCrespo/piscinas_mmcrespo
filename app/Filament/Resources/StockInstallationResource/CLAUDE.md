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

## Coisas a rever
- **Não existe ação "Reabastecer"/"Entrada" direta aqui** — a única forma de aumentar `quantity` é via transferência no armazém, ou edição manual do campo. E, ao contrário do StockWarehouse, **editar a quantidade diretamente aqui não gera log nenhum** (`EditStockInstallation` não tem `afterSave` equivalente) — inconsistência clara e potencial buraco de auditoria.
- Autorização da ação de consumo usa `can('update', $record)` genérico, diferente do padrão de policies dedicadas (`updateStock`/`transferStock`) usado no StockWarehouse.
