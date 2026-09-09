<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDailyRecords extends ListRecords
{
    protected static string $resource = DailyRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $phMin = DailyRecord::getPhMin();
        $phMax = DailyRecord::getPhMax();

        return [
            'todos' => Tab::make('Todos'),
            'hoje' => Tab::make('Hoje')
                ->badge(fn () => DailyRecordResource::getEloquentQuery()->whereDate('registado_em', today())->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('registado_em', today())),
            'nao_conformes' => Tab::make('Não conformes')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q) use ($phMin, $phMax) {
                    $q->whereRaw('COALESCE(ph, ns_ph) < ? OR COALESCE(ph, ns_ph) > ?', [$phMin, $phMax])
                        ->orWhereRaw('COALESCE(cloro_livre, ns_cloro_livre) < ? OR COALESCE(cloro_livre, ns_cloro_livre) > ?', [0.4, 3.0])
                        ->orWhereRaw('(COALESCE(cloro_total, ns_cloro_total) - COALESCE(cloro_livre, ns_cloro_livre)) > ?', [0.6]);
                })),
            'ultimos_7_dias' => Tab::make('Últimos 7 dias')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('registado_em', '>=', now()->subDays(7))),
        ];
    }
}
