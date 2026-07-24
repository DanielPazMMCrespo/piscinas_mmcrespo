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
| `analise_pontual` | `ph`/`cloro_livre`/`cloro_total`/`orp`/`temperatura` (≥1 obrigatório) + `orp_da_sonda` (flag interna) | nenhum |
| `reabastecimento_bidao` | `bidao_tipo` (cloro/ph_menos/ambos), `quantidade_l` | reabastece `DosingContainer` |
| `outro` | livre (só textarea) | nenhum |

## Lógica de negócio não óbvia
- **Side-effects síncronos** via `OperationalActionObserver::created()` (não é job em fila, ao contrário do `DailyRecord`):
  - `torneira` → abre/fecha `TapAlert`, fechando com `resolution = 'acao_operacional'` (distinto de `registo_seguinte` do job do DailyRecord, para se distinguir na auditoria qual fluxo fechou o alerta).
  - `reabastecimento_bidao` → `firstOrCreate` do `DosingContainer` (pool_id, tipo), capacidade default 20000ml se novo; se `bidao_tipo === 'ambos'`, aplica a ambos (Cloro e pH-); calcula ml a partir de `quantidade_l*1000` ou usa a capacidade total se omitido.
  - Sempre invalida cache de piscina + todos os alertas + cache local do utilizador.
- Sem transação/fila: qualquer falha no observer (ex. DosingContainer inválido) propaga erro direto na submissão do formulário.
- `dadosFormatados()` no model é a fonte única de tradução do JSON `dados` para texto legível (usado na tabela e no infolist).
- **`analise_pontual.orp` é preenchido automaticamente pela sonda** (decisão do Daniel: ORP não tem método de medição manual de campo, ao contrário de pH/cloro): `OperationalActionResource::preencherOrpDaSonda()` corre em `afterStateUpdated` de `pool_id`/`tipo`/`registado_em` (todos `->live()`) e procura a `SensorReading` mais próxima da hora de colheita dentro da janela `sensor_fresco_minutos` (`AppSetting`, padrão 240 min). Encontrando, marca `dados.orp_da_sonda=true` e o campo fica `readOnly()`. Sem leitura na janela (sonda offline/piscina sem sonda), o campo destranca para input manual como fallback — e se o valor lá estava era um auto-preenchido de uma seleção anterior, é limpo para não ficar a passar por manual.

## Ações
- Criar (com modal de confirmação), Ver, Editar, Eliminar (bulk, admin only).
- **Editar re-sincroniza os efeitos colaterais, sob dois guards** (`OperationalActionObserver::updated()`): só re-corre torneira/bidão se (1) `dados` mudou — editar só `observacoes`/foto não replica efeitos — **e** (2) a ação é a mais recente do seu tipo para a piscina (`ehAcaoMaisRecente()`) — editar uma ação já substituída não reescreve o estado atual. Sem estes guards, editar a nota de uma ação de torneira antiga reabria um `TapAlert` já resolvido, e editar a nota de um reabastecimento repunha o nível + criava log duplicado + chamava a API Hanna (apanhado em code-review).
  - Limitação remanescente: mudar `bidao_tipo` ao editar não reverte o nível do tipo anterior (raro; deixado por decidir).
- Atalhos com querystring (`?pool=X&tipo=Y`) usados a partir de `PainelPiscinasWidget` e `EsquemaPiscina`.

## Coisas resolvidas
- ✓ **`canEdit()` removido**: dead code — não havia rota edit registada, portanto nenhuma razão para ter validação de autorização.
- ✓ **`piscinasOptions()` simplificado**: removida validação redundante de NS piscinas (já bloqueado em `canAccess()`).
- ✓ **Modal de confirmação ao criar** (`CreateOperationalAction::getCreateFormAction()`): mensagem varia por tipo, avisa quando há efeito colateral (bidão/torneira).
- ✓ **Efeitos colaterais resilientes** (`OperationalActionObserver::comEfeitoResiliente()`): o registo grava sempre; decisão do Daniel de que a criação nunca pode falhar. Uma falha no reabastecimento do bidão ou na gestão da torneira é apanhada, registada em log e vira aviso suave — nunca 500 nem rollback do registo. (Optou-se por resiliência síncrona em vez de fila, para o estado atualizar de imediato no esquema.)
