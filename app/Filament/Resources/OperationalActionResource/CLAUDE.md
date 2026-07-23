# Ações Operacionais (`OperationalActionResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Registo de eventos pontuais fora do ciclo diário (lavagem de filtro a meio do dia, torneira, reabastecimento de bidão, etc.) — mais leve que o `DailyRecord`, serve também para justificar anomalias detetadas pelo controlador automático nesse período. Acesso: só **Admin e Técnico** (nenhum acesso de NS). Não existe página de edição (`getPages()` só tem `index`/`create`/`view`).

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
- Criar (**sem confirmação em modal**, ao contrário de DailyRecord/Incident), Ver, Eliminar (bulk, admin only). Sem editar/corrigir.
- Atalhos com querystring (`?pool=X&tipo=Y`) usados a partir de `PainelPiscinasWidget` e `EsquemaPiscina`.

## Coisas a rever
- `canEdit($record)` está definido no Resource (Admin) mas **não há página `edit` registada** — dead code de autorização, ou falta implementar a rota.
- `piscinasOptions()` reaplica a restrição de piscinas por NS mesmo que `canAccess()` já bloqueie NS de todo o Resource — código morto/inofensivo, mas confuso.
- Falta confirmação em modal ao criar, inconsistente com DailyRecord/Incident — a ação pode ter efeitos colaterais relevantes (reabastecimento de bidão, alerta de torneira).
- Efeitos colaterais síncronos (sem fila) tornam a criação mais lenta/menos resiliente que o fluxo assíncrono do DailyRecord; sem tratamento de erro explícito no Observer.
