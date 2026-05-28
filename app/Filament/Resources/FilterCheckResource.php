<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FilterCheckResource\Pages;
use App\Models\FilterCheck;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FilterCheckResource extends Resource
{
    protected static ?string $model = FilterCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationGroup = 'Operação';
    protected static ?string $modelLabel = 'Limpeza de Filtro';
    protected static ?string $pluralModelLabel = 'Limpezas de Filtros';

    public static function form(Form $form): Form
    {
        return $form
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
                Forms\Components\DateTimePicker::make('verificado_em')
                    ->label('Data/Hora da Limpeza')
                    ->default(now())
                    ->required(),
                Forms\Components\Select::make('tipo_operacao')
                    ->label('Tipo de Operação')
                    ->options([
                        'lavagem'          => 'Lavagem (Backwash)',
                        'enxaguamento'     => 'Enxaguamento (Rinse)',
                        'posicao_normal'   => 'Posição Normal',
                    ])
                    ->required(),
                Forms\Components\FileUpload::make('caminho_foto')
                    ->label('Fotografia do Filtro / Água')
                    ->image()
                    ->directory('verificacoes-filtro'),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações')
                    ->columnSpanFull(),
                Forms\Components\Section::make('Análise de Inteligência Artificial (Automático)')
                    ->schema([
                        Forms\Components\TextInput::make('resultado_ia')
                            ->label('Resultado (Limpo/Sujo)')
                            ->disabled(),
                        Forms\Components\Textarea::make('descricao_ia')
                            ->label('Descrição da IA')
                            ->disabled()
                            ->columnSpanFull(),
                    ])->collapsed(),
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
                Tables\Columns\TextColumn::make('tipo_operacao')
                    ->label('Operação')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('verificado_em')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('resultado_ia')
                    ->label('IA')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado em')
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
            'index'  => Pages\ListFilterChecks::route('/'),
            'create' => Pages\CreateFilterCheck::route('/create'),
            'view'   => Pages\ViewFilterCheck::route('/{record}'),
            'edit'   => Pages\EditFilterCheck::route('/{record}/edit'),
        ];
    }
}
