<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Filament\Resources\OperationalActionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationalAction extends CreateRecord
{
    protected static string $resource = OperationalActionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ação registada';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
