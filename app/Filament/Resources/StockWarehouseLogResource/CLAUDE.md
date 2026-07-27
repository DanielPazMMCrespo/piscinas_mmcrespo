# Movimentos — Armazém (`StockWarehouseLogResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Consulta read-only do histórico de movimentos do armazém central (grupo de navegação "Logs", separado de "Stock"). Acesso Admin+Técnico. Só tem página `index` — sem create/edit/view, sem botão de criar.

## Estrutura
Colunas: `created_at`, `tipo_movimento` (badge entrada=success/saida=warning), `produto.name`, `quantity` (com unidade), `fornecedor`, `utilizador.name`. Filtros: `tipo_movimento`, produto. Eager-load de `produto`/`utilizador`, ordenado `created_at desc`.

## Coisas a rever
- Sem exportação/CSV.
- Sem policy dedicada — autorização é só `canAccess()` estático.
- Como não há FK direta armazém↔log (ver `StockWarehouseResource/CLAUDE.md`), o log é indistinguível de "log por produto" — assume armazém único.
