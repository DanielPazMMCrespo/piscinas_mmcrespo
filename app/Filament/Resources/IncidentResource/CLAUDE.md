# Incidentes (`IncidentResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Registo e acompanhamento de incidentes operacionais (avaria, fuga de água, qualidade da água, outro), com chat/timeline associado. Quase sempre criados manualmente pela equipa — o auto-incidente por 3x violação/dia/piscina (`ExecuteBusinessRulesCommand`) existe mas dispara raramente na prática.
- Qualquer papel com permissão `NSPermission::INCIDENTES` vê/cria. **NS só vê os seus próprios** (filtrado em `getEloquentQuery()` e na Policy).
- Só **Admin** edita/elimina. Resolver exige Admin ou Técnico.

## Estrutura de dados
- Campos: `installation_id` (restrito às instalações do NS), `user_id` (fixo ao autor), `ocorreu_em`, `type` (avaria_equipamento/fuga_agua/qualidade_agua/outro), `descricao`, `observacoes`. Secção "Resolução" só visível quando `status === 'resolvido'` (campos disabled, preenchidos só pela ação resolver).
- Relações: `instalacao`, `utilizador` (autor), `resolvidoPor`, `mensagens` (`IncidentMessage`, tipo `mensagem`/`sistema`).
- `Incident::participantes()`: autores de mensagens + reportante, exclui quem escreveu agora; se ninguém participou ainda, fallback para todos Admin+Técnico. Usado para decidir quem notificar.

## Lógica de negócio não óbvia
- Mensagens de sistema automáticas em criação ("Incidente reportado") e em resolução ("Estado alterado para: Resolvido"), com notificação aos participantes/Admin+Técnico.
- **Reabertura automática pelo chat**: se alguém escreve num incidente já resolvido, o `IncidentChatWidget` reabre-o sozinho (limpa `resolvido_em`/`resolvido_por`/`resolucao`, volta a `aberto`), regista mensagem de sistema. Acontece para **qualquer autor**, não só Admin/Técnico.
- `IncidentObserver` invalida `invalidateAllAlerts()` em `created`/`updated`.
- `IncidentChatWidget` tem `$shouldRegister = false` — só aparece no rodapé de `ViewIncident`.

## Ações
- Criar (com confirmação em modal), Ver (abre chat), Editar (admin only, com confirmação), Resolver (Admin/Técnico, pede texto ≥5 chars), Eliminar (bulk, admin only), Enviar mensagem (chat, pode reabrir).

## Coisas a rever
- `status`/`type` são strings soltas sem enum/constantes no model `Incident` (diferente de `OperationalAction::TIPOS`) — risco de erro de digitação; os 4 valores de `type` estão hardcoded em dois sítios (form + tabela).
- `IncidentResource` **não define `canCreate()`** explicitamente (usa só a Policy), ao contrário de `DailyRecordResource`/`OperationalActionResource` que o fazem no próprio Resource — confirmar se é intencional.
- Reabertura automática por qualquer autor de mensagem (mesmo NS a só comentar) pode não ser o comportamento desejado — resolver exige Admin/Técnico, mas reabrir não exige nada.
- `IncidentPolicy` e o Resource duplicam a mesma regra de "NS só vê os seus" em dois sítios (defesa em profundidade, mas tem de se manter sincronizado).
