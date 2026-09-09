<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Constants\IncidentStatus;
use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Incidente')
                ->icon('heroicon-m-plus'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'abertos' => Tab::make('Abertos')
                ->badge(fn () => IncidentResource::getEloquentQuery()->where('status', IncidentStatus::ABERTO)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', IncidentStatus::ABERTO)),
            'hoje' => Tab::make('Hoje')
                ->badge(fn () => IncidentResource::getEloquentQuery()->whereDate('ocorreu_em', today())->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('ocorreu_em', today())),
            'resolvidos' => Tab::make('Resolvidos')
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', IncidentStatus::RESOLVIDO)),
            'todos' => Tab::make('Todos'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}
