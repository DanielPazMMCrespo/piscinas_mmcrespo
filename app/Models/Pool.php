<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            \Illuminate\Support\Facades\DB::table('tap_alerts')->where('pool_id', $pool->id)->delete();
            \Illuminate\Support\Facades\DB::table('sensor_readings')->where('pool_id', $pool->id)->delete();
            app(\App\Services\CacheService::class)->invalidatePoolData();
            app(\App\Services\CacheService::class)->invalidateGraphCache($pool->id);
        });

        static::saved(function (Pool $pool): void {
            app(\App\Services\CacheService::class)->invalidatePoolData();
            app(\App\Services\CacheService::class)->invalidateGraphCache($pool->id);
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

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_pools');
    }
}
