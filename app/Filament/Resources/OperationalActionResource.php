<?php declare(strict_types=1);
namespace App\Filament\Resources;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource\Pages;
use App\Models\OperationalAction;
use App\Models\Pool;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OperationalActionResource extends Resource
{
    protected static ?string $model = OperationalAction::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Ações Operacionais';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Ação Operacional';

    protected static ?string $pluralModelLabel = 'Ações Operacionais';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['piscina.instalacao', 'utilizador']);

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('pool_id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        if ($user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            return true;
        }
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return $user->podeVer(NSPermission::REGISTO_DIARIO) && $user->piscinas()->exists();
        }
        return false;
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

    private static function piscinasOptions(): array
    {
        $query = Pool::query()->where('active', true)->with('instalacao');

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query->orderBy('installation_id')->orderBy('name')->get()
            ->mapWithKeys(fn (Pool $p) => [$p->id => $p->nome_completo])
            ->toArray();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('user_id')->default(auth()->id()),

            Forms\Components\Select::make('pool_id')
                ->label('Piscina')
                ->options(fn () => self::piscinasOptions())
                ->default(fn () => request()->integer('pool') ?: null)
                ->searchable()
                ->required(),

            Forms\Components\Select::make('tipo')
                ->label('Tipo de ação')
                ->options(OperationalAction::TIPOS)
                ->default(fn () => in_array(request()->query('tipo'), array_keys(OperationalAction::TIPOS), true) ? request()->query('tipo') : null)
                ->required()
                ->live(),

            Forms\Components\DateTimePicker::make('registado_em')
                ->label('Data e hora')
                ->default(now())
                ->seconds(false)
                ->required(),

            // Lavagem / enxaguamento de filtro: duração opcional em minutos.
            Forms\Components\TextInput::make('dados.duracao_min')
                ->label('Duração (min)')
                ->numeric()->minValue(0)->step(1)
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            // Torneira / entrada de água.
            Forms\Components\Select::make('dados.agua_modo')
                ->label('Estado da água')
                ->options([
                    'auto_com_agua' => 'Auto com água',
                    'auto_sem_agua' => 'Auto sem água',
                    'on_com_agua' => 'ON com água',
                    'on_sem_agua' => 'ON sem água',
                    'off' => 'OFF sem água',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA),

            // Bomba.
            Forms\Components\Toggle::make('dados.bomba_ferrada')
                ->label('Bomba ferrada')
                ->default(true)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

            // Contador.
            Forms\Components\TextInput::make('dados.contador_valor')
                ->label('Leitura do contador (m³)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR),

            // Tanque.
            Forms\Components\Toggle::make('dados.tanque_ok')
                ->label('Tanque OK')
                ->default(true)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            // Análise rápida (parcial): pelo menos um parâmetro.
            Forms\Components\Fieldset::make('Valores medidos')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL)
                ->schema([
                    Forms\Components\TextInput::make('dados.ph')
                        ->label('pH')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal'])
                        ->rules([
                            fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                $preenchido = filled($value)
                                    || filled($get('dados.cloro_livre'))
                                    || filled($get('dados.cloro_total'))
                                    || filled($get('dados.temperatura'));
                                if (! $preenchido) {
                                    $fail('Preencha pelo menos um valor na análise rápida.');
                                }
                            },
                        ]),
                    Forms\Components\TextInput::make('dados.cloro_livre')
                        ->label('Cl livre')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.cloro_total')
                        ->label('Cl total')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.temperatura')
                        ->label('Temp')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                ])->columns(['default' => 2, 'sm' => 4]),

            Forms\Components\Textarea::make('observacoes')
                ->label('Observações')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('foto')
                ->label('Foto (opcional)')
                ->disk(\App\Models\DailyRecord::getStorageDisk())->visibility('public')
                ->directory('operational-actions')
                ->image()
                ->imageEditor()
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('1024')
                ->maxSize(20480)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('piscina.nome_completo')->label('Piscina'),
            Infolists\Components\TextEntry::make('tipo')
                ->label('Ação')
                ->badge()
                ->formatStateUsing(fn (string $state) => OperationalAction::TIPOS[$state] ?? $state),
            Infolists\Components\TextEntry::make('registado_em')->label('Data e hora')->dateTime('d/m/Y H:i'),
            Infolists\Components\TextEntry::make('utilizador.name')->label('Responsável'),
            Infolists\Components\KeyValueEntry::make('dados')
                ->label('Valores')
                ->visible(fn ($record) => filled($record->dados))
                ->columnSpanFull(),
            Infolists\Components\TextEntry::make('observacoes')
                ->label('Observações')
                ->visible(fn ($record) => filled($record->observacoes))
                ->columnSpanFull(),
            Infolists\Components\ImageEntry::make('foto')
                ->label('Foto')
                ->disk(\App\Models\DailyRecord::getStorageDisk())
                ->visible(fn ($record) => filled($record->foto))
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('registado_em', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('registado_em')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('piscina.nome_completo')
                    ->label('Piscina')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Ação')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => OperationalAction::TIPOS[$state] ?? $state),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Responsável')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('observacoes')
                    ->label('Observações')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(OperationalAction::TIPOS),
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->options(fn () => self::piscinasOptions()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalActions::route('/'),
            'create' => Pages\CreateOperationalAction::route('/create'),
            'view' => Pages\ViewOperationalAction::route('/{record}'),
        ];
    }
}
