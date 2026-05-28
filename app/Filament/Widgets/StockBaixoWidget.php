<?php

namespace App\Filament\Widgets;

use App\Models\StockInstallation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class StockBaixoWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected static ?string $heading = 'Alertas de Stock Baixo';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                StockInstallation::query()
                    ->whereColumn('quantity', '<=', 'limite_minimo')
                    ->with(['instalacao', 'produto'])
                    ->orderBy('quantity')
            )
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('Instalação')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto'),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade Atual')
                    ->numeric(3)
                    ->color('danger'),
                Tables\Columns\TextColumn::make('limite_minimo')
                    ->label('Limite Mínimo')
                    ->numeric(3)
                    ->color('warning'),
            ])
            ->emptyStateHeading('Sem alertas de stock')
            ->emptyStateDescription('Todos os produtos estão acima do limite mínimo.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }
}
