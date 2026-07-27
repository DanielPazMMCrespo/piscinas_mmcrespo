# Sensores Hanna (`HannaDeviceResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Mapeamento dispositivo Hanna Cloud (BL132) → piscina. Só Admin. Hoje todas as 5 piscinas têm sonda ativa. Existe rota `view` (`ViewHannaDevice`) **e** um modal "Detalhes" (`recordAction('ver_detalhes')`); o clique na linha abre o modal, não a página — redundância conhecida, ambos funcionais.

## Estrutura de dados
- `hanna_device_id` (DID, imutável depois de criado), `name`, `pool_id`, `active`.
- `raw_info` (JSON com `reportedSettings` completos da API, incluindo `DS` = CSV de config de dosagem) — `dosingSettings()` faz parse do `DS` (setpoint/banda/overtime de pH).
- `leituras()` liga a `SensorReading` por `hanna_device_id` (string), **não** por FK/PK — frágil se o DID mudar ou duplicar.

## Circuit breaker (`HannaCircuitBreaker`)
Máquina de estados global (não por dispositivo), guardada em Cache:
- Abre com ≥5 falhas em 5 min (só conta `SensorCommunicationException` com `shouldRetry()`).
- Aberto: nega chamadas 60s, depois passa a "half-open" (deixa 1 tentativa de prova).
- Prova ok → fecha; prova falha → reabre.
- Durante "aberto", `HannaCloudSync` salta o dispositivo nesse ciclo (o "fallback" é `null`, não devolve a última leitura ativamente — a leitura anterior só continua acessível porque já está na BD).

## `hanna:sync --discover`
Lista dispositivos da conta Hanna Cloud — **cria** dispositivos novos sem piscina associada (`pool_id=null`, `active=true`) e **atualiza** metadados (`name`/`raw_info`) dos existentes **preservando o `active`** (respeita um disable manual). Não apaga dispositivos que desapareceram da conta, mas **avisa** na saída quais os dispositivos ativos que já não constam da conta (o admin decide se desativa).

## Sync normal
Por dispositivo ativo: atualiza `raw_info`, lê última leitura (protegida pelo circuit breaker), grava em `sensor_readings` (upsert idempotente por `hanna_device_id`+`lida_em`), dispara `notificarThresholds()` (pH fora dos limites) e `atualizarPhOvertime()` (máquina de estados própria, histerese de 2 leituras para fechar episódio). Ignora leituras dentro de janelas de "artefacto" (`LeituraArtefactoService`). Também desconta dosagem dos bidões (`sincronizarDosagem()`, janela de recuperação máx. 24h).

## Ações
"Sincronizar agora", "Descobrir dispositivos" (com confirmação), "Detalhes" (modal), "Configurar" (link externo para hannacloud.com), Editar, Eliminar.

## Coisas resolvidas
- ✓ **Página `ViewHannaDevice` adicionada**: consistente com Pool/Installation/User. (O modal "Detalhes" não chegou a ser removido — o row-click ainda o usa; redundância a limpar um dia.)
- ✓ **`--discover` já não reativa disable manual**: só cria novos como `active=true`; existentes mantêm o `active` atual.
- ✓ **Dispositivos desaparecidos da conta**: `--discover` avisa quais os ativos que já não constam (não desativa automaticamente — evita desligar sensores bons num discover parcial por glitch da API).

## Coisas a rever
- `leituras()` liga por `hanna_device_id` (string), não por FK — frágil se o DID mudar/duplicar (ver Estrutura de dados). Não resolvido; mudança de schema com risco, deixado deliberadamente.
