<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use App\Notifications\TendenciaAlertaNotification;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class CheckParameterTrendsCommand extends Command
{
    /** Tolerância para casar um registo manual com a leitura da sonda "dessa altura". */
    private const ORP_JANELA_MINUTOS = 60;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tendencias:verificar';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica tendências degradantes nos parâmetros das piscinas e alerta preventivamente';

    public function __construct(private readonly SettingsService $settings)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Uma tendência degradante numa piscina encerrada não é accionável.
        $pools = Pool::operacionais()->with('instalacao')->get();
        $adminAndTecnicos = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();
        $registosMinimos = $this->settings->getInt('tendencia_registos_minimos', 3);

        foreach ($pools as $pool) {
            // Get last 5 records without corrections
            $records = DailyRecord::where('pool_id', $pool->id)
                ->whereDoesntHave('correcoes')
                ->orderByDesc('registado_em')
                ->limit(5)
                ->get()
                ->reverse()
                ->values();

            if ($records->count() < $registosMinimos) {
                continue;
            }

            $this->checkTrend($pool, $records, 'ph', $adminAndTecnicos, $registosMinimos);
            $this->checkTrend($pool, $records, 'cloro_livre', $adminAndTecnicos, $registosMinimos);
        }

        return Command::SUCCESS;
    }

    private function checkTrend(Pool $pool, $records, string $parameter, $users, int $registosMinimos): void
    {
        $values = [];
        $registosComValor = [];

        foreach ($records as $record) {
            $val = $parameter === 'ph' ? $record->ph_efetivo : $record->cloro_livre_efetivo;
            if ($val === null) {
                continue;
            }

            $values[] = (float) $val;
            $registosComValor[] = $record->id;
        }

        if (count($values) < $registosMinimos) {
            return;
        }

        $exceptionsDec = 0;
        $exceptionsInc = 0;

        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i] >= $values[$i - 1]) {
                $exceptionsDec++;
            }
            if ($values[$i] <= $values[$i - 1]) {
                $exceptionsInc++;
            }
        }

        $consistentlyDecreasing = $exceptionsDec <= 1;
        $consistentlyIncreasing = $exceptionsInc <= 1;

        if (! $consistentlyDecreasing && ! $consistentlyIncreasing) {
            return;
        }

        $minLimit = $parameter === 'ph' ? DailyRecord::getPhMin() : DailyRecord::getCloroLivreMin();
        $maxLimit = $parameter === 'ph' ? DailyRecord::getPhMax() : DailyRecord::getCloroLivreMax();
        $midpoint = ($minLimit + $maxLimit) / 2;
        $currentValue = end($values);

        $violationExpected = false;
        $limitCrossed = 0.0;

        $n = count($values);
        $slope = ($values[$n - 1] - $values[0]) / ($n - 1);
        $nextValue = $values[$n - 1] + $slope;

        if ($parameter === 'ph') {
            if ($consistentlyDecreasing && $currentValue < $midpoint && $nextValue < $minLimit) {
                $violationExpected = true;
                $limitCrossed = $minLimit;
            } elseif ($consistentlyIncreasing && $currentValue > $midpoint && $nextValue > $maxLimit) {
                $violationExpected = true;
                $limitCrossed = $maxLimit;
            }
        } elseif ($parameter === 'cloro_livre') {
            if ($consistentlyDecreasing && $nextValue < $minLimit) {
                $violationExpected = true;
                $limitCrossed = $minLimit;
            }
        }

        if (! $violationExpected) {
            return;
        }

        $today = now()->format('Y-m-d');
        $cacheKey = "tendencia_{$pool->id}_{$parameter}_{$today}";

        if (Cache::has($cacheKey)) {
            return;
        }

        // O cloro livre manual é medido uma ou duas vezes por dia; o controlador
        // dosa continuamente entre registos. Uma descida nas análises manuais,
        // por si só, não diz que o poder desinfetante caiu — é o ORP dessa altura
        // que o diz.
        $orp = null;
        if ($parameter === 'cloro_livre') {
            $porRegisto = $this->orpPorRegisto($pool, $records);
            $orp = $this->avaliarOrp($pool, array_map(fn (int $id) => $porRegisto[$id] ?? null, $registosComValor));

            if ($orp['estado'] === 'contradiz') {
                $this->line(sprintf(
                    '%s: tendência de cloro livre ignorada — ORP %s → %s mV (a sonda compensou).',
                    $pool->nomeCompleto(),
                    number_format($orp['inicial'], 0, ',', ''),
                    number_format($orp['final'], 0, ',', ''),
                ));

                return;
            }
        }

        NotificationFacade::send(
            $users,
            new TendenciaAlertaNotification(
                $pool->nome_completo,
                $parameter,
                $values,
                $nextValue,
                $limitCrossed,
                $orp
            )
        );
        Cache::add($cacheKey, true, now()->endOfDay());
    }

    /**
     * ORP da sonda no momento de cada registo manual (leitura mais próxima, ±60 min).
     *
     * @return array<int, float> record_id => mV
     */
    private function orpPorRegisto(Pool $pool, Collection $records): array
    {
        $momentos = $records->pluck('registado_em')->filter();

        if ($momentos->isEmpty()) {
            return [];
        }

        // Uma janela estreita por registo em vez de um intervalo único: entre o
        // primeiro e o último registo cabem dias de leituras de 15 em 15 min e
        // só interessam as vizinhas de cada um.
        $leituras = SensorReading::query()
            ->select(['lida_em', 'orp'])
            ->where('pool_id', $pool->id)
            ->whereNotNull('orp')
            ->where(function ($query) use ($momentos): void {
                foreach ($momentos as $momento) {
                    $query->orWhereBetween('lida_em', [
                        $momento->copy()->subMinutes(self::ORP_JANELA_MINUTOS),
                        $momento->copy()->addMinutes(self::ORP_JANELA_MINUTOS),
                    ]);
                }
            })
            ->orderBy('lida_em')
            ->get();

        if ($leituras->isEmpty()) {
            return [];
        }

        $orps = [];

        foreach ($records as $record) {
            if ($record->registado_em === null) {
                continue;
            }

            $maisProxima = $leituras
                ->filter(fn (SensorReading $l) => abs($l->lida_em->diffInMinutes($record->registado_em)) <= self::ORP_JANELA_MINUTOS)
                ->sortBy(fn (SensorReading $l) => abs($l->lida_em->diffInSeconds($record->registado_em)))
                ->first();

            if ($maisProxima !== null) {
                $orps[$record->id] = (float) $maisProxima->orp;
            }
        }

        return $orps;
    }

    /**
     * Contraprova da tendência do cloro livre contra o ORP da mesma janela.
     *
     * ORP a subir ou estável significa que o poder desinfetante se manteve — o
     * controlador tratou disso sozinho — e a descida das análises manuais não
     * justifica um alerta. Só uma descida real de ORP, ou um ORP já abaixo do
     * mínimo da piscina, confirma a degradação.
     *
     * @param  list<float|null>  $orps  ORP no momento de cada registo, alinhado com os valores do parâmetro
     * @return array{estado: string, inicial: float, final: float, delta: float} inicial/final só têm significado fora de 'sem_dados'
     */
    private function avaliarOrp(Pool $pool, array $orps): array
    {
        $conhecidos = array_values(array_filter($orps, fn (?float $orp) => $orp !== null));

        // A contraprova tem de terminar no mesmo ponto que a tendência: um ORP
        // antigo — sonda offline à hora do último registo — não pode calar o alerta.
        if (count($conhecidos) < 2 || $orps[count($orps) - 1] === null) {
            return ['estado' => 'sem_dados', 'inicial' => 0.0, 'final' => 0.0, 'delta' => 0.0];
        }

        $inicial = $conhecidos[0];
        $final = $conhecidos[count($conhecidos) - 1];
        $delta = round($final - $inicial, 1);
        $margem = $this->settings->getFloat('tendencia_orp_delta_mv', 10.0);

        $abaixoDoMinimo = $pool->orp_min !== null && $final < (float) $pool->orp_min;

        return [
            'estado' => ($abaixoDoMinimo || $delta <= -$margem) ? 'confirma' : 'contradiz',
            'inicial' => $inicial,
            'final' => $final,
            'delta' => $delta,
        ];
    }
}
