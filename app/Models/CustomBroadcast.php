<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomBroadcast extends Model
{
    use LogsActivity;

    public const TIPO_UNICO = 'unico';

    public const TIPO_DIARIO = 'diario';

    protected $fillable = [
        'titulo',
        'corpo',
        'cargos',
        'tipo_agendamento',
        'enviar_em',
        'hora_diaria',
        'ativo',
        'ultima_data_enviada',
        'enviado_em',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cargos' => 'array',
            'ativo' => 'boolean',
            'enviar_em' => 'datetime',
            'ultima_data_enviada' => 'date',
            'enviado_em' => 'datetime',
        ];
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
