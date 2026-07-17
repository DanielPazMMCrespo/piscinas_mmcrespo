<?php declare(strict_types=1);
namespace App\Filament\Resources\CustomBroadcastResource\Pages;

use App\Filament\Resources\CustomBroadcastResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomBroadcast extends CreateRecord
{
    protected static string $resource = CustomBroadcastResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
