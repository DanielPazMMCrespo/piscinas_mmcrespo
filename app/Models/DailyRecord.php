<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DailyRecord extends Model
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

    /**
     * Limites regulamentares CN 14/DA (DGS 2009) para piscinas públicas.
     * Fonte única de verdade — usados em validação, tabelas e dashboard.
     */
    public const PH_MIN = 6.9;

    public const PH_MAX = 8.0;

    public const CLORO_LIVRE_MIN = 0.5;

    public const CLORO_LIVRE_MAX = 2.0;

    public const CLORO_COMBINADO_MAX = 0.6;

    /**
     * Limite máximo de turbidez (FNU). A CN 14/DA exige água límpida com o fundo
     * perfeitamente visível; usa-se 5 FNU como limiar operacional de alerta.
     */
    public const TRANSPARENCIA_MAX = 5.0;

    /**
     * Mapa central das métricas com limites legais — fonte única para o semáforo
     * de conformidade do formulário, validações e relatórios.
     * `min`/`max` a null significam "sem limite fixo" (a temperatura usa os
     * limites próprios da piscina — Pool::temp_min/temp_max).
     */
    public const METRICAS = [
        'ph' => ['label' => 'pH', 'min' => self::PH_MIN, 'max' => self::PH_MAX, 'unidade' => ''],
        'cloro_livre' => ['label' => 'Cloro livre', 'min' => self::CLORO_LIVRE_MIN, 'max' => self::CLORO_LIVRE_MAX, 'unidade' => 'mg/L'],
        'cloro_combinado' => ['label' => 'Cloro combinado', 'min' => null, 'max' => self::CLORO_COMBINADO_MAX, 'unidade' => 'mg/L'],
        'transparencia' => ['label' => 'Turbidez', 'min' => null, 'max' => self::TRANSPARENCIA_MAX, 'unidade' => 'FNU'],
        'temperatura' => ['label' => 'Temperatura', 'min' => null, 'max' => null, 'unidade' => 'ºC'],
    ];

    protected $appends = ['cloro_combinado'];

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
        // Caminho da água
        'bomba_ferrada', 'bomba_foto', 'contador_valor', 'contador_foto', 'agua_modo',
        'tanque_ok', 'tanque_observacoes', 'tanque_foto',
        // Fotos das nossas análises (até 5)
        'analises_fotos',
    ];

    protected $casts = [
        'registado_em' => 'datetime',
        'cloro_livre' => 'decimal:2',
        'cloro_total' => 'decimal:2',
        'ph' => 'decimal:2',
        'temperatura' => 'decimal:1',
        'pressao_filtro' => 'decimal:2',
        'ns_ph' => 'decimal:2',
        'ns_cloro_livre' => 'decimal:2',
        'ns_cloro_total' => 'decimal:2',
        'ns_temperatura' => 'decimal:2',
        'transparencia' => 'decimal:2',
        'contador_valor' => 'decimal:2',
        'caleira_feita' => 'boolean',
        'renovacao_agua' => 'boolean',
        'bomba_com_bolhas' => 'boolean',
        'bomba_ferrada' => 'boolean',
        'tanque_ok' => 'boolean',
        'filtro_faz_retrolavagem' => 'boolean',
        'e_correcao' => 'boolean',
        'analises_fotos' => 'array',
    ];

    protected function cloroCombinado(): Attribute
    {
        // Sem ambas as leituras não há combinado calculável — devolve null em vez
        // de um valor falso (ex: cloro_total null daria 0 - cloro_livre, negativo).
        return Attribute::make(
            get: fn () => ($this->cloro_total === null || $this->cloro_livre === null)
                ? null
                : round((float) $this->cloro_total - (float) $this->cloro_livre, 2)
        );
    }

    /**
     * Avalia um valor contra os limites legais (semáforo do formulário em tempo real).
     * Fonte única usada pelos hints reativos do DailyRecordResource.
     *
     * @return array{estado: string, mensagem: string}
     *   estado: \App\Enums\EstadoConformidade (verde|amarelo|vermelho|neutro)
     */
    public static function avaliarConformidade(string $campo, mixed $valor, ?Pool $piscina = null): array
    {
        if ($valor === null || $valor === '') {
            return ['estado' => \App\Enums\EstadoConformidade::NEUTRO, 'mensagem' => ''];
        }

        $meta = self::METRICAS[$campo] ?? null;
        if ($meta === null) {
            return ['estado' => \App\Enums\EstadoConformidade::NEUTRO, 'mensagem' => ''];
        }

        $valor = (float) $valor;
        $min = $meta['min'];
        $max = $meta['max'];
        $label = $meta['label'];
        $unidade = $meta['unidade'] !== '' ? ' '.$meta['unidade'] : '';

        if ($campo === 'temperatura') {
            if (! $piscina || $piscina->temp_min === null || $piscina->temp_max === null) {
                return ['estado' => \App\Enums\EstadoConformidade::NEUTRO, 'mensagem' => 'Sem gama definida para esta piscina'];
            }
            $min = (float) $piscina->temp_min;
            $max = (float) $piscina->temp_max;
        }

        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

        if ($min !== null && $valor < (float) $min) {
            return ['estado' => \App\Enums\EstadoConformidade::VERMELHO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — abaixo do mínimo ('.$fmt((float) $min).')'];
        }

        if ($max !== null && $valor > (float) $max) {
            return ['estado' => \App\Enums\EstadoConformidade::VERMELHO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — acima do máximo ('.$fmt((float) $max).')'];
        }

        $referencia = ($min !== null && $max !== null)
            ? (float) $max - (float) $min
            : (float) ($max ?? $min);
        $margem = abs($referencia) * 0.10;

        if ($margem > 0.0) {
            if ($min !== null && $valor < (float) $min + $margem) {
                return ['estado' => \App\Enums\EstadoConformidade::AMARELO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — perto do mínimo ('.$fmt((float) $min).')'];
            }
            if ($max !== null && $valor > (float) $max - $margem) {
                return ['estado' => \App\Enums\EstadoConformidade::AMARELO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — perto do máximo ('.$fmt((float) $max).')'];
            }
        }

        return ['estado' => \App\Enums\EstadoConformidade::VERDE, 'mensagem' => '✓ Conforme'];
    }

    public function phConforme(): bool
    {
        if ($this->ph === null) {
            return true; // sem leitura não é violação
        }

        $min = app(\App\Services\SettingsService::class)->getFloat('ph_min', self::PH_MIN);
        $max = app(\App\Services\SettingsService::class)->getFloat('ph_max', self::PH_MAX);

        return (float) $this->ph >= $min && (float) $this->ph <= $max;
    }

    public function cloroLivreConforme(): bool
    {
        if ($this->cloro_livre === null) {
            return true; // sem leitura não é violação
        }

        $min = app(\App\Services\SettingsService::class)->getFloat('cloro_livre_min', self::CLORO_LIVRE_MIN);
        $max = app(\App\Services\SettingsService::class)->getFloat('cloro_livre_max', self::CLORO_LIVRE_MAX);

        return (float) $this->cloro_livre >= $min && (float) $this->cloro_livre <= $max;
    }

    public function cloroCombinadoConforme(): bool
    {
        // Sem leitura combinada não há base para alertar.
        if ($this->cloro_combinado === null) {
            return true;
        }

        $max = app(\App\Services\SettingsService::class)->getFloat('cloro_combinado_max', self::CLORO_COMBINADO_MAX);

        return $this->cloro_combinado <= $max;
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

    /**
     * @return BelongsTo
     */
    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    /**
     * @return BelongsTo
     */
    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function adicoes(): HasMany
    {
        return $this->hasMany(RecordAddition::class);
    }

    /**
     * @return HasMany
     */
    public function fotos(): HasMany
    {
        return $this->hasMany(RecordPhoto::class);
    }

    /**
     * @return HasMany
     */
    public function correcoes(): HasMany
    {
        return $this->hasMany(DailyRecord::class, 'corrige_registo_id');
    }

    /**
     * Registo original que este registo corrige (inversa de correcoes()).
     *
     * @return BelongsTo
     */
    public function registoOriginal(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'corrige_registo_id');
    }

    /**
     * Obter apenas o último registo válido de cada piscina.
     * Utiliza uma sub-query window function para performance (evita N+1 queries).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeLatestPerPool(Builder $query): Builder
    {
        return $query->fromSub(
            static::query()
                ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY pool_id ORDER BY registado_em DESC, id DESC) as rn')
                ->whereDoesntHave('correcoes'),
            'sub'
        )->where('rn', 1);
    }

    /**
     * Obter o disco de armazenamento dinamicamente.
     */
    public static function getStorageDisk(): string
    {
        $default = config('filesystems.default', 'public');
        return $default === 'local' ? 'public' : $default;
    }

    /**
     * Obter o URL de armazenamento dinamicamente, tornando caminhos locais relativos.
     */
    public static function getStorageUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $disk = self::getStorageDisk();
        $url = \Illuminate\Support\Facades\Storage::disk($disk)->url($path);

        if (str_starts_with($url, 'http://localhost') || str_starts_with($url, 'http://127.0.0.1')) {
            $parsed = parse_url($url);
            return ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        if (str_starts_with($url, 'http://') && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1')) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }
}
