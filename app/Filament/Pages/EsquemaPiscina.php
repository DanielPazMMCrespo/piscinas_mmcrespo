<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\OperationalActionResource;
use App\Models\DailyRecord;
use App\Models\FilterCheck;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\TapAlert;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Esquema visual do circuito de água de uma piscina: torneira/contador →
 * piscina → bomba → filtro → retorno. Cada componente reflete o estado do
 * último registo diário válido, dos tap_alerts em aberto e da sonda Hanna.
 */
class EsquemaPiscina extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Esquema';

    protected static ?string $title = 'Esquema do Circuito de Água';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.esquema-piscina';

    protected static ?string $slug = 'esquema';

    /** Estados do registo diário com mais de 24h já não descrevem o presente. */
    private const STALE_HORAS = 24;

    /** Mesmos ranges do PainelPiscinasWidget (BL132). */
    private const ORP_MIN = 660;
    private const ORP_MAX = 750;

    private const AGUA_MODO_LABELS = [
        'auto_com_agua' => 'Auto com água',
        'auto_sem_agua' => 'Auto sem água',
        'on_com_agua' => 'ON com água',
        'on_sem_agua' => 'ON sem água',
        'off' => 'OFF sem água',
    ];

    #[Url(as: 'pool')]
    public ?int $poolId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public function mount(): void
    {
        $permitidas = $this->piscinasPermitidas();

        if ($this->poolId === null || ! $permitidas->contains('id', $this->poolId)) {
            $this->poolId = $permitidas->first()?->id;
        }
    }

    public function selecionarPiscina(int $poolId): void
    {
        if ($this->piscinasPermitidas()->contains('id', $poolId)) {
            $this->poolId = $poolId;
        }
    }

    protected function getViewData(): array
    {
        $piscinas = $this->piscinasPermitidas();

        return [
            'grupos' => $piscinas->groupBy(fn (Pool $p) => $p->instalacao?->name ?? '—'),
            'esquema' => $this->buildEsquema($piscinas),
        ];
    }

    private function piscinasPermitidas(): Collection
    {
        $query = Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name');

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query->get();
    }

    private function buildEsquema(Collection $piscinas): ?array
    {
        $piscina = $piscinas->firstWhere('id', $this->poolId);

        if (! $piscina instanceof Pool) {
            return null;
        }

        $registo = DailyRecord::latestPerPool()
            ->where('pool_id', $piscina->id)
            ->with('utilizador')
            ->first();
        $registo?->setRelation('piscina', $piscina);

        // Ação operacional mais recente por tipo: é o evento mais fresco de cada
        // componente e, quando mais recente que o registo diário, define o estado.
        $acoes = OperationalAction::query()
            ->where('pool_id', $piscina->id)
            ->with('utilizador')
            ->orderByDesc('registado_em')
            ->get();
        $ultimaAcao = $acoes->groupBy('tipo')->map(fn (Collection $g) => $g->first());

        $stale = $registo === null
            || $registo->registado_em->lt(now()->subHours(self::STALE_HORAS));

        $tapAberta = TapAlert::query()
            ->where('pool_id', $piscina->id)
            ->whereNull('resolved_at')
            ->latest('opened_at')
            ->with('openedBy')
            ->first();

        $agua = $this->valoresAgua($piscina, $registo, $ultimaAcao->get(OperationalAction::TIPO_ANALISE_PONTUAL));

        return [
            'piscina' => $piscina,
            'registo' => $registo,
            'stale' => $stale,
            'torneira' => $this->estadoTorneira($registo, $tapAberta, $ultimaAcao),
            'bomba' => $this->estadoBomba($registo, $ultimaAcao->get(OperationalAction::TIPO_BOMBA)),
            'filtro' => $this->estadoFiltro($piscina, $registo, $acoes),
            'tanque' => $this->estadoTanque($piscina, $registo, $ultimaAcao->get(OperationalAction::TIPO_TANQUE)),
            'agua' => $agua,
            'justificacoes' => $agua['algum_mau'] ? $this->justificacoes($acoes) : [],
            'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
            'url_acoes_rapidas' => $this->urlsAcoesRapidas($piscina),
        ];
    }

    /**
     * URL de ação rápida por componente do esquema: permite registar só aquela
     * parte (ex.: "só fechar a torneira") sem preencher o registo diário completo.
     *
     * @return array<string, string>
     */
    private function urlsAcoesRapidas(Pool $piscina): array
    {
        $criar = fn (string $tipo) => OperationalActionResource::getUrl('create', ['pool' => $piscina->id, 'tipo' => $tipo]);

        return [
            'torneira' => $criar(OperationalAction::TIPO_TORNEIRA),
            'contador' => $criar(OperationalAction::TIPO_CONTADOR),
            'bomba' => $criar(OperationalAction::TIPO_BOMBA),
            'filtro' => $criar(OperationalAction::TIPO_LAVAGEM_FILTRO),
            'tanque' => $criar(OperationalAction::TIPO_TANQUE),
            'agua' => $criar(OperationalAction::TIPO_ANALISE_PONTUAL),
        ];
    }

    /**
     * Escolhe o candidato mais recente entre registo diário e ação operacional.
     * Cada candidato é ['ts' => Carbon, 'valor' => mixed, 'via' => string, ...].
     *
     * @param  array<string, mixed>|null  $dr
     * @param  array<string, mixed>|null  $oa
     * @return array<string, mixed>|null
     */
    private function maisRecente(?array $dr, ?array $oa): ?array
    {
        if ($dr !== null && $oa !== null) {
            return $oa['ts']->gte($dr['ts']) ? $oa : $dr;
        }

        return $dr ?? $oa;
    }

    private function estaStale(mixed $ts): bool
    {
        return $ts === null || $ts->lt(now()->subHours(self::STALE_HORAS));
    }

    /** @param array<string, mixed>|null $efetivo */
    private function fonte(?array $efetivo): ?array
    {
        if ($efetivo === null) {
            return null;
        }

        return [
            'via' => $efetivo['via'] === 'acao' ? 'Ação operacional' : 'Registo diário',
            'quando' => $efetivo['ts']->format('d/m/Y H:i'),
            'por' => $efetivo['por'] ?? null,
        ];
    }

    private function estadoTorneira(?DailyRecord $registo, ?TapAlert $tapAberta, Collection $ultimaAcao): array
    {
        $acao = $ultimaAcao->get(OperationalAction::TIPO_TORNEIRA);
        $acaoContador = $ultimaAcao->get(OperationalAction::TIPO_CONTADOR);

        $modoEfetivo = $this->maisRecente(
            $registo?->agua_modo !== null ? ['ts' => $registo->registado_em, 'valor' => $registo->agua_modo, 'via' => 'registo', 'por' => $registo->utilizador?->name] : null,
            ($acao && ($acao->dados['agua_modo'] ?? null) !== null) ? ['ts' => $acao->registado_em, 'valor' => $acao->dados['agua_modo'], 'via' => 'acao', 'por' => $acao->utilizador?->name] : null,
        );

        $contadorEfetivo = $this->maisRecente(
            $registo?->contador_valor !== null ? ['ts' => $registo->registado_em, 'valor' => (float) $registo->contador_valor, 'via' => 'registo', 'foto' => DailyRecord::getStorageUrl($registo->contador_foto)] : null,
            ($acaoContador && ($acaoContador->dados['contador_valor'] ?? null) !== null) ? ['ts' => $acaoContador->registado_em, 'valor' => (float) $acaoContador->dados['contador_valor'], 'via' => 'acao', 'foto' => DailyRecord::getStorageUrl($acaoContador->foto)] : null,
        );

        $modo = $modoEfetivo['valor'] ?? null;

        $estado = match (true) {
            $tapAberta !== null => 'aberta',
            $this->estaStale($modoEfetivo['ts'] ?? null), $modo === null => 'desconhecido',
            in_array($modo, ['on_com_agua', 'auto_com_agua'], true) => 'com_agua',
            default => 'fechada',
        };

        return [
            'estado' => $estado,
            'desde' => $tapAberta?->opened_at->format('d/m H:i'),
            'desde_humano' => $tapAberta?->opened_at->locale('pt')->diffForHumans(),
            'aberta_por' => $tapAberta?->openedBy?->name,
            'agua_modo' => $modo !== null ? (self::AGUA_MODO_LABELS[$modo] ?? $modo) : null,
            'contador' => isset($contadorEfetivo['valor'])
                ? number_format((float) $contadorEfetivo['valor'], 2, ',', ' ') . ' m³'
                : null,
            'contador_foto' => $contadorEfetivo['foto'] ?? null,
            'fonte' => $this->fonte($modoEfetivo),
        ];
    }

    private function estadoBomba(?DailyRecord $registo, ?OperationalAction $acao): array
    {
        $efetivo = $this->maisRecente(
            $registo?->bomba_ferrada !== null ? ['ts' => $registo->registado_em, 'valor' => (bool) $registo->bomba_ferrada, 'via' => 'registo', 'por' => $registo->utilizador?->name, 'foto' => DailyRecord::getStorageUrl($registo->bomba_foto)] : null,
            ($acao && ($acao->dados['bomba_ferrada'] ?? null) !== null) ? ['ts' => $acao->registado_em, 'valor' => (bool) $acao->dados['bomba_ferrada'], 'via' => 'acao', 'por' => $acao->utilizador?->name, 'foto' => DailyRecord::getStorageUrl($acao->foto)] : null,
        );

        $estado = match (true) {
            $this->estaStale($efetivo['ts'] ?? null), ! isset($efetivo['valor']) => 'desconhecido',
            $efetivo['valor'] === true => 'a_trabalhar',
            default => 'parada',
        };

        return [
            'estado' => $estado,
            'foto' => $efetivo['foto'] ?? null,
            'fonte' => $this->fonte($efetivo),
        ];
    }

    private function estadoFiltro(Pool $piscina, ?DailyRecord $registo, Collection $acoes): array
    {
        $ultimaVerificacao = FilterCheck::query()
            ->where('pool_id', $piscina->id)
            ->where('tipo_operacao', 'lavagem')
            ->latest('verificado_em')
            ->first();

        $ultimaAcaoLavagem = $acoes
            ->firstWhere('tipo', OperationalAction::TIPO_LAVAGEM_FILTRO);

        $datas = collect([
            $ultimaVerificacao?->verificado_em,
            $registo?->filtro_faz_retrolavagem ? $registo->registado_em : null,
            $ultimaAcaoLavagem?->registado_em,
        ])->filter();

        $ultimaLavagem = $datas->sortDesc()->first();

        return [
            'ultima_lavagem' => $ultimaLavagem?->format('d/m/Y'),
            'lavado_hoje' => (bool) $ultimaLavagem?->isToday(),
        ];
    }

    private function estadoTanque(Pool $piscina, ?DailyRecord $registo, ?OperationalAction $acao): ?array
    {
        if (! $piscina->instalacao?->tanques_verificaveis) {
            return null;
        }

        $efetivo = $this->maisRecente(
            $registo?->tanque_ok !== null ? ['ts' => $registo->registado_em, 'valor' => (bool) $registo->tanque_ok, 'via' => 'registo', 'por' => $registo->utilizador?->name, 'obs' => $registo->tanque_observacoes, 'foto' => DailyRecord::getStorageUrl($registo->tanque_foto)] : null,
            ($acao && ($acao->dados['tanque_ok'] ?? null) !== null) ? ['ts' => $acao->registado_em, 'valor' => (bool) $acao->dados['tanque_ok'], 'via' => 'acao', 'por' => $acao->utilizador?->name, 'obs' => $acao->observacoes, 'foto' => DailyRecord::getStorageUrl($acao->foto)] : null,
        );

        $estado = match (true) {
            $this->estaStale($efetivo['ts'] ?? null), ! isset($efetivo['valor']) => 'desconhecido',
            $efetivo['valor'] === true => 'ok',
            default => 'verificar',
        };

        return [
            'estado' => $estado,
            'observacoes' => $efetivo['obs'] ?? null,
            'foto' => $efetivo['foto'] ?? null,
            'fonte' => $this->fonte($efetivo),
        ];
    }

    /**
     * Ações operacionais das últimas 6h que podem explicar valores fora dos
     * limites (ex.: uma lavagem de filtro faz o pH/ORP cair no controlador).
     *
     * @return array<int, array{tipo: string, quando: string, por: ?string, observacoes: ?string}>
     */
    private function justificacoes(Collection $acoes): array
    {
        return $acoes
            ->filter(fn (OperationalAction $a) => $a->registado_em->gte(now()->subHours(6)))
            ->take(5)
            ->map(fn (OperationalAction $a) => [
                'tipo' => $a->tipoLabel(),
                'quando' => $a->registado_em->format('d/m H:i'),
                'por' => $a->utilizador?->name,
                'observacoes' => $a->observacoes,
            ])
            ->values()
            ->all();
    }

    /**
     * Cascata de fontes: sonda fresca (≤60 min) → leitura manual mais recente
     * (≤8h: registo diário ou análise rápida) → sonda stale → sem dados.
     */
    private function valoresAgua(Pool $piscina, ?DailyRecord $registo, ?OperationalAction $analise = null): array
    {
        $device = HannaDevice::query()
            ->where('active', true)
            ->where('pool_id', $piscina->id)
            ->first();

        $leitura = $device
            ? SensorReading::query()
                ->where('hanna_device_id', $device->hanna_device_id)
                ->latest('lida_em')
                ->first()
            : null;

        $idadeMin = $leitura?->lida_em ? (int) $leitura->lida_em->diffInMinutes(now()) : null;
        $controladorOnline = $leitura !== null && $idadeMin !== null && $idadeMin <= 60;

        // Leitura manual mais recente (≤8h): registo diário vs análise rápida.
        $manual = null;
        if (! $controladorOnline) {
            $manual = $this->maisRecente(
                ($registo !== null && abs((int) $registo->registado_em->diffInHours(now())) <= 8)
                    ? ['ts' => $registo->registado_em, 'ph' => $registo->ph_efetivo, 'cloro' => $registo->cloro_livre_efetivo, 'temp' => $registo->temperatura_efetivo, 'origem' => 'Registo manual']
                    : null,
                ($analise !== null && $analise->registado_em->gte(now()->subHours(8)))
                    ? ['ts' => $analise->registado_em, 'ph' => $analise->dados['ph'] ?? null, 'cloro' => $analise->dados['cloro_livre'] ?? null, 'temp' => $analise->dados['temperatura'] ?? null, 'origem' => 'Análise rápida']
                    : null,
            );
        }

        if ($controladorOnline || ($manual === null && $leitura !== null)) {
            $ph = $leitura->ph !== null ? (float) $leitura->ph : null;
            $orp = $leitura->orp !== null ? (float) $leitura->orp : null;
            $temp = $leitura->temperatura_agua !== null ? (float) $leitura->temperatura_agua : null;

            $valores = [
                $this->valor('pH', $ph, 2, '', $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null),
                $this->valor('ORP', $orp, 0, ' mV', $orp !== null ? ($orp >= ($piscina->orp_min ?? self::ORP_MIN) && $orp <= ($piscina->orp_max ?? self::ORP_MAX)) : null),
                $this->valor('Temp.', $temp, 1, ' °C', $temp !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                    ? ($temp >= (float) $piscina->temp_min && $temp <= (float) $piscina->temp_max)
                    : null),
            ];

            return [
                'origem' => $controladorOnline ? 'Controlador' : 'Controlador (desatualizado)',
                'stale' => ! $controladorOnline,
                'atualizado' => $leitura->lida_em->locale('pt')->diffForHumans(),
                'valores' => $valores,
                'algum_mau' => collect($valores)->contains(fn (array $v) => $v['ok'] === false),
            ];
        }

        if ($manual !== null) {
            $ph = $manual['ph'] !== null ? (float) $manual['ph'] : null;
            $cloro = $manual['cloro'] !== null ? (float) $manual['cloro'] : null;
            $temp = $manual['temp'] !== null ? (float) $manual['temp'] : null;

            $valores = [
                $this->valor('pH', $ph, 2, '', $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null),
                $this->valor('Cl. Livre', $cloro, 2, ' mg/L', $cloro !== null ? ($cloro >= DailyRecord::getCloroLivreMin() && $cloro <= DailyRecord::getCloroLivreMax()) : null),
                $this->valor('Temp.', $temp, 1, ' °C', $temp !== null && $piscina->temp_min !== null && $piscina->temp_max !== null
                    ? ($temp >= (float) $piscina->temp_min && $temp <= (float) $piscina->temp_max)
                    : null),
            ];

            return [
                'origem' => $manual['origem'],
                'stale' => false,
                'atualizado' => $manual['ts']->locale('pt')->diffForHumans(),
                'valores' => $valores,
                'algum_mau' => collect($valores)->contains(fn (array $v) => $v['ok'] === false),
            ];
        }

        return [
            'origem' => null,
            'stale' => true,
            'atualizado' => null,
            'valores' => [
                $this->valor('pH', null, 2, '', null),
                $this->valor('Cl. Livre', null, 2, ' mg/L', null),
                $this->valor('Temp.', null, 1, ' °C', null),
            ],
            'algum_mau' => false,
        ];
    }

    /** @return array{label: string, valor: string, ok: bool|null} */
    private function valor(string $label, mixed $valor, int $casas, string $sufixo, ?bool $ok): array
    {
        return [
            'label' => $label,
            'valor' => $valor !== null ? number_format((float) $valor, $casas, ',', '') . $sufixo : '—',
            'ok' => $valor !== null ? $ok : null,
        ];
    }
}
