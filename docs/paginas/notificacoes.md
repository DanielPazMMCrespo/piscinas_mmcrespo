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
- Diários: compara `hora_diaria` com a hora atual **exata** (sem janela de tolerância) — se o scheduler atrasar/falhar nesse minuto exato, o disparo desse dia perde-se silenciosamente. Protegido de duplo-envio no mesmo dia por `ultima_data_enviada`.
- Destinatários via `User::role($broadcast->cargos)` — se vazio, não faz nada (sem log/aviso).

## Coisas resolvidas
- ✓ **Campo "Horários do Resumo de Conformidade" removido**: fonte de verdade agora só em DefinicoesSistema.php (página apropriada para settings globais, não para preferências pessoais).

## Coisas a rever
- Disparo diário sem janela de tolerância — se o scheduler tiver uma falha pontual nesse minuto, o resumo desse dia simplesmente não sai (sem aviso).
