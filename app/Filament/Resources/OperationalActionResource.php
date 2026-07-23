<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource\Pages;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
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

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false;
    }

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
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false;
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
        return Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get()
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

            // Lavagem / enxaguamento de filtro.
            Forms\Components\TextInput::make('dados.filtro_nome')
                ->label('Identificação do filtro (opcional)')
                ->placeholder('Ex.: Filtro 1, Filtro Principal')
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.duracao_min')
                ->label('Duração (min)')
                ->numeric()->minValue(0)->step(1)
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.pressao_antes_bar')
                ->label('Pressão inicial (bar)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.pressao_depois_bar')
                ->label('Pressão final (bar)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
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
            Forms\Components\TextInput::make('dados.bomba_nome')
                ->label('Identificação da bomba (opcional)')
                ->placeholder('Ex.: Bomba 1, Recirculação')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

            Forms\Components\Select::make('dados.bomba_acao')
                ->label('Ação realizada')
                ->options([
                    'ferragem' => 'Ferragem de bomba',
                    'limpeza_pre_filtro' => 'Limpeza de pré-filtro',
                    'paragem_arranque' => 'Paragem / Arranque',
                    'manutencao' => 'Manutenção / Reparação',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

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
            Forms\Components\TextInput::make('dados.tanque_nome')
                ->label('Identificação do tanque')
                ->placeholder('Ex.: Tanque de Compensação')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            Forms\Components\TextInput::make('dados.tanque_nivel_pct')
                ->label('Nível estimado (%)')
                ->numeric()->minValue(0)->maxValue(100)->step(1)
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            Forms\Components\Toggle::make('dados.tanque_ok')
                ->label('Tanque OK')
                ->default(true)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            // Reabastecimento de bidão.
            Forms\Components\Select::make('dados.bidao_tipo')
                ->label('Tipo de bidão / produto')
                ->options([
                    DosingContainer::TIPO_CLORO => 'Cloro',
                    DosingContainer::TIPO_PH_MENOS => 'pH-',
                    'coagulante' => 'Coagulante / Floculante',
                    'ph_mais' => 'pH+',
                    'anti_algas' => 'Anti-algas',
                    'ambos' => 'Ambos (Cloro e pH-)',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->live(),

            Forms\Components\TextInput::make('dados.quantidade_l')
                ->label('Quantidade reabastecida (L)')
                ->numeric()
                ->step('any')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO && $get('dados.bidao_tipo') !== 'ambos')
                ->default(function (Get $get) {
                    $poolId = $get('pool_id');
                    $tipo = $get('dados.bidao_tipo');
                    if ($poolId && $tipo && $tipo !== 'ambos') {
                        $container = DosingContainer::where('pool_id', $poolId)
                            ->where('tipo', $tipo)
                            ->first();
                        if ($container && $container->capacidade_ml) {
                            return $container->capacidade_ml / 1000;
                        }
                    }

                    return null;
                })
                ->helperText(fn (Get $get) => $get('dados.bidao_tipo') === 'ambos'
                    ? 'Deixe em branco para encher ambos os bidões até às respetivas capacidades totais.'
                    : 'Por defeito, assume o tamanho total (capacidade) configurado para o bidão desta piscina.'),

            // Tratamento de choque.
            Forms\Components\TextInput::make('dados.produto')
                ->label('Produto utilizado')
                ->placeholder('Ex.: Hipoclorito de Cálcio / Cloro Choque')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE),

            Forms\Components\TextInput::make('dados.quantidade')
                ->label('Quantidade / Dose')
                ->placeholder('Ex.: 5 kg, 10 L')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE),

            // Análise rápida (parcial): pelo menos um parâmetro.
            Forms\Components\Fieldset::make('Valores medidos')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL)
                ->schema([
                    Forms\Components\TextInput::make('dados.ph')
                        ->label('pH')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal'])
                        ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL
                            && !filled($get('dados.cloro_livre'))
                            && !filled($get('dados.cloro_total'))
                            && !filled($get('dados.orp'))
                            && !filled($get('dados.temperatura'))
                        )
                        ->validationMessages([
                            'required' => 'Preencha pelo menos um valor na análise rápida.',
                        ]),
                    Forms\Components\TextInput::make('dados.cloro_livre')
                        ->label('Cl livre (mg/L)')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.cloro_total')
                        ->label('Cl total (mg/L)')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.orp')
                        ->label('ORP (mV)')->numeric()->step(1)
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    Forms\Components\TextInput::make('dados.temperatura')
                        ->label('Temp (°C)')->numeric()->step(0.1)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                ])->columns(['default' => 2, 'sm' => 5]),

            Forms\Components\Textarea::make('observacoes')
                ->label('Observações')
                ->rows(3)
                ->required(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_OUTRO,
                    OperationalAction::TIPO_LIMPEZA_PRAIAS,
                    OperationalAction::TIPO_ASPIRACAO_FUNDO,
                    OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO,
                ], true))
                ->helperText(fn (Get $get) => match ($get('tipo')) {
                    OperationalAction::TIPO_OUTRO => 'Descreva detalhadamente a ação realizada.',
                    OperationalAction::TIPO_LIMPEZA_PRAIAS => 'Especifique as zonas limpas ou desinfetadas.',
                    OperationalAction::TIPO_ASPIRACAO_FUNDO => 'Indique se usou robô ou aspiração manual.',
                    OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO => 'Descreva o equipamento e o trabalho efetuado.',
                    default => null,
                })
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('foto')
                ->label('Foto (opcional)')
                ->disk(DailyRecord::getStorageDisk())->visibility('public')
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
            Infolists\Components\TextEntry::make('valores')
                ->label('Valores')
                ->getStateUsing(fn (OperationalAction $record) => $record->dadosFormatados())
                ->columnSpanFull(),
            Infolists\Components\TextEntry::make('observacoes')
                ->label('Observações')
                ->visible(fn ($record) => filled($record->observacoes))
                ->columnSpanFull(),
            Infolists\Components\ImageEntry::make('foto')
                ->label('Foto')
                ->disk(DailyRecord::getStorageDisk())
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
                Tables\Columns\TextColumn::make('valores')
                    ->label('Valores')
                    ->getStateUsing(fn (OperationalAction $record) => $record->dadosFormatados())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Responsável')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('observacoes')
                    ->label('Observações')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('foto')
                    ->label('Foto')
                    ->icon('heroicon-o-camera')
                    ->boolean(fn ($record) => filled($record->foto))
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(OperationalAction::TIPOS),
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->options(fn () => self::piscinasOptions()),
                Tables\Filters\Filter::make('registado_em')
                    ->form([
                        Forms\Components\DatePicker::make('data_de')->label('Desde'),
                        Forms\Components\DatePicker::make('data_ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_de'] ?? null, fn (Builder $query, $date) => $query->whereDate('registado_em', '>=', $date))
                            ->when($data['data_ate'] ?? null, fn (Builder $query, $date) => $query->whereDate('registado_em', '<=', $date));
                    }),
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
