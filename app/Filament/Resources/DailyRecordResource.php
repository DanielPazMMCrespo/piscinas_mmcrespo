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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ── SECÇÃO 1 — Informação Geral ──────────────────────────────────
                Forms\Components\Section::make('Informação Geral')
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
                            ->live(onBlur: true),
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

                // ── SECÇÃO 2 — Nadadores Salvadores ──────────────────────────────
                Forms\Components\Section::make('Nadadores Salvadores')
                    ->description('Leituras registadas pelo Nadador-Salvador.')
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
                        Forms\Components\TextInput::make('ns_ph')
                            ->label('pH (NS)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(14)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('ns_cloro_livre')
                            ->label('Cloro Livre — NS (mg/L)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('ns_cloro_total')
                            ->label('Cloro Total — NS (mg/L)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('ns_temperatura')
                            ->label('Temperatura — NS (ºC)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(50)
                            ->live(onBlur: true),
                    ]),

                // ── SECÇÃO 3 — Nossas Análises ───────────────────────────────────
                Forms\Components\Section::make('Nossas Análises')
                    ->columns(2)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('ph'))
                        && filled($get('cloro_livre'))
                        && filled($get('cloro_total'))
                        && filled($get('transparencia'))
                    ))
                    ->schema([
                        Forms\Components\TextInput::make('ph')
                            ->label('pH')
                            ->helperText('Limite legal CN 14/DA: ' . DailyRecord::PH_MIN . ' a ' . DailyRecord::PH_MAX)
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(14)
                            ->rules(['between:0,14'])
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('cloro_livre')
                            ->label('Cloro Livre (mg/L)')
                            ->helperText('Limite legal: ' . DailyRecord::CLORO_LIVRE_MIN . ' a ' . DailyRecord::CLORO_LIVRE_MAX . ' mg/L')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('cloro_total')
                            ->label('Cloro Total (mg/L)')
                            ->helperText('Cloro combinado (total − livre) deve ser ≤ ' . DailyRecord::CLORO_COMBINADO_MAX . ' mg/L')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
                            ->live(onBlur: true)
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    if (filled($get('cloro_livre')) && (float) $value < (float) $get('cloro_livre')) {
                                        $fail('O cloro total não pode ser inferior ao cloro livre.');
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Turbidez (FNU)')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(100)
                            ->live(onBlur: true),
                    ]),

                // ── SECÇÃO 4 — Filtros ────────────────────────────────────────────
                Forms\Components\Section::make('Filtros')
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

                // ── SECÇÃO 5 — Adições de Químicos ───────────────────────────────
                Forms\Components\Section::make('Adições de Químicos')
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Repeater::make('adicoes')
                            ->relationship()
                            ->label('')
                            ->columns(2)
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
                                                    ->step(0.01),
                                                Forms\Components\TextInput::make('concentracao')
                                                    ->label('% Concentração do produto')
                                                    ->numeric()
                                                    ->required()
                                                    ->step(0.1)
                                                    ->minValue(0.1)
                                                    ->maxValue(100),
                                            ])
                                            ->action(function (array $data, Set $set, Get $get): void {
                                                $poolId = $get('../../pool_id');
                                                $pool   = Pool::find($poolId);

                                                if (! $pool || ! $pool->volume || $data['concentracao'] <= 0) {
                                                    Notification::make()
                                                        ->warning()
                                                        ->title('Cálculo impossível')
                                                        ->body('O volume da piscina não está definido ou a concentração é inválida.')
                                                        ->send();
                                                    return;
                                                }

                                                $result = ($pool->volume * $data['dosagem']) / $data['concentracao'];
                                                $set('quantity', round($result, 2));
                                            })
                                    ),
                            ]),
                    ]),

                // ── SECÇÃO 6 — Observações ────────────────────────────────────────
                Forms\Components\Section::make('Observações')
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // ── SECÇÃO 7 — Informação de Correção ─────────────────────────────
                Forms\Components\Section::make('Informação de Correção')
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
                            ->formatStateUsing(fn ($state): string => 'pH ' . $state)
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->phConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->phConforme() ? null : 'Fora do limite legal (' . DailyRecord::PH_MIN . '–' . DailyRecord::PH_MAX . ')'),
                        Tables\Columns\TextColumn::make('cloro_livre')
                            ->label('Cloro L.')
                            ->formatStateUsing(fn ($state): string => 'Cl ' . $state . ' mg/L')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->cloroLivreConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->cloroLivreConforme() ? null : 'Fora do limite legal (' . DailyRecord::CLORO_LIVRE_MIN . '–' . DailyRecord::CLORO_LIVRE_MAX . ' mg/L)'),
                        Tables\Columns\TextColumn::make('transparencia')
                            ->label('Turbidez')
                            ->formatStateUsing(fn ($state): string => $state . ' FNU')
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
                //
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
                        'ph'           => $record->ph,
                        'cloro_livre'  => $record->cloro_livre,
                        'cloro_total'  => $record->cloro_total,
                        'transparencia'=> $record->transparencia,
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
                            'pool_id'            => $record->pool_id,
                            'user_id'            => auth()->id(),
                            'registado_em'       => $record->registado_em,
                            'ph'                 => $data['ph'],
                            'cloro_livre'        => $data['cloro_livre'],
                            'cloro_total'        => $data['cloro_total'],
                            'transparencia'      => $data['transparencia'],
                            'temperatura'        => $record->temperatura,
                            'caleira_feita'      => $record->caleira_feita,
                            'renovacao_agua'     => $record->renovacao_agua,
                            'observacoes'        => $record->observacoes,
                            'e_correcao'         => true,
                            'corrige_registo_id' => $record->id,
                            'razao_correcao'     => $data['razao_correcao'],
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
            'index'  => Pages\ListDailyRecords::route('/'),
            'create' => Pages\CreateDailyRecord::route('/create'),
            'view'   => Pages\ViewDailyRecord::route('/{record}'),
            'edit'   => Pages\EditDailyRecord::route('/{record}/edit'),
        ];
    }
}
