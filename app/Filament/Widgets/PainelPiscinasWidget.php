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

        // Otimização: obter as últimas ações operacionais (análises rápidas)
        $ultimasAcoes = OperationalAction::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->where('tipo', OperationalAction::TIPO_ANALISE_PONTUAL)
            ->orderBy('registado_em', 'desc')
            ->get()
            ->groupBy('pool_id')
            ->map(fn ($acoes) => $acoes->first());

        // Histórico para os gráficos (Sparklines)
        $historicoSensores = \App\Models\SensorReading::query()
            ->whereIn('hanna_device_id', $sondas->pluck('hanna_device_id'))
            ->where('lida_em', '>=', now()->subHours(24))
            ->orderByDesc('lida_em')
            ->get()
            ->groupBy('hanna_device_id');

        $historicoRegistos = DailyRecord::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->where('registado_em', '>=', now()->subDays(14))
            ->orderByDesc('registado_em')
            ->get()
            ->groupBy('pool_id');

        $historicoAcoesParaGrafico = OperationalAction::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->where('tipo', OperationalAction::TIPO_ANALISE_PONTUAL)
            ->where('registado_em', '>=', now()->subDays(14))
            ->orderByDesc('registado_em')
            ->get()
            ->groupBy('pool_id');

        $historicoManualArray = collect();
        foreach ($piscinas as $piscina) {
            $registos = $historicoRegistos->get($piscina->id) ?? collect();
            $acoes = $historicoAcoesParaGrafico->get($piscina->id) ?? collect();
            
            $combined = $registos->map(fn($r) => [
                'date' => $r->registado_em,
                'ph' => $r->ph_efetivo !== null ? (float)$r->ph_efetivo : null,
                'cloro_livre' => $r->cloro_livre_efetivo !== null ? (float)$r->cloro_livre_efetivo : null,
                'cloro_combinado' => $r->cloro_combinado !== null ? (float)$r->cloro_combinado : null,
                'temperatura' => $r->temperatura_efetivo !== null ? (float)$r->temperatura_efetivo : null,
            ])->concat($acoes->map(function($a) {
                $cl = $a->dados['cloro_livre'] ?? null;
                $ct = $a->dados['cloro_total'] ?? null;
                return [
                    'date' => $a->registado_em,
                    'ph' => isset($a->dados['ph']) ? (float)str_replace(',', '.', (string)$a->dados['ph']) : null,
                    'cloro_livre' => isset($cl) ? (float)str_replace(',', '.', (string)$cl) : null,
                    'cloro_combinado' => (isset($cl) && isset($ct)) ? ((float)str_replace(',', '.', (string)$ct) - (float)str_replace(',', '.', (string)$cl)) : null,
                    'temperatura' => isset($a->dados['temperatura']) ? (float)str_replace(',', '.', (string)$a->dados['temperatura']) : null,
                ];
            }))->sortByDesc('date')->take(15)->sortBy('date')->values();
            
            $historicoManualArray->put($piscina->id, $combined);
        }

        $historicoSensoresArray = collect();
        foreach ($sondas as $sonda) {
            $leituras = $historicoSensores->get($sonda->hanna_device_id) ?? collect();
            $mapped = $leituras->map(fn($l) => [
                'date' => $l->lida_em,
                'ph' => $l->ph !== null ? (float)$l->ph : null,
                'orp' => $l->orp !== null ? (float)$l->orp : null,
                'temperatura' => $l->temperatura_agua !== null ? (float)$l->temperatura_agua : null,
            ])->sortByDesc('date')->take(30)->sortBy('date')->values();
            $historicoSensoresArray->put($sonda->hanna_device_id, $mapped);
        }

        // Unificar o mais recente (registo diário ou ação operacional)
        $registosUnificados = [];
        
        $parseValue = function ($val) {
            if ($val === null || $val === '') return null;
            return (float)str_replace(',', '.', (string)$val);
        };

        foreach ($piscinas as $piscina) {
            $registoDiario = $ultimosRegistos->get($piscina->id);
            $acao = $ultimasAcoes->get($piscina->id);

            $usarAcao = $acao && (!$registoDiario || $acao->registado_em->isAfter($registoDiario->registado_em));

            if ($usarAcao) {
                $registo = new DailyRecord();
                $registo->pool_id = $acao->pool_id;
                $registo->registado_em = $acao->registado_em;
                $registo->ph = $parseValue($acao->dados['ph'] ?? null);
                $registo->cloro_livre = $parseValue($acao->dados['cloro_livre'] ?? null);
                $registo->cloro_total = $parseValue($acao->dados['cloro_total'] ?? null);
                $registo->temperatura = $parseValue($acao->dados['temperatura'] ?? null);
                $registosUnificados[$piscina->id] = $registo;
            } else {
                $registosUnificados[$piscina->id] = $registoDiario;
            }
        }

        // Procurar o ORP correspondente ao momento da análise manual (janela de +/- 60 mins)
        $orpsNoMomento = [];
        foreach ($registosUnificados as $poolId => $registo) {
            $device = $sondas->get($poolId);
            if (! $device || ! $registo || ! $registo->registado_em) {
                continue;
            }

            $leituraProxima = \App\Models\SensorReading::query()
                ->where('hanna_device_id', $device->hanna_device_id)
                ->whereNotNull('orp')
                ->whereBetween('lida_em', [
                    $registo->registado_em->copy()->subMinutes(60),
                    $registo->registado_em->copy()->addMinutes(60)
                ])
                ->get()
                ->sortBy(fn ($leitura) => abs($leitura->lida_em->diffInSeconds($registo->registado_em)))
                ->first();

            if ($leituraProxima) {
                $orpsNoMomento[$poolId] = (float) $leituraProxima->orp;
            }
        }

        $piscinasMapped = $piscinas->map(function (Pool $piscina) use ($sondas, $registosUnificados, $ultimasLeituras, $orpsNoMomento, $historicoManualArray, $historicoSensoresArray): array {
            $registo = $registosUnificados[$piscina->id] ?? null;

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

            $artefacto = $leitura !== null
                ? app(\App\Services\LeituraArtefactoService::class)->motivoEm($piscina->id, $leitura->lida_em)
                : null;

            $controladorOnline = $leitura !== null && $idadeMin !== null && $idadeMin <= 60 && $artefacto === null;

            $usarRegistoManual = ! $controladorOnline
                && $registo !== null
                && abs((int) $registo->registado_em->diffInHours(now())) <= 8;

            $metricas4 = [];
            $phOkConformes = null;
            $cloroOkConformes = null;
            $tempOkConformes = null;

            // 1. pH
            if ($controladorOnline) {
                $phOk = $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null;
                $phOkConformes = $phOk;
                $metricas4['ph'] = [
                    'label' => 'pH',
                    'valor' => $ph !== null ? number_format($ph, 2, ',', '') : '—',
                    'ok' => $phOk,
                    'origem' => 'controlador',
                    'idade' => match (true) {
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    }
                ];
            } elseif ($usarRegistoManual) {
                $phOk = $registo?->ph_efetivo !== null ? $registo->phConforme() : null;
                $phOkConformes = $phOk;
                $metricas4['ph'] = [
                    'label' => 'pH',
                    'valor' => $registo?->ph_efetivo !== null ? number_format((float) $registo->ph_efetivo, 2, ',', '') : '—',
                    'ok' => $phOk,
                    'origem' => 'manual',
                    'idade' => $registo->registado_em->locale('pt')->diffForHumans()
                ];
            } else {
                // Tenta controlador offline ou artefacto
                if ($leitura !== null) {
                    $phOk = $artefacto === null && $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null;
                    $phOkConformes = $phOk;
                    $metricas4['ph'] = [
                        'label' => 'pH',
                        'valor' => $ph !== null ? number_format($ph, 2, ',', '') : '—',
                        'ok' => $phOk,
                        'origem' => $artefacto !== null ? 'artefacto' : 'controlador_offline',
                        'idade' => $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans()
                    ];
                } else {
                    $metricas4['ph'] = ['label' => 'pH', 'valor' => '—', 'ok' => null, 'origem' => 'sem_dados', 'idade' => ''];
                }
            }

            // 2. Cloro (ORP e Livre)
            $valorOrp = null;
            $valorLivre = null;
            $cloroOk = null;
            $cloroOrigem = 'sem_dados';
            $cloroIdade = '';

            if ($controladorOnline) {
                $orpOk = $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $valorOrp = $orp !== null ? number_format($orp, 0, ',', '') . ' mV' : null;
                $cloroOk = $orpOk;
                $cloroOkConformes = $orpOk;
                $cloroOrigem = 'controlador';
                $cloroIdade = match (true) {
                    $idadeMin < 1 => 'agora',
                    $idadeMin < 60 => "há {$idadeMin}m",
                    default => $leitura->lida_em->locale('pt')->diffForHumans(),
                };
            } elseif ($usarRegistoManual) {
                $livreOk = $registo?->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null;
                $cloroOk = $livreOk;
                $cloroOkConformes = $livreOk;
                $cloroOrigem = 'manual';
                $cloroIdade = $registo->registado_em->locale('pt')->diffForHumans();
                $orpNoMomento = $orpsNoMomento[$piscina->id] ?? null;
                if ($orpNoMomento !== null) {
                    $valorOrp = number_format($orpNoMomento, 0, ',', '') . ' mV';
                }
            } elseif ($leitura !== null) {
                $orpOk = $artefacto === null && $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $cloroOk = $orpOk;
                $cloroOkConformes = $orpOk;
                $valorOrp = $orp !== null ? number_format($orp, 0, ',', '') . ' mV' : null;
                $cloroOrigem = $artefacto !== null ? 'artefacto' : 'controlador_offline';
                $cloroIdade = $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans();
            }

            if ($registo?->cloro_livre_efetivo !== null) {
                $valorLivre = number_format((float) $registo->cloro_livre_efetivo, 2, ',', '') . ' mg/L';
                if ($cloroOrigem === 'controlador_offline' || $cloroOrigem === 'artefacto') {
                    $cloroIdade = $registo->registado_em->locale('pt')->diffForHumans() . ' (Manual)';
                }
            }

            $metricas4['cloro'] = [
                'label' => 'ORP / Cl. Livre',
                'valor_orp' => $valorOrp,
                'valor_livre' => $valorLivre,
                'ok' => $cloroOk,
                'origem' => $cloroOrigem,
                'idade' => $cloroIdade
            ];

            // 3. Cloro Combinado (Sempre Manual se houver, independentemente do tempo)
            $combOk = $registo?->cloro_combinado !== null ? $registo->cloroCombinadoConforme() : null;
            $metricas4['combinado'] = [
                'label' => 'Cl. Combinado',
                'valor' => $registo?->cloro_combinado !== null ? number_format((float) $registo->cloro_combinado, 2, ',', '') . ' mg/L' : '—',
                'ok' => $combOk,
                'origem' => $registo ? 'manual' : 'sem_dados',
                'idade' => $registo ? $registo->registado_em->locale('pt')->diffForHumans() : ''
            ];

            // 4. Temperatura
            if ($controladorOnline) {
                $tempOk = $tempAgua !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                    ? ($tempAgua >= (float) $piscina->temp_min && $tempAgua <= (float) $piscina->temp_max)
                    : null;
                $tempOkConformes = $tempOk;
                $metricas4['temp'] = [
                    'label' => 'Temp.',
                    'valor' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') . ' °C' : '—',
                    'ok' => $tempOk,
                    'origem' => 'controlador',
                    'idade' => match (true) {
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    }
                ];
            } elseif ($usarRegistoManual) {
                $tempOk = $registo?->temperatura_efetivo !== null ? $registo->temperaturaConforme() : null;
                $tempOkConformes = $tempOk;
                $metricas4['temp'] = [
                    'label' => 'Temp.',
                    'valor' => $registo?->temperatura_efetivo !== null ? number_format((float) $registo->temperatura_efetivo, 1, ',', '') . ' °C' : '—',
                    'ok' => $tempOk,
                    'origem' => 'manual',
                    'idade' => $registo->registado_em->locale('pt')->diffForHumans()
                ];
            } else {
                if ($leitura !== null) {
                    $tempOk = $artefacto === null && $tempAgua !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                        ? ($tempAgua >= (float) $piscina->temp_min && $tempAgua <= (float) $piscina->temp_max)
                        : null;
                    $tempOkConformes = $tempOk;
                    $metricas4['temp'] = [
                        'label' => 'Temp.',
                        'valor' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '') . ' °C' : '—',
                        'ok' => $tempOk,
                        'origem' => $artefacto !== null ? 'artefacto' : 'controlador_offline',
                        'idade' => $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans()
                    ];
                } else {
                    $metricas4['temp'] = ['label' => 'Temp.', 'valor' => '—', 'ok' => null, 'origem' => 'sem_dados', 'idade' => ''];
                }
            }

            $getSparklineData = function(?string $origem, string $key) use ($piscina, $device, $historicoManualArray, $historicoSensoresArray) {
                if (in_array($origem, ['controlador', 'controlador_offline', 'artefacto'])) {
                    return array_filter($historicoSensoresArray->get($device?->hanna_device_id)?->pluck($key === 'cloro' ? 'orp' : $key)->toArray() ?? [], fn($v) => $v !== null);
                } elseif ($origem === 'manual') {
                    $k = $key === 'cloro' ? 'cloro_livre' : $key;
                    return array_filter($historicoManualArray->get($piscina->id)?->pluck($k)->toArray() ?? [], fn($v) => $v !== null);
                }
                return [];
            };

            $metricas4['ph']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['ph']['origem'], 'ph'));
            $metricas4['cloro']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['cloro']['origem'], 'cloro'));
            $metricas4['combinado']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['combinado']['origem'], 'cloro_combinado'));
            $metricas4['temp']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['temp']['origem'], 'temperatura'));

            return [
                'piscina' => $piscina,
                'registo' => $registo,
                'sem_hoje' => ! $registo || ! $registo->registado_em->isToday(),
                'metricas4' => $metricas4,
                'parametros_conformes' => [$phOkConformes, $cloroOkConformes, $tempOkConformes],
                'tem_dados_conformes' => $metricas4['ph']['valor'] !== '—' || $metricas4['cloro']['valor_orp'] !== null || $metricas4['cloro']['valor_livre'] !== null,
                'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
                'acoes_rapidas' => collect([
                    [DailyRecordResource::getUrl('create', ['pool' => $piscina->id, 'quick' => 1]), 'Registo Rápido', 'heroicon-m-document-check', true, true],
                    [OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL]), 'Análise rápida', 'heroicon-m-beaker', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                    [OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO]), 'Lavar filtro', 'heroicon-m-funnel', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                    [OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => OperationalAction::TIPO_TORNEIRA]), 'Torneira', 'heroicon-m-adjustments-horizontal', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                    [OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => OperationalAction::TIPO_CONTADOR]), 'Contador', 'heroicon-m-calculator', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                ])->filter(fn (array $a) => $a[4])
                ->map(fn (array $a) => [
                    'url' => $a[0],
                    'label' => $a[1],
                    'icon' => $a[2],
                    'primary' => $a[3],
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
     * Generates a smooth SVG path for a sparkline from an array of values.
     */
    private static function generateSparkline(array $values): ?array
    {
        $values = array_values($values);
        if (count($values) < 2) {
            return null;
        }
        
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        
        if ($range == 0) {
            $range = 1;
            $min -= 0.5;
            $max += 0.5;
        } else {
            $padding = $range * 0.1;
            $min -= $padding;
            $max += $padding;
            $range = $max - $min;
        }

        $width = 100;
        $height = 30;
        
        $points = [];
        $stepX = $width / (count($values) - 1);
        
        foreach ($values as $i => $val) {
            $x = $i * $stepX;
            $y = $height - ((($val - $min) / $range) * $height);
            $points[] = [$x, $y];
        }

        $d = "M " . round($points[0][0], 1) . "," . round($points[0][1], 1);
        for ($i = 0; $i < count($points) - 1; $i++) {
            $p0 = $points[max(0, $i - 1)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min(count($points) - 1, $i + 2)];
            
            $cp1x = $p1[0] + ($p2[0] - $p0[0]) * 0.15;
            $cp1y = $p1[1] + ($p2[1] - $p0[1]) * 0.15;
            
            $cp2x = $p2[0] - ($p3[0] - $p1[0]) * 0.15;
            $cp2y = $p2[1] - ($p3[1] - $p1[1]) * 0.15;
            
            $d .= " C " . round($cp1x, 1) . "," . round($cp1y, 1) . " " . 
                          round($cp2x, 1) . "," . round($cp2y, 1) . " " . 
                          round($p2[0], 1) . "," . round($p2[1], 1);
        }
        
        return [
            'fill' => $d . " L {$width},{$height} L 0,{$height} Z",
            'stroke' => $d
        ];
    }
}
