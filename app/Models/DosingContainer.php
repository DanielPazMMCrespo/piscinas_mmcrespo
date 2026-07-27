<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\UserRole;
use App\Notifications\DosingContainerLowAlert;
use App\Services\HannaCloudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
     * Notifica Admin/Técnico uma vez por episódio de nível baixo. Fonte única
     * chamada tanto pelo sync do controlador como pelo ajuste manual, para a
     * regra de "está baixo" não divergir entre os dois caminhos. O episódio
     * reinicia quando `reabastecer()` limpa `alerta_notificado_em`.
     */
    public function notificarSeBaixo(): void
    {
        if (! $this->estaBaixo() || $this->alerta_notificado_em !== null) {
            return;
        }

        $this->update(['alerta_notificado_em' => now()]);
        $destinatarios = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();
        Notification::send($destinatarios, new DosingContainerLowAlert($this));
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
    public function reabastecer(float $ml, ?int $userId = null, ?string $nota = null, ?Carbon $timestamp = null): void
    {
        DB::transaction(function () use ($ml, $userId, $nota, $timestamp): void {
            $this->restante_ml = round($ml, 2);
            $this->reabastecido_em = $timestamp ?? now();
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
                'registado_em' => $timestamp ?? now(),
            ]);
        });

        // Run retroactive consumption catch-up outside the transaction to prevent database lockups during API HTTP requests
        $this->recalcularConsumoAposReabastecimento();
    }

    /** Recalcula retroativamente o consumo de químicos desde a data de reabastecimento. */
    public function recalcularConsumoAposReabastecimento(): void
    {
        if ($this->reabastecido_em === null) {
            return;
        }

        $device = HannaDevice::where('pool_id', $this->pool_id)->first();
        if (! $device) {
            return;
        }

        $syncTime = $device->dose_sincronizada_ate;
        if ($syncTime === null || $this->reabastecido_em->gte($syncTime)) {
            return;
        }

        try {
            $hanna = app(HannaCloudService::class);
            $email = config('services.hanna.email');
            $password = config('services.hanna.password');

            if (empty($email) || empty($password)) {
                return;
            }

            $hanna->authenticate($email, $password);

            $leituras = $hanna->getHistoryReadings(
                $device->hanna_device_id,
                $this->reabastecido_em,
                $syncTime
            );

            $doseMl = 0.0;
            foreach ($leituras as $l) {
                if ($l['dt'] === null) {
                    continue;
                }

                $dt = Carbon::parse($l['dt']);
                if ($dt->gt($this->reabastecido_em) && $dt->lte($syncTime)) {
                    if ($this->tipo === self::TIPO_CLORO) {
                        $doseMl += (float) ($l['dose_cloro_ml'] ?? 0);
                    } else {
                        $doseMl += (float) ($l['dose_ph_ml'] ?? 0);
                    }
                }
            }

            if ($doseMl > 0) {
                DB::transaction(function () use ($doseMl) {
                    $this->refresh();
                    $this->restante_ml = max(0.0, round($this->restante_ml - $doseMl, 2));
                    $this->save();

                    $this->logs()->create([
                        'tipo_movimento' => 'consumo_sonda',
                        'quantidade_ml' => round($doseMl, 2),
                        'restante_apos_ml' => $this->restante_ml,
                        'origem' => 'sonda',
                        'nota' => 'Consumo recalculado retroativamente após reabastecimento',
                        'registado_em' => now(),
                    ]);
                });
            }
        } catch (\Throwable $e) {
            Log::warning("Erro ao recalcular consumo após reabastecimento do bidão {$this->id}: ".$e->getMessage());
        }
    }
}
