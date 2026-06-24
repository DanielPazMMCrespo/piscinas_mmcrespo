<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Constants\UserRole;
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

        if (auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Livro de registo sanitário é append-only (CN 14/DA).
     * Técnicos e NS criam e corrigem; apenas o admin pode editar/eliminar.
     */
    /**
     * Apenas admin, técnico e nadador-salvador criam registos.
     * O gestor é só-leitura (vê a lista mas não cria).
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole([
            UserRole::ADMIN,
            UserRole::TECNICO,
            UserRole::NADADOR_SALVADOR,
        ]) ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    private static function sectionRing(bool $complete): array
    {
        return ['class' => $complete
            ? 'ring-2 ring-green-500 ring-offset-2 rounded-xl'
            : 'ring-2 ring-red-500 ring-offset-2 rounded-xl'];
    }

    private static array $ultimoCache = [];
    private static array $poolCache = [];

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

    private static function quantidadeDisponivel(Get $get): ?float
    {
        $poolId = $get('../../pool_id');
        $productId = $get('product_id');

        if (! $poolId || ! $productId) {
            return null;
        }

        $pool = Pool::find($poolId);
        if (! $pool || ! $pool->instalacao) {
            return null;
        }

        $stock = \App\Models\StockInstallation::query()
            ->where('installation_id', $pool->instalacao->id)
            ->where('product_id', $productId)
            ->first();

        return $stock ? (float) $stock->quantity : null;
    }

    private static function helperQuantidadeDisponivel(Get $get): string
    {
        $parts = [];

        $disponivel = self::quantidadeDisponivel($get);
        if ($disponivel === null) {
            $parts[] = 'Selecione a piscina e o produto para ver a quantidade disponível.';
        } else {
            $productId = $get('product_id');
            $unidade = $productId ? (\App\Models\Product::find($productId)?->unidade ?? 'unid.') : 'unid.';
            $parts[] = "Disponível: {$disponivel} {$unidade}";
        }

        $sugestao = self::sugestaoDosagem($get);
        if ($sugestao !== '') {
            $parts[] = $sugestao;
        }

        return implode(' · ', $parts);
    }

    private static function sugestaoDosagem(Get $get): string
    {
        $productId = $get('product_id');
        if (! $productId) {
            return '';
        }

        $product = \App\Models\Product::find($productId);
        if (! $product || ! $product->concentracao_cl) {
            return '';
        }

        $poolId = $get('../../pool_id');
        $pool = $poolId ? Pool::find((int) $poolId) : null;
        if (! $pool || ! $pool->volume) {
            return '';
        }

        $cloro = $get('../../cloro_livre');
        if ($cloro === null || $cloro === '') {
            return '';
        }

        $deficit = max(0.0, 1.7 - (float) $cloro);
        if ($deficit <= 0) {
            return '';
        }

        $divisor = (float) $product->concentracao_cl * 10;
        if ($divisor <= 0) {
            return '';
        }

        $dose = round(((float) $pool->volume * $deficit) / $divisor, 2);

        return 'Sugerido: '.number_format($dose, 2, ',', '').' '.$product->unidade
            .' (défice '.number_format($deficit, 3, ',', '').' mg/L)';
    }

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

    private static function comSemaforo(Forms\Components\TextInput $campo, string $metrica): Forms\Components\TextInput
    {
        return $campo
            ->live(onBlur: true)
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->hint(fn (Get $get): ?string => self::conformidadeCampo($metrica, $get)['mensagem'] ?: null)
            ->hintColor(fn (Get $get): ?string => self::corSemaforo(self::conformidadeCampo($metrica, $get)['estado']))
            ->hintIcon(fn (Get $get): ?string => self::iconeSemaforo(self::conformidadeCampo($metrica, $get)['estado']));
    }

    private static function haNaoConformidade(Get $get): bool
    {
        foreach (['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia'] as $campo) {
            if (self::conformidadeCampo($campo, $get)['estado'] === 'vermelho') {
                return true;
            }
        }

        return false;
    }

    private static function descricaoFiltros(Get $get): string
    {
        $ultimo = self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null);
        if (! $ultimo) {
            return 'Retrolavagem e fotos das três posições da válvula.';
        }

        if ($ultimo->filtro_faz_retrolavagem) {
            return 'Retrolavagem e fotos das três posições da válvula. · Último registo ('.$ultimo->registado_em->format('d/m H:i').'): ✓ retrolavagem feita';
        }

        return 'Retrolavagem e fotos das três posições da válvula. · ⚠ Último registo ('.$ultimo->registado_em->format('d/m H:i').'): sem retrolavagem — considere fazer agora';
    }

    private static function helperRetrolavagem(Get $get): string
    {
        $ultimo = self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null);
        if (! $ultimo) {
            return '';
        }

        if ($ultimo->filtro_faz_retrolavagem) {
            return '✓ Feita no registo anterior ('.$ultimo->registado_em->format('d/m H:i').')';
        }

        return '⚠ Não foi feita no registo anterior ('.$ultimo->registado_em->format('d/m H:i').') — recomendado fazer agora';
    }

    private static function lookback(string $campo, Get $get): string
    {
        $ultimo = self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null);
        if (! $ultimo || $ultimo->{$campo} === null) {
            return '';
        }

        $valor = rtrim(rtrim(number_format((float) $ultimo->{$campo}, 2, ',', ''), '0'), ',');

        return ' · Último: '.$valor.' ('.$ultimo->registado_em->format('d/m H:i').')';
    }

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
                Forms\Components\Wizard::make([

                    Forms\Components\Wizard\Step::make('Piscina & Estado')
                        ->icon('heroicon-o-home')
                        ->schema([
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
                                        ->default(fn (): ?int => request()->integer('pool')
                                            ?: DailyRecord::query()
                                                ->where('user_id', auth()->id())
                                                ->orderByDesc('registado_em')
                                                ->orderByDesc('id')
                                                ->value('pool_id'))
                                        ->live()
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

                            Forms\Components\Section::make('Bomba')
                                ->description('A bomba está ferrada?')
                                ->icon('heroicon-o-bolt')
                                ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
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
                                        ->disk('public')->visibility('public')
                                        ->directory('bomba')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->helperText('Foto opcional da bomba para documentação')
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Section::make('Contador & Água')
                                ->description(fn (Get $get): string => 'Leitura do contador e estado da entrada de água. '.self::progresso(['contador_valor', 'agua_modo'], $get))
                                ->icon('heroicon-o-calculator')
                                ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
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
                                        ->disk('public')->visibility('public')
                                        ->directory('contador')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->helperText('Evidência fotográfica da leitura do contador')
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Section::make('Tanque de Compensação')
                                ->icon('heroicon-o-beaker')
                                ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
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
                                        ->disk('public')->visibility('public')
                                        ->directory('tanque')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->helperText('Foto opcional do tanque de compensação para documentação')
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Forms\Components\Wizard\Step::make('Análises')
                        ->icon('heroicon-o-beaker')
                        ->schema([
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
                                        ->disk('public')->visibility('public')
                                        ->directory('ns-fotos')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
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

                            Forms\Components\Section::make('Nossas Análises')
                                ->description(fn (Get $get): string => 'Análises do técnico, com até 5 fotos de evidência. '.self::progresso(['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia'], $get))
                                ->icon('heroicon-o-beaker')
                                ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
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
                                            ->required(fn (): bool => ! (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false))
                                            ->numeric()->step(0.01)->minValue(0)->maxValue(14)
                                            ->rules(['between:0,14']),
                                        'ph'
                                    ),
                                    self::comSemaforo(
                                        Forms\Components\TextInput::make('cloro_livre')
                                            ->label('Cloro Livre (mg/L)')
                                            ->helperText(fn (Get $get): string => 'Limite legal: '.DailyRecord::CLORO_LIVRE_MIN.' a '.DailyRecord::CLORO_LIVRE_MAX.' mg/L'.self::lookback('cloro_livre', $get))
                                            ->required(fn (): bool => ! (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false))
                                            ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                                        'cloro_livre'
                                    ),
                                    self::comSemaforo(
                                        Forms\Components\TextInput::make('cloro_total')
                                            ->label('Cloro Total (mg/L)')
                                            ->helperText(fn (Get $get): string => 'Combinado (total − livre) deve ser ≤ '.DailyRecord::CLORO_COMBINADO_MAX.' mg/L'.self::lookback('cloro_total', $get))
                                            ->required(fn (): bool => ! (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false))
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
                                            ->required(fn (): bool => ! (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false))
                                            ->numeric()->step(0.1)->minValue(0)->maxValue(50),
                                        'temperatura'
                                    ),
                                    self::comSemaforo(
                                        Forms\Components\TextInput::make('transparencia')
                                            ->label('Turbidez (FNU)')
                                            ->helperText(fn (Get $get): string => 'Limite operacional: ≤ '.DailyRecord::TRANSPARENCIA_MAX.' FNU (0.2 cristalina, 0.35+ turva)'.self::lookback('transparencia', $get))
                                            ->required(fn (): bool => ! (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false))
                                            ->numeric()->step(0.01)->minValue(0)->maxValue(DailyRecord::TRANSPARENCIA_MAX),
                                        'transparencia'
                                    ),
                                    Forms\Components\FileUpload::make('analises_fotos')
                                        ->label('Fotos das análises (até 5)')
                                        ->disk('public')->visibility('public')
                                        ->directory('analises')
                                        ->image()
                                        ->multiple()
                                        ->maxFiles(5)
                                        ->reorderable()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Forms\Components\Wizard\Step::make('Filtros')
                        ->icon('heroicon-o-funnel')
                        ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
                        ->schema([
                            Forms\Components\Section::make('Filtros')
                                ->description(fn (Get $get): string => self::descricaoFiltros($get))
                                ->icon('heroicon-o-funnel')
                                ->collapsible()
                                ->extraAttributes(fn (): array => self::sectionRing(true))
                                ->schema([
                                    Forms\Components\Toggle::make('filtro_faz_retrolavagem')
                                        ->label('Vai ser feita uma retrolavagem?')
                                        ->helperText(fn (Get $get): string => self::helperRetrolavagem($get))
                                        ->default(false)
                                        ->live(),
                                    Forms\Components\FileUpload::make('filtro_foto_retrolavagem')
                                        ->label('Foto — Posição Retrolavagem')
                                        ->disk('public')->visibility('public')
                                        ->directory('filtros')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                                    Forms\Components\FileUpload::make('filtro_foto_enxaguamento')
                                        ->label('Foto — Posição Enxaguamento')
                                        ->disk('public')->visibility('public')
                                        ->directory('filtros')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                                    Forms\Components\FileUpload::make('filtro_foto_posicao_normal')
                                        ->label('Foto — Retorno à Posição Normal')
                                        ->disk('public')->visibility('public')
                                        ->directory('filtros')
                                        ->image()
                                        ->maxSize(5120)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                                        ->visible(fn (Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                                ]),
                        ]),

                    Forms\Components\Wizard\Step::make('Químicos & Notas')
                        ->icon('heroicon-o-sparkles')
                        ->schema([
                            Forms\Components\Section::make('Adições de Químicos')
                                ->icon('heroicon-o-sparkles')
                                ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
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
                                                ->preload()
                                                ->live(),
                                            Forms\Components\TextInput::make('quantity')
                                                ->label('Quantidade')
                                                ->helperText(fn (Get $get): string => self::helperQuantidadeDisponivel($get))
                                                ->numeric()
                                                ->required()
                                                ->minValue(0)
                                                ->step(0.001)
                                                ->rules([
                                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                                        $disponivel = self::quantidadeDisponivel($get);
                                                        if ($disponivel !== null && filled($value) && (float) $value > $disponivel) {
                                                            $productId = $get('product_id');
                                                            $unidade = $productId ? (\App\Models\Product::find($productId)?->unidade ?? 'unid.') : 'unid.';
                                                            $fail("Quantidade insuficiente. Disponível: {$disponivel} {$unidade}.");
                                                        }
                                                    },
                                                ])
                                                ->suffixAction(
                                                    Forms\Components\Actions\Action::make('calcular_dose')
                                                        ->icon('heroicon-m-calculator')
                                                        ->tooltip('Calculadora de dosagem de cloro')
                                                        ->mountUsing(function (Forms\Form $form, Get $get): void {
                                                            $cloroAtual = (float) ($get('../../cloro_livre') ?? 0);
                                                            $deficit = max(0.0, round(1.7 - $cloroAtual, 3));
                                                            $productId = $get('product_id');
                                                            $concentracao = $productId
                                                                ? (\App\Models\Product::find($productId)?->concentracao_cl)
                                                                : null;
                                                            $form->fill([
                                                                'dosagem' => $deficit > 0 ? $deficit : null,
                                                                'concentracao' => $concentracao,
                                                            ]);
                                                        })
                                                        ->form([
                                                            Forms\Components\TextInput::make('dosagem')
                                                                ->label('Dosagem em falta (mg/L)')
                                                                ->helperText('Défice até ao alvo de 1,7 mg/L de cloro livre.')
                                                                ->numeric()
                                                                ->required()
                                                                ->step(0.001)
                                                                ->minValue(0),
                                                            Forms\Components\TextInput::make('concentracao')
                                                                ->label('Concentração de cloro ativo (%)')
                                                                ->helperText('Ex: 56 para granulado, 16,8 para hipoclorito de sódio.')
                                                                ->numeric()
                                                                ->required()
                                                                ->step(0.01)
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

                                                            $resultado = round(
                                                                ((float) $pool->volume * (float) $data['dosagem'])
                                                                / ((float) $data['concentracao'] * 10),
                                                                3
                                                            );
                                                            $set('quantity', $resultado);
                                                        })
                                                ),
                                            Forms\Components\Textarea::make('acao_corretiva')
                                                ->label('Ação corretiva tomada (opcional)')
                                                ->helperText('Descreva a correção ou medida aplicada (ex.: dose de ácido, reforço de cloro, pausa de funcionamento).')
                                                ->rows(2)
                                                ->columnSpanFull(),
                                        ]),
                                ]),

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
                        ]),

                ])
                ->skippable()
                ->columnSpanFull()
                ->persistStepInQueryString('registo-step'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['piscina', 'utilizador', 'adicoes.produto', 'fotos'])
                ->withCount('correcoes')
            )
            ->defaultSort('registado_em', 'desc')
            ->recordUrl(fn (DailyRecord $record): string => Pages\EditDailyRecord::getUrl(['record' => $record]))
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
                    ->visible(fn (DailyRecord $record): bool => ! $record->e_correcao && ($record->correcoes_count ?? 0) === 0 && auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]))
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
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(DailyRecord::TRANSPARENCIA_MAX),
                        Forms\Components\Textarea::make('razao_correcao')
                            ->label('Razão da correção')
                            ->required()
                            ->minLength(5)
                            ->columnSpanFull(),
                    ])
                    ->action(function (DailyRecord $record, array $data): void {
                        if (! auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                            Notification::make()->danger()->title('Sem permissão')->send();
                            return;
                        }

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
            'edit' => Pages\EditDailyRecord::route('/{record}/edit'),
        ];
    }
}
