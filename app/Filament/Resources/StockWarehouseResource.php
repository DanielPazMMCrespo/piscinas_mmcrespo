<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockWarehouseResource\Pages;
use App\Models\StockWarehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class StockWarehouseResource extends Resource
{
    protected static ?string $model = StockWarehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventário';
    protected static ?string $modelLabel = 'Stock de Armazém';
    protected static ?string $pluralModelLabel = 'Stock de Armazém';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['admin', 'tecnico']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantidade Mínima / Inicial')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade em Stock')
                    ->numeric(3)
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('entrada_stock')
                    ->label('Entrada')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade a adicionar')
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações (ex: Nº da Fatura)')
                            ->maxLength(255),
                    ])
                    ->action(function (StockWarehouse $record, array $data): void {
                        DB::transaction(function () use ($record, $data) {
                            $fresh = StockWarehouse::lockForUpdate()->findOrFail($record->id);
                            $fresh->quantity += $data['quantidade'];
                            $fresh->save();
                            \App\Models\StockWarehouseLog::create([
                                'product_id'     => $fresh->product_id,
                                'user_id'        => auth()->id(),
                                'tipo_movimento' => 'entrada',
                                'quantity'       => $data['quantidade'],
                                'fornecedor'     => $data['observacoes'] ?? null,
                            ]);
                        });
                    }),
                Tables\Actions\Action::make('saida_stock')
                    ->label('Saída')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade a remover (Saída p/ Instalação)')
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações (ex: Destino)')
                            ->maxLength(255),
                    ])
                    ->action(function (StockWarehouse $record, array $data): void {
                        DB::transaction(function () use ($record, $data) {
                            $fresh = StockWarehouse::lockForUpdate()->findOrFail($record->id);
                            if ($fresh->quantity < $data['quantidade']) {
                                Notification::make()
                                    ->danger()
                                    ->title('Stock insuficiente')
                                    ->body("Disponível: {$fresh->quantity}. Pedido: {$data['quantidade']}.")
                                    ->send();
                                return;
                            }
                            $fresh->quantity -= $data['quantidade'];
                            $fresh->save();
                            \App\Models\StockWarehouseLog::create([
                                'product_id'     => $fresh->product_id,
                                'user_id'        => auth()->id(),
                                'tipo_movimento' => 'saida',
                                'quantity'       => $data['quantidade'],
                                'fornecedor'     => $data['observacoes'] ?? null,
                            ]);
                        });
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
            'index'  => Pages\ListStockWarehouses::route('/'),
            'create' => Pages\CreateStockWarehouse::route('/create'),
            'view'   => Pages\ViewStockWarehouse::route('/{record}'),
            'edit'   => Pages\EditStockWarehouse::route('/{record}/edit'),
        ];
    }
}
