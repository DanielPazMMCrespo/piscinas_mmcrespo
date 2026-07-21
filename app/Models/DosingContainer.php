<?php declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Bidão de reagente (cloro ou pH-) do controlador de uma piscina.
 * O nível é uma estimativa auditável: desce com a dosagem reportada pelo
 * controlador e repõe-se com um reabastecimento manual.
 */
class DosingContainer extends Model
{
    public const TIPO_CLORO = 'cloro';
    public const TIPO_PH_MENOS = 'ph_menos';

    public const TIPOS = [
        self::TIPO_CLORO => 'Cloro',
        self::TIPO_PH_MENOS => 'pH-',
    ];

    protected $fillable = [
        'pool_id', 'tipo', 'capacidade_ml', 'restante_ml',
        'alerta_percent', 'reabastecido_em', 'reabastecido_por', 'alerta_notificado_em',
    ];

    protected $casts = [
        'capacidade_ml' => 'integer',
        'restante_ml' => 'decimal:2',
        'alerta_percent' => 'integer',
        'reabastecido_em' => 'datetime',
        'alerta_notificado_em' => 'datetime',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function reabastecidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reabastecido_por');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DosingContainerLog::class);
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** Percentagem de nível (0-100) ou null se a capacidade não estiver definida. */
    public function percentagem(): ?float
    {
        if ($this->capacidade_ml === null || $this->capacidade_ml <= 0) {
            return null;
        }

        return round(min(100, max(0, (float) $this->restante_ml / $this->capacidade_ml * 100)), 1);
    }

    /** Estado visual do nível: 'ok' | 'aviso' | 'critico' | 'desconhecido'. */
    public function nivel(): string
    {
        $pct = $this->percentagem();

        if ($pct === null) {
            return 'desconhecido';
        }

        if ($pct < $this->alerta_percent) {
            return 'critico';
        }

        if ($pct < $this->alerta_percent * 2) {
            return 'aviso';
        }

        return 'ok';
    }

    public function estaBaixo(): bool
    {
        $pct = $this->percentagem();

        return $pct !== null && $pct < $this->alerta_percent;
    }

    /**
     * Desconta volume doseado. Nunca desce abaixo de zero (o bidão físico não
     * tem volume negativo; qualquer excesso significa que já estava vazio ou a
     * estimativa derivou — corrige-se no próximo reabastecimento). Regista o
     * movimento e devolve o volume efetivamente descontado.
     */
    public function consumir(float $ml, string $origem = 'controlador'): float
    {
        if ($ml <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($ml, $origem): float {
            $fresco = self::query()->lockForUpdate()->find($this->id);

            $disponivel = (float) $fresco->restante_ml;
            $descontado = min($ml, $disponivel);

            if ($descontado <= 0) {
                return 0.0;
            }

            $fresco->restante_ml = $disponivel - $descontado;
            $fresco->save();

            $fresco->logs()->create([
                'tipo_movimento' => 'consumo',
                'quantidade_ml' => -$descontado,
                'restante_apos_ml' => $fresco->restante_ml,
                'origem' => $origem,
                'registado_em' => now(),
            ]);

            $this->restante_ml = $fresco->restante_ml;

            return $descontado;
        });
    }

    /** Repõe o nível do bidão (reabastecimento manual) e limpa o alerta. */
    public function reabastecer(float $ml, ?int $userId = null, ?string $nota = null): void
    {
        DB::transaction(function () use ($ml, $userId, $nota): void {
            $this->restante_ml = round($ml, 2);
            $this->reabastecido_em = now();
            $this->reabastecido_por = $userId;
            $this->alerta_notificado_em = null;
            $this->save();

            $this->logs()->create([
                'tipo_movimento' => 'reabastecimento',
                'quantidade_ml' => round($ml, 2),
                'restante_apos_ml' => $this->restante_ml,
                'origem' => 'manual',
                'user_id' => $userId,
                'nota' => $nota,
                'registado_em' => now(),
            ]);
        });
    }
}
