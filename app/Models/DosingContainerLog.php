<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DosingContainerLog extends Model
{
    protected $fillable = [
        'dosing_container_id', 'tipo_movimento', 'quantidade_ml',
        'restante_apos_ml', 'origem', 'user_id', 'nota', 'registado_em',
    ];

    protected $casts = [
        'quantidade_ml' => 'decimal:2',
        'restante_apos_ml' => 'decimal:2',
        'registado_em' => 'datetime',
    ];

    public function container(): BelongsTo
    {
        return $this->belongsTo(DosingContainer::class, 'dosing_container_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
