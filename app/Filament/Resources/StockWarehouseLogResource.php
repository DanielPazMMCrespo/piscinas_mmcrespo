<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Constants\UserRole;
use App\Filament\Concerns\HasPeriodoFilter;
use App\Filament\Resources\StockWarehouseLogResource\Pages;
use App\Models\StockWarehouseLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockWarehouseLogResource extends Resource
{
    use HasPeriodoFilter;

    protected static ?string $model = StockWarehouseLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-down';

    protected static ?string $navigationGroup = 'Logs';

    protected static ?string $modelLabel = 'Transação de Armazém';

    protected static ?string $pluralModelLabel = 'Histórico de Transações — Armazém Central';

    protected static ?string $navigationLabel = 'Movimentos — Armazém';

    public static function canAccess(): bool
    {
        // Gestor é leitura/relatórios: vê o histórico, não o altera.
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR])
            && $user->podeVerPagina(PaginaGestor::MOVIMENTOS_ARMAZEM);
    }

    public static function table(Table $table): Table
    {
        return $table
            // O produto chega por `armazem`: o modelo não tem relação `produto()` e
            // o StockService não preenche `product_id` — usar 'produto' aqui fazia
            // a página inteira devolver 500.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('armazem.produto', 'utilizador')->orderByDesc('created_at'))
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
                Tables\Columns\TextColumn::make('armazem.produto.name')
                    ->label('Produto')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->formatStateUsing(fn ($state, $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->armazem?->produto?->unidade ?? ''))
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->numeric(decimalPlaces: 3)),
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
                Tables\Filters\SelectFilter::make('armazem.product_id')
                    ->label('Produto')
                    ->relationship('armazem.produto', 'name')
                    ->searchable()
                    ->preload(),
                self::filtroPeriodo('created_at'),
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
