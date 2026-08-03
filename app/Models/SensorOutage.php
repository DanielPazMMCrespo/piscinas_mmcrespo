<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Indisponibilidade declarada da sonda Hanna de uma piscina: peça partida,
 * calibração, sonda removida, etc. Aberta por uma ação operacional do tipo
 * `avaria_sonda` e fechada por outra a dar baixa da situação.
 *
 * Serve dois propósitos:
 *  - justificar valores ausentes ou absurdos do controlador nesse período (a
 *    janela entra no LeituraArtefactoService, logo as leituras deixam de contar
 *    para a conformidade em todos os ecrãs, gráficos e no livro sanitário);
 *  - dizer em cada ecrã que fala da sonda o que se passa com ela e desde quando.
 */
class SensorOutage extends Model
{
    use HasFactory, LogsActivity;

    public const MOTIVO_PECA_PARTIDA = 'peca_partida';

    public const MOTIVO_EM_REPARACAO = 'em_reparacao';

    public const MOTIVO_EM_CALIBRACAO = 'em_calibracao';

    public const MOTIVO_SEM_COMUNICACAO = 'sem_comunicacao';

    public const MOTIVO_REMOVIDA = 'removida';

    public const MOTIVO_LEITURAS_ERRADAS = 'leituras_erradas';

    public const MOTIVO_OUTRO = 'outro';

    /** Estado escolhido na ação operacional para dar baixa da avaria. */
    public const ESTADO_RESOLVIDO = 'resolvido';

    public const MOTIVOS = [
        self::MOTIVO_PECA_PARTIDA => 'Peça partida',
        self::MOTIVO_EM_REPARACAO => 'Em reparação',
        self::MOTIVO_EM_CALIBRACAO => 'Em calibração',
        self::MOTIVO_SEM_COMUNICACAO => 'Sem comunicação',
        self::MOTIVO_REMOVIDA => 'Sonda removida',
        self::MOTIVO_LEITURAS_ERRADAS => 'Leituras erradas / não fiáveis',
        self::MOTIVO_OUTRO => 'Outro motivo',
    ];

    protected $fillable = [
        'pool_id', 'hanna_device_id', 'motivo', 'detalhe', 'aberta_em', 'resolvida_em',
        'aberta_por', 'resolvida_por', 'opened_action_id', 'resolved_action_id',
    ];

    protected $casts = [
        'aberta_em' => 'datetime',
        'resolvida_em' => 'datetime',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function abertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberta_por');
    }

    public function resolvidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }

    public function scopeAbertas(Builder $query): Builder
    {
        return $query->whereNull('resolvida_em');
    }

    /** Avaria em aberto de uma piscina, ou null se a sonda está declarada boa. */
    public static function abertaPara(int $poolId): ?self
    {
        return static::query()
            ->abertas()
            ->where('pool_id', $poolId)
            ->latest('aberta_em')
            ->first();
    }

    public function motivoLabel(): string
    {
        return self::MOTIVOS[$this->motivo] ?? $this->motivo;
    }

    /** Texto curto para badges e linhas de estado ("Sonda avariada: peça partida"). */
    public function resumo(): string
    {
        return 'Sonda indisponível: '.mb_strtolower($this->motivoLabel());
    }

    public function desdeHumano(): string
    {
        return $this->aberta_em->locale('pt')->diffForHumans();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
