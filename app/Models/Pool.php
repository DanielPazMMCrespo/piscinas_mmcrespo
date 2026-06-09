<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pool extends Model
{
    protected $fillable = ['installation_id', 'name', 'type', 'temp_min', 'temp_max', 'volume', 'active'];

    protected $casts = [
        'active'   => 'boolean',
        'temp_min' => 'decimal:1',
        'temp_max' => 'decimal:1',
        'volume'   => 'decimal:2',
    ];

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function registosDiarios(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    public function verificacoesFiltro(): HasMany
    {
        return $this->hasMany(FilterCheck::class);
    }
}
