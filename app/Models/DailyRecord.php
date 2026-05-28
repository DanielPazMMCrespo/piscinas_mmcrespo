<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyRecord extends Model
{
    protected $fillable = [
        'pool_id', 'user_id', 'registado_em',
        'cloro_livre', 'cloro_total',
        'ph', 'temperatura', 'transparencia',
        'caleira_feita', 'renovacao_agua',
        'observacoes', 'e_correcao',
        'corrige_registo_id', 'razao_correcao',
    ];

    protected $casts = [
        'registado_em'  => 'datetime',
        'cloro_livre'   => 'decimal:2',
        'cloro_total'   => 'decimal:2',
        'ph'            => 'decimal:2',
        'temperatura'   => 'decimal:1',
        'caleira_feita' => 'boolean',
        'renovacao_agua'=> 'boolean',
        'e_correcao'    => 'boolean',
    ];

    protected function cloroCombinado(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->cloro_total - (float) $this->cloro_livre, 2)
        );
    }

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adicoes(): HasMany
    {
        return $this->hasMany(RecordAddition::class);
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(RecordPhoto::class);
    }

    public function registoOriginal(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'corrige_registo_id');
    }
}
