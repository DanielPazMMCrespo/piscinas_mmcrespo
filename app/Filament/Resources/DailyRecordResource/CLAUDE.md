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
- Campos por piscina: `bomba_ferrada`, `contador_valor`, `agua_modo` (5 estados), fotos várias, `tanque_ok`, `pressao_filtro`, timers de retrolavagem, `ns_ph`/`ns_cloro_livre`/`ns_cloro_total`/`ns_temperatura`, `adicoes` (repeater de químicos com `acao_corretiva`), `observacoes`.
- Relações: `piscina`, `utilizador`, `adicoes` (RecordAddition), `fotos` (RecordPhoto), `correcoes`/`registoOriginal` (self, append-only).

## Lógica de negócio não óbvia
- **Criação assíncrona**: `CreateDailyRecord` → `DailyRecordService::createRecords()` cria um `DailyRecord` por piscina numa transação, e despacha `ProcessDailyRecordAfterCreate` (queue `daily-records`) por registo. O job faz, fora da transação HTTP: gravar fotos de análises, descontar stock da instalação (nunca fica negativo, notifica admins se insuficiente), detetar não-conformidade (`listarViolacoes()`, notifica admins), e gerir `TapAlert` (abre/fecha consoante `agua_modo`).
- **Semáforo em tempo real**: `DailyRecord::avaliarConformidade()` é a fonte única para hint/cor/borda; sugere dose via `DosageCalculatorService`.
- **Valor zero suspeito**: um `0`/`0.00` num parâmetro NS obriga a preencher `observacoes` (pode ser sonda avariada, falta de reagente).
- **Contador só avança**: valida contra a última leitura não corrigida da mesma piscina.
- **Cloro total ≥ cloro livre**: validado em três sítios distintos (form, ação corrigir, ambas variantes NS/técnico) — duplicado, não extraído.
- **Padrão append-only**: ação "corrigir" cria novo `DailyRecord` com `corrige_registo_id`, nunca altera o original; protegida por `whereDoesntHave('correcoes')` (um registo já corrigido não pode voltar a ser corrigido).
- **Lock de duplo-submit**: `Cache::lock('create_record_'.userId, 10)` + modal de confirmação com resumo antes de gravar.

## Ações
- Criar (wizard + confirmação), Ver (infolist, com botão "Editar" que leva à página de edição), Editar (admin only), Corrigir (append-only), Eliminar (bulk, admin only).

## Coisas a rever (encontradas no código, não confirmadas contigo)
- **`EditDailyRecord` não edita de facto**: `DailyRecordFormBuilder::form()` devolve só um `Placeholder` ("Edição não suportada neste Wizard") sempre que a operação não é `create`. A única forma real de corrigir dados é a ação "corrigir" na tabela — se alguém for à página de edição à espera de mexer campo a campo, não vai conseguir.
- `analises_fotos` está no `$fillable`/usado no job, mas não existe nenhum campo de upload correspondente no wizard atual — ou é preenchido por outra via (API/import) ou é código morto.
- `bomba_com_bolhas`, `estado_valvulas_filtro`, `caleira_feita`, `renovacao_agua`, `transparencia` estão no `$fillable`/casts mas sem campo no wizard atual (alguns aparecem só no infolist "Técnico").
- Lógica de "cloro total ≥ cloro livre" e "gestão de torneira" duplicada entre form builder, table builder e job — candidato a extrair para serviço/Rule partilhado.
