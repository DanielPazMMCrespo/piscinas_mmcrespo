<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'pool_id', 'user_id', 'verificado_em', 'tipo_operacao',
        'caminho_foto', 'observacoes',
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
