# Encerramentos (`EncerramentoPiscinas`)

**Rota:** `/admin/encerramentos` · **Grupo:** Operação · **Acesso:** admin, gestor, técnico

Encerrar e reabrir piscinas. Vive em Operação e não na `PoolResource` (grupo Estrutura, território
admin) porque quem fecha a época balnear é o gestor ou o técnico.

## O modelo, em duas frases

`pools.active` é **estrutural**: a piscina existe ou não na operação. O encerramento (`pool_closures`)
é **temporal**: um período datado ao dia, com `fim` inclusivo (`null` = em aberto). São eixos
independentes; `Pool::$estado_operacional` deriva `ativa | encerrada | desativada`.

Encerrar **não apaga nada**. Os registos do período mantêm-se e o livro sanitário passa a imprimir a
justificação dos dias sem registos. É essa a razão de haver uma tabela e não um booleano: um
encerramento apagado ou sem datas tornaria o passado inauditável.

## `agua_em_tratamento` — os dois regimes

| Regime | Quando | Registos diários | Alertas de violação legal |
|---|---|---|---|
| **Desligado** (piscina parada/vazia) | Fim de época balnear | **Bloqueados** (Select + validação no servidor) | Não |
| **Ligado** (fechada ao público, química mantida) | Manutenção, obra | Permitidos, nunca obrigatórios | **Sim** — água tratada fora dos limites é um problema real |

Em ambos os regimes desaparecem os alertas de falta de registo e de torneira aberta, e a piscina sai
dos denominadores de conformidade.

## Ações

- **Encerrar** (por piscina): motivo, data de início (pode ser retroativa), data de fim opcional,
  toggle `agua_em_tratamento`, observações.
- **Reabrir**: pede o primeiro dia aberto. Grava `fim = reabertura - 1 dia`. Se isso cair antes do
  início — desfazer no mesmo dia — o encerramento é **apagado**, porque nunca produziu efeito.
- **Encerrar selecionadas** (lote): o caso real do fim de época. Mesmo motivo e período para várias
  piscinas; erros são reportados por piscina, as restantes passam.
- **Histórico** (modal por piscina) e `PoolClosureResource` em Logs para o histórico completo
  filtrável. Criar está desligado aí de propósito: encerrar passa sempre pelo `PoolClosureService`,
  que valida sobreposições.

Apagar um encerramento é **só admin** — é ele que justifica os dias sem registos perante a DGS.

## Onde é que o estado se faz sentir

**Fonte única:** `PoolClosureService`. Num único dia, `Pool::estaEncerradaEm($data)`. Num intervalo
(heatmap, gráficos, PDF), `PoolClosureService::mapa()` — **uma** query para toda a janela. Nunca
chamar `estaEncerradaEm()` dentro de um ciclo por dia, e nunca recalcular o estado noutro sítio: uma
segunda implementação divergiria do livro sanitário.

- **Dashboard** — o cartão fica na grelha com badge e faixa de motivo/período (o técnico tem de ver
  que a piscina existe e está fechada), valores neutros, "Reabrir" no lugar de "Registo Rápido".
  Percentagens só sobre piscinas abertas.
- **Alertas / Kanban** — sem falta-de-registo, fora-de-limites, temperatura nem torneira. Um único
  cartão neutro agregado. Encerrar resolve as torneiras abertas e os cartões pendentes da piscina.
- **Registo Diário** — piscinas paradas saem do Select e são recusadas no servidor, **contra a data do
  registo** e não contra hoje (o formulário aceita datas retroativas).
- **Incidentes** — manuais sempre permitidos (vandalismo, fuga). O auto-incidente por 3× violação
  salta piscinas encerradas. Badge retroativo "piscina encerrada" à data da ocorrência.
- **Ações Operacionais** — sempre permitidas, marcadas. É no encerramento que se faz drenagem, obra
  e lavagem de filtros.
- **Heatmap** — dias encerrados têm estado e cor próprios, distintos de "sem dados".
- **Relatório PDF** — declaração no topo da secção **e** linha inline na tabela, em ordem
  cronológica; dias encerrados contados no resumo.
- **Comandos agendados** — `tendencias:verificar`, resumo de turno e comparação semanal usam
  `Pool::operacionais()`.
- **Esquema** e **Relatório PDF** continuam a **listar** piscinas encerradas de propósito: o circuito
  consulta-se durante a obra, e é sobre as encerradas que se emite a declaração.
- **Conta do Nadador-Salvador** — bloqueada por completo (`BlockClosedPoolAccess` middleware +
  `PoolAccessRequestService::estaBloqueado()`) quando **todas** as piscinas atribuídas ao NS estão
  encerradas. Redirige para `/piscinas-encerradas`: mostra piscina(s), motivo e período, e permite
  pedir acesso ao administrador (`PoolAccessRequestResource`, grupo Operação, admin only). Uma
  aprovação só cobre o encerramento vigente no momento da decisão — se a piscina reabrir e voltar a
  encerrar depois, é um `PoolClosure` novo e o NS tem de pedir outra vez.

## Notas de manutenção

- `PainelPiscinasWidget::CACHE_SHAPE_VERSION` subiu para 4 (nova chave `encerramento` no payload).
  Mudar a forma outra vez obriga a incrementar de novo.
- O mapa de encerramentos vigentes é cacheado sob uma **chave fixa**
  (`CacheService::CLOSURES_KEY`), para o `Cache::forget()` funcionar em todos os drivers — a
  invalidação por wildcard não funciona com o driver `file` usado em dev.
- Piscinas com `active = false` **sem** encerramento datado são um estado legado: a `PoolResource`
  mostra um tooltip a encaminhar para o fecho temporário. Não houve backfill automático porque as
  datas desses casos não são conhecidas.
- As datas nas assinaturas usam `CarbonInterface`, não `Illuminate\Support\Carbon`: há chamadores
  no projeto que passam `Carbon\Carbon` e o tipo estrito rebentava com `TypeError`.

## A rever

- O gráfico `CloroPhChartWidget` não sombreia o intervalo encerrado. A linha já quebra nos dias sem
  dados (`spanGaps = false`), pelo que o período lê-se como interrupção, mas uma banda de fundo
  (plugin Chart.js) seria mais explícita. Ficou de fora por ser polimento sobre informação que o
  heatmap já dá de forma inequívoca.
- O alerta de stock baixo não desce a neutro quando todas as piscinas de uma instalação estão
  encerradas. Marginal — o stock de armazém continua a fazer sentido monitorizar fora de época.
