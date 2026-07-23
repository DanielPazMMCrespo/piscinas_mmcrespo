# Movimentos — Instalação (`StockInstallationLogResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Consulta read-only do histórico de movimentos por instalação (grupo "Logs"). Acesso Admin+Técnico. Só página `index`, sem create/edit/view.

## Estrutura
Colunas: `created_at`, `tipo_movimento` (badge entrada/consumo), `stockInstalacao.instalacao.name`, `stockInstalacao.produto.name`, `quantity` (com unidade), `utilizador.name`. Filtros: `tipo_movimento`, instalação, produto.

## Coisas a rever
Sem exportação/CSV. Sem policy dedicada.
