<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Estabilidade das medições — separa "a água oscila" de "a medição oscila".
 * Compara o desvio-padrão das análises manuais (pH, cloro livre) com o da
 * sonda Hanna (pH, ORP) no mesmo período, e o delta médio de cada análise
 * manual face à leitura da sonda mais próxima (±15 min). Se σ(manual) for
 * muito superior a σ(sonda), a variação vem da rotina de amostragem
 * (hora/ponto de recolha), não da água.
 */
class EstabilidadeMedicoesWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.estabilidade-medicoes';

    private const DIAS = 14;

    /** Janela de emparelhamento manual↔sonda, alinhada com o RelatorioPdf. */
    private const JANELA_PAR_MINUTOS = 15;

    /** Mínimo de análises manuais para o desvio-padrão ter significado. */
    private const MIN_AMOSTRAS = 5;

    /** Rácio σ(manual)/σ(sonda) a partir do qual a medição é a suspeita. */
    private const RACIO_AVISO = 3.0;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    /**
     * @return array{dias: int, linhas: array<int, array<string, mixed>>}
     */
    public function getDados(): array
    {
        $inicio = now()->subDays(self::DIAS)->startOfDay();

        $piscinas = Pool::query()
            ->where('active', true)
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        $linhas = $piscinas->map(function (Pool $piscina) use ($inicio): array {
            $registos = DailyRecord::query()
                ->where('pool_id', $piscina->id)
                ->where('registado_em', '>=', $inicio)
                ->whereDoesntHave('correcoes')
                ->orderBy('registado_em')
                ->get(['id', 'pool_id', 'registado_em', 'ph', 'ns_ph', 'cloro_livre', 'ns_cloro_livre']);

            $leituras = SensorReading::query()
                ->where('pool_id', $piscina->id)
                ->where('lida_em', '>=', $inicio)
                ->orderBy('lida_em')
                ->get(['ph', 'orp', 'lida_em']);

            $phManual = $registos->map(fn (DailyRecord $r) => $r->ph_efetivo)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values();
            $clManual = $registos->map(fn (DailyRecord $r) => $r->cloro_livre_efetivo)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values();
            $phSonda = $leituras->pluck('ph')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values();
            $orpSonda = $leituras->pluck('orp')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values();

            // Delta de cada análise manual face à leitura da sonda mais próxima (±15 min).
            $deltas = $registos
                ->map(function (DailyRecord $r) use ($leituras): ?float {
                    if ($r->ph_efetivo === null) {
                        return null;
                    }
                    $par = $leituras->first(fn (SensorReading $l) => $l->ph !== null
                        && abs($l->lida_em->diffInMinutes($r->registado_em)) <= self::JANELA_PAR_MINUTOS);

                    return $par !== null ? (float) $r->ph_efetivo - (float) $par->ph : null;
                })
                ->filter(fn ($v) => $v !== null)
                ->values();

            $sigmaPhManual = self::desvioPadrao($phManual);
            $sigmaPhSonda = self::desvioPadrao($phSonda);
            $racio = ($sigmaPhManual !== null && $sigmaPhSonda !== null && $sigmaPhSonda > 0.0)
                ? $sigmaPhManual / $sigmaPhSonda
                : null;

            [$leitura, $cor] = self::interpretar($phManual->count(), $racio, $deltas);

            return [
                'nome' => $piscina->nomeCompleto(' — '),
                'n_manual' => $phManual->count(),
                'sigma_ph_manual' => $sigmaPhManual,
                'sigma_ph_sonda' => $sigmaPhSonda,
                'racio_ph' => $racio,
                'delta_ph_medio' => $deltas->isNotEmpty() ? $deltas->average() : null,
                'n_pares' => $deltas->count(),
                'sigma_cl_manual' => self::desvioPadrao($clManual),
                'sigma_orp_sonda' => self::desvioPadrao($orpSonda),
                'leitura' => $leitura,
                'cor' => $cor,
            ];
        })->values()->all();

        return ['dias' => self::DIAS, 'linhas' => $linhas];
    }

    /**
     * @param  Collection<int, float>  $valores
     */
    private static function desvioPadrao(Collection $valores): ?float
    {
        $n = $valores->count();
        if ($n < 2) {
            return null;
        }

        $media = (float) $valores->average();
        $variancia = $valores->reduce(fn (float $acc, float $v): float => $acc + ($v - $media) ** 2, 0.0) / $n;

        return sqrt($variancia);
    }

    /**
     * @param  Collection<int, float>  $deltas
     * @return array{0: string, 1: string}
     */
    private static function interpretar(int $nManual, ?float $racio, Collection $deltas): array
    {
        if ($nManual < self::MIN_AMOSTRAS) {
            return ['Dados insuficientes no período', 'gray'];
        }

        // Offset sistemático manual↔sonda: aponta para calibração/técnica, não para a água.
        $bias = $deltas->count() >= 3 ? abs((float) $deltas->average()) : null;
        if ($bias !== null && $bias >= 0.2) {
            return ['Desvio sistemático face à sonda — verificar calibração/técnica', 'danger'];
        }

        if ($racio === null) {
            return ['Sem leituras da sonda para comparar', 'gray'];
        }

        if ($racio >= self::RACIO_AVISO) {
            return ['Água estável; oscilação vem da medição/rotina de amostragem', 'warning'];
        }

        if ($racio <= 1.5) {
            return ['Medições consistentes com a água', 'success'];
        }

        return ['Alguma variabilidade de medição', 'gray'];
    }
}
