<?php declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HannaDevice extends Model
{
    protected $fillable = ['hanna_device_id', 'name', 'pool_id', 'active', 'raw_info'];

    protected $casts = [
        'active' => 'boolean',
        'raw_info' => 'array',
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
}

