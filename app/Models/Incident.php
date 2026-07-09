<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasFactory;

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

    /**
     * @return BelongsTo
     */
    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    /**
     * @return BelongsTo
     */
    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo
     */
    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    /**
     * @return HasMany
     */
    public function mensagens(): HasMany
    {
        return $this->hasMany(IncidentMessage::class, 'incident_id')->orderBy('created_at');
    }

}
