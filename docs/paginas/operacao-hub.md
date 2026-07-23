# Registo Diário / Hub (`app/Filament/Pages/OperacaoHub.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral.

## Propósito
Página "hub" que substitui dois itens de menu (Registos Diários, Incidentes) por um ecrã de escolha único — reduz ruído na sidebar (`DailyRecordResource`/`IncidentResource` têm `shouldRegisterNavigation() = false`, só este hub aparece). Acesso: qualquer utilizador autenticado.

## Lógica
- `registoDiarioAction()` abre um modal (`filament.pages.operacao-hub-modal`) sem submit/cancel próprios — o conteúdo do modal gere a navegação.
- `getDailyRecordUrl()`/`getIncidentUrl()` só devolvem URL se o utilizador tiver `canViewAny()` do Resource respetivo, senão `null` (a view esconde os botões correspondentes).

## Coisas a rever
- `shouldRegisterNavigation() => false` está combinado com `$navigationLabel`/`$navigationIcon`/`$navigationSort` definidos — esses atributos ficam mortos porque a página nunca aparece na sidebar (parece copiado de um padrão de Page normal e nunca limpo).
