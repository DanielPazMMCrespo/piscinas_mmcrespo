<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Incident extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'installation_id', 'pool_id', 'user_id', 'ocorreu_em',
        'type', 'descricao', 'observacoes',
        'status', 'resolvido_em', 'resolvido_por', 'resolucao',
    ];

    protected $casts = [
        'ocorreu_em' => 'datetime',
        'resolvido_em' => 'datetime',
    ];

    public function estaResolvido(): bool
    {
        return $this->status === IncidentStatus::RESOLVIDO;
    }

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    /**
     * @return BelongsTo
     */
    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(IncidentMessage::class, 'incident_id')->orderBy('created_at');
    }

    /**
     * Quem já participou nesta conversa (reportante + autores de mensagens),
     * excluindo quem acabou de escrever. Se ninguém mais participou ainda, cai
     * para admin+técnico — evita notificar todo o sistema a cada mensagem
     * mantendo pelo menos alguém avisado à primeira resposta.
     *
     * @return Collection<int, User>
     */
    public function participantes(?User $excluir = null): Collection
    {
        $ids = $this->mensagens()->pluck('user_id')
            ->push($this->user_id)
            ->filter()
            ->unique();

        if ($excluir) {
            $ids = $ids->reject(fn ($id) => $id === $excluir->id);
        }

        $participantes = User::whereIn('id', $ids)->get();

        if ($participantes->isNotEmpty()) {
            return $participantes;
        }

        $fallback = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();

        return $excluir ? $fallback->reject(fn (User $u) => $u->id === $excluir->id) : $fallback;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
