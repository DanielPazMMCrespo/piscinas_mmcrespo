<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource;

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

class DailyRecordFormBuilder
{
    private static function sectionRing(bool $complete): array
    {
        return ['class' => $complete
            ? 'ring-2 ring-green-500 ring-offset-2 rounded-xl'
            : 'ring-2 ring-red-500 ring-offset-2 rounded-xl'];
    }

    private static array $ultimoCache = [];
    private static array $poolCache = [];
    private static function fotoPreview(string $field, string $label): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make($field . '_preview')
            ->label($label)
            ->visible(fn ($record, Get $get) => $record !== null && filled($record->{$field}) && !((bool)$get('substituir_' . $field)) && ($field === 'filtro_foto_retrolavagem' || $field === 'filtro_foto_enxaguamento' || $field === 'filtro_foto_posicao_normal' ? (bool)$get('filtro_faz_retrolavagem') : true))
            ->content(function ($record) use ($field) {
                $val = $record->{$field};
                if (empty($val)) {
                    return null;
                }

                $paths = is_array($val) ? $val : [$val];
                $html = '<div class="flex flex-wrap gap-4 mt-2 mb-2">';
                foreach ($paths as $path) {
                    $url = DailyRecord::getStorageUrl($path);
                    $html .= "<div class='relative group'><a href='{$url}' class='glightbox-trigger block overflow-hidden rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 hover:ring-2 hover:ring-primary-500 hover:shadow-md transition-all duration-200'><img src='{$url}' class='object-cover h-40 w-56 group-hover:scale-105 transition-transform duration-300 cursor-zoom-in' alt='Preview da foto' /></a></div>";
                }
                $html .= '</div>';
                return new \Illuminate\Support\HtmlString($html);
            });
    }

    private static function fotoField(
        string $field,
        string $label,
        string $directory,
        bool $multiple = false,
        int $maxFiles = 5,
        ?Closure $extraVisible = null,
        ?string $helperText = null,
        bool $required = false
    ): array {
        $toggleName = 'substituir_' . $field;

        $defaultHelper = 'Max. 5MB. HEIC aceite. Em iPhone: Definições > Câmara > Formato > Mais Compatível';
        $fullHelper = $helperText ? "{$helperText} · {$defaultHelper}" : $defaultHelper;

        return [
            Forms\Components\Toggle::make($toggleName)
                ->label('Substituir foto existente')
                ->visible(fn ($record, Get $get) =>
                    $record !== null &&
                    filled($record->{$field}) &&
                    ($extraVisible ? $extraVisible($record, $get) : true)
                )
                ->dehydrated(false)
                ->live(),

            self::fotoPreview($field, 'Visualização da Foto'),

            Forms\Components\FileUpload::make($field)
                ->label($label)
                ->disk(DailyRecord::getStorageDisk())->visibility('public')
                ->directory($directory)
                ->image()
                ->multiple($multiple)
                ->maxFiles($multiple ? $maxFiles : null)
                ->reorderable($multiple)
                ->maxSize(5120)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                ->openable()
                ->downloadable()
                ->helperText($fullHelper)
                ->required($required)
                ->visible(fn ($record, Get $get) =>
                    ($record === null || !filled($record->{$field}) || (bool)$get($toggleName)) &&
                    ($extraVisible ? $extraVisible($record, $get) : true)
                )
                ->columnSpanFull(),
        ];
    }
    /**
     * @return array<int, array{campo: string, label: string, duracaoSegundos: int}>
     */
    public static function timerRetrolavagemConfig(): array
    {
        return [
            ['campo' => 'filtro_foto_retrolavagem', 'label' => 'Timer — Retrolavagem', 'duracaoSegundos' => 300],
            ['campo' => 'filtro_foto_enxaguamento', 'label' => 'Timer — Enxaguamento', 'duracaoSegundos' => 120],
        ];
    }

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
            'cloro_total', 'ns_cloro_total' => self::avaliarCloroCombinado($get, $campo, $pool),
            default => ['estado' => \App\Enums\EstadoConformidade::NEUTRO, 'mensagem' => ''],
        };
    }

    private static function avaliarCloroCombinado(Get $get, string $campo, ?Pool $pool): array
    {
        $prefix = str_starts_with($campo, 'ns_') ? 'ns_' : '';
        $livre = $get($prefix . 'cloro_livre');
        $total = $get($prefix . 'cloro_total');

        if ($livre === null || $total === null || $livre === '' || $total === '') {
            return ['estado' => \App\Enums\EstadoConformidade::NEUTRO, 'mensagem' => ''];
        }

        $combinado = (float)$total - (float)$livre;
        return DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
    }

    private static function corSemaforo(\App\Enums\EstadoConformidade $estado): ?string
    {
        return match ($estado) {
            \App\Enums\EstadoConformidade::VERDE => 'success',
            \App\Enums\EstadoConformidade::AMARELO => 'warning',
            \App\Enums\EstadoConformidade::VERMELHO => 'danger',
            \App\Enums\EstadoConformidade::NEUTRO => null,
        };
    }

    private static function iconeSemaforo(\App\Enums\EstadoConformidade $estado): ?string
    {
        return match ($estado) {
            \App\Enums\EstadoConformidade::VERDE => 'heroicon-m-check-circle',
            \App\Enums\EstadoConformidade::AMARELO => 'heroicon-m-exclamation-circle',
            \App\Enums\EstadoConformidade::VERMELHO => 'heroicon-m-x-circle',
            \App\Enums\EstadoConformidade::NEUTRO => null,
        };
    }

    private static function createHint(string $metrica, string $campo): Closure
    {
        return fn (Get $get): string => self::conformidadeCampo($metrica, $get)['mensagem'] ?: '';
    }

    private static function comSemaforo(Forms\Components\TextInput $campo, string $metrica): Forms\Components\TextInput
    {
        return $campo
            ->live(onBlur: true)
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->hint(fn (Get $get): ?string => self::conformidadeCampo($metrica, $get)['mensagem'] ?: null)
            ->hintColor(fn (Get $get): ?string => self::corSemaforo(self::conformidadeCampo($metrica, $get)['estado']))
            ->hintIcon(fn (Get $get): ?string => self::iconeSemaforo(self::conformidadeCampo($metrica, $get)['estado']))
            ->extraAttributes(function (Get $get) use ($metrica) {
                $classes = [];
                if (self::conformidadeCampo($metrica, $get)['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                    $classes[] = 'ring-2 ring-danger-500 ring-inset bg-danger-50 dark:bg-danger-900/30';
                }
                return ['class' => implode(' ', $classes)];
            });
    }

    private static function haNaoConformidade(Get $get): bool
    {
        foreach (['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia'] as $campo) {
            if (self::conformidadeCampo($campo, $get)['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
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
        $step1 = [
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
                        ->relationship('piscina', 'name', function ($query) {
                            $user = auth()->user();
                            if ($user->hasRole(UserRole::NADADOR_SALVADOR)) {
                                return $query->whereIn('id', $user->piscinas()->pluck('pools.id'));
                            }
                            return $query;
                        })
                        ->required()
                        ->preload()
                        ->searchable()
                        ->default(function (): ?int {
                            $user = auth()->user();
                            $requested = request()->integer('pool') ?: null;

                            if ($user->hasRole(UserRole::NADADOR_SALVADOR)) {
                                $allowedIds = $user->piscinas()->pluck('pools.id');
                                if ($requested && $allowedIds->contains($requested)) {
                                    return $requested;
                                }
                                $last = DailyRecord::query()
                                    ->where('user_id', $user->id)
                                    ->whereIn('pool_id', $allowedIds)
                                    ->orderByDesc('registado_em')
                                    ->orderByDesc('id')
                                    ->value('pool_id');
                                return $last ?? $allowedIds->first();
                            }

                            return $requested
                                ?: DailyRecord::query()
                                    ->where('user_id', $user->id)
                                    ->orderByDesc('registado_em')
                                    ->orderByDesc('id')
                                    ->value('pool_id');
                        })
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            $ultimo = self::ultimoRegisto($state ? (int) $state : null);
                            $set('bomba_ferrada', $ultimo?->bomba_ferrada);
                            $set('agua_modo', $ultimo?->agua_modo);
                            $set('tanque_ok', $ultimo?->tanque_ok);
                        }),
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
                        ->disabled(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
                        ->dehydrated()
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
                    ...self::fotoField('bomba_foto', 'Foto da Bomba', 'bomba', false, 5, null, 'Foto opcional da bomba para documentação'),
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
                        ->label('Contador (m³)')
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
                        ->label('Entrada de Água')
                        ->options([
                            'auto_com_agua' => 'Automático — com água',
                            'auto_sem_agua' => 'Automático — sem água',
                            'on_com_agua' => 'ON — com água',
                            'on_sem_agua' => 'ON — sem água',
                            'off' => 'OFF — sem água na instalação',
                            'inoperacional' => 'Inoperacional',
                        ])
                        ->native(false)
                        ->default(fn (Get $get) => self::ultimoRegisto($get('pool_id') ? (int) $get('pool_id') : null)?->agua_modo)
                        ->live(onBlur: true),
                    ...self::fotoField('contador_foto', 'Foto do Contador', 'contador', false, 5, null, 'Evidência fotográfica da leitura do contador'),
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
                    ...self::fotoField('tanque_foto', 'Foto do Tanque', 'tanque', false, 5, null, 'Foto opcional do tanque de compensação para documentação'),
                ]),
        ];

        $step2 = [
            Forms\Components\Section::make('Análises — Nadador-Salvador')
                ->description(fn (Get $get): string => 'Leituras feitas pelo Nadador-Salvador. '.self::progresso(['ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'], $get))
                ->icon('heroicon-o-eye')
                ->collapsible()
                ->columns(2)
                ->extraAttributes(fn (Get $get): array => self::sectionRing(
                    filled($get('ns_ph')) && filled($get('ns_cloro_livre'))
                ))
                ->schema([
                    ...self::fotoField('ns_foto', 'Foto da Análise NS', 'ns-fotos', required: true),
                    self::comSemaforo(
                        Forms\Components\TextInput::make('ns_ph')
                            ->label('pH (NS)')
                            ->required()
                            ->numeric()->step(0.01)->minValue(0)->maxValue(14),
                        'ns_ph'
                    ),
                    self::comSemaforo(
                        Forms\Components\TextInput::make('ns_cloro_livre')
                            ->label('Cloro Livre — NS (mg/L)')
                            ->required()
                            ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                        'ns_cloro_livre'
                    ),
                    self::comSemaforo(
                        Forms\Components\TextInput::make('ns_cloro_total')
                            ->label('Cloro Total — NS (mg/L)')
                            ->required()
                            ->numeric()->step(0.01)->minValue(0)->maxValue(20),
                        'ns_cloro_total'
                    ),
                    self::comSemaforo(
                        Forms\Components\TextInput::make('ns_temperatura')
                            ->label('Temperatura — NS (ºC)')
                            ->required()
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
                            ->numeric()->step(0.01)->minValue(0)->maxValue(DailyRecord::TRANSPARENCIA_MAX),
                        'transparencia'
                    ),
                    ...self::fotoField('analises_fotos', 'Fotos das análises (até 5)', 'analises', true, 5),
                ]),
        ];

        $step3 = [
            Forms\Components\Section::make('Filtros')
                ->description(fn (Get $get): string => self::descricaoFiltros($get))
                ->icon('heroicon-o-funnel')
                ->collapsible()
                ->extraAttributes(fn (): array => self::sectionRing(true))
                ->schema([
                    Forms\Components\Toggle::make('filtro_faz_retrolavagem')
                        ->label('Fazer Retrolavagem?')
                        ->helperText(fn (Get $get): string => self::helperRetrolavagem($get))
                        ->default(false)
                        ->live(),
                    ...self::fotoField('filtro_foto_retrolavagem', 'Foto — Posição Retrolavagem', 'filtros', false, 5, fn ($record, Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                    ...self::fotoField('filtro_foto_enxaguamento', 'Foto — Posição Enxaguamento', 'filtros', false, 5, fn ($record, Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                    ...self::fotoField('filtro_foto_posicao_normal', 'Foto — Retorno à Posição Normal', 'filtros', false, 5, fn ($record, Get $get): bool => $get('filtro_faz_retrolavagem') === true),
                ]),
        ];

        $step4 = [
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
        ];

        if ($form->getOperation() === 'create') {
            return $form
                ->schema([
                    Forms\Components\Wizard::make([
                        Forms\Components\Wizard\Step::make('Piscina & Estado')
                            ->icon('heroicon-o-home')
                            ->schema($step1),
                        Forms\Components\Wizard\Step::make('Análises')
                            ->icon('heroicon-o-beaker')
                            ->schema($step2),
                        Forms\Components\Wizard\Step::make('Filtros')
                            ->icon('heroicon-o-funnel')
                            ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
                            ->schema($step3),
                        Forms\Components\Wizard\Step::make('Químicos & Notas')
                            ->icon('heroicon-o-sparkles')
                            ->schema($step4),
                    ])
                    ->skippable()
                    ->columnSpanFull(),
                ]);
        }

        return $form
            ->schema([
                Forms\Components\Tabs::make('Registo')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Operacional')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->schema($step1),
                        Forms\Components\Tabs\Tab::make('Química')
                            ->icon('heroicon-o-beaker')
                            ->schema($step2),
                        Forms\Components\Tabs\Tab::make('Filtros')
                            ->icon('heroicon-o-funnel')
                            ->hidden(fn (): bool => auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false)
                            ->schema($step3),
                        Forms\Components\Tabs\Tab::make('Notas')
                            ->icon('heroicon-o-wrench-screwdriver')
                            ->schema($step4),
                    ])
                    ->columnSpanFull(),
            ]);
    }

}
