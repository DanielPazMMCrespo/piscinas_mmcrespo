<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource;

use App\Constants\UserRole;
use App\Enums\EstadoConformidade;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\SensorReading;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use App\Services\DosageCalculatorService;
use App\Services\LeituraArtefactoService;
use App\Services\SourceSelectionService;
use App\Support\Auditoria;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class DailyRecordFormBuilder
{
    /**
     * Divergência manual↔sonda a partir da qual o hint avisa para confirmar
     * a medição (o HI97104 e a sonda calibrada não justificam desvios maiores).
     */
    private const DELTA_PH_AVISO = 0.2;

    private const DELTA_TEMP_AVISO = 1.0;

    /**
     * Janela (min) à volta da hora da colheita dentro da qual uma leitura da
     * sonda é aceite como contemporânea da amostra. Fora dela não se cruza.
     */
    private const TOLERANCIA_SONDA_MIN = 60;

    /** @var array<int, ?SensorReading> */
    private static array $sondaMemo = [];

    /** @var array<string, ?SensorReading> */
    private static array $sondaMomentoMemo = [];

    /**
     * Última leitura fresca da sonda Hanna (≤60 min, sem artefacto) para
     * comparação em tempo real com a análise manual. Memoizada por pedido —
     * os hints live() reavaliam várias vezes por render.
     */
    private static function sondaFresca(Pool $pool): ?SensorReading
    {
        if (! array_key_exists($pool->id, self::$sondaMemo)) {
            $selecao = app(SourceSelectionService::class)->selectSource($pool);
            self::$sondaMemo[$pool->id] = $selecao['source'] === 'hanna_online' ? $selecao['reading'] : null;
        }

        return self::$sondaMemo[$pool->id];
    }

    /**
     * Momento da colheita: data de hoje + hora escolhida (a data está fixa a
     * hoje no formulário). Sem hora escolhida, é "agora".
     */
    private static function momentoColheita(?string $horaColheita): Carbon
    {
        if (filled($horaColheita)) {
            $hora = Carbon::parse($horaColheita);

            return now()->setTime($hora->hour, $hora->minute, 0);
        }

        return now();
    }

    /**
     * Leitura da sonda a cruzar com a amostra, tendo em conta a hora da colheita
     * (suporta colheitas retroativas dentro do dia). Se o momento é praticamente
     * "agora" usa o caminho ao vivo (`sondaFresca`, ciente de artefacto/online);
     * caso contrário procura a leitura mais próxima dentro de ±TOLERANCIA_SONDA_MIN,
     * devolvendo null se não houver nenhuma nessa janela. Memoizada por pedido.
     */
    private static function sondaParaMomento(Pool $pool, ?string $horaColheita): ?SensorReading
    {
        $momento = self::momentoColheita($horaColheita);
        $chave = $pool->id.'|'.$momento->format('H:i');

        if (! array_key_exists($chave, self::$sondaMomentoMemo)) {
            if (abs($momento->diffInMinutes(now())) <= self::TOLERANCIA_SONDA_MIN) {
                self::$sondaMomentoMemo[$chave] = self::sondaFresca($pool);
            } else {
                $leitura = SensorReading::query()
                    ->where('pool_id', $pool->id)
                    ->whereBetween('lida_em', [
                        $momento->copy()->subMinutes(self::TOLERANCIA_SONDA_MIN),
                        $momento->copy()->addMinutes(self::TOLERANCIA_SONDA_MIN),
                    ])
                    ->get()
                    ->sortBy(fn (SensorReading $r): int => abs((int) $r->lida_em->diffInSeconds($momento)))
                    ->first();

                // O caminho retroativo não passa pela cascata de fontes: sem este
                // filtro cruzava a amostra com uma leitura de sonda avariada (ou
                // tirada durante uma lavagem) e acusava divergência do técnico.
                if ($leitura !== null && app(LeituraArtefactoService::class)->motivoEm($pool->id, $leitura->lida_em) !== null) {
                    $leitura = null;
                }

                self::$sondaMomentoMemo[$chave] = $leitura;
            }
        }

        return self::$sondaMomentoMemo[$chave];
    }

    /**
     * Cruza o valor manual com a leitura fresca da sonda: delta numérico para
     * pH/temperatura, contexto ORP para cloro livre (a sonda não mede cloro).
     * 'aviso' = true quando a divergência sugere erro de medição/amostragem.
     *
     * @return array{mensagem: string, aviso: bool}|null
     */
    private static function infoSonda(string $metrica, mixed $valor, Pool $pool, ?string $horaColheita = null): ?array
    {
        if (! filled($valor) || ! is_numeric($valor)) {
            return null;
        }

        $sonda = self::sondaParaMomento($pool, $horaColheita);
        if ($sonda === null) {
            return null;
        }

        $fmt = static fn (float $v, int $casas = 2): string => number_format($v, $casas, ',', '');
        $campo = str_starts_with($metrica, 'ns_') ? substr($metrica, 3) : $metrica;

        if ($campo === 'ph' && $sonda->ph !== null) {
            $delta = (float) $valor - (float) $sonda->ph;
            $aviso = abs($delta) >= self::DELTA_PH_AVISO;

            return [
                'mensagem' => 'Sonda: pH '.$fmt((float) $sonda->ph).' (Δ '.($delta >= 0 ? '+' : '−').$fmt(abs($delta)).')'
                    .($aviso ? ' — diverge da sonda, confirme a medição' : ''),
                'aviso' => $aviso,
                'delta' => $delta,
            ];
        }

        if ($campo === 'temperatura' && $sonda->temperatura_agua !== null) {
            $delta = (float) $valor - (float) $sonda->temperatura_agua;
            $aviso = abs($delta) >= self::DELTA_TEMP_AVISO;

            return [
                'mensagem' => 'Sonda: '.$fmt((float) $sonda->temperatura_agua, 1).' °C (Δ '.($delta >= 0 ? '+' : '−').$fmt(abs($delta), 1).')'
                    .($aviso ? ' — diverge da sonda, confirme a medição' : ''),
                'aviso' => $aviso,
                'delta' => $delta,
            ];
        }

        if ($campo === 'cloro_livre' && $sonda->orp !== null) {
            $orp = (float) $sonda->orp;
            $orpNaGama = $pool->orp_min !== null && $pool->orp_max !== null
                && $orp >= (float) $pool->orp_min && $orp <= (float) $pool->orp_max;
            $estado = DailyRecord::avaliarConformidade($metrica, $valor, $pool)['estado'];
            $aviso = $orpNaGama && $estado === EstadoConformidade::VERMELHO;

            return [
                'mensagem' => 'Sonda: ORP '.$fmt($orp, 0).' mV'
                    .($aviso ? ' — desinfeção na gama da piscina; confirme a medição antes de corrigir' : ''),
                'aviso' => $aviso,
                'delta' => null,
            ];
        }

        return null;
    }

    /**
     * Confronta a leitura fresca da sonda com os limites CN 14/DA da piscina
     * (pH via avaliarConformidade, ORP via Pool::orp_min/max, temperatura via
     * avaliarConformidade). Devolve os rótulos dos parâmetros fora da gama.
     *
     * @return array<int, string>
     */
    private static function sondaViolacoes(SensorReading $sonda, Pool $pool): array
    {
        $violacoes = [];

        if ($sonda->ph !== null && DailyRecord::avaliarConformidade('ph', $sonda->ph, $pool)['estado'] === EstadoConformidade::VERMELHO) {
            $violacoes[] = 'pH';
        }

        if ($sonda->orp !== null && $pool->orp_min !== null && $pool->orp_max !== null) {
            $orp = (float) $sonda->orp;
            if ($orp < (float) $pool->orp_min || $orp > (float) $pool->orp_max) {
                $violacoes[] = 'ORP';
            }
        }

        if ($sonda->temperatura_agua !== null && DailyRecord::avaliarConformidade('temperatura', $sonda->temperatura_agua, $pool)['estado'] === EstadoConformidade::VERMELHO) {
            $violacoes[] = 'Temperatura';
        }

        return $violacoes;
    }

    private static function isNS(): bool
    {
        return auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;
    }

    private static bool $modoRapido = false;

    private static ?int $poolFixo = null;

    /** @var array<int, ?DailyRecord> */
    private static array $ultimoRegistoMemo = [];

    /**
     * O contexto do atalho "Registo Rápido" vem da query string, mas os POSTs do
     * Livewire não a incluem — sem isto o modo rápido desfazia-se no primeiro
     * roundtrip e o wizard voltava aos 6 passos. A página aplica-o em cada pedido.
     */
    public static function aplicarContexto(bool $modoRapido, ?int $poolFixo): void
    {
        self::$modoRapido = $modoRapido;
        self::$poolFixo = $poolFixo;
    }

    private static function modoRapido(): bool
    {
        return self::$modoRapido || request()->query('quick') == '1';
    }

    private static function poolFixo(): ?int
    {
        return self::$poolFixo ?: (request()->integer('pool') ?: null);
    }

    /**
     * Último registo não corrigido da piscina, memoizado por pedido: é lido pela
     * validação do contador e pelos helpers de "último valor" de vários campos.
     */
    /**
     * Nomes das piscinas da instalação que já têm registo de hoje, para avisar
     * antes de duplicar (a correção append-only é o caminho certo nesse caso).
     */
    private static function piscinasJaRegistadasHoje(int|string|null $installationId): ?string
    {
        if (blank($installationId)) {
            return null;
        }

        $nomes = DailyRecord::query()
            ->whereDate('registado_em', today())
            ->whereDoesntHave('correcoes')
            ->whereHas('piscina', fn (Builder $q) => $q->where('installation_id', $installationId))
            ->with('piscina:id,name')
            ->get()
            ->pluck('piscina.name')
            ->filter()
            ->unique()
            ->implode(', ');

        return $nomes ?: null;
    }

    private static function ultimoRegisto(int $poolId): ?DailyRecord
    {
        if (! array_key_exists($poolId, self::$ultimoRegistoMemo)) {
            self::$ultimoRegistoMemo[$poolId] = DailyRecord::query()
                ->where('pool_id', $poolId)
                ->whereDoesntHave('correcoes')
                ->orderByDesc('registado_em')
                ->orderByDesc('id')
                ->first();
        }

        return self::$ultimoRegistoMemo[$poolId];
    }

    /**
     * Piscinas permitidas para o utilizador atual: todas para Admin/Técnico,
     * apenas as atribuídas via user_pools para Nadador-Salvador.
     */
    private static function piscinasPermitidas(Builder|HasMany $query): Builder|HasMany
    {
        if (self::isNS()) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        if (self::modoRapido() && self::poolFixo()) {
            $query->where('id', self::poolFixo());
        }

        // Piscina encerrada e parada não tem registo diário para fazer. Com a
        // água em tratamento (fechada ao público, química mantida) continua a
        // ter — daí o filtro ser pelo regime e não pelo encerramento em si.
        $query->whereDoesntHave('encerramentos', fn ($q) => $q
            ->vigenteEm(Carbon::now())
            ->where('agua_em_tratamento', false));

        return $query;
    }

    /**
     * Estado inicial de cada piscina no array `data.pools`. Necessário registar
     * estas chaves cedo: os campos das piscinas são construídos num schema dinâmico
     * (dependente de installation_id), e sem as chaves pré-existentes o @entangle
     * do Livewire falha ('property cannot be found') e parte a reatividade live().
     */
    private static function estadoInicialPiscinas(Installation $installation): array
    {
        $base = [
            'bomba_ferrada' => true,
            'contador_valor' => null,
            'agua_modo' => null,
            'bomba_foto' => null,
            'contador_foto' => null,
            'torneira_foto' => null,
            'tanque_ok' => true,
            'tanque_observacoes' => null,
            'tanque_foto' => null,
            'pressao_filtro' => null,
            'filtro_faz_retrolavagem' => false,
            'numero_lavagens_filtro' => 1,
            'timer_lavagem' => 3,
            'filtro_foto_retrolavagem' => null,
            'timer_enxaguamento' => 2,
            'filtro_foto_enxaguamento' => null,
            'filtro_foto_posicao_normal' => null,
            'ns_ph' => null,
            'ns_cloro_livre' => null,
            'ns_cloro_total' => null,
            'ns_temperatura' => null,
            'banhistas' => null,
            'adicoes' => [],
            'observacoes' => null,
        ];

        // Herdar o último estado conhecido da piscina (bomba, água, tanque) em vez
        // de pedir ao técnico que reintroduza o que não mudou desde ontem.
        return self::piscinasPermitidas($installation->piscinas())
            ->pluck('id')
            ->mapWithKeys(function ($id) use ($base) {
                $ultimo = self::ultimoRegisto((int) $id);

                return [(string) $id => array_merge($base, [
                    'bomba_ferrada' => $ultimo?->bomba_ferrada ?? true,
                    'agua_modo' => $ultimo?->agua_modo,
                    'tanque_ok' => $ultimo?->tanque_ok ?? true,
                ])];
            })
            ->toArray();
    }

    private static function semearEstadoPiscinas(Set $set, int|string|null $installationId): void
    {
        $installation = $installationId ? Installation::find($installationId) : null;

        $set('pools', $installation ? self::estadoInicialPiscinas($installation) : []);
        $set('ns_foto', null);
        // A hora da colheita perde-se quando o schema é remontado; sem isto o
        // técnico tem de a reintroduzir sempre que muda de instalação.
        $set('hora_colheita', now()->format('H:i'));
    }

    private static function fotoField(string $field, string $label, string $directory, bool $required = false, ?string $uniqueId = null): array
    {
        $component = Forms\Components\FileUpload::make($field)
            ->label($label)
            ->disk(DailyRecord::getStorageDisk())->visibility('public')
            ->directory($directory)
            ->image()
            ->imageEditor()
            ->imageResizeMode('cover')
            ->imageResizeTargetWidth('1024')
            ->maxSize(20480)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
            ->required($required)
            ->columnSpanFull();

        if ($uniqueId !== null) {
            $component->id($uniqueId);
        }

        return [$component];
    }

    /**
     * Agrupa campos de foto numa secção colapsável fechada por defeito.
     * As fotos são o elemento mais alto do formulário e raramente usadas em
     * cada registo — no telemóvel só ocupam espaço quando o técnico as expande.
     */
    private static function fotosSection(array $fotoFields): Forms\Components\Section
    {
        return Forms\Components\Section::make('Fotos (opcional)')
            ->icon('heroicon-o-camera')
            ->collapsible()
            ->collapsed()
            ->compact()
            ->columnSpanFull()
            ->schema(array_merge(...$fotoFields));
    }

    /**
     * Um valor de 0/0.00 num parâmetro legal é quase sempre sintoma de algo
     * (sonda avariada, sem reagente, não medido) e não uma leitura real —
     * exige-se justificação para não passar despercebido no livro sanitário.
     */
    private static function valorEhZero(mixed $valor): bool
    {
        return filled($valor) && (float) $valor === 0.0;
    }

    private static function algumValorZero(Get $get): bool
    {
        foreach (['ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'] as $campo) {
            if (self::valorEhZero($get($campo))) {
                return true;
            }
        }

        return false;
    }

    private static function comSemaforo(Forms\Components\TextInput $campo, string $metrica, Pool $pool): Forms\Components\TextInput
    {
        return $campo
            ->live(onBlur: true)
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->suffix(function (Get $get, $livewire) use ($campo, $metrica, $pool): ?HtmlString {
                $val = $get($campo->getName());
                if (! filled($val)) {
                    return null;
                }

                $sonda = self::infoSonda($metrica, $val, $pool, $livewire->data['hora_colheita'] ?? null);
                if ($sonda === null || !array_key_exists('delta', $sonda) || $sonda['delta'] === null) {
                    return null;
                }

                $corBg = $sonda['aviso'] ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300 ring-1 ring-danger-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700';
                $icon = $sonda['delta'] > 0 ? '↑' : '↓';
                
                return new HtmlString("
                    <span class='inline-flex items-center gap-1 px-2 py-0.5 text-xs font-bold rounded-md {$corBg} shadow-sm' title='Diferença face à sonda'>
                        <span>{$icon}</span>
                        <span>" . number_format(abs($sonda['delta']), 2, ',', '') . "</span>
                    </span>
                ");
            })
            ->hint(function (Get $get, $livewire) use ($campo, $metrica, $pool): ?string {
                $val = $get($campo->getName());
                if (! filled($val)) {
                    return null;
                }

                $eval = DailyRecord::avaliarConformidade($metrica, $val, $pool);
                $msg = $eval['mensagem'] ?: null;

                if ($eval['estado'] !== EstadoConformidade::VERDE) {
                    $param = match ($metrica) {
                        'ns_ph' => 'ph',
                        'ns_cloro_livre' => 'cloro_livre',
                        default => null,
                    };
                    if ($param) {
                        $dosagem = app(DosageCalculatorService::class)->calcularDose($pool, $param, (float) $val);
                        if ($dosagem && ($dosagem['dose_com_fator_ml'] ?? 0) > 0 && isset($dosagem['produto'])) {
                            $prodNome = $dosagem['produto']->name;
                            $msg .= " | ⚡ Sugestão: +{$dosagem['dose_formatada']} de {$prodNome}";
                        }
                    }
                }

                $sonda = self::infoSonda($metrica, $val, $pool, $livewire->data['hora_colheita'] ?? null);
                if ($sonda !== null && (!array_key_exists('delta', $sonda) || $sonda['delta'] === null)) {
                    $msg = ($msg ? $msg.' | ' : '').$sonda['mensagem'];
                }

                return $msg;
            })
            ->hintColor(function (Get $get, $livewire) use ($campo, $metrica, $pool): ?string {
                $val = $get($campo->getName());
                $cor = match (DailyRecord::avaliarConformidade($metrica, $val, $pool)['estado']) {
                    EstadoConformidade::VERDE => 'success',
                    EstadoConformidade::AMARELO => 'warning',
                    EstadoConformidade::VERMELHO => 'danger',
                    EstadoConformidade::NEUTRO => null,
                };

                // Conforme mas a divergir da sonda: sinaliza possível erro de medição.
                if ($cor === 'success' || $cor === null) {
                    $sonda = self::infoSonda($metrica, $val, $pool, $livewire->data['hora_colheita'] ?? null);
                    if ($sonda !== null && $sonda['aviso']) {
                        return 'warning';
                    }
                }

                return $cor;
            })
            ->extraAttributes(function (Get $get) use ($metrica, $pool, $campo) {
                $classes = [];
                if (DailyRecord::avaliarConformidade($metrica, $get($campo->getName()), $pool)['estado'] === EstadoConformidade::VERMELHO) {
                    $classes[] = 'ring-2 ring-danger-500 ring-inset bg-danger-50 dark:bg-danger-900/30';
                }

                return ['class' => implode(' ', $classes)];
            });
    }

    public static function form(Form $form): Form
    {
        if ($form->getOperation() !== 'create') {
            return $form->schema([Forms\Components\Placeholder::make('Edição não suportada neste Wizard.')]);
        }

        return $form->schema([
            Forms\Components\Section::make('Início')
                // Depois de escolher a instalação esta secção é peso morto no topo
                // de todos os passos do wizard (372 px em telemóvel).
                ->collapsible()
                ->collapsed(fn (Get $get) => filled($get('installation_id')))
                ->compact()
                ->schema([
                    Forms\Components\Select::make('installation_id')
                        ->label('Instalação')
                        ->options(function () {
                            $query = Installation::query();

                            if (self::isNS()) {
                                $poolIds = auth()->user()->piscinas()->pluck('pools.id');
                                $query->whereHas('piscinas', fn (Builder $q) => $q->whereIn('id', $poolIds));
                            }

                            return $query->pluck('name', 'id');
                        })
                        ->required()
                        ->live()
                        ->default(function () {
                            if ($poolFixo = self::poolFixo()) {
                                if ($p = Pool::find($poolFixo)) {
                                    return $p->installation_id;
                                }
                            }

                            $pool = Pool::whereHas('users', fn ($q) => $q->where('users.id', auth()->id()))->first();
                            if ($pool) {
                                return $pool->installation_id;
                            }

                            // Técnico/admin sem piscinas atribuídas: usar a instalação do
                            // último registo que ele próprio fez (o lookback documentado).
                            return DailyRecord::query()
                                ->where('user_id', auth()->id())
                                ->orderByDesc('registado_em')
                                ->with('piscina')
                                ->first()?->piscina?->installation_id
                                ?? Installation::query()->where('active', true)->value('id');
                        })
                        ->afterStateHydrated(function (Set $set, Get $get, $state) {
                            if (filled($state) && blank($get('pools'))) {
                                self::semearEstadoPiscinas($set, $state);
                            }
                        })
                        ->afterStateUpdated(fn (Set $set, $state) => self::semearEstadoPiscinas($set, $state)),
                    // Responsável é sempre quem está autenticado: um select desativado
                    // só repetia o nome, gastava uma query e 96 px de ecrã.
                    Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id())
                        ->dehydrated(),
                    Forms\Components\DatePicker::make('registado_em')
                        ->label('Data do Registo')
                        ->default(now())
                        ->required()
                        ->disabled(fn () => self::isNS())
                        ->dehydrated(),
                    // Registar duas vezes a mesma piscina no mesmo dia não é
                    // bloqueado (pode ser legítimo), mas deixa de ser silencioso.
                    Forms\Components\Placeholder::make('aviso_registo_hoje')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => filled(self::piscinasJaRegistadasHoje($get('installation_id'))))
                        ->content(function (Get $get): ?HtmlString {
                            $nomes = self::piscinasJaRegistadasHoje($get('installation_id'));
                            if (blank($nomes)) {
                                return null;
                            }

                            return new HtmlString(
                                '<div class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700/60 text-amber-900 dark:text-amber-200 text-sm">'
                                .'⚠️ Já existe registo de hoje para: <strong>'.e($nomes).'</strong>. '
                                .'Para alterar um valor use a ação <em>Corrigir</em> na lista de registos.'
                                .'</div>'
                            );
                        }),
                ])->columns(3),

            Forms\Components\Section::make('Registo Diário')
                ->visible(fn (Get $get) => filled($get('installation_id')))
                ->schema(function (Get $get) {
                    $installationId = $get('installation_id');
                    if (! $installationId) {
                        return [];
                    }
                    $installation = Installation::find($installationId);
                    if (! $installation) {
                        return [];
                    }

                    $modoRapido = ! self::isNS() && self::modoRapido();

                    $poolsByBombas = self::piscinasPermitidas($installation->piscinas())->orderBy('ordem_bombas')->get();
                    $poolsByFiltros = self::piscinasPermitidas($installation->piscinas())->orderBy('ordem_filtros')->get();

                    $bombasSchema = fn (Pool $pool) => [
                                    Forms\Components\Toggle::make('bomba_ferrada')
                                        ->id("bomba_ferrada_{$pool->id}")
                                        ->label('Bomba ferrada')
                                        ->default(true),
                                    Forms\Components\TextInput::make('contador_valor')
                                        ->id("contador_valor_{$pool->id}")
                                        ->label('Contador (m³)')
                                        ->numeric()->step(0.01)->minValue(0)
                                        // A última leitura só aparecia na mensagem de erro, depois de
                                        // falhar a validação e voltar 5 passos atrás no wizard.
                                        ->helperText(function () use ($pool): ?string {
                                            $ultimo = self::ultimoRegisto($pool->id);
                                            if (! $ultimo || $ultimo->contador_valor === null) {
                                                return null;
                                            }

                                            return 'Última: '.number_format((float) $ultimo->contador_valor, 2, ',', ' ')
                                                .' m³ ('.$ultimo->registado_em->format('d/m H:i').')';
                                        })
                                        ->rules([
                                            fn (): Closure => function (string $attribute, $value, Closure $fail) use ($pool) {
                                                $ultimo = self::ultimoRegisto($pool->id);
                                                if (filled($value) && $ultimo && $ultimo->contador_valor !== null
                                                    && (float) $value < (float) $ultimo->contador_valor) {
                                                    $fail('A leitura ('.$value.') é inferior à última ('.$ultimo->contador_valor.'). O contador só avança.');
                                                }
                                            },
                                        ]),
                                    Forms\Components\Select::make('agua_modo')
                                        ->id("agua_modo_{$pool->id}")
                                        ->label('Água')
                                        ->options([
                                            'auto_com_agua' => 'Auto com água',
                                            'auto_sem_agua' => 'Auto sem água',
                                            'on_com_agua' => 'ON com água',
                                            'on_sem_agua' => 'ON sem água',
                                            'off' => 'OFF sem água',
                                        ]),
                                    self::fotosSection([
                                        self::fotoField('bomba_foto', 'Foto bomba', 'bomba', false, "bomba_foto_{$pool->id}"),
                                        self::fotoField('contador_foto', 'Foto contador da água', 'contador', false, "contador_foto_{$pool->id}"),
                                        self::fotoField('torneira_foto', 'Foto da torneira', 'torneira', false, "torneira_foto_{$pool->id}"),
                                    ]),
                                ];

                    $tanquesSchema = fn (Pool $pool) => [
                                    Forms\Components\Toggle::make('tanque_ok')
                                        ->id("tanque_ok_{$pool->id}")
                                        ->label('Tanque OK')->default(true),
                                    Forms\Components\Textarea::make('tanque_observacoes')
                                        ->id("tanque_observacoes_{$pool->id}")
                                        ->label('Observações'),
                                    self::fotosSection([
                                        self::fotoField('tanque_foto', 'Foto Tanque', 'tanque', false, "tanque_foto_{$pool->id}"),
                                    ]),
                                ];

                    $lavagemSchema = fn (Pool $pool) => [
                                    Forms\Components\Placeholder::make("historico_lavagem_{$pool->id}")
                                        ->label('Histórico de Retrolavagens')
                                        ->content(function () use ($pool): HtmlString {
                                            $ultima = DailyRecord::query()
                                                ->where('pool_id', $pool->id)
                                                ->where('filtro_faz_retrolavagem', true)
                                                ->orderByDesc('registado_em')
                                                ->first();
                                            if (! $ultima) {
                                                return new HtmlString('<span class="text-sm text-slate-500">Sem registo anterior de retrolavagem.</span>');
                                            }
                                            $dias = (int) $ultima->registado_em->diffInDays(now());
                                            $alerta = $dias >= 7 ? ' <span class="text-amber-600 dark:text-amber-400 font-bold">⚠️ Recomendada lavagem (>7 dias)</span>' : '';

                                            return new HtmlString(
                                                "<span class=\"text-sm font-medium\">Última: há {$dias} dia(s) ({$ultima->registado_em->format('d/m/Y')}) — {$ultima->numero_lavagens_filtro} ciclo(s){$alerta}</span>"
                                            );
                                        }),
                                    Forms\Components\TextInput::make('pressao_filtro')
                                        ->id("pressao_filtro_{$pool->id}")
                                        ->label('Pressão do Filtro (bar)')
                                        ->numeric()
                                        ->step(0.05)
                                        ->live(onBlur: true)
                                        ->helperText(function (Get $get): ?string {
                                            $val = $get('pressao_filtro');
                                            if (blank($val)) {
                                                return null;
                                            }
                                            $pressao = (float) $val;
                                            if ($pressao >= 1.5) {
                                                return '⚠️ Pressão elevada ('.$pressao.' bar)! Recomendada retrolavagem urgente do filtro.';
                                            } elseif ($pressao >= 1.2) {
                                                return 'ℹ️ Pressão moderada ('.$pressao.' bar). Considere programar lavagem brevemente.';
                                            }

                                            return '✅ Pressão normal ('.$pressao.' bar).';
                                        }),
                                    Forms\Components\Toggle::make('filtro_faz_retrolavagem')
                                        ->id("filtro_faz_retrolavagem_{$pool->id}")
                                        ->label('Fazer retrolavagem?')->default(false)->live(),
                                    Forms\Components\TextInput::make('numero_lavagens_filtro')
                                        ->id("numero_lavagens_filtro_{$pool->id}")
                                        ->label('Nº de lavagens')
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(1)
                                        ->visible(fn (Get $get) => $get('filtro_faz_retrolavagem')),
                                    Forms\Components\ViewField::make('timer_lavagem')
                                        ->id("timer_lavagem_{$pool->id}")
                                        ->view('filament.timer-retrolavagem')
                                        ->default(3)
                                        ->visible(fn (Get $get) => $get('filtro_faz_retrolavagem')),
                                    self::fotosSection([
                                        self::fotoField('filtro_foto_retrolavagem', 'Foto da lavagem', 'filtros', false, "filtro_foto_retrolavagem_{$pool->id}"),
                                    ])->visible(fn (Get $get) => $get('filtro_faz_retrolavagem')),
                                ];

                    $enxaguamentoSchema = fn (Pool $pool) => [
                                    Forms\Components\ViewField::make('timer_enxaguamento')
                                        ->id("timer_enxaguamento_{$pool->id}")
                                        ->view('filament.timer-retrolavagem')
                                        ->default(2),
                                    self::fotosSection([
                                        self::fotoField('filtro_foto_enxaguamento', 'Foto do enxaguamento', 'filtros', false, "filtro_foto_enxaguamento_{$pool->id}"),
                                    ]),
                                ];

                    $posicaoNormalSchema = fn (Pool $pool) => [
                                    ...self::fotoField('filtro_foto_posicao_normal', 'Foto posição normal', 'filtros', false, "filtro_foto_posicao_normal_{$pool->id}"),
                                ];

                    $globaisSchema = [
                        Forms\Components\TimePicker::make('hora_colheita')
                            ->label('Hora da colheita')
                            ->helperText('Hora oficial do registo. Recue-a se a colheita foi mais cedo — a comparação com a sonda usa a leitura mais próxima desta hora.')
                            ->seconds(false)
                            ->default(now())
                            ->live(onBlur: true),
                        ...self::fotoField('ns_foto', 'Foto do quadro NS', 'ns-fotos', self::isNS(), 'ns_foto_global'),
                    ];

                    $nsSchema = fn (Pool $pool) => [
                                    Forms\Components\Placeholder::make("sonda_referencia_{$pool->id}")
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->content(function ($livewire) use ($pool): ?HtmlString {
                                            $horaColheita = $livewire->data['hora_colheita'] ?? null;
                                            $sonda = self::sondaParaMomento($pool, $horaColheita);
                                            if ($sonda !== null) {
                                                if ($sonda->ph === null && $sonda->orp === null && $sonda->temperatura_agua === null) {
                                                    return null;
                                                }

                                                $violacoes = self::sondaViolacoes($sonda, $pool);

                                                if ($violacoes === []) {
                                                    return new HtmlString(
                                                        '<div class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 text-emerald-900 dark:text-emerald-200 text-sm">'
                                                        .'📡 Sonda: ✓ Conforme'
                                                        .'</div>'
                                                    );
                                                }

                                                return new HtmlString(
                                                    '<div class="p-2 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800/60 text-red-900 dark:text-red-200 text-sm">'
                                                    .'📡 Sonda: Não conforme - Valor '.implode(' e ', $violacoes).', por favor considera refazer a medição.'
                                                    .'</div>'
                                                );
                                            }

                                            // Sem leitura próxima do momento: fallback neutro. A mensagem
                                            // distingue "hora retroativa sem leitura" de "sonda indisponível".
                                            $mensagem = filled($horaColheita)
                                                ? 'ℹ️ Sem leitura da sonda próxima da hora da colheita. Introduza a sua própria análise.'
                                                : 'ℹ️ Sonda Hanna não disponível. Introduza a sua própria análise.';

                                            return new HtmlString(
                                                '<div class="p-2 rounded-lg bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 text-sm">'
                                                .$mensagem
                                                .'</div>'
                                            );
                                        }),
                                    self::comSemaforo(Forms\Components\TextInput::make('ns_ph')->id("ns_ph_{$pool->id}")->label('pH')->numeric()->step(0.01)->minValue(0)->maxValue(14)->required(), 'ns_ph', $pool),
                                    self::comSemaforo(Forms\Components\TextInput::make('ns_cloro_livre')->id("ns_cloro_livre_{$pool->id}")->label('Cl livre')->numeric()->step(0.01)->required(), 'ns_cloro_livre', $pool),
                                    self::comSemaforo(Forms\Components\TextInput::make('ns_cloro_total')
                                        ->id("ns_cloro_total_{$pool->id}")
                                        ->label('Cl total')
                                        ->numeric()
                                        ->step(0.01)
                                        ->required(), 'ns_cloro_total', $pool),
                                    self::comSemaforo(Forms\Components\TextInput::make('ns_temperatura')->id("ns_temperatura_{$pool->id}")->label('Temp')->numeric()->step(0.01)->required(), 'ns_temperatura', $pool),
                                    Forms\Components\TextInput::make('banhistas')
                                        ->id("banhistas_{$pool->id}")
                                        ->label('Banhistas')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->extraInputAttributes(['inputmode' => 'numeric'])
                                        ->helperText('Nº de banhistas desde o último registo.'),
                                    Forms\Components\Textarea::make('observacoes')
                                        ->id("observacoes_zero_{$pool->id}")
                                        ->label('Motivo do valor 0')
                                        ->helperText('Um dos parâmetros está a 0. Indique o motivo (sonda avariada, sem reagente, não medido, etc.).')
                                        ->required(fn (Get $get) => self::algumValorZero($get))
                                        ->visible(fn (Get $get) => self::algumValorZero($get))
                                        ->columnSpanFull(),
                                ];

                    $observacoesSchema = fn (Pool $pool, $installation) => [
                                    Forms\Components\Placeholder::make("sugestao_dosagem_banner_{$pool->id}")
                                        ->hiddenLabel()
                                        ->content(function (Get $get) use ($pool) {
                                            $ph = $get("pools.{$pool->id}.ns_ph");
                                            $cl = $get("pools.{$pool->id}.ns_cloro_livre");

                                            $sugestoes = [];
                                            $calculator = app(DosageCalculatorService::class);

                                            if (filled($ph)) {
                                                $dosePh = $calculator->calcularDose($pool, 'ph', (float) $ph);
                                                if ($dosePh && ($dosePh['dose_com_fator_ml'] ?? 0) > 0) {
                                                    $prod = e($dosePh['produto']?->name ?? 'Produto pH');
                                                    $sugestoes[] = '• <strong>pH ('.number_format((float) $ph, 2, ',', '')."):</strong> {$dosePh['explicacao']} Dose sugerida: <strong>{$dosePh['dose_formatada']}</strong> de <em>{$prod}</em>";
                                                }
                                            }

                                            if (filled($cl)) {
                                                $doseCl = $calculator->calcularDose($pool, 'cloro_livre', (float) $cl);
                                                if ($doseCl && ($doseCl['dose_com_fator_ml'] ?? 0) > 0) {
                                                    $prod = e($doseCl['produto']?->name ?? 'Cloro');
                                                    $sugestoes[] = '• <strong>Cloro Livre ('.number_format((float) $cl, 2, ',', '')." ppm):</strong> {$doseCl['explicacao']} Dose sugerida: <strong>{$doseCl['dose_formatada']}</strong> de <em>{$prod}</em>";
                                                }
                                            }

                                            if (empty($sugestoes)) {
                                                return null;
                                            }

                                            $html = '<div class="p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700/60 rounded-lg text-amber-900 dark:text-amber-200 text-sm space-y-1 mb-2">';
                                            $html .= '<div class="font-semibold flex items-center gap-1.5"><span class="text-base">⚡</span> <span>Sugestões Automáticas de Dosagem (Ação Corretiva Recomendada)</span></div>';
                                            foreach ($sugestoes as $sug) {
                                                $html .= "<div>{$sug}</div>";
                                            }
                                            $html .= '</div>';

                                            return new HtmlString($html);
                                        })
                                        ->visible(function (Get $get) use ($pool) {
                                            $ph = $get("pools.{$pool->id}.ns_ph");
                                            $cl = $get("pools.{$pool->id}.ns_cloro_livre");

                                            return filled($ph) || filled($cl);
                                        })
                                        ->columnSpanFull(),
                                    Forms\Components\Repeater::make('adicoes')
                                        ->id("adicoes_{$pool->id}")
                                        ->label('Adições de Químicos')
                                        ->schema([
                                            Forms\Components\Select::make('product_id')
                                                ->label('Produto')
                                                ->options(Product::query()->pluck('name', 'id'))
                                                ->required()
                                                ->live(),
                                            Forms\Components\TextInput::make('quantity')
                                                ->label('Quantidade')
                                                ->numeric()
                                                ->minValue(0.01)
                                                ->step(0.01)
                                                ->required()
                                                ->live(onBlur: true)
                                                ->hint(function (Get $get) use ($installation) {
                                                    $productId = $get('product_id');
                                                    if (! $productId) {
                                                        return null;
                                                    }
                                                    $stock = StockInstallation::where('installation_id', $installation->id)
                                                        ->where('product_id', $productId)->first();
                                                    $produto = Product::find($productId);
                                                    $disponivel = $stock?->quantity ?? 0;

                                                    return "Disponível na instalação: {$disponivel} {$produto?->unidade}";
                                                })
                                                ->hintColor(function (Get $get) use ($installation) {
                                                    $productId = $get('product_id');
                                                    $value = $get('quantity');
                                                    if (! $productId) {
                                                        return 'gray';
                                                    }
                                                    $stock = StockInstallation::where('installation_id', $installation->id)
                                                        ->where('product_id', $productId)->first();
                                                    $disponivel = (float) ($stock?->quantity ?? 0);
                                                    if (! $value) {
                                                        return 'gray';
                                                    }

                                                    return $disponivel < (float) $value ? 'danger' : 'gray';
                                                })
                                                ->hintAction(
                                                    Forms\Components\Actions\Action::make('adicionarStockInsuficiente')
                                                        ->label('Adicionar stock')
                                                        ->icon('heroicon-o-plus-circle')
                                                        ->color('danger')
                                                        ->visible(function (Get $get) use ($installation) {
                                                            $productId = $get('product_id');
                                                            $value = $get('quantity');
                                                            if (! $productId || ! $value) {
                                                                return false;
                                                            }
                                                            $stock = StockInstallation::where('installation_id', $installation->id)
                                                                ->where('product_id', $productId)->first();
                                                            $disponivel = (float) ($stock?->quantity ?? 0);

                                                            return $disponivel < (float) $value;
                                                        })
                                                        ->modalHeading('Adicionar stock em falta')
                                                        ->modalDescription('A quantidade é debitada do stock de armazém e creditada no stock desta instalação.')
                                                        ->form([
                                                            Forms\Components\TextInput::make('quantidade_a_adicionar')
                                                                ->label('Quantidade a transferir do armazém')
                                                                ->numeric()
                                                                ->minValue(0.001)
                                                                ->rules(['gt:0'])
                                                                ->required(),
                                                        ])
                                                        ->action(function (array $data, Get $get) use ($installation) {
                                                            $productId = $get('product_id');
                                                            $pedido = (float) $data['quantidade_a_adicionar'];
                                                            $insuficiente = false;

                                                            DB::transaction(function () use ($productId, $pedido, $installation, &$insuficiente) {
                                                                $armazem = StockWarehouse::where('product_id', $productId)
                                                                    ->lockForUpdate()
                                                                    ->first();

                                                                if (! $armazem || (float) $armazem->quantity < $pedido) {
                                                                    $insuficiente = true;

                                                                    return;
                                                                }

                                                                $armazem->quantity -= $pedido;
                                                                $armazem->save();

                                                                StockWarehouseLog::create([
                                                                    'stock_warehouse_id' => $armazem->id,
                                                                    'product_id' => $armazem->product_id,
                                                                    'user_id' => auth()->id(),
                                                                    'tipo_movimento' => 'saida',
                                                                    'quantity' => $pedido,
                                                                ]);

                                                                $stockInstalacao = StockInstallation::firstOrCreate(
                                                                    ['installation_id' => $installation->id, 'product_id' => $productId],
                                                                    ['quantity' => 0, 'limite_minimo' => 0],
                                                                );
                                                                $stockInstalacao = StockInstallation::query()->lockForUpdate()->findOrFail($stockInstalacao->id);
                                                                $stockInstalacao->quantity += $pedido;
                                                                $stockInstalacao->save();

                                                                StockInstallationLog::create([
                                                                    'stock_installation_id' => $stockInstalacao->id,
                                                                    'user_id' => auth()->id(),
                                                                    'tipo_movimento' => 'entrada',
                                                                    'quantity' => $pedido,
                                                                    'created_at' => now(),
                                                                ]);
                                                            });

                                                            if ($insuficiente) {
                                                                Auditoria::registar(
                                                                    Auditoria::CANAL_STOCK,
                                                                    'Transferência de stock recusada: quantidade insuficiente no armazém.',
                                                                    [
                                                                        'produto_id' => $productId,
                                                                        'instalacao' => $installation->name,
                                                                        'pedido' => $pedido,
                                                                    ],
                                                                );

                                                                Notification::make()
                                                                    ->danger()
                                                                    ->title('Stock insuficiente no armazém')
                                                                    ->body('Não há quantidade suficiente no armazém para transferir para esta instalação.')
                                                                    ->send();

                                                                return;
                                                            }

                                                            Notification::make()
                                                                ->success()
                                                                ->title('Stock transferido do armazém')
                                                                ->send();
                                                        }),
                                                ),
                                            Forms\Components\Textarea::make('acao_corretiva')
                                                ->label('Ação corretiva')
                                                ->helperText('Motivo/correção associada a esta adição (ex.: corrigir pH).')
                                                ->columnSpanFull(),
                                        ])
                                        // Sem isto, entrar pelo atalho da piscina abria uma linha
                                        // vazia cujos campos obrigatórios bloqueavam a submissão.
                                        ->defaultItems(0)
                                        ->addActionLabel('Adicionar químico')
                                        ->columns(['default' => 1, 'sm' => 2]),
                                    Forms\Components\Textarea::make('observacoes')->id("observacoes_{$pool->id}")->label('Observações gerais'),
                                ];

                    $tabs = Forms\Components\Tabs::make('Piscinas')->tabs(
                        $poolsByBombas->map(function (Pool $pool) use ($installation, $modoRapido, $poolsByFiltros, $bombasSchema, $tanquesSchema, $lavagemSchema, $enxaguamentoSchema, $posicaoNormalSchema, $nsSchema, $observacoesSchema) {
                            $sections = [];

                            if (!self::isNS() && !$modoRapido) {
                                $sections[] = Forms\Components\Section::make('Bombas e contadores')
                                    ->schema($bombasSchema($pool))
                                    ->columns(['default' => 2, 'sm' => 3, 'lg' => 4]);
                                
                                if ((bool) $installation->tanques_verificaveis) {
                                    $sections[] = Forms\Components\Section::make('Tanques')
                                        ->schema($tanquesSchema($pool));
                                }
                                
                                if ($poolsByFiltros->contains('id', $pool->id)) {
                                    $sections[] = Forms\Components\Section::make('Lavagem filtros')
                                        ->schema($lavagemSchema($pool));
                                    
                                    $sections[] = Forms\Components\Section::make('Enxaguamento')
                                        ->schema($enxaguamentoSchema($pool))
                                        ->visible(fn (Get $get) => $get("pools.{$pool->id}.filtro_faz_retrolavagem"));
                                    
                                    $sections[] = Forms\Components\Section::make('Posição normal')
                                        ->schema($posicaoNormalSchema($pool))
                                        ->visible(fn (Get $get) => $get("pools.{$pool->id}.filtro_faz_retrolavagem"));
                                }
                            }

                            $sections[] = Forms\Components\Section::make($modoRapido ? 'Registo Rápido' : 'Análises')
                                ->schema($nsSchema($pool))
                                ->columns(['default' => 2, 'sm' => 4]);
                            
                            if (!self::isNS()) {
                                $sections[] = Forms\Components\Section::make('Químicos e Observações')
                                    ->schema($observacoesSchema($pool, $installation));
                            }

                            return Forms\Components\Tabs\Tab::make($pool->name)
                                ->statePath("pools.{$pool->id}")
                                ->schema($sections);
                        })->toArray()
                    )->columnSpanFull();

                    return array_merge(
                        [Forms\Components\Section::make('Dados Globais')->schema($globaisSchema)->columns(['default' => 1, 'sm' => 2])],
                        [$tabs]
                    );
                }),
        ])->columns(1);
    }
}
