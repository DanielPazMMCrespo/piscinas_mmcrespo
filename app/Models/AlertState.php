<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de tratamento (Kanban) de um alerta operacional calculado.
 * A chave é estável e determinística — ver AlertasService.
 */
class AlertState extends Model
{
    public const STATUS = ['pendente', 'em_curso', 'resolvido', 'resolvido_auto'];

    protected $fillable = ['alert_key', 'status', 'payload', 'moved_by', 'moved_at'];

    protected $casts = [
        'payload' => 'array',
        'moved_at' => 'datetime',
    ];

    public function movidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
