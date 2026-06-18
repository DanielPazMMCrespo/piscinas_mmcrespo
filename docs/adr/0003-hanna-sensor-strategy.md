# ADR 0003: Hanna Sensor Integration (Manual + Automático)

**Data:** 2026-06-08  
**Decisor:** Daniel Paz  
**Status:** ACCEPTED  

---

## 🎯 Contexto

Piscinas MMCrespo pode ter **sensores Hanna BL132** (sondas inteligentes) instalados em algumas piscinas para leitura contínua de pH, ORP, temperatura.

### Problema

A integração de sensores levanta questões de design:

1. **Obrigatoriedade:** Sensor é mandatório ou fallback?
2. **Frecuência:** Atualizar a cada segundo ou 15 min?
3. **Conflito:** Maneira de o técnico entrar dados manualmente vs. sensor?
4. **Offline:** Comportamento se sensor offline?
5. **Cost:** API calls para Hanna Cloud (potencialmente paga)?

### Decisão Anterior (Sessão 8)

Sistema começou com "sensor optional". **Este ADR formaliza a estratégia final.**

---

## 🏗️ Decisão

**Estratégia: Manual-First com Sensor as Display Enhancement**

### Arquitetura

```
┌──────────────────────────────────────────┐
│ Formulário Registo Diário (Técnico)      │
├──────────────────────────────────────────┤
│ [pH manual]  ← Entrada obrigatória       │
│ [Cloro]      ← Entrada obrigatória       │
│ [Temp]       ← Entrada obrigatória       │
└──────────────────────────────────────────┘
              ↓
┌──────────────────────────────────────────┐
│ Dashboard (Piscina Card)                 │
├──────────────────────────────────────────┤
│ Leitura Manual: 7.4 (há 2h)              │
│ Sonda Hanna: 7.42 (há 5 min) ← Bonus     │
│ Status: ✅ CONFORME                       │
└──────────────────────────────────────────┘
              ↓
┌──────────────────────────────────────────┐
│ Cron hanna:sync (15 min)                 │
├──────────────────────────────────────────┤
│ Sincroniza Hanna Cloud API                │
│ Atualiza sensor_readings                  │
│ Verifica se fora dos limites → alerta     │
└──────────────────────────────────────────┘
```

### Princípios

1. **Manual-first:** Técnico sempre entra dados manualmente
2. **Sensor informacional:** Mostra "Sonda diz X" como referência
3. **Fallback:** Se sensor offline, app funciona normalmente
4. **Alertas duais:** Registo manual + sensor offline = 2 alertas independentes
5. **Não competitive:** Sensor não **invalida** entrada manual

---

## 📋 Consequências

### ✅ Positivas

1. **Independência:** App funciona sem sensores
2. **Compliance CN 14/DA:** Registo manual é oficial (sensor é bonus)
3. **Simplicidade:** Sem lógica de "qual usar?"
4. **Transparency:** Técnico vê ambas leituras, decide confiar
5. **Falha segura:** Sensor offline não bloqueia operação

### ⚠️ Negativas

1. **Duplicação:** Técnico digita mesmo se sensor mede
2. **Cost:** Manutenção Hanna Cloud API + Device
3. **Sync lag:** Sensor pode estar "stale" (não realtime)
4. **Storage:** `sensor_readings` tabela cresce (histórico)
5. **UI clutter:** Cards mostram ambas leituras

### 🔧 Mitigações

| Risco | Mitigation |
|-------|-----------|
| **Digitação** | Botão "Usar leitura sonda" pré-preenche campo (UX improvement) |
| **Cost** | Hanna Cloud gratuito para alguns dispositivos |
| **Lag** | Display mostra "5 min ago" → técnico sabe idade |
| **Storage** | `sensor_readings` com TTL 90 dias (delete old) |
| **Clutter** | Sonda em seção collapsed/opcional (design) |

---

## 🚀 Implementação

### 1. Schema

```sql
CREATE TABLE sensor_readings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(255),
    device_name VARCHAR(255),
    pool_id BIGINT UNSIGNED,
    leitura_ph DECIMAL(4,2),
    leitura_orp_mv INT,
    leitura_temp DECIMAL(4,2),
    lido_em TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pool_id) REFERENCES pools(id),
    INDEX idx_pool_lido (pool_id, lido_em DESC)
);
```

### 2. Cron Job

```php
// app/Console/Commands/HannaSyncCommand.php
class HannaSyncCommand extends Command
{
    protected $signature = 'hanna:sync {--discover}';

    public function handle()
    {
        $service = app(HannaService::class);
        
        if ($this->option('discover')) {
            $devices = $service->discoverDevices();
            $this->info("Found " . count($devices) . " devices");
            return;
        }

        $readings = $service->fetchLatestReadings();
        
        foreach ($readings as $reading) {
            SensorReading::create($reading);
            
            // Opcional: Alertar se fora dos limites
            if ($reading['leitura_ph'] < 6.8 || $reading['leitura_ph'] > 8.0) {
                Alert::create([
                    'tipo' => 'sensor_fora_limites',
                    'pool_id' => $reading['pool_id'],
                ]);
            }
        }
    }
}

// Em routes/console.php:
Schedule::command('hanna:sync')->everyFifteenMinutes();
```

### 3. Dashboard Display

```php
// PainelPiscinasWidget
public function getPoolCard($pool)
{
    $latestManual = $pool->dailyRecords()
        ->whereDoesntHave('correcoes')
        ->latest('registado_em')
        ->first();

    $latestSensor = $pool->sensorReadings()
        ->latest('lido_em')
        ->first();

    return [
        'manual' => [
            'ph' => $latestManual?->ph,
            'idade' => $latestManual?->registado_em,
        ],
        'sensor' => [
            'ph' => $latestSensor?->leitura_ph,
            'idade' => $latestSensor?->lido_em,
            'status' => $latestSensor ? 'online' : 'offline',
        ],
    ];
}
```

### 4. Form Enhancement

```php
// CreateDailyRecord
Section::make('Parâmetros Água')
    ->schema([
        Grid::make()
            ->schema([
                TextInput::make('ph')
                    ->helperText(function () {
                        $sensor = Pool::find($this->pool_id)
                            ?->sensorReadings
                            ?->latest()
                            ?->first();
                        
                        return $sensor 
                            ? "Sonda: {$sensor->leitura_ph} (há " . $sensor->lido_em->diffForHumans() . ")"
                            : "Sem sensor disponível";
                    })
                    ->suffix('pH'),

                // Botão para auto-fill:
                // (implementado via Livewire action)
            ]),
    ])
```

---

## 📊 Estratégia Alternativa (Rejected)

### Alternativa 1: Sensor-Mandatory
```
❌ Rejeitada
- Impossível se sensor offline
- Instalação sem Hanna não funciona
- Custo hardware obrigatório
```

### Alternativa 2: Sensor-Replaces-Manual
```
❌ Rejeitada
- Sensor falha → registo perdido
- Técnico não pode entrar dado manual se sensor online
- Não cumpre CN 14/DA (registo oficial sempre manual)
```

### Alternativa 3: Dual-Validate (chosen)
```
✓ Implementado
- Manual é oficial
- Sensor é informacional
- Ambos visíveis, nenhum invalida outro
- App funciona sempre
```

---

## 🔄 Integração com Features Existentes

### Alertas

```php
// AlertasService coleta ambas:
public function getAlertas()
{
    $aleratas = [];

    // 1. Registos manuais fora limites
    $aleratas[] = DailyRecord::whereRaw('ph < ? OR ph > ?', [6.8, 8.0])
                               ->whereDoesntHave('correcoes')
                               ->get();

    // 2. Sensores offline (se esperado estar online)
    $aleratas[] = Pool::where('tem_sensor', true)
                      ->whereDoesntHave('sensorReadings', function ($q) {
                          $q->where('lido_em', '>', now()->subMinutes(30));
                      })
                      ->get();

    return $aleratas;
}
```

### Dashboard Kanban

```
PARA TRATAR:
├─ pH Competição (manual) = 8.2 [MANUAL]
├─ Sensor Lazer offline (15min) [SENSOR]

EM TRATAMENTO:
├─ Ação cloro Infantil [MANUAL]

RESOLVIDO HOJE:
├─ Sensor Maceira responde [SENSOR]
```

---

## ✅ Validação

### Pre-Production

- [x] `sensor_readings` table migrated
- [x] `hanna:sync` command testado
- [x] Cron schedule setup (15 min)
- [x] Dashboard mostra ambas leituras
- [x] Sensor offline não bloqueia registo manual
- [x] Alertas dual-layer implementados
- [x] Documentação ao utilizador

### Post-Production

```bash
# Verificar sensor sync:
php artisan tinker
> SensorReading::where('pool_id', 1)->latest()->first()
# Deve mostrar leitura recente

# Verificar cron:
railway logs | grep "hanna:sync"
# Deve ver logs a cada 15 min
```

---

## 📚 Referências

- [Hanna BL132 Datasheet](https://www.hannainst.com/)
- [Laravel Scheduling](https://laravel.com/docs/12/scheduling)
- [IoT Integration Patterns](https://www.iot.org/)

---

## 🔄 Decisões Dependentes

- **ADR 0004 (future):** Webhooks Hanna → push (vs pull hanna:sync)
- **ADR 0005 (future):** Mobile app — sensor sync local vs cloud

---

**Decidido por:** Daniel Paz  
**Data:** 2026-06-08  
**Effective:** 2026-06-08 (implementado)  
**Last Review:** 2026-06-18
