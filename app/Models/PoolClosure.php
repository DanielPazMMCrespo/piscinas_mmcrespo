<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\MotivoEncerramento;
use App\Services\CacheService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Encerramento temporário de uma piscina (fim de época balnear, manutenção,
 * obra, avaria). Período datado ao dia, com 'fim' inclusivo — null significa
 * encerramento em aberto.
 *
 * [AI_CONTEXT]
 * - 'fim' é o ÚLTIMO dia encerrado. Um encerramento de 03/08 a 30/09 tem a
 *   piscina fechada nos dois extremos inclusive.
 * - 'agua_em_tratamento' distingue os dois regimes: true (fechada ao público
 *   mas com química mantida) permite registos diários e mantém os alertas de
 *   violação legal; false (piscina parada/vazia) bloqueia registos diários.
 * - Nunca apagar um encerramento passado para "limpar" o histórico: o livro
 *   sanitário (CN 14/DA) precisa dele para justificar os dias sem registos.
 *
 * @property int $id
 * @property int $pool_id
 * @property Carbon $inicio
 * @property ?Carbon $fim
 * @property string $motivo
 * @property bool $agua_em_tratamento
 * @property ?string $observacoes
 * @property-read Pool $piscina
 * @property-read string $motivo_label
 * @property-read string $descricao_periodo
 * @property-read bool $esta_vigente
 * @property-read int $dias
 * @property-read Collection<int, PoolClosureTask> $trabalhos
 *
 * @method static Builder<PoolClosure> vigenteEm(\Carbon\CarbonInterface $data)
 * @method static Builder<PoolClosure> queIntersetam(\Carbon\CarbonInterface $inicio, \Carbon\CarbonInterface $fim)
 */
class PoolClosure extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'pool_id', 'inicio', 'fim', 'motivo', 'agua_em_tratamento',
        'observacoes', 'encerrada_por', 'reaberta_por', 'reaberta_em',
    ];

    protected $casts = [
        'inicio' => 'date',
        'fim' => 'date',
        'agua_em_tratamento' => 'boolean',
        'reaberta_em' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function (PoolClosure $encerramento): void {
            $encerramento->invalidarCaches();
        });

        static::deleted(function (PoolClosure $encerramento): void {
            $encerramento->invalidarCaches();
        });
    }

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function encerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encerrada_por');
    }

    public function reabertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reaberta_por');
    }

    public function trabalhos(): HasMany
    {
        return $this->hasMany(PoolClosureTask::class, 'pool_closure_id')->orderBy('ordem');
    }

    /** Encerramentos que intersetam o dia indicado ('fim' inclusivo). */
    public function scopeVigenteEm(Builder $query, CarbonInterface $data): Builder
    {
        $dia = $data->copy()->startOfDay();

        return $query
            ->whereDate('inicio', '<=', $dia)
            ->where(function (Builder $q) use ($dia): void {
                $q->whereNull('fim')->orWhereDate('fim', '>=', $dia);
            });
    }

    /** Encerramentos que intersetam a janela [inicio, fim]. */
    public function scopeQueIntersetam(Builder $query, CarbonInterface $inicio, CarbonInterface $fim): Builder
    {
        return $query
            ->whereDate('inicio', '<=', $fim->copy()->endOfDay())
            ->where(function (Builder $q) use ($inicio): void {
                $q->whereNull('fim')->orWhereDate('fim', '>=', $inicio->copy()->startOfDay());
            });
    }

    public function cobreDia(CarbonInterface $data): bool
    {
        $dia = $data->copy()->startOfDay();

        if ($this->inicio->copy()->startOfDay()->greaterThan($dia)) {
            return false;
        }

        return $this->fim === null || $this->fim->copy()->startOfDay()->greaterThanOrEqualTo($dia);
    }

    public function getEstaVigenteAttribute(): bool
    {
        return $this->cobreDia(Carbon::now());
    }

    public function getMotivoLabelAttribute(): string
    {
        return MotivoEncerramento::label($this->motivo);
    }

    /** Número de dias encerrados (até hoje, se ainda estiver em aberto). */
    public function getDiasAttribute(): int
    {
        $fim = $this->fim ?? Carbon::now();

        return (int) $this->inicio->copy()->startOfDay()->diffInDays($fim->copy()->startOfDay()) + 1;
    }

    public function getDescricaoPeriodoAttribute(): string
    {
        $inicio = $this->inicio->format('d/m/Y');

        if ($this->fim === null) {
            return "desde {$inicio}";
        }

        return "de {$inicio} a ".$this->fim->format('d/m/Y');
    }

    private function invalidarCaches(): void
    {
        $cache = app(CacheService::class);
        $cache->invalidateClosures();
        $cache->invalidatePoolData();
        $cache->invalidateAllAlerts();
        $cache->invalidateGraphCache($this->pool_id);
    }
}
