<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\FilterCheck;
use App\Models\HannaDevice;
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

        $stale = $registo === null
            || $registo->registado_em->lt(now()->subHours(self::STALE_HORAS));

        $tapAberta = TapAlert::query()
            ->where('pool_id', $piscina->id)
            ->whereNull('resolved_at')
            ->latest('opened_at')
            ->with('openedBy')
            ->first();

        return [
            'piscina' => $piscina,
            'registo' => $registo,
            'stale' => $stale,
            'torneira' => $this->estadoTorneira($registo, $tapAberta, $stale),
            'bomba' => $this->estadoBomba($registo, $stale),
            'filtro' => $this->estadoFiltro($piscina, $registo),
            'tanque' => $this->estadoTanque($piscina, $registo, $stale),
            'agua' => $this->valoresAgua($piscina, $registo),
            'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
        ];
    }

    private function estadoTorneira(?DailyRecord $registo, ?TapAlert $tapAberta, bool $stale): array
    {
        $estado = match (true) {
            $tapAberta !== null => 'aberta',
            $stale, $registo?->agua_modo === null => 'desconhecido',
            in_array($registo->agua_modo, ['on_com_agua', 'auto_com_agua'], true) => 'com_agua',
            default => 'fechada',
        };

        return [
            'estado' => $estado,
            'desde' => $tapAberta?->opened_at->format('d/m H:i'),
            'desde_humano' => $tapAberta?->opened_at->locale('pt')->diffForHumans(),
            'aberta_por' => $tapAberta?->openedBy?->name,
            'agua_modo' => $registo?->agua_modo !== null
                ? (self::AGUA_MODO_LABELS[$registo->agua_modo] ?? $registo->agua_modo)
                : null,
            'contador' => $registo?->contador_valor !== null
                ? number_format((float) $registo->contador_valor, 2, ',', ' ') . ' m³'
                : null,
            'contador_foto' => DailyRecord::getStorageUrl($registo?->contador_foto),
        ];
    }

    private function estadoBomba(?DailyRecord $registo, bool $stale): array
    {
        $estado = match (true) {
            $stale, $registo?->bomba_ferrada === null => 'desconhecido',
            (bool) $registo->bomba_ferrada => 'a_trabalhar',
            default => 'parada',
        };

        return [
            'estado' => $estado,
            'foto' => DailyRecord::getStorageUrl($registo?->bomba_foto),
        ];
    }

    private function estadoFiltro(Pool $piscina, ?DailyRecord $registo): array
    {
        $ultimaVerificacao = FilterCheck::query()
            ->where('pool_id', $piscina->id)
            ->where('tipo_operacao', 'lavagem')
            ->latest('verificado_em')
            ->first();

        $datas = collect([
            $ultimaVerificacao?->verificado_em,
            $registo?->filtro_faz_retrolavagem ? $registo->registado_em : null,
        ])->filter();

        $ultimaLavagem = $datas->sortDesc()->first();

        return [
            'ultima_lavagem' => $ultimaLavagem?->format('d/m/Y'),
            'lavado_hoje' => (bool) $ultimaLavagem?->isToday(),
        ];
    }

    private function estadoTanque(Pool $piscina, ?DailyRecord $registo, bool $stale): ?array
    {
        if (! $piscina->instalacao?->tanques_verificaveis) {
            return null;
        }

        $estado = match (true) {
            $stale, $registo?->tanque_ok === null => 'desconhecido',
            (bool) $registo->tanque_ok => 'ok',
            default => 'verificar',
        };

        return [
            'estado' => $estado,
            'observacoes' => $registo?->tanque_observacoes,
            'foto' => DailyRecord::getStorageUrl($registo?->tanque_foto),
        ];
    }

    /**
     * Mesma cascata de fontes do PainelPiscinasWidget::buildPoolData():
     * sonda fresca (≤60 min) → registo manual (≤8h) → sonda stale → sem dados.
     */
    private function valoresAgua(Pool $piscina, ?DailyRecord $registo): array
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

        $usarRegistoManual = ! $controladorOnline
            && $registo !== null
            && abs((int) $registo->registado_em->diffInHours(now())) <= 8;

        if ($controladorOnline || (! $usarRegistoManual && $leitura !== null)) {
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

        if ($usarRegistoManual) {
            $valores = [
                $this->valor('pH', $registo->ph_efetivo, 2, '', $registo->ph_efetivo !== null ? $registo->phConforme() : null),
                $this->valor('Cl. Livre', $registo->cloro_livre_efetivo, 2, ' mg/L', $registo->cloro_livre_efetivo !== null ? $registo->cloroLivreConforme() : null),
                $this->valor('Temp.', $registo->temperatura_efetivo, 1, ' °C', $registo->temperatura_efetivo !== null ? $registo->temperaturaConforme() : null),
            ];

            return [
                'origem' => 'Registo manual',
                'stale' => false,
                'atualizado' => $registo->registado_em->locale('pt')->diffForHumans(),
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
