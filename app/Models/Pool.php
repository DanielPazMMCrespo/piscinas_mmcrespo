<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CacheService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pool extends Model
{
    /** Piscina a operar normalmente. */
    public const ESTADO_ATIVA = 'ativa';

    /** Encerramento temporário datado (PoolClosure vigente). */
    public const ESTADO_ENCERRADA = 'encerrada';

    /** active = false — desativada estruturalmente, não é um encerramento. */
    public const ESTADO_DESATIVADA = 'desativada';

    use HasFactory;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = ['installation_id', 'name', 'type', 'temp_min', 'temp_max', 'orp_min', 'orp_max', 'volume', 'active', 'ordem_bombas', 'ordem_filtros'];

    protected $casts = [
        'active' => 'boolean',
        'temp_min' => 'decimal:1',
        'temp_max' => 'decimal:1',
        'orp_min' => 'integer',
        'orp_max' => 'integer',
        'volume' => 'decimal:2',
        'ordem_bombas' => 'integer',
        'ordem_filtros' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Pool $pool): void {
            // filter_checks.pool_id é RESTRICT (sem cascade na BD) — tem de ser
            // apagado à mão, senão o delete rebenta com FK violation em PostgreSQL.
            $pool->verificacoesFiltro()->delete();
            DB::table('tap_alerts')->where('pool_id', $pool->id)->delete();
            DB::table('sensor_readings')->where('pool_id', $pool->id)->delete();
            $pool->bidoesDosagem()->delete();
            app(CacheService::class)->invalidatePoolData();
            app(CacheService::class)->invalidateGraphCache($pool->id);
        });

        static::saved(function (Pool $pool): void {
            app(CacheService::class)->invalidatePoolData();
            app(CacheService::class)->invalidateGraphCache($pool->id);
            Cache::forget('pool_nomes');
        });

        static::deleted(function (): void {
            Cache::forget('pool_nomes');
        });
    }

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function nomeCompleto(string $separator = ' '): string
    {
        $instalacaoNome = $this->instalacao?->name;
        if (! $instalacaoNome) {
            return $this->name;
        }

        if ($instalacaoNome === $this->name) {
            return $this->name;
        }

        return "{$instalacaoNome}{$separator}{$this->name}";
    }

    public function getNomeCompletoAttribute(): string
    {
        return $this->nomeCompleto();
    }

    public function registosDiarios(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    public function verificacoesFiltro(): HasMany
    {
        return $this->hasMany(FilterCheck::class);
    }

    public function bidoesDosagem(): HasMany
    {
        return $this->hasMany(DosingContainer::class);
    }

    /** Nadadores-salvadores atribuídos. Usada por nome em whereHas('users', ...). */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_pools');
    }

    public function encerramentos(): HasMany
    {
        return $this->hasMany(PoolClosure::class)->orderByDesc('inicio');
    }

    /**
     * Encerramento que cobre o dia indicado (hoje, por omissão).
     *
     * Se a relação 'encerramentos' já estiver carregada, resolve em memória —
     * os ecrãs que listam piscinas fazem eager loading e não devem disparar
     * uma query por piscina.
     */
    public function encerramentoEm(?CarbonInterface $data = null): ?PoolClosure
    {
        $dia = ($data ?? Carbon::now())->copy()->startOfDay();

        if ($this->relationLoaded('encerramentos')) {
            return $this->encerramentos->first(fn (PoolClosure $e) => $e->cobreDia($dia));
        }

        return $this->encerramentos()->vigenteEm($dia)->first();
    }

    public function estaEncerradaEm(?CarbonInterface $data = null): bool
    {
        return $this->encerramentoEm($data) !== null;
    }

    public function getEstadoOperacionalAttribute(): string
    {
        if (! $this->active) {
            return self::ESTADO_DESATIVADA;
        }

        return $this->estaEncerradaEm() ? self::ESTADO_ENCERRADA : self::ESTADO_ATIVA;
    }

    /**
     * Piscinas a operar hoje: existentes no sistema E sem encerramento vigente.
     *
     * Substitui o ->where('active', true) na maioria dos call sites. Atenção:
     * NÃO usar onde as piscinas encerradas têm de continuar listadas — Relatório
     * PDF (é sobre elas que se declara o encerramento), Esquema do circuito e
     * atribuição de piscinas a um nadador-salvador.
     */
    public function scopeOperacionais(Builder $query): Builder
    {
        return $query->where('active', true)->naoEncerradasEm(Carbon::now());
    }

    public function scopeNaoEncerradasEm(Builder $query, CarbonInterface $data): Builder
    {
        return $query->whereDoesntHave('encerramentos', fn ($q) => $q->vigenteEm($data));
    }

    public function scopeEncerradasEm(Builder $query, CarbonInterface $data): Builder
    {
        return $query->whereHas('encerramentos', fn ($q) => $q->vigenteEm($data));
    }
}
