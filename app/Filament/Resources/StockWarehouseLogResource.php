<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Filament\Resources\StockWarehouseLogResource\Pages;
use App\Models\StockWarehouseLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockWarehouseLogResource extends Resource
{
    protected static ?string $model = StockWarehouseLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-down';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?string $modelLabel = 'Transação de Armazém';

    protected static ?string $pluralModelLabel = 'Histórico de Transações — Armazém Central';

    protected static ?string $navigationLabel = 'Movimentos — Armazém';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'tecnico']) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('produto', 'utilizador')->orderByDesc('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('tipo_movimento')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'entrada' => 'Entrada',
                        'saida' => 'Saída',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'entrada' => 'success',
                        'saida' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->formatStateUsing(fn ($state, $record): string =>
                        number_format((float) $state, 3, '.', '') . ' ' . ($record->produto?->unidade ?? ''))
                    ->sortable(),
                Tables\Columns\TextColumn::make('fornecedor')
                    ->label('Fornecedor')
                    ->default('—'),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Utilizador')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_movimento')
                    ->label('Tipo de Movimento')
                    ->options([
                        'entrada' => 'Entrada',
                        'saida' => 'Saída',
                    ]),
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockWarehouseLogs::route('/'),
        ];
    }
}
