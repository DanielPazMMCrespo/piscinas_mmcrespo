<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta de "torneira de água aberta": criado quando um registo diário marca a
 * entrada de água como "ON — com água" (agua_modo='on_com_agua'), resolvido
 * quando um registo seguinte da mesma piscina muda esse estado.
 *
 * O AlertasService lê as linhas por resolver (resolved_at null) para o Kanban.
 */
class TapAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'pool_id', 'opened_record_id', 'opened_by', 'opened_at', 'notified_at',
        'resolved_at', 'resolved_by', 'resolved_record_id', 'resolution',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function openedRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'opened_record_id');
    }

    public function resolvedRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'resolved_record_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
