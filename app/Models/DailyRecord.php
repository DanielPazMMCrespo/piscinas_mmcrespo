<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     *   estado: 'verde' (conforme) | 'amarelo' (perto do limite) | 'vermelho' (fora) | 'neutro' (vazio/sem base)
     */
    public static function avaliarConformidade(string $campo, mixed $valor, ?Pool $piscina = null): array
    {
        if ($valor === null || $valor === '') {
            return ['estado' => 'neutro', 'mensagem' => ''];
        }

        $meta = self::METRICAS[$campo] ?? null;
        if ($meta === null) {
            return ['estado' => 'neutro', 'mensagem' => ''];
        }

        $valor = (float) $valor;
        $min = $meta['min'];
        $max = $meta['max'];
        $label = $meta['label'];
        $unidade = $meta['unidade'] !== '' ? ' '.$meta['unidade'] : '';

        // A temperatura não tem limite legal fixo — usa a gama própria da piscina.
        if ($campo === 'temperatura') {
            if (! $piscina || $piscina->temp_min === null || $piscina->temp_max === null) {
                return ['estado' => 'neutro', 'mensagem' => 'Sem gama definida para esta piscina'];
            }
            $min = (float) $piscina->temp_min;
            $max = (float) $piscina->temp_max;
        }

        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

        if ($min !== null && $valor < (float) $min) {
            return ['estado' => 'vermelho', 'mensagem' => $label.' '.$fmt($valor).$unidade.' — abaixo do mínimo ('.$fmt((float) $min).')'];
        }

        if ($max !== null && $valor > (float) $max) {
            return ['estado' => 'vermelho', 'mensagem' => $label.' '.$fmt($valor).$unidade.' — acima do máximo ('.$fmt((float) $max).')'];
        }

        // Margem de aviso: a 10% do intervalo de cada fronteira (ou de uma só, se
        // o limite for unilateral). Sinaliza que o parâmetro se está a aproximar do limite.
        $referencia = ($min !== null && $max !== null)
            ? (float) $max - (float) $min
            : (float) ($max ?? $min);
        $margem = abs($referencia) * 0.10;

        if ($margem > 0.0) {
            if ($min !== null && $valor < (float) $min + $margem) {
                return ['estado' => 'amarelo', 'mensagem' => $label.' '.$fmt($valor).$unidade.' — perto do mínimo ('.$fmt((float) $min).')'];
            }
            if ($max !== null && $valor > (float) $max - $margem) {
                return ['estado' => 'amarelo', 'mensagem' => $label.' '.$fmt($valor).$unidade.' — perto do máximo ('.$fmt((float) $max).')'];
            }
        }

        return ['estado' => 'verde', 'mensagem' => '✓ Conforme'];
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
        // Sem leitura combinada não há base para alertar.
        if ($this->cloro_combinado === null) {
            return true;
        }

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
}
