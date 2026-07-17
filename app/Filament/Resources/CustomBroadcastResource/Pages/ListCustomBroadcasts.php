<?php declare(strict_types=1);
namespace App\Filament\Resources\CustomBroadcastResource\Pages;

use App\Filament\Resources\CustomBroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomBroadcasts extends ListRecords
{
    protected static string $resource = CustomBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
