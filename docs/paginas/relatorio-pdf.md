# Relatório PDF / CN 14/DA (`app/Filament/Pages/RelatorioPdf.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Acesso: Admin ou Técnico. Uso pontual, não diário — serve auditorias DGS externas e arquivo interno mensal (o `relatorio:mensal-automatico` já gera automaticamente todo dia 1).

## Propósito
Gera o Livro de Registo Sanitário oficial em PDF (`barryvdh/laravel-dompdf`) + exportação CSV plana.

## Form
- Instalação → Piscina (dependente, com "Todas"). Data início/fim (fim não pode ser futura, fim ≥ início).
- Personalização: aviso quando as opções desviam do modelo regulamentar "completo" (18 colunas + 8 secções). `registo_modo`/`controlador_modo` separados (todos/média diária) para manual vs. controlador. CheckboxList de 18 colunas (incluindo `orp` para Redox da sonda) e 8 secções visíveis.
- **Observações Gerais e Fotos**: campo de texto livre para anotações/justificações técnicas e upload de fotografias de suporte (até 6 imagens, máx. 5MB cada, JPG/PNG/WebP). Inclui a ação "Inserir Justificação da Sonda (OMS / DIN 19643)" que analisa as leituras contínuas do período (ORP médio/mínimo/máximo, total de leituras 24h) e gera uma justificação técnica sólida comprovando desinfeção contínua (ORP ≥ 650–700 mV) perante eventuais quebras matinais pontuais de cloro manual decorrentes de esgotamento noturno de doseadores.
- No PDF (`pdf.livro-sanitario`), estas observações e fotos surgem no **topo do relatório** (primeira coisa a ser lida após o cabeçalho oficial), com um quadro de destaque sanitário, quebra de linha tratada e grelha de 3 colunas de fotos com legenda e proteção anti-quebra de página (`page-break-inside: avoid`). As imagens são convertidas em Data URIs base64 em `processarFotosObservacoes()` para garantir renderização fiável pelo Dompdf.
- **Conformidade de Cloro por ORP**: na tabela do controlador Hanna, a coluna `Cloro Conf. (ORP)` avalia a eficácia germicida contínua (ORP entre 650 e 850 mV segundo norma OMS / DIN 19643), lado a lado com `pH Conforme`. Na coluna `Cl. Livre Manual`, caso exista medição manual no dia/hora, é apresentada a respetiva conformidade (`✓`/`✗`), permitindo evidenciar que mesmo em quebras matinais a desinfeção permanente foi assegurada pela sonda.

## Lógica não óbvia (`exportar()`)
- `ini_set('memory_limit','1024M')` + `set_time_limit(240)` — gerar o PDF é pesado.
- **Limite de 31 dias** quando `controlador_modo === 'todos'` (`MAX_DIAS_CONTROLADOR_TODOS = 31`) — permite extrair um mês civil completo detalhado; caso exceda 31 dias, ajusta a data fim e notifica com aviso.
- Numeração "Página X de Y" via `$canvas->page_text(...)` (o script PHP inline do dompdf está desativado por segurança).
- Regista `activity('relatorio')` para PDF e CSV (auditoria de quem exportou).

## `construirSeccoes()` (estático, reutilizado por `GerarRelatorioMensalCommand`)
- **Análise rápida (`OperationalAction::TIPO_ANALISE_PONTUAL`) conta tanto quanto um registo diário** (decisão do Daniel): entra na mesma tabela `registos`, indistinguível de um `DailyRecord` normal — sem coluna/rótulo de origem. `registoSinteticoDeAnalise()` converte cada análise pontual num `DailyRecord` sintético (nunca persistido, `ph`/`cloro_livre`/`cloro_total`/`temperatura` vindos de `dados`) reutilizando `phConforme()`/`cloroLivreConforme()`/`cloroCombinadoConforme()`/`temperaturaConforme()` sem duplicar a lógica de conformidade legal. Consequência: participa também no modo "média diária" (entra na agregação do dia) e no matching de "registo manual mais próximo" da secção do controlador. A ação deixa de aparecer na tabela "Ações Operacionais" (filtrada de lá para não duplicar). **Limitação conhecida**: `exportarCsv()` continua a ler só `DailyRecord` diretamente — análise rápida não entra no CSV.
- Modo "média diária": cria `DailyRecord` **sintéticos** (nunca persistidos) com médias dos campos. Regras específicas nada óbvias:
  - `bombaFerrada`/`tanqueOk` só ficam `true` se **nenhum** registo do dia tiver `false` (unanimidade do dia).
  - `renovacaoAgua` é `true` se houve `renovacao_agua=true` OU modo `on_com_agua` OU (`auto_com_agua` E houve lavagem de filtro nesse dia).
- Para o controlador em "média diária", deteta 3 categorias de "dia com motivo de exclusão": (1) janelas de artefacto conhecidas (`LeituraArtefactoService`, baseadas em ações operacionais, sem limiares numéricos); (2) deteção própria de anomalia **dentro deste ficheiro** com dois conjuntos de limiares hardcoded diferentes para propósitos diferentes — `pH<6 OU ORP<400 OU ORP>900` para detetar que existe uma anomalia no dia (linhas ~561-568), e `(pH<6 OU pH>8) E (ORP<600 OU ORP>870)` como heurística para "adivinhar" que a causa foi lavagem de filtro (linhas ~585-595 e ~667-671, duplicada nesses dois sítios); (3) qualquer dia dentro de uma janela "Bomba parada" é sempre justificado.

## `exportarCsv()`
Export "flat" sem agregação diária, sem o limite de 7 dias (mais leve, sem gráficos). Escreve BOM UTF-8 explícito para o Excel Windows não corromper acentos.

## Coisas a rever
- ~~Limiares pH/ORP hardcoded e regra de "lavagem de filtro" duplicada~~ — **resolvido**: todos os limiares vivem em `WaterQualityThresholds` (`ANOMALY_*` para deteção de anomalia, `FILTER_WASH_*` para a heurística de lavagem — dois conjuntos legítimos, não duplicação). A regra de lavagem é agora o método único `cumpresRegraLavagemFiltro($ph, $orp)`. O `where` de ORP no query builder é só um pré-filtro SQL grosseiro que usa as mesmas constantes, sem risco de divergência.
