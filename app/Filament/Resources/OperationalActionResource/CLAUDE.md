# Ações Operacionais (`OperationalActionResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Registo de eventos pontuais fora do ciclo diário (lavagem de filtro a meio do dia, torneira, reabastecimento de bidão, etc.) — mais leve que o `DailyRecord`, serve também para justificar anomalias detetadas pelo controlador automático nesse período. Acesso: só **Admin e Técnico** (nenhum acesso de NS). `getPages()`: `index`/`create`/`view`/`edit` — a edição re-sincroniza os efeitos colaterais (ver secção Ações).

## Estrutura de dados
- Campos comuns: `user_id` (fixo ao autor), `pool_id`, `tipo` (`OperationalAction::TIPOS`, reativo), `registado_em`, `observacoes`, `foto` opcional.
- Campo `dados` (JSON) com esquema por tipo:

| Tipo | Campos em `dados` | Side-effect no Observer |
|---|---|---|
| `lavagem_filtro` / `enxaguamento_filtro` | `duracao_min` | nenhum |
| `torneira` | `agua_modo` (5 estados) | abre/fecha `TapAlert` |
| `bomba` | `bomba_ferrada` | nenhum |
| `contador` | `contador_valor` | nenhum |
| `tanque` | `tanque_ok` | nenhum |
| `analise_pontual` | `ph`/`cloro_livre`/`cloro_total`/`temperatura` (≥1 obrigatório) | nenhum |
| `reabastecimento_bidao` | `bidao_tipo` (cloro/ph_menos/ambos), `quantidade_l` | reabastece `DosingContainer` |
| `outro` | livre (só textarea) | nenhum |

## Lógica de negócio não óbvia
- **Side-effects síncronos** via `OperationalActionObserver::created()` (não é job em fila, ao contrário do `DailyRecord`):
  - `torneira` → abre/fecha `TapAlert`, fechando com `resolution = 'acao_operacional'` (distinto de `registo_seguinte` do job do DailyRecord, para se distinguir na auditoria qual fluxo fechou o alerta).
  - `reabastecimento_bidao` → `firstOrCreate` do `DosingContainer` (pool_id, tipo), capacidade default 20000ml se novo; se `bidao_tipo === 'ambos'`, aplica a ambos (Cloro e pH-); calcula ml a partir de `quantidade_l*1000` ou usa a capacidade total se omitido.
  - Sempre invalida cache de piscina + todos os alertas + cache local do utilizador.
- Sem transação/fila: qualquer falha no observer (ex. DosingContainer inválido) propaga erro direto na submissão do formulário.
- `dadosFormatados()` no model é a fonte única de tradução do JSON `dados` para texto legível (usado na tabela e no infolist).

## Ações
- Criar (com modal de confirmação), Ver, Editar, Eliminar (bulk, admin only).
- **Editar re-sincroniza os efeitos colaterais**: ao guardar, o `OperationalActionObserver::updated()` re-corre a gestão de torneira/reabastecimento de bidão com os novos `dados`, para o `TapAlert`/`DosingContainer` não ficarem dessincronizados do registo. Seguro porque `reabastecer()` faz SET do nível (não soma) e `gerirTorneira()` reconcilia contra o alerta aberto atual.
  - Limitação conhecida: a reconciliação de torneira assume que a ação editada é a mais recente; editar uma ação antiga pode mexer num alerta aberto por uma ação posterior. Mudar `bidao_tipo` na edição não reverte o nível do tipo anterior.
- Atalhos com querystring (`?pool=X&tipo=Y`) usados a partir de `PainelPiscinasWidget` e `EsquemaPiscina`.

## Coisas resolvidas
- ✓ **`canEdit()` removido**: dead code — não havia rota edit registada, portanto nenhuma razão para ter validação de autorização.
- ✓ **`piscinasOptions()` simplificado**: removida validação redundante de NS piscinas (já bloqueado em `canAccess()`).
- ✓ **Modal de confirmação ao criar** (`CreateOperationalAction::getCreateFormAction()`): mensagem varia por tipo, avisa quando há efeito colateral (bidão/torneira).
- ✓ **Efeitos colaterais resilientes** (`OperationalActionObserver::comEfeitoResiliente()`): o registo grava sempre; decisão do Daniel de que a criação nunca pode falhar. Uma falha no reabastecimento do bidão ou na gestão da torneira é apanhada, registada em log e vira aviso suave — nunca 500 nem rollback do registo. (Optou-se por resiliência síncrona em vez de fila, para o estado atualizar de imediato no esquema.)
