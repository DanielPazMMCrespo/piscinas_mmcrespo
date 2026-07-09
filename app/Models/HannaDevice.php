<?php declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HannaDevice extends Model
{
    protected $fillable = [
        'hanna_device_id', 'name', 'pool_id', 'active', 'raw_info',
        'ph_out_of_band_since', 'ph_overtime_notified_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'raw_info' => 'array',
        'ph_out_of_band_since' => 'datetime',
        'ph_overtime_notified_at' => 'datetime',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function leituras(): HasMany
    {
        return $this->hasMany(SensorReading::class, 'hanna_device_id', 'hanna_device_id');
    }

    /** Ãšltima leitura guardada para este dispositivo. */
    public function ultimaLeitura(): ?SensorReading
    {
        return $this->leituras()->latest('lida_em')->first();
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

    /**
     * Partes cruas do DS (11 campos), para reconstruir a string completa ao
     * escrever — ver formato em dosingSettings(). Devolve null se DS em
     * falta ou com menos de 11 campos (não seguro para reescrever).
     *
     * @return list<string>|null
     */
    public function dosingSettingsParts(): ?array
    {
        $ds = $this->raw_info['reportedSettings']['DS'] ?? null;

        if (! is_string($ds) || $ds === '') {
            return null;
        }

        $parts = explode(',', $ds);

        return count($parts) === 11 ? $parts : null;
    }

    /** AS e GS crus, para reenviar inalterados junto com o DS novo. */
    public function alarmSettingsRaw(): ?string
    {
        $as = $this->raw_info['reportedSettings']['AS'] ?? null;

        return is_string($as) ? $as : null;
    }

    public function generalSettingsRaw(): ?string
    {
        $gs = $this->raw_info['reportedSettings']['GS'] ?? null;

        return is_string($gs) ? $gs : null;
    }
}

