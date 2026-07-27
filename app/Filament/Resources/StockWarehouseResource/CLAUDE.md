# Stock de Armazém (`StockWarehouseResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Stock centralizado no armazém municipal (um registo por produto). Acesso Admin+Técnico; eliminar só Admin.

## Estrutura de dados
- `StockWarehouse`: `product_id`, `quantity` (decimal:3). Relação `registos()` (HasMany `StockWarehouseLog`) e `StockWarehouseLog::armazem()` ligam por `product_id`, **não** por uma FK dedicada — funciona porque há relação 1:1 produto↔armazém, mas é frágil/confuso de ler.
- `StockWarehouseLog`: sem timestamps automáticos, `tipo_movimento` (`entrada`/`saida`), `fornecedor` (texto livre usado como observações).
- **Não existe `limite_minimo` no armazém central** — só existe ao nível da instalação. Alerta de stock baixo (`StockBaixoWidget`) só olha para `stock_installations`.

## Lógica de negócio não óbvia
- `EditStockWarehouse::handleRecordUpdate`: `DB::transaction`+`lockForUpdate`, clamp de `quantity` a zero se negativo.
- `afterSave()`: calcula o delta entre quantidade nova/anterior e cria **automaticamente** um log (`entrada`/`saida` consoante o sinal) com `fornecedor = 'Edição direta (ajuste de inventário)'` — qualquer edição manual do campo quantidade gera log.
- Ação "Entrada" e ação "Transferir p/ Instalação" passam por `StockService` (`addWarehouseStock`, `transferToInstallation`); esta última captura `DomainException` (stock insuficiente) e mostra notificação em vez de rebentar.

## Ações
- Editar (admin only), "Entrada" (soma stock, log entrada), "Transferir p/ Instalação" (debita armazém, credita/cria instalação, dois logs), Eliminar (bulk, admin only).

## Coisas a rever
- `StockWarehouse::registos()`/`StockWarehouseLog::armazem()` ligam por `product_id` em vez de FK — assume armazém único por produto; se algum dia houver mais de um armazém isto parte.
- Log de "Entrada" via ação (StockService) e via edição direta (`afterSave`) fazem o mesmo efeito por dois caminhos de código completamente diferentes, sem reutilizar `StockService` na edição.

## StockService (partilhado com StockInstallationResource)
`app/Services/StockService.php` — `addWarehouseStock()`, `transferToInstallation()`, `consumeInstallationStock()`, todos com `DB::transaction`+`lockForUpdate`. Só usado por estas duas Resources (as ações "Entrada"/"Transferir"/"Consumo Manual"). **Não** é chamado por `ProcessDailyRecordAfterCreate::descontarStock()`, que reimplementa a lógica de consumo à parte (ver StockInstallationResource/CLAUDE.md).

- `transferToInstallation()` faz `firstOrCreate` + um `lockForUpdate()->findOrFail()` **separado** sobre o registo recém-criado — dois round-trips à BD onde um único lock inicial bastaria (redundante, não incorreto).
- ~~Sem validação de `$quantity > 0` dentro do próprio serviço~~ — **resolvido**: `addWarehouseStock`, `transferToInstallation` e `addInstallationStock` lançam `DomainException` para quantidade não-positiva, independentes das regras do formulário.
