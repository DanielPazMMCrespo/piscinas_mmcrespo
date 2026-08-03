<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoConformidade;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
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

    public const TRANSPARENCIA_MAX = 5.0;

    public static function getPhMin(): float
    {
        return app(SettingsService::class)->getFloat('ph_min', self::PH_MIN);
    }

    public static function getPhMax(): float
    {
        return app(SettingsService::class)->getFloat('ph_max', self::PH_MAX);
    }

    public static function getCloroLivreMin(): float
    {
        return app(SettingsService::class)->getFloat('cloro_livre_min', self::CLORO_LIVRE_MIN);
    }

    public static function getCloroLivreMax(): float
    {
        return app(SettingsService::class)->getFloat('cloro_livre_max', self::CLORO_LIVRE_MAX);
    }

    public static function getCloroCombinadoMax(): float
    {
        return app(SettingsService::class)->getFloat('cloro_combinado_max', self::CLORO_COMBINADO_MAX);
    }

    public static function getTransparenciaMax(): float
    {
        return app(SettingsService::class)->getFloat('transparencia_max', self::TRANSPARENCIA_MAX);
    }

    /**
     * Mapa central das métricas com limites legais dinâmicos — fonte única para o semáforo
     * de conformidade do formulário, validações e relatórios.
     */
    public static function getMetricas(): array
    {
        return [
            'ph' => ['label' => 'pH', 'min' => self::getPhMin(), 'max' => self::getPhMax(), 'unidade' => ''],
            'cloro_livre' => ['label' => 'Cloro livre', 'min' => self::getCloroLivreMin(), 'max' => self::getCloroLivreMax(), 'unidade' => 'mg/L'],
            'cloro_combinado' => ['label' => 'Cloro combinado', 'min' => null, 'max' => self::getCloroCombinadoMax(), 'unidade' => 'mg/L'],
            'transparencia' => ['label' => 'Turbidez', 'min' => null, 'max' => self::getTransparenciaMax(), 'unidade' => 'FNU'],
            'temperatura' => ['label' => 'Temperatura', 'min' => null, 'max' => null, 'unidade' => 'ºC'],
        ];
    }

    protected $fillable = [
        'pool_id', 'user_id', 'registado_em', 'hora_colheita',
        'cloro_livre', 'cloro_total',
        'ph', 'temperatura', 'transparencia',
        'caleira_feita', 'renovacao_agua',
        'pressao_filtro',
        'observacoes', 'e_correcao',
        'corrige_registo_id', 'razao_correcao',
        // Leituras do Nadador-Salvador
        'ns_foto', 'ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura',
        'banhistas',
        // Filtros
        'filtro_faz_retrolavagem', 'numero_lavagens_filtro',
        'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal',
        // Caminho da água
        'bomba_ferrada', 'bomba_foto', 'contador_valor', 'contador_foto', 'torneira_foto', 'agua_modo',
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
        'contador_valor' => 'decimal:2',
        'caleira_feita' => 'boolean',
        'renovacao_agua' => 'boolean',
        'bomba_ferrada' => 'boolean',
        'tanque_ok' => 'boolean',
        'filtro_faz_retrolavagem' => 'boolean',
        'numero_lavagens_filtro' => 'integer',
        'banhistas' => 'integer',
        'e_correcao' => 'boolean',
        'analises_fotos' => 'array',
    ];

    protected function cloroCombinado(): Attribute
    {
        // Sem ambas as leituras não há combinado calculável — devolve null em vez
        // de um valor falso (ex: cloro_total null daria 0 - cloro_livre, negativo).
        return Attribute::make(
            get: fn () => ($this->cloro_total_efetivo === null || $this->cloro_livre_efetivo === null)
                ? null
                : round((float) $this->cloro_total_efetivo - (float) $this->cloro_livre_efetivo, 2)
        );
    }

    protected function phEfetivo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ph ?? $this->ns_ph
        );
    }

    protected function cloroLivreEfetivo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cloro_livre ?? $this->ns_cloro_livre
        );
    }

    protected function cloroTotalEfetivo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cloro_total ?? $this->ns_cloro_total
        );
    }

    protected function temperaturaEfetivo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->temperatura ?? $this->ns_temperatura
        );
    }

    /**
     * Avalia um valor contra os limites legais (semáforo do formulário em tempo real).
     * Fonte única usada pelos hints reativos do DailyRecordResource.
     *
     * @return array{estado: string, mensagem: string}
     *                                                 estado: \App\Enums\EstadoConformidade (verde|amarelo|vermelho|neutro)
     */
    public static function avaliarConformidade(string $campo, mixed $valor, ?Pool $piscina = null): array
    {
        if ($valor === null || $valor === '') {
            return ['estado' => EstadoConformidade::NEUTRO, 'mensagem' => ''];
        }

        $campoReal = str_starts_with($campo, 'ns_') ? substr($campo, 3) : $campo;
        $meta = self::getMetricas()[$campoReal] ?? null;
        if ($meta === null) {
            return ['estado' => EstadoConformidade::NEUTRO, 'mensagem' => ''];
        }

        $valor = (float) $valor;
        $min = $meta['min'];
        $max = $meta['max'];
        $label = $meta['label'];
        $unidade = $meta['unidade'] !== '' ? ' '.$meta['unidade'] : '';

        if ($campoReal === 'temperatura') {
            if (! $piscina || $piscina->temp_min === null || $piscina->temp_max === null) {
                return ['estado' => EstadoConformidade::NEUTRO, 'mensagem' => 'Sem gama definida para esta piscina'];
            }
            $min = (float) $piscina->temp_min;
            $max = (float) $piscina->temp_max;
        }

        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

        $isBelowMin = $min !== null && $valor < (float) $min;
        $isAboveMax = $max !== null && $valor > (float) $max;

        if (! $isBelowMin && ! $isAboveMax) {
            return ['estado' => EstadoConformidade::VERDE, 'mensagem' => '✓ Conforme'];
        }

        $margemTolerancia = app(SettingsService::class)->getFloat('tolerancia_amarelo', 0.2);

        if ($isBelowMin) {
            $diff = (float) $min - $valor;
            if ($diff < $margemTolerancia) {
                return ['estado' => EstadoConformidade::AMARELO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — ligeiramente abaixo do mínimo ('.$fmt((float) $min).')'];
            }

            return ['estado' => EstadoConformidade::VERMELHO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — abaixo do mínimo ('.$fmt((float) $min).')'];
        }

        if ($isAboveMax) {
            $diff = $valor - (float) $max;
            if ($diff < $margemTolerancia) {
                return ['estado' => EstadoConformidade::AMARELO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — ligeiramente acima do máximo ('.$fmt((float) $max).')'];
            }

            return ['estado' => EstadoConformidade::VERMELHO, 'mensagem' => $label.' '.$fmt($valor).$unidade.' — acima do máximo ('.$fmt((float) $max).')'];
        }

        return ['estado' => EstadoConformidade::VERDE, 'mensagem' => '✓ Conforme'];
    }

    public function phConforme(): bool
    {
        $val = $this->ph_efetivo;
        if ($val === null) {
            return true; // sem leitura não é violação
        }

        return (float) $val >= self::getPhMin() && (float) $val <= self::getPhMax();
    }

    public function cloroLivreConforme(): bool
    {
        $val = $this->cloro_livre_efetivo;
        if ($val === null) {
            return true; // sem leitura não é violação
        }

        return (float) $val >= self::getCloroLivreMin() && (float) $val <= self::getCloroLivreMax();
    }

    public function cloroCombinadoConforme(): bool
    {
        // Sem leitura combinada não há base para alertar.
        if ($this->cloro_combinado === null) {
            return true;
        }

        return $this->cloro_combinado <= self::getCloroCombinadoMax();
    }

    /**
     * Temperatura conforme os limites próprios da piscina (Pool::temp_min/temp_max).
     * Devolve true se não houver piscina/limites definidos (não há base para alertar).
     */
    public function temperaturaConforme(): bool
    {
        $val = $this->temperatura_efetivo;
        if ($val === null || ! $this->piscina || $this->piscina->temp_min === null || $this->piscina->temp_max === null) {
            return true;
        }

        return $val >= $this->piscina->temp_min
            && $val <= $this->piscina->temp_max;
    }

    /**
     * Fonte única de deteção de violações (sino de notificações + Kanban de alertas).
     *
     * @return array<int, array{parametro: string, mensagem: string}>
     */
    public function listarViolacoes(): array
    {
        $settings = app(SettingsService::class);
        $fmt = static fn (float $v, int $casas = 2): string => number_format($v, $casas, ',', '');
        $violacoes = [];

        if ($this->ph_efetivo !== null && ! $this->phConforme()) {
            $ph = (float) $this->ph_efetivo;
            $phMin = $settings->getFloat('ph_min', self::PH_MIN);
            $phMax = $settings->getFloat('ph_max', self::PH_MAX);
            $violacoes[] = [
                'parametro' => 'ph',
                'mensagem' => $ph < $phMin
                    ? 'pH '.$fmt($ph).' abaixo do mínimo ('.$fmt($phMin, 1).')'
                    : 'pH '.$fmt($ph).' acima do máximo ('.$fmt($phMax, 1).')',
            ];
        }

        if ($this->cloro_livre_efetivo !== null && ! $this->cloroLivreConforme()) {
            $cl = (float) $this->cloro_livre_efetivo;
            $clMin = $settings->getFloat('cloro_livre_min', self::CLORO_LIVRE_MIN);
            $clMax = $settings->getFloat('cloro_livre_max', self::CLORO_LIVRE_MAX);
            $violacoes[] = [
                'parametro' => 'cloro_livre',
                'mensagem' => $cl < $clMin
                    ? 'cloro livre '.$fmt($cl).' mg/L abaixo do mínimo ('.$fmt($clMin, 1).')'
                    : 'cloro livre '.$fmt($cl).' mg/L acima do máximo ('.$fmt($clMax, 1).')',
            ];
        }

        if ($this->cloro_total_efetivo !== null && $this->cloro_livre_efetivo !== null && ! $this->cloroCombinadoConforme()) {
            $clCombMax = $settings->getFloat('cloro_combinado_max', self::CLORO_COMBINADO_MAX);
            $violacoes[] = [
                'parametro' => 'cloro_combinado',
                'mensagem' => 'cloro combinado '.$fmt((float) $this->cloro_combinado)
                    .' mg/L acima do máximo ('.$fmt($clCombMax, 1).')',
            ];
        }

        if ($this->temperatura_efetivo !== null && ! $this->temperaturaConforme() && $this->piscina) {
            $temp = (float) $this->temperatura_efetivo;
            $violacoes[] = [
                'parametro' => 'temperatura',
                'mensagem' => $temp < (float) $this->piscina->temp_min
                    ? 'temperatura '.$fmt($temp, 1).' °C abaixo do mínimo ('.$fmt((float) $this->piscina->temp_min, 1).')'
                    : 'temperatura '.$fmt($temp, 1).' °C acima do máximo ('.$fmt((float) $this->piscina->temp_max, 1).')',
            ];
        }

        // A turbidez é um parâmetro legal CN 14/DA e não estava a ser avaliada
        // em sítio nenhum (no PDF saía "Conforme" fixo para três das piscinas).
        if ($this->transparencia !== null) {
            $turbidezMax = $settings->getFloat('transparencia_max', self::TRANSPARENCIA_MAX);

            if ((float) $this->transparencia > $turbidezMax) {
                $violacoes[] = [
                    'parametro' => 'transparencia',
                    'mensagem' => 'turbidez '.$fmt((float) $this->transparencia, 1)
                        .' FNU acima do máximo ('.$fmt($turbidezMax, 1).')',
                ];
            }
        }

        return $violacoes;
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

    public function correcoes(): HasMany
    {
        return $this->hasMany(DailyRecord::class, 'corrige_registo_id');
    }

    /**
     * Registo original que este registo corrige (inversa de correcoes()).
     */
    public function registoOriginal(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'corrige_registo_id');
    }

    /**
     * Obter apenas o último registo válido de cada piscina.
     * Utiliza uma sub-query window function para performance (evita N+1 queries).
     *
     * @param  int|null  $dias  Limita a janela analisada (a window function varre a
     *                          tabela inteira se não for limitada — custo cresce com o histórico)
     */
    public function scopeLatestPerPool(Builder $query, ?int $dias = null): Builder
    {
        return $query->fromSub(
            static::query()
                ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY pool_id ORDER BY registado_em DESC, id DESC) as rn')
                ->when($dias !== null, fn (Builder $q): Builder => $q->where('registado_em', '>=', now()->subDays($dias)))
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
        $url = Storage::disk($disk)->url($path);

        if (str_starts_with($url, 'http://localhost') || str_starts_with($url, 'http://127.0.0.1')) {
            $parsed = parse_url($url);

            return ($parsed['path'] ?? '').(isset($parsed['query']) ? '?'.$parsed['query'] : '');
        }

        if (str_starts_with($url, 'http://') && ! str_contains($url, 'localhost') && ! str_contains($url, '127.0.0.1')) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }
}
