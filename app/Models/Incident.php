<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $fillable = [
        'installation_id', 'user_id', 'ocorreu_em',
        'type', 'descricao', 'observacoes',
        'status', 'resolvido_em', 'resolvido_por', 'resolucao',
    ];

    protected $casts = [
        'ocorreu_em' => 'datetime',
        'resolvido_em' => 'datetime',
    ];

    public function estaResolvido(): bool
    {
        return $this->status === 'resolvido';
    }

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function piscinas(): BelongsToMany
    {
        return $this->belongsToMany(Pool::class, 'incident_pools');
    }

    public function produtosIncidente(): HasMany
    {
        return $this->hasMany(IncidentProduct::class);
    }
}
