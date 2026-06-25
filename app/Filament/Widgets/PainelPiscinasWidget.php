<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\Pool;
use App\Services\CacheService;
use Filament\Widgets\Widget;

/**
 * Primeiro widget do dashboard: o estado atual de todas as piscinas.
 *
 * Cada cartão junta as duas fontes de verdade da mesma piscina:
 *  - o último registo manual válido (não substituído por correção), avaliado
 *    contra os limites CN 14/DA;
 *  - a última leitura do controlador Hanna (BL132) mapeada à piscina, se existir,
 *    com indicação de idade (stale > 15 min, o intervalo de envio do BL132).
 *
 * Ação direta: "Registar" por piscina (pré-seleciona a piscina no formulário).
 */
class PainelPiscinasWidget extends Widget
{
    protected static ?int $sort = -30;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.painel-piscinas';

    /** 30s de polling garante que os dados aparecem logo após um sync manual. */
    protected static ?string $pollingInterval = '30s';

    /** Range operacional do controlador BL132 — mínimo comum a todas as piscinas (660 mV). */
    private const ORP_MIN = 660;
    private const ORP_MAX = 750;

    protected function getViewData(): array
    {
        // Cache: 10 min TTL para dados do painel (valores + estado).
        $cacheService = app(CacheService::class);
        $cached = $cacheService->getPoolData();
        if ($cached !== null) {
            return $cached;
        }

        try {
            // Lock de 15 segundos para evitar cache stampede, block up to 5 seconds.
            return \Illuminate\Support\Facades\Cache::lock('painel_piscinas_widget_lock', 15)->block(5, function () use ($cacheService) {
                // Verifica novamente após obter o lock
                $cached = $cacheService->getPoolData();
                if ($cached !== null) {
                    return $cached;
                }

                $viewData = $this->buildPoolData();

                // Cache: guarda para 10 min.
                $cacheService->cachePoolData($viewData, 10);

                return $viewData;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Fallback: build without caching, or return the cache data if it got set in the meantime
            $cached = $cacheService->getPoolData();
            if ($cached !== null) {
                return $cached;
            }
            return $this->buildPoolData();
        }
    }

    private function buildPoolData(): array
    {
        $sondas = HannaDevice::query()
            ->where('active', true)
            ->whereNotNull('pool_id')
            ->get()
            ->keyBy('pool_id');

        $query = Pool::query()
            ->where('active', true)
            ->with('instalacao');

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        $piscinas = $query
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        // Otimização: obter as últimas leituras das sondas em batch (evita N+1).
        $ultimasLeituras = \App\Models\SensorReading::query()
            ->whereIn('hanna_device_id', $sondas->pluck('hanna_device_id'))
            ->orderByDesc('lida_em')
            ->get()
            ->groupBy('hanna_device_id')
            ->map(fn ($leituras) => $leituras->first());

        // Otimização: obter apenas o último registo válido de cada piscina numa só query.
        $ultimosRegistos = DailyRecord::latestPerPool()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->get()
            ->keyBy('pool_id');

        $piscinasMapped = $piscinas->map(function (Pool $piscina) use ($sondas, $ultimosRegistos, $ultimasLeituras): array {
            $registo = $ultimosRegistos->get($piscina->id);

            // Garante que a avaliação de temperatura conhece os limites da piscina.
            $registo?->setRelation('piscina', $piscina);

            $device = $sondas->get($piscina->id);
            $leitura = $device ? $ultimasLeituras->get($device->hanna_device_id) : null;

            $idadeMin = $leitura?->lida_em
                ? (int) $leitura->lida_em->diffInMinutes(now())
                : null;

            $ph = $leitura?->ph !== null ? (float) $leitura->ph : null;
            $orp = $leitura?->orp !== null ? (float) $leitura->orp : null;
            $tempAgua = $leitura?->temperatura_agua !== null ? (float) $leitura->temperatura_agua : null;

            return [
                'piscina' => $piscina,
                'registo' => $registo,
                'sem_hoje' => ! $registo || ! $registo->registado_em->isToday(),
                'ha_quanto' => $registo?->registado_em->diffForHumans(),
                'metricas' => $registo ? [
                    self::metrica('pH', $registo->ph, 2, '', $registo->ph !== null ? $registo->phConforme() : null),
                    self::metrica('Cl. Livre', $registo->cloro_livre, 2, ' mg/L', $registo->cloro_livre !== null ? $registo->cloroLivreConforme() : null),
                    self::metrica('Cl. Total', $registo->cloro_total, 2, ' mg/L', $registo->cloro_total !== null && $registo->cloro_livre !== null ? $registo->cloroCombinadoConforme() : null),
                    self::metrica('Temp.', $registo->temperatura, 1, ' °C', $registo->temperatura !== null ? $registo->temperaturaConforme() : null),
                ] : [],
                'controlador' => $leitura ? [
                    'ph' => $ph !== null ? number_format($ph, 2, ',', '') : null,
                    'ph_ok' => $ph !== null
                        ? ($ph >= DailyRecord::PH_MIN && $ph <= DailyRecord::PH_MAX)
                        : null,
                    'orp' => $orp !== null ? number_format($orp, 0, ',', '') : null,
                    'orp_ok' => $orp !== null
                        ? ($orp >= self::ORP_MIN && $orp <= self::ORP_MAX)
                        : null,
                    'temp' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') : null,
                    'temp_ok' => $tempAgua !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                        ? ($tempAgua >= (float) $piscina->temp_min && $tempAgua <= (float) $piscina->temp_max)
                        : null,
                    'idade_txt' => match (true) {
                        $idadeMin === null => 'sem dados',
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    },
                    'stale' => $idadeMin !== null && $idadeMin > 15,
                ] : null,
                'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
            ];
        });

        $totalPiscinas = $piscinasMapped->count();
        $registadasHoje = $piscinasMapped->filter(fn ($p) => !$p['sem_hoje'])->count();
        $conformes = $piscinasMapped->filter(fn ($p) => $p['registo'] && collect($p['metricas'])->every(fn ($m) => $m['ok'] !== false))->count();

        $percentagemRegisto = $totalPiscinas > 0 ? (int) (($registadasHoje / $totalPiscinas) * 100) : 0;
        $percentagemConforme = $totalPiscinas > 0 ? (int) (($conformes / $totalPiscinas) * 100) : 0;

        return [
            'piscinas' => $piscinasMapped,
            'urlRegistar' => DailyRecordResource::getUrl('create'),
            'totalPiscinas' => $totalPiscinas,
            'registadasHoje' => $registadasHoje,
            'conformes' => $conformes,
            'percentagemRegisto' => $percentagemRegisto,
            'percentagemConforme' => $percentagemConforme,
        ];
    }

    /**
     * Linha de métrica com tratamento de leitura em falta (registos legados):
     * valor "—" e estado neutro em vez de "0,00" enganador.
     *
     * @return array{label: string, valor: string, ok: bool|null}
     */
    private static function metrica(string $label, mixed $valor, int $casas, string $sufixo, ?bool $ok): array
    {
        return [
            'label' => $label,
            'valor' => $valor !== null ? number_format((float) $valor, $casas, ',', '').$sufixo : '—',
            'ok' => $valor !== null ? $ok : null,
        ];
    }
}
