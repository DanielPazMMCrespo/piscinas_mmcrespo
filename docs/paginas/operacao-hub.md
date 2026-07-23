# Registo Diário / Hub (`app/Filament/Pages/OperacaoHub.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral.

## Propósito
Página "hub" que substitui dois itens de menu (Registos Diários, Incidentes) por um ecrã de escolha único — reduz ruído na sidebar (`DailyRecordResource`/`IncidentResource` têm `shouldRegisterNavigation() = false`, só este hub aparece). Acesso: qualquer utilizador autenticado.

## Lógica
- `registoDiarioAction()` abre um modal (`filament.pages.operacao-hub-modal`) sem submit/cancel próprios — o conteúdo do modal gere a navegação.
- `getDailyRecordUrl()`/`getIncidentUrl()` só devolvem URL se o utilizador tiver `canViewAny()` do Resource respetivo, senão `null` (a view esconde os botões correspondentes).

## Coisas a rever
- Nada pendente. Os atributos de navegação mortos (`$navigationLabel`/`$navigationIcon`/`$navigationSort`) já não existem no ficheiro — só `shouldRegisterNavigation() => false`, `$title` e `$view`.
