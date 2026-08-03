<?php

declare(strict_types=1);

namespace App\Filament\Resources\PoolClosureResource\Pages;

use App\Filament\Pages\EncerramentoPiscinas;
use App\Filament\Resources\PoolClosureResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPoolClosures extends ListRecords
{
    protected static string $resource = PoolClosureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('encerrar')
                ->label('Encerrar ou reabrir piscinas')
                ->icon('heroicon-o-lock-closed')
                ->url(EncerramentoPiscinas::getUrl()),
        ];
    }
}
