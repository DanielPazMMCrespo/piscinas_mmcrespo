<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\Pool;
use App\Models\StockInstallation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Cache;

class StockBaixoWidget extends BaseWidget
{
    protected static ?int $sort = -10;

    protected static ?string $heading = 'Alertas de Stock Baixo';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $ids = Cache::remember('cache_low_stock_ids', 300, function () {
            $query = StockInstallation::query()
                ->whereColumn('quantity', '<=', 'limite_minimo');

            if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
                $instalacaoIds = Pool::query()
                    ->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'))
                    ->pluck('installation_id')
                    ->unique();
                $query->whereIn('installation_id', $instalacaoIds);
            }

            return $query->pluck('id')->toArray();
        });

        return $table
            ->query(
                StockInstallation::query()
                    ->whereIn('id', $ids)
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
