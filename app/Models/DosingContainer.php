<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\UserRole;
use App\Notifications\DosingContainerLowAlert;
use App\Services\HannaCloudService;
use App\Services\StockService;
use App\Support\JanelaSilencio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Bidão de reagente (cloro ou pH-) do controlador de uma piscina.
 * O nível é uma estimativa auditável: desce com a dosagem reportada pelo
 * controlador e repõe-se com um reabastecimento manual.
 */
class DosingContainer extends Model
{
    use LogsActivity;

    public const TIPO_CLORO = 'cloro';

    public const TIPO_PH_MENOS = 'ph_menos';

    public const TIPOS = [
        self::TIPO_CLORO => 'Cloro',
        self::TIPO_PH_MENOS => 'pH-',
    ];

    protected $fillable = [
        'pool_id', 'product_id', 'tipo', 'capacidade_ml', 'restante_ml',
        'alerta_percent', 'reabastecido_em', 'reabastecido_por', 'alerta_notificado_em',
    ];

    protected $casts = [
        'capacidade_ml' => 'integer',
        'restante_ml' => 'decimal:2',
        'alerta_percent' => 'integer',
        'reabastecido_em' => 'datetime',
        'alerta_notificado_em' => 'datetime',
    ];

    /**
     * Só configuração. `restante_ml` desce a cada sync da Hanna (15 min) e
     * inundaria o trilho de auditoria — o consumo real vive em
     * `dosing_container_logs`, espelhado no activity log pelo observer.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['pool_id', 'product_id', 'tipo', 'capacidade_ml', 'alerta_percent'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

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
     * Consumo médio diário em ml com base nos logs de consumo dos últimos $dias.
     * Devolve null se não houver dados de consumo suficientes.
     */
    public function consumoMedioDiarioMl(int $dias = 3): ?float
    {
        $dias = max(1, $dias);
        $desde = Carbon::now()->subDays($dias);

        $logs = $this->relationLoaded('logs')
            ? $this->logs->where('tipo_movimento', 'consumo')->where('registado_em', '>=', $desde)
            : $this->logs()->where('tipo_movimento', 'consumo')->where('registado_em', '>=', $desde)->get();

        if ($logs->isEmpty()) {
            return null;
        }

        $totalConsumidoMl = abs((float) $logs->sum('quantidade_ml'));
        if ($totalConsumidoMl <= 0) {
            return null;
        }

        // Dias com consumo registado dentro da janela
        $diasComRegisto = $logs->map(fn ($l) => Carbon::parse($l->registado_em)->toDateString())->unique()->count();
        $diasReais = max(1, min($dias, $diasComRegisto));

        return round($totalConsumidoMl / $diasReais, 2);
    }

    /**
     * Estimativa de horas de autonomia restante com base no consumo médio diário.
     */
    public function horasAutonomia(int $diasJanela = 3): ?float
    {
        $consumoDiario = $this->consumoMedioDiarioMl($diasJanela);
        if ($consumoDiario === null || $consumoDiario <= 0) {
            return null;
        }

        $restante = max(0.0, (float) $this->restante_ml);
        $diasRestantes = $restante / $consumoDiario;

        return round($diasRestantes * 24, 1);
    }

    /**
     * Data e hora estimada para esgotamento do bidão.
     */
    public function previsaoEsgotamento(int $diasJanela = 3): ?Carbon
    {
        $horas = $this->horasAutonomia($diasJanela);
        if ($horas === null) {
            return null;
        }

        return Carbon::now()->addHours($horas);
    }

    /**
     * Indica se o bidão está previsto esgotar durante o próximo fim de semana
     * ou nas próximas 48 horas.
     */
    public function esgotaNoFimDeSemana(int $diasJanela = 3): bool
    {
        $previsao = $this->previsaoEsgotamento($diasJanela);
        if ($previsao === null) {
            return false;
        }

        $hoje = Carbon::now();
        $horas = $this->horasAutonomia($diasJanela);

        if ($horas !== null && $horas <= 48 && in_array($hoje->dayOfWeek, [Carbon::THURSDAY, Carbon::FRIDAY, Carbon::SATURDAY], true)) {
            return true;
        }

        return $previsao->isWeekend();
    }

    /**
     * Estado preditivo de autonomia: 'critico' (< 24h) | 'aviso' (< 48h ou fim de semana) | 'ok' | 'sem_dados'.
     */
    public function statusAutonomia(int $diasJanela = 3): string
    {
        $horas = $this->horasAutonomia($diasJanela);
        if ($horas === null) {
            return 'sem_dados';
        }

        if ($horas < 24 || (float) $this->restante_ml <= 0) {
            return 'critico';
        }

        if ($horas < 48 || $this->esgotaNoFimDeSemana($diasJanela)) {
            return 'aviso';
        }

        return 'ok';
    }

    /**
     * Descrição amigável de autonomia para UI e badges.
     */
    public function descricaoAutonomia(int $diasJanela = 3): string
    {
        $horas = $this->horasAutonomia($diasJanela);
        if ($horas === null) {
            $pct = $this->percentagem();

            return $pct !== null ? "{$pct}%" : '—';
        }

        if ($horas < 24) {
            return sprintf('~%dh (Esgota hoje/amanhã)', (int) ceil($horas));
        }

        $dias = round($horas / 24, 1);
        if ($dias < 3) {
            return sprintf('~%dh (~%.1f dias)', (int) ceil($horas), $dias);
        }

        return sprintf('~%d dias', (int) round($dias));
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

        if (app(JanelaSilencio::class)->ativa()) {
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

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Debita do stock da instalação o químico que entrou fisicamente no bidão.
     * Best-effort: o reabastecimento é um facto e nunca é bloqueado por falta de
     * stock registado — nesse caso o stock fica a zero e o admin é avisado pelo
     * próprio StockService.
     */
    private function debitarStockInstalacao(float $ml, ?int $userId): void
    {
        if ($this->product_id === null) {
            return;
        }

        $installationId = $this->piscina?->installation_id;
        if ($installationId === null) {
            return;
        }

        $stock = StockInstallation::query()
            ->where('installation_id', $installationId)
            ->where('product_id', $this->product_id)
            ->first();

        if ($stock === null) {
            return;
        }

        // A dose é medida em ml; o produto é vendido em L/kg (densidade ≈ 1 para
        // hipoclorito e redutor de pH — suficiente para gestão de stock).
        $quantidade = match (strtolower((string) $stock->produto?->unidade)) {
            'l', 'litro', 'litros', 'kg' => $ml / 1000,
            default => $ml,
        };

        try {
            app(StockService::class)->consumeInstallationStock($stock->id, round($quantidade, 3), $userId);
        } catch (\Throwable $e) {
            Log::warning('Reabastecimento de bidão sem stock suficiente na instalação', [
                'dosing_container_id' => $this->id,
                'erro' => $e->getMessage(),
            ]);
        }
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

        // Fora da transação: o débito de stock tem a sua própria transação com lock.
        $this->debitarStockInstalacao($ml, $userId);

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
                $dt = HannaCloudService::horaLeitura($l['dt'], $device->ajuste_minutos);

                if ($dt === null) {
                    continue;
                }

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
