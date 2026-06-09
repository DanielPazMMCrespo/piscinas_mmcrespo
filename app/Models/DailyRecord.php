<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyRecord extends Model
{
    /**
     * Limites regulamentares CN 14/DA (DGS 2009) para piscinas públicas.
     * Fonte única de verdade — usados em validação, tabelas e dashboard.
     */
    public const PH_MIN = 6.9;
    public const PH_MAX = 8.0;
    public const CLORO_LIVRE_MIN = 0.5;
    public const CLORO_LIVRE_MAX = 2.0;
    public const CLORO_COMBINADO_MAX = 0.6;

    protected $fillable = [
        'pool_id', 'user_id', 'registado_em',
        'cloro_livre', 'cloro_total',
        'ph', 'temperatura', 'transparencia',
        'caleira_feita', 'renovacao_agua',
        'bomba_com_bolhas', 'pressao_filtro', 'estado_valvulas_filtro',
        'observacoes', 'e_correcao',
        'corrige_registo_id', 'razao_correcao',
        // Leituras do Nadador-Salvador
        'ns_foto', 'ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura',
        // Filtros
        'filtro_faz_retrolavagem',
        'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal',
    ];

    protected $casts = [
        'registado_em'           => 'datetime',
        'cloro_livre'            => 'decimal:2',
        'cloro_total'            => 'decimal:2',
        'ph'                     => 'decimal:2',
        'temperatura'            => 'decimal:1',
        'pressao_filtro'         => 'decimal:2',
        'ns_ph'                  => 'decimal:2',
        'ns_cloro_livre'         => 'decimal:2',
        'ns_cloro_total'         => 'decimal:2',
        'ns_temperatura'         => 'decimal:2',
        'caleira_feita'          => 'boolean',
        'renovacao_agua'         => 'boolean',
        'bomba_com_bolhas'       => 'boolean',
        'filtro_faz_retrolavagem'=> 'boolean',
        'e_correcao'             => 'boolean',
    ];

    protected function cloroCombinado(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->cloro_total - (float) $this->cloro_livre, 2)
        );
    }

    public function phConforme(): bool
    {
        return $this->ph >= self::PH_MIN && $this->ph <= self::PH_MAX;
    }

    public function cloroLivreConforme(): bool
    {
        return $this->cloro_livre >= self::CLORO_LIVRE_MIN && $this->cloro_livre <= self::CLORO_LIVRE_MAX;
    }

    public function cloroCombinadoConforme(): bool
    {
        return $this->cloro_combinado <= self::CLORO_COMBINADO_MAX;
    }

    /**
     * Temperatura conforme os limites próprios da piscina (Pool::temp_min/temp_max).
     * Devolve true se não houver piscina/limites definidos (não há base para alertar).
     */
    public function temperaturaConforme(): bool
    {
        if (! $this->piscina || $this->piscina->temp_min === null || $this->piscina->temp_max === null) {
            return true;
        }

        return $this->temperatura >= $this->piscina->temp_min
            && $this->temperatura <= $this->piscina->temp_max;
    }

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adicoes(): HasMany
    {
        return $this->hasMany(RecordAddition::class);
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(RecordPhoto::class);
    }

    public function registoOriginal(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'corrige_registo_id');
    }

    public function correcoes(): HasMany
    {
        return $this->hasMany(DailyRecord::class, 'corrige_registo_id');
    }
}
