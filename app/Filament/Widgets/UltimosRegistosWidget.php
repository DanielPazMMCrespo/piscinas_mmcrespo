<?php

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UltimosRegistosWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected static ?string $heading = 'Últimos Registos Diários';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DailyRecord::query()
                    ->with(['piscina.instalacao', 'utilizador'])
                    ->orderByDesc('registado_em')
            )
            ->columns([
                Tables\Columns\TextColumn::make('piscina.instalacao.name')
                    ->label('Instalação'),
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina'),
                Tables\Columns\TextColumn::make('cloro_livre')
                    ->label('Cl. Livre')
                    ->numeric(2)
                    ->suffix(' mg/L'),
                Tables\Columns\TextColumn::make('ph')
                    ->label('pH')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('temperatura')
                    ->label('Temperatura')
                    ->numeric(1)
                    ->suffix(' °C'),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Registado por'),
                Tables\Columns\TextColumn::make('registado_em')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25]);
    }
}
