<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HannaDevice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'hanna_device_id', 'name', 'pool_id', 'active', 'raw_info', 'ajuste_minutos',
        'ph_out_of_band_since', 'ph_overtime_notified_at', 'dose_sincronizada_ate',
    ];

    protected $casts = [
        'active' => 'boolean',
        'raw_info' => 'array',
        'ajuste_minutos' => 'integer',
        'ph_out_of_band_since' => 'datetime',
        'ph_overtime_notified_at' => 'datetime',
        'dose_sincronizada_ate' => 'datetime',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function leituras(): HasMany
    {
        return $this->hasMany(SensorReading::class, 'hanna_device_id', 'hanna_device_id');
    }

    private ?SensorReading $ultimaLeituraMemo = null;

    private bool $ultimaLeituraCarregada = false;

    /**
     * Última leitura guardada para este dispositivo. Memoizada: a tabela de
     * sensores chamava-a numa coluna a seguir à outra, por linha.
     */
    public function ultimaLeitura(): ?SensorReading
    {
        if (! $this->ultimaLeituraCarregada) {
            $this->ultimaLeituraMemo = $this->leituras()->latest('lida_em')->first();
            $this->ultimaLeituraCarregada = true;
        }

        return $this->ultimaLeituraMemo;
    }

    /**
     * Configuração de dosagem (campo DS) tal como reportada pelo próprio
     * controlador Hanna. Formato CSV:
     * Tipo,phSetpoint,phBanda,phOvertimeMin,orpSetpoint,orpBanda,orpOvertimeMin,caudalPh,caudalCl,delayPh,delayOrp
     *
     * @return array{setpoint: float, band: float, overtimeMinutes: int}|null
     */
    public function dosingSettings(): ?array
    {
        $ds = $this->raw_info['reportedSettings']['DS'] ?? null;

        if (! is_string($ds) || $ds === '') {
            return null;
        }

        $parts = explode(',', $ds);

        if (count($parts) < 4) {
            return null;
        }

        return [
            'setpoint' => (float) $parts[1],
            'band' => (float) $parts[2],
            'overtimeMinutes' => (int) $parts[3],
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
