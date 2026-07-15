<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Builder;

class DailyRecordFormBuilder
{
    private static function isNS(): bool
    {
        return auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;
    }

    /**
     * Piscinas permitidas para o utilizador atual: todas para Admin/Técnico,
     * apenas as atribuídas via user_pools para Nadador-Salvador.
     */
    private static function piscinasPermitidas(Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query): Builder|\Illuminate\Database\Eloquent\Relations\HasMany
    {
        if (self::isNS()) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

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
            'tanque_ok' => true,
            'tanque_observacoes' => null,
            'tanque_foto' => null,
            'filtro_faz_retrolavagem' => false,
            'timer_lavagem' => 3,
            'filtro_foto_retrolavagem' => null,
            'timer_enxaguamento' => 2,
            'filtro_foto_enxaguamento' => null,
            'filtro_foto_posicao_normal' => null,
            'ns_ph' => null,
            'ns_cloro_livre' => null,
            'ns_cloro_total' => null,
            'ns_temperatura' => null,
            'acao_corretiva' => null,
            'adicoes' => [],
            'observacoes' => null,
        ];

        return self::piscinasPermitidas($installation->piscinas())
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(string) $id => $base])
            ->toArray();
    }

    private static function semearEstadoPiscinas(Set $set, int|string|null $installationId): void
    {
        $installation = $installationId ? Installation::find($installationId) : null;

        $set('pools', $installation ? self::estadoInicialPiscinas($installation) : []);
        $set('ns_foto', null);
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
            ->maxSize(5120)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
            ->required($required)
            ->columnSpanFull();

        if ($uniqueId !== null) {
            $component->id($uniqueId);
        }

        return [$component];
    }

    private static function comSemaforo(Forms\Components\TextInput $campo, string $metrica, Pool $pool): Forms\Components\TextInput
    {
        return $campo
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->hint(fn (Get $get): ?string => DailyRecord::avaliarConformidade($metrica, $get($campo->getName()), $pool)['mensagem'] ?: null)
            ->hintColor(fn (Get $get): ?string => match(DailyRecord::avaliarConformidade($metrica, $get($campo->getName()), $pool)['estado']) {
                \App\Enums\EstadoConformidade::VERDE => 'success',
                \App\Enums\EstadoConformidade::AMARELO => 'warning',
                \App\Enums\EstadoConformidade::VERMELHO => 'danger',
                \App\Enums\EstadoConformidade::NEUTRO => null,
            })
            ->extraAttributes(function (Get $get) use ($metrica, $pool, $campo) {
                $classes = [];
                if (DailyRecord::avaliarConformidade($metrica, $get($campo->getName()), $pool)['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                    $classes[] = 'ring-2 ring-danger-500 ring-inset bg-danger-50 dark:bg-danger-900/30';
                }
                return ['class' => implode(' ', $classes)];
            });
    }

    public static function form(Form $form): Form
    {
        if ($form->getOperation() !== 'create') {
            return $form->schema([ Forms\Components\Placeholder::make('Edição não suportada neste Wizard.') ]);
        }

        return $form->schema([
            Forms\Components\Section::make('Início')
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
                        ->default(function() {
                             $pool = Pool::whereHas('users', fn($q) => $q->where('users.id', auth()->id()))->first();
                             return $pool?->installation_id;
                        })
                        ->afterStateHydrated(function (Set $set, Get $get, $state) {
                            if (filled($state) && blank($get('pools'))) {
                                self::semearEstadoPiscinas($set, $state);
                            }
                        })
                        ->afterStateUpdated(fn (Set $set, $state) => self::semearEstadoPiscinas($set, $state)),
                    Forms\Components\Select::make('user_id')
                        ->label('Responsável')
                        ->relationship('utilizador', 'name')
                        ->default(auth()->id())
                        ->required()
                        ->disabled()
                        ->dehydrated(),
                    Forms\Components\DateTimePicker::make('registado_em')
                        ->label('Data e Hora do Registo')
                        ->default(now())
                        ->required()
                        ->disabled(fn (): bool => self::isNS())
                        ->dehydrated(),
                ])->columns(3),

            Forms\Components\Section::make('Registo Diário')
                ->visible(fn(Get $get) => filled($get('installation_id')))
                ->schema(function(Get $get) {
                    $installationId = $get('installation_id');
                    if (!$installationId) return [];
                    $installation = Installation::find($installationId);
                    if (!$installation) return [];

                    $poolsByBombas = self::piscinasPermitidas($installation->piscinas())->orderBy('ordem_bombas')->get();
                    $poolsByFiltros = self::piscinasPermitidas($installation->piscinas())->orderBy('ordem_filtros')->get();

                    $stepBombas = Forms\Components\Wizard\Step::make('Bombas e contadores')
                        ->icon('heroicon-o-bolt')
                        ->schema(
                            $poolsByBombas->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->schema([
                                        Forms\Components\Toggle::make('bomba_ferrada')
                                            ->id("bomba_ferrada_{$pool->id}")
                                            ->label('Bomba ferrada')
                                            ->default(true),
                                        Forms\Components\TextInput::make('contador_valor')
                                            ->id("contador_valor_{$pool->id}")
                                            ->label('Contador (m³)')
                                            ->numeric()->step(0.01)->minValue(0)
                                            ->rules([
                                                 fn (): Closure => function (string $attribute, $value, Closure $fail) use ($pool) {
                                                     $ultimo = DailyRecord::query()
                                                         ->where('pool_id', $pool->id)
                                                         ->whereDoesntHave('correcoes')
                                                         ->orderByDesc('registado_em')
                                                         ->orderByDesc('id')
                                                         ->first();
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
                                        ...self::fotoField('bomba_foto', 'Foto bomba', 'bomba', false, "bomba_foto_{$pool->id}"),
                                        ...self::fotoField('contador_foto', 'Foto contador', 'contador', false, "contador_foto_{$pool->id}"),
                                    ])->columns(3)
                            )->toArray()
                        );

                    $stepTanques = Forms\Components\Wizard\Step::make('Tanques')
                        ->icon('heroicon-o-beaker')
                        ->visible((bool) $installation->tanques_verificaveis)
                        ->schema(
                            $poolsByBombas->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->schema([
                                        Forms\Components\Toggle::make('tanque_ok')
                                            ->id("tanque_ok_{$pool->id}")
                                            ->label('Tanque OK')->default(true),
                                        Forms\Components\Textarea::make('tanque_observacoes')
                                            ->id("tanque_observacoes_{$pool->id}")
                                            ->label('Observações'),
                                        ...self::fotoField('tanque_foto', 'Foto Tanque', 'tanque', false, "tanque_foto_{$pool->id}"),
                                    ])
                            )->toArray()
                        );

                    $stepLavagem = Forms\Components\Wizard\Step::make('Lavagem filtros')
                        ->icon('heroicon-o-funnel')
                        ->schema(
                            $poolsByFiltros->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->schema([
                                        Forms\Components\Toggle::make('filtro_faz_retrolavagem')
                                            ->id("filtro_faz_retrolavagem_{$pool->id}")
                                            ->label('Fazer retrolavagem?')->default(false)->live(),
                                        Forms\Components\ViewField::make('timer_lavagem')
                                            ->id("timer_lavagem_{$pool->id}")
                                            ->view('filament.timer-retrolavagem')
                                            ->default(3)
                                            ->visible(fn(Get $get) => $get('filtro_faz_retrolavagem')),
                                        ...self::fotoField('filtro_foto_retrolavagem', 'Foto da lavagem', 'filtros', false, "filtro_foto_retrolavagem_{$pool->id}"),
                                    ])
                            )->toArray()
                        );

                    $stepEnxaguamento = Forms\Components\Wizard\Step::make('Enxaguamento')
                        ->icon('heroicon-o-funnel')
                        ->schema(
                            $poolsByFiltros->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->visible(fn(Get $get) => $get("pools.{$pool->id}.filtro_faz_retrolavagem"))
                                    ->schema([
                                        Forms\Components\ViewField::make('timer_enxaguamento')
                                            ->id("timer_enxaguamento_{$pool->id}")
                                            ->view('filament.timer-retrolavagem')
                                            ->default(2),
                                        ...self::fotoField('filtro_foto_enxaguamento', 'Foto do enxaguamento', 'filtros', false, "filtro_foto_enxaguamento_{$pool->id}"),
                                    ])
                            )->toArray()
                        );

                    $stepPosicaoNormal = Forms\Components\Wizard\Step::make('Posição normal')
                        ->icon('heroicon-o-funnel')
                        ->schema(
                            $poolsByFiltros->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->visible(fn(Get $get) => $get("pools.{$pool->id}.filtro_faz_retrolavagem"))
                                    ->schema([
                                        ...self::fotoField('filtro_foto_posicao_normal', 'Foto posição normal', 'filtros', false, "filtro_foto_posicao_normal_{$pool->id}"),
                                    ])
                            )->toArray()
                        );

                    $stepNS = Forms\Components\Wizard\Step::make('Nadadores-salvadores')
                        ->icon('heroicon-o-users')
                        ->schema([
                            ...self::fotoField('ns_foto', 'Foto do quadro NS', 'ns-fotos', true, 'ns_foto_global'),
                            ...$poolsByBombas->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->schema([
                                        self::comSemaforo(Forms\Components\TextInput::make('ns_ph')->id("ns_ph_{$pool->id}")->label('pH')->numeric()->step(0.01)->required(), 'ns_ph', $pool),
                                        self::comSemaforo(Forms\Components\TextInput::make('ns_cloro_livre')->id("ns_cloro_livre_{$pool->id}")->label('Cl livre')->numeric()->step(0.01)->required(), 'ns_cloro_livre', $pool),
                                        self::comSemaforo(Forms\Components\TextInput::make('ns_cloro_total')
                                            ->id("ns_cloro_total_{$pool->id}")
                                            ->label('Cl total')
                                            ->numeric()
                                            ->step(0.01)
                                            ->required()
                                            ->rules([
                                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                                    if (filled($get('ns_cloro_livre')) && (float) $value < (float) $get('ns_cloro_livre')) {
                                                        $fail('O cloro total não pode ser inferior ao cloro livre.');
                                                    }
                                                },
                                            ]), 'ns_cloro_total', $pool),
                                        self::comSemaforo(Forms\Components\TextInput::make('ns_temperatura')->id("ns_temperatura_{$pool->id}")->label('Temp')->numeric()->step(0.01)->required(), 'ns_temperatura', $pool),
                                    ])->columns(4)
                            )->toArray()
                        ]);
                        
                    $stepObservacoes = Forms\Components\Wizard\Step::make('Observações')
                        ->icon('heroicon-o-chat-bubble-bottom-center-text')
                        ->schema(
                            $poolsByBombas->map(fn(Pool $pool) => 
                                Forms\Components\Fieldset::make($pool->name)
                                    ->statePath("pools.{$pool->id}")
                                    ->schema([
                                        Forms\Components\Textarea::make('acao_corretiva')->id("acao_corretiva_{$pool->id}")->label('Ação corretiva (Químicos, etc)'),
                                        Forms\Components\Repeater::make('adicoes')
                                            ->id("adicoes_{$pool->id}")
                                            ->label('Adições de Químicos')
                                            ->schema([
                                                Forms\Components\Select::make('product_id')
                                                    ->label('Produto')
                                                    ->options(\App\Models\Product::query()->pluck('name', 'id'))
                                                    ->required(),
                                                Forms\Components\TextInput::make('quantity')
                                                    ->label('Quantidade')
                                                    ->numeric()
                                                    ->minValue(0.01)
                                                    ->step(0.01)
                                                    ->required()
                                                    ->rules([
                                                        function (Get $get) use ($installation) {
                                                            return function (string $attribute, $value, Closure $fail) use ($get, $installation) {
                                                                $productId = $get('product_id');
                                                                if (! $productId || ! $value) return;
                                                                $stock = \App\Models\StockInstallation::where('installation_id', $installation->id)
                                                                    ->where('product_id', $productId)->first();
                                                                if (! $stock || $stock->quantity < (float) $value) {
                                                                    $fail('Stock insuficiente na instalação.');
                                                                }
                                                            };
                                                        },
                                                    ]),
                                            ])->columns(2),
                                        Forms\Components\Textarea::make('observacoes')->id("observacoes_{$pool->id}")->label('Observações gerais'),
                                    ])
                            )->toArray()
                        );

                    $steps = self::isNS()
                        ? [$stepNS]
                        : [
                            $stepBombas,
                            $stepTanques,
                            $stepLavagem,
                            $stepEnxaguamento,
                            $stepPosicaoNormal,
                            $stepNS,
                            $stepObservacoes,
                        ];

                    return [
                        Forms\Components\Wizard::make($steps)->skippable()
                    ];
                })
        ])->columns(1);
    }
}
