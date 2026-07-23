# Utilizadores (`UserResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Gestão de contas + convites. Acesso Admin e Gestor. Gestor só edita/convida NS (não pode tocar em Admin/Gestor/Técnico) — reforçado no servidor, não só na UI.

## Estrutura de dados
- Form: `first_name`/`last_name`/`phone`/`email`, `roles` (só visível/editável por Admin), `piscinas` (belongsToMany User↔Pool, visível para Gestor ou quando o cargo é NS), `ns_permissions` (CheckboxList, default = tudo visível — importante para registos antigos sem este campo).
- `User::podeVer($seccao)` controla visibilidade de secções do form de registo diário para NS.
- `User::wantsNotification()`: preferências por canal, mas força `true` para eventos de conformidade (`nao_conformidade`, `resumo_conformidade`, `hanna_threshold`, `hanna_overtime`) se o utilizador for Admin/Gestor — não podem desligar notificações legais.

## Fluxo de convite (`InvitationService`)
- Rejeita se já existe conta com o email, ou convite pendente não expirado.
- Token: gerado em claro (`Str::random(64)`) só para o email; **na BD só fica o hash SHA-256**.
- Validade configurável (`convite_validade_horas` em Definições, default 48h).
- `pool_ids` só gravado se o papel do convite for NS (pré-atribuição antes de a conta existir).
- Aceitação é fora do Filament (`InvitationController`, rota pública `/convite/{token}`): valida password/pin, cria o User, atribui papel, sincroniza piscinas, marca `accepted_at`.
- No form Livewire, se quem convida não for Admin, o `role` é **forçado no servidor** para `NADADOR_SALVADOR`, independente do que o cliente enviar — previne um Gestor convidar/promover-se a Admin manipulando o form.

## Ações
- "Convidar Utilizador" (modal), "Criar manualmente" (password inicial forçada + `must_change_password=true`, login seguinte obriga troca).
- "Enviar Email de Redefinição", "Forçar Pass/PIN" (com confirmação de 5s via Alpine).
- Eliminar (form/bulk): bloqueado se auto-eliminação, último Admin, ou se o utilizador tem `daily_records`/`incidents` associados.

## Coisas resolvidas
- ✓ **Validação de "não pode eliminar" consolidada**: extraído `temDadosAssociados()` method privado; reutilizado em `canDelete()`, ação singular, e ação bulk — um ponto único de verificação.

## Coisas resolvidas (cont.)
- ✓ **Reenvio e listagem de convites**: novo `UserInvitationResource` (grupo Sistema, admin/gestor) lista convites com estado (Pendente/Expirado/Aceite) e ações "Reenviar" (`InvitationService::resend()` — regenera token, estende validade, reenvia email; invalida o link antigo) e "Revogar" (apaga convite não aceite). Fecha o gap de um convite expirado só poder ser recriado.

## Coisas a rever
- ~~Sem ação "Reenviar convite" nem listagem de pendentes~~ — **resolvido** (ver acima, `UserInvitationResource`).
- ~~Mensagem de sucesso do convite tinha "Válido 48 horas." hardcoded~~ — **corrigido**: agora usa `$invitation->expires_at->diffForHumans()`, refletindo a validade real configurada em Definições do Sistema.
- `canAccess()`/`canCreate()` usam `?->` (null-safe); `PoolResource`/`InstallationResource` não usam — inconsistência de estilo. (Menor; `UserRole::LABELS` novo pode servir para dedupe futuro dos mapas de labels de cargo duplicados em `UserResource`/`ListUsers`.)
