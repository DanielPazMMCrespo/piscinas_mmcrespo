# Sensores Hanna (`HannaDeviceResource`)

Contexto local desta pasta. O `CLAUDE.md` da raiz tem a arquitetura geral do projeto.

## Propósito
Mapeamento dispositivo Hanna Cloud (BL132) → piscina. Só Admin. Hoje todas as 5 piscinas têm sonda ativa. Sem página `view` — "ver" é um modal (`recordAction`), não uma rota Filament separada.

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
Lista dispositivos da conta Hanna Cloud e faz `updateOrCreate` por `hanna_device_id` — **cria automaticamente** dispositivos novos sem piscina associada (`pool_id=null`) e **reativa** (`active=true`) qualquer dispositivo que um admin tivesse desativado manualmente. Não apaga dispositivos que desapareceram da conta.

## Sync normal
Por dispositivo ativo: atualiza `raw_info`, lê última leitura (protegida pelo circuit breaker), grava em `sensor_readings` (upsert idempotente por `hanna_device_id`+`lida_em`), dispara `notificarThresholds()` (pH fora dos limites) e `atualizarPhOvertime()` (máquina de estados própria, histerese de 2 leituras para fechar episódio). Ignora leituras dentro de janelas de "artefacto" (`LeituraArtefactoService`). Também desconta dosagem dos bidões (`sincronizarDosagem()`, janela de recuperação máx. 24h).

## Ações
"Sincronizar agora", "Descobrir dispositivos" (com confirmação), "Detalhes" (modal), "Configurar" (link externo para hannacloud.com), Editar, Eliminar.

## Coisas resolvidas
- ✓ **Página `ViewHannaDevice` adicionada**: consistente com Pool/Installation/User; substituiu modal "Detalhes" por ViewAction navegável.

## Coisas a rever
- `--discover` reativa dispositivos desativados manualmente — pode reintroduzir sync indesejado num sensor que um admin desligou de propósito.
- Sem tratamento de dispositivos removidos da conta Hanna Cloud (nunca ficam `active=false` sozinhos).
