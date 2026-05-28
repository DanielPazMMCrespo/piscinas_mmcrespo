<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informação Geral')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('pool_id')
                            ->label('Piscina')
                            ->relationship('piscina', 'name')
                            ->required()
                            ->preload()
                            ->searchable(),
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
                            ->required(),
                    ]),

                Forms\Components\Section::make('Parâmetros da Água')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('ph')
                            ->label('pH')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(14)
                            ->rules(['between:0,14']),
                        Forms\Components\TextInput::make('cloro_livre')
                            ->label('Cloro Livre (mg/L)')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('cloro_total')
                            ->label('Cloro Total (mg/L)')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(20)
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
                            ->rules(['between:0,45']),
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Transparência (m)')
                            ->required()
                            ->numeric()
                            ->step(1)
                            ->minValue(0)
                            ->maxValue(100),
                    ]),

                Forms\Components\Section::make('Tarefas de Manutenção')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('caleira_feita')
                            ->label('Caleira Trasbordada?')
                            ->default(false),
                        Forms\Components\Toggle::make('renovacao_agua')
                            ->label('Renovação de Água Feita?')
                            ->default(false),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Técnico/NS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('registado_em')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ph')
                    ->label('pH')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cloro_livre')
                    ->label('Cloro L.')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('temperatura')
                    ->label('Temp.')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('e_correcao')
                    ->label('Correção?')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
