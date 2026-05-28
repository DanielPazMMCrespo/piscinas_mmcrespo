<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockInstallationResource\Pages;
use App\Models\StockInstallation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockInstallationResource extends Resource
{
    protected static ?string $model = StockInstallation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Inventário';
    protected static ?string $modelLabel = 'Stock na Instalação';
    protected static ?string $pluralModelLabel = 'Stock nas Instalações';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação')
                    ->relationship('instalacao', 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\Select::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantidade Mínima / Inicial')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('limite_minimo')
                    ->label('Alerta de Stock Baixo (Mínimo)')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('Instalação')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade Atual')
                    ->numeric(3)
                    ->sortable(),
                Tables\Columns\TextColumn::make('limite_minimo')
                    ->label('Alerta Mín.')
                    ->numeric(3)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('entrada_stock')
                    ->label('Entrada')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade a dar entrada (Recebido do Armazém)')
                            ->numeric()
                            ->required(),
                    ])
                    ->action(function (StockInstallation $record, array $data): void {
                        $record->quantity += $data['quantidade'];
                        $record->save();

                        \App\Models\StockInstallationLog::create([
                            'stock_installation_id' => $record->id,
                            'user_id'               => auth()->id(),
                            'tipo_movimento'        => 'entrada',
                            'quantity'              => $data['quantidade'],
                            'created_at'            => now(),
                        ]);
                    }),
                Tables\Actions\Action::make('consumo_stock')
                    ->label('Consumo Manual')
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade consumida (Ajuste Manual)')
                            ->numeric()
                            ->required(),
                    ])
                    ->action(function (StockInstallation $record, array $data): void {
                        $record->quantity -= $data['quantidade'];
                        $record->save();

                        \App\Models\StockInstallationLog::create([
                            'stock_installation_id' => $record->id,
                            'user_id'               => auth()->id(),
                            'tipo_movimento'        => 'consumo',
                            'quantity'              => $data['quantidade'],
                            'created_at'            => now(),
                        ]);
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
            'index'  => Pages\ListStockInstallations::route('/'),
            'create' => Pages\CreateStockInstallation::route('/create'),
            'view'   => Pages\ViewStockInstallation::route('/{record}'),
            'edit'   => Pages\EditStockInstallation::route('/{record}/edit'),
        ];
    }
}
