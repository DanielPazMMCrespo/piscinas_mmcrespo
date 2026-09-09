<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Pages\EncerramentoPiscinas;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\OperationalActionResource;
use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorOutage;
use App\Models\SensorReading;
use App\Services\CacheService;
use App\Services\LimitesLegaisService;
use App\Services\SourceSelectionService;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

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
    protected static ?string $pollingInterval = '60s';

    /** Range operacional do controlador BL132 — mínimo comum a todas as piscinas (660 mV). */
    private const ORP_MIN = 660;

    private const ORP_MAX = 750;

    protected function getViewData(): array
    {
        // Cache: 10 min TTL para dados do painel (valores + estado).
        $cacheService = app(CacheService::class);
        $scope = $this->cacheScope();
        $cached = $cacheService->getPoolData($scope);
        if ($cached !== null) {
            return $this->attachQuickActions($cached);
        }

        try {
            // Lock de 15 segundos para evitar cache stampede, block up to 5 seconds.
            $viewData = Cache::lock("painel_piscinas_widget_lock_{$scope}", 15)->block(5, function () use ($cacheService, $scope) {
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

            return $this->attachQuickActions($viewData);
        } catch (LockTimeoutException $e) {
            // Fallback: build without caching, or return the cache data if it got set in the meantime
            $cached = $cacheService->getPoolData($scope);
            if ($cached !== null) {
                return $this->attachQuickActions($cached);
            }

            return $this->attachQuickActions($this->buildPoolData());
        }
    }

    private function attachQuickActions(array $viewData): array
    {
        $viewData['piscinas'] = $viewData['piscinas']->map(function (array $item) {
            $piscinaId = $item['piscina']->id;

            $encerrada = $item['encerramento'] !== null;
            // Piscina parada não aceita registos (o formulário bloqueia); com a
            // água em tratamento o registo continua a fazer sentido.
            $podeRegistar = ! $encerrada || ($item['encerramento']['agua_em_tratamento'] ?? false);
            $podeReabrir = $encerrada && EncerramentoPiscinas::canAccess();

            // Fora do payload cacheado: a chave 'full' é partilhada por admin,
            // gestor e técnico, e o gestor não pode criar ações operacionais.
            $podeAcaoOperacional = auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false;

            $user = auth()->user();
            $podeAnalise = false;
            if ($user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                $podeAnalise = true;
            } elseif ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
                $podeAnalise = $user->podeVer(NSPermission::ANALISE_PARAMETROS);
            }

            $item['sonda']['url_reportar'] = $podeAcaoOperacional
                ? OperationalActionResource::getUrl('create', ['pool' => $piscinaId, 'tipo' => OperationalAction::TIPO_AVARIA_SONDA])
                : null;

            $item['acoes_rapidas'] = collect([
                [EncerramentoPiscinas::getUrl(), 'Reabrir', 'heroicon-m-lock-open', true, $podeReabrir],
                [DailyRecordResource::getUrl('create', ['pool' => $piscinaId, 'quick' => 1]), 'Registo Rápido', 'heroicon-m-document-check', ! $encerrada, $podeRegistar],
                [OperationalActionResource::getUrl('create', ['pool' => $piscinaId, 'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL]), 'Análise rápida', 'heroicon-m-beaker', false, $podeAnalise],
                [OperationalActionResource::getUrl('create', ['pool' => $piscinaId, 'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO]), 'Lavar filtro', 'heroicon-m-funnel', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                [OperationalActionResource::getUrl('create', ['pool' => $piscinaId, 'tipo' => OperationalAction::TIPO_TORNEIRA]), 'Torneira', 'heroicon-m-adjustments-horizontal', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
                [OperationalActionResource::getUrl('create', ['pool' => $piscinaId, 'tipo' => OperationalAction::TIPO_CONTADOR]), 'Contador', 'heroicon-m-calculator', false, auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false],
            ])->filter(fn (array $a) => $a[4])
                ->map(fn (array $a) => [
                    'url' => $a[0],
                    'label' => $a[1],
                    'icon' => $a[2],
                    'primary' => $a[3],
                ])->all();

            return $item;
        });

        return $viewData;
    }

    /**
     * Versão da estrutura de dados cacheada por buildPoolData(). Incrementar sempre
     * que as chaves de metricas4 mudarem — evita servir um array com a forma antiga
     * a uma blade já atualizada (TTL de 10min seria tempo suficiente para um 500).
     */
    private const CACHE_SHAPE_VERSION = 6;

    /**
     * Nadador-Salvador só vê as suas piscinas — uma chave global cruzaria
     * dados de instalações diferentes entre utilizadores com esse papel.
     */
    private function cacheScope(): string
    {
        $utilizador = auth()->user();

        if ($utilizador?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return "ns_{$utilizador->id}_v".self::CACHE_SHAPE_VERSION;
        }

        return 'full_v'.self::CACHE_SHAPE_VERSION;
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
            ->with(['instalacao', 'encerramentos']);

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        $piscinas = $query
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        // Avarias de sonda em aberto, em batch: uma por piscina no máximo.
        $avarias = SensorOutage::query()
            ->abertas()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->orderBy('aberta_em')
            ->get()
            ->keyBy('pool_id');

        // Otimização: obter as últimas leituras das sondas em batch (evita N+1).
        $ultimasLeituras = SensorReading::query()
            ->whereIn('hanna_device_id', $sondas->pluck('hanna_device_id'))
            ->orderByDesc('lida_em')
            ->get()
            ->groupBy('hanna_device_id')
            ->map(fn ($leituras) => $leituras->first());

        // Otimização: obter apenas o último registo válido de cada piscina numa só query.
        $ultimosRegistos = DailyRecord::latestPerPool()
            ->with('user:id,name')
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->get()
            ->keyBy('pool_id');

        // Otimização: obter as últimas ações operacionais (análises rápidas)
        $ultimasAcoes = OperationalAction::query()
            ->with('user:id,name')
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->where('tipo', OperationalAction::TIPO_ANALISE_PONTUAL)
            ->orderBy('registado_em', 'desc')
            ->get()
            ->groupBy('pool_id')
            ->map(fn ($acoes) => $acoes->first());

        // Histórico para os gráficos (Sparklines)
        $historicoSensores = SensorReading::query()
            ->select('hanna_device_id', 'lida_em', 'ph', 'orp', 'temperatura_agua')
            ->whereIn('hanna_device_id', $sondas->pluck('hanna_device_id'))
            ->where('lida_em', '>=', now()->subHours(24))
            ->orderByDesc('lida_em')
            ->get()
            ->groupBy('hanna_device_id');

        $historicoRegistos = DailyRecord::query()
            // Colunas reais: *_efetivo e cloro_combinado são accessors (manual ?? NS),
            // não colunas — pô-los num select() rebenta a query em PostgreSQL.
            ->select(
                'id', 'pool_id', 'registado_em', 'transparencia',
                'ph', 'ns_ph',
                'cloro_livre', 'ns_cloro_livre',
                'cloro_total', 'ns_cloro_total',
                'temperatura', 'ns_temperatura',
            )
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

            $combined = $registos->map(fn ($r) => [
                'date' => $r->registado_em,
                'ph' => $r->ph_efetivo !== null ? (float) $r->ph_efetivo : null,
                'cloro_livre' => $r->cloro_livre_efetivo !== null ? (float) $r->cloro_livre_efetivo : null,
                'cloro_combinado' => $r->cloro_combinado !== null ? (float) $r->cloro_combinado : null,
                'temperatura' => $r->temperatura_efetivo !== null ? (float) $r->temperatura_efetivo : null,
                'transparencia' => $r->transparencia !== null ? (float) $r->transparencia : null,
            ])->concat($acoes->map(function ($a) {
                $cl = $a->dados['cloro_livre'] ?? null;
                $ct = $a->dados['cloro_total'] ?? null;

                return [
                    'date' => $a->registado_em,
                    'ph' => isset($a->dados['ph']) ? (float) str_replace(',', '.', (string) $a->dados['ph']) : null,
                    'cloro_livre' => isset($cl) ? (float) str_replace(',', '.', (string) $cl) : null,
                    'cloro_combinado' => (isset($cl) && isset($ct)) ? ((float) str_replace(',', '.', (string) $ct) - (float) str_replace(',', '.', (string) $cl)) : null,
                    'temperatura' => isset($a->dados['temperatura']) ? (float) str_replace(',', '.', (string) $a->dados['temperatura']) : null,
                ];
            }))->sortByDesc('date')->take(15)->sortBy('date')->values();

            $historicoManualArray->put($piscina->id, $combined);
        }

        $historicoSensoresArray = collect();
        foreach ($sondas as $sonda) {
            $leituras = $historicoSensores->get($sonda->hanna_device_id) ?? collect();
            $mapped = $leituras->map(fn ($l) => [
                'date' => $l->lida_em,
                'ph' => $l->ph !== null ? (float) $l->ph : null,
                'orp' => $l->orp !== null ? (float) $l->orp : null,
                'temperatura' => $l->temperatura_agua !== null ? (float) $l->temperatura_agua : null,
            ])->sortByDesc('date')->take(30)->sortBy('date')->values();
            $historicoSensoresArray->put($sonda->hanna_device_id, $mapped);
        }

        // Unificar o mais recente (registo diário ou ação operacional)
        $registosUnificados = [];

        $parseValue = function ($val) {
            if ($val === null || $val === '') {
                return null;
            }

            return (float) str_replace(',', '.', (string) $val);
        };

        foreach ($piscinas as $piscina) {
            $registoDiario = $ultimosRegistos->get($piscina->id);
            $acao = $ultimasAcoes->get($piscina->id);

            $usarAcao = $acao && (! $registoDiario || $acao->registado_em->isAfter($registoDiario->registado_em));

            if ($usarAcao) {
                $registo = new DailyRecord;
                $registo->pool_id = $acao->pool_id;
                $registo->user_id = $acao->user_id;
                if ($acao->relationLoaded('user') && $acao->user) {
                    $registo->setRelation('user', $acao->user);
                }
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

        // ORP correspondente ao momento da análise manual (janela de ±60 min).
        // Uma só query para todas as piscinas — era uma por piscina, a cada render
        // e a cada poll do widget.
        $orpsNoMomento = [];
        $janelas = [];
        foreach ($registosUnificados as $poolId => $registo) {
            $device = $sondas->get($poolId);
            if (! $device || ! $registo || ! $registo->registado_em) {
                continue;
            }

            $janelas[$poolId] = [
                'device' => $device->hanna_device_id,
                'momento' => $registo->registado_em,
                'de' => $registo->registado_em->copy()->subMinutes(60),
                'ate' => $registo->registado_em->copy()->addMinutes(60),
            ];
        }

        if ($janelas !== []) {
            $leiturasJanela = SensorReading::query()
                ->whereNotNull('orp')
                ->whereIn('hanna_device_id', array_column($janelas, 'device'))
                ->whereBetween('lida_em', [
                    min(array_column($janelas, 'de')),
                    max(array_column($janelas, 'ate')),
                ])
                ->get()
                ->groupBy('hanna_device_id');

            foreach ($janelas as $poolId => $janela) {
                $maisProxima = $leiturasJanela->get($janela['device'], collect())
                    ->filter(fn (SensorReading $l) => $l->lida_em->betweenIncluded($janela['de'], $janela['ate']))
                    ->sortBy(fn (SensorReading $l) => abs($l->lida_em->diffInSeconds($janela['momento'])))
                    ->first();

                if ($maisProxima) {
                    $orpsNoMomento[$poolId] = (float) $maisProxima->orp;
                }
            }
        }

        $piscinasMapped = $piscinas->map(function (Pool $piscina) use ($sondas, $registosUnificados, $ultimasLeituras, $orpsNoMomento, $historicoManualArray, $historicoSensoresArray, $avarias): array {
            $registo = $registosUnificados[$piscina->id] ?? null;

            // Garante que a avaliação de temperatura conhece os limites da piscina.
            $registo?->setRelation('piscina', $piscina);

            $autorNome = $registo?->user?->name;
            $autorCurto = null;
            if ($autorNome) {
                $partes = explode(' ', trim($autorNome));
                $autorCurto = count($partes) > 1 ? $partes[0].' '.mb_substr($partes[count($partes) - 1], 0, 1).'.' : $partes[0];
            }

            $device = $sondas->get($piscina->id);
            $leitura = $device ? $ultimasLeituras->get($device->hanna_device_id) : null;

            // Reutiliza o que já foi carregado em lote acima (sondas, últimas
            // leituras e registos): sem isto eram ~8 queries por piscina.
            $avaria = $avarias->get($piscina->id);

            $source = app(SourceSelectionService::class)->selectSource(
                $piscina,
                $device,
                $leitura,
                $registo,
                usarCarregados: true,
                avariaCarregada: $avaria,
            );

            $idadeMin = $source['age_minutes'];
            $ph = $leitura?->ph !== null ? (float) $leitura->ph : null;
            $orp = $leitura?->orp !== null ? (float) $leitura->orp : null;
            $tempAgua = $leitura?->temperatura_agua !== null ? (float) $leitura->temperatura_agua : null;

            // Motivo legível, não o booleano: `is_artifact` a false passava o teste
            // `!== null` e o cartão dizia "artefacto" com a idade a false.
            $artefacto = $source['artifact_reason'];
            $controladorOnline = $source['source'] === 'hanna_online';
            $usarRegistoManual = $source['source'] === 'manual';

            $metricas4 = [];
            $phOkConformes = null;
            $cloroOkConformes = null;
            $tempOkConformes = null;

            $phMinFmt = number_format(DailyRecord::getPhMin(), 1, ',', '');
            $phMaxFmt = number_format(DailyRecord::getPhMax(), 1, ',', '');
            $limiteResumoPh = "{$phMinFmt}–{$phMaxFmt}";

            // 1. pH
            if ($controladorOnline) {
                $phOk = $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null;
                $phOkConformes = $phOk;
                $phValFmt = $ph !== null ? number_format($ph, 2, ',', '') : null;
                $metricas4['ph'] = [
                    'label' => 'pH',
                    'valor' => $phValFmt ?? '—',
                    'ok' => $phOk,
                    'origem' => 'controlador',
                    'idade' => match (true) {
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    },
                    'autor' => null,
                    'autor_curto' => null,
                    'limite_resumo' => $limiteResumoPh,
                    'tooltip' => match (true) {
                        $ph === null => 'pH sem leitura',
                        $phOk === false => "Alerta: pH {$phValFmt} fora do limite legal ({$limiteResumoPh} — CN 14/DA)",
                        default => "Conforme: pH {$phValFmt} (limite: {$limiteResumoPh} — CN 14/DA)",
                    },
                ];
            } elseif ($usarRegistoManual) {
                $phOk = $registo?->ph_efetivo !== null ? $registo->phConforme() : null;
                $phOkConformes = $phOk;
                $phValFmt = $registo?->ph_efetivo !== null ? number_format((float) $registo->ph_efetivo, 2, ',', '') : null;
                $metricas4['ph'] = [
                    'label' => 'pH',
                    'valor' => $phValFmt ?? '—',
                    'ok' => $phOk,
                    'origem' => 'manual',
                    'idade' => $registo->registado_em->locale('pt')->diffForHumans(),
                    'autor' => $autorNome,
                    'autor_curto' => $autorCurto,
                    'limite_resumo' => $limiteResumoPh,
                    'tooltip' => match (true) {
                        $phValFmt === null => 'pH sem registo',
                        $phOk === false => "Alerta: pH {$phValFmt} fora do limite legal ({$limiteResumoPh} — CN 14/DA)".($autorNome ? " · por {$autorNome}" : ''),
                        default => "Conforme: pH {$phValFmt} (limite: {$limiteResumoPh} — CN 14/DA)".($autorNome ? " · por {$autorNome}" : ''),
                    },
                ];
            } else {
                // Tenta controlador offline ou artefacto
                if ($leitura !== null) {
                    $phOk = $artefacto === null && $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null;
                    $phOkConformes = $phOk;
                    $phValFmt = $ph !== null ? number_format($ph, 2, ',', '') : null;
                    $metricas4['ph'] = [
                        'label' => 'pH',
                        'valor' => $phValFmt ?? '—',
                        'ok' => $phOk,
                        'origem' => $artefacto !== null ? 'artefacto' : 'controlador_offline',
                        'idade' => $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans(),
                        'autor' => null,
                        'autor_curto' => null,
                        'limite_resumo' => $limiteResumoPh,
                        'tooltip' => match (true) {
                            $ph === null => 'pH sem leitura',
                            $phOk === false => "Alerta: pH {$phValFmt} fora do limite legal ({$limiteResumoPh} — CN 14/DA)",
                            default => "Conforme: pH {$phValFmt} (limite: {$limiteResumoPh} — CN 14/DA)",
                        },
                    ];
                } else {
                    $metricas4['ph'] = [
                        'label' => 'pH',
                        'valor' => '—',
                        'ok' => null,
                        'origem' => 'sem_dados',
                        'idade' => '',
                        'autor' => null,
                        'autor_curto' => null,
                        'limite_resumo' => $limiteResumoPh,
                        'tooltip' => "pH sem dados (limite legal: {$limiteResumoPh})",
                    ];
                }
            }

            // 2. Redox (ORP)
            $valorOrp = null;
            $orpOk = null;
            $orpOrigem = 'sem_dados';
            $orpIdade = '';
            $orpMin = (int) ($piscina->orp_min ?? self::ORP_MIN);
            $orpMax = (int) ($piscina->orp_max ?? self::ORP_MAX);
            $limiteResumoOrp = "{$orpMin}–{$orpMax} mV";

            if ($controladorOnline) {
                $orpOk = $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $valorOrp = $orp !== null ? number_format($orp, 0, ',', '').' mV' : null;
                $cloroOkConformes = $orpOk;
                $orpOrigem = 'controlador';
                $orpIdade = match (true) {
                    $idadeMin < 1 => 'agora',
                    $idadeMin < 60 => "há {$idadeMin}m",
                    default => $leitura->lida_em->locale('pt')->diffForHumans(),
                };
            } elseif ($usarRegistoManual) {
                $orpNoMomento = $orpsNoMomento[$piscina->id] ?? null;
                if ($orpNoMomento !== null) {
                    $valorOrp = number_format($orpNoMomento, 0, ',', '').' mV';
                    $orpOk = $orpNoMomento >= ($piscina->orp_min ?? self::ORP_MIN) && $orpNoMomento <= ($piscina->orp_max ?? self::ORP_MAX);
                    $orpOrigem = 'manual';
                    $orpIdade = $registo->registado_em->locale('pt')->diffForHumans();
                }
                $livreOk = $registo?->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null;
                $cloroOkConformes = $livreOk;
            } elseif ($leitura !== null) {
                $orpOk = $artefacto === null && $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null;
                $cloroOkConformes = $orpOk;
                $valorOrp = $orp !== null ? number_format($orp, 0, ',', '').' mV' : null;
                $orpOrigem = $artefacto !== null ? 'artefacto' : 'controlador_offline';
                $orpIdade = $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans();
            }

            $metricas4['redox'] = [
                'label' => 'Redox (ORP)',
                'valor' => $valorOrp ?? '—',
                'ok' => $orpOk,
                'origem' => $orpOrigem,
                'idade' => $orpIdade,
                'autor' => $orpOrigem === 'manual' ? $autorNome : null,
                'autor_curto' => $orpOrigem === 'manual' ? $autorCurto : null,
                'limite_resumo' => $limiteResumoOrp,
                'tooltip' => match (true) {
                    $valorOrp === null => 'Redox (ORP) sem leitura',
                    $orpOk === false => "Alerta: {$valorOrp} fora do intervalo recomendado ({$limiteResumoOrp})".($orpOrigem === 'manual' && $autorNome ? " · por {$autorNome}" : ''),
                    default => "Conforme: {$valorOrp} (intervalo recomendado: {$limiteResumoOrp})".($orpOrigem === 'manual' && $autorNome ? " · por {$autorNome}" : ''),
                },
            ];

            // 3. Cloro Livre
            $livreOk = $registo?->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null;
            $clLivreVal = $registo?->cloro_livre_efetivo !== null ? (float) $registo->cloro_livre_efetivo : null;

            // Banda legal aplicável em função do pH da leitura e da data (CN 14/DA)
            $phParaBanda = $registo?->ph_efetivo !== null ? (float) $registo->ph_efetivo : ($controladorOnline ? $ph : null);
            $bandaLivre = LimitesLegaisService::bandaCloroLivre($phParaBanda, $registo?->registado_em);
            $bandaMinFmt = number_format($bandaLivre['min'], 1, ',', '');
            $bandaMaxFmt = number_format($bandaLivre['max'], 1, ',', '');
            $limiteResumoLivre = "{$bandaMinFmt}–{$bandaMaxFmt}";

            $clLivreValFmt = $clLivreVal !== null ? number_format($clLivreVal, 2, ',', '') : null;
            $phFmt = $phParaBanda !== null ? number_format($phParaBanda, 2, ',', '') : null;
            $contextoPh = $phFmt !== null ? " para pH {$phFmt}" : '';

            $tooltipLivre = match (true) {
                $clLivreVal === null => 'Cloro livre sem registo',
                $livreOk === false => "Alerta: {$clLivreValFmt} mg/L fora da banda legal ({$bandaMinFmt}–{$bandaMaxFmt} mg/L{$contextoPh} — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
                default => "Conforme: {$clLivreValFmt} mg/L dentro da banda legal ({$bandaMinFmt}–{$bandaMaxFmt} mg/L{$contextoPh} — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
            };

            $metricas4['livre'] = [
                'label' => 'Cl. Livre',
                'valor' => $clLivreValFmt !== null ? $clLivreValFmt.' mg/L' : '—',
                'ok' => $livreOk,
                'origem' => $registo ? 'manual' : 'sem_dados',
                'idade' => $registo ? $registo->registado_em->locale('pt')->diffForHumans() : '',
                'autor' => $registo ? $autorNome : null,
                'autor_curto' => $registo ? $autorCurto : null,
                'limite_resumo' => $limiteResumoLivre,
                'tooltip' => $tooltipLivre,
            ];
            if ($cloroOkConformes === null) {
                $cloroOkConformes = $livreOk;
            }

            // 4. Cloro Combinado (Sempre Manual se houver, independentemente do tempo)
            // Um combinado negativo é impossível (total < livre = erro de medição):
            // aparecia como "OK" verde. Passa a valor inválido, não a conforme.
            $combinadoValido = $registo?->cloro_combinado !== null && (float) $registo->cloro_combinado >= 0;
            $combOk = $combinadoValido ? $registo->cloroCombinadoConforme() : null;
            $combMax = LimitesLegaisService::cloroCombinadoMax($registo?->registado_em);
            $combMaxFmt = number_format($combMax, 1, ',', '');
            $limiteResumoComb = "≤ {$combMaxFmt}";
            $clCombVal = $combinadoValido ? (float) $registo->cloro_combinado : null;
            $clCombValFmt = $clCombVal !== null ? number_format($clCombVal, 2, ',', '') : null;

            $tooltipComb = match (true) {
                ! $combinadoValido && $registo?->cloro_combinado !== null => 'Medição inválida: cloro combinado negativo (total < livre)',
                $clCombVal === null => 'Cloro combinado sem registo',
                $combOk === false => "Alerta: {$clCombValFmt} mg/L acima do máximo legal (≤ {$combMaxFmt} mg/L — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
                default => "Conforme: {$clCombValFmt} mg/L (máximo legal: ≤ {$combMaxFmt} mg/L — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
            };

            $metricas4['combinado'] = [
                'label' => 'Cl. Combinado',
                'valor' => match (true) {
                    $combinadoValido => $clCombValFmt.' mg/L',
                    $registo?->cloro_combinado !== null => 'verificar medição',
                    default => '—',
                },
                'ok' => $combOk,
                'origem' => $registo ? 'manual' : 'sem_dados',
                'idade' => $registo ? $registo->registado_em->locale('pt')->diffForHumans() : '',
                'autor' => $registo ? $autorNome : null,
                'autor_curto' => $registo ? $autorCurto : null,
                'limite_resumo' => $limiteResumoComb,
                'tooltip' => $tooltipComb,
            ];

            // 5. Temperatura
            $tempMin = $piscina->temp_min !== null ? (float) $piscina->temp_min : null;
            $tempMax = $piscina->temp_max !== null ? (float) $piscina->temp_max : null;
            $limiteResumoTemp = ($tempMin !== null && $tempMax !== null)
                ? number_format($tempMin, 1, ',', '').'–'.number_format($tempMax, 1, ',', '').' °C'
                : null;

            $buildTempTooltip = function (?float $v, ?bool $ok, ?string $autor) use ($limiteResumoTemp): string {
                if ($v === null) {
                    return 'Temperatura da água sem leitura';
                }
                $vFmt = number_format($v, 1, ',', '').' °C';
                if ($limiteResumoTemp === null) {
                    return "Temperatura: {$vFmt}".($autor ? " · por {$autor}" : '');
                }
                $sufixo = $autor ? " · por {$autor}" : '';

                return $ok === false
                    ? "Alerta: {$vFmt} fora dos limites da piscina ({$limiteResumoTemp}){$sufixo}"
                    : "Conforme: {$vFmt} (limites da piscina: {$limiteResumoTemp}){$sufixo}";
            };

            if ($controladorOnline) {
                $tempOk = $tempAgua !== null && $tempMin !== null && $tempMax !== null
                    ? ($tempAgua >= $tempMin && $tempAgua <= $tempMax)
                    : null;
                $tempOkConformes = $tempOk;
                $metricas4['temp'] = [
                    'label' => 'Temp.',
                    'valor' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '').' °C' : '—',
                    'ok' => $tempOk,
                    'origem' => 'controlador',
                    'idade' => match (true) {
                        $idadeMin < 1 => 'agora',
                        $idadeMin < 60 => "há {$idadeMin}m",
                        default => $leitura->lida_em->locale('pt')->diffForHumans(),
                    },
                    'autor' => null,
                    'autor_curto' => null,
                    'limite_resumo' => $limiteResumoTemp,
                    'tooltip' => $buildTempTooltip($tempAgua, $tempOk, null),
                ];
            } elseif ($usarRegistoManual) {
                $tempOk = $registo?->temperatura_efetivo !== null ? $registo->temperaturaConforme() : null;
                $tempOkConformes = $tempOk;
                $tempVal = $registo?->temperatura_efetivo !== null ? (float) $registo->temperatura_efetivo : null;
                $metricas4['temp'] = [
                    'label' => 'Temp.',
                    'valor' => $tempVal !== null ? number_format($tempVal, 1, ',', '').' °C' : '—',
                    'ok' => $tempOk,
                    'origem' => 'manual',
                    'idade' => $registo->registado_em->locale('pt')->diffForHumans(),
                    'autor' => $autorNome,
                    'autor_curto' => $autorCurto,
                    'limite_resumo' => $limiteResumoTemp,
                    'tooltip' => $buildTempTooltip($tempVal, $tempOk, $autorNome),
                ];
            } else {
                if ($leitura !== null) {
                    $tempOk = $artefacto === null && $tempAgua !== null && $tempMin !== null && $tempMax !== null
                        ? ($tempAgua >= $tempMin && $tempAgua <= $tempMax)
                        : null;
                    $tempOkConformes = $tempOk;
                    $metricas4['temp'] = [
                        'label' => 'Temp.',
                        'valor' => $tempAgua !== null ? number_format($tempAgua, 1, ',', '').' °C' : '—',
                        'ok' => $tempOk,
                        'origem' => $artefacto !== null ? 'artefacto' : 'controlador_offline',
                        'idade' => $artefacto !== null ? $artefacto : $leitura->lida_em->locale('pt')->diffForHumans(),
                        'autor' => null,
                        'autor_curto' => null,
                        'limite_resumo' => $limiteResumoTemp,
                        'tooltip' => $buildTempTooltip($tempAgua, $tempOk, null),
                    ];
                } else {
                    $metricas4['temp'] = [
                        'label' => 'Temp.',
                        'valor' => '—',
                        'ok' => null,
                        'origem' => 'sem_dados',
                        'idade' => '',
                        'autor' => null,
                        'autor_curto' => null,
                        'limite_resumo' => $limiteResumoTemp,
                        'tooltip' => 'Temperatura sem dados',
                    ];
                }
            }

            // 6. Turbidez (só manual — sem variante de sonda/NS)
            $turbidezMax = LimitesLegaisService::transparenciaMax($registo?->registado_em);
            $turbMaxFmt = number_format($turbidezMax, 1, ',', '');
            $limiteResumoTurb = "≤ {$turbMaxFmt}";
            $turbidezOk = $registo?->transparencia !== null
                ? (float) $registo->transparencia <= $turbidezMax
                : null;
            $turbVal = $registo?->transparencia !== null ? (float) $registo->transparencia : null;
            $turbValFmt = $turbVal !== null ? number_format($turbVal, 2, ',', '') : null;

            $tooltipTurb = match (true) {
                $turbVal === null => 'Turbidez sem registo',
                $turbidezOk === false => "Alerta: {$turbValFmt} FNU acima do máximo legal (≤ {$turbMaxFmt} FNU — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
                default => "Conforme: {$turbValFmt} FNU (máximo legal: ≤ {$turbMaxFmt} FNU — CN 14/DA)".($autorNome ? " · registado por {$autorNome}" : ''),
            };

            $metricas4['turbidez'] = [
                'label' => 'Turbidez',
                'valor' => $turbValFmt !== null ? $turbValFmt.' FNU' : '—',
                'ok' => $turbidezOk,
                'origem' => $registo?->transparencia !== null ? 'manual' : 'sem_dados',
                'idade' => $registo?->transparencia !== null ? $registo->registado_em->locale('pt')->diffForHumans() : '',
                'autor' => $registo?->transparencia !== null ? $autorNome : null,
                'autor_curto' => $registo?->transparencia !== null ? $autorCurto : null,
                'limite_resumo' => $limiteResumoTurb,
                'tooltip' => $tooltipTurb,
            ];

            $getSparklineData = function (?string $origem, string $key) use ($piscina, $device, $historicoManualArray, $historicoSensoresArray) {
                if (in_array($origem, ['controlador', 'controlador_offline', 'artefacto'])) {
                    return array_filter($historicoSensoresArray->get($device?->hanna_device_id)?->pluck($key === 'redox' ? 'orp' : $key)->toArray() ?? [], fn ($v) => $v !== null);
                } elseif ($origem === 'manual') {
                    $k = $key === 'redox' ? 'orp' : ($key === 'livre' ? 'cloro_livre' : $key);

                    return array_filter($historicoManualArray->get($piscina->id)?->pluck($k)->toArray() ?? [], fn ($v) => $v !== null);
                }

                return [];
            };

            $metricas4['ph']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['ph']['origem'], 'ph'));
            $metricas4['redox']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['redox']['origem'], 'redox'));
            $metricas4['livre']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['livre']['origem'], 'livre'));
            $metricas4['combinado']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['combinado']['origem'], 'cloro_combinado'));
            $metricas4['temp']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['temp']['origem'], 'temperatura'));
            $metricas4['turbidez']['sparkline'] = self::generateSparkline($getSparklineData($metricas4['turbidez']['origem'], 'transparencia'));

            $encerramento = $piscina->encerramentoEm();

            return [
                'piscina' => $piscina,
                'registo' => $registo,
                // Encerrada não é "sem registo": o cartão fica na grelha (o
                // técnico tem de ver que existe e que está fechada), mas sem o
                // aviso de falta e fora das percentagens abaixo.
                'sem_hoje' => $encerramento === null && (! $registo || ! $registo->registado_em->isToday()),
                'encerramento' => $encerramento === null ? null : [
                    'motivo' => $encerramento->motivo_label,
                    'periodo' => $encerramento->descricao_periodo,
                    'agua_em_tratamento' => $encerramento->agua_em_tratamento,
                    'url' => EncerramentoPiscinas::getUrl(),
                ],
                // Estado da sonda independente da fonte escolhida: com um registo
                // manual fresco a cascata escolhia 'manual' e a sonda desaparecia
                // do cartão — o técnico não tinha como saber que estava offline.
                'sonda' => [
                    'instalada' => $device !== null,
                    'idade_min' => $leitura !== null ? (int) abs($leitura->lida_em->diffInMinutes(now())) : null,
                    // Avaria reportada: substitui o diagnóstico automático ("sem
                    // leituras há Xh — verificar controlador") pela causa conhecida.
                    'avaria' => $avaria === null ? null : [
                        'motivo' => $avaria->motivoLabel(),
                        'detalhe' => $avaria->detalhe,
                        'desde' => $avaria->aberta_em->format('d/m/Y H:i'),
                        'desde_humano' => $avaria->desdeHumano(),
                    ],
                ],
                'ultimo_registo_manual' => $registo && $registo->registado_em ? [
                    'autor' => $autorNome ?? 'Técnico',
                    'autor_curto' => $autorCurto ?? 'Técnico',
                    'idade' => $registo->registado_em->locale('pt')->diffForHumans(),
                    'hora' => $registo->registado_em->format('H:i'),
                ] : null,
                'metricas4' => $metricas4,
                'parametros_conformes' => [$phOkConformes, $cloroOkConformes, $tempOkConformes],
                'tem_dados_conformes' => $metricas4['ph']['valor'] !== '—' || $metricas4['redox']['valor'] !== '—' || $metricas4['livre']['valor'] !== '—',
                'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
            ];
        });

        // Percentagens só sobre piscinas abertas: com as encerradas no
        // denominador, "registos de hoje" nunca voltaria a 100% enquanto a época
        // estivesse fechada.
        $abertasMapped = $piscinasMapped->filter(fn ($p) => $p['encerramento'] === null);

        $totalPiscinas = $abertasMapped->count();
        $registadasHoje = $abertasMapped->filter(fn ($p) => ! $p['sem_hoje'])->count();
        $conformes = $abertasMapped->filter(fn ($p) => $p['tem_dados_conformes'] && collect($p['parametros_conformes'])->every(fn ($ok) => $ok !== false))->count();

        $percentagemRegisto = $totalPiscinas > 0 ? (int) (($registadasHoje / $totalPiscinas) * 100) : 0;
        $percentagemConforme = $totalPiscinas > 0 ? (int) (($conformes / $totalPiscinas) * 100) : 0;

        return [
            'piscinas' => $piscinasMapped,
            'urlRegistar' => DailyRecordResource::getUrl('create'),
            'encerradas' => $piscinasMapped->count() - $totalPiscinas,
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

        $d = 'M '.round($points[0][0], 1).','.round($points[0][1], 1);
        for ($i = 0; $i < count($points) - 1; $i++) {
            $p0 = $points[max(0, $i - 1)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min(count($points) - 1, $i + 2)];

            $cp1x = $p1[0] + ($p2[0] - $p0[0]) * 0.15;
            $cp1y = $p1[1] + ($p2[1] - $p0[1]) * 0.15;

            $cp2x = $p2[0] - ($p3[0] - $p1[0]) * 0.15;
            $cp2y = $p2[1] - ($p3[1] - $p1[1]) * 0.15;

            $d .= ' C '.round($cp1x, 1).','.round($cp1y, 1).' '.
                          round($cp2x, 1).','.round($cp2y, 1).' '.
                          round($p2[0], 1).','.round($p2[1], 1);
        }

        return [
            'fill' => $d." L {$width},{$height} L 0,{$height} Z",
            'stroke' => $d,
        ];
    }
}
