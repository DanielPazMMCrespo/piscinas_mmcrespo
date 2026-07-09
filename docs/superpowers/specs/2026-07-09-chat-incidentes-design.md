# Chat de Incidentes — Design

Data: 2026-07-09

## Problema

Hoje o `Incident` (`app/Models/Incident.php`) é um registo estático: `descricao`, `observacoes`, e uma ação "Resolver" que grava `resolucao`. Não há troca de mensagens entre quem reporta (Nadador-Salvador/Gestor no terreno) e quem corrige (Admin/Técnico). Comunicação real acontece hoje por WhatsApp/telefone, fora da app. Objetivo: permitir reportar uma anomalia e conversar sobre ela dentro da app, com notificação imediata a quem tem de agir, e sem perder o histórico.

## Âmbito

Dentro:
- Thread de mensagens por incidente, misturando mensagens de chat com mensagens automáticas de mudança de estado.
- Notificação via base de dados (sino do Filament) a cada evento relevante da thread.
- Estrutura pronta para email (canal desligado, sem SMTP configurado ainda).
- Reabertura automática de incidentes resolvidos quando chega mensagem nova.

Fora de âmbito (não implementar agora):
- Envio real de email.
- Atribuição de utilizadores a instalações específicas — Admin e Técnico continuam a ver todos os incidentes de todas as instalações, como hoje.
- Terceiro estado de incidente (`em_curso`). Mantém-se `aberto` / `resolvido`.
- Alterações à lógica de agregação de alertas do `AlertasService` para o Kanban (continua a ler apenas incidentes não resolvidos).

## Modelo de dados

Nova tabela `incident_messages`:

```
id
incident_id   FK -> incidents, cascade delete
user_id       FK -> users
tipo          enum: 'mensagem' | 'sistema'
texto         text
created_at
updated_at
```

Índice em `(incident_id, created_at)` para carregar a timeline ordenada.

Model `IncidentMessage`:
- `belongsTo(Incident::class)`, `belongsTo(User::class, 'user_id')`.
- `declare(strict_types=1)`, sem soft deletes (append-only, como o resto do projeto).

## Comportamento

**Criação do incidente** (`CreateIncident`):
- Ao gravar o incidente, cria-se automaticamente a primeira `IncidentMessage` (`tipo='sistema'`, texto = `"Incidente reportado: {descricao}"`).
- Notificação BD enviada a todos os utilizadores com role `admin` ou `tecnico`.

**Resposta na thread**:
- Qualquer utilizador autenticado com acesso ao incidente pode adicionar uma `IncidentMessage` (`tipo='mensagem'`).
- Se o autor é Admin/Técnico → notificação BD ao autor original do incidente (`incident.user_id`).
- Se o autor é o reportante (NS/Gestor) ou qualquer outro que não seja Admin/Técnico → notificação BD a todos os Admin/Técnico.
- Se `incident.status === 'resolvido'` e chega uma mensagem nova (`tipo='mensagem'`), o incidente reabre automaticamente: `status` volta a `'aberto'`, limpa `resolvido_em`/`resolvido_por`/`resolucao`, e grava-se uma mensagem `sistema` ("Reaberto automaticamente após nova mensagem").

**Ação "Resolver"** (já existe em `IncidentResource`, mantém-se restrita a admin/tecnico):
- Ao confirmar, além de atualizar `status`, `resolvido_em`, `resolvido_por`, `resolucao` (comportamento atual), grava-se uma `IncidentMessage` (`tipo='sistema'`, texto = `"Estado alterado para: Resolvido — {resolucao}"`).
- Notificação BD ao autor original do incidente.

## Notificações

Duas notification classes novas em `app/Notifications/`, seguindo o padrão existente (`HannaOvertimeAlert.php`):
- `IncidentCreatedNotification` — `via()` retorna `['database']`. Título/corpo/link para `ViewIncident`.
- `IncidentMessageNotification` — idem, inclui autor e trecho da mensagem.

Ambas com um comentário `// TODO: adicionar 'mail' ao via() quando SMTP estiver configurado` no local exato onde o canal seria adicionado — não implementar envio de email agora.

## UI

`ViewIncident` (Filament) ganha uma secção de timeline substituindo/complementando o bloco estático de `descricao`/`observacoes`:
- Lista de `IncidentMessage` ordenada por `created_at`, renderizada como bolhas de chat (mensagens do próprio utilizador alinhadas à direita, restantes à esquerda; mensagens `sistema` centradas, estilo "pill" cinzento, sem avatar).
- Caixa de texto + botão "Enviar" no fundo, disponível sempre que o utilizador tem acesso ao incidente (não depende do status — enviar uma mensagem com incidente resolvido é o que despoleta a reabertura).
- Auto-scroll para a mensagem mais recente ao abrir a página.

Sem alterações à lista (`ListIncidents`) ou ao Kanban (`QuadroOperacionalWidget`) nesta fase — ficam como estão.

## Permissões

Sem alterações às regras de acesso já existentes em `IncidentResource`:
- Todos os 4 roles (`admin`, `gestor`, `tecnico`, `nadador_salvador`) podem criar um incidente.
- NS continua a só ver os seus próprios incidentes na listagem (linha já existente `IncidentResource.php:38-40`); dentro do incidente que ele próprio criou, pode ler e escrever na thread livremente.
- Qualquer participante autenticado com acesso de visualização ao incidente (passa a policy existente `IncidentPolicy`) pode escrever mensagens.

## Testes

- Unit: criar incidente gera mensagem `sistema` inicial + notificação a admin/tecnico.
- Unit: resposta de NS a incidente aberto notifica admin/tecnico; resposta de admin notifica o reportante.
- Unit: mensagem nova em incidente `resolvido` reabre o incidente e limpa campos de resolução.
- Unit: ação "Resolver" grava mensagem `sistema` com o texto da resolução.
- Feature: NS só vê incidentes próprios na listagem, mas pode responder ao seu próprio incidente mesmo depois de resolvido.
