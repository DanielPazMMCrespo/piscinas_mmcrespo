<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\TendenciaAlertaNotification;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class CheckParameterTrendsCommand extends Command
{
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
        $pools = Pool::where('active', true)->with('instalacao')->get();
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
        foreach ($records as $record) {
            $val = $parameter === 'ph' ? $record->ph_efetivo : $record->cloro_livre_efetivo;
            if ($val !== null) {
                $values[] = (float) $val;
            }
        }

        if (count($values) < $registosMinimos) {
            return;
        }

        $isDecreasing = true;
        $isIncreasing = true;
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

        if ($violationExpected) {
            $today = now()->format('Y-m-d');
            $cacheKey = "tendencia_{$pool->id}_{$parameter}_{$today}";

            if (! Cache::has($cacheKey)) {
                NotificationFacade::send(
                    $users,
                    new TendenciaAlertaNotification(
                        $pool->nome_completo,
                        $parameter,
                        $values,
                        $nextValue,
                        $limitCrossed
                    )
                );
                Cache::add($cacheKey, true, now()->endOfDay());
            }
        }
    }
}
