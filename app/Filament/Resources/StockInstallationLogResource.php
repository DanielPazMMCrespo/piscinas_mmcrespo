<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Constants\UserRole;
use App\Filament\Concerns\HasPeriodoFilter;
use App\Filament\Resources\StockInstallationLogResource\Pages;
use App\Models\StockInstallationLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockInstallationLogResource extends Resource
{
    use HasPeriodoFilter;

    protected static ?string $model = StockInstallationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-down';

    protected static ?string $navigationGroup = 'Logs';

    protected static ?string $modelLabel = 'Transação de Instalação';

    protected static ?string $pluralModelLabel = 'Histórico de Transações — Instalação';

    protected static ?string $navigationLabel = 'Movimentos — Instalação';

    public static function canAccess(): bool
    {
        // Gestor é leitura/relatórios: vê o histórico, não o altera.
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR])
            && $user->podeVerPagina(PaginaGestor::MOVIMENTOS_INSTALACAO);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('stockInstalacao.instalacao', 'stockInstalacao.produto', 'utilizador')->orderByDesc('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('tipo_movimento')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'entrada' => 'Entrada',
                        'consumo' => 'Consumo',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'entrada' => 'success',
                        'consumo' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('stockInstalacao.instalacao.name')
                    ->label('Instalação')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stockInstalacao.produto.name')
                    ->label('Produto')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->formatStateUsing(fn ($state, $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->stockInstalacao?->produto?->unidade ?? ''))
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->numeric(decimalPlaces: 3)),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Utilizador')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_movimento')
                    ->label('Tipo de Movimento')
                    ->options([
                        'entrada' => 'Entrada',
                        'consumo' => 'Consumo',
                    ]),
                Tables\Filters\SelectFilter::make('stockInstalacao.instalacao_id')
                    ->label('Instalação')
                    ->relationship('stockInstalacao.instalacao', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('stockInstalacao.produto_id')
                    ->label('Produto')
                    ->relationship('stockInstalacao.produto', 'name')
                    ->searchable()
                    ->preload(),
                self::filtroPeriodo(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockInstallationLogs::route('/'),
        ];
    }
}
