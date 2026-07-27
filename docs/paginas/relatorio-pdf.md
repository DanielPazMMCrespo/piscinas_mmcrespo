# Relatório PDF / CN 14/DA (`app/Filament/Pages/RelatorioPdf.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Acesso: Admin ou Técnico. Uso pontual, não diário — serve auditorias DGS externas e arquivo interno mensal (o `relatorio:mensal-automatico` já gera automaticamente todo dia 1).

## Propósito
Gera o Livro de Registo Sanitário oficial em PDF (`barryvdh/laravel-dompdf`) + exportação CSV plana.

## Form
- Instalação → Piscina (dependente, com "Todas"). Data início/fim (fim não pode ser futura, fim ≥ início).
- Personalização: aviso quando as opções desviam do modelo regulamentar "completo" (13 colunas + 6 secções). `registo_modo`/`controlador_modo` separados (todos/média diária) para manual vs. controlador. CheckboxList de 17 colunas e 6 secções visíveis.

## Lógica não óbvia (`exportar()`)
- `ini_set('memory_limit','1024M')` + `set_time_limit(240)` — gerar o PDF é pesado.
- **Limite rígido de 7 dias** quando `controlador_modo === 'todos'` ("Prevenção de Erro 500", comentário explícito no código) — ajusta silenciosamente a data fim e aborta com aviso, obrigando a exportar de novo.
- Numeração "Página X de Y" via `$canvas->page_text(...)` (o script PHP inline do dompdf está desativado por segurança).
- Regista `activity('relatorio')` para PDF e CSV (auditoria de quem exportou).

## `construirSeccoes()` (estático, reutilizado por `GerarRelatorioMensalCommand`)
- Modo "média diária": cria `DailyRecord` **sintéticos** (nunca persistidos) com médias dos campos. Regras específicas nada óbvias:
  - `bombaFerrada`/`tanqueOk` só ficam `true` se **nenhum** registo do dia tiver `false` (unanimidade do dia).
  - `renovacaoAgua` é `true` se houve `renovacao_agua=true` OU modo `on_com_agua` OU (`auto_com_agua` E houve lavagem de filtro nesse dia).
- Para o controlador em "média diária", deteta 3 categorias de "dia com motivo de exclusão": (1) janelas de artefacto conhecidas (`LeituraArtefactoService`, baseadas em ações operacionais, sem limiares numéricos); (2) deteção própria de anomalia **dentro deste ficheiro** com dois conjuntos de limiares hardcoded diferentes para propósitos diferentes — `pH<6 OU ORP<400 OU ORP>900` para detetar que existe uma anomalia no dia (linhas ~561-568), e `(pH<6 OU pH>8) E (ORP<600 OU ORP>870)` como heurística para "adivinhar" que a causa foi lavagem de filtro (linhas ~585-595 e ~667-671, duplicada nesses dois sítios); (3) qualquer dia dentro de uma janela "Bomba parada" é sempre justificado.

## `exportarCsv()`
Export "flat" sem agregação diária, sem o limite de 7 dias (mais leve, sem gráficos). Escreve BOM UTF-8 explícito para o Excel Windows não corromper acentos.

## Coisas a rever
- ~~Limiares pH/ORP hardcoded e regra de "lavagem de filtro" duplicada~~ — **resolvido**: todos os limiares vivem em `WaterQualityThresholds` (`ANOMALY_*` para deteção de anomalia, `FILTER_WASH_*` para a heurística de lavagem — dois conjuntos legítimos, não duplicação). A regra de lavagem é agora o método único `cumpresRegraLavagemFiltro($ph, $orp)`. O `where` de ORP no query builder é só um pré-filtro SQL grosseiro que usa as mesmas constantes, sem risco de divergência.
