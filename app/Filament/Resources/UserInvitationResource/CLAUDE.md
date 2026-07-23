# Convites (`UserInvitationResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Listagem e gestão dos convites de utilizador (grupo Sistema). Acesso Admin+Gestor. Complementa o `UserResource`, onde os convites nascem — aqui veem-se e gerem-se os que estão por aceitar. Só index (sem criar/editar/ver).

## Estrutura
- Colunas: email, cargo (badge via `UserRole::LABELS`), estado (Pendente/Expirado/Aceite, cor warning/danger/success calculada de `isAccepted()`/`isExpired()`), expira, convidado por, enviado.
- Filtro "Só pendentes" (`accepted_at` null + `expires_at` futuro).

## Ações
- **Reenviar** (visível se não aceite): `InvitationService::resend()` — regenera o token (o link antigo deixa de funcionar), estende a validade pela config `convite_validade_horas`, reenvia `UserInvitationMail`. Confirmação no modal.
- **Revogar** (`DeleteAction`, visível se não aceite): apaga o convite.

## Notas
- O token na BD é só o hash SHA-256; o token em claro só existe no email (ver `InvitationService`). Reenviar gera um novo par.
- `UserInvitationMail` é `ShouldQueue` — nos testes usa-se `Mail::assertQueued`, não `assertSent`.

## Coisas a rever
- Nada pendente.
