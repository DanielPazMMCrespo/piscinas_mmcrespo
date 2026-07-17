<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\OperationalActionResource;
use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
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
 *    com indicação de idade (stale > 1h / 60 min).
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

    /** Proxy de cloro conforme via ORP, usado só no cálculo agregado de "conformes" (não no cartão). */
    private const ORP_CLORO_MIN = 680;
    private const ORP_CLORO_MAX = 820;

    protected function getViewData(): array
    {
        // Cache: 10 min TTL para dados do painel (valores + estado).
        $cacheService = app(CacheService::class);
        $scope = $this->cacheScope();
        $cached = $cacheService->getPoolData($scope);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // Lock de 15 segundos para evitar cache stampede, block up to 5 seconds.
            return \Illuminate\Support\Facades\Cache::lock("painel_piscinas_widget_lock_{$scope}", 15)->block(5, function () use ($cacheService, $scope) {
                // Verifica novamente após obter o lock
                $cached = $cacheService->getPoolData($scope);
                if ($cached !== null) {
                    return $cached;
                }

                $viewData = $this->buildPoolData();

                // Cache: guarda para 10 min.
                $cacheService->cachePoolData($scope, $viewData, 10);

                return $viewData;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Fallback: build without caching, or return the cache data if it got set in the meantime
            $cached = $cacheService->getPoolData($scope);
            if ($cached !== null) {
                return $cached;
            }
            return $this->buildPoolData();
        }
    }

    /**
     * Nadador-Salvador só vê as suas piscinas — uma chave global cruzaria
     * dados de instalações diferentes entre utilizadores com esse papel.
     */
    private function cacheScope(): string
    {
        $utilizador = auth()->user();

        if ($utilizador?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return "ns_{$utilizador->id}";
        }

        return 'full';
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

            // Leitura durante lavagem/bomba parada é artefacto: não circula água
            // no sensor, logo não conta para conformidade.
            $artefacto = $leitura !== null
                ? app(\App\Services\LeituraArtefactoService::class)->motivoEm($piscina->id, $leitura->lida_em)
                : null;

            $controladorOnline = $leitura !== null && $idadeMin !== null && $idadeMin <= 60 && $artefacto === null;

            $usarRegistoManual = ! $controladorOnline
                && $registo !== null
                && abs((int) $registo->registado_em->diffInHours(now())) <= 8;

            $dadosApresentados = null;
            $phOkConformes = null;
            $cloroOkConformes = null;
            $tempOkConformes = null;

            if ($controladorOnline) {
                $phOk = $ph !== null ? ($ph >= DailyRecord::PH_MIN && $ph <= DailyRecord::PH_MAX) : null;
                $orpOk = $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $tempOk = $tempAgua !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                    ? ($tempAgua >= (float) $piscina->temp_min && $tempAgua <= (float) $piscina->temp_max)
                    : null;

                $dadosApresentados = [
                    'origem' => 'controlador',
                    'atualizado_ha' => match (true) {
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    },
                    'ph' => $ph !== null ? number_format($ph, 2, ',', '') : null,
                    'ph_ok' => $phOk,
                    'middle_label' => 'ORP',
                    'middle_value' => $orp !== null ? number_format($orp, 0, ',', '') . ' mV' : null,
                    'middle_ok' => $orpOk,
                    'temp' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') . ' °C' : null,
                    'temp_ok' => $tempOk,
                    'stale' => false,
                ];

                $phOkConformes = $phOk;
                $cloroOkConformes = $orpOk;
                $tempOkConformes = $tempOk;
            } elseif ($usarRegistoManual) {
                $phOk = $registo->ph_efetivo !== null ? $registo->phConforme() : null;
                $cloroOk = $registo->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null;
                $tempOk = $registo->temperatura_efetivo !== null ? $registo->temperaturaConforme() : null;

                $dadosApresentados = [
                    'origem' => 'manual',
                    'atualizado_ha' => $registo->registado_em->locale('pt')->diffForHumans(),
                    'ph' => $registo->ph_efetivo !== null ? number_format((float) $registo->ph_efetivo, 2, ',', '') : null,
                    'ph_ok' => $phOk,
                    'middle_label' => 'Cl. Livre',
                    'middle_value' => $registo->cloro_livre_efetivo !== null ? number_format((float) $registo->cloro_livre_efetivo, 2, ',', '') . ' mg/L' : null,
                    'middle_ok' => $cloroOk,
                    'temp' => $registo->temperatura_efetivo !== null ? number_format((float) $registo->temperatura_efetivo, 1, ',', '') . ' °C' : null,
                    'temp_ok' => $tempOk,
                    'stale' => false,
                ];

                $phOkConformes = $phOk;
                $cloroOkConformes = $cloroOk;
                $tempOkConformes = $tempOk;
            } elseif ($leitura !== null && $artefacto === null) {
                $phOk = $ph !== null ? ($ph >= DailyRecord::PH_MIN && $ph <= DailyRecord::PH_MAX) : null;
                $orpOk = $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $tempOk = $tempAgua !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                    ? ($tempAgua >= (float) $piscina->temp_min && $tempAgua <= (float) $piscina->temp_max)
                    : null;

                $dadosApresentados = [
                    'origem' => 'controlador_offline',
                    'atualizado_ha' => $leitura->lida_em->locale('pt')->diffForHumans(),
                    'ph' => $ph !== null ? number_format($ph, 2, ',', '') : null,
                    'ph_ok' => $phOk,
                    'middle_label' => 'ORP',
                    'middle_value' => $orp !== null ? number_format($orp, 0, ',', '') . ' mV' : null,
                    'middle_ok' => $orpOk,
                    'temp' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') . ' °C' : null,
                    'temp_ok' => $tempOk,
                    'stale' => true,
                ];

                $phOkConformes = $phOk;
                $cloroOkConformes = $orpOk;
                $tempOkConformes = $tempOk;
            } elseif ($leitura !== null && $artefacto !== null) {
                // Leitura em artefacto: mostra em tom neutro, sem contribuir para
                // a conformidade (parâmetros ficam null).
                $dadosApresentados = [
                    'origem' => 'artefacto',
                    'artefacto' => $artefacto,
                    'atualizado_ha' => $leitura->lida_em->locale('pt')->diffForHumans(),
                    'ph' => $ph !== null ? number_format($ph, 2, ',', '') : null,
                    'ph_ok' => null,
                    'middle_label' => 'ORP',
                    'middle_value' => $orp !== null ? number_format($orp, 0, ',', '') . ' mV' : null,
                    'middle_ok' => null,
                    'temp' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') . ' °C' : null,
                    'temp_ok' => null,
                    'stale' => true,
                ];
            }

            return [
                'piscina' => $piscina,
                'registo' => $registo,
                'sem_hoje' => ! $registo || ! $registo->registado_em->isToday(),
                'ha_quanto' => $registo?->registado_em->diffForHumans(),
                'metricas' => $registo ? [
                    self::metrica('pH', $registo->ph_efetivo, 2, '', $registo->ph_efetivo !== null ? $registo->phConforme() : null),
                    self::metrica('Cl. Livre', $registo->cloro_livre_efetivo, 2, ' mg/L', $registo->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null),
                    self::metrica('Cl. Combinado', $registo->cloro_combinado, 2, ' mg/L', $registo->cloro_combinado !== null ? $registo->cloroCombinadoConforme() : null),
                    self::metrica('Temp.', $registo->temperatura_efetivo, 1, ' °C', $registo->temperatura_efetivo !== null ? $registo->temperaturaConforme() : null),
                ] : [],
                'parametros_conformes' => [$phOkConformes, $cloroOkConformes, $tempOkConformes],
                'tem_dados_conformes' => $dadosApresentados !== null,
                'controlador' => $dadosApresentados,
                'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
                'acoes_rapidas' => collect([
                    [OperationalAction::TIPO_ANALISE_PONTUAL, 'Análise rápida', 'heroicon-m-beaker'],
                    [OperationalAction::TIPO_LAVAGEM_FILTRO, 'Lavar filtro', 'heroicon-m-funnel'],
                    [OperationalAction::TIPO_TORNEIRA, 'Torneira', 'heroicon-m-adjustments-horizontal'],
                    [OperationalAction::TIPO_CONTADOR, 'Contador', 'heroicon-m-calculator'],
                ])->map(fn (array $a) => [
                    'label' => $a[1],
                    'icon' => $a[2],
                    'url' => OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => $a[0]]),
                ])->all(),
            ];
        });

        $totalPiscinas = $piscinasMapped->count();
        $registadasHoje = $piscinasMapped->filter(fn ($p) => !$p['sem_hoje'])->count();
        $conformes = $piscinasMapped->filter(fn ($p) => $p['tem_dados_conformes'] && collect($p['parametros_conformes'])->every(fn ($ok) => $ok !== false))->count();

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
            'isNS' => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false,
        ];
    }

    /**
     * Conformidade de um parâmetro para o cálculo agregado: usa a sonda se estiver
     * fresca e tiver valor; caso contrário cai para a avaliação do registo diário.
     */
    private static function parametroOk(bool $sensorFresco, ?float $valorSensor, ?bool $okSensor, ?bool $okRegisto): ?bool
    {
        return $sensorFresco && $valorSensor !== null ? $okSensor : $okRegisto;
    }

    /**
     * Combina vários booleanos anuláveis: false se algum falhar, null se todos
     * forem desconhecidos, true caso contrário (nulos não bloqueiam, como no resto do model).
     */
    private static function combinarOk(?bool ...$valores): ?bool
    {
        $conhecidos = array_filter($valores, fn (?bool $v) => $v !== null);

        if ($conhecidos === []) {
            return null;
        }

        return ! in_array(false, $conhecidos, true);
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
