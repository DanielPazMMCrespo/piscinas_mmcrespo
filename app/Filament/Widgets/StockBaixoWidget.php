<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Filament\Resources\StockWarehouseResource;
use App\Models\Pool;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
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
        $user = auth()->user();
        $cacheKey = $user?->hasRole(UserRole::NADADOR_SALVADOR)
            ? "cache_low_stock_ids_ns_{$user->id}"
            : 'cache_low_stock_ids_admin';

        $ids = Cache::remember($cacheKey, 300, function () use ($user) {
            $query = StockInstallation::query()
                ->whereColumn('quantity', '<=', 'limite_minimo');

            if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
                $instalacaoIds = Pool::query()
                    ->whereIn('id', $user->piscinas()->pluck('pools.id'))
                    ->pluck('installation_id')
                    ->unique();
                $query->whereIn('installation_id', $instalacaoIds);
            }

            return $query->pluck('id')->toArray();
        });

        return $table
            ->poll('120s')
            ->query(
                StockInstallation::query()
                    ->whereIn('id', $ids)
                    ->with(['instalacao', 'produto'])
                    ->orderBy('quantity')
            )
            // Duas colunas em vez de quatro: a tabela tinha 602 px num contentor de
            // 356 px e o "Limite Mínimo" ficava fora do ecrã no telemóvel.
            ->columns([
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->weight('bold')
                    ->description(fn (StockInstallation $record): string => $record->instalacao?->name ?? ''),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Em stock / mínimo')
                    ->formatStateUsing(fn ($state, StockInstallation $record): string => number_format((float) $state, 3, ',', ' ')
                        .' / '.number_format((float) $record->limite_minimo, 3, ',', ' ')
                        .' '.($record->produto?->unidade ?? ''))
                    ->badge()
                    ->color('danger'),
            ])
            // Dava para ver o problema e não para o resolver: obrigava a ir ao
            // armazém e procurar o produto à mão.
            ->actions([
                Tables\Actions\Action::make('repor')
                    ->label('Repor do armazém')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->button()
                    ->visible(fn (StockInstallation $record): bool => auth()->user()?->can('transferStock', $record->produto?->stockArmazem ?? new StockWarehouse) ?? false)
                    ->url(fn (StockInstallation $record): string => StockWarehouseResource::getUrl('index', [
                        'tableSearch' => $record->produto?->name,
                    ])),
            ])
            ->emptyStateHeading('Sem alertas de stock')
            ->emptyStateDescription('Todos os produtos estão acima do limite mínimo.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }
}
