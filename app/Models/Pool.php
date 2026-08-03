<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pool extends Model
{
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_pools');
    }
}
