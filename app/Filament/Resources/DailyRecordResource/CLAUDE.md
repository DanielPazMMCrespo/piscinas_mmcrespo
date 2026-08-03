# Registo Diário (`DailyRecordResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto — este ficheiro é só sobre este recurso.

## Propósito
Livro de registo sanitário legal (CN 14/DA) — página núcleo, uso diário. Acesso:
- **Admin/Técnico**: fluxo completo, todas as piscinas.
- **Nadador-Salvador**: só analisa parâmetros da água das piscinas atribuídas (`user_pools`), condicionado à permissão `NSPermission::REGISTO_DIARIO`.
- Só **Admin** edita (`canEdit`) ou elimina — reforça o padrão append-only.

## Estrutura de dados
- Wizard dinâmico por instalação, passos condicionais: `Bombas e contadores → Tanques (se instalação tem `tanques_verificaveis`) → Lavagem filtros → Enxaguamento → Posição normal → Nadadores-salvadores → Observações`. NS só vê `[stepNS]`. Modo rápido (`?quick=1&pool=X`) só `[stepNS, stepObservacoes]`.
- Estado por piscina em array aninhado `data.pools.{pool_id}.*`, pré-semeado em `estadoInicialPiscinas()` (necessário porque `@entangle` falha se a chave não existir antes do campo dinâmico montar).
- Campos por piscina: `bomba_ferrada`, `contador_valor`, `agua_modo` (5 estados), fotos várias, `tanque_ok`, `pressao_filtro`, timers de retrolavagem, `ns_ph`/`ns_cloro_livre`/`ns_cloro_total`/`ns_temperatura`, `banhistas` (nº desde o último registo; agregado diário = soma, não média), `adicoes` (repeater de químicos com `acao_corretiva`), `observacoes`.
- Relações: `piscina`, `utilizador`, `adicoes` (RecordAddition), `fotos` (RecordPhoto), `correcoes`/`registoOriginal` (self, append-only).

## Lógica de negócio não óbvia
- **Criação assíncrona**: `CreateDailyRecord` → `DailyRecordService::createRecords()` cria um `DailyRecord` por piscina numa transação, e despacha `ProcessDailyRecordAfterCreate` (queue `daily-records`) por registo. O job faz, fora da transação HTTP: gravar fotos de análises, descontar stock da instalação (nunca fica negativo, notifica admins se insuficiente), detetar não-conformidade (`listarViolacoes()`, notifica admins), e gerir `TapAlert` (abre/fecha consoante `agua_modo`).
- **Semáforo em tempo real**: `DailyRecord::avaliarConformidade()` é a fonte única para hint/cor/borda; sugere dose via `DosageCalculatorService`.
- **Hora da colheita = hora oficial do registo**: campo `hora_colheita` (TimePicker, topo do passo NS). A data (`registado_em`) está fixa a hoje (DatePicker `disabled`); a hora é editável e pode recuar-se para colheitas mais cedo no dia. No `DailyRecordService`, `registado_em` passa a ser `data de hoje + hora_colheita` — é o timestamp usado em toda a app (dashboard, PDF, gráficos, freshness). O instante de submissão fica no `created_at`.
- **Referência da sonda no passo NS**: o placeholder mostra apenas **✓ Conforme** ou **"Não conforme - Valor X, por favor considera refazer a medição"** (X = pH/ORP/Temperatura fora da gama; pH/temp via `avaliarConformidade`, ORP via `Pool::orp_min/max`) — `sondaViolacoes()`. A leitura cruzada respeita a `hora_colheita` (`sondaParaMomento()`): momento ≤ `TOLERANCIA_SONDA_MIN` (60 min) de agora → caminho ao vivo (`sondaFresca`/`SourceSelectionService`, ciente de artefacto/online); momento retroativo → leitura de `SensorReading` mais próxima dentro de ±60 min, ou fallback neutro se não houver. Os hints por campo (`infoSonda()`) continuam a cruzar valor manual ↔ sonda desse momento: Δ pH ≥0.2 ou Δ temp ≥1.0 °C → "diverge da sonda, confirme a medição"; cloro livre fora dos limites com ORP na gama da piscina → "confirme a medição antes de corrigir". `hora_colheita` lida via `$livewire->data['hora_colheita']` (é top-level, não está sob `pools.{id}`). Leituras memoizadas por pedido (`$sondaMemo`/`$sondaMomentoMemo`).
- **Valor zero suspeito**: um `0`/`0.00` num parâmetro NS obriga a preencher `observacoes` (pode ser sonda avariada, falta de reagente).
- **Contador só avança**: valida contra a última leitura não corrigida da mesma piscina.
- **Cloro total ≥ cloro livre**: validado em três sítios distintos (form, ação corrigir, ambas variantes NS/técnico) — duplicado, não extraído.
- **Padrão append-only**: ação "corrigir" cria novo `DailyRecord` com `corrige_registo_id`, nunca altera o original; protegida por `whereDoesntHave('correcoes')` (um registo já corrigido não pode voltar a ser corrigido).
- **Lock de duplo-submit**: `Cache::lock('create_record_'.userId, 10)` + modal de confirmação com resumo antes de gravar.

## Ações
- Criar (wizard + confirmação), Ver (infolist), Corrigir (append-only, única forma de alterar um registo), Eliminar (bulk, admin only).
- **Não há ação "Editar"**: `canEdit()` devolve sempre `false` e a rota `edit` foi removida de `getPages()`. Decisão consciente — reforça append-only; a única forma de alterar dados é "Corrigir" na tabela, que cria um novo registo ligado ao original.

## Ação "Corrigir" — detalhe
- Formulário do técnico/admin cobre agora **todos** os campos relevantes: `ph`, `cloro_livre`, `cloro_total`, `temperatura`, `transparencia`, `caleira_feita`, `renovacao_agua` (antes só cobria pH/cloro e copiava os restantes silenciosamente do original). NS continua só a corrigir os seus `ns_*`.
- `user_id` da correção preserva o autor do registo original (`$record->user_id`) — antes ficava com o utilizador que fez a correção, quebrando a atribuição do registo. Quem efetivamente corrigiu fica no `causer` do activity log (`LogsActivity` no model).
- Após gravar, valida conformidade dos valores corrigidos (`DailyRecord::avaliarConformidade()`); se algum ficar fora dos limites, notificação de aviso (amarela) lista os parâmetros em causa — a correção é sempre gravada (é um valor real), só muda o tom da notificação.

## Coisas a rever (encontradas no código, não confirmadas contigo)
- `analises_fotos` está no `$fillable`/usado no job, mas não existe nenhum campo de upload correspondente no wizard atual — ou é preenchido por outra via (API/import) ou é código morto.
- `bomba_com_bolhas`, `estado_valvulas_filtro` saíram do `$fillable`/casts (nunca foram lidos nem escritos por nenhum form/PDF/comando). As colunas continuam na BD, sem migração de drop — se houver dados antigos, decidir se se arquivam antes de as largar.
- Lógica de "cloro total ≥ cloro livre" e "gestão de torneira" duplicada entre form builder, table builder e job — candidato a extrair para serviço/Rule partilhado.
