# Notificações (`app/Filament/Pages/Notificacoes.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Acesso: qualquer utilizador (partes de gestão condicionadas internamente a Admin via `podeGerir()`).

## Propósito
Três funções na mesma página:
1. **Preferências pessoais** de notificação (push/email por tipo de evento) — 4 secções (Incidentes, Operação, Segurança/Conformidade/Sensores, Sistema); as 3 primeiras escondidas do NS.
2. **Envio manual** de notificação a um cargo ou utilizador (só Admin). Uso real: avisos operacionais (piscina fechada, trocar produto) e lembretes administrativos (reuniões, RH).
3. **Notificações Personalizadas agendadas** (`CustomBroadcast`, CRUD só Admin) — únicas ou diárias.

## Lógica não óbvia
- O Select "Horários do Resumo de Conformidade" aparece dentro do form de **preferências pessoais** só se `podeGerir()` — mistura de escopos (preferência individual vs. config global). Está **duplicado** com o mesmo campo em Definições do Sistema (mesmas opções, máx. 4) — dois pontos de edição para a mesma setting, mantê-los coerentes é manual.
- `savePreferences()`: `array_replace_recursive` das preferências existentes com as novas — merge, não substituição total (não perde chaves ausentes do form atual).
- `enviarManual()`: destino é cargo XOR utilizador; tag da notificação `manual-send-{time()}` (não idempotente entre pedidos muito próximos, aceitável para uma ação manual pontual).
- Tabela de `CustomBroadcast`: coluna "Estado" muda de lógica por tipo — diário usa `ativo` (Ativo/Pausado), único usa `enviado_em` (Enviado/Agendado).

## `FireDueCustomBroadcastsCommand` (`notificacoes:custom-fire-due`, agendado a cada minuto)
- Únicos: `enviado_em IS NULL AND enviar_em <= now()` → envia e marca.
- Diários: dispara em **qualquer corrida do dia a partir da hora agendada** (`hora_diaria <= agora`), não só no minuto exato — uma falha pontual do scheduler nesse minuto já não perde o envio. Protegido de duplo-envio no mesmo dia por `ultima_data_enviada`. (Nota: um broadcast diário criado depois da hora agendada dispara nesse mesmo dia; comportamento aceite.)
- Destinatários via `User::role($broadcast->cargos)` — se vazio, não faz nada (sem log/aviso).

## Janela de silêncio (`App\Support\JanelaSilencio`)
Fonte única de "agora não se notifica ninguém". Padrão: **22:00 → 08:00 todos os dias e o domingo inteiro**, configurável em Definições → Sistema → "Janela de Silêncio" (`silencio_ativo`, `silencio_inicio`, `silencio_fim`, `silencio_domingo`).

- **Onde corta**: `User::wantsNotification()` devolve `false` para push e e-mail dentro da janela — inclusive para os tipos de conformidade obrigatória, que de resto não são desligáveis. O canal `database` não passa por aí, logo o sino do painel continua a receber tudo.
- **Exceções**: `timer_finished` (o utilizador iniciou o temporizador e está à espera dele) e a zona de testes de Definições (`TesteNotificacaoPush`, `PedidoAtivacaoPushNotification`, que nunca passaram por `wantsNotification`). Testar o push às 03:00 funciona — é propositado.
- **Avisos de episódio único**: torneira aberta, bidão baixo, pH em overtime, escalação de incidente e tendência degradante marcam "já notifiquei" numa coluna ou em cache. Se o corte fosse só no canal, o episódio ficava marcado sem ninguém ter recebido nada. Por isso cada um destes verifica `JanelaSilencio::ativa()` **antes** de marcar e sai sem fazer nada — o comando volta a correr (15 em 15 min, ou no dia seguinte) e o aviso sai na primeira corrida depois da janela.
- **Horários agendados movidos** para fora da janela: `tendencias:verificar` (era `everySixHours()`, ou seja 00:00/06/12/18 — a corrida da meia-noite era a que acordava a equipa) passou a diário às 09:00; `notificacoes:comparacao-semanal` passou de domingo para segunda às 09:00, comparando agora duas semanas completas; `relatorio:mensal-automatico` passou das 06:00 para as 08:30.
- **Não há fila de adiamento**: o que é event-driven e cai na janela (novo incidente, mensagem de incidente, não-conformidade de um registo criado às 23:00) fica só no sino. Foi decisão explícita — "não quero qualquer tipo de notificação nessas condições".

## Coisas resolvidas
- ✓ **Campo "Horários do Resumo de Conformidade" removido**: fonte de verdade agora só em DefinicoesSistema.php (página apropriada para settings globais, não para preferências pessoais).
- ✓ **Janela de tolerância no disparo diário**: `FireDueCustomBroadcastsCommand` dispara a partir da hora agendada (não só no minuto exato), recuperando de uma falha pontual do scheduler sem risco de duplo-envio.

## Coisas a rever
- `HannaThresholdAlert` não tem dedup nenhum: enquanto o pH estiver fora dos limites, o `hanna:sync` volta a notificar de 15 em 15 minutos. Fora da janela de silêncio isto é ruído a sério.
- `CheckParameterTrendsCommand` trata leituras em falta gravadas como `0,00` como valores reais e tolera uma exceção na monotonia, pelo que uma série como `1,14 → 0,42 → 0,00 → 1,49 → 0,00` passa como "tendência degradante" e chega a prever cloro negativo.
