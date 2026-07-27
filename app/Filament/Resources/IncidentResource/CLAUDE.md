# Incidentes (`IncidentResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Registo e acompanhamento de incidentes operacionais (avaria, fuga de água, qualidade da água, outro), com chat/timeline associado. Quase sempre criados manualmente pela equipa — o auto-incidente por 3x violação/dia/piscina (`ExecuteBusinessRulesCommand`) existe mas dispara raramente na prática.
- Qualquer papel com permissão `NSPermission::INCIDENTES` vê/cria. **NS só vê os seus próprios** (filtrado em `getEloquentQuery()` e na Policy).
- Só **Admin** edita/elimina. Resolver exige Admin ou Técnico.

## Estrutura de dados
- Campos: `installation_id` (restrito às instalações do NS), `pool_id` (opcional — null quando o incidente é da instalação toda, ex: fuga geral; preenchido quando é de uma piscina específica, ex: qualidade da água; filtrado pela instalação escolhida e pelas piscinas do NS), `user_id` (fixo ao autor), `ocorreu_em`, `type` (`App\Constants\IncidentType`), `descricao`, `observacoes`. Secção "Resolução" só visível quando `status === IncidentStatus::RESOLVIDO` (campos disabled/Placeholder, preenchidos só pela ação resolver).
- Relações: `instalacao`, `piscina`, `utilizador` (autor), `resolvidoPor`, `mensagens` (`IncidentMessage`, tipo `mensagem`/`sistema`).
- `status` (`App\Constants\IncidentStatus`) e `type` (`App\Constants\IncidentType`) são enums de constantes — `IncidentType::options()`/`::label()` centralizam as 4 opções (form + tabela já não duplicam o array).
- `Incident::participantes()`: autores de mensagens + reportante, exclui quem escreveu agora; se ninguém participou ainda, fallback para todos Admin+Técnico. Usado para decidir quem notificar.

## Lógica de negócio não óbvia
- Mensagens de sistema automáticas em criação ("Incidente reportado") e em resolução ("Estado alterado para: Resolvido"), com notificação aos participantes/Admin+Técnico.
- **Reabertura automática pelo chat, restrita**: se um **Admin/Técnico** escreve num incidente já resolvido, o `IncidentChatWidget` reabre-o (limpa `resolvido_em`/`resolvido_por`/`resolucao`, volta a `aberto`), regista mensagem de sistema. Um NS a comentar num incidente resolvido **não** o reabre — só adiciona a mensagem à conversa.
- `IncidentObserver` invalida `invalidateAllAlerts()` em `created`/`updated`.
- `IncidentChatWidget` tem `$shouldRegister = false` — só aparece no rodapé de `ViewIncident`.
- Notificações: criação → Admin+Técnico; cada mensagem/resolução → participantes (3 canais: database/push/mail); escalação automática >24h sem resposta → Admin+Gestor (sino Filament, `ExecuteBusinessRulesCommand::rule2_autoEscalateIncidents`). Bem coberto, sem necessidade de alteração.

## Ações
- Criar (com confirmação em modal), Ver (abre chat, com botão **Resolver** no cabeçalho quando aplicável), Editar (admin only, com confirmação), Resolver (Admin/Técnico, pede texto ≥5 chars — disponível tanto na tabela como na página de visualização, lógica partilhada via `IncidentResource::aplicarResolucao()`), Eliminar (bulk, admin only), Enviar mensagem (chat, só reabre se Admin/Técnico).

## Coisas revistas com o utilizador (2026-07-23)
- ~~Falta seleção de piscina~~ — **adicionado** `pool_id` (opcional, migração `2026_07_23_000001`).
- ~~"Resolver" só na tabela~~ — **adicionado** à página `ViewIncident` (botão no cabeçalho).
- ~~"Resolvido em" desformatado~~ — **corrigido**, `Placeholder` com `->format('d/m/Y H:i')`. De caminho, corrigido também "Resolvido por" que aparecia sempre vazio (`resolvidoPor.name` não resolvia via dot-notation no form — trocado por `Placeholder`).
- ~~`status`/`type` strings soltas~~ — **extraído** `App\Constants\IncidentType` (já existia `IncidentStatus`); usados em model, resource, tabela e `ExecuteBusinessRulesCommand`.
- ~~`canCreate()` ausente no Resource~~ — **adicionado**, espelha a regra da `IncidentPolicy::create()`.
- ~~Reabertura por qualquer autor~~ — **restrita** a Admin/Técnico (mesma regra de quem pode resolver).
- Duplicação Policy/Resource da regra "NS só vê os seus" — **decisão: manter**. É defesa em profundidade intencional (Policy autoriza a ação, Resource filtra a listagem), igual ao padrão usado em `DailyRecordResource`. Não é bug.

## Coisas a rever (novas, encontradas durante este trabalho)
- Testado em ambiente local: com WebPush configurado (VAPID + GMP/BCMath), a notificação de resolução funciona; sem isso (como neste sandbox de dev), o envio falha com erro 500 **depois** de os dados já terem sido gravados — o utilizador vê um erro mas a resolução foi aplicada. Confirmar que produção tem GMP/BCMath instalado (Railway) para este caminho nunca falhar silenciosamente em produção.
