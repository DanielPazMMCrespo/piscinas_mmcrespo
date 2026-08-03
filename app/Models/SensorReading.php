<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorReading extends Model
{
    protected $fillable = [
        'pool_id', 'hanna_device_id', 'lida_em',
        'ph', 'orp', 'temperatura_agua', 'temperatura_ar',
        'caudal_ph', 'caudal_cloro', 'raw_parameters',
    ];

    protected $casts = [
        'lida_em' => 'datetime',
        'ph' => 'decimal:2',
        'orp' => 'decimal:2',
        'temperatura_agua' => 'decimal:1',
        'temperatura_ar' => 'decimal:1',
        'caudal_ph' => 'decimal:2',
        'caudal_cloro' => 'decimal:2',
        'raw_parameters' => 'array',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }
}
