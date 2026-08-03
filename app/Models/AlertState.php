<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado de tratamento (Kanban) de um alerta operacional calculado.
 * A chave é estável e determinística — ver AlertasService.
 */
class AlertState extends Model
{
    protected $fillable = ['alert_key', 'status', 'payload', 'moved_by', 'moved_at'];

    protected $casts = [
        'payload' => 'array',
        'moved_at' => 'datetime',
    ];
}
