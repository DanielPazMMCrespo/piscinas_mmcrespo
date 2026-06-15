<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use App\Models\Pool;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyRecordResource extends Resource
{
    protected static ?string $model = DailyRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Registo Diário';

    protected static ?string $pluralModelLabel = 'Registos Diários';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()->hasRole('nadador_salvador')) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Livro de registo sanitário é append-only (CN 14/DA).
     * Técnicos e NS criam e corrigem; apenas o admin pode editar/eliminar.
     */
    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    private static function sectionRing(bool $complete): array
    {
        return ['class' => $complete
            ? 'ring-2 ring-green-500 ring-offset-2 rounded-xl'
            : 'ring-2 ring-red-500 ring-offset-2 rounded-xl'];
    }

    /** Cache por-pedido do último registo de cada piscina (lookback contextual). */
    private static array $ultimoCache = [];

    /** Cache por-pedido das piscinas já carregadas (avaliação de temperatura). */
    private static array $poolCache = [];

    /**
     * Último registo válido (não corrigido) de uma piscina — base dos smart
     * defaults e do "Ontem: ...". Memoizado para uma query por piscina.
     */
    private static function ultimoRegisto(?int $poolId): ?DailyRecord
    {
        if (! $poolId) {
            return null;
        }

        if (! array_key_exists($poolId, self::$ultimoCache)) {
            self::$ultimoCache[$poolId] = DailyRecord::query()
                ->where('pool_id', $poolId)
                ->whereDoesntHave('correcoes')
                ->orderByDesc('registado_em')
                ->orderByDesc('id')
                ->first();
        }

        return self::$ultimoCache[$poolId];
    }

    private static function poolFromGet(Get $get): ?Pool
    {
        $poolId = $get('pool_id');
        if (! $poolId) {
            return null;
        }

        $poolId = (int) $poolId;
        if (! array_key_exists($poolId, self::$poolCache)) {
            self::$poolCache[$poolId] = Pool::find($poolId);
        }

        return self::$poolCache[$poolId];
    }

    /**
     * Avaliação de conformidade de um campo do formulário (semáforo em tempo real).
     * Mapeia o campo para a métrica legal certa; cloro total/combinado avalia-se
     * pelo combinado (total − livre).
     *
     * @return array{estado: string, mensagem: string}
     */
    private static function conformidadeCampo(string $campo, Get $get): array
    {
        $pool = self::poolFromGet($get);

        return match ($campo) {
            'ph', 'ns_ph' => DailyRecord::avaliarConformidade('ph', $get($campo), $pool),
            'cloro_livre', 'ns_cloro_livre' => DailyRecord::avaliarConformidade('cloro_livre', $get($campo), $pool),
            'temperatura', 'ns_temperatura' => DailyRecord::avaliarConformidade('temperatura', $get($campo), $pool),
            'transparencia' => DailyRecord::avaliarConformidade('transparencia', $get($campo), $pool),
            'cloro_total' => self::conformidadeCombinado($get('cloro_total'), $get('cloro_livre'), $pool),
            'ns_cloro_total' => self::conformidadeCombinado($get('ns_cloro_total'), $get('ns_cloro_livre'), $pool),
            default => ['estado' => 'neutro', 'mensagem' => ''],
        };
    }

    /** @return array{estado: string, mensagem: string} */
    private static function conformidadeCombinado(mixed $total, mixed $livre, ?Pool $pool): array
    {
        if ($total === null || $total === '' || $livre === null || $livre === '') {
            return ['estado' => 'neutro', 'mensagem' => ''];
        }

        $combinado = round((float) $total - (float) $livre, 2);

        return DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
    }

    private static function corSemaforo(string $estado): ?string
    {
        return match ($estado) {
            'verde' => 'success',
            'amarelo' => 'warning',
            'vermelho' => 'danger',
            default => null,
        };
    }

    private static function iconeSemaforo(string $estado): ?string
    {
        return match ($estado) {
            'verde' => 'heroicon-m-check-circle',
            'amarelo' => 'heroicon-m-exclamation-triangle',
            'vermelho' => 'heroicon-m-x-circle',
            default => null,
        };
    }

    /**
     * Aplica o semáforo de conformidade em tempo real a um campo de métrica:
     * hint colorido + ícone que reagem ao valor introduzido (avaliado ao sair do campo).
     */
    private static function comSemaforo(Forms\Components\TextInput $campo, string $metrica): Forms\Components\TextInput
    {
        return $campo
            ->live(onBlur: true)
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->hint(fn (Get $get): ?string => self::conformidadeCampo($metrica, $get)['mensagem'] ?: null)
            ->hintColor(fn (Get $get): ?string => self::corSemaforo(self::conformidadeCampo($metrica, $get)['estado']))
            ->hintIcon(fn (Get $get): ?string => self::iconeSemaforo(self::conformidadeCampo($metrica, $get)['estado']));
    }

    /**
     * Há alguma métrica das "Nossas Análises" fora dos limites legais?
     * (pH, cloro livre, cloro combinado, temperatura, turbidez = estado vermelho).
     */
    private static function haNaoConformidade(Get $get): bool
    {
        foreach (['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia'] as $campo) {
            if (self::conformidadeCampo($campo, $get)['estado'] === 'vermelho') {
                return true;
            }
        }

        return false;
    }

    /** Texto "Ontem: valor (dd/mm HH:mm)" para o helperText de um campo de métrica. */
    private static function lookback(string $campo, Get $get): string
    {
        $ultimo = self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null);
        if (! $ultimo || $ultimo->{$campo} === null) {
            return '';
        }

        $valor = rtrim(rtrim(number_format((float) $ultimo->{$campo}, 2, ',', ''), '0'), ',');

        return ' · Último: '.$valor.' ('.$ultimo->registado_em->format('d/m H:i').')';
    }

    /** "n/total preenchidos" para a descrição de uma secção. */
    private static function progresso(array $campos, Get $get): string
    {
        $preenchidos = 0;
        foreach ($campos as $campo) {
            if (filled($get($campo))) {
                $preenchidos++;
            }
        }

        return $preenchidos.'/'.count($campos).' preenchidos';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ── Informação Geral ─────────────────────────────────────────────
                Forms\Components\Section::make('Informação Geral')
                    ->icon('heroicon-o-identification')
                    ->collapsible()
                    ->columns(3)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('pool_id')) && filled($get('registado_em'))
                    ))
                    ->schema([
                        Forms\Components\Select::make('pool_id')
                            ->label('Piscina')
                            ->relationship('piscina', 'name')
                            ->required()
                            ->preload()
                            ->searchable()
                            // Smart default: ?pool= (botão "Registar" do cartão da
                            // piscina no dashboard) > piscina do último registo do utilizador.
                            ->default(fn (): ?int => request()->integer('pool')
                                ?: DailyRecord::query()
                                    ->where('user_id', auth()->id())
                                    ->orderByDesc('registado_em')
                                    ->orderByDesc('id')
                                    ->value('pool_id'))
                            ->live()
                            // Ao trocar de piscina, herda o estado operacional do último
                            // registo dessa piscina (bomba, entrada de água, tanque).
                            ->afterStateUpdated(function (Set $set, $state): void {
                                $ultimo = self::ultimoRegisto($state ? (int) $state : null);
                                $set('bomba_ferrada', $ultimo?->bomba_ferrada);
                                $set('agua_modo', $ultimo?->agua_modo);
                                $set('tanque_ok', $ultimo?->tanque_ok);
                            }),
                        Forms\Components\Select::make('user_id')
                            ->label('Técnico / Nadador-Salvador')
                            ->relationship('utilizador', 'name')
                            ->default(auth()->id())
                            ->required()
                            ->disabled()
                            ->dehydrated(),
                        Forms\Components\DateTimePicker::make('registado_em')
                            ->label('Data e Hora do Registo')
                            ->default(now())
                            ->required()
                            ->live(onBlur: true),
                    ]),

                // ── 1. Bomba ─────────────────────────────────────────────────────
                Forms\Components\Section::make('Bomba')
                    ->description('A bomba está ferrada?')
                    ->icon('heroicon-o-bolt')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        $get('bomba_ferrada') !== null
                    ))
                    ->schema([
                        Forms\Components\Toggle::make('bomba_ferrada')
                            ->label('Bomba ferrada')
                            ->helperText('Liga se a bomba está a aspirar bem, sem ar.')
                            ->onIcon('heroicon-m-check')
                            ->offIcon('heroicon-m-x-mark')
                            ->default(fn (Get $get) => self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null)?->bomba_ferrada)
                            ->live(onBlur: true),
                        Forms\Components\FileUpload::make('bomba_foto')
                            ->label('Foto da Bomba')
                            ->disk('local')
                            ->directory('bomba')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->helperText('Foto opcional da bomba para documentação')
                            ->columnSpanFull(),
                    ]),

                // ── 2. Filtros ───────────────────────────────────────────────────
                Forms\Components\Section::make('Filtros')
                    ->description('Retrolavagem e fotos das três posições da válvula.')
                    ->icon('heroicon-o-funnel')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Toggle::make('filtro_faz_retrolavagem')
                            ->label('Vai ser feita uma retrolavagem?')
                            ->default(false)
                            ->live(),
                        Forms\Components\FileUpload::make('filtro_foto_retrolavagem')
                            ->label('Foto — Posição Retrolavagem')
                            ->disk('local')
                            ->directory('filtros')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                        Forms\Components\FileUpload::make('filtro_foto_enxaguamento')
                            ->label('Foto — Posição Enxaguamento')
                            ->disk('local')
                            ->directory('filtros')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                        Forms\Components\FileUpload::make('filtro_foto_posicao_normal')
                            ->label('Foto — Retorno à Posição Normal')
                            ->disk('local')
                            ->directory('filtros')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                    ]),

                // ── 3. Contador & Água ───────────────────────────────────────────
                Forms\Components\Section::make('Contador & Água')
                    ->description(fn (Get $get): string => 'Leitura do contador e estado da entrada de água. '.self::progresso(['contador_valor', 'agua_modo'], $get))
                    ->icon('heroicon-o-calculator')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('contador_valor')) && filled($get('agua_modo'))
                    ))
                    ->schema([
                        Forms\Components\TextInput::make('contador_valor')
                            ->label('Valor do Contador (m³)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->suffix('m³')
                            ->extraInputAttributes(['inputmode' => 'decimal'])
                            ->helperText(fn (Get $get): string => 'O contador não anda para trás.'.self::lookback('contador_valor', $get))
                            // A leitura não pode ser inferior à última (a água só avança).
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $ultimo = self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null);
                                    if (filled($value) && $ultimo && $ultimo->contador_valor !== null
                                        && (float) $value < (float) $ultimo->contador_valor) {
                                        $fail('A leitura ('.$value.') é inferior à última ('.$ultimo->contador_valor.'). O contador só avança.');
                                    }
                                },
                            ])
                            ->live(onBlur: true),
                        Forms\Components\Select::make('agua_modo')
                            ->label('Estado da Entrada de Água')
                            ->options([
                                'auto_com_agua' => 'Automático — com água',
                                'auto_sem_agua' => 'Automático — sem água',
                                'on_com_agua' => 'ON — com água',
                                'on_sem_agua' => 'ON — sem água',
                                'off' => 'OFF — sem água na instalação',
                            ])
                            ->native(false)
                            ->default(fn (Get $get) => self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null)?->agua_modo)
                            ->live(onBlur: true),
                        Forms\Components\FileUpload::make('contador_foto')
                            ->label('Foto do Contador')
                            ->disk('local')
                            ->directory('contador')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->helperText('Evidência fotográfica da leitura do contador')
                            ->columnSpanFull(),
                    ]),

                // ── 4. Tanque de Compensação ─────────────────────────────────────
                Forms\Components\Section::make('Tanque de Compensação')
                    ->icon('heroicon-o-beaker')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        $get('tanque_ok') !== null
                    ))
                    ->schema([
                        Forms\Components\Toggle::make('tanque_ok')
                            ->label('Tanque OK')
                            ->helperText('Nível e estado conformes.')
                            ->onIcon('heroicon-m-check')
                            ->offIcon('heroicon-m-x-mark')
                            ->default(fn (Get $get) => self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null)?->tanque_ok)
                            ->live(onBlur: true),
                        Forms\Components\Textarea::make('tanque_observacoes')
                            ->label('Observações do Tanque')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('tanque_foto')
                            ->label('Foto do Tanque')
                            ->disk('local')
                            ->directory('tanque')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->helperText('Foto opcional do tanque de compensação para documentação')
                            ->columnSpanFull(),
                    ]),

                // ── 5. Análises NS ───────────────────────────────────────────────
                Forms\Components\Section::make('Análises — Nadador-Salvador')
                    ->description(fn (Get $get): string => 'Leituras feitas pelo Nadador-Salvador. '.self::progresso(['ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'], $get))
                    ->icon('heroicon-o-eye')
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('ns_ph')) && filled($get('ns_cloro_livre'))
                    ))
                    ->schema([
                        Forms\Components\FileUpload::make('ns_foto')
                            ->label('Foto da Análise NS')
                            ->disk('local')
                            ->directory('ns-fotos')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->columnSpanFull(),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('ns_ph')
                                ->label('pH (NS)')
                                ->numeric()->step(0.01)->minValue(0)->maxValue(14),
                            'ns_ph'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('ns_cloro_livre')
                                ->label('Cloro Livre — NS (mg/L)')
                                ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                            'ns_cloro_livre'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('ns_cloro_total')
                                ->label('Cloro Total — NS (mg/L)')
                                ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                            'ns_cloro_total'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('ns_temperatura')
                                ->label('Temperatura — NS (ºC)')
                                ->numeric()->step(0.01)->minValue(0)->maxValue(50),
                            'ns_temperatura'
                        ),
                    ]),

                // ── 6. Nossas Análises ───────────────────────────────────────────
                Forms\Components\Section::make('Nossas Análises')
                    ->description(fn (Get $get): string => 'Análises do técnico, com até 5 fotos de evidência. '.self::progresso(['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia'], $get))
                    ->icon('heroicon-o-beaker')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->columns(2)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('ph'))
                        && filled($get('cloro_livre'))
                        && filled($get('cloro_total'))
                        && filled($get('temperatura'))
                        && filled($get('transparencia'))
                    ))
                    ->schema([
                        self::comSemaforo(
                            Forms\Components\TextInput::make('ph')
                                ->label('pH')
                                ->helperText(fn (Get $get): string => 'Limite legal CN 14/DA: '.DailyRecord::PH_MIN.' a '.DailyRecord::PH_MAX.self::lookback('ph', $get))
                                ->required(fn (): bool => ! (auth()->user()?->hasRole('nadador_salvador') ?? false))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(14)
                                ->rules(['between:0,14']),
                            'ph'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('cloro_livre')
                                ->label('Cloro Livre (mg/L)')
                                ->helperText(fn (Get $get): string => 'Limite legal: '.DailyRecord::CLORO_LIVRE_MIN.' a '.DailyRecord::CLORO_LIVRE_MAX.' mg/L'.self::lookback('cloro_livre', $get))
                                ->required(fn (): bool => ! (auth()->user()?->hasRole('nadador_salvador') ?? false))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                            'cloro_livre'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('cloro_total')
                                ->label('Cloro Total (mg/L)')
                                ->helperText(fn (Get $get): string => 'Combinado (total − livre) deve ser ≤ '.DailyRecord::CLORO_COMBINADO_MAX.' mg/L'.self::lookback('cloro_total', $get))
                                ->required(fn (): bool => ! (auth()->user()?->hasRole('nadador_salvador') ?? false))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(20)
                                ->rules([
                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                        if (filled($get('cloro_livre')) && (float) $value < (float) $get('cloro_livre')) {
                                            $fail('O cloro total não pode ser inferior ao cloro livre.');
                                        }
                                    },
                                ]),
                            'cloro_total'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('temperatura')
                                ->label('Temperatura (ºC)')
                                ->helperText(fn (Get $get): string => 'Avaliada contra os limites próprios da piscina (temp. mín/máx).'.self::lookback('temperatura', $get))
                                ->required(fn (): bool => ! (auth()->user()?->hasRole('nadador_salvador') ?? false))
                                ->numeric()->step(0.1)->minValue(0)->maxValue(50),
                            'temperatura'
                        ),
                        self::comSemaforo(
                            Forms\Components\TextInput::make('transparencia')
                                ->label('Turbidez (FNU)')
                                ->helperText(fn (Get $get): string => 'Limite operacional: ≤ '.DailyRecord::TRANSPARENCIA_MAX.' FNU'.self::lookback('transparencia', $get))
                                ->required(fn (): bool => ! (auth()->user()?->hasRole('nadador_salvador') ?? false))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(100),
                            'transparencia'
                        ),
                        // Ação corretiva: obrigatória quando há parâmetros fora dos limites
                        // legais (padrão "failed response → corrective action").
                        Forms\Components\Textarea::make('acao_corretiva')
                            ->label('Ação corretiva tomada')
                            ->helperText('Há parâmetros fora dos limites legais. Descreva a correção aplicada (ex.: dose de ácido, reforço de cloro, fecho ao público).')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => self::haNaoConformidade($get))
                            ->required(fn (Get $get): bool => self::haNaoConformidade($get)),
                        Forms\Components\FileUpload::make('analises_fotos')
                            ->label('Fotos das análises (até 5)')
                            ->disk('local')
                            ->directory('analises')
                            ->image()
                            ->multiple()
                            ->maxFiles(5)
                            ->reorderable()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->columnSpanFull(),
                    ]),

                // ── 7. Adições de Químicos ───────────────────────────────────────
                Forms\Components\Section::make('Adições de Químicos')
                    ->icon('heroicon-o-sparkles')
                    ->hidden(fn (): bool => auth()->user()?->hasRole('nadador_salvador') ?? false)
                    ->collapsible()
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Repeater::make('adicoes')
                            ->relationship()
                            ->label('')
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar produto')
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Produto')
                                    ->relationship('produto', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->step(0.001)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('calcular_dose')
                                            ->icon('heroicon-m-calculator')
                                            ->tooltip('Calculadora de dosagem')
                                            ->form([
                                                Forms\Components\TextInput::make('dosagem')
                                                    ->label('Dosagem (mg/L)')
                                                    ->numeric()
                                                    ->required()
                                                    ->step(0.0001)
                                                    ->minValue(0),
                                                Forms\Components\TextInput::make('concentracao')
                                                    ->label('% Concentração do produto')
                                                    ->helperText('Aceita decimais (ex.: 0,56 para granulado).')
                                                    ->numeric()
                                                    ->required()
                                                    ->step(0.0001)
                                                    ->minValue(0.01)
                                                    ->maxValue(100.00)
                                                    ->suffix('%'),
                                            ])
                                            ->action(function (array $data, Set $set, Get $get): void {
                                                $poolId = $get('../../pool_id');
                                                $pool = Pool::find($poolId);

                                                if (! $pool || ! $pool->volume || (float) $data['concentracao'] <= 0) {
                                                    Notification::make()
                                                        ->warning()
                                                        ->title('Cálculo impossível')
                                                        ->body('O volume da piscina não está definido ou a concentração é inválida.')
                                                        ->send();

                                                    return;
                                                }

                                                $result = ((float) $pool->volume * (float) $data['dosagem']) / (float) $data['concentracao'];
                                                $set('quantity', round($result, 3));
                                            })
                                    ),
                            ]),
                    ]),

                // ── 8. Observações ───────────────────────────────────────────────
                Forms\Components\Section::make('Observações')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->collapsible()
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // ── Informação de Correção (só visível em correções) ─────────────
                Forms\Components\Section::make('Informação de Correção')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->collapsible()
                    ->visible(fn (Get $get): bool => (bool) $get('e_correcao'))
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        ! $get('e_correcao') || filled($get('razao_correcao'))
                    ))
                    ->schema([
                        Forms\Components\Placeholder::make('aviso_correcao')
                            ->label('')
                            ->content('Este registo é uma correção. O original mantém-se inalterado no livro sanitário.'),
                        Forms\Components\Textarea::make('razao_correcao')
                            ->label('Razão da Correção')
                            ->required()
                            ->minLength(5)
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('piscina')->withCount('correcoes'))
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('piscina.name')
                            ->label('Piscina')
                            ->weight('bold')
                            ->sortable()
                            ->searchable(),
                        Tables\Columns\TextColumn::make('registado_em')
                            ->label('Data/Hora')
                            ->dateTime('d/m/Y H:i')
                            ->color('gray')
                            ->size('sm')
                            ->sortable(),
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->size('sm')
                            ->icon('heroicon-m-user')
                            ->sortable(),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('ph')
                            ->label('pH')
                            ->formatStateUsing(fn ($state): string => 'pH '.$state)
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->phConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->phConforme() ? null : 'Fora do limite legal ('.DailyRecord::PH_MIN.'–'.DailyRecord::PH_MAX.')'),
                        Tables\Columns\TextColumn::make('cloro_livre')
                            ->label('Cloro L.')
                            ->formatStateUsing(fn ($state): string => 'Cl '.$state.' mg/L')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->cloroLivreConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->cloroLivreConforme() ? null : 'Fora do limite legal ('.DailyRecord::CLORO_LIVRE_MIN.'–'.DailyRecord::CLORO_LIVRE_MAX.' mg/L)'),
                        Tables\Columns\TextColumn::make('transparencia')
                            ->label('Turbidez')
                            ->formatStateUsing(fn ($state): string => $state.' FNU')
                            ->numeric()
                            ->badge()
                            ->color('info'),
                    ])->space(1),

                    Tables\Columns\TextColumn::make('estado')
                        ->label('Estado')
                        ->badge()
                        ->getStateUsing(function (DailyRecord $record): ?string {
                            if ($record->e_correcao) {
                                return 'Correção';
                            }
                            if (($record->correcoes_count ?? 0) > 0) {
                                return 'Corrigido';
                            }

                            return null;
                        })
                        ->color(fn (?string $state): string => $state === 'Correção' ? 'warning' : 'gray'),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->relationship('piscina', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('periodo')
                    ->form([
                        Forms\Components\DatePicker::make('de')
                            ->label('De')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                        Forms\Components\DatePicker::make('ate')
                            ->label('Até')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'] ?? null, fn (Builder $q, $de): Builder => $q->whereDate('registado_em', '>=', $de))
                            ->when($data['ate'] ?? null, fn (Builder $q, $ate): Builder => $q->whereDate('registado_em', '<=', $ate));
                    })
                    ->indicateUsing(function (array $data): array {
                        $ind = [];
                        if ($data['de'] ?? null) {
                            $ind[] = 'De '.\Illuminate\Support\Carbon::parse($data['de'])->format('d/m/Y');
                        }
                        if ($data['ate'] ?? null) {
                            $ind[] = 'Até '.\Illuminate\Support\Carbon::parse($data['ate'])->format('d/m/Y');
                        }

                        return $ind;
                    }),
                Tables\Filters\TernaryFilter::make('e_correcao')
                    ->label('Correções')
                    ->placeholder('Todos os registos')
                    ->trueLabel('Apenas correções')
                    ->falseLabel('Apenas registos originais'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('corrigir')
                    ->label('Corrigir')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (DailyRecord $record): bool => ! $record->e_correcao && ($record->correcoes_count ?? 0) === 0)
                    ->modalHeading('Corrigir registo')
                    ->modalDescription('Cria um novo registo de correção ligado ao original. O original mantém-se inalterado, como exige o livro sanitário.')
                    ->modalSubmitActionLabel('Registar correção')
                    ->fillForm(fn (DailyRecord $record): array => [
                        'ph' => $record->ph,
                        'cloro_livre' => $record->cloro_livre,
                        'cloro_total' => $record->cloro_total,
                        'transparencia' => $record->transparencia,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('ph')
                            ->label('pH')
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(14),
                        Forms\Components\TextInput::make('cloro_livre')
                            ->label('Cloro Livre (mg/L)')
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20),
                        Forms\Components\TextInput::make('cloro_total')
                            ->label('Cloro Total (mg/L)')
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20)
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    if (filled($get('cloro_livre')) && (float) $value < (float) $get('cloro_livre')) {
                                        $fail('O cloro total não pode ser inferior ao cloro livre.');
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Turbidez (FNU)')
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(100),
                        Forms\Components\Textarea::make('razao_correcao')
                            ->label('Razão da correção')
                            ->required()
                            ->minLength(5)
                            ->columnSpanFull(),
                    ])
                    ->action(function (DailyRecord $record, array $data): void {
                        DailyRecord::create([
                            'pool_id' => $record->pool_id,
                            'user_id' => auth()->id(),
                            'registado_em' => $record->registado_em,
                            'ph' => $data['ph'],
                            'cloro_livre' => $data['cloro_livre'],
                            'cloro_total' => $data['cloro_total'],
                            'transparencia' => $data['transparencia'],
                            'temperatura' => $record->temperatura,
                            'caleira_feita' => $record->caleira_feita,
                            'renovacao_agua' => $record->renovacao_agua,
                            'observacoes' => $record->observacoes,
                            'e_correcao' => true,
                            'corrige_registo_id' => $record->id,
                            'razao_correcao' => $data['razao_correcao'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Correção registada')
                            ->body('O registo original foi mantido e a correção ficou associada.')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyRecords::route('/'),
            'create' => Pages\CreateDailyRecord::route('/create'),
            'view' => Pages\ViewDailyRecord::route('/{record}'),
            'edit' => Pages\EditDailyRecord::route('/{record}/edit'),
        ];
    }
}
