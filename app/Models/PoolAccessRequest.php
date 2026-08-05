<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\PoolAccessRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pedido de um nadador-salvador bloqueado (piscina encerrada) para aceder à
 * app. App\Services\PoolAccessRequestService é a fonte única do bloqueio e
 * da aprovação — nunca decidir "está bloqueado?" a partir daqui diretamente.
 */
class PoolAccessRequest extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['user_id', 'motivo', 'status', 'resposta_admin', 'decidido_por', 'decidido_em'];

    protected $casts = [
        'decidido_em' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', PoolAccessRequestStatus::PENDENTE);
    }

    public function getStatusLabelAttribute(): string
    {
        return PoolAccessRequestStatus::label($this->status);
    }
}
