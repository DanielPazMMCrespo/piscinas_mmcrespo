# Produtos (`ProductResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Catálogo mestre de produtos químicos. Acesso Admin+Técnico.

## Estrutura de dados
- Campos: `name`, `unidade` (L/kg/un), `categoria` (combo dinâmico: lista categorias já existentes na BD + opção "Outro" → `categoria_custom`), `concentracao_cl` (%, usado na calculadora de dosagem), `active`.
- Relações: `stockArmazem()` (HasOne StockWarehouse), `stockInstalacoes()` (HasMany StockInstallation).

## Lógica de negócio não óbvia
- `CreateProduct`/`EditProduct` fazem o mapeamento entre o valor real gravado em `categoria` e a UI de combo+texto livre: ao editar, se a categoria gravada não estiver na lista fixa, trata como "outro" e pré-preenche `categoria_custom`.

## Ações
Ver, Editar, Eliminar (bulk).

## Coisas a rever
Nada de relevante encontrado — resource simples e coerente. `canDelete`/`canDeleteAny` não são sobrepostos no Resource (cai inteiramente na `ProductPolicy`, não revista aqui).
