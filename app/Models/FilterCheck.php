<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterCheck extends Model
{
    protected $fillable = [
        'pool_id', 'user_id', 'verificado_em', 'tipo_operacao',
        'caminho_foto', 'resultado_ia', 'descricao_ia', 'observacoes',
    ];

    protected $casts = ['verificado_em' => 'datetime'];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
