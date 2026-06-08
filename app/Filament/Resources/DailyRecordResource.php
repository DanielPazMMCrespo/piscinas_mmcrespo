<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use App\Services\OcrVisionService;
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

    /** Anel verde/vermelho para indicar se a secção está completa. */
    private static function sectionRing(bool $complete): array
    {
        return ['class' => $complete
            ? 'ring-2 ring-green-500 ring-offset-2 rounded-xl'
            : 'ring-2 ring-red-500 ring-offset-2 rounded-xl'];
    }

    /** Callback partilhado para processar OCR após upload. */
    private static function ocrAfterStateUpdated(mixed $state, Set $set): void
    {
        if (! $state) {
            return;
        }

        try {
            $result = app(OcrVisionService::class)->analyze($state);

            foreach ([
                'ph', 'cloro_livre', 'cloro_total', 'temperatura',
                'transparencia', 'pressao_filtro', 'estado_valvulas_filtro', 'observacoes',
            ] as $field) {
                if (isset($result[$field]) && $result[$field] !== null) {
                    $set($field, $result[$field]);
                }
            }

            Notification::make()
                ->success()
                ->title('OCR concluído')
                ->body('Campos preenchidos automaticamente. Confirma antes de guardar.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Erro no OCR')
                ->body('Não foi possível analisar a imagem: ' . $e->getMessage())
                ->send();
        }
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

                // ── SECÇÃO 2 — OCR: Foto NS ───────────────────────────────────────
                Forms\Components\Section::make('Foto NS — Leitura Automática (OCR)')
                    ->description('Faz upload da foto da folha de análise. Os campos são preenchidos automaticamente via IA.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\FileUpload::make('foto_ocr_ns')
                            ->label('Foto (Nadador-Salvador)')
                            ->disk('local')
                            ->directory('ocr-temp')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => self::ocrAfterStateUpdated($state, $set)),
                    ]),

                // ── SECÇÃO 3 — OCR: Foto Técnico ──────────────────────────────────
                Forms\Components\Section::make('Foto Técnico — Leitura Automática (OCR)')
                    ->description('Faz upload da foto da folha de análise do técnico.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\FileUpload::make('foto_ocr_tecnico')
                            ->label('Foto (Técnico)')
                            ->disk('local')
                            ->directory('ocr-temp')
                            ->image()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => self::ocrAfterStateUpdated($state, $set)),
                    ]),

                // ── SECÇÃO 4 — Parâmetros da Água ────────────────────────────────
                Forms\Components\Section::make('Parâmetros da Água')
                    ->columns(3)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('ph'))
                        && filled($get('cloro_livre'))
                        && filled($get('cloro_total'))
                        && filled($get('temperatura'))
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
                        Forms\Components\TextInput::make('temperatura')
                            ->label('Temperatura (ºC)')
                            ->required()
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0)
                            ->maxValue(45)
                            ->rules(['between:0,45'])
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Transparência (m)')
                            ->required()
                            ->numeric()
                            ->step(1)
                            ->minValue(0)
                            ->maxValue(100)
                            ->live(onBlur: true),
                    ]),

                // ── SECÇÃO 5 — Bomba e Filtro ─────────────────────────────────────
                Forms\Components\Section::make('Bomba e Filtro')
                    ->columns(3)
                    ->extraAttributes(fn (Get $get): array => self::sectionRing(
                        filled($get('estado_valvulas_filtro'))
                    ))
                    ->schema([
                        Forms\Components\Toggle::make('bomba_com_bolhas')
                            ->label('Bomba com bolhas de ar?')
                            ->helperText('Indica presença de ar no circuito')
                            ->default(false)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('pressao_filtro')
                            ->label('Pressão do Filtro (bar)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(10)
                            ->placeholder('ex: 1.5')
                            ->live(onBlur: true),
                        Forms\Components\Select::make('estado_valvulas_filtro')
                            ->label('Estado das Válvulas')
                            ->required()
                            ->options([
                                'normal'       => 'Normal (filtração)',
                                'retrolavagem' => 'Retrolavagem',
                                'enxaguamento' => 'Enxaguamento',
                                'circulacao'   => 'Circulação',
                                'avaria'       => 'Avaria',
                            ])
                            ->live(onBlur: true),
                    ]),

                // ── SECÇÃO 6 — Tarefas de Manutenção ─────────────────────────────
                Forms\Components\Section::make('Tarefas de Manutenção')
                    ->columns(2)
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Toggle::make('caleira_feita')
                            ->label('Caleira Trasbordada?')
                            ->default(false),
                        Forms\Components\Toggle::make('renovacao_agua')
                            ->label('Renovação de Água Feita?')
                            ->default(false),
                    ]),

                // ── SECÇÃO 7 — Observações ────────────────────────────────────────
                Forms\Components\Section::make('Observações')
                    ->extraAttributes(fn (): array => self::sectionRing(true))
                    ->schema([
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // ── SECÇÃO 8 — Informação de Correção ─────────────────────────────
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
                        Tables\Columns\TextColumn::make('temperatura')
                            ->label('Temp.')
                            ->formatStateUsing(fn ($state): string => $state . ' °C')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->temperaturaConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->temperaturaConforme() ? null : 'Fora dos limites desta piscina'),
                        Tables\Columns\IconColumn::make('bomba_com_bolhas')
                            ->label('Bomba')
                            ->boolean()
                            ->trueIcon('heroicon-o-exclamation-triangle')
                            ->falseIcon('heroicon-o-check-circle')
                            ->trueColor('warning')
                            ->falseColor('success'),
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
                        'cloro_livre'            => $record->cloro_livre,
                        'cloro_total'            => $record->cloro_total,
                        'ph'                     => $record->ph,
                        'temperatura'            => $record->temperatura,
                        'transparencia'          => $record->transparencia,
                        'bomba_com_bolhas'       => $record->bomba_com_bolhas,
                        'pressao_filtro'         => $record->pressao_filtro,
                        'estado_valvulas_filtro' => $record->estado_valvulas_filtro,
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
                        Forms\Components\TextInput::make('temperatura')
                            ->label('Temperatura (ºC)')
                            ->required()->numeric()->step(0.1)->minValue(0)->maxValue(45),
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Transparência (m)')
                            ->required()->numeric()->step(1)->minValue(0)->maxValue(100),
                        Forms\Components\Toggle::make('bomba_com_bolhas')
                            ->label('Bomba com bolhas de ar?')
                            ->default(false),
                        Forms\Components\TextInput::make('pressao_filtro')
                            ->label('Pressão do Filtro (bar)')
                            ->numeric()->step(0.01)->minValue(0)->maxValue(10),
                        Forms\Components\Select::make('estado_valvulas_filtro')
                            ->label('Estado das Válvulas')
                            ->required()
                            ->options([
                                'normal'       => 'Normal (filtração)',
                                'retrolavagem' => 'Retrolavagem',
                                'enxaguamento' => 'Enxaguamento',
                                'circulacao'   => 'Circulação',
                                'avaria'       => 'Avaria',
                            ]),
                        Forms\Components\Textarea::make('razao_correcao')
                            ->label('Razão da correção')
                            ->required()
                            ->minLength(5)
                            ->columnSpanFull(),
                    ])
                    ->action(function (DailyRecord $record, array $data): void {
                        DailyRecord::create([
                            'pool_id'                => $record->pool_id,
                            'user_id'                => auth()->id(),
                            'registado_em'           => $record->registado_em,
                            'cloro_livre'            => $data['cloro_livre'],
                            'cloro_total'            => $data['cloro_total'],
                            'ph'                     => $data['ph'],
                            'temperatura'            => $data['temperatura'],
                            'transparencia'          => $data['transparencia'],
                            'bomba_com_bolhas'       => $data['bomba_com_bolhas'] ?? false,
                            'pressao_filtro'         => $data['pressao_filtro'] ?? null,
                            'estado_valvulas_filtro' => $data['estado_valvulas_filtro'] ?? null,
                            'caleira_feita'          => $record->caleira_feita,
                            'renovacao_agua'         => $record->renovacao_agua,
                            'observacoes'            => $record->observacoes,
                            'e_correcao'             => true,
                            'corrige_registo_id'     => $record->id,
                            'razao_correcao'         => $data['razao_correcao'],
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
